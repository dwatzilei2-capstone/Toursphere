(() => {
  'use strict';

  const body = document.body;
  if (!body || !body.classList.contains('fuel-management-module')) return;

  if (body.classList.contains('fuel-overview-page')) {
    const canvas = document.getElementById('chart-fuel-monthly');
    canvas?.parentElement?.classList.add('fuel-chart-wrap');

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
