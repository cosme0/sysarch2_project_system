    </main>

    <footer class="site-footer">
        <p>&copy; <?= date('Y') ?> <?= SYSTEM_NAME_FULL ?></p>
        <p class="footer-note"><?= SYSTEM_ACRONYM ?> — For authorized pharmacy personnel only. All dispensing activity is logged.</p>
    </footer>

    <?php include __DIR__ . '/dispense-cart.php'; ?>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var menuToggle = document.getElementById('menu-toggle');
        var navbar = document.getElementById('navbar');
        if (menuToggle && navbar) {
            menuToggle.addEventListener('click', function () {
                navbar.classList.toggle('active');
            });
        }
    });
    </script>
</body>
</html>
