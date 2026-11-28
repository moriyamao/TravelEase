<?php
/**
 * FR-15: the trip owner creates and revokes share links.
 *
 * - Join link: a verified customer who signs in can join the trip as a
 *   Viewer after confirming. View-only link: anyone with the link can see
 *   the destination, dates, time zone and the itinerary's dates, times,
 *   titles and locations, without signing in.
 * - Only a hash of each link is stored, so a new link is shown exactly once
 *   (from a one-time session flash) and can never be shown again; to share
 *   it again, revoke it and create a new one.
 * - Creating and revoking happen in share_action.php (POST, owner only).
 */
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/trip_membership.php';
require_once __DIR__ . '/../../includes/trip_shares.php';

require_role(['customer']);

$tripId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$tripId) {
    http_response_code(404);
    exit('Trip not found.');
}

$pdo = get_db_connection();
require_trip_permission($pdo, $tripId, 'manage_trip');

$stmt = $pdo->prepare('SELECT name FROM trips WHERE id = :id');
$stmt->execute(['id' => $tripId]);
$tripName = (string) $stmt->fetchColumn();
$links = list_trip_share_links($pdo, $tripId);

// The raw link exists only in this one-time flash; it is gone after this page load.
$newLink = $_SESSION['share_link_new'][$tripId] ?? null;
$notice = $_SESSION['share_notice'][$tripId] ?? null;
$error = $_SESSION['share_error'][$tripId] ?? null;
unset($_SESSION['share_link_new'][$tripId], $_SESSION['share_notice'][$tripId], $_SESSION['share_error'][$tripId]);
if (!is_array($newLink) || !is_string($newLink['url'] ?? null)) {
    $newLink = null;
}

$now = time();
$utc = fn($value) => $value === null ? null : strtotime($value . ' UTC');
$active = [];
$past = [];
foreach ($links as $link) {
    if ($link['revoked_at'] === null && $utc($link['expires_at']) > $now) {
        $active[] = $link;
    } else {
        $past[] = $link;
    }
}
$kindLabels = ['join' => 'Join link', 'view' => 'View-only link'];
$e = fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$when = fn($value) => $value === null ? '' : gmdate('M j, Y, H:i', $utc($value)) . ' UTC';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    <title>Share links — TravelEase</title>
    <link rel="icon" href="/assets/images/travelease-logo-mark.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">
    <link rel="stylesheet" href="/assets/css/trips.css">
    <link rel="stylesheet" href="/assets/css/shares.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/pages.css">
