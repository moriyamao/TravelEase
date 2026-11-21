<?php
/**
 * FR-22 / FR-23: a trip's shared expense ledger and who owes whom.
 *
 * Every trip member can view this page. Owners and editors add expenses,
 * mark shares settled or reopen them, and delete expenses
 * (expense_action.php). Itinerary estimated/actual costs are shown in a
 * separate section and are never added to ledger totals, so the same
 * purchase is not counted twice.
 */
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/trip_membership.php';
require_once __DIR__ . '/../../includes/itinerary_validation.php';
require_once __DIR__ . '/../../includes/trip_expenses.php';

require_role(['customer']);

$tripId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$tripId) {
    http_response_code(400);
    die('Invalid trip.');
}

$pdo = get_db_connection();
$tripRole = require_trip_permission($pdo, $tripId, 'view');
$canEdit = trip_role_can($tripRole, 'edit_itinerary');

$stmt = $pdo->prepare('SELECT name, destination, budget_currency, budget_amount FROM trips WHERE id = :id');
$stmt->execute(['id' => $tripId]);
$trip = $stmt->fetch();

// Ledger rows: one row per share (LEFT JOIN keeps an expense even if it
// somehow has no shares). Names are the snapshots stored with each row.
$stmt = $pdo->prepare(
    'SELECT e.id, e.title, e.category, e.currency, e.amount_minor, e.payer_id, e.payer_name,
            e.split_mode, e.receipt_url, e.version, e.created_at,
            s.traveler_id, s.traveler_name, s.amount_minor AS share_minor, s.settled_at
     FROM trip_expenses e
     LEFT JOIN trip_expense_shares s ON s.expense_id = e.id
     WHERE e.trip_id = :trip_id
     ORDER BY e.created_at DESC, e.id DESC, s.traveler_name ASC, s.traveler_id ASC'
);
$stmt->execute(['trip_id' => $tripId]);
$rows = $stmt->fetchAll();

$expenses = [];
$balanceRows = [];
$ledgerTotals = [];    // currency => minor
$ledgerByCategory = []; // currency => category => minor
foreach ($rows as $r) {
    $id = (int) $r['id'];
    if (!isset($expenses[$id])) {
        $expenses[$id] = $r + ['shares' => [], 'locked' => false];
        $ledgerTotals[$r['currency']] = ($ledgerTotals[$r['currency']] ?? 0) + (int) $r['amount_minor'];
        $cat = $r['category'] ?? '';
        $ledgerByCategory[$r['currency']][$cat] = ($ledgerByCategory[$r['currency']][$cat] ?? 0) + (int) $r['amount_minor'];
    }
    if ($r['traveler_id'] === null && $r['traveler_name'] === null) {
        continue;
    }
    $expenses[$id]['shares'][] = $r;
    $isSelf = $r['payer_id'] !== null && (string) $r['traveler_id'] === (string) $r['payer_id'];
    if (!$isSelf && $r['settled_at'] !== null) {
        $expenses[$id]['locked'] = true; // delete is refused once someone else has settled
    }
    $balanceRows[] = [
        'currency' => $r['currency'], 'payer_id' => $r['payer_id'], 'payer_name' => $r['payer_name'],
        'traveler_id' => $r['traveler_id'], 'traveler_name' => $r['traveler_name'],
        'amount_minor' => $r['share_minor'], 'settled_at' => $r['settled_at'],
    ];
}
$balances = expense_balances($balanceRows);
$travelers = $canEdit ? expense_travelers($pdo, $tripId) : [];

// Itinerary planned vs actual, by category, in the trip currency.
$stmt = $pdo->prepare(
    'SELECT category, SUM(estimated_cost) AS planned, SUM(actual_cost) AS actual
     FROM itinerary_items WHERE trip_id = :trip_id
     GROUP BY category ORDER BY category ASC'
);
$stmt->execute(['trip_id' => $tripId]);
$itineraryCosts = array_filter($stmt->fetchAll(), fn($c) => $c['planned'] !== null || $c['actual'] !== null);

