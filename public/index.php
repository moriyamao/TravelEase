<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';

if (is_logged_in()) {
    header('Location: /includes/redirect_dashboard.php');
} else {
    header('Location: /auth/login.php');
}
exit;
