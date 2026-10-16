// Shared UI helpers: theme toggle, site-wide toasts, confirm modal,
// tabbed admin sections, responsive table labels, and scroll-to-top.
(function () {
    'use strict';

    var THEME_KEY = 'amdpms-theme';

    function onReady(fn) {
        if (document.readyState !== 'loading') { fn(); } else { document.addEventListener('DOMContentLoaded', fn); }
    }

    /* ---- Theme ---- */
    function currentTheme() {
        try { return localStorage.getItem(THEME_KEY) || 'light'; } catch (e) { return 'light'; }
    }

    function updateThemeUI() {
        var dark = currentTheme() === 'dark';
        document.querySelectorAll('.theme-toggle').forEach(function (btn) {
            btn.setAttribute('aria-pressed', String(dark));
            var icon = btn.querySelector('.theme-icon');
            if (icon) icon.textContent = dark ? '\u2600\uFE0F' : '\uD83C\uDF19';
        });
    }

    function toggleTheme() {
        try { localStorage.setItem(THEME_KEY, currentTheme() === 'dark' ? 'light' : 'dark'); } catch (e) {}
        document.documentElement.setAttribute('data-theme', currentTheme());
        updateThemeUI();
    }

    /* ---- Toast ---- */
    var toastTimer = null;

    function showToast(msg, type) {
        var el = document.getElementById('appToast');
        if (!el) {
            el = document.createElement('div');
            el.id = 'appToast';
            el.className = 'app-toast';
            (document.body || document.documentElement).appendChild(el);
        }
        el.textContent = msg;
        el.className = 'app-toast' + (type ? ' ' + type : '') + ' show';
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () {
            el.className = 'app-toast' + (type ? ' ' + type : '');
        }, 3200);
    }

    /* ---- Confirm modal ---- */
    var pendingForm = null;
    var pendingSubmitter = null;

    function openModal() {
        var overlay = document.getElementById('confirmModal');
        if (!overlay) return;
        overlay.classList.add('open');
        overlay.setAttribute('aria-hidden', 'false');
        var ok = document.getElementById('confirmOk');
        if (ok) ok.focus();
    }

    function closeModal() {
        var overlay = document.getElementById('confirmModal');
        if (!overlay) return;
        overlay.classList.remove('open');
        overlay.setAttribute('aria-hidden', 'true');
        pendingForm = null;
        pendingSubmitter = null;
    }

    function initConfirmModal() {
        var overlay = document.getElementById('confirmModal');
        var cancelBtn = document.getElementById('confirmCancel');
        var okBtn = document.getElementById('confirmOk');
        if (!overlay || !cancelBtn || !okBtn) return;

        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form || form.tagName !== 'FORM') return;
            if (!form.hasAttribute('data-confirm') || form.getAttribute('data-confirmed') === '1') return;
            e.preventDefault();
            e.stopImmediatePropagation();
            pendingForm = form;
            pendingSubmitter = e.submitter || form.querySelector('input[type=submit], button[type=submit]');
            var text = document.getElementById('confirmText');
            if (text) text.textContent = form.getAttribute('data-confirm') || 'Are you sure?';
            openModal();
        }, true);

        cancelBtn.addEventListener('click', closeModal);
        okBtn.addEventListener('click', function () {
            var form = pendingForm;
            var sub = pendingSubmitter;
            closeModal();
            if (!form) return;
            if (sub && sub.name) {
                var h = document.createElement('input');
                h.type = 'hidden';
                h.name = sub.name;
                h.value = sub.value || '';
                form.appendChild(h);
            }
            form.setAttribute('data-confirmed', '1');
            form.submit();
        });
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closeModal();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && document.getElementById('confirmModal').classList.contains('open')) closeModal();
        });
    }

    /* ---- Tabs ---- */
    function tabStorageKey() {
        var page = (window.location.pathname.split('/').pop() || 'page');
        return 'amdpms-active-tab-' + page;
    }

    function initTabs() {
        document.querySelectorAll('[data-tabs]').forEach(function (group) {
            var buttons = group.querySelectorAll('[data-tab-target]');
            var panels = document.querySelectorAll('[data-tab-panel]');

            function activate(id) {
                buttons.forEach(function (b) {
                    var active = b.getAttribute('data-tab-target') === id;
                    b.classList.toggle('active', active);
                    b.setAttribute('aria-selected', String(active));
                });
                panels.forEach(function (p) {
                    p.classList.toggle('active', p.id === id);
                });
            }

            buttons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var target = btn.getAttribute('data-tab-target');
                    activate(target);
                    try { localStorage.setItem(tabStorageKey(), target); } catch (e) {}
                });
            });

            // Restore the last-active tab so that after a form POST (update,
            // purchase, cycle count, write-off) the page reloads onto the same
            // tab the staff member was working in.
            var saved = null;
            try { saved = localStorage.getItem(tabStorageKey()); } catch (e) {}
            if (saved) {
                var exists = false;
                buttons.forEach(function (b) {
                    if (b.getAttribute('data-tab-target') === saved) exists = true;
                });
                if (exists) {
                    activate(saved);
                    var panel = document.getElementById(saved);
                    if (panel) {
                        setTimeout(function () {
                            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }, 60);
                    }
                }
            }
        });
    }

    /* ---- Responsive table labels (auto from <th> text) ---- */
    function initTableLabels() {
        document.querySelectorAll('.admin-table').forEach(function (table) {
            var ths = Array.prototype.map.call(
                table.querySelectorAll('thead th'),
                function (th) { return th.textContent.trim(); }
            );
            table.querySelectorAll('tbody tr').forEach(function (tr) {
                tr.querySelectorAll('td').forEach(function (td, i) {
                    if (!td.getAttribute('data-label') && ths[i]) {
                        td.setAttribute('data-label', ths[i]);
                    }
                });
            });
        });
    }

    /* ---- Quick-pick selects: <select data-param="date"> updates a named
           field of its own form and submits it. ---- */
    function initJumpSelects() {
        document.querySelectorAll('select[data-param]').forEach(function (select) {
            select.addEventListener('change', function () {
                if (!select.value) { return; }

                var form = select.form;
                var field = form ? form.querySelector('[name="' + select.getAttribute('data-param') + '"]') : null;

                if (form && field) {
                    field.value = select.value;
                    form.submit();
                    return;
                }

                // Not inside a form: fall back to a plain query string.
                var base = select.getAttribute('data-param');
                window.location.href = base + encodeURIComponent(select.value);
            });
        });
    }

    /* ---- Scroll reveal (slide-in on scroll) ---- */
    function initReveal() {
        var prefersReduced = false;
        try {
            prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        } catch (e) {}

        var targets = document.querySelectorAll(
            '.reveal, .stat-card, .trust-card, .product-card, .about-grid > *, ' +
            '.admin-stats > *, .page-header, .admin-section-title'
        );
        if (!targets.length) return;

        if (prefersReduced || !('IntersectionObserver' in window)) {
            targets.forEach(function (el) {
                el.classList.add('reveal', 'in-view');
            });
            return;
        }

        // Stagger siblings that share the same parent so animated groups ripple.
        var parentIndex = {};
        Array.prototype.forEach.call(targets, function (el) {
            var parent = el.parentNode && el.parentNode.nodeType === 1 ? el.parentNode : null;
            var key = parent ? parent.tagName + '#' + (parent.id || '') + '.' + parent.className : 'root';
            parentIndex[key] = parentIndex[key] || [];
            parentIndex[key].push(el);
        });
        Object.keys(parentIndex).forEach(function (key) {
            if (parentIndex[key].length < 2) return;
            parentIndex[key].forEach(function (el, i) {
                el.dataset.revealIndex = String(Math.min(i, 4));
            });
        });

        function settle(el) {
            var delay = parseFloat(el.dataset.revealIndex || '0') * 70;
            el.classList.add('in-view');
            setTimeout(function () {
                el.classList.remove('reveal', 'in-view');
                delete el.dataset.revealIndex;
            }, delay + 650);
        }

        var revealQueue = Array.prototype.filter.call(targets, function (el) {
            // Never leave elements inside a hidden container stuck invisible.
            if (!el.offsetParent) { settle(el); return false; }
            return true;
        });

        var revealObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                settle(entry.target);
                revealObserver.unobserve(entry.target);
            });
        }, { threshold: 0.12, rootMargin: '0px 0px -8% 0px' });

        revealQueue.forEach(function (el) {
            revealObserver.observe(el);
        });
    }

    /* ---- Scroll to top ---- */
    function initScrollTop() {
        var btn = document.getElementById('scrollTop');
        if (!btn) {
            btn = document.createElement('button');
            btn.type = 'button';
            btn.id = 'scrollTop';
            btn.className = 'scroll-top';
            btn.setAttribute('aria-label', 'Back to top');
            btn.textContent = '\u2191';
            (document.body || document.documentElement).appendChild(btn);
        }
        btn.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
        function onScroll() {
            btn.classList.toggle('show', (window.pageYOffset || document.documentElement.scrollTop) > 420);
        }
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }

    /* ---- Print / Save as PDF ---- */
    // Browsers expose no direct "save as PDF" API, so the print dialog's
    // "Save as PDF" destination is the standard route. Setting the document
    // title first gives the saved file a sensible default name.
    function printAsPdf(filename) {
        var prev = document.title;
        if (filename) { document.title = filename; }
        var restored = false;
        function restore() {
            if (restored) { return; }
            restored = true;
            document.title = prev;
            window.removeEventListener('afterprint', restore);
        }
        window.addEventListener('afterprint', restore);
        window.print();
        setTimeout(restore, 2000);
    }

    function initPrintPdf() {
        document.querySelectorAll('[data-print-pdf]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                printAsPdf(btn.getAttribute('data-print-pdf') || document.title);
            });
        });
    }

    /* ---- Preloader (hide once page resources finish) ---- */
    function ensurePreloader(text) {
        var pre = document.getElementById('preloader');
        if (!pre) {
            pre = document.createElement('div');
            pre.className = 'preloader';
            pre.id = 'preloader';
            pre.setAttribute('aria-hidden', 'true');
            pre.innerHTML = '<div class="preloader-pill" aria-hidden="true"></div>'
                + '<p class="preloader-text">Loading medications\u2026</p>';
            (document.body || document.documentElement).appendChild(pre);
        }
        var cap = pre.querySelector('.preloader-text');
        if (cap && text) cap.textContent = text;
        pre.classList.remove('hide');
        return pre;
    }

    function hidePreloader() {
        var pre = document.getElementById('preloader');
        if (!pre) return;
        pre.classList.add('hide');
        setTimeout(function () {
            if (pre.parentNode) pre.parentNode.removeChild(pre);
        }, 400);
    }

    window.APP_LOADER = {
        show: ensurePreloader,
        hide: hidePreloader
    };

    function initPreloader() {
        var pre = document.getElementById('preloader');
        if (!pre) return;
        function done() {
            if (!pre) return;
            pre.classList.add('hide');
            setTimeout(function () {
                if (pre.parentNode) pre.parentNode.removeChild(pre);
            }, 400);
        }
        if (document.readyState === 'complete') { done(); return; }
        window.addEventListener('load', done);
        setTimeout(done, 8000);
    }

    /* ---- Server-supplied toast (set via window.APP_TOAST) ---- */
    function initServerToast() {
        if (window.APP_TOAST && window.APP_TOAST.message) {
            showToast(window.APP_TOAST.message, window.APP_TOAST.type || 'success');
            delete window.APP_TOAST;
        }
    }

    onReady(function () {
        document.documentElement.setAttribute('data-theme', currentTheme());
        updateThemeUI();
        document.querySelectorAll('.theme-toggle').forEach(function (btn) {
            btn.addEventListener('click', toggleTheme);
        });
        initConfirmModal();
        initTabs();
        initTableLabels();
        initJumpSelects();
        initPrintPdf();
        initScrollTop();
        initReveal();
        initPreloader();
        initServerToast();
    });
})();
