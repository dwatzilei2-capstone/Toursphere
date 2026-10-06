(() => {
  'use strict';
  const modal = document.getElementById('fuel-receipt-modal');
  if (!modal) return;
  let controller;
  modal.addEventListener('hidden.bs.modal', () => {
    controller?.abort();
    document.getElementById('fuel-receipt-preview').replaceChildren();
  });
  document.addEventListener('click', async event => {
    const button = event.target.closest('[data-fuel-receipt]');
    if (!button) return;
    controller?.abort();controller = new AbortController();
    const status = document.getElementById('fuel-receipt-status');
    const content = document.getElementById('fuel-receipt-content');
    const preview = document.getElementById('fuel-receipt-preview');
    content.hidden = true;preview.replaceChildren();status.textContent = 'Loading receipt…';
    bootstrap.Modal.getOrCreateInstance(modal).show();
    const url = `${window.TC_BASE_URL}/actions/fuel-receipt.php?id=${encodeURIComponent(button.dataset.fuelReceipt)}`;
    try {
      const response = await fetch(url + '&info=1', { signal: controller.signal });
      if (!response.ok) throw new Error('The receipt is unavailable or you do not have access.');
      const data = await response.json();
      const documentView = document.createElement(data.mime === 'application/pdf' ? 'iframe' : 'img');
      documentView.src = url;documentView.style.cssText = 'width:100%;height:390px;object-fit:contain;border:1px solid var(--tc-border,#EEF2F7);border-radius:6px';
      if (data.mime === 'application/pdf') documentView.title = 'Uploaded fuel receipt PDF';
      else documentView.alt = 'Uploaded receipt for ' + data.id;
      preview.append(documentView);
      const info = document.getElementById('fuel-receipt-info');info.replaceChildren();
      for (const [label,key] of [['Log ID','id'],['Vehicle','vehicle'],['Trip','trip'],['Date & Time','date'],['Fuel Type','fuel_type'],['Liters','liters'],['Price per Liter','price'],['Total Spend','total'],['Driver','driver'],['Efficiency','efficiency'],['Station','station']]) {
        const row = document.createElement('div');row.className = 'd-flex justify-content-between gap-2 border-bottom py-2';
        const term = document.createElement('dt');term.className = 'fw-normal text-muted-custom';term.textContent = label;
        const value = document.createElement('dd');value.className = 'mb-0 text-end';value.textContent = data[key] || '—';
        row.append(term,value);info.append(row);
      }
      document.getElementById('fuel-receipt-download').href = url + '&download=1';
      content.hidden = false;status.textContent = '';
    } catch (error) { if (error.name !== 'AbortError') status.textContent = error.message; }
  });
})();
