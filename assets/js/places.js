/*
 * Trip places page (FR-21 / FR-27): map, place search, nearby search.
 * All text from the server or the search service is inserted with
 * textContent, never as HTML. Search only runs when the user submits.
 */
(function () {
    'use strict';

    var config = window.TRIP_PLACES || {};
    var dataEl = document.getElementById('places-data');
    var data = { places: [], days: [], tileUrl: '' };
    try {
        if (dataEl) { data = JSON.parse(dataEl.textContent); }
    } catch (e) { /* keep the empty defaults */ }

    // ---- Map -----------------------------------------------------------
    var mapEl = document.getElementById('places-map');
    if (mapEl && window.L && data.places.length) {
        var map = L.map(mapEl, { scrollWheelZoom: false });
        L.tileLayer(data.tileUrl, {
            maxZoom: 19,
            referrerPolicy: 'origin',
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);

        var bounds = [];
        data.places.forEach(function (p) {
            var popup = document.createElement('strong');
            popup.textContent = p.name;
            L.marker([p.lat, p.lon], { title: p.name, alt: p.name }).bindPopup(popup).addTo(map);
            bounds.push([p.lat, p.lon]);
        });
        // Schematic order of each day's places -- not a road route.
        var colors = ['#b5532c', '#335b26', '#2f5d8a', '#7a3e8f', '#8a6d1f'];
        data.days.forEach(function (day, i) {
            var line = day.points.map(function (pt) { return [pt.lat, pt.lon]; });
            var label = document.createElement('span');
            label.textContent = day.date + ': order of visits (not a route)';
            L.polyline(line, { color: colors[i % colors.length], weight: 3, dashArray: '6 6' })
                .bindTooltip(label).addTo(map);
        });
        if (bounds.length === 1) {
            map.setView(bounds[0], 15);
        } else {
            map.fitBounds(bounds, { padding: [24, 24], maxZoom: 16 });
        }
    } else if (mapEl) {
        mapEl.textContent = 'The map could not be loaded. The saved places are listed below.';
        mapEl.classList.add('places-map-fallback');
    }

    // ---- Search and nearby ---------------------------------------------
    var panel = document.querySelector('[data-search-panel]');
    var form = document.getElementById('place-search-form');
    var statusEl = document.getElementById('place-search-status');
    var resultsEl = document.getElementById('place-search-results');
    var saveForm = document.getElementById('place-save-form');
    if (!panel || !form || !window.fetch) { return; }
    panel.hidden = false;
    document.querySelectorAll('.place-nearby').forEach(function (el) { el.hidden = false; });

    var busy = false;

    function setStatus(text) { statusEl.textContent = text; }

    function render(results) {
        resultsEl.textContent = '';
        if (!results.length) { setStatus('No places found.'); return; }
        setStatus(results.length === 1 ? '1 place found.' : results.length + ' places found.');
        results.forEach(function (r) {
            var li = document.createElement('li');
            var name = document.createElement('strong');
            name.textContent = r.name;
            li.appendChild(name);
            if (r.address) {
                var addr = document.createElement('span');
                addr.className = 'place-address';
                addr.textContent = r.address;
                li.appendChild(addr);
            }
            if (config.canEdit && saveForm) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.textContent = 'Save to trip';
                btn.addEventListener('click', function () {
                    saveForm.elements.name.value = r.name || '';
                    saveForm.elements.address.value = r.address || '';
                    saveForm.elements.latitude.value = r.latitude === null || r.latitude === undefined ? '' : r.latitude;
                    saveForm.elements.longitude.value = r.longitude === null || r.longitude === undefined ? '' : r.longitude;
                    saveForm.elements.category.value = r.category || '';
                    saveForm.elements.provider_place_id.value = r.provider_place_id || '';
                    btn.disabled = true;
                    saveForm.submit();
                });
                li.appendChild(btn);
            }
            resultsEl.appendChild(li);
        });
    }

    function search(fields, scrollTarget) {
        if (busy) { return; }
        busy = true;
        setStatus('Searching…');
        resultsEl.textContent = '';
        var body = new FormData();
        body.append('csrf_token', config.csrf);
        body.append('trip_id', String(config.tripId));
        Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
        fetch('/customer/trips/place_search.php', { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (res) {
                return res.json().catch(function () { return { ok: false, error: 'Search failed. Please try again.' }; });
            })
            .then(function (json) {
                if (json && json.ok && Array.isArray(json.results)) {
                    render(json.results);
                } else {
                    setStatus((json && json.error) || 'Search failed. Please try again.');
                }
            })
            .catch(function () { setStatus('Search failed. Check your connection and try again.'); })
            .then(function () {
                busy = false;
                if (scrollTarget) { panel.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
            });
    }

    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var q = form.elements.q.value.trim();
        if (q.length < 2) { setStatus('Enter at least 2 characters.'); return; }
        search({ mode: 'text', q: q }, false);
    });

    document.querySelectorAll('[data-nearby]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-nearby');
            var select = document.getElementById('nearby-cat-' + id);
            search({ mode: 'nearby', place_id: id, category: select ? select.value : '' }, true);
        });
    });
})();
