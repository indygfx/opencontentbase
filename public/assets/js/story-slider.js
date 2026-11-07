/* Excerpt slider on the writing overview: auto-advance + dot navigation.
   Pacing: SLIDE_INTERVAL_MS is deliberately slow (2x the original 8s) so the
   cross-fade is not frantic; hover/pointer pauses it, and users with
   prefers-reduced-motion get no auto-advance at all (dots still work). */
(function () {
    'use strict';
    const SLIDE_INTERVAL_MS = 16000;

    var slider = document.querySelector('.story-slider');
    if (!slider || slider.dataset.count === '1') {
        return;
    }
    var slides = slider.querySelectorAll('.story-slide');
    var dots = slider.querySelectorAll('.story-slider-nav button');
    if (slides.length < 2) {
        return;
    }
    var current = 0;
    var timer = null;
    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function show(index) {
        current = (index + slides.length) % slides.length;
        slides.forEach(function (slide, i) {
            slide.classList.toggle('is-active', i === current);
        });
        dots.forEach(function (dot, i) {
            dot.classList.toggle('is-active', i === current);
        });
    }

    function next() {
        show(current + 1);
    }

    function stop() {
        if (timer !== null) {
            clearInterval(timer);
            timer = null;
        }
    }

    function restart() {
        stop();
        if (!reducedMotion) {
            timer = setInterval(next, SLIDE_INTERVAL_MS);
        }
    }

    // Pause on hover/touch-hold; resume on leave (Pointer Events cover touch).
    slider.addEventListener('pointerenter', stop);
    slider.addEventListener('pointerleave', restart);

    dots.forEach(function (dot) {
        dot.addEventListener('click', function () {
            show(parseInt(dot.dataset.slide, 10));
            restart();
        });
    });

    restart();
})();
