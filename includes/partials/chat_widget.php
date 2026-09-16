<?php
/**
 * AI support chat widget.
 * Include this once, right before </body>, on any page only a
 * logged-in user can reach. Needs $_SESSION['user_name'] to already
 * be set (it is, by both login flows) and the csrf_* helpers loaded.
 */
$__chatUserName  = $_SESSION['user_name']  ?? '';
$__chatUserEmail = $_SESSION['user_email'] ?? '';
?>
<div id="te-chat-widget" class="te-chat-widget" data-user-name="<?= htmlspecialchars($__chatUserName, ENT_QUOTES, 'UTF-8') ?>" data-user-email="<?= htmlspecialchars($__chatUserEmail, ENT_QUOTES, 'UTF-8') ?>">
    <button id="te-chat-toggle" class="te-chat-toggle" type="button" aria-label="Open support chat">💬</button>

    <div id="te-chat-panel" class="te-chat-panel" hidden>
        <div class="te-chat-header">
            <span>TravelEase Support</span>
            <button id="te-chat-close" type="button" aria-label="Close chat">&times;</button>
        </div>
        <div id="te-chat-messages" class="te-chat-messages"></div>
        <form id="te-chat-form" class="te-chat-form">
            <?= csrf_field() ?>
            <input type="text" id="te-chat-input" placeholder="Ask a question..." autocomplete="off" maxlength="1000" required>
            <button type="submit">Send</button>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/@emailjs/browser@4/dist/email.min.js"></script>
<script src="/assets/js/chat-widget.js"></script>
