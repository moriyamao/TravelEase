(function () {
    'use strict';

    // EmailJS credentials are meant to be public (client-side SDK) --
    // this is not a secret, unlike the Groq API key which never
    // leaves the server (see includes/chat_ai.php).
    const EMAILJS_PUBLIC_KEY  = 'tsCajoh5dqxR93vz5';
    const EMAILJS_SERVICE_ID  = 'service_28hg6ip';
    const EMAILJS_TEMPLATE_ID = 'template_t4gn02v';

    const widget   = document.getElementById('te-chat-widget');
    const toggle   = document.getElementById('te-chat-toggle');
    const panel    = document.getElementById('te-chat-panel');
    const closeBtn = document.getElementById('te-chat-close');
    const form     = document.getElementById('te-chat-form');
    const input    = document.getElementById('te-chat-input');
    const messages = document.getElementById('te-chat-messages');

    const userName  = widget.dataset.userName  || 'TravelEase user';
    const userEmail = widget.dataset.userEmail || '';
    // Set only on a trip page: the id lets the server answer status questions
    // about that trip (it re-checks access each time). Anything that is not a
    // plain positive id is ignored, so general support is unchanged.
    const rawTripId = widget.dataset.tripId || '';
    const tripId = /^[1-9][0-9]{0,9}$/.test(rawTripId) && Number(rawTripId) <= 4294967295
        ? Number(rawTripId) : null;

    toggle.addEventListener('click', () => {
        // Close the support conversation panel first, if it's open, so
        // the two widgets never overlap on screen.
        const supportPanel = document.getElementById('te-support-panel');
        if (supportPanel && !supportPanel.hidden) supportPanel.hidden = true;

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

    // After a slow reply, refocus the input only if the user is still here:
    // the panel is open and focus has not moved anywhere else on the page.
    function restoreInputFocus() {
        const active = document.activeElement;
        if (!panel.hidden && (!active || active === document.body || panel.contains(active))) {
            input.focus();
        }
    }

    function addMessage(text, sender) {
        const el = document.createElement('div');
        el.className = 'te-chat-message te-chat-message--' + sender;
        el.textContent = text;
        messages.appendChild(el);
        messages.scrollTop = messages.scrollHeight;
    }

    async function escalateToSupport(userMessage) {
        // The server has committed the conversation before returning escalate=true.
        addMessage('Your message is saved in your support conversation. Refresh this page to open it and read staff replies.', 'bot');
        if (!userEmail || !window.emailjs) return;

        try {
            window.emailjs.init({ publicKey: EMAILJS_PUBLIC_KEY });
            await window.emailjs.send(EMAILJS_SERVICE_ID, EMAILJS_TEMPLATE_ID, {
                title:   'Chatbot escalation',
                name:    userName,
                email:   userEmail,
                time:    new Date().toLocaleString(),
                message: userMessage,
            });
        } catch (err) {
            // Email is optional; the saved conversation remains available to staff.
            console.error('Optional EmailJS notification failed:', err);
        }
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const message = input.value.trim();
        if (!message) return;

        addMessage(message, 'user');
        input.value = '';
        input.disabled = true;

        const csrfToken = form.querySelector('input[name="csrf_token"]').value;

        try {
            const res = await fetch('/chat/ask.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(tripId === null
                    ? { message: message, csrf_token: csrfToken }
                    : { message: message, csrf_token: csrfToken, trip_id: tripId }),
            });

            const data = await res.json();

            if (!res.ok) {
                addMessage(data.error || 'Something went wrong. Please try again.', 'bot');
                input.value = message;
                return;
            }

            addMessage(data.reply, 'bot');

            if (data.escalate) {
                escalateToSupport(message);
            }
        } catch (err) {
            console.error('Chat request failed:', err);
            addMessage("I'm having trouble connecting right now. Please try again in a moment.", 'bot');
            input.value = message;
        } finally {
            input.disabled = false;
            restoreInputFocus();
        }
    });
})();
