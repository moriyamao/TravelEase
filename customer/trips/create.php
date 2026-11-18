<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/trip_validation.php';
require_once __DIR__ . '/../../includes/itinerary_validation.php';
require_once __DIR__ . '/../../includes/trip_recommendations.php';

require_role(['customer']);

function plan_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$errors = [];
$notice = null;
$step = 'details';
$draftId = '';
$ideas = [];
$values = [
    'name' => '', 'destination' => '', 'start_date' => '', 'end_date' => '',
    'status' => 'planning', 'budget_currency' => 'PHP', 'budget_amount' => '',
    'notes' => '', 'interests' => '', 'pace' => 'balanced',
];
// Drafts stay in this customer's session, independently for each planning tab.
$drafts = $_SESSION['trip_planning_drafts'] ?? [];
foreach ($drafts as $key => $draft) {
    if (($draft['created_at'] ?? 0) < time() - 7200) unset($drafts[$key]);
}
$_SESSION['trip_planning_drafts'] = $drafts;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $draftId = is_string($_POST['draft_id'] ?? null) ? $_POST['draft_id'] : '';
    $draft = $_SESSION['trip_planning_drafts'][$draftId] ?? null;
    if ($draft) {
        $values = $draft['values'];
        $ideas = $draft['ideas'];
    }
    // Preserve typed details on a failed validation or expired CSRF token.
    if (in_array($action, ['recommend', 'skip'], true)) {
        foreach ($values as $field => $default) {
            $values[$field] = is_string($_POST[$field] ?? null) ? trim($_POST[$field]) : $default;
        }
    }
    if (!csrf_verify(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (in_array($action, ['recommend', 'skip'], true)) {
        foreach ([
            validate_trip_name($values['name']),
            validate_trip_destination($values['destination']),
            validate_trip_dates($values['start_date'], $values['end_date']),
            validate_trip_status($values['status']),
            validate_trip_currency($values['budget_currency']),
            validate_money_amount($values['budget_amount'], 'Budget'),
            validate_trip_notes($values['notes']),
        ] as $error) {
            if ($error) $errors[] = $error;
        }
        if (mb_strlen($values['interests']) > 120) $errors[] = 'Interests must be 120 characters or fewer.';
        if (!in_array($values['pace'], ['relaxed', 'balanced', 'packed'], true)) $errors[] = 'Choose a valid travel pace.';
        if (!$errors) {
            $step = 'review';
            $ideas = [];
            if ($action === 'recommend') {
                if (($_SESSION['last_trip_recommendation_at'] ?? 0) > time() - 30) {
                    $notice = 'Please wait 30 seconds between requests. You can still create your trip below.';
                } else {
                    $_SESSION['last_trip_recommendation_at'] = time();
                    $result = trip_recommendations($values, $values['interests'], $values['pace']);
                    $ideas = $result['ideas'];
                    $notice = $result['error'];
                }
            }
            // Replace only the draft belonging to this form, keeping other tabs intact.
            if ($draft) unset($_SESSION['trip_planning_drafts'][$draftId]);
            $draftId = bin2hex(random_bytes(16));
            while (count($_SESSION['trip_planning_drafts']) >= 5) array_shift($_SESSION['trip_planning_drafts']);
            $_SESSION['trip_planning_drafts'][$draftId] = ['values' => $values, 'ideas' => $ideas, 'created_at' => time()];
        }
    } elseif (in_array($action, ['back', 'create'], true)) {
        if (!$draft) {
            $errors[] = 'This planning draft expired or was already saved. Please start a new plan.';
        } elseif ($action === 'create') {
            $step = 'review';
            $selected = [];
            $submitted = $_POST['ideas'] ?? [];
            if (!is_array($submitted) || count($submitted) > 3) {
                $errors[] = 'Invalid activity selection.';
            } else {
                foreach ($ideas as $index => $idea) {
                    $entry = $submitted[$index] ?? [];
                    if (!is_array($entry)) { $errors[] = 'Invalid activity selection.'; continue; }
                    foreach (['title', 'location', 'date', 'notes'] as $field) {
                        if (isset($entry[$field])) {
                            if (!is_string($entry[$field])) { $errors[] = 'Invalid activity details.'; continue; }
                            $ideas[$index][$field] = trim($entry[$field]);
                        }
                    }
                    $ideas[$index]['selected'] = isset($entry['selected']);
                    if (!$ideas[$index]['selected']) continue;
                    $item = $ideas[$index];
                    foreach ([
                        validate_item_title($item['title']),
                        validate_item_location($item['location']),
                        validate_item_date($item['date'], $values['start_date'], $values['end_date']),
                        validate_item_notes($item['notes']),
                    ] as $error) {
                        if ($error) $errors[] = $error;
                    }
                    $selected[] = $item;
                }
            }
            if (!$errors) {
                $pdo = get_db_connection();
                try {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare(
                        'INSERT INTO trips (user_id, name, destination, start_date, end_date, status, budget_currency, budget_amount, notes)
                         VALUES (:user_id, :name, :destination, :start_date, :end_date, :status, :budget_currency, :budget_amount, :notes)'
                    );
                    $stmt->execute([
                        'user_id' => current_user_id(), 'name' => $values['name'], 'destination' => $values['destination'],
                        'start_date' => $values['start_date'], 'end_date' => $values['end_date'], 'status' => $values['status'],
                        'budget_currency' => $values['budget_currency'],
                        'budget_amount' => $values['budget_amount'] !== '' ? $values['budget_amount'] : null,
                        'notes' => $values['notes'] !== '' ? $values['notes'] : null,
                    ]);
                    $tripId = (int) $pdo->lastInsertId();
                    $stmt = $pdo->prepare(
                        'INSERT INTO trip_memberships (trip_id, user_id, role)
                         VALUES (:trip_id, :user_id, :role)'
                    );
                    $stmt->execute([
                        'trip_id' => $tripId,
                        'user_id' => current_user_id(),
                        'role' => 'owner',
                    ]);
                    if ($selected) {
                        $stmt = $pdo->prepare(
                            'INSERT INTO itinerary_items (trip_id, item_date, title, location, notes)
                             VALUES (:trip_id, :item_date, :title, :location, :notes)'
                        );
                        foreach ($selected as $item) {
                            $stmt->execute([
                                'trip_id' => $tripId, 'item_date' => $item['date'], 'title' => $item['title'],
                                'location' => $item['location'] !== '' ? $item['location'] : null,
                                'notes' => $item['notes'] !== '' ? $item['notes'] : null,
                            ]);
                        }
                    }
                    $pdo->commit();
                    unset($_SESSION['trip_planning_drafts'][$draftId]);
                    header('Location: /customer/trips/view.php?id=' . $tripId);
                    exit;
                } catch (PDOException $exception) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('TravelEase: planning draft save failed: ' . $exception->getMessage());
                    $errors[] = 'Your plan could not be saved. Please try again.';
                }
            }
        }
    } else {
        $errors[] = 'Choose a planning action to continue.';
    }
}
$dayCount = $values['start_date'] && $values['end_date'] && !validate_trip_dates($values['start_date'], $values['end_date'])
    ? (int) (new DateTime($values['start_date']))->diff(new DateTime($values['end_date']))->days + 1 : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Plan a trip — TravelEase</title>
    <link rel="icon" href="/assets/images/travelease-logo-mark.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">
    <link rel="stylesheet" href="/assets/css/trips.css">
    <link rel="stylesheet" href="/assets/css/planning.css?v=20261105-1">
