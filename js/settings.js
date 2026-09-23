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
