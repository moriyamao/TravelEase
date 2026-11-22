/*
 * FR-15 trip share links.
 *
 * Owner page (share_links.php): copy button for the one-time new link, and
 * the "anyone with the link" confirmation, required only for view-only links.
 *
 * Public page (share.php): reads the token from the URL fragment (never
 * sent in a request URL), POSTs it to share_resolve.php and renders the
 * reply with textContent/createElement only, never as HTML.
 */
(function () {
    'use strict';

    // ---- Owner page ----
    var copyButton = document.querySelector('[data-share-copy]');
    var copySource = document.querySelector('[data-share-copy-source]');
    var copyStatus = document.querySelector('[data-share-copy-status]');
    if (copyButton && copySource) {
        copyButton.hidden = false;
        copyButton.addEventListener('click', function () {
            var done = function (ok) {
                if (copyStatus) {
                    copyStatus.textContent = ok ? 'Link copied.' : 'Select the link and copy it.';
                }
            };
            copySource.focus();
            copySource.select();
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(copySource.value).then(function () { done(true); }, function () { done(false); });
            } else {
                var ok = false;
                try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
                done(ok);
            }
        });
    }

    var createForm = document.querySelector('[data-share-create]');
    if (createForm) {
        var publishBlock = createForm.querySelector('[data-share-publish]');
        var publishBox = createForm.querySelector('[data-share-publish-box]');
        var syncPublish = function () {
            var kind = createForm.querySelector('input[name="kind"]:checked');
            var isView = kind && kind.value === 'view';
            publishBlock.hidden = !isView;
            publishBox.required = isView;
            if (!isView) {
                publishBox.checked = false;
            }
        };
        Array.prototype.forEach.call(createForm.querySelectorAll('input[name="kind"]'), function (input) {
            input.addEventListener('change', syncPublish);
        });
        syncPublish();
    }

    // ---- Public landing page ----
    var status = document.querySelector('[data-share-status]');
    if (!status) {
        return;
    }
    var viewPanel = document.querySelector('[data-share-view]');
    var joinPanel = document.querySelector('[data-share-join]');

    function setStatus(message, isError) {
        status.textContent = message;
        status.className = isError ? 'share-status share-error' : 'share-status';
        status.hidden = message === '';
    }

    function formatDate(value) {
        var match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || ''));
        if (!match) {
            return String(value || '');
        }
        var date = new Date(Date.UTC(+match[1], +match[2] - 1, +match[3]));
        try {
            return date.toLocaleDateString(undefined, { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric', timeZone: 'UTC' });
        } catch (e) {
            return String(value);
        }
    }

    function shortTime(value) {
        var match = /^(\d{2}):(\d{2})/.exec(String(value || ''));
        return match ? match[1] + ':' + match[2] : '';
    }

    function text(tag, className, value) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        node.textContent = value;
        return node;
    }

    function dateRange(data) {
        return formatDate(data.start_date) + ' – ' + formatDate(data.end_date);
    }

    function renderView(data) {
        viewPanel.querySelector('[data-share-destination]').textContent = String(data.destination || 'Shared trip');
        viewPanel.querySelector('[data-share-dates]').textContent = dateRange(data);
        viewPanel.querySelector('[data-share-zone]').textContent = data.time_zone
            ? 'Times are in ' + String(data.time_zone).replace(/_/g, ' ') : '';
        var holder = viewPanel.querySelector('[data-share-activities]');
        holder.textContent = '';
        var activities = Array.isArray(data.activities) ? data.activities : [];
        if (!activities.length) {
            holder.appendChild(text('p', 'share-note', 'No activities are planned yet.'));
        }
        var list = null;
        var currentDate = null;
        activities.forEach(function (activity) {
            if (!activity || typeof activity !== 'object') {
                return;
            }
            if (activity.date !== currentDate || list === null) {
                currentDate = activity.date;
                holder.appendChild(text('h2', 'share-day', formatDate(activity.date)));
                list = document.createElement('ul');
                list.className = 'share-itinerary';
                holder.appendChild(list);
            }
            var item = document.createElement('li');
            var start = shortTime(activity.time);
            var end = shortTime(activity.end_time);
            item.appendChild(text('span', 'share-time', start ? (end ? start + '–' + end : start) : 'Any time'));
            var body = document.createElement('div');
            body.appendChild(text('strong', '', String(activity.title || '')));
            if (activity.location) {
                body.appendChild(text('span', 'share-location', String(activity.location)));
            }
            item.appendChild(body);
            list.appendChild(item);
        });
        viewPanel.hidden = false;
    }

    function renderJoin(data, token) {
        joinPanel.querySelector('[data-share-join-destination]').textContent = String(data.destination || 'a shared trip');
        joinPanel.querySelector('[data-share-join-dates]').textContent = dateRange(data);
        var tokenField = joinPanel.querySelector('[data-share-token]');
        if (tokenField) {
            tokenField.value = token;
        }
        joinPanel.hidden = false;
    }

    function fail(message) {
        var error = new Error(message);
        error.userFacing = true;
        return error;
    }

    // Pasting another link into the same tab changes only the fragment; start over for it.
    window.addEventListener('hashchange', function () { window.location.reload(); });

    var token = window.location.hash.replace(/^#/, '');
    if (!/^[0-9a-f]{64}$/.test(token)) {
        setStatus('This link is not valid. Check that you copied the whole link, or ask the trip owner for a new one.', true);
        return;
    }

    var body = new URLSearchParams();
    body.set('token', token);
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    body.set('csrf_token', csrfMeta ? csrfMeta.content : '');
    fetch('/public/share_resolve.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
        body: body.toString(),
        credentials: 'same-origin',
        cache: 'no-store',
        referrerPolicy: 'no-referrer'
    }).then(function (response) {
        if (response.status === 429) {
            var wait = parseInt(response.headers.get('Retry-After') || '60', 10);
            throw fail('Too many attempts from your network. Try again in about ' + Math.max(1, Math.ceil((isNaN(wait) ? 60 : wait) / 60)) + ' min.');
        }
        if (response.status === 403) {
            throw fail('Your session changed. Reload this page to open the link.');
        }
        if (response.status === 404) {
            throw fail('This link is invalid, has expired or was turned off. Ask the trip owner for a new one.');
        }
        if (!response.ok) {
            throw fail('Could not open the link right now. Please try again later.');
        }
        return response.json();
    }).then(function (data) {
        if (!data || (data.kind !== 'view' && data.kind !== 'join')) {
            throw fail('Could not open the link right now. Please try again later.');
        }
        setStatus('', false);
        if (data.kind === 'view') {
            renderView(data);
        } else {
            renderJoin(data, token);
        }
    }).catch(function (error) {
        setStatus(error && error.userFacing ? error.message : 'Could not open the link right now. Please try again later.', true);
    });
}());
