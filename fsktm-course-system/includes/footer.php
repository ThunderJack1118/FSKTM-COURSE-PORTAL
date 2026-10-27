    </main>
    <footer class="main-footer">
        <div class="container">
            <div class="footer-section">
                <h3>About UTHM Secure Course Portal</h3>
                <p>A web security demonstration prototype for secure course registration, authentication, session management and protected academic access.</p>
            </div>
            <div class="footer-section">
                <h3>Quick Links</h3>
                <ul>
                    <li><a href="<?php echo BASE_URL; ?>/pages/courses.php">All Courses</a></li>
                    <li><a href="#">Academic Calendar</a></li>
                    <li><a href="#">Contact Us</a></li>
                </ul>
            </div>
            <div class="footer-section">
                <h3>Contact</h3>
                <p>Email: fsktm@uthm.edu.my</p>
                <p>Phone: +607-453 7000</p>
            </div>
        </div>
        <div class="copyright">
            <p>&copy; <?php echo date('Y'); ?> UTHM Secure Course Portal. All rights reserved.</p>
        </div>
    </footer>
    <?php if (function_exists('isAdmin') && isAdmin()): ?>
        <script src="<?php echo BASE_URL; ?>/assets/js/admin.js"></script>
    <?php endif; ?>
    <script src="<?php echo BASE_URL; ?>/assets/js/main.js"></script>
</body>
</html>
