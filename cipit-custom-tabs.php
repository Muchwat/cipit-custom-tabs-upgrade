<?php
/**
 * Plugin Name: CIPIT Custom Tabs
 * Plugin URI: https://github.com/Muchwat/cipit-custom-tabs
 * Description: Implements a custom [custom_tabs] shortcode system with deep linking, dynamic content, layouts, search, collapsible submenus, and legacy-URL fallback.
 * Version: 2.1.0
 * Author: Kevin Muchwat
 * Author URI: https://github.com/Muchwat
 * Text Domain: cipit-custom-tabs
 * Requires at least: 5.5
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

define('CIPIT_CT_VERSION', '2.1.0');
define('CIPIT_CT_URL', plugin_dir_url(__FILE__));
define('CIPIT_CT_PATH', plugin_dir_path(__FILE__));

final class CIPIT_Custom_Tabs
{
    /** @var CIPIT_Custom_Tabs|null */
    private static $instance = null;

    /**
     * Stack of tab collections. Each [custom_tabs] instance pushes an array,
     * [custom_tab] children append to the top of the stack, and the instance
     * pops it when rendering. Supports nesting safely.
     *
     * @var array
     */
    private $tab_data = array();

    /** @var int */
    private $recursion_depth = 0;

    /** @var int */
    private $max_recursion = 5;

    /**
     * Deterministic per-page instance counter. Guarantees that a shortcode
     * without an explicit id/group gets the SAME instance id on every page
     * load (uniqid() broke copied deep links).
     *
     * @var int
     */
    private $instance_counter = 0;

    /** @var bool */
    private $assets_enqueued = false;

    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', array($this, 'load_textdomain'), 0);
        add_action('init', array($this, 'register_cpt'), 0);
        add_action('init', array($this, 'register_taxonomy'), 0);
        add_action('add_meta_boxes', array($this, 'add_meta_box'));
        add_action('save_post_tab_item', array($this, 'save_meta'), 10, 1);
        add_action('wp_enqueue_scripts', array($this, 'register_assets'));
        add_shortcode('custom_tabs', array($this, 'tabs_shortcode'));
        add_shortcode('custom_tab', array($this, 'tab_shortcode'));
    }

    /* --------------------------------------------------------------------
     * i18n
     * ------------------------------------------------------------------ */

    public function load_textdomain()
    {
        load_plugin_textdomain('cipit-custom-tabs', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    /* --------------------------------------------------------------------
     * Post type + taxonomy
     * ------------------------------------------------------------------ */

    public function register_cpt()
    {
        $labels = array(
            'name'          => _x('Tab Items', 'Post Type General Name', 'cipit-custom-tabs'),
            'singular_name' => _x('Tab Item', 'Post Type Singular Name', 'cipit-custom-tabs'),
            'menu_name'     => __('Custom Tabs', 'cipit-custom-tabs'),
            'name_admin_bar' => __('Tab Item', 'cipit-custom-tabs'),
            'add_new_item'  => __('Add New Tab Item', 'cipit-custom-tabs'),
            'new_item'      => __('New Tab Item', 'cipit-custom-tabs'),
            'edit_item'     => __('Edit Tab Item', 'cipit-custom-tabs'),
            'view_item'     => __('View Tab Item', 'cipit-custom-tabs'),
            'all_items'     => __('All Tab Items', 'cipit-custom-tabs'),
        );
        $args = array(
            'label'               => __('Tab Items', 'cipit-custom-tabs'),
            'description'         => __('Content for custom tab sections.', 'cipit-custom-tabs'),
            'labels'              => $labels,
            'supports'            => array('title', 'editor', 'page-attributes'),
            'hierarchical'        => true,
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'menu_position'       => 20,
            'menu_icon'           => 'dashicons-editor-table',
            'show_in_admin_bar'   => true,
            'show_in_nav_menus'   => false,
            'can_export'          => true,
            'has_archive'         => false,
            'exclude_from_search' => true,
            'publicly_queryable'  => false,
            'capability_type'     => 'post',
        );
        register_post_type('tab_item', $args);
    }

    public function register_taxonomy()
    {
        $labels = array(
            'name'          => _x('Tab Groups', 'taxonomy general name', 'cipit-custom-tabs'),
            'singular_name' => _x('Tab Group', 'taxonomy singular name', 'cipit-custom-tabs'),
            'menu_name'     => __('Tab Groups', 'cipit-custom-tabs'),
        );
        $args = array(
            'hierarchical'      => true,
            'labels'            => $labels,
            'show_ui'           => true,
            'show_admin_column' => true,
            'query_var'         => true,
            'rewrite'           => array('slug' => 'tab-group'),
        );
        register_taxonomy('tab_group', array('tab_item'), $args);
    }

    /* --------------------------------------------------------------------
     * Meta box
     * ------------------------------------------------------------------ */

    public function add_meta_box()
    {
        add_meta_box(
            'ctdl_tab_id_box',
            __('Custom Tab ID', 'cipit-custom-tabs'),
            array($this, 'render_meta_box'),
            'tab_item',
            'side',
            'high'
        );
    }

    public function render_meta_box($post)
    {
        wp_nonce_field('ctdl_save_custom_id_data', 'ctdl_custom_id_nonce');
        $custom_id             = get_post_meta($post->ID, '_ctdl_custom_tab_id', true);
        $content_mode_override = get_post_meta($post->ID, '_ctdl_content_mode_override', true);
        $default_id            = $post->post_name;

        echo '<label for="ctdl_custom_tab_id">' . esc_html__('Unique ID (for deep linking):', 'cipit-custom-tabs') . '</label>';
        echo '<input type="text" id="ctdl_custom_tab_id" name="ctdl_custom_tab_id" value="' . esc_attr($custom_id) . '" size="25" style="width: 100%; margin-bottom: 10px;" />';
        echo '<p class="description">' . esc_html__('This ID is used for deep linking (e.g., #your-id). If left blank, the ID will default to the post slug:', 'cipit-custom-tabs') . ' <code>' . esc_html($default_id) . '</code>. ' . esc_html__('Tip: paste an old-format ID here (e.g. 1740660865543-2fdc6c7c-720f) to keep legacy links working.', 'cipit-custom-tabs') . '</p>';

        // Warn when another tab item already uses this custom ID (deep links
        // resolve to whichever renders first, which is confusing to debug).
        if (!empty($custom_id)) {
            $dupes = get_posts(array(
                'post_type'      => 'tab_item',
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'post__not_in'   => array($post->ID),
                'fields'         => 'ids',
                'meta_key'       => '_ctdl_custom_tab_id',
                'meta_value'     => $custom_id,
            ));
            if (!empty($dupes)) {
                echo '<p style="color:#b32d2e;font-weight:600;">' . esc_html__('Warning: another Tab Item already uses this ID. Deep links may open the wrong tab.', 'cipit-custom-tabs') . '</p>';
            }
        }

        echo '<hr style="margin: 15px 0; border: 0; border-top: 1px solid #eee;" />';
        $legacy_ids = get_post_meta($post->ID, '_ctdl_legacy_tab_ids', true);
        echo '<label for="ctdl_legacy_tab_ids" style="display: block; margin-bottom: 5px; font-weight: 600;">' . esc_html__('Legacy IDs (aliases):', 'cipit-custom-tabs') . '</label>';
        echo '<input type="text" id="ctdl_legacy_tab_ids" name="ctdl_legacy_tab_ids" value="' . esc_attr($legacy_ids) . '" style="width: 100%; margin-bottom: 10px;" placeholder="1740660865543-2fdc6c7c-720f, old-id-2" />';
        echo '<p class="description">' . esc_html__('Comma-separated old IDs from the previous plugin version. Old links using any of these IDs will open this tab and be upgraded to the new URL format. Does not change this tab\'s own ID.', 'cipit-custom-tabs') . '</p>';

        echo '<hr style="margin: 15px 0; border: 0; border-top: 1px solid #eee;" />';
        echo '<label for="ctdl_content_mode_override" style="display: block; margin-bottom: 5px; font-weight: 600;">' . esc_html__('Content Mode Override:', 'cipit-custom-tabs') . '</label>';
        echo '<select id="ctdl_content_mode_override" name="ctdl_content_mode_override" style="width: 100%;">';
        echo '<option value="inherit"' . selected($content_mode_override, 'inherit', false) . '>' . esc_html__('Default (inherit from shortcode)', 'cipit-custom-tabs') . '</option>';
        echo '<option value="on"' . selected($content_mode_override, 'on', false) . '>' . esc_html__('Enable Content Mode (no borders/padding)', 'cipit-custom-tabs') . '</option>';
        echo '<option value="off"' . selected($content_mode_override, 'off', false) . '>' . esc_html__('Disable Content Mode (show borders/padding)', 'cipit-custom-tabs') . '</option>';
        echo '</select>';
        echo '<p class="description">' . esc_html__('Override the global content-mode setting for this specific tab.', 'cipit-custom-tabs') . '</p>';
    }

    public function save_meta($post_id)
    {
        if (!isset($_POST['ctdl_custom_id_nonce']) || !wp_verify_nonce(wp_unslash($_POST['ctdl_custom_id_nonce']), 'ctdl_save_custom_id_data')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($post_id)) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $input_id = isset($_POST['ctdl_custom_tab_id']) ? trim(wp_unslash($_POST['ctdl_custom_tab_id'])) : '';
        if ('' !== $input_id) {
            $new_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $input_id);
            if ('' !== $new_id) {
                update_post_meta($post_id, '_ctdl_custom_tab_id', $new_id);
            } else {
                delete_post_meta($post_id, '_ctdl_custom_tab_id');
            }
        } else {
            delete_post_meta($post_id, '_ctdl_custom_tab_id');
        }

        $legacy_raw = isset($_POST['ctdl_legacy_tab_ids']) ? wp_unslash($_POST['ctdl_legacy_tab_ids']) : '';
        $legacy_ids = array();
        foreach (preg_split('/[\s,]+/', $legacy_raw) as $lid) {
            $lid = preg_replace('/[^a-zA-Z0-9_-]/', '', $lid);
            if ('' !== $lid) {
                $legacy_ids[] = $lid;
            }
        }
        $legacy_ids = array_unique($legacy_ids);
        if (!empty($legacy_ids)) {
            update_post_meta($post_id, '_ctdl_legacy_tab_ids', implode(', ', $legacy_ids));
        } else {
            delete_post_meta($post_id, '_ctdl_legacy_tab_ids');
        }

        $content_mode_override = isset($_POST['ctdl_content_mode_override']) ? sanitize_text_field(wp_unslash($_POST['ctdl_content_mode_override'])) : 'inherit';
        if (in_array($content_mode_override, array('on', 'off'), true)) {
            update_post_meta($post_id, '_ctdl_content_mode_override', $content_mode_override);
        } else {
            delete_post_meta($post_id, '_ctdl_content_mode_override');
        }
    }

    /* --------------------------------------------------------------------
     * Assets
     * ------------------------------------------------------------------ */

    public function register_assets()
    {
        wp_register_style(
            'cipit-custom-tabs',
            CIPIT_CT_URL . 'assets/css/custom-tabs.css',
            array(),
            CIPIT_CT_VERSION
        );
        wp_register_script(
            'cipit-custom-tabs',
            CIPIT_CT_URL . 'assets/js/custom-tabs.js',
            array(), // v2.1.0: native JS, no jQuery dependency
            CIPIT_CT_VERSION,
            true // footer
        );
    }

    private function enqueue_assets()
    {
        if ($this->assets_enqueued) {
            return;
        }
        wp_enqueue_style('cipit-custom-tabs');
        wp_enqueue_script('cipit-custom-tabs');
        $this->assets_enqueued = true;
    }

    /* --------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    private function safe_render_content($content)
    {
        return apply_filters('the_content', $content);
    }

    /**
     * Build the truncated search haystack for a tab. Embedding the FULL post
     * content in a data attribute bloated pages badly; the title plus the
     * first ~400 characters catches virtually all real searches.
     */
    private function search_haystack($title, $raw_content)
    {
        $text = $title . ' ' . wp_strip_all_tags(strip_shortcodes((string) $raw_content));
        $text = preg_replace('/\s+/', ' ', $text);
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, 400);
        }
        return substr($text, 0, 400);
    }

    private function content_mode_class($tab, $content_mode_active)
    {
        if (isset($tab['post_id'])) {
            $override = get_post_meta($tab['post_id'], '_ctdl_content_mode_override', true);
            if ('on' === $override) {
                return 'tab-content-mode-active';
            }
            if ('off' === $override) {
                return 'tab-content-mode-inactive';
            }
        }
        return $content_mode_active ? 'tab-content-mode-active' : '';
    }

    /** Current page URL built from WP settings, not attacker-controllable headers. */
    private function current_url()
    {
        return home_url(add_query_arg(array()));
    }

    /**
     * Render a data-legacy-ids attribute for a tab (space-separated tokens so
     * the JS can match with the [data-legacy-ids~="id"] word selector).
     * Returns '' when the tab has no aliases.
     */
    private function legacy_attr($tab)
    {
        if (empty($tab['legacy_ids'])) {
            return '';
        }
        $ids = array();
        foreach (preg_split('/[\s,]+/', (string) $tab['legacy_ids']) as $lid) {
            $lid = preg_replace('/[^a-zA-Z0-9_-]/', '', $lid);
            if ('' !== $lid) {
                $ids[] = $lid;
            }
        }
        if (empty($ids)) {
            return '';
        }
        return ' data-legacy-ids="' . esc_attr(implode(' ', array_unique($ids))) . '"';
    }

    /* --------------------------------------------------------------------
     * [custom_tab] — child shortcode
     * ------------------------------------------------------------------ */

    public function tab_shortcode($atts, $content = null)
    {
        // PHP 8 fix: the old code called count(end($array)) on an empty
        // stack, which fatals. A [custom_tab] outside [custom_tabs] is
        // simply ignored now.
        if (empty($this->tab_data)) {
            return '';
        }

        $atts = shortcode_atts(array(
            'title'  => __('Tab', 'cipit-custom-tabs'),
            'id'     => '',
            'parent' => '',
            'legacy' => '', // comma-separated old IDs (aliases) for v1 links
        ), $atts, 'custom_tab');

        $last_key = array_key_last($this->tab_data);

        if (empty($atts['id'])) {
            $atts['id'] = sanitize_title($atts['title']) . '-' . count($this->tab_data[$last_key]);
        }

        $raw = (string) $content;

        $this->tab_data[$last_key][] = array(
            'title'       => $atts['title'],
            'id'          => $atts['id'],
            'parent'      => $atts['parent'],
            'raw_content' => $raw,
            'content'     => do_shortcode($raw),
            'legacy_ids'  => $atts['legacy'],
        );

        return '';
    }

    /* --------------------------------------------------------------------
     * [custom_tabs] — container shortcode
     * ------------------------------------------------------------------ */

    public function tabs_shortcode($atts, $content = null)
    {
        $this->recursion_depth++;
        $is_nested = ($this->recursion_depth > 1);

        if ($this->recursion_depth > $this->max_recursion) {
            $this->recursion_depth--;
            return '<div class="custom-tabs-recursion-warning"><strong>' . esc_html__('Warning:', 'cipit-custom-tabs') . '</strong> ' . esc_html__('Maximum tab nesting depth exceeded. Possible recursive shortcode loop detected.', 'cipit-custom-tabs') . '</div>';
        }

        $this->enqueue_assets();

        $atts = shortcode_atts(array(
            'layout'       => 'top',
            'group'        => '',
            'id'           => '',
            'title'        => '',
            'description'  => '',
            'show-header'  => 'true',
            'content-mode' => 'false',
        ), $atts, 'custom_tabs');

        $layout_class        = 'layout-' . sanitize_html_class($atts['layout']);
        $group_slug          = sanitize_title($atts['group']);
        $content_mode_active = filter_var($atts['content-mode'], FILTER_VALIDATE_BOOLEAN);
        $content_mode_class  = $content_mode_active ? 'content-mode-active' : '';

        // Deterministic fallback id — stable across page loads so deep links survive.
        $this->instance_counter++;
        if (!empty($atts['id'])) {
            $instance_id = sanitize_title($atts['id']);
        } elseif (!empty($group_slug)) {
            $instance_id = $group_slug;
        } else {
            $instance_id = 'ctdl-' . $this->instance_counter;
        }

        $this->tab_data[] = array();
        $stack_index      = array_key_last($this->tab_data);

        $default_title = '';
        $default_desc  = '';
        $term          = null;

        if (!empty($group_slug)) {
            $term = get_term_by('slug', $group_slug, 'tab_group');
            if ($term && !is_wp_error($term)) {
                $default_title = $term->name;
                $default_desc  = $term->description;
            }
        }

        $title       = !empty($atts['title']) ? $atts['title'] : $default_title;
        $description = !empty($atts['description']) ? $atts['description'] : $default_desc;
        $show_header = filter_var($atts['show-header'], FILTER_VALIDATE_BOOLEAN);

        if (!empty($group_slug)) {
            $this->collect_group_tabs($group_slug, $stack_index);
        } else {
            do_shortcode($content);
        }

        $current_tabs = $this->tab_data[$stack_index];

        if (empty($current_tabs)) {
            $error_message = __('No tab items found.', 'cipit-custom-tabs');
            if (!empty($group_slug)) {
                if ($term && !is_wp_error($term)) {
                    /* translators: %s: tab group name */
                    $error_message = sprintf(__('No tab items found in the group: "%s".', 'cipit-custom-tabs'), $term->name);
                } else {
                    /* translators: %s: tab group slug */
                    $error_message = sprintf(__('No tab items found for the group slug: "%s".', 'cipit-custom-tabs'), $group_slug);
                }
            }
            array_pop($this->tab_data);
            $this->recursion_depth--;
            return '<p class="custom-tabs-empty-notice">' . esc_html($error_message) . '</p>';
        }

        $output = $this->render_instance(
            $current_tabs,
            $instance_id,
            $layout_class,
            $content_mode_active,
            $content_mode_class,
            $title,
            $description,
            $show_header,
            $is_nested
        );

        array_pop($this->tab_data);
        $this->recursion_depth--;

        return $output;
    }

    /**
     * Query tab_item posts for a group and push them onto the current stack frame.
     */
    private function collect_group_tabs($group_slug, $stack_index)
    {
        $tabs_query = new WP_Query(array(
            'post_type'      => 'tab_item',
            'posts_per_page' => -1,
            'orderby'        => 'menu_order date',
            'order'          => 'ASC',
            'post_status'    => array('publish'),
            'no_found_rows'  => true,
            'tax_query'      => array(
                array(
                    'taxonomy' => 'tab_group',
                    'field'    => 'slug',
                    'terms'    => $group_slug,
                ),
            ),
        ));

        if (!$tabs_query->have_posts()) {
            return;
        }

        while ($tabs_query->have_posts()) {
            $tabs_query->the_post();
            $post_obj         = get_post(get_the_ID());
            $raw_post_content = $post_obj->post_content;
            $custom_id        = get_post_meta($post_obj->ID, '_ctdl_custom_tab_id', true);
            $tab_id           = !empty($custom_id) ? $custom_id : $post_obj->post_name;

            $rendered_content = '';
            if ('private' === $post_obj->post_status && !current_user_can('read_private_posts', $post_obj->ID)) {
                $rendered_content = '<p class="private-content-notice">' . esc_html__('This content is private.', 'cipit-custom-tabs') . '</p>';
                $raw_post_content = '';
            } elseif (post_password_required($post_obj)) {
                $current_url          = $this->current_url();
                $password_form_filter = function ($form) use ($tab_id, $current_url) {
                    return str_replace(get_permalink(), esc_url(strtok($current_url, '#') . '#' . $tab_id), $form);
                };
                add_filter('the_password_form', $password_form_filter);
                $rendered_content = get_the_password_form();
                remove_filter('the_password_form', $password_form_filter);
                $raw_post_content = ''; // don't leak protected content into the search index
            } else {
                $rendered_content = $this->safe_render_content($raw_post_content);
            }

            $parent_slug = '';
            if ($post_obj->post_parent) {
                $parent_post = get_post($post_obj->post_parent);
                if ($parent_post) {
                    $parent_custom_id = get_post_meta($parent_post->ID, '_ctdl_custom_tab_id', true);
                    $parent_slug      = !empty($parent_custom_id) ? $parent_custom_id : $parent_post->post_name;
                }
            }

            $this->tab_data[$stack_index][] = array(
                'title'       => $post_obj->post_title,
                'id'          => $tab_id,
                'parent'      => $parent_slug,
                'raw_content' => $raw_post_content,
                'content'     => $rendered_content,
                'post_id'     => $post_obj->ID,
                'legacy_ids'  => get_post_meta($post_obj->ID, '_ctdl_legacy_tab_ids', true),
            );
        }
        wp_reset_postdata();
    }

    /**
     * Build the HTML for a single tabs instance.
     */
    private function render_instance($tabs, $instance_id, $layout_class, $content_mode_active, $content_mode_class, $title, $description, $show_header, $is_nested)
    {
        // Group flat list into parents + children.
        $parent_tabs = array();
        $child_tabs  = array();
        foreach ($tabs as $tab) {
            if (empty($tab['parent'])) {
                $parent_tabs[$tab['id']]             = $tab;
                $parent_tabs[$tab['id']]['children'] = array();
            } else {
                $child_tabs[] = $tab;
            }
        }
        foreach ($child_tabs as $child) {
            $p_id = $child['parent'];
            if (isset($parent_tabs[$p_id])) {
                $parent_tabs[$p_id]['children'][] = $child;
            } else {
                // Orphaned child: promote to top level rather than dropping it.
                $parent_tabs[$child['id']]             = $child;
                $parent_tabs[$child['id']]['children'] = array();
            }
        }

        $output       = '';
        $tab_headers  = '';
        $tab_contents = '';

        $has_header_content = (!empty($title) || !empty($description));
        if ($show_header && $has_header_content) {
            $output .= sprintf(
                '<div class="custom-tabs-header-wrap">%1$s%2$s%3$s</div>',
                !empty($title) ? '<h1>' . esc_html($title) . '</h1>' : '',
                !empty($description) ? '<p>' . esc_html($description) . '</p>' : '',
                sprintf(
                    '<div class="custom-tabs-search-bar-wrap" data-tabs-id="%1$s-search"><div class="search-inner-bar"><input type="text" placeholder="%2$s" class="custom-tabs-search-input" aria-label="%3$s"><div class="search-button-area" title="%3$s"><i class="fas fa-search" aria-hidden="true"></i></div></div></div>',
                    esc_attr($instance_id),
                    esc_attr__('Search...', 'cipit-custom-tabs'),
                    esc_attr__('Search Tabs', 'cipit-custom-tabs')
                )
            );
        }

        foreach ($parent_tabs as $parent) {
            $p_id         = esc_attr($parent['id']);
            $p_title      = esc_html($parent['title']);
            $has_children = !empty($parent['children']);
            $search_data  = esc_attr($this->search_haystack($parent['title'], $parent['raw_content'] ?? ''));

            if ($has_children) {
                $children_html = '';
                foreach ($parent['children'] as $child) {
                    $c_id              = esc_attr($child['id']);
                    $c_title           = esc_html($child['title']);
                    $child_search_data = esc_attr($this->search_haystack($child['title'], $child['raw_content'] ?? ''));

                    $children_html .= sprintf(
                        '<li class="tab-submenu-item" data-search-content="%3$s"><a href="#%1$s" id="tab-btn-%1$s" data-target="%1$s" data-parent-id="%4$s"%5$s class="custom-tabs-header tab-inactive sub-menu-link" role="tab" aria-controls="%1$s" aria-selected="false">%2$s</a></li>',
                        $c_id,
                        $c_title,
                        $child_search_data,
                        $p_id,
                        $this->legacy_attr($child)
                    );

                    $tab_contents .= sprintf(
                        '<div id="%1$s" class="custom-tabs-content tab-content-panel hidden %3$s" role="tabpanel" aria-labelledby="tab-btn-%1$s" tabindex="0">%2$s</div>',
                        $c_id,
                        $child['content'],
                        esc_attr($this->content_mode_class($child, $content_mode_active))
                    );
                }

                $tab_headers .= sprintf(
                    '<li class="tab-header-item has-submenu" data-search-content="%3$s" data-parent-id="%1$s"><a href="#" class="custom-tabs-header parent-toggle" aria-haspopup="true" aria-expanded="false"><span>%2$s</span><span class="submenu-arrow" aria-hidden="true"></span></a><ul class="tab-submenu-list">%4$s</ul></li>',
                    $p_id,
                    $p_title,
                    $search_data,
                    $children_html
                );
            } else {
                $tab_headers .= sprintf(
                    '<li class="tab-header-item" data-search-content="%3$s"><a href="#%1$s" id="tab-btn-%1$s" data-target="%1$s"%4$s class="custom-tabs-header tab-inactive" role="tab" aria-controls="%1$s" aria-selected="false">%2$s</a></li>',
                    $p_id,
                    $p_title,
                    $search_data,
                    $this->legacy_attr($parent)
                );

                $tab_contents .= sprintf(
                    '<div id="%1$s" class="custom-tabs-content tab-content-panel hidden %3$s" role="tabpanel" aria-labelledby="tab-btn-%1$s" tabindex="0">%2$s</div>',
                    $p_id,
                    $parent['content'],
                    esc_attr($this->content_mode_class($parent, $content_mode_active))
                );
            }
        }

        $output .= sprintf(
            '<div class="custom-tabs-container %4$s" data-group-instance="%1$s" data-tabs-id="%1$s" data-url-mode="%5$s"><ul class="tab-headers-list" role="tablist">%2$s</ul><div class="tab-contents-wrap">%3$s<p class="no-results-message hidden">%6$s</p></div></div>',
            esc_attr($instance_id),
            $tab_headers,
            $tab_contents,
            esc_attr(trim($layout_class . ' ' . $content_mode_class)),
            $is_nested ? 'query' : 'hash',
            esc_html__('No results found matching your search criteria.', 'cipit-custom-tabs')
        );

        return $output;
    }
}

CIPIT_Custom_Tabs::instance();
