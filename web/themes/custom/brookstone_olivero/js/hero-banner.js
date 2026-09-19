/**
 * @file
 * Full-bleed rotating banner hero: crossfade auto-advance, dots, swipe.
 * Honors prefers-reduced-motion (no auto-advance; first slide shown).
 */
((Drupal, once) => {
  'use strict';

  const INTERVAL = 6000;

  Drupal.behaviors.boHeroBanner = {
    attach(context) {
      once('bo-hero', '[data-bo-hero].bo-hero--rotating', context).forEach((hero) => {
        const slides = Array.from(hero.querySelectorAll('.bo-hero__img'));
        const dots = Array.from(hero.querySelectorAll('.bo-hero__dot'));
        if (slides.length < 2) {
          return;
        }

        let index = 0;
        let timer = null;
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        const show = (next) => {
          index = (next + slides.length) % slides.length;
          slides.forEach((s, i) => s.classList.toggle('is-active', i === index));
          dots.forEach((d, i) => d.classList.toggle('is-active', i === index));
        };

        const start = () => {
          if (reduce || timer) {
            return;
          }
          timer = window.setInterval(() => show(index + 1), INTERVAL);
        };
        const stop = () => {
          window.clearInterval(timer);
          timer = null;
        };
        const restart = () => {
          stop();
          start();
        };

        dots.forEach((dot, i) => {
          dot.addEventListener('click', () => {
            show(i);
            restart();
          });
        });

        // Pause on hover (desktop), resume on leave.
        hero.addEventListener('mouseenter', stop);
        hero.addEventListener('mouseleave', start);

        // Swipe (touch).
        let x0 = null;
        hero.addEventListener('touchstart', (e) => {
          x0 = e.changedTouches[0].clientX;
        }, { passive: true });
        hero.addEventListener('touchend', (e) => {
          if (x0 === null) {
            return;
          }
          const dx = e.changedTouches[0].clientX - x0;
          if (Math.abs(dx) > 40) {
            show(index + (dx < 0 ? 1 : -1));
            restart();
          }
          x0 = null;
        }, { passive: true });

        start();
      });
    },
  };
})(Drupal, once);
