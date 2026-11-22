<?php
/**
 * FR-24: booking references and private attachments.
 *
 * Every trip member sees the references their role may view
 * (list_trip_references applies the visibility rules). Owners and editors
 * add, edit and delete the references they can see (reference_action.php).
 * Attachments inherit reference visibility; downloads always pass the PHP guard.
 */
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/trip_membership.php';
require_once __DIR__ . '/../../includes/trip_references.php';
require_once __DIR__ . '/../../includes/trip_documents.php';

require_role(['customer']);

$tripId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$tripId) {
    http_response_code(400);
    die('Invalid trip.');
}

$pdo = get_db_connection();
$tripRole = require_trip_permission($pdo, $tripId, 'view');
$canEdit = trip_role_can($tripRole, 'edit_itinerary');
$userId = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare('SELECT name, destination FROM trips WHERE id = :id');
$stmt->execute(['id' => $tripId]);
$trip = $stmt->fetch();

$references = list_trip_references($pdo, $tripId, $tripRole, $userId);
$documents = list_reference_documents($pdo, $references);

// Editing is scoped to the visible list, so a hidden reference can't be opened by ID.
$editing = null;
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($canEdit && $editId) {
    foreach ($references as $r) {
        if ((int) $r['id'] === $editId) {
            $editing = $r;
            break;
        }
    }
}

$notice = $_SESSION['reference_notice'][$tripId] ?? null;
$error = $_SESSION['reference_error'][$tripId] ?? null;
unset($_SESSION['reference_notice'][$tripId], $_SESSION['reference_error'][$tripId]);

$statusLabels = ['pending' => 'Pending', 'confirmed' => 'Confirmed'];
$e = fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$safeUrl = fn($url) => is_string($url) && preg_match('#^https://\S+$#i', $url) ? $url : null;
$fmtDate = fn($d) => $d ? date('M j, Y', strtotime($d)) : null;
$field = fn(string $key) => $editing[$key] ?? '';

