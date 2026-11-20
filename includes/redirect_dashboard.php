<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/auth.php';

require_login();

header('Location: ' . dashboard_path_for_role(current_user_role()));
exit;
