(() => {
  'use strict';
  if (!document.body.classList.contains('role-portal-polish')) return;
  const content = document.getElementById('content-container');
  if (!content || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  // Animate only initial surfaces; refreshing status never replays a whole card.
  content.querySelectorAll('.tc-card, .stat-card').forEach((card, index) => {
    card.style.setProperty('--portal-delay', `${Math.min(index, 4) * 35}ms`);
    card.classList.add('portal-enter');
    card.addEventListener('animationend', (event) => {
      if (event.target === card) card.classList.remove('portal-enter');
    }, { once: true });
  });

  // Existing booking calculations own these live regions. Observe text only.
  ['required-vehicle-type', 'capacity-hint', 'departure-schedule-help', 'return-schedule-help'].forEach((id) => {
    const region = document.getElementById(id);
    if (!region) return;
    let frame = null;
    const observer = new MutationObserver(() => {
      if (frame !== null) cancelAnimationFrame(frame);
      region.classList.remove('portal-feedback');
      frame = requestAnimationFrame(() => {
        region.classList.add('portal-feedback');
        frame = null;
      });
    });
    observer.observe(region, { childList: true, characterData: true, subtree: true });
    region.addEventListener('animationend', () => region.classList.remove('portal-feedback'));
  });
})();
