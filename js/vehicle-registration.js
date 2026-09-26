document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('vehicle-registration-form');
  if (!form) return;

  const fileInput = document.getElementById('vehicle-document');
  const dropZone = document.getElementById('vehicle-document-drop');
  const workflow = document.getElementById('vehicle-registration-workflow');
  const preview = document.getElementById('vehicle-document-preview');
  const previewImage = document.getElementById('vehicle-preview-image');
  const previewPdf = document.getElementById('vehicle-preview-pdf');
  const filename = document.getElementById('vehicle-document-name');
  const type = document.getElementById('vehicle-type');
  const brand = document.getElementById('vehicle-brand');
  const model = document.getElementById('vehicle-model');
  const variant = document.getElementById('vehicle-variant');
  const specs = document.getElementById('vehicle-specifications');
  const submit = document.getElementById('vehicle-register-submit');
  const status = document.getElementById('vehicle-form-status');
  let previewUrl = null;

  const setOptions = (select, items, prompt) => {
    select.innerHTML = `<option value="">${prompt}</option>` + items.map(item =>
      `<option value="${App.escapeHtml(item.id)}">${App.escapeHtml(item.name)}</option>`
    ).join('');
    select.disabled = false;
  };

  const clearSelect = (select, prompt) => {
    setOptions(select, [], prompt);
    select.disabled = true;
  };

  const loadItems = async (url, select, prompt) => {
    select.disabled = true;
    select.innerHTML = '<option value="">Loading...</option>';
    status.textContent = '';
    try {
      const response = await fetch(url, { headers: { Accept: 'application/json' } });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || 'Unable to load options.');
      select._items = data.items;
      setOptions(select, data.items, prompt);
      if (!data.items.length) status.textContent = 'No active catalog entries are available for that selection.';
    } catch (error) {
      clearSelect(select, prompt);
      status.textContent = error.message;
    }
  };

  const validate = () => {
    submit.disabled = !form.checkValidity() || !fileInput.files.length || !variant.value;
  };

  const showDocument = (file) => {
    if (!file) return;
    if (previewUrl) URL.revokeObjectURL(previewUrl);
    previewUrl = URL.createObjectURL(file);
    filename.textContent = `${file.name} (${(file.size / 1024 / 1024).toFixed(2)} MB)`;
    preview.hidden = false;
    previewImage.hidden = true;
    previewPdf.hidden = true;
    if (file.type === 'application/pdf') {
      previewPdf.src = previewUrl;
      previewPdf.hidden = false;
    } else {
      previewImage.src = previewUrl;
      previewImage.hidden = false;
    }
    workflow.hidden = false;
    status.textContent = 'Plate number could not be detected automatically. Please encode it while reviewing the uploaded document.';
    validate();
  };

  fileInput.addEventListener('change', () => showDocument(fileInput.files[0]));
  dropZone.addEventListener('click', () => fileInput.click());
  dropZone.addEventListener('keydown', event => {
    if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); fileInput.click(); }
  });
  ['dragenter', 'dragover'].forEach(name => dropZone.addEventListener(name, event => {
    event.preventDefault(); dropZone.classList.add('is-dragging');
  }));
  ['dragleave', 'drop'].forEach(name => dropZone.addEventListener(name, event => {
    event.preventDefault(); dropZone.classList.remove('is-dragging');
  }));
  dropZone.addEventListener('drop', event => {
    if (!event.dataTransfer.files.length) return;
    const transfer = new DataTransfer();
    transfer.items.add(event.dataTransfer.files[0]);
    fileInput.files = transfer.files;
    showDocument(fileInput.files[0]);
  });

  type.addEventListener('change', () => {
    clearSelect(brand, 'Select brand');
    clearSelect(model, 'Select model');
    clearSelect(variant, 'Select variant');
    specs.hidden = true;
    if (type.value) loadItems(`${window.TC_BASE_URL}/actions/vehicle-catalog.php?resource=brands&parent_id=${encodeURIComponent(type.value)}`, brand, 'Select brand');
    validate();
  });
  brand.addEventListener('change', () => {
    clearSelect(model, 'Select model');
    clearSelect(variant, 'Select variant');
    specs.hidden = true;
    if (brand.value) loadItems(`${window.TC_BASE_URL}/actions/vehicle-catalog.php?resource=models&parent_id=${encodeURIComponent(brand.value)}&type_id=${encodeURIComponent(type.value)}`, model, 'Select model');
    validate();
  });
  model.addEventListener('change', () => {
    clearSelect(variant, 'Select variant');
    specs.hidden = true;
    if (model.value) loadItems(`${window.TC_BASE_URL}/actions/vehicle-catalog.php?resource=variants&parent_id=${encodeURIComponent(model.value)}`, variant, 'Select variant');
    validate();
  });
  variant.addEventListener('change', () => {
    const option = variant.selectedOptions[0];
    if (!option?.value) { specs.hidden = true; validate(); return; }
    const item = variant._items?.find(entry => String(entry.id) === option.value);
    if (item) {
      form.elements.year.value = item.model_year || '';
      form.elements.capacity.value = item.passenger_capacity || '';
      form.elements.fuel_capacity.value = item.fuel_tank_capacity || '';
      form.elements.fuel_type.value = item.fuel_type || '';
      specs.hidden = false;
    }
    validate();
  });

  form.addEventListener('input', validate);
  form.addEventListener('change', validate);
  form.addEventListener('submit', event => {
    if (!form.checkValidity() || !variant.value) {
      event.preventDefault();
      form.reportValidity();
      status.textContent = 'Complete all required fields before registering the vehicle.';
      return;
    }
    submit.disabled = true;
    submit.textContent = 'Registering...';
  });
});
