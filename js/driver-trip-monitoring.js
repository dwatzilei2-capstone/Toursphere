(() => {
  if (!document.body.classList.contains('driver-trip-monitoring-module')) return;

  document.documentElement.classList.add('driver-trip-motion-enabled');

  if (document.body.classList.contains('trip-performance-page') && typeof Chart !== 'undefined') {
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    Chart.defaults.animation = {
      duration: reduceMotion ? 0 : 850,
      easing: 'easeOutQuart',
      delay: (context) => reduceMotion ? 0 : (context.type === 'data' ? context.dataIndex * 65 : 0),
    };
    Chart.defaults.interaction = { mode: 'nearest', intersect: false };
  }

  requestAnimationFrame(() => requestAnimationFrame(() => {
    document.body.classList.add('driver-trip-motion-ready');
  }));

  document.querySelectorAll('#drivers-grid-container .tc-card').forEach((card) => {
    card.addEventListener('pointerdown', () => card.classList.add('is-pressed'));
    ['pointerup', 'pointercancel', 'pointerleave'].forEach((eventName) => {
      card.addEventListener(eventName, () => card.classList.remove('is-pressed'));
    });
  });
})();
