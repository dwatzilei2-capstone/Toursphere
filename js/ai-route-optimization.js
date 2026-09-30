(() => {
  'use strict';

  const body = document.body;
  if (!body || !body.classList.contains('ai-route-optimization-module')) return;

  requestAnimationFrame(() => {
    requestAnimationFrame(() => body.classList.add('ai-route-motion-ready'));
  });

  document.querySelectorAll('.tc-table tbody tr').forEach((row) => {
    if (row.querySelector('td[colspan]')) return;
    row.addEventListener('pointerdown', () => row.classList.add('ai-route-row-pressed'));
    ['pointerup', 'pointercancel', 'pointerleave'].forEach((eventName) => {
      row.addEventListener(eventName, () => row.classList.remove('ai-route-row-pressed'));
    });
  });

  if (!body.classList.contains('ai-route-planner-page')) return;

  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reducedMotion) return;

  const animateUpdate = (element) => {
    element.classList.remove('ai-route-content-updated');
    requestAnimationFrame(() => element.classList.add('ai-route-content-updated'));
  };

  ['ai-res-title', 'ai-res-reason', 'ai-itinerary-list'].forEach((id) => {
    const element = document.getElementById(id);
    if (!element) return;
    new MutationObserver(() => animateUpdate(element)).observe(element, {
      childList: true,
      characterData: true,
      subtree: true
    });
    element.addEventListener('animationend', () => element.classList.remove('ai-route-content-updated'));
  });
})();
