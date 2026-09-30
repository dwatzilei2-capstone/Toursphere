document.querySelectorAll('form.settings-card').forEach((form) => {
  const fields = form.querySelector('.settings-fields');
  const edit = form.querySelector('[data-settings-edit]');
  const cancel = form.querySelector('[data-settings-cancel]');
  const save = form.querySelector('[data-settings-save]');
  if (!fields || !edit || !cancel || !save) return;
  const savedValues = JSON.parse(fields.dataset.savedValues);
  const logo = form.querySelector('#company-logo-preview');
  const logoHelp = form.querySelector('#logo-help');
  const savedLogo = logo?.src;
  const savedLogoHelp = logoHelp?.textContent;

  const setEditing = (editing) => {
    fields.disabled = !editing;
    fields.dataset.editing = String(editing);
    edit.hidden = editing;
    edit.setAttribute('aria-expanded', String(editing));
    cancel.hidden = !editing;
    save.hidden = !editing;
    save.disabled = !editing;
  };
  edit.addEventListener('click', () => {
    setEditing(true);
    fields.querySelector('input:not([type="hidden"]):not([type="file"]), select, textarea')?.focus();
  });
  cancel.addEventListener('click', () => {
    form.reset();
    fields.querySelectorAll('input, select, textarea').forEach((control) => {
      control.setCustomValidity('');
      if (control.type === 'file') { control.value = ''; return; }
      if (!(control.name in savedValues)) return;
      if (control.type === 'radio') control.checked = control.value === savedValues[control.name];
      else control.value = savedValues[control.name];
    });
    if (logo) {
      logo.src = savedLogo;
      logoHelp.textContent = savedLogoHelp;
      if (logoPreviewUrl) { URL.revokeObjectURL(logoPreviewUrl); logoPreviewUrl = null; }
    }
    setEditing(false);
    edit.focus();
  });
  form.addEventListener('submit', (event) => {
    if (fields.disabled) event.preventDefault();
  });
});

const logoInput = document.getElementById('company-logo');
let logoPreviewUrl;
logoInput?.addEventListener('change', () => {
  const file = logoInput.files[0];
  logoInput.setCustomValidity('');
  if (!file) return;
  if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024) {
    logoInput.setCustomValidity('Choose a PNG, JPG or WebP image smaller than 2 MB.');
    logoInput.reportValidity(); return;
  }
  if (logoPreviewUrl) URL.revokeObjectURL(logoPreviewUrl);
  logoPreviewUrl = URL.createObjectURL(file);
  document.getElementById('company-logo-preview').src = logoPreviewUrl;
  document.getElementById('logo-help').textContent = file.name + ' · Save company profile to apply.';
});

const scheduleEditor = document.getElementById('schedule-editor');
if (scheduleEditor) {
  const action = document.getElementById('schedule-action');
  const id = document.getElementById('schedule-id');
  const name = document.getElementById('schedule-name');
  const time = document.getElementById('schedule-time');
  const title = document.getElementById('schedule-editor-title');
  const save = document.getElementById('schedule-save');
  const cancel = document.getElementById('schedule-cancel-edit');
  const resetEditor = () => {
    scheduleEditor.reset(); action.value = 'create'; id.value = '';
    title.textContent = 'Add operating schedule'; save.textContent = 'Add schedule'; cancel.hidden = true;
  };
  document.querySelectorAll('.schedule-edit').forEach((button) => button.addEventListener('click', () => {
    resetEditor(); action.value = 'update'; id.value = button.dataset.id;
    name.value = button.dataset.name; time.value = button.dataset.time.slice(0, 5);
    const days = JSON.parse(button.dataset.days);
    days.forEach((day) => { const checkbox = scheduleEditor.querySelector(`[name="operating_days[]"][value="${day}"]`); if (checkbox) checkbox.checked = true; });
    title.textContent = 'Edit operating schedule'; save.textContent = 'Save schedule'; cancel.hidden = false;
    scheduleEditor.scrollIntoView({ behavior: 'smooth', block: 'start' }); name.focus();
  }));
  cancel.addEventListener('click', resetEditor);
  scheduleEditor.addEventListener('submit', (event) => {
    if (!scheduleEditor.querySelector('[name="operating_days[]"]:checked')) {
      event.preventDefault();
      const first = scheduleEditor.querySelector('[name="operating_days[]"]');
      first.setCustomValidity('Select at least one operating day.'); first.reportValidity();
      first.addEventListener('change', () => first.setCustomValidity(''), { once: true });
    }
  });
}
