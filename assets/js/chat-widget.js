(function () {
    'use strict';

    // EmailJS credentials are meant to be public (client-side SDK) --
    // this is not a secret, unlike the Groq API key which never
    // leaves the server (see includes/chat_ai.php).
    const EMAILJS_PUBLIC_KEY  = 'tsCajoh5dqxR93vz5';
    const EMAILJS_SERVICE_ID  = 'service_28hg6ip';
    const EMAILJS_TEMPLATE_ID = 'template_t4gn02v';

    emailjs.init({ publicKey: EMAILJS_PUBLIC_KEY });

    const widget   = document.getElementById('te-chat-widget');
    const toggle   = document.getElementById('te-chat-toggle');
    const panel    = document.getElementById('te-chat-panel');
    const closeBtn = document.getElementById('te-chat-close');
    const form     = document.getElementById('te-chat-form');
    const input    = document.getElementById('te-chat-input');
    const messages = document.getElementById('te-chat-messages');

    const userName  = widget.dataset.userName  || 'TravelEase user';
    const userEmail = widget.dataset.userEmail || '';

    toggle.addEventListener('click', () => {
        panel.hidden = !panel.hidden;
        if (!panel.hidden) input.focus();
    });
    closeBtn.addEventListener('click', () => { panel.hidden = true; });

    function addMessage(text, sender) {
        const el = document.createElement('div');
        el.className = 'te-chat-message te-chat-message--' + sender;
        el.textContent = text;
        messages.appendChild(el);
        messages.scrollTop = messages.scrollHeight;
    }

    function escalateToSupport(userMessage) {
        if (!userEmail) {
            addMessage("I can't reach support without an email on your account — please update your profile.", 'bot');
            return;
        }

        emailjs.send(EMAILJS_SERVICE_ID, EMAILJS_TEMPLATE_ID, {
            title:   'Chatbot escalation',
            name:    userName,
            email:   userEmail,
            time:    new Date().toLocaleString(),
            message: userMessage,
        }).then(() => {
            addMessage("I've sent this to our support team — they'll reply to your email directly.", 'bot');
        }).catch((err) => {
            console.error('EmailJS send failed:', err);
            addMessage("I couldn't reach support automatically — please email us directly for now.", 'bot');
        });
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
                body: JSON.stringify({ message: message, csrf_token: csrfToken }),
            });

            const data = await res.json();

            if (!res.ok) {
                addMessage(data.error || 'Something went wrong. Please try again.', 'bot');
                return;
            }

            addMessage(data.reply, 'bot');

            if (data.escalate) {
                escalateToSupport(message);
            }
        } catch (err) {
            console.error('Chat request failed:', err);
            addMessage("I'm having trouble connecting right now. Please try again in a moment.", 'bot');
        } finally {
            input.disabled = false;
            input.focus();
        }
    });
})();