</head>
<body>
    <?php $navCurrent = null; require __DIR__ . '/../../includes/partials/app_header.php'; ?>
    <main class="app-page legacy-page" id="main-content" tabindex="-1">
        <?php $tripId = (int) $tripId; $tripTab = 'share_links'; $tripPageTitle = 'Share links'; $tripPageLead = null; require __DIR__ . '/../../includes/partials/trip_header.php'; ?>
        <p class="share-trip-name"><?= $e($tripName) ?></p>
        <?php if ($notice): ?><p class="form-notice" role="status"><?= $e($notice) ?></p><?php endif; ?>
        <?php if ($error): ?><p class="form-errors" role="alert"><?= $e($error) ?></p><?php endif; ?>

        <?php if ($newLink !== null): ?>
            <section class="share-card share-new" aria-labelledby="new-link-heading">
                <h2 id="new-link-heading">Your new <?= $e(strtolower($kindLabels[$newLink['kind'] ?? ''] ?? 'link')) ?></h2>
                <p><strong>Copy it now. It is shown only once</strong> and cannot be shown again after you leave or reload this page.</p>
                <div class="share-copy">
                    <label class="visually-hidden" for="new-share-url">New share link</label>
                    <input id="new-share-url" type="text" readonly value="<?= $e($newLink['url']) ?>" data-share-copy-source>
                    <button type="button" class="share-button" data-share-copy hidden>Copy link</button>
                </div>
                <p class="share-copy-status" data-share-copy-status role="status" aria-live="polite"></p>
                <?php if (!empty($newLink['expires_at'])): ?><p class="share-meta">Expires <?= $e($when($newLink['expires_at'])) ?>.</p><?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="share-card" aria-labelledby="about-heading">
            <h2 id="about-heading">How share links work</h2>
            <ul class="share-facts">
                <li><strong>Join link:</strong> someone who opens it, signs in with a verified customer account and confirms, joins this trip as a <strong>Viewer</strong>. You can change their role later. Someone already on the trip keeps their current role.</li>
                <li><strong>View-only link:</strong> anyone who has the link can see, without signing in, the destination, the trip dates, the time zone and each activity's date, times, title and location. It never shows the trip name, notes, costs, budgets, comments, votes, members, bookings or reminders.</li>
                <li>Anyone who gets a copy of a link can use it until it expires or you revoke it, so share it only with people you trust.</li>
                <li>Links last 1 or 7 days. A trip can have up to 10 active links.</li>
                <li>Revoking a link stops it at once. Transferring ownership of this trip or removing a member <strong>revokes all of its links</strong>, so a removed member cannot rejoin with an old link. A member leaving on their own does not revoke links.</li>
                <li>For safety, a link is shown only once, when you create it. To share it again later, revoke it and create a new one.</li>
            </ul>
        </section>

        <section class="share-card" aria-labelledby="create-heading">
            <h2 id="create-heading">Create a link</h2>
            <?php if (count($active) >= 10): ?>
                <p class="empty-state">This trip has 10 active links. Revoke one to create another.</p>
            <?php else: ?>
                <form method="post" action="/customer/trips/share_action.php" class="share-form" data-share-create>
                    <?= csrf_field() ?>
                    <input type="hidden" name="trip_id" value="<?= (int) $tripId ?>">
                    <input type="hidden" name="action" value="create">
                    <fieldset>
                        <legend>Type of link</legend>
                        <label class="share-choice"><input type="radio" name="kind" value="join" checked> Join link (they join as a Viewer after signing in)</label>
                        <label class="share-choice"><input type="radio" name="kind" value="view"> View-only link (no sign-in needed)</label>
                    </fieldset>
                    <label for="share-days">Expires after</label>
                    <select id="share-days" name="days">
                        <option value="1">1 day</option>
                        <option value="7" selected>7 days</option>
                    </select>
                    <div class="share-warning" data-share-publish>
                        <p>A view-only link lets <strong>anyone who has it</strong> see this trip's destination, dates, time zone and the date, times, title and location of every activity, without signing in.</p>
                        <label class="share-choice"><input type="checkbox" name="publish" value="1" data-share-publish-box> I understand. Make this itinerary viewable by anyone with the link.</label>
                    </div>
                    <button type="submit" class="share-button">Create link</button>
                </form>
            <?php endif; ?>
        </section>

        <section class="share-card" aria-labelledby="active-heading">
            <h2 id="active-heading">Active links (<?= count($active) ?> of 10)</h2>
            <?php if (!$active): ?>
                <p class="empty-state">No active links.</p>
            <?php else: ?>
                <ul class="share-list">
                    <?php foreach ($active as $link): ?>
                        <li>
                            <div>
                                <strong><?= $e($kindLabels[$link['kind']] ?? $link['kind']) ?></strong>
                                <span class="share-meta">Created <?= $e($when($link['created_at'])) ?> &middot; expires <?= $e($when($link['expires_at'])) ?></span>
                            </div>
                            <form method="post" action="/customer/trips/share_action.php">
                                <?= csrf_field() ?>
                                <input type="hidden" name="trip_id" value="<?= (int) $tripId ?>">
                                <input type="hidden" name="action" value="revoke">
                                <input type="hidden" name="link_id" value="<?= (int) $link['id'] ?>">
                                <button type="submit" class="share-button-secondary">Revoke</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <?php if ($past): ?>
            <section class="share-card" aria-labelledby="past-heading">
                <h2 id="past-heading">Expired and revoked links</h2>
                <ul class="share-list">
                    <?php foreach ($past as $link): ?>
                        <li>
                            <div>
                                <strong><?= $e($kindLabels[$link['kind']] ?? $link['kind']) ?></strong>
                                <span class="share-meta">Created <?= $e($when($link['created_at'])) ?> &middot;
                                    <?= $link['revoked_at'] !== null ? 'revoked ' . $e($when($link['revoked_at'])) : 'expired ' . $e($when($link['expires_at'])) ?></span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    </main>
    <script src="/assets/js/trip-share.js" defer></script>
</body>
</html>
