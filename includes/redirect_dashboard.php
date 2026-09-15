<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/auth.php';

require_login();

switch (current_user_role()) {
    case 'admin':
        header('Location: /admin/dashboard.php');
        break;
    case 'staff':
        header('Location: /staff/dashboard.php');
        break;
    case 'customer':
    default:
        header('Location: /customer/dashboard.php');
        break;
}
exit;
