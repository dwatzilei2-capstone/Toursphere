(() => {
  const form = document.getElementById('va-form');
  if (!form) return;
  const driver = document.getElementById('va-driver');
  const notice = document.getElementById('va-reassignment');
  const search = document.getElementById('va-search');
  const statusFilter = document.getElementById('va-status-filter');
  const picker = document.getElementById('va-driver-picker');
  const unassignModal = document.getElementById('modal-unassign-confirm');
  const unassignMessage = document.getElementById('unassign-confirm-message');
  const unassignCancel = document.getElementById('unassign-confirm-cancel');
  const unassignConfirm = document.getElementById('unassign-confirm-submit');
  const trigger = picker.querySelector('.va-driver-trigger');
  const menu = picker.querySelector('.va-driver-menu');
  const rows = Array.from(form.querySelectorAll('.va-vehicle-row'));
  let pendingUnassignButton = null;
  let confirmedUnassign = false;

  function closeUnassignModal() {
    App.closeModal('modal-unassign-confirm');
    const button = pendingUnassignButton;
    pendingUnassignButton = null;
    button?.focus();
  }

  unassignCancel.addEventListener('click', closeUnassignModal);
  unassignModal.addEventListener('click', (event) => {
    if (event.target === unassignModal) closeUnassignModal();
  });
  unassignModal.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      event.preventDefault();
      closeUnassignModal();
      return;
    }
    if (event.key === 'Tab') {
      const focusable = [unassignCancel, unassignConfirm];
      const currentIndex = focusable.indexOf(document.activeElement);
      if (event.shiftKey && currentIndex === 0) {
        event.preventDefault();
        unassignConfirm.focus();
      } else if (!event.shiftKey && currentIndex === focusable.length - 1) {
        event.preventDefault();
        unassignCancel.focus();
      }
    }
  });
  unassignConfirm.addEventListener('click', () => {
    if (!pendingUnassignButton?.isConnected) return closeUnassignModal();
    confirmedUnassign = true;
    App.closeModal('modal-unassign-confirm');
    form.requestSubmit(pendingUnassignButton);
  });
  function filterVehicles() {
    const query = search.value.trim().toLocaleLowerCase();
    let visible = 0;
    rows.forEach((row) => {
      row.hidden = !row.textContent.toLocaleLowerCase().includes(query) ||
        (statusFilter.value !== '' && row.dataset.status !== statusFilter.value);
      if (!row.hidden) visible++;
    });
    document.getElementById('va-result-count').textContent = `${visible} of ${rows.length} vehicles`;
    document.getElementById('va-no-results').hidden = visible !== 0 || rows.length === 0;
  }
  search.addEventListener('input', filterVehicles);
  search.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') event.preventDefault();
  });
  document.getElementById('va-search-button').addEventListener('click', () => {
    filterVehicles();
    search.focus();
  });
  document.getElementById('va-clear-filters').addEventListener('click', () => {
    search.value = '';
    statusFilter.value = '';
    filterVehicles();
    search.focus();
  });
  statusFilter.addEventListener('change', filterVehicles);
  let selectedVehicle = form.querySelector('[name="vehicle_id"]:checked');
  const openMenu = () => { menu.hidden = false; trigger.setAttribute('aria-expanded', 'true'); };
  trigger.addEventListener('focus', openMenu);
  trigger.addEventListener('input', () => {
    const query = trigger.value.trim().toLocaleLowerCase();
    menu.querySelectorAll('[role="option"]').forEach((option) => { option.hidden = !option.textContent.toLocaleLowerCase().includes(query); });
    openMenu();
  });
  menu.querySelectorAll('[role="option"]').forEach((option) => option.addEventListener('click', () => {
    driver.value = option.dataset.value;
    trigger.value = option.textContent.trim();
    menu.hidden = true;
    trigger.setAttribute('aria-expanded', 'false');
    driver.dispatchEvent(new Event('change', { bubbles: true }));
  }));
  document.addEventListener('click', (event) => {
    if (!picker.contains(event.target)) { menu.hidden = true; trigger.setAttribute('aria-expanded', 'false'); }
  });
  function update() {
    const vehicle = form.querySelector('[name="vehicle_id"]:checked');
    selectedVehicle = vehicle;
    const option = driver.selectedOptions[0];
    document.getElementById('va-selected-plate').textContent = vehicle ? vehicle.dataset.plate : 'No vehicle selected';
    document.getElementById('va-selected-detail').textContent = vehicle
      ? `${vehicle.dataset.model} · ${vehicle.dataset.capacity} passengers`
      : 'Choose a vehicle from the list.';
    document.getElementById('va-driver-details').hidden = !driver.value;
    document.getElementById('va-license').textContent = option.dataset.license || '—';
    document.getElementById('va-score').textContent = option.dataset.score || '—';
    const warnings = [];
    if (vehicle && vehicle.dataset.currentDriver) warnings.push(`This vehicle is currently assigned to ${vehicle.dataset.currentDriver}. Saving will replace its driver assignment.`);
    if (driver.value && option.dataset.status === 'Assigned') warnings.push('This driver is currently assigned to another vehicle. Unassign that vehicle first before continuing.');
    notice.textContent = warnings.join(' ');
    notice.hidden = warnings.length === 0;
  }
  form.addEventListener('click', (event) => {
    const input = event.target;
    if (!input.matches('input[type="radio"][name="vehicle_id"]')) return;
    // Native radios select before click fires; remember the previous selection
    // so a second activation (including the label or keyboard) can clear it.
    if (input === selectedVehicle) {
      input.checked = false;
      input.dispatchEvent(new Event('change', { bubbles: true }));
    } else {
      update();
    }
  });
  form.addEventListener('change', update);
  form.addEventListener('submit', (event) => {
    if (event.submitter?.classList.contains('va-unassign')) {
      if (!confirmedUnassign) {
        event.preventDefault();
        pendingUnassignButton = event.submitter;
        unassignMessage.textContent = event.submitter.dataset.confirm || 'Unassign this driver?';
        App.openModal('modal-unassign-confirm');
        unassignCancel.focus();
        return;
      }
      confirmedUnassign = false;
      pendingUnassignButton = null;
      return;
    }
    const button = form.querySelector('.va-submit');
    button.disabled = true;
    button.textContent = 'Saving assignment…';
  });
  window.addEventListener('pageshow', () => {
    const button = form.querySelector('.va-submit');
    button.disabled = !form.querySelector('[name="vehicle_id"]') || driver.options.length < 2;
    button.innerHTML = 'Save assignment <i class="bi bi-arrow-right" aria-hidden="true"></i>';
    update();
    filterVehicles();
  });
  update();
  filterVehicles();
})();
