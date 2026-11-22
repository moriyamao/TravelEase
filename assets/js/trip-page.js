/* Trip details page: small progressive enhancements. Everything works without it. */
(function () {
    'use strict';

    // "+ Add activity" opens the add form below and moves focus into it.
    function openDetails(id, focus) {
        var details = document.getElementById(id);
        if (!details) {
            return;
        }
        details.open = true;
        if (focus) {
            var field = details.querySelector('input:not([type="hidden"]), select, textarea');
            if (field) {
                field.focus();
            }
        }
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-open-details]');
        if (!trigger) {
            return;
        }
        event.preventDefault();
        var id = trigger.getAttribute('data-open-details');
        openDetails(id, true);
        document.getElementById(id).scrollIntoView({ block: 'start', behavior: 'smooth' });
    });

    // Arriving with #add-activity, #plan-review or #trip-ideas opens that panel.
    var hash = window.location.hash.replace('#', '');
    if (hash === 'add-activity' || hash === 'plan-review' || hash === 'trip-ideas') {
        openDetails(hash, false);
    }
}());
