<?php
/**
 * One Help launcher for both support conversations (replaces the two
 * floating chat buttons). Include right after chat_widget.php and
 * support_chathead.php; it reads $__openEscalation from the latter.
 *
 * - No open support request: the launcher opens the AI assistant directly.
 * - Open request: it shows two clearly named choices, the AI assistant or
 *   the staff conversation, and a dot marks the open conversation.
 */
$__hasStaffThread = !empty($__openEscalation);
?>
<div class="te-launcher" id="te-launcher">
    <div class="te-launcher-menu" id="te-launcher-menu" hidden>
        <p class="te-launcher-title">How can we help?</p>
        <button type="button" class="te-launcher-option" data-open-widget="te-chat-toggle">
            <strong>Ask the assistant</strong>
            <span>Instant AI answers about using TravelEase</span>
        </button>
        <?php if ($__hasStaffThread): ?>
            <button type="button" class="te-launcher-option" data-open-widget="te-support-toggle">
                <strong>Message support staff</strong>
                <span>Continue your open support request</span>
            </button>
        <?php endif; ?>
    </div>
    <button type="button" class="te-launcher-button" id="te-launcher-button"
            aria-controls="<?= $__hasStaffThread ? 'te-launcher-menu' : 'te-chat-panel' ?>" aria-expanded="false"
            data-single-target="<?= $__hasStaffThread ? '' : 'te-chat-toggle' ?>">
        <span class="te-launcher-icon" aria-hidden="true">?</span>
        Help<?php if ($__hasStaffThread): ?><span class="te-launcher-dot"><span class="sr-only"> (open support request)</span></span><?php endif; ?>
    </button>
</div>
<script src="/assets/js/support-launcher.js"></script>
