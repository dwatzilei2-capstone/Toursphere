(() => {
  if (!document.body.classList.contains('reservation-dispatch-module')) return;

  document.documentElement.classList.add('reservation-dispatch-motion-enabled');
  requestAnimationFrame(() => requestAnimationFrame(() => {
    document.body.classList.add('reservation-dispatch-motion-ready');
  }));

  document.querySelectorAll('.reservation-dispatch-page-shell .tc-table tbody tr').forEach((row) => {
    row.addEventListener('pointerdown', () => row.classList.add('is-pressed'));
    ['pointerup', 'pointercancel', 'pointerleave'].forEach((eventName) => {
      row.addEventListener(eventName, () => row.classList.remove('is-pressed'));
    });
  });

  document.addEventListener('submit', (event) => {
    if (event.defaultPrevented || !event.target.closest('.reservation-dispatch-module')) return;
    requestAnimationFrame(() => {
      if (event.defaultPrevented) return;
      const button = event.submitter || event.target.querySelector('button[type="submit"]');
      if (!button) return;
      button.dataset.rdOriginalHtml = button.innerHTML;
      button.classList.add('is-loading');
      button.setAttribute('aria-busy', 'true');
      const label = button.textContent.trim();
      button.innerHTML = `<i class="bi bi-arrow-repeat" aria-hidden="true"></i><span>${label}</span>`;
    });
  });

  window.addEventListener('pageshow', () => {
    document.querySelectorAll('.reservation-dispatch-module .tc-btn.is-loading').forEach((button) => {
      if (button.dataset.rdOriginalHtml) button.innerHTML = button.dataset.rdOriginalHtml;
      delete button.dataset.rdOriginalHtml;
      button.classList.remove('is-loading');
      button.removeAttribute('aria-busy');
    });
  });
})();
