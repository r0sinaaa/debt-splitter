// Home page: the IOUs untangle into two payments once, shortly after the page loads.
// The button switches back and forth. With reduced motion, it just shows the result.
(function () {
    var figure = document.getElementById('untangle');
    var button = document.getElementById('untangle-toggle');
    if (!figure || !button) return;

    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function show(state) {
        figure.setAttribute('data-state', state);
        button.textContent = state === 'after' ? 'Show the IOUs again' : 'Show the payments';
    }

    button.hidden = false;
    button.addEventListener('click', function () {
        show(figure.getAttribute('data-state') === 'after' ? 'before' : 'after');
    });

    if (reduceMotion) {
        show('after');
        return;
    }

    // Start on the tangled IOUs without animating, then untangle them
    figure.classList.add('no-anim');
    show('before');
    requestAnimationFrame(function () {
        requestAnimationFrame(function () {
            figure.classList.remove('no-anim');
            setTimeout(function () { show('after'); }, 1400);
        });
    });
})();
