<?php
/**
 * Trip sub-page header (redesign): breadcrumb, page title and the trip
 * section tabs shared with the trip page. Include it after the page has
 * already authorized the current user for this trip.
 *
 * Required: $pdo, $tripId (int), $tripTab (tab key or null), $tripPageTitle
 * Optional: $tripPageLead (short description under the title)
 */
require_once __DIR__ . '/../trip_membership.php';
$tripHeaderId = (int) $tripId;
$tripHeaderStmt = $pdo->prepare('SELECT name, archived_at FROM trips WHERE id = :id');
$tripHeaderStmt->execute(['id' => $tripHeaderId]);
$tripHeaderRow = $tripHeaderStmt->fetch() ?: ['name' => 'Trip', 'archived_at' => null];
$tripHeaderOwner = trip_member_role($pdo, $tripHeaderId, (int) current_user_id()) === 'owner';
$tripHeaderTabs = [
    'itinerary' => ['/customer/trips/view.php?id=' . $tripHeaderId . '#itinerary', 'Itinerary'],
    'places' => ['/customer/trips/places.php?id=' . $tripHeaderId, 'Places &amp; map'],
    'expenses' => ['/customer/trips/expenses.php?id=' . $tripHeaderId, 'Expenses'],
    'references' => ['/customer/trips/references.php?id=' . $tripHeaderId, 'Bookings'],
    'reminders' => ['/customer/trips/reminders.php?id=' . $tripHeaderId, 'Reminders'],
    'history' => ['/customer/trips/history.php?id=' . $tripHeaderId, 'History'],
];
if ($tripHeaderOwner) {
    $tripHeaderTabs['share_links'] = ['/customer/trips/share_links.php?id=' . $tripHeaderId, 'Share links'];
}
$tripTab = $tripTab ?? null;
?>
<div class="page-head trip-subhead">
    <nav class="breadcrumb" aria-label="Breadcrumb">
        <a href="/customer/dashboard.php<?= $tripHeaderRow['archived_at'] !== null ? '?archived=1' : '' ?>"><?= $tripHeaderRow['archived_at'] !== null ? 'Archived trips' : 'My trips' ?></a>
        <span aria-hidden="true">/</span>
        <a href="/customer/trips/view.php?id=<?= $tripHeaderId ?>"><?= htmlspecialchars($tripHeaderRow['name'], ENT_QUOTES, 'UTF-8') ?></a>
        <span aria-hidden="true">/</span>
        <span aria-current="page"><?= htmlspecialchars($tripPageTitle, ENT_QUOTES, 'UTF-8') ?></span>
    </nav>
    <h1 class="page-title"><?= htmlspecialchars($tripPageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
    <?php if (!empty($tripPageLead)): ?><p class="section-lead"><?= htmlspecialchars($tripPageLead, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
</div>
<nav class="trip-tabs" aria-label="Trip sections">
    <?php foreach ($tripHeaderTabs as $tabKey => [$tabHref, $tabLabel]): ?>
        <a href="<?= htmlspecialchars($tabHref, ENT_QUOTES, 'UTF-8') ?>"<?= $tripTab === $tabKey ? ' aria-current="page"' : '' ?>><?= $tabLabel ?></a>
    <?php endforeach; ?>
</nav>
