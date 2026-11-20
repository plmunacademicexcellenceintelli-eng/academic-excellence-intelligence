<?php
// Protected page (Admin only). The access check runs on the server before anything is sent.
require __DIR__ . '/includes/bootstrap.php';
render_portal_page(ROLE_ADMIN, 'academic-records');
