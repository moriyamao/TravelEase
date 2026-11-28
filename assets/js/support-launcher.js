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

    // Direct mode: keep aria-expanded in step with the assistant panel however
    // it opens or closes (launcher, its own close button, or the staff chat).
    var directPanel = button.getAttribute('data-single-target') ? document.getElementById('te-chat-panel') : null;
    if (directPanel) {
        new MutationObserver(function () {
            button.setAttribute('aria-expanded', directPanel.hidden ? 'false' : 'true');
        }).observe(directPanel, { attributes: true, attributeFilter: ['hidden'] });
    }

    button.addEventListener('click', function () {
        var single = button.getAttribute('data-single-target');
        if (single) {
            openWidget(single);
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
    // Tabbing out of the launcher closes the menu instead of leaving it open
    // over the page. relatedTarget is null when focus leaves the document.
    document.getElementById('te-launcher').addEventListener('focusout', function (event) {
        if (!menu.hidden && event.relatedTarget && !event.relatedTarget.closest('#te-launcher')) {
            closeMenu();
        }
    });
    document.addEventListener('click', function (event) {
        if (!menu.hidden && !event.target.closest('#te-launcher')) {
            closeMenu();
        }
    });
}());