/** Shared add/edit form fields. */
$renderFields = function () use ($e, $field, $editing): void {
    $p = $editing ? 'edit' : 'new';
    ?>
    <div class="reference-form-grid">
        <div class="reference-wide">
            <label for="<?= $p ?>-title">Title</label>
            <input id="<?= $p ?>-title" name="title" required maxlength="150" value="<?= $e($field('title')) ?>" placeholder="e.g. Flight to Cebu">
        </div>
        <div>
            <label for="<?= $p ?>-kind">Type</label>
            <select id="<?= $p ?>-kind" name="kind" required>
                <?php foreach (REFERENCE_KIND_LABELS as $value => $label): ?>
                    <option value="<?= $e($value) ?>"<?= $field('kind') === $value ? ' selected' : '' ?>><?= $e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="<?= $p ?>-status">Status</label>
            <select id="<?= $p ?>-status" name="status" required>
                <option value="pending"<?= $field('status') !== 'confirmed' ? ' selected' : '' ?>>Pending</option>
                <option value="confirmed"<?= $field('status') === 'confirmed' ? ' selected' : '' ?>>Confirmed</option>
            </select>
        </div>
        <div>
            <label for="<?= $p ?>-provider">Provider (optional)</label>
            <input id="<?= $p ?>-provider" name="provider" maxlength="100" value="<?= $e($field('provider')) ?>" placeholder="Airline, hotel, operator">
        </div>
        <div>
            <label for="<?= $p ?>-code">Confirmation code (optional)</label>
            <input id="<?= $p ?>-code" name="confirmation_code" maxlength="150" autocomplete="off" value="<?= $e($field('confirmation_code')) ?>">
        </div>
        <div>
            <label for="<?= $p ?>-start">Start date (optional)</label>
            <input id="<?= $p ?>-start" name="start_date" type="date" value="<?= $e($field('start_date')) ?>">
        </div>
        <div>
            <label for="<?= $p ?>-end">End date (optional)</label>
            <input id="<?= $p ?>-end" name="end_date" type="date" value="<?= $e($field('end_date')) ?>">
        </div>
        <div class="reference-wide">
            <label for="<?= $p ?>-url">Booking link (optional, https only)</label>
            <input id="<?= $p ?>-url" name="reference_url" type="url" maxlength="2048" pattern="https://.+" value="<?= $e($field('reference_url')) ?>" placeholder="https://">
        </div>
        <div class="reference-wide">
            <label for="<?= $p ?>-visibility">Who can see it</label>
            <select id="<?= $p ?>-visibility" name="visibility" required>
                <?php foreach (REFERENCE_VISIBILITY_LABELS as $value => $label): ?>
                    <option value="<?= $e($value) ?>"<?= ($editing ? $field('visibility') : 'private') === $value ? ' selected' : '' ?>><?= $e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <?php
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking references — <?= $e($trip['name']) ?> — TravelEase</title>
    <link rel="icon" href="/assets/images/travelease-logo-mark.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">
    <link rel="stylesheet" href="/assets/css/trips.css">
    <link rel="stylesheet" href="/assets/css/references.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/pages.css">
</head>
<body>
    <?php $navCurrent = null; require __DIR__ . '/../../includes/partials/app_header.php'; ?>
    <main class="app-page legacy-page" id="main-content" tabindex="-1">
        <?php $tripId = (int) $tripId; $tripTab = 'references'; $tripPageTitle = 'Booking references'; $tripPageLead = null; require __DIR__ . '/../../includes/partials/trip_header.php'; ?>

        <p class="eyebrow"><?= $e($trip['name']) ?> &middot; <?= $e($trip['destination']) ?></p>
        <?php if ($notice): ?><p class="form-notice" role="status"><?= $e($notice) ?></p><?php endif; ?>
        <?php if ($error): ?><p class="form-errors" role="alert"><?= $e($error) ?></p><?php endif; ?>
        <p class="reference-note">Keep flight, hotel and tour booking details here. Attachments accept PDF, JPEG or PNG up to 2 MB and follow the reference's visibility. Downloads are files, not safety or authenticity checks.</p>

        <?php if ($canEdit && $editing): ?>
        <section class="reference-card" aria-labelledby="edit-heading" id="edit">
            <h2 id="edit-heading">Edit reference</h2>
            <form method="post" action="/customer/trips/reference_action.php" class="reference-form">
                <?= csrf_field() ?>
                <input type="hidden" name="trip_id" value="<?= (int) $tripId ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="reference_id" value="<?= (int) $editing['id'] ?>">
                <input type="hidden" name="version" value="<?= (int) $editing['version'] ?>">
                <?php $renderFields(); ?>
                <div class="reference-actions">
                    <button type="submit">Save changes</button>
                    <a href="/customer/trips/references.php?id=<?= (int) $tripId ?>">Cancel</a>
                </div>
            </form>
        </section>
        <?php endif; ?>

        <section class="reference-card" aria-labelledby="list-heading">
            <h2 id="list-heading">References</h2>
            <?php if (!$references): ?>
                <p class="empty-state">No booking references you can see yet.<?= $canEdit ? ' Add one below.' : '' ?></p>
            <?php else: ?>
                <ul class="reference-list">
                    <?php foreach ($references as $r): ?>
                        <li class="reference-item" id="reference-<?= (int) $r['id'] ?>">
                            <div class="reference-head">
                                <strong><?= $e($r['title']) ?></strong>
                                <span class="reference-status reference-status-<?= $e($r['status']) ?>"><?= $e($statusLabels[$r['status']] ?? $r['status']) ?></span>
                            </div>
                            <p class="reference-meta">
                                <?= $e(REFERENCE_KIND_LABELS[$r['kind']] ?? $r['kind']) ?>
                                <?php if ($r['provider'] !== null && $r['provider'] !== ''): ?> &middot; <?= $e($r['provider']) ?><?php endif; ?>
                                <?php if ($r['start_date'] || $r['end_date']): ?>
                                    &middot; <?= $e($fmtDate($r['start_date']) ?? '?') ?><?php if ($r['end_date'] && $r['end_date'] !== $r['start_date']): ?> &ndash; <?= $e($fmtDate($r['end_date'])) ?><?php endif; ?>
                                <?php endif; ?>
                                &middot; <span class="reference-visibility"><?= $e(REFERENCE_VISIBILITY_LABELS[$r['visibility']] ?? $r['visibility']) ?></span>
                            </p>
                            <?php if ($r['confirmation_code'] !== null && $r['confirmation_code'] !== ''): ?>
                                <p class="reference-code">Confirmation code: <code><?= $e($r['confirmation_code']) ?></code></p>
                            <?php endif; ?>
                            <?php if ($link = $safeUrl($r['reference_url'])): ?>
                                <p class="reference-link"><a href="<?= $e($link) ?>" target="_blank" rel="noopener noreferrer">Open booking</a></p>
                            <?php endif; ?>
                            <?php foreach ($documents[$r['id']] ?? [] as $document): ?>
                                <div class="reference-actions">
                                    <a href="/customer/trips/document_download.php?id=<?= (int) $document['id'] ?>">Download <?= $e($document['original_name']) ?></a>
                                    <span><?= number_format($document['byte_size'] / 1024, 1) ?> KB</span>
                                    <?php if ($canEdit): ?>
                                        <form method="post" action="/customer/trips/document_action.php" class="inline-form" onsubmit="return confirm('Remove this attachment?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="trip_id" value="<?= (int) $tripId ?>">
                                            <input type="hidden" name="reference_id" value="<?= (int) $r['id'] ?>">
                                            <input type="hidden" name="version" value="<?= (int) $r['version'] ?>">
                                            <input type="hidden" name="document_id" value="<?= (int) $document['id'] ?>">
                                            <button type="submit" class="link-danger">Remove attachment</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <?php if ($canEdit && count($documents[$r['id']] ?? []) < 3): ?>
                                <form method="post" action="/customer/trips/document_action.php" enctype="multipart/form-data" class="reference-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="upload">
                                    <input type="hidden" name="trip_id" value="<?= (int) $tripId ?>">
                                    <input type="hidden" name="reference_id" value="<?= (int) $r['id'] ?>">
                                    <input type="hidden" name="version" value="<?= (int) $r['version'] ?>">
                                    <input type="hidden" name="MAX_FILE_SIZE" value="2097152">
                                    <label for="document-<?= (int) $r['id'] ?>">Attach PDF, JPEG or PNG (2 MB maximum)</label>
                                    <input id="document-<?= (int) $r['id'] ?>" name="document" type="file" accept="application/pdf,image/jpeg,image/png" required>
                                    <button type="submit">Upload attachment</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($canEdit): ?>
                                <div class="reference-actions">
                                    <a href="/customer/trips/references.php?id=<?= (int) $tripId ?>&amp;edit=<?= (int) $r['id'] ?>#edit">Edit</a>
                                    <form method="post" action="/customer/trips/reference_action.php" class="inline-form"
                                          onsubmit="return confirm('Delete this booking reference?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="trip_id" value="<?= (int) $tripId ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="reference_id" value="<?= (int) $r['id'] ?>">
                                        <input type="hidden" name="version" value="<?= (int) $r['version'] ?>">
                                        <button type="submit" class="link-danger">Delete</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <?php if ($canEdit && !$editing): ?>
        <section class="reference-card" aria-labelledby="add-heading">
            <h2 id="add-heading">Add a reference</h2>
            <form method="post" action="/customer/trips/reference_action.php" class="reference-form">
                <?= csrf_field() ?>
                <input type="hidden" name="trip_id" value="<?= (int) $tripId ?>">
                <input type="hidden" name="action" value="create">
                <?php $renderFields(); ?>
                <p class="reference-note">Private references are visible only to the person who added them and the trip owner. Booking references are not sent to the AI assistant.</p>
                <button type="submit">Add reference</button>
            </form>
        </section>
        <?php endif; ?>
    </main>
</body>
</html>
