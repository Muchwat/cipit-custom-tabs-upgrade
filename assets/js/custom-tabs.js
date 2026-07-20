/**
 * CIPIT Custom Tabs — frontend behavior. v2.1.0 — NATIVE JS, no jQuery.
 *
 * Behavior is identical to the proven baseline:
 * - Hash routing: #instanceId/tabId[/nestedInstanceId/nestedTabId/...]
 * - Legacy fallback: flat v1 hashes resolve via the tab's current id OR its
 *   data-legacy-ids aliases, then the URL is rewritten to the modern format.
 * - Dropdown-select submenus: layout-top/layout-bottom always; ALL layouts
 *   on small screens (<=768px). Parent button shows the active child's
 *   title and reverts when the submenu is no longer active.
 * - Per-container scoping so nested tab sets never interfere.
 * - Keyboard navigation and per-instance search.
 *
 * No dependencies. Safe to load anywhere, any order, bundled or not.
 */
(function () {
    'use strict';

    /* ------------------------------------------------------------------
     * Small utilities.
     * ---------------------------------------------------------------- */

    var cssEscape = (window.CSS && typeof window.CSS.escape === 'function')
        ? function (v) { return window.CSS.escape(v); }
        : function (v) { return String(v).replace(/([^a-zA-Z0-9_-])/g, '\\$1'); };

    function toArray(list) {
        return Array.prototype.slice.call(list || []);
    }

    // Guarded matchMedia: degrade to desktop behavior instead of throwing.
    var mobileQuery;
    try {
        mobileQuery = window.matchMedia('(max-width: 768px)');
    } catch (err) {
        mobileQuery = { matches: false };
    }

    /* ------------------------------------------------------------------
     * Scoped traversal helpers.
     * Nested containers live inside content panels, never inside the
     * header list, so querying within the header list is safe; panels
     * are selected as direct children of this container's contents wrap.
     * ---------------------------------------------------------------- */

    function headerList(container) {
        return container.querySelector(':scope > .tab-headers-list');
    }

    function panelsWrap(container) {
        return container.querySelector(':scope > .tab-contents-wrap');
    }

    function ownPanels(container) {
        var wrap = panelsWrap(container);
        return wrap ? toArray(wrap.querySelectorAll(':scope > .custom-tabs-content')) : [];
    }

    function ownHeaders(container) {
        var list = headerList(container);
        return list ? toArray(list.querySelectorAll('.custom-tabs-header')) : [];
    }

    function isDropdownLayout(container) {
        return mobileQuery.matches ||
            container.classList.contains('layout-top') ||
            container.classList.contains('layout-bottom');
    }

    /* ------------------------------------------------------------------
     * Submenu open/close (keeps aria-expanded and max-height in sync;
     * uses scrollHeight so long menus never clip).
     * ---------------------------------------------------------------- */

    function expandSubmenu(li) {
        var list = li.querySelector(':scope > .tab-submenu-list');
        var toggle = li.querySelector(':scope > .parent-toggle');
        li.classList.add('is-expanded');
        if (toggle) { toggle.setAttribute('aria-expanded', 'true'); }
        if (list) { list.style.maxHeight = list.scrollHeight + 'px'; }
    }

    function collapseSubmenu(li) {
        var list = li.querySelector(':scope > .tab-submenu-list');
        var toggle = li.querySelector(':scope > .parent-toggle');
        li.classList.remove('is-expanded');
        if (toggle) { toggle.setAttribute('aria-expanded', 'false'); }
        if (list) { list.style.maxHeight = '0'; }
    }

    /* ------------------------------------------------------------------
     * Dropdown-select labels.
     * ---------------------------------------------------------------- */

    function toggleSpan(li) {
        var toggle = li.querySelector(':scope > .parent-toggle');
        return toggle ? toggle.querySelector('span') : null;
    }

    // Restore every parent-toggle in THIS container to its original text.
    function resetParentToggleLabels(container) {
        var list = headerList(container);
        if (!list) { return; }
        toArray(list.querySelectorAll(':scope > .tab-header-item.has-submenu')).forEach(function (li) {
            var span = toggleSpan(li);
            if (span && span.dataset.originalTitle) {
                span.textContent = span.dataset.originalTitle;
            }
        });
    }

    // Show the active child's title on the parent dropdown button.
    function setParentToggleLabel(parentItem, childTitle) {
        var span = toggleSpan(parentItem);
        if (!span) { return; }
        if (!span.dataset.originalTitle) {
            span.dataset.originalTitle = span.textContent;
        }
        span.textContent = childTitle;
    }

    /* ------------------------------------------------------------------
     * Routing helpers.
     * ---------------------------------------------------------------- */

    function updateHashPath(instanceId, tabId) {
        var currentHash = window.location.hash.replace(/^#+/, '');
        var segments = currentHash ? currentHash.split('/') : [];
        var idx = segments.indexOf(instanceId);
        if (idx !== -1) {
            segments = segments.slice(0, idx);
        }
        segments.push(instanceId, tabId);
        window.location.hash = segments.join('/');
    }

    // First activatable link of a container (submenu-aware).
    function firstTargetLink(container) {
        var headers = ownHeaders(container);
        var first = headers.length ? headers[0] : null;
        if (first && first.classList.contains('parent-toggle')) {
            var item = first.closest('.tab-header-item');
            first = item ? item.querySelector('.tab-submenu-item a') : null;
        }
        return first;
    }

    /* ------------------------------------------------------------------
     * Core activation.
     * ---------------------------------------------------------------- */

    function activateTab(container, instanceId, tabId, updateHash, scrollTo) {
        var list = headerList(container);
        if (!list) { return; }

        ownHeaders(container).forEach(function (h) {
            h.classList.remove('tab-active');
            h.classList.add('tab-inactive');
            if (h.getAttribute('role') === 'tab') {
                h.setAttribute('aria-selected', 'false');
            }
        });
        toArray(list.querySelectorAll('.tab-header-item, .tab-submenu-item')).forEach(function (el) {
            el.classList.remove('is-active');
        });
        ownPanels(container).forEach(function (p) {
            p.classList.add('hidden');
        });
        resetParentToggleLabels(container);

        var targetLink = list.querySelector('a[data-target="' + cssEscape(tabId) + '"]');

        // Graceful fallback: unknown tabId -> first available tab.
        if (!targetLink) {
            targetLink = firstTargetLink(container);
            if (!targetLink) {
                return; // Truly empty
            }
            tabId = targetLink.getAttribute('data-target');
        }

        targetLink.classList.remove('tab-inactive');
        targetLink.classList.add('tab-active');
        targetLink.setAttribute('aria-selected', 'true');
        var tabItem = targetLink.closest('.tab-header-item');
        if (tabItem) { tabItem.classList.add('is-active'); }

        var submenuList = targetLink.closest('.tab-submenu-list');
        if (submenuList) {
            var submenuItem = targetLink.closest('.tab-submenu-item');
            if (submenuItem) { submenuItem.classList.add('is-active'); }
            var parentItem = submenuList.closest('.tab-header-item');
            if (parentItem) {
                if (isDropdownLayout(container)) {
                    // Dropdown-select behavior: child title on the button, menu closes.
                    setParentToggleLabel(parentItem, (targetLink.textContent || '').trim());
                    collapseSubmenu(parentItem);
                } else {
                    // Sidebar layouts keep the accordion expanded.
                    expandSubmenu(parentItem);
                }
            }
        }

        var contentPanel = null;
        ownPanels(container).forEach(function (p) {
            if (p.id === tabId) { contentPanel = p; }
        });
        if (contentPanel) {
            contentPanel.classList.remove('hidden');

            // GUARANTEE: nested tab containers inside the activated panel get
            // their first tab activated if nothing is active yet. Deeper user
            // selections are preserved: only inactive containers are touched.
            toArray(contentPanel.querySelectorAll('.custom-tabs-container')).forEach(function (nested) {
                var hasActive = ownHeaders(nested).some(function (h) {
                    return h.classList.contains('tab-active');
                });
                if (hasActive) { return; }
                var nestedInstanceId = nested.getAttribute('data-group-instance');
                var nestedFirst = firstTargetLink(nested);
                var nestedTarget = nestedFirst ? nestedFirst.getAttribute('data-target') : null;
                if (nestedInstanceId && nestedTarget) {
                    activateTab(nested, nestedInstanceId, nestedTarget, false, false);
                }
            });
        }

        if (updateHash) {
            updateHashPath(instanceId, tabId);
        }

        if (scrollTo && contentPanel) {
            setTimeout(function () {
                try {
                    var top = contentPanel.getBoundingClientRect().top + window.pageYOffset - 100;
                    window.scrollTo({ top: top, behavior: 'smooth' });
                } catch (err) {
                    try { window.scrollTo(0, contentPanel.offsetTop - 100); } catch (err2) { /* noop */ }
                }
            }, 100);
        }
    }

    /* ------------------------------------------------------------------
     * Router.
     * ---------------------------------------------------------------- */

    function syncUiWithRoute() {
        var hash = decodeURIComponent(window.location.hash.replace(/^#+/, ''));
        var segments = hash ? hash.split('/') : [];
        var activatedInstances = [];
        var i;

        for (i = 0; i < segments.length - 1; i += 2) {
            var instanceId = segments[i];
            var tabId = segments[i + 1];

            var container = document.querySelector('[data-group-instance="' + cssEscape(instanceId) + '"]');
            if (container) {
                activatedInstances.push(instanceId);
                var shouldScroll = (i >= segments.length - 2);
                activateTab(container, instanceId, tabId, false, shouldScroll);
            }
        }

        // LEGACY FALLBACK: v1 used flat hashes like #1740660865543-2fdc6c7c-720f.
        // Resolution order:
        //   1. a tab whose CURRENT id equals the hash (data-target match);
        //   2. a tab listing the hash in data-legacy-ids (aliases from the
        //      Tab Item meta box or the shortcode legacy="" attribute).
        // The hash is rewritten to instanceId/currentTabId; the resulting
        // hashchange re-runs this router with a valid modern route.
        if (activatedInstances.length === 0 && hash.length > 0) {
            var escapedHash = cssEscape(hash);
            var legacyLink =
                document.querySelector('.custom-tabs-container a[data-target="' + escapedHash + '"]') ||
                document.querySelector('.custom-tabs-container a[data-legacy-ids~="' + escapedHash + '"]');

            if (legacyLink) {
                var canonicalId = legacyLink.getAttribute('data-target');
                var path = [];
                var cont = legacyLink.closest('.custom-tabs-container');
                path.unshift(cont.getAttribute('data-group-instance') + '/' + canonicalId);

                // Prepend each ancestor container's instanceId/panelId pair
                // so the whole nesting chain opens.
                var panel = cont.closest('.custom-tabs-content');
                while (panel) {
                    var outer = panel.closest('.custom-tabs-container');
                    if (!outer || !panel.id) { break; }
                    path.unshift(outer.getAttribute('data-group-instance') + '/' + panel.id);
                    panel = outer.closest('.custom-tabs-content');
                }

                window.location.hash = path.join('/');
                return;
            }
        }

        // Default activation for containers not covered by the route.
        toArray(document.querySelectorAll('.custom-tabs-container')).forEach(function (container) {
            var cid = container.getAttribute('data-group-instance');

            if (activatedInstances.indexOf(cid) !== -1) { return; }

            var hasActive = ownHeaders(container).some(function (h) {
                return h.classList.contains('tab-active');
            });
            if (hasActive) { return; }

            var first = firstTargetLink(container);
            var target = first ? first.getAttribute('data-target') : null;
            if (cid && target) {
                activateTab(container, cid, target, false, false);
            }
        });
    }

    /* ------------------------------------------------------------------
     * ROUTING REGISTRATION — before all other listeners so nothing can
     * prevent deep links and the legacy fallback from initializing.
     * ---------------------------------------------------------------- */

    window.addEventListener('hashchange', syncUiWithRoute);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', syncUiWithRoute);
    } else {
        // DOM already parsed (footer script, deferred load): run now.
        syncUiWithRoute();
    }

    // Safety net for containers injected late (page builders, lazy render).
    window.addEventListener('load', function () {
        if (document.querySelector('.custom-tabs-container') &&
            !document.querySelector('.custom-tabs-container .custom-tabs-header.tab-active')) {
            syncUiWithRoute();
        }
    });

    /* ------------------------------------------------------------------
     * Events — all delegated on document, so late-rendered tabs work too.
     * ---------------------------------------------------------------- */

    // Tab link clicks (not parent toggles).
    document.addEventListener('click', function (e) {
        var link = e.target.closest ? e.target.closest('.custom-tabs-header') : null;

        // 1) Parent toggle: open/close the dropdown.
        if (link && link.classList.contains('parent-toggle')) {
            e.preventDefault();
            e.stopPropagation();
            var li = link.closest('.tab-header-item');
            var container = link.closest('.custom-tabs-container');
            if (!li || !container) { return; }

            if (li.classList.contains('is-expanded')) {
                collapseSubmenu(li);
            } else {
                var list = headerList(container);
                if (list) {
                    toArray(list.querySelectorAll(':scope > .tab-header-item.has-submenu')).forEach(function (other) {
                        if (other !== li) { collapseSubmenu(other); }
                    });
                }
                expandSubmenu(li);
            }
            return;
        }

        // 2) Regular tab link: activate.
        if (link) {
            var c = link.closest('.custom-tabs-container');
            var instanceId = c ? c.getAttribute('data-group-instance') : null;
            var target = link.getAttribute('data-target');
            if (c && instanceId && target) {
                e.preventDefault();
                e.stopPropagation();
                activateTab(c, instanceId, target, true, false);
            }
            return;
        }

        // 3) Click outside any submenu: close open dropdowns. Top/bottom
        // layouts always; ALL layouts on mobile (pill-bar mode).
        if (!e.target.closest || !e.target.closest('.tab-header-item.has-submenu')) {
            var selector = mobileQuery.matches
                ? '.custom-tabs-container'
                : '.custom-tabs-container.layout-top, .custom-tabs-container.layout-bottom';
            toArray(document.querySelectorAll(selector)).forEach(function (container) {
                var list = headerList(container);
                if (!list) { return; }
                toArray(list.querySelectorAll(':scope > .tab-header-item.has-submenu.is-expanded')).forEach(function (li) {
                    collapseSubmenu(li);
                });
            });
        }
    });

    // Keyboard support: arrows move between visible tab links, Space/Enter
    // activate, Escape closes an open dropdown and refocuses its toggle.
    document.addEventListener('keydown', function (e) {
        var link = e.target.closest ? e.target.closest('.custom-tabs-header') : null;
        if (!link) { return; }
        var container = link.closest('.custom-tabs-container');
        if (!container) { return; }

        if (e.key === 'Escape') {
            var submenu = link.closest('.tab-submenu-list');
            if (submenu) {
                var li = submenu.closest('.tab-header-item');
                if (li) {
                    collapseSubmenu(li);
                    var toggle = li.querySelector(':scope > .parent-toggle');
                    if (toggle) { toggle.focus(); }
                }
                e.preventDefault();
            }
            return;
        }

        if (e.key === ' ' || e.key === 'Spacebar') {
            e.preventDefault();
            link.click();
            return;
        }

        var forward = (e.key === 'ArrowRight' || e.key === 'ArrowDown');
        var backward = (e.key === 'ArrowLeft' || e.key === 'ArrowUp');
        if (!forward && !backward && e.key !== 'Home' && e.key !== 'End') {
            return;
        }

        var focusables = ownHeaders(container).filter(function (h) {
            return h.getClientRects().length > 0; // visible
        });
        var idx = focusables.indexOf(link);
        if (idx === -1) { return; }

        e.preventDefault();
        var next = idx;
        if (forward) {
            next = (idx + 1) % focusables.length;
        } else if (backward) {
            next = (idx - 1 + focusables.length) % focusables.length;
        } else if (e.key === 'Home') {
            next = 0;
        } else if (e.key === 'End') {
            next = focusables.length - 1;
        }
        focusables[next].focus();
    });

    // Search: scoped to this instance only; a parent chip stays visible when
    // any of its children match, and non-matching children are hidden.
    document.addEventListener('input', function (e) {
        if (!e.target.classList || !e.target.classList.contains('custom-tabs-search-input')) {
            return;
        }
        var query = e.target.value.toLowerCase();
        var wrap = e.target.closest('.custom-tabs-header-wrap');
        var container = wrap ? wrap.nextElementSibling : null;
        if (!container || !container.classList.contains('custom-tabs-container')) {
            return;
        }
        var list = headerList(container);
        if (!list) { return; }
        var visibleHeaders = 0;

        toArray(list.querySelectorAll(':scope > .tab-header-item')).forEach(function (item) {
            var selfMatch = ((item.getAttribute('data-search-content') || '').toLowerCase().indexOf(query) > -1);
            var childMatches = 0;

            toArray(item.querySelectorAll('.tab-submenu-item')).forEach(function (child) {
                var childMatch = ((child.getAttribute('data-search-content') || '').toLowerCase().indexOf(query) > -1);
                if (childMatch || selfMatch) {
                    child.classList.remove('hidden');
                    if (childMatch) { childMatches++; }
                } else {
                    child.classList.add('hidden');
                }
            });

            if (selfMatch || childMatches > 0) {
                item.classList.remove('hidden');
                visibleHeaders++;
            } else {
                item.classList.add('hidden');
            }
        });

        var wrapEl = panelsWrap(container);
        var noResults = wrapEl ? wrapEl.querySelector(':scope > .no-results-message') : null;
        if (noResults) {
            noResults.classList.toggle('hidden', visibleHeaders !== 0);
        }
    });

    /* ------------------------------------------------------------------
     * Optional extras — a failure here can never affect routing.
     * ---------------------------------------------------------------- */

    try {
        // Re-apply correct submenu presentation when crossing the breakpoint.
        var refreshSubmenuPresentation = function () {
            toArray(document.querySelectorAll('.custom-tabs-container')).forEach(function (container) {
                resetParentToggleLabels(container);
                var list = headerList(container);
                if (!list) { return; }
                toArray(list.querySelectorAll(':scope > .tab-header-item.has-submenu')).forEach(function (li) {
                    collapseSubmenu(li);
                });

                var active = list.querySelector('.custom-tabs-header.tab-active');
                if (active && active.closest('.tab-submenu-list')) {
                    var parentItem = active.closest('.tab-submenu-list').closest('.tab-header-item');
                    if (parentItem) {
                        if (isDropdownLayout(container)) {
                            setParentToggleLabel(parentItem, (active.textContent || '').trim());
                        } else {
                            expandSubmenu(parentItem);
                        }
                    }
                }
            });
        };

        if (mobileQuery && typeof mobileQuery.addEventListener === 'function') {
            mobileQuery.addEventListener('change', refreshSubmenuPresentation);
        } else if (mobileQuery && typeof mobileQuery.addListener === 'function') {
            mobileQuery.addListener(refreshSubmenuPresentation); // older Safari
        }
    } catch (err) {
        // Nice-to-have only; never let it interfere.
    }
})();
