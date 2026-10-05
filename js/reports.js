(() => {
  'use strict';
  const period = document.getElementById('report-period');
  const custom = document.getElementById('report-custom');
  function updateDates() { if (!custom) return; const active = period.value === 'custom'; custom.hidden = !active; custom.querySelectorAll('input').forEach(input => { input.required = active; input.disabled = !active; }); }
  period?.addEventListener('change', updateDates); updateDates();
  let busy = false;
  const status = document.getElementById('report-status');
  function message(text) { if (status) { status.textContent = text; status.hidden = false; } }
  document.querySelectorAll('.report-generate').forEach(link => link.addEventListener('click', event => {
    if (busy) { event.preventDefault(); return; }
    busy = true; link.setAttribute('aria-disabled', 'true'); message('Generating report…');
  }));
  document.querySelectorAll('.report-download').forEach(link => link.addEventListener('click', async event => {
    event.preventDefault(); if (busy) return; busy = true;
    const links = document.querySelectorAll('.report-download'); links.forEach(item => item.setAttribute('aria-disabled', 'true'));
    bootstrap.Dropdown.getInstance(link.closest('.dropdown').querySelector('[data-bs-toggle]'))?.hide();
    message('Generating report…');
    try {
      const response = await fetch(link.href, { credentials: 'same-origin' });
      if (!response.ok || !response.headers.get('Content-Disposition')) throw new Error('Unable to generate the report. Please try again.');
      const blob = await response.blob(); const url = URL.createObjectURL(blob);
      const anchor = document.createElement('a'); anchor.href = url;
      anchor.download = response.headers.get('Content-Disposition').match(/filename="([^"]+)"/)?.[1] || 'TourSphere_Report';
      document.body.appendChild(anchor); anchor.click(); anchor.remove(); setTimeout(() => URL.revokeObjectURL(url), 30000);
      message('Report downloaded.'); setTimeout(() => { status.hidden = true; }, 5000);
    } catch (error) { message(error.message); }
    finally { busy = false; links.forEach(item => item.removeAttribute('aria-disabled')); }
  }));
  window.addEventListener('pageshow', () => { busy = false; document.querySelectorAll('.report-generate').forEach(link => link.removeAttribute('aria-disabled')); if (status) status.hidden = true; });
})();
