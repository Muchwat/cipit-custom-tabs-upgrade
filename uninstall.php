<?php
/**
 * CIPIT Custom Tabs — uninstall cleanup.
 *
 * Removes plugin-owned post meta. Tab Item posts and Tab Group terms are
 * intentionally LEFT IN PLACE: they are user content, and deleting content
 * on uninstall is destructive and unexpected. Delete them manually from
 * the admin if you no longer need them.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_metadata('post', 0, '_ctdl_custom_tab_id', '', true);
delete_metadata('post', 0, '_ctdl_content_mode_override', '', true);
delete_metadata('post', 0, '_ctdl_legacy_tab_ids', '', true);
