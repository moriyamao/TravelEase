<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/trip_membership.php';
require_once __DIR__ . '/../../includes/trip_activity.php';
require_once __DIR__ . '/../../includes/trip_expenses.php';

require_role(['customer']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}
$token = $_POST['csrf_token'] ?? null;
if (!is_string($token) || !csrf_verify($token)) {
    http_response_code(403);
    exit('Your session expired. Reload the page and try again.');
}
try {
    $tripId = expense_id($_POST['trip_id'] ?? null);
    $action = $_POST['action'] ?? null;
    if (!in_array($action, ['create', 'settle', 'reopen', 'delete'], true)) {
        throw new InvalidArgumentException('Choose a valid expense action.');
    }
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    exit($e->getMessage());
}

$pdo = get_db_connection();
require_trip_permission($pdo, $tripId, 'edit_itinerary');
unset($_SESSION['expense_notice'][$tripId], $_SESSION['expense_error'][$tripId]);
try {
    $pdo->beginTransaction();
    lock_trip_and_require_permission($pdo, $tripId, 'edit_itinerary');
    $event = null;
    if ($action === 'create') {
        $title = $_POST['title'] ?? null;
        $category = $_POST['category'] ?? null;
        $mode = $_POST['split_mode'] ?? null;
        $receipt = $_POST['receipt_url'] ?? '';
        $travelers = $_POST['travelers'] ?? null;
        $custom = $_POST['shares'] ?? [];
        if (!is_string($title) || validate_item_title($title) !== null
            || !is_string($category) || !isset(ITINERARY_CATEGORIES[$category])
            || !is_string($mode) || !is_string($receipt) || !is_array($travelers) || !is_array($custom)) {
            throw new InvalidArgumentException('Enter a title, category and valid split details.');
        }
        $title = trim($title);
        $receipt = trim($receipt);
        if ($receipt !== '' && (strlen($receipt) > 2048 || !filter_var($receipt, FILTER_VALIDATE_URL)
            || strtolower((string) parse_url($receipt, PHP_URL_SCHEME)) !== 'https'
            || parse_url($receipt, PHP_URL_USER) !== null || parse_url($receipt, PHP_URL_PASS) !== null)) {
            throw new InvalidArgumentException('Enter a valid HTTPS receipt link.');
        }
        $payer = expense_id($_POST['payer_id'] ?? null);
        $ids = array_map('expense_id', $travelers);
        $names = expense_travelers($pdo, $tripId);
        if (!isset($names[$payer]) || array_diff($ids, array_keys($names))) {
            throw new InvalidArgumentException('Choose a payer and travelers who are current trip members.');
        }
        $total = expense_amount_minor($_POST['amount'] ?? null);
        $shares = expense_split($total, $mode, $ids, $custom);
        $stmt = $pdo->prepare('SELECT budget_currency FROM trips WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $tripId]);
        $currency = $stmt->fetchColumn();
        if (($_POST['currency'] ?? null) !== $currency) {
            throw new DomainException('The trip currency changed. Reload before recording this expense.');
        }
        $stmt = $pdo->prepare(
            'INSERT INTO trip_expenses
                (trip_id, title, category, currency, amount_minor, payer_id, payer_name, split_mode, receipt_url)
             VALUES (:trip, :title, :category, :currency, :amount, :payer, :name, :mode, :receipt)'
        );
        $stmt->execute(['trip' => $tripId, 'title' => $title, 'category' => $category, 'currency' => $currency,
            'amount' => $total, 'payer' => $payer, 'name' => $names[$payer], 'mode' => $mode, 'receipt' => $receipt ?: null]);
        $expenseId = $pdo->lastInsertId();
        $stmt = $pdo->prepare(
            'INSERT INTO trip_expense_shares (expense_id, traveler_id, traveler_name, amount_minor)
             VALUES (:expense, :traveler, :name, :amount)'
        );
        foreach ($shares as $traveler => $amount) {
            $stmt->execute(['expense' => $expenseId, 'traveler' => $traveler, 'name' => $names[$traveler], 'amount' => $amount]);
        }
        $event = 'expense_added';
    } else {
        $expenseId = expense_id($_POST['expense_id'] ?? null);
        $version = expense_id($_POST['version'] ?? null);
        $stmt = $pdo->prepare('SELECT title, payer_id, version FROM trip_expenses WHERE id = :id AND trip_id = :trip FOR UPDATE');
        $stmt->execute(['id' => $expenseId, 'trip' => $tripId]);
        $expense = $stmt->fetch();
        if (!$expense) {
            throw new OutOfBoundsException('Expense not found.');
        }
        if ($version !== (int) $expense['version']) {
            throw new DomainException('This expense changed. Reload and try again.');
        }
        $title = $expense['title'];
        if ($action === 'delete') {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM trip_expense_shares WHERE expense_id = :id AND traveler_id <> :payer AND settled_at IS NOT NULL LIMIT 1'
            );
            $stmt->execute(['id' => $expenseId, 'payer' => $expense['payer_id']]);
            if ($stmt->fetchColumn() !== false) {
                throw new DomainException('Reopen settled shares before removing this expense.');
            }
            $stmt = $pdo->prepare('DELETE FROM trip_expenses WHERE id = :id AND trip_id = :trip');
            $stmt->execute(['id' => $expenseId, 'trip' => $tripId]);
            $event = 'expense_removed';
        } else {
            $traveler = expense_id($_POST['traveler_id'] ?? null);
            if ($traveler === (int) $expense['payer_id']) {
                throw new InvalidArgumentException('The payer does not owe a payment to themselves.');
            }
            $stmt = $pdo->prepare('SELECT settled_at FROM trip_expense_shares WHERE expense_id = :id AND traveler_id = :traveler FOR UPDATE');
            $stmt->execute(['id' => $expenseId, 'traveler' => $traveler]);
            $share = $stmt->fetch();
            if (!$share) {
                throw new OutOfBoundsException('Expense share not found.');
            }
            if (($share['settled_at'] === null) === ($action === 'settle')) {
                $stmt = $pdo->prepare('UPDATE trip_expense_shares SET settled_at = :settled WHERE expense_id = :id AND traveler_id = :traveler');
                $stmt->execute(['settled' => $action === 'settle' ? gmdate('Y-m-d H:i:s') : null, 'id' => $expenseId, 'traveler' => $traveler]);
                $stmt = $pdo->prepare('UPDATE trip_expenses SET version = version + 1 WHERE id = :id AND trip_id = :trip');
                $stmt->execute(['id' => $expenseId, 'trip' => $tripId]);
                $event = $action === 'settle' ? 'expense_share_settled' : 'expense_share_reopened';
            }
        }
    }
    if ($event !== null) {
        log_trip_activity($pdo, $tripId, $event, null, $title);
    }
    $pdo->commit();
    $_SESSION['expense_notice'][$tripId] = 'Expense ledger saved.';
} catch (OutOfBoundsException | DomainException $e) {
    $pdo->rollBack();
    http_response_code($e instanceof OutOfBoundsException ? 404 : 409);
    exit($e->getMessage());
} catch (InvalidArgumentException $e) {
    $pdo->rollBack();
    $_SESSION['expense_error'][$tripId] = $e->getMessage();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('TravelEase: expense write failed (database code ' . (int) ($e->errorInfo[1] ?? 0) . ').');
    $_SESSION['expense_error'][$tripId] = 'Could not save the expense. Please try again.';
}
header('Location: /customer/trips/expenses.php?id=' . $tripId);
exit;
