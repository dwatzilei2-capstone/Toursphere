(() => {
  'use strict';
  const el = id => document.getElementById(id);
  const form = el('archive-action-form');
  if (!form) return;
  const endpoint = form.getAttribute('action');
  const modal = new bootstrap.Modal(el('archive-action-modal'));
  const fields = el('archive-retirement-fields'), button = el('archive-confirm');
  let vehicle = null, stage = 0, busy = false, generation = 0, lastTrigger = null;
  const error = message => { el('archive-action-error').textContent = message; };
  const reason = el('archive-reason');
  reason.addEventListener('change', () => {
    const other = reason.value === 'Other';
    el('archive-other').hidden = !other;
    el('archive-explanation').required = other;
  });
  function retirementForm() {
    stage = 0; fields.hidden = false; reason.required = true;
    el('archive-back').hidden = true;
    el('archive-action-title').textContent = 'Retire Vehicle';
    el('archive-action-copy').textContent = `Vehicle: ${vehicle.id}\nPlate Number: ${vehicle.plate_number}\nCurrent Status: ${vehicle.status}`;
    button.textContent = 'Continue';
  }
  el('archive-back').addEventListener('click', retirementForm);
  el('archive-action-modal').addEventListener('hidden.bs.modal', () => { generation++; if(lastTrigger?.isConnected) lastTrigger.focus(); });
  el('archive-action-modal').addEventListener('hide.bs.modal', event => { if(busy) event.preventDefault(); });
  document.addEventListener('click', async event => {
    const trigger = event.target.closest('[data-archive-trip], [data-retire-vehicle]');
    if (!trigger || busy) return;
    lastTrigger = trigger;
    event.preventDefault(); event.stopPropagation();
    form.reset(); error(''); stage = 0; vehicle = null; fields.hidden = true;
    reason.required = false; el('archive-explanation').required = false; el('archive-other').hidden = true;
    el('archive-back').hidden = true; button.disabled = false;
    const request = ++generation;
    if (trigger.hasAttribute('data-archive-trip')) {
      form.elements.action.value = 'trip'; form.elements.id.value = trigger.dataset.archiveTrip;
      el('archive-action-title').textContent = 'Archive Trip?';
      el('archive-action-copy').textContent = `You are about to archive ${trigger.dataset.archiveTrip}.\nStatus: ${trigger.dataset.status}\n\nThis trip will be removed from the active Trip Board but its information and historical records will remain available in the Archive.`;
      button.textContent = 'Confirm Archive'; modal.show();
    } else {
      form.elements.action.value = 'retire'; form.elements.id.value = trigger.dataset.retireVehicle;
      el('archive-action-title').textContent = 'Retire Vehicle';
      el('archive-action-copy').textContent = 'Analyzing vehicle records…'; button.disabled = true; modal.show();
      try {
        const response = await fetch(`${endpoint}?action=retirement-preview&id=${encodeURIComponent(trigger.dataset.retireVehicle)}`, {headers: {'Accept':'application/json'}});
        const data = await response.json();
        if (request !== generation) return;
        if (!response.ok) throw new Error(data.error || 'Unable to analyze this vehicle.');
        vehicle = data.vehicle;
        el('archive-recommendation').textContent = data.recommendation.reason || 'No recommendation available';
        el('archive-basis').textContent = data.recommendation.basis;
        retirementForm(); button.disabled = false;
      } catch (err) { if (request === generation) error(err.message); }
    }
  });
  form.addEventListener('submit', async event => {
    event.preventDefault(); if (busy) return; error('');
    if (form.elements.action.value === 'retire' && stage === 0) {
      if (!vehicle || !form.reportValidity()) return;
      stage = 1; fields.hidden = true; el('archive-back').hidden = false;
      el('archive-action-title').textContent = 'Confirm Vehicle Retirement?';
      el('archive-action-copy').textContent = `You are about to permanently retire ${vehicle.id} from active fleet operations.\n\nRetirement Reason: ${reason.value}${reason.value === 'Other' ? ' — '+el('archive-explanation').value : ''}\n\nThis vehicle will no longer be available for future assignment or dispatch. Existing information and historical records will remain available in Archive.`;
      button.textContent = 'Confirm Retirement'; button.focus(); return;
    }
    busy = true; button.disabled = true; el('archive-back').disabled = true;
    const label = button.textContent; button.textContent = 'Saving…';
    try {
      const response = await fetch(endpoint, {method:'POST',body:new FormData(form),headers:{'Accept':'application/json'}});
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || 'Unable to save this decision.');
      window.location.reload();
    } catch (err) { error(err.message); }
    finally { busy = false; button.disabled = false; el('archive-back').disabled = false; button.textContent = label; }
  });
})();
