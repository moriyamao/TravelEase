<?php
/**
 * App header (redesign): skip link, logo and main navigation for every
 * signed-in page. The navigation follows the signed-in system role
 * (customer, staff or admin) from the server-side session, so system
 * roles never see each other's links.
 *
 * Optional variables set by the including page:
 *   $navCurrent           key of the current nav item, or null
 *   $unreadNotifications  customer badge count; looked up here when not set
 */
require_once __DIR__ . '/../csrf.php';
$navCurrent = $navCurrent ?? null;
$navRole = current_user_role();
if ($navRole === 'customer' && !isset($unreadNotifications) && isset($pdo)) {
    require_once __DIR__ . '/../notifications.php';
    $unreadNotifications = count_unread_notifications($pdo, (int) current_user_id());
}
$navUnread = $navRole === 'customer' ? (int) ($unreadNotifications ?? 0) : 0;
$navItems = [
    'customer' => [
        'trips' => ['/customer/dashboard.php', 'My trips'],
        'notifications' => ['/customer/notifications.php', 'Notifications'],
        'profile' => ['/profile.php', 'Profile'],
    ],
    'staff' => [
        'staff-home' => ['/staff/dashboard.php', 'Dashboard'],
        'queue' => ['/staff/escalations.php', 'Support queue'],
        'profile' => ['/profile.php', 'Profile'],
    ],
    'admin' => [
        'admin-home' => ['/admin/dashboard.php', 'Users'],
        'profile' => ['/profile.php', 'Profile'],
    ],
][$navRole] ?? [];
$navHome = dashboard_path_for_role($navRole);
?>
<a class="skip-link" href="#main-content">Skip to main content</a>
<header class="app-header">
    <div class="app-header-inner">
        <a class="app-brand" href="<?= htmlspecialchars($navHome, ENT_QUOTES, 'UTF-8') ?>">
            <img src="/assets/images/travelease-logo-mark.svg" alt="" width="34" height="34">
            <span class="app-wordmark">Travel<span>Ease</span></span>
            <?php if ($navRole === 'staff' || $navRole === 'admin'): ?><span class="app-role-tag"><?= $navRole === 'staff' ? 'Staff' : 'Admin' ?></span><?php endif; ?>
        </a>
        <nav class="app-nav" aria-label="Main">
            <?php foreach ($navItems as $navKey => [$navHref, $navLabel]): ?>
                <a href="<?= htmlspecialchars($navHref, ENT_QUOTES, 'UTF-8') ?>"<?= $navCurrent === $navKey ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($navLabel, ENT_QUOTES, 'UTF-8') ?><?php if ($navKey === 'notifications' && $navUnread > 0): ?><span class="nav-count"><span class="sr-only">, </span><?= $navUnread > 99 ? '99+' : $navUnread ?><span class="sr-only"> unread</span></span><?php endif; ?></a>
            <?php endforeach; ?>
            <form method="post" action="/auth/logout.php" class="nav-signout-form"><?= csrf_field() ?><button type="submit" class="nav-signout">Sign out</button></form>
        </nav>
    </div>
</header>
