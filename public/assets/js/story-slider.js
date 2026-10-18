(function () {
    'use strict';
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

    function restart() {
        if (timer !== null) {
            clearInterval(timer);
        }
        timer = setInterval(next, 8000);
    }

    dots.forEach(function (dot) {
        dot.addEventListener('click', function () {
            show(parseInt(dot.dataset.slide, 10));
            restart();
        });
    });

    restart();
})();
