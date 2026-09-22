<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../config/database.php';

require_role(['staff', 'admin']);

$name = htmlspecialchars($_SESSION['user_name'] ?? 'Staff', ENT_QUOTES, 'UTF-8');

$pdo = get_db_connection();

$stmt = $pdo->query('
    SELECT
        e.id, e.status, e.created_at,
        u.name AS customer_name, u.email AS customer_email
    FROM escalations e
    JOIN users u ON u.id = e.user_id
    ORDER BY
        (e.status = "open") DESC,
        e.created_at DESC
');
$escalations = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Queue — TravelEase</title>
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">
    <link rel="stylesheet" href="/assets/css/chat-widget.css">
    <style>
        .escalation-row { border: 1px solid var(--line); border-radius: 8px; margin-bottom: 1rem; background: var(--paper-white); overflow: hidden; }
        .escalation-summary { display: flex; justify-content: space-between; align-items: center; padding: 0.9rem 1.1rem; cursor: pointer; }
        .escalation-summary:hover { background: rgba(0,0,0,0.02); }
        .escalation-thread { display: none; border-top: 1px solid var(--line); padding: 1rem 1.1rem; }
        .escalation-thread.open { display: block; }
        .escalation-messages { max-height: 260px; overflow-y: auto; display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 0.75rem; }
        .escalation-reply-form { display: flex; gap: 0.5rem; }
        .escalation-reply-form input { flex: 1; }
    </style>
</head>
<body>
    <main class="dashboard">
        <header class="dashboard-header">
            <h1>Support Queue — <?= $name ?></h1>
            <div class="header-actions">
                <a href="/staff/dashboard.php" class="logout-link">Trips</a>
                <a href="/profile.php" class="logout-link">Profile</a>
                <a href="/auth/logout.php" class="logout-link">Sign out</a>
            </div>
        </header>

        <?php if (empty($escalations)): ?>
            <p class="empty-state">No support requests yet.</p>
        <?php endif; ?>

        <?php foreach ($escalations as $item): ?>
            <div class="escalation-row" data-escalation-id="<?= (int) $item['id'] ?>">
                <div class="escalation-summary" onclick="toggleThread(<?= (int) $item['id'] ?>)">
                    <div>
                        <strong><?= htmlspecialchars($item['customer_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <small style="color: var(--ink-muted);"> — <?= htmlspecialchars($item['customer_email'], ENT_QUOTES, 'UTF-8') ?></small>
                        <br>
                        <small style="color: var(--ink-muted);"><?= htmlspecialchars(date('M j, Y g:ia', strtotime($item['created_at'])), ENT_QUOTES, 'UTF-8') ?></small>
                    </div>
                    <div>
                        <span class="status-badge status-<?= $item['status'] === 'open' ? 'planning' : 'completed' ?>">
                            <?= $item['status'] === 'open' ? 'Open' : 'Resolved' ?>
                        </span>
                        <?php if ($item['status'] === 'open'): ?>
                            <form method="POST" action="/staff/resolve_escalation.php" class="admin-inline-form" style="display: inline;" onclick="event.stopPropagation();">
                                <?= csrf_field() ?>
                                <input type="hidden" name="escalation_id" value="<?= (int) $item['id'] ?>">
                                <button type="submit" class="link-danger">Mark resolved</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="escalation-thread" id="thread-<?= (int) $item['id'] ?>">
                    <div class="escalation-messages" id="messages-<?= (int) $item['id'] ?>">
                        <p style="color: var(--ink-muted);">Loading...</p>
                    </div>
                    <?php if ($item['status'] === 'open'): ?>
                        <form class="escalation-reply-form" onsubmit="return sendReply(event, <?= (int) $item['id'] ?>)">
                            <input type="text" placeholder="Type a reply..." maxlength="2000" required>
                            <button type="submit">Send</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </main>

    <script>
        const csrfToken = <?= json_encode(csrf_token()) ?>;
        const openThreads = new Set();

        async function loadMessages(escalationId) {
            const res = await fetch('/staff/escalation_messages.php?escalation_id=' + escalationId);
            const data = await res.json();
            const container = document.getElementById('messages-' + escalationId);
            container.innerHTML = '';
            (data.messages || []).forEach((msg) => {
                const el = document.createElement('div');
                el.className = 'te-chat-message te-chat-message--' + (msg.sender_type === 'staff' ? 'user' : 'bot');
                el.textContent = msg.message;
                container.appendChild(el);
            });
            container.scrollTop = container.scrollHeight;
        }

        function toggleThread(escalationId) {
            const thread = document.getElementById('thread-' + escalationId);
            const isOpen = thread.classList.toggle('open');
            if (isOpen) {
                openThreads.add(escalationId);
                loadMessages(escalationId);
            } else {
                openThreads.delete(escalationId);
            }
        }

        async function sendReply(event, escalationId) {
            event.preventDefault();
            const input = event.target.querySelector('input');
            const message = input.value.trim();
            if (!message) return false;

            input.disabled = true;
            try {
                await fetch('/staff/send_escalation_message.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ escalation_id: escalationId, message: message, csrf_token: csrfToken }),
                });
                input.value = '';
                await loadMessages(escalationId);
            } finally {
                input.disabled = false;
                input.focus();
            }
            return false;
        }

        // Poll open threads every 4 seconds for new customer messages.
        setInterval(() => {
            openThreads.forEach(loadMessages);
        }, 4000);
    </script>
</body>
</html>