    </main>

    <footer class="site-footer">
        <p>&copy; <?= date('Y') ?> <?= SYSTEM_NAME_FULL ?></p>
        <p class="footer-note"><?= SYSTEM_ACRONYM ?> — For authorized pharmacy personnel only. All dispensing activity is logged.</p>
    </footer>

    <?php if (($currentPage ?? '') === 'shop.php'): ?>
        <?php include __DIR__ . '/dispense-cart.php'; ?>
    <?php endif; ?>

    <button type="button" class="scroll-top" id="scrollTop" aria-label="Back to top">↑</button>

    <div class="app-toast" id="appToast" role="status" aria-live="polite"></div>

    <div class="modal-overlay" id="confirmModal" aria-hidden="true">
        <div class="modal" role="dialog" aria-modal="true" aria-label="Confirm action">
            <h3>Confirm action</h3>
            <p id="confirmText">Are you sure?</p>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" id="confirmCancel">Cancel</button>
                <button type="button" class="btn-primary btn-danger-solid" id="confirmOk">Confirm</button>
            </div>
        </div>
    </div>

    <script src="ui.js?v=<?= filemtime(dirname(__DIR__) . '/ui.js') ?>"></script>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var menuToggle = document.getElementById('menu-toggle');
        var navbar = document.getElementById('navbar');
        if (menuToggle && navbar) {
            menuToggle.addEventListener('click', function () {
                navbar.classList.toggle('active');
            });
        }

        // Admin dropdown: tapping the burger toggles the menu (touch/keyboard friendly).
        document.querySelectorAll('.nav-drop').forEach(function (drop) {
            var burger = drop.querySelector('.nav-drop-burger');
            var trigger = drop.querySelector('.nav-drop-trigger');
            if (!burger) return;
            burger.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var open = drop.classList.toggle('open');
                if (trigger) trigger.setAttribute('aria-expanded', String(open));
            });
            // Close when an item is picked on mobile.
            drop.querySelectorAll('.nav-drop-menu a').forEach(function (link) {
                link.addEventListener('click', function () {
                    drop.classList.remove('open');
                    if (trigger) trigger.setAttribute('aria-expanded', 'false');
                });
            });
        });

        // Admin quick-links dropdown on the admin dashboard page.
        document.querySelectorAll('.quick-nav').forEach(function (nav) {
            var toggle = nav.querySelector('.quick-nav-toggle');
            if (!toggle) return;
            toggle.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var open = nav.classList.toggle('open');
                toggle.setAttribute('aria-expanded', String(open));
            });
            nav.querySelectorAll('.quick-nav-menu a').forEach(function (link) {
                link.addEventListener('click', function () {
                    nav.classList.remove('open');
                    toggle.setAttribute('aria-expanded', 'false');
                });
            });
        });
    });
    </script>
</body>
</html>
