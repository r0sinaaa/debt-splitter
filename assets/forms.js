// Stops a form being sent twice by a double click: once it's submitted,
// its buttons are switched off until the next page loads.
document.addEventListener('submit', function (event) {
    var form = event.target;
    if (event.defaultPrevented) return;   // e.g. "Cancel" in a confirm box
    setTimeout(function () {
        form.querySelectorAll('button[type="submit"]').forEach(function (button) {
            button.disabled = true;
        });
    }, 0);
});
