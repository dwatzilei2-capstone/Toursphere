(() => {
  'use strict';

  const body = document.body;
  if (!body || !body.classList.contains('fuel-management-module')) return;

  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  if (body.classList.contains('fuel-overview-page')) {
    const canvas = document.getElementById('chart-fuel-monthly');
    canvas?.parentElement?.classList.add('fuel-chart-wrap');

    document.addEventListener('DOMContentLoaded', () => {
      if (reduceMotion || !window.Chart) return;
      Chart.defaults.animation = {
        duration: 720,
        easing: 'easeOutQuart',
        delay: (context) => context.type === 'data' ? context.dataIndex * 22 : 0
      };
      Chart.defaults.transitions.active.animation.duration = 180;
    }, { once: true });
  }

  requestAnimationFrame(() => {
    requestAnimationFrame(() => body.classList.add('fuel-management-motion-ready'));
  });

  document.querySelectorAll('.tc-table tbody tr').forEach((row) => {
    if (row.querySelector('td[colspan]')) return;
    row.addEventListener('pointerdown', () => row.classList.add('fuel-row-pressed'));
    ['pointerup', 'pointercancel', 'pointerleave'].forEach((eventName) => {
      row.addEventListener(eventName, () => row.classList.remove('fuel-row-pressed'));
    });
  });
})();
