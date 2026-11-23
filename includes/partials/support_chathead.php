<?php
/**
 * Support chathead: lets a customer with an open escalation continue
 * the conversation with staff in near-real-time (polling, not
 * websockets -- a few seconds of delay is expected).
 *
 * Requires $pdo and current_user_id() to be available, and csrf.php
 * loaded. Renders nothing at all if the customer has no open
 * escalation -- most customers most of the time.
 */
$stmt = $pdo->prepare(
    'SELECT id FROM escalations
     WHERE user_id = :user_id AND status = "open"
     ORDER BY created_at DESC
     LIMIT 1'
);
$stmt->execute(['user_id' => current_user_id()]);
$__openEscalation = $stmt->fetch();
?>
<?php if ($__openEscalation): ?>
<div id="te-support-chathead" class="te-chat-widget" data-escalation-id="<?= (int) $__openEscalation['id'] ?>">
    <button id="te-support-toggle" class="te-chat-toggle" type="button" aria-label="Open your conversation with support staff" hidden>💬</button>

    <div id="te-support-panel" class="te-chat-panel" hidden>
        <div class="te-chat-header" style="background: var(--route, #2F7A5C);">
            <span>Support staff <small class="te-chat-tag">Your request</small></span>
            <button id="te-support-close" type="button" aria-label="Close">&times;</button>
        </div>
        <p class="te-support-share"><a href="/customer/support_access.php">Share a trip with support</a> (optional, read-only)</p>
        <div id="te-support-messages" class="te-chat-messages"></div>
        <form id="te-support-form" class="te-chat-form">
            <?= csrf_field() ?>
            <input type="text" id="te-support-input" placeholder="Type a message..." autocomplete="off" maxlength="1000" required>
            <button type="submit">Send</button>
        </form>
    </div>
</div>
<script src="/assets/js/support-chathead.js"></script>
<?php endif; ?>