# CIPIT Custom Tabs 2.1.1

A `[custom_tabs]` shortcode system for WordPress with deep linking, nested tab sets, four layouts, live search, collapsible submenus, dropdown-select submenus (top/bottom layouts), and automatic fallback for legacy v1 hash URLs.

## Installation

Upload the `cipit-custom-tabs` folder to `wp-content/plugins/` and activate. CSS/JS are loaded from `assets/` only on pages that actually render the shortcode.

## Usage

### From a Tab Group (recommended)

Create **Tab Items** in the admin, assign them to a **Tab Group**, then:

```
[custom_tabs group="my-group" layout="left"]
```

Hierarchy is respected: a Tab Item with a parent renders inside that parent's submenu. Order follows menu order (Page Attributes → Order).

### Inline

```
[custom_tabs id="docs" layout="top" title="Documentation"]
  [custom_tab title="Overview" id="overview"]Content here...[/custom_tab]
  [custom_tab title="Setup" id="setup"]More content...[/custom_tab]
  [custom_tab title="Advanced" id="advanced" parent="setup"]Nested under Setup[/custom_tab]
[/custom_tabs]
```

### Attributes for `[custom_tabs]`

| Attribute | Default | Description |
|---|---|---|
| `layout` | `top` | `top`, `bottom`, `left`, `right`. Top/bottom render submenus as dropdown selects; left/right as accordions. At ≤768px width all four collapse into the same wrapping pill row, with submenus as dropdown selects — see Mobile behavior below. |
| `group` | — | Tab Group slug to pull Tab Items from. |
| `id` | group slug or auto | Instance ID used in hash routing. Set this explicitly if you want stable shareable URLs on pages with multiple inline tab sets. |
| `title` | group name | Header title. |
| `description` | group description | Header description. |
| `show-header` | `true` | Show the title/description/search header. |
| `content-mode` | `false` | Strip panel borders/padding (per-tab override available in the Tab Item meta box). |

### Mobile behavior (≤768px)

All four layouts converge on the same presentation on small screens: the tab headers become a single centered pill row that **wraps** onto as many lines as needed so every top-level tab stays visible at once — nothing is clipped or scrolled off-screen. Tabs with children render as a pill that opens a small dropdown panel below it, matching the desktop top/bottom dropdown behavior. Tapping anywhere outside an open dropdown closes it, including on touch devices (this uses `pointerdown`/`touchstart` rather than relying on `click` alone, since iOS Safari doesn't reliably dispatch `click` on plain non-interactive elements).

### Deep linking

URLs use the format `#instanceId/tabId`, chaining for nested sets:
`#outer-tabs/panel-a/inner-tabs/tab-3`.

### Legacy v1 URLs

Old flat links like `#1740660865543-2fdc6c7c-720f` still work: the router resolves the raw hash to a tab anywhere on the page (including nested ones) and rewrites the URL to the new format. For this to resolve, the old ID must exist in the new system — paste it into the **Custom Tab ID** meta box of the corresponding Tab Item. The meta box warns if another Tab Item already uses the same ID.

## Changelog

### 2.0.0
- Rewritten as a class; no globals, single `cipit-custom-tabs` text domain, `load_plugin_textdomain`.
- CSS/JS moved to enqueued files (cacheable, printed once, explicit jQuery dependency, loaded only when the shortcode renders).
- Fixed PHP 8 fatal when `[custom_tab]` is used outside `[custom_tabs]`.
- Deterministic instance IDs for id-less shortcodes (deep links survive reloads; previously `uniqid()`).
- All JS traversal scoped per container: activation, search, and dropdown labels no longer bleed into nested tab sets; nested user selections are preserved when switching outer tabs.
- Legacy v1 flat-hash fallback with automatic URL upgrade.
- Dropdown-select submenus in top/bottom layouts: selecting a child closes the menu and shows the child title on the parent button; label reverts when the submenu is no longer active. Click-outside closes open dropdowns.
- Accessibility: `role="tablist"/"tab"/"tabpanel"`, `aria-selected`, `aria-controls`, `aria-expanded`, keyboard navigation (arrows, Home/End, Space/Enter, Escape), visible focus styles, `prefers-reduced-motion`.
- Search: haystack truncated to 400 chars (page-size fix), parent chips stay visible when a child matches, non-matching children hidden.
- Security: password-form URL built from `home_url()` instead of `$_SERVER['HTTP_HOST']`; save handler hooked to `save_post_tab_item` only, with `wp_unslash` and revision guard; password-protected content excluded from the search index.
- Submenu heights use `scrollHeight` (long menus no longer clip at 500px).
- Duplicate Custom Tab ID warning in the meta box; `uninstall.php` cleanup.

### 2.0.4
- Rebuilt from the proven v2.0.0 baseline: routing code, structure, and init position are IDENTICAL to the version confirmed working in production. Changes are strictly additive:
- Legacy aliases: "Legacy IDs" field in the Tab Item meta box and `legacy=""` attribute on inline `[custom_tab]`. Old v1 flat hashes resolve via alias and rewrite to `#instance/current-id` without changing the tab's clean ID.
- jQuery 4 safety: `$.trim` (removed in jQuery 4) replaced with native `String.trim()`.
- Mobile: submenus behave as dropdown selects on all layouts at <=768px; click-outside close; breakpoint re-sync. All mobile extras run AFTER routing init inside a try/catch, so they can never prevent routing from initializing.
- Deterministic init: router runs synchronously when the DOM is already parsed; window-load safety net for late-injected containers.
- Verified: 15/15 automated routing tests passing (default activation, modern deep links, nested chains, alias resolve + rewrite, dropdown labels, unknown-hash fallback).

### 2.1.0
- Frontend rewritten in NATIVE JavaScript — zero dependencies, no jQuery. Eliminates every jQuery-related failure mode: version differences (`$.trim` removal in jQuery 4), load order, themes without jQuery, and optimizer bundles breaking `jQuery is not defined`. All events are delegated on `document`, so late-rendered tabs work.
- Identical behavior to 2.0.4: hash routing, legacy flat-hash fallback (current-id AND data-legacy-ids alias resolution with URL upgrade), nested chains, dropdown-select submenus (top/bottom + all layouts on mobile), label swap/revert, click-outside close, keyboard navigation, ARIA sync, per-instance search, breakpoint re-sync.
- Verified: 16/16 automated routing tests, including the production scenario (flat hash equal to a tab's current ID, e.g. #1722338859771-9ad29a5a-8ba9).

### 2.1.1
- Fixed a CSS specificity bug where the mobile pill row silently inherited `flex-wrap: wrap` from the desktop top/bottom rule (a more specific selector than the mobile override), so tabs could wrap unpredictably instead of following the intended mobile presentation.
- Mobile tab row now wraps onto multiple centered lines so every top-level tab is visible at once, instead of the tabs behaving as a horizontally-scrolling strip.
- Fixed submenus on mobile: previously they expanded inline as an accordion inside the pill row, which visually broke the row's layout. They now open as a floating dropdown panel below their pill, matching the desktop top/bottom dropdown, for every layout.
- Click-outside-to-close is now driven by `pointerdown` (falling back to `touchstart`) instead of only `click`, so closing an open dropdown by tapping elsewhere is reliable on touch devices — iOS Safari doesn't consistently dispatch `click` on plain, non-interactive elements.
