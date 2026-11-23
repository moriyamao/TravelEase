/* One Help launcher: opens the AI assistant, or offers the assistant and the
   staff conversation when a support request is open. It reuses each widget's
   own (hidden) toggle button, so their existing scripts keep working. */
(function () {
    'use strict';
    var button = document.getElementById('te-launcher-button');
    var menu = document.getElementById('te-launcher-menu');
    if (!button || !menu) {
        return;
    }

    function openWidget(toggleId) {
        var toggle = document.getElementById(toggleId);
        if (toggle) {
            toggle.click();
        }
    }
    function closeMenu() {
        menu.hidden = true;
        button.setAttribute('aria-expanded', 'false');
    }

    button.addEventListener('click', function () {
        var single = button.getAttribute('data-single-target');
        if (single) {
            openWidget(single);
            var panel = document.getElementById('te-chat-panel');
            button.setAttribute('aria-expanded', panel && !panel.hidden ? 'true' : 'false');
            return;
        }
        var opening = menu.hidden;
        menu.hidden = !opening;
        button.setAttribute('aria-expanded', opening ? 'true' : 'false');
        if (opening) {
            var first = menu.querySelector('.te-launcher-option');
            if (first) {
                first.focus();
            }
        }
    });

    menu.addEventListener('click', function (event) {
        var option = event.target.closest('[data-open-widget]');
        if (!option) {
            return;
        }
        closeMenu();
        openWidget(option.getAttribute('data-open-widget'));
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !menu.hidden) {
            closeMenu();
            button.focus();
        }
    });
    document.addEventListener('click', function (event) {
        if (!menu.hidden && !event.target.closest('#te-launcher')) {
            closeMenu();
        }
    });
}());
