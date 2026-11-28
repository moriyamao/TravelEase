(function () {
    'use strict';

    const widget = document.getElementById('te-support-chathead');
    if (!widget) return; // no open escalation for this customer right now

    const toggle   = document.getElementById('te-support-toggle');
    const panel    = document.getElementById('te-support-panel');
    const closeBtn = document.getElementById('te-support-close');
    const form     = document.getElementById('te-support-form');
    const input    = document.getElementById('te-support-input');
    const messages = document.getElementById('te-support-messages');

    let shownIds = new Set();      // ids of messages already on screen
    let historyLoaded = false;     // the live region turns on after the first load
    let pollSeq = 0;               // order of poll requests ...
    let appliedSeq = 0;            // ... and the newest one applied
    let conversationClosed = false;
    let pollTimer = null;

    toggle.addEventListener('click', () => {
        // Close the AI chat panel first, if it's open, so the two
        // widgets never overlap on screen.
        const aiPanel = document.getElementById('te-chat-panel');
        if (aiPanel && !aiPanel.hidden) aiPanel.hidden = true;

        panel.hidden = !panel.hidden;
        if (!panel.hidden) input.focus();
    });

    // Non-modal panel: closing it (close button or Escape) returns focus to
    // the Help launcher, so keyboard users are not dropped at the page top.
    function closePanel() {
        panel.hidden = true;
        const launcher = document.getElementById('te-launcher-button');
        if (launcher) launcher.focus();
    }
    closeBtn.addEventListener('click', closePanel);
    panel.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            e.preventDefault();
            closePanel();
        }
    });

    // After a slow send, refocus the input only if the user is still here:
    // the panel is open and focus has not moved anywhere else on the page.
    function restoreInputFocus() {
        const active = document.activeElement;
        if (!panel.hidden && (!active || active === document.body || panel.contains(active))) {
            input.focus();
        }
    }

    function messageElement(msg) {
        const el = document.createElement('div');
        el.className = 'te-chat-message te-chat-message--' + (msg.sender_type === 'customer' ? 'user' : 'bot');
        el.textContent = msg.message;
        return el;
    }

    function enableLiveRegion() {
        messages.setAttribute('role', 'log');
        messages.setAttribute('aria-live', 'polite');
    }

    function disableLiveRegion() {
        messages.removeAttribute('role');
        messages.removeAttribute('aria-live');
    }

    // Show a conversation's whole history without announcing it: the live
    // region is off while the list is replaced, then turned back on, so a
    // screen reader reads only messages that arrive afterwards.
    function showHistory(msgs) {
        disableLiveRegion();
        shownIds = new Set(msgs.map((msg) => msg.id));
        messages.replaceChildren(...msgs.map(messageElement));
        messages.scrollTop = messages.scrollHeight;
        historyLoaded = true;
        setTimeout(enableLiveRegion, 0);
    }

    // The server reports which conversation it answered for (the customer's
    // newest open request). A different id is a different conversation: its
    // history replaces the old one and later messages go to it. No open
    // request means this one was closed.
    function applyPoll(data) {
        if (data.escalation_id === null) {
            closeConversation();
            return;
        }
        const id = String(data.escalation_id);
        if (!historyLoaded || id !== widget.dataset.escalationId) {
            widget.dataset.escalationId = id;
            showHistory(data.messages);
            return;
        }
        let added = false;
        data.messages.forEach((msg) => {
            if (!shownIds.has(msg.id)) {   // append only unseen messages, in server order
                shownIds.add(msg.id);
                messages.appendChild(messageElement(msg));
                added = true;
            }
        });
        if (added) messages.scrollTop = messages.scrollHeight;
    }

    function closeConversation() {
        if (conversationClosed) return;
        conversationClosed = true;
        clearInterval(pollTimer);
        input.disabled = true;
        form.querySelector('button[type="submit"]').disabled = true;
        messages.appendChild(messageElement({ sender_type: 'staff', message: 'This support request has been closed.' }));
        messages.scrollTop = messages.scrollHeight;
    }

    async function poll() {
        if (conversationClosed) return;
        const seq = ++pollSeq;
        try {
            const res = await fetch('/customer/escalation_messages.php');
            if (!res.ok) return;
            const data = await res.json();
            // Polls can overlap (the timer and a send); ignore one that
            // finishes after a newer one has already been applied.
            if (seq < appliedSeq || !Array.isArray(data.messages)) return;
            appliedSeq = seq;
            applyPoll(data);
        } catch (err) {
            console.error('Support poll failed:', err);
        }
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const message = input.value.trim();
        if (!message) return;

        const escalationId = widget.dataset.escalationId;
        const csrfToken = form.querySelector('input[name="csrf_token"]').value;

        input.value = '';
        input.disabled = true;

        try {
            const res = await fetch('/customer/send_escalation_message.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    escalation_id: escalationId,
                    message: message,
                    csrf_token: csrfToken,
                }),
            });

            if (res.ok) {
                await poll(); // immediately show the message we just sent
            } else {
                input.value = message; // keep the unsent text
            }
        } catch (err) {
            console.error('Send failed:', err);
            input.value = message;
        } finally {
            input.disabled = conversationClosed;
            if (!conversationClosed) restoreInputFocus();
        }
    });

    poll();
    pollTimer = setInterval(poll, 4000); // check for new messages every 4 seconds
})();