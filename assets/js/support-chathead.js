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

    let lastMessageCount = 0;

    toggle.addEventListener('click', () => {
        // Close the AI chat panel first, if it's open, so the two
        // widgets never overlap on screen.
        const aiPanel = document.getElementById('te-chat-panel');
        if (aiPanel && !aiPanel.hidden) aiPanel.hidden = true;

        panel.hidden = !panel.hidden;
        if (!panel.hidden) input.focus();
    });
    closeBtn.addEventListener('click', () => { panel.hidden = true; });

    function renderMessages(msgs) {
        if (msgs.length === lastMessageCount) return; // nothing new, skip re-render

        messages.innerHTML = '';
        msgs.forEach((msg) => {
            const el = document.createElement('div');
            el.className = 'te-chat-message te-chat-message--' + (msg.sender_type === 'customer' ? 'user' : 'bot');
            el.textContent = msg.message;
            messages.appendChild(el);
        });
        messages.scrollTop = messages.scrollHeight;
        lastMessageCount = msgs.length;
    }

    async function poll() {
        try {
            const res = await fetch('/customer/escalation_messages.php');
            const data = await res.json();
            if (data.messages) renderMessages(data.messages);
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
            }
        } catch (err) {
            console.error('Send failed:', err);
        } finally {
            input.disabled = false;
            input.focus();
        }
    });

    poll();
    setInterval(poll, 4000); // check for new messages every 4 seconds
})();