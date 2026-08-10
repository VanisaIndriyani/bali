<?php
$user = currentUser();
$closeMain = (bool)$user;
$closeApp = (bool)$user;
?>
<?php if ($closeMain): ?>
    </div><!-- .main-wrapper -->
<?php endif; ?>
<?php if ($closeApp): ?>
</div><!-- .app-layout (sidebar + main) -->
<?php endif; ?>

<?php if (!isset($hideNavbar) || !$hideNavbar): ?>
    <script>
        setTimeout(function() {
            var alerts = document.querySelectorAll('.alert-auto-dismiss');
            alerts.forEach(function(alert) {
                if (alert) {
                    alert.style.transition = 'opacity 0.5s, transform 0.5s';
                    alert.style.opacity = '0';
                    alert.style.transform = 'translateY(-10px)';
                    setTimeout(function() { alert.remove(); }, 500);
                }
            });
        }, 4500);
    </script>
<?php endif; ?>
</body>
</html>
