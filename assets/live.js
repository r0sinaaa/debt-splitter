// Live updates for the group page, using AJAX polling.
//
// Every few seconds the page asks group_state.php for the group's fingerprint.
// If it has changed (say, an expense was added from another tab or device),
// the page fetches the latest version of itself in the background and swaps in
// the updated sections, so balances and payments update without reloading.
// Anything the person is in the middle of typing or editing is left alone.
(function () {
    var main = document.getElementById('main');
    if (!main || !main.dataset.stateUrl) return;

    var POLL_MS = 5000;
    var version = main.dataset.version;
    var busy = false;

    // Sections that can be swapped in. The expense form is only replaced when
    // it's empty and not being used, so nothing typed is ever lost.
    var SECTIONS = ['.page-head', '#settle', '#people', '#history'];

    function isEditing(el) {
        if (!el) return false;
        var active = document.activeElement;
        if (active && el.contains(active) && /INPUT|SELECT|TEXTAREA/.test(active.tagName)) return true;
        return el.querySelector('details[open]') !== null && el.id !== 'settle';
    }

    function setStatus(text) {
        var status = document.getElementById('live-status');   // looked up each time: it may have been swapped in
        if (status) status.textContent = text;
    }

    function refresh() {
        return fetch(window.location.pathname + window.location.search, { credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                var fresh = new DOMParser().parseFromString(html, 'text/html');
                var skipped = false;
                SECTIONS.concat(['#expense']).forEach(function (selector) {
                    var current = document.querySelector(selector);
                    var updated = fresh.querySelector(selector);
                    if (!current || !updated) return;
                    if (selector === '#expense') {
                        var typed = Array.prototype.some.call(current.querySelectorAll('input[type="text"]'), function (i) { return i.value !== ''; });
                        if (typed || isEditing(current)) return;
                    } else if (isEditing(current)) {
                        skipped = true;
                        return;
                    }
                    current.innerHTML = updated.innerHTML;
                });
                var freshMain = fresh.getElementById('main');
                if (freshMain && !skipped) version = freshMain.dataset.version;
                setStatus(skipped ? 'This group has changed. Finish editing to see the update.' : 'Updated just now');
            });
    }

    function poll() {
        if (busy || document.hidden) return;
        busy = true;
        fetch(main.dataset.stateUrl, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) {
                if (r.status === 401) { window.location.href = 'login.php'; throw new Error('logged out'); }
                return r.json();
            })
            .then(function (data) {
                if (data.version && data.version !== version) return refresh();
            })
            .catch(function () { /* offline for a moment: try again next time */ })
            .then(function () { busy = false; });
    }

    setInterval(poll, POLL_MS);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
})();