</head>
<body>
<main class="dashboard planning-page">
    <header class="dashboard-header">
        <a class="site-brand" href="/customer/dashboard.php"><img src="/assets/images/travelease-logo-mark.svg" alt=""><span><strong>TravelEase</strong><small>A little planning. A great journey.</small></span></a>
        <a href="/customer/dashboard.php" class="logout-link">← Back to my trips</a>
    </header>
    <div class="planning-layout">
        <aside class="planning-intro">
            <p class="eyebrow">Make room for the memorable</p>
            <h1><?= $step === 'details' ? 'Your next trip starts here.' : 'A few ideas. Your kind of trip.' ?></h1>
            <p>Bring the places you want to see and the things you love into one thoughtful plan.</p>
            <ol class="planning-steps" aria-label="Planning progress">
                <li <?= $step === 'details' ? 'aria-current="step"' : '' ?>><span>01</span><div><strong>Set the scene</strong><small>Where, when, and your travel style</small></div></li>
                <li <?= $step === 'review' ? 'aria-current="step"' : '' ?>><span>02</span><div><strong>Make it yours</strong><small>Review ideas and create your itinerary</small></div></li>
            </ol>
            <div class="planning-postcard" aria-hidden="true">
                <svg viewBox="0 0 320 145" fill="none"><path d="M18 115C70 115 37 36 104 39S177 157 207 76 264 20 299 36" stroke="currentColor" stroke-width="2" stroke-dasharray="5 7"/><circle cx="18" cy="115" r="6" fill="currentColor"/><circle cx="207" cy="76" r="6" fill="currentColor"/><path d="m288 27 21 9-23 6 6-7z" fill="currentColor"/></svg>
                <span>Leave a little room for discovery.</span>
            </div>
        </aside>
        <section class="planning-panel" aria-labelledby="planning-heading">
            <?php if ($errors): ?><ul class="form-errors" role="alert"><?php foreach (array_unique($errors) as $error): ?><li><?= plan_escape($error) ?></li><?php endforeach; ?></ul><?php endif; ?>
            <?php if ($step === 'details'): ?>
                <p class="eyebrow">Step 01 / Trip details</p>
                <h2 id="planning-heading">Where are we headed?</h2>
                <p class="planning-subtitle">Start with the essentials. We’ll help with the inspiration.</p>
                <form method="post" action="/customer/trips/create.php" class="planning-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="draft_id" value="<?= plan_escape($draftId) ?>">
                    <label for="destination">Destination</label>
                    <input id="destination" name="destination" placeholder="A city, region, or country" maxlength="150" required value="<?= plan_escape($values['destination']) ?>">
                    <label for="name">Give your trip a name</label>
                    <input id="name" name="name" placeholder="A long weekend in Tokyo" maxlength="150" required value="<?= plan_escape($values['name']) ?>">
                    <div class="form-row">
                        <div><label for="start_date">Departure</label><input type="date" id="start_date" name="start_date" required value="<?= plan_escape($values['start_date']) ?>"></div>
                        <div><label for="end_date">Return</label><input type="date" id="end_date" name="end_date" required value="<?= plan_escape($values['end_date']) ?>"></div>
                    </div>
                    <div class="form-row">
                        <div><label for="budget_currency">Currency</label><select id="budget_currency" name="budget_currency"><?php foreach (TRIP_ALLOWED_CURRENCIES as $code): ?><option <?= $values['budget_currency'] === $code ? 'selected' : '' ?>><?= $code ?></option><?php endforeach; ?></select></div>
                        <div><label for="budget_amount">Trip budget <span>(optional)</span></label><input inputmode="decimal" id="budget_amount" name="budget_amount" placeholder="Your total budget" value="<?= plan_escape($values['budget_amount']) ?>"></div>
                    </div>
                    <div class="planning-divider"></div>
                    <h3>A trip that feels like you</h3>
                    <label for="interests">What would you love to do?</label>
                    <input id="interests" name="interests" maxlength="120" placeholder="Street food, museums, nature, hidden gems..." value="<?= plan_escape($values['interests']) ?>">
                    <fieldset class="pace-options"><legend>Your travel pace</legend>
                        <?php foreach (['relaxed' => ['Take it slow', 'Room to wander'], 'balanced' => ['A bit of both', 'Explore and unwind'], 'packed' => ['See it all', 'Make the most of each day']] as $pace => $copy): ?>
                        <label class="pace-option"><input type="radio" name="pace" value="<?= $pace ?>" <?= $values['pace'] === $pace ? 'checked' : '' ?>><span><strong><?= $copy[0] ?></strong><small><?= $copy[1] ?></small></span></label>
                        <?php endforeach; ?>
                    </fieldset>
                    <details class="planning-extra"><summary>Notes and trip status</summary>
                        <label for="notes">Notes <span>(optional)</span></label><textarea id="notes" name="notes" rows="3" maxlength="5000"><?= plan_escape($values['notes']) ?></textarea>
                        <label for="status">Status</label><select id="status" name="status"><?php foreach (['planning', 'confirmed', 'completed', 'cancelled'] as $status): ?><option value="<?= $status ?>" <?= $values['status'] === $status ? 'selected' : '' ?>><?= ucfirst($status) ?></option><?php endforeach; ?></select>
                    </details>
                    <button class="planning-primary" name="action" value="recommend">Find ideas for my trip <span aria-hidden="true">↗</span></button>
                    <button class="planning-secondary" name="action" value="skip">I’ll build my own itinerary →</button>
                    <p class="planning-disclosure">To suggest activities, we send your destination, dates, budget, interests, and pace to our AI provider. You review every idea before saving.</p>
                </form>
            <?php else: ?>
                <p class="eyebrow">Step 02 / Your starting itinerary</p>
                <h2 id="planning-heading">Choose what speaks to you.</h2>
                <p class="planning-subtitle">Keep the ideas you like. Change their details or start with an empty itinerary.</p>
                <div class="planning-trip-summary"><strong><?= plan_escape($values['name']) ?></strong><span><?= plan_escape($values['destination']) ?> · <?= $dayCount ?> <?= $dayCount === 1 ? 'day' : 'days' ?></span><span><?= plan_escape($values['start_date']) ?> → <?= plan_escape($values['end_date']) ?></span></div>
                <?php if ($notice): ?><p class="planning-notice" role="status"><?= plan_escape($notice) ?> You can continue with an empty itinerary or go back to try again.</p><?php endif; ?>
                <form method="post" action="/customer/trips/create.php" class="planning-form">
                    <?= csrf_field() ?><input type="hidden" name="draft_id" value="<?= plan_escape($draftId) ?>">
                    <?php if (!$ideas): ?><div class="planning-empty"><h3>A little space for your own discoveries.</h3><p>Create your trip now and add activities from the planner whenever you’re ready.</p></div><?php endif; ?>
                    <?php foreach ($ideas as $index => $idea): ?>
                        <article class="planning-idea">
                            <div class="planning-idea-top"><span class="eyebrow">AI activity idea <?= $index + 1 ?></span><label><input type="checkbox" name="ideas[<?= $index ?>][selected]" value="1" <?= !empty($idea['selected']) ? 'checked' : '' ?>> Include in my itinerary</label></div>
                            <label for="idea-title-<?= $index ?>">Activity</label><input id="idea-title-<?= $index ?>" name="ideas[<?= $index ?>][title]" maxlength="150" value="<?= plan_escape($idea['title']) ?>">
                            <div class="form-row">
                                <div><label for="idea-date-<?= $index ?>">Day</label><input type="date" id="idea-date-<?= $index ?>" name="ideas[<?= $index ?>][date]" min="<?= plan_escape($values['start_date']) ?>" max="<?= plan_escape($values['end_date']) ?>" value="<?= plan_escape($idea['date']) ?>"></div>
                                <div><label for="idea-location-<?= $index ?>">Place</label><input id="idea-location-<?= $index ?>" name="ideas[<?= $index ?>][location]" maxlength="150" value="<?= plan_escape($idea['location']) ?>"></div>
                            </div>
                            <label for="idea-notes-<?= $index ?>">Notes</label><textarea id="idea-notes-<?= $index ?>" name="ideas[<?= $index ?>][notes]" rows="2" maxlength="2000"><?= plan_escape($idea['notes']) ?></textarea>
                        </article>
                    <?php endforeach; ?>
                    <?php if ($ideas): ?><p class="planning-disclosure">AI ideas may contain inaccurate place information. Verify availability, travel times, and opening hours before visiting. Nothing is booked through this plan.</p><?php endif; ?>
                    <button class="planning-primary" name="action" value="create">Create my trip <span aria-hidden="true">→</span></button>
                    <button class="planning-secondary" name="action" value="back" formnovalidate>← Edit trip details</button>
                </form>
            <?php endif; ?>
        </section>
    </div>
</main>
</body>
</html>
