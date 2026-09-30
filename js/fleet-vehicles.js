(() => {
  if (!document.body.classList.contains('fleet-vehicles-module')) return;

  document.documentElement.classList.add('fleet-vehicles-motion-enabled');
  requestAnimationFrame(() => requestAnimationFrame(() => {
    document.body.classList.add('fleet-vehicles-motion-ready');
  }));

  document.querySelectorAll('.fleet-vehicles-page-shell tbody tr').forEach((row) => {
    row.addEventListener('pointerdown', () => row.classList.add('is-pressed'));
    ['pointerup', 'pointercancel', 'pointerleave'].forEach((eventName) => {
      row.addEventListener(eventName, () => row.classList.remove('is-pressed'));
    });
  });

  document.querySelectorAll('.fleet-vehicles-page-shell form:not(#vehicle-registration-form), .fleet-vehicles-module .tc-modal form').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (event.defaultPrevented || form.id === 'vehicle-registration-form') return;
      requestAnimationFrame(() => {
        if (event.defaultPrevented) return;
        const button = event.submitter || form.querySelector('button[type="submit"]');
        if (!button || button.classList.contains('va-unassign')) return;
        button.dataset.fvOriginalHtml = button.innerHTML;
        button.classList.add('is-loading');
        button.setAttribute('aria-busy', 'true');
        const label = button.textContent.trim();
        button.innerHTML = `<i class="bi bi-arrow-repeat" aria-hidden="true"></i><span>${label}</span>`;
      });
    });
  });

  const assignmentForm = document.getElementById('va-form');
  const selection = document.querySelector('.va-selection');
  if (assignmentForm && selection) {
    assignmentForm.addEventListener('change', (event) => {
      if (!event.target.matches('[name="vehicle_id"], #va-driver')) return;
      selection.classList.add('is-updating');
      requestAnimationFrame(() => requestAnimationFrame(() => selection.classList.remove('is-updating')));
    });
  }

  window.addEventListener('pageshow', () => {
    document.querySelectorAll('.fleet-vehicles-module .tc-btn.is-loading').forEach((button) => {
      if (button.dataset.fvOriginalHtml) button.innerHTML = button.dataset.fvOriginalHtml;
      delete button.dataset.fvOriginalHtml;
      button.classList.remove('is-loading');
      button.removeAttribute('aria-busy');
    });
  });
})();