$notice = $_SESSION['expense_notice'][$tripId] ?? null;
$error = $_SESSION['expense_error'][$tripId] ?? null;
unset($_SESSION['expense_notice'][$tripId], $_SESSION['expense_error'][$tripId]);

$e = fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$money = fn(string $currency, int $minor) => $currency . ' ' . number_format($minor / 100, 2);
$categoryLabel = fn($c) => ITINERARY_CATEGORIES[$c ?? ''] ?? 'Uncategorized';
$safeReceipt = fn($url) => is_string($url) && preg_match('#^https://[^\s]+$#i', $url) ? $url : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expenses — <?= $e($trip['name']) ?> — TravelEase</title>
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">
    <link rel="stylesheet" href="/assets/css/trips.css">
    <link rel="stylesheet" href="/assets/css/expenses.css">
</head>
<body>
    <main class="dashboard">
        <header class="dashboard-header">
            <h1>Expenses</h1>
            <a href="/customer/trips/view.php?id=<?= (int) $tripId ?>" class="logout-link">Back to trip</a>
        </header>

        <p class="eyebrow"><?= $e($trip['name']) ?> &middot; <?= $e($trip['destination']) ?></p>
        <?php if ($notice): ?><p class="form-notice" role="status"><?= $e($notice) ?></p><?php endif; ?>
        <?php if ($error): ?><p class="form-errors" role="alert"><?= $e($error) ?></p><?php endif; ?>

        <div class="expense-layout">
            <section class="expense-card" aria-labelledby="balances-title">
                <h2 id="balances-title">Balances</h2>
                <?php if (!$balances): ?>
                    <p class="empty-state">Nobody owes anything yet.</p>
                <?php else: ?>
                    <?php foreach ($balances as $currency => $people): ?>
                        <h3 class="expense-currency"><?= $e($currency) ?></h3>
                        <ul class="balance-list">
                            <?php foreach ($people as $person): ?>
                                <?php $net = (int) $person['net_minor']; ?>
                                <li>
                                    <span><?= $e($person['name']) ?></span>
                                    <?php if ($net > 0): ?>
                                        <span class="balance-owed">is owed <?= $e($money($currency, $net)) ?></span>
                                    <?php elseif ($net < 0): ?>
                                        <span class="balance-owes">owes <?= $e($money($currency, -$net)) ?></span>
                                    <?php else: ?>
                                        <span class="balance-even">is settled up</span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endforeach; ?>
                    <p class="expense-note">Balances count unsettled shares only. Each currency is balanced on its own; nothing is converted.</p>
                <?php endif; ?>
            </section>

            <section class="expense-card" aria-labelledby="totals-title">
                <h2 id="totals-title">Totals</h2>
                <h3 class="expense-subhead">Shared ledger</h3>
                <?php if (!$ledgerTotals): ?>
                    <p class="empty-state">No expenses recorded yet.</p>
                <?php else: ?>
                    <?php foreach ($ledgerByCategory as $currency => $cats): ?>
                        <dl class="expense-totals">
                            <?php foreach ($cats as $cat => $minor): ?>
                                <dt><?= $e($categoryLabel($cat)) ?></dt><dd><?= $e($money($currency, $minor)) ?></dd>
                            <?php endforeach; ?>
                            <dt class="expense-total-label">Total</dt><dd class="expense-total-value"><?= $e($money($currency, $ledgerTotals[$currency])) ?></dd>
                        </dl>
                    <?php endforeach; ?>
                <?php endif; ?>

                <h3 class="expense-subhead">Itinerary costs (<?= $e($trip['budget_currency']) ?>)</h3>
                <?php
                $plannedTotal = array_sum(array_map(fn($c) => (float) $c['planned'], $itineraryCosts));
                $actualTotal = array_sum(array_map(fn($c) => (float) $c['actual'], $itineraryCosts));
                ?>
                <dl class="expense-totals">
                    <dt>Trip budget</dt>
                    <dd><?= $trip['budget_amount'] !== null ? $e(number_format((float) $trip['budget_amount'], 2)) : 'Not set' ?></dd>
                </dl>
                <?php if (!$itineraryCosts): ?>
                    <p class="empty-state">No itinerary activity has a cost yet.</p>
                <?php else: ?>
                    <table class="expense-table">
                        <thead><tr><th scope="col">Category</th><th scope="col">Planned</th><th scope="col">Actual</th></tr></thead>
                        <tbody>
                            <?php foreach ($itineraryCosts as $c): ?>
                                <tr>
                                    <th scope="row"><?= $e($categoryLabel($c['category'])) ?></th>
                                    <td><?= $c['planned'] !== null ? $e(number_format((float) $c['planned'], 2)) : '&mdash;' ?></td>
                                    <td><?= $c['actual'] !== null ? $e(number_format((float) $c['actual'], 2)) : '&mdash;' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr><th scope="row">Total</th><td><?= $e(number_format($plannedTotal, 2)) ?></td><td><?= $e(number_format($actualTotal, 2)) ?></td></tr>
                        </tfoot>
                    </table>
                <?php endif; ?>
                <p class="expense-note">Itinerary costs and the shared ledger are tracked separately and never added together, so a purchase entered in both is not counted twice.</p>
            </section>
        </div>

        <?php if ($canEdit): ?>
        <section class="expense-card expense-add" aria-labelledby="add-title">
            <h2 id="add-title">Add an expense</h2>
            <?php if (!$travelers): ?>
                <p class="empty-state">No travelers are available to split with.</p>
            <?php else: ?>
            <form method="post" action="/customer/trips/expense_action.php" class="expense-form">
                <?= csrf_field() ?>
                <input type="hidden" name="trip_id" value="<?= (int) $tripId ?>">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="currency" value="<?= $e($trip['budget_currency']) ?>">

                <div class="expense-form-grid">
                    <div>
                        <label for="expense-title">What was it for?</label>
                        <input id="expense-title" name="title" required maxlength="150">
                    </div>
                    <div>
                        <label for="expense-category">Category</label>
                        <select id="expense-category" name="category" required>
                            <?php foreach (ITINERARY_CATEGORIES as $value => $label): ?>
                                <option value="<?= $e($value) ?>"><?= $e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="expense-amount">Amount in <?= $e($trip['budget_currency']) ?></label>
                        <input id="expense-amount" name="amount" required inputmode="decimal" pattern="\d{1,8}(\.\d{1,2})?" placeholder="0.00">
                    </div>
                    <div>
                        <label for="expense-payer">Paid by</label>
                        <select id="expense-payer" name="payer_id" required>
                            <?php foreach ($travelers as $tid => $tname): ?>
                                <option value="<?= (int) $tid ?>"<?= (int) $tid === (int) ($_SESSION['user_id'] ?? 0) ? ' selected' : '' ?>><?= $e($tname) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <fieldset class="expense-split">
                    <legend>Split</legend>
                    <label class="inline-choice"><input type="radio" name="split_mode" value="equal" checked> Equally among the selected travelers</label>
                    <label class="inline-choice"><input type="radio" name="split_mode" value="custom"> Custom amounts (must add up to the total)</label>
                    <ul class="split-list">
                        <?php foreach ($travelers as $tid => $tname): ?>
                            <li>
                                <label class="inline-choice"><input type="checkbox" name="travelers[]" value="<?= (int) $tid ?>" checked> <?= $e($tname) ?></label>
                                <label class="visually-hidden" for="share-<?= (int) $tid ?>">Custom share for <?= $e($tname) ?></label>
                                <input id="share-<?= (int) $tid ?>" class="split-share" name="shares[<?= (int) $tid ?>]" inputmode="decimal" pattern="\d{1,8}(\.\d{1,2})?" placeholder="Custom only">
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="expense-note">Custom amounts are used only with a custom split. In an equal split, any leftover cent goes to one traveler at a time, so the shares always add up to the total.</p>
                </fieldset>

                <label for="expense-receipt">Receipt link (optional, https only)</label>
                <input id="expense-receipt" name="receipt_url" type="url" maxlength="2048" pattern="https://.+" placeholder="https://">
                <p class="expense-note">Paste a link to a receipt stored elsewhere. Files are not uploaded.</p>

                <button type="submit">Add expense</button>
            </form>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <section class="expense-card" aria-labelledby="ledger-title">
            <h2 id="ledger-title">Ledger</h2>
            <?php if (!$expenses): ?>
                <p class="empty-state">No expenses yet.<?= $canEdit ? ' Add the first one above.' : '' ?></p>
            <?php else: ?>
                <ul class="expense-list">
                    <?php foreach ($expenses as $x): ?>
                        <li class="expense-item" id="expense-<?= (int) $x['id'] ?>">
                            <div class="expense-head">
                                <strong><?= $e($x['title']) ?></strong>
                                <span class="expense-amount"><?= $e($money($x['currency'], (int) $x['amount_minor'])) ?></span>
                            </div>
                            <p class="expense-meta">
                                <?= $e($categoryLabel($x['category'])) ?> &middot; Paid by <?= $e($x['payer_name']) ?>
                                &middot; <?= $x['split_mode'] === 'custom' ? 'Custom split' : 'Split equally' ?>
                                &middot; <time datetime="<?= $e($x['created_at']) ?>"><?= $e(substr((string) $x['created_at'], 0, 10)) ?></time>
                                <?php if ($receipt = $safeReceipt($x['receipt_url'])): ?>
                                    &middot; <a href="<?= $e($receipt) ?>" target="_blank" rel="noopener noreferrer">Receipt</a>
                                <?php endif; ?>
                            </p>
                            <ul class="share-list">
                                <?php foreach ($x['shares'] as $s): ?>
                                    <?php $isSelf = $x['payer_id'] !== null && (string) $s['traveler_id'] === (string) $x['payer_id']; ?>
                                    <li>
                                        <span><?= $e($s['traveler_name']) ?></span>
                                        <span><?= $e($money($x['currency'], (int) $s['share_minor'])) ?></span>
                                        <?php if ($isSelf): ?>
                                            <span class="share-status">Paid</span>
                                        <?php elseif ($s['settled_at'] !== null): ?>
                                            <span class="share-status share-settled">Settled</span>
                                        <?php else: ?>
                                            <span class="share-status share-open">Owes <?= $e($x['payer_name']) ?></span>
                                        <?php endif; ?>
                                        <?php if ($canEdit && !$isSelf && $s['traveler_id'] !== null): ?>
                                            <form method="post" action="/customer/trips/expense_action.php" class="inline-form">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="trip_id" value="<?= (int) $tripId ?>">
                                                <input type="hidden" name="action" value="<?= $s['settled_at'] === null ? 'settle' : 'reopen' ?>">
                                                <input type="hidden" name="expense_id" value="<?= (int) $x['id'] ?>">
                                                <input type="hidden" name="version" value="<?= (int) $x['version'] ?>">
                                                <input type="hidden" name="traveler_id" value="<?= (int) $s['traveler_id'] ?>">
                                                <button type="submit" class="link-button"><?= $s['settled_at'] === null ? 'Mark settled' : 'Reopen' ?></button>
                                            </form>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php if ($canEdit): ?>
                                <?php if ($x['locked']): ?>
                                    <p class="expense-note">This expense can't be deleted because someone has settled their share. Reopen those shares first.</p>
                                <?php else: ?>
                                    <form method="post" action="/customer/trips/expense_action.php" class="inline-form"
                                          onsubmit="return confirm('Delete this expense and all its shares?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="trip_id" value="<?= (int) $tripId ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="expense_id" value="<?= (int) $x['id'] ?>">
                                        <input type="hidden" name="version" value="<?= (int) $x['version'] ?>">
                                        <button type="submit" class="link-button link-danger">Delete expense</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
