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
  const variantField = document.getElementById('vehicle-variant-field');
  const year = document.getElementById('vehicle-year');
  const plate = document.getElementById('vehicle-plate-number');
  const specs = document.getElementById('vehicle-specifications');
  const submit = document.getElementById('vehicle-register-submit');
  const status = document.getElementById('vehicle-form-status');
  const documentValidationWarning = document.getElementById('vehicle-document-validation-warning');
  const uploadStatus = document.getElementById('vehicle-upload-status');
  const extractionReview = document.getElementById('vehicle-extraction-review');
  const insuranceInput = document.getElementById('vehicle-insurance-document');
  const insuranceStatus = document.getElementById('vehicle-insurance-status');
  const insuranceReview = document.getElementById('vehicle-insurance-review');
  const insuranceReviewLabel = document.getElementById('vehicle-insurance-review-label');
  const insuranceManualPlate = form.elements.insurance_manual_vehicle_plate;
  const ltfrbInput = document.getElementById('vehicle-ltfrb-document');
  const ltfrbStatus = document.getElementById('vehicle-ltfrb-status');
  const ltfrbReview = document.getElementById('vehicle-ltfrb-review');
  const ltfrbReviewLabel = document.getElementById('vehicle-ltfrb-review-label');
  const ltfrbManualPlate = form.elements.ltfrb_manual_vehicle_plate;
  const plateReview = document.getElementById('vehicle-plate-review');
  const registrationReview = document.getElementById('vehicle-registration-review');
  const registrationReviewLabel = document.getElementById('vehicle-registration-review-label');
  let detectedPlate = '';
  let registrationTypeMismatch = false;
  let registrationTypeStatus = 'NEEDS_REVIEW';
  let registrationTypeReason = '';
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

  const documentBlockers = () => {
    const blockers = [];
    if (registrationTypeMismatch) {
      blockers.push(`Registration: ${uploadStatus.textContent || 'This is not a verified Registration Document.'}`);
    } else if (fileInput.files.length && registrationTypeStatus !== 'MATCHED') {
      blockers.push(`Registration document type needs review: ${registrationTypeReason || 'TourSphere could not confirm that this is a CR/OR-CR document.'} Upload a clearer searchable registration document.`);
    }
    for (const [input, label, statusNode] of [
      [insuranceInput, 'Insurance', insuranceStatus],
      [ltfrbInput, 'LTFRB Permit', ltfrbStatus],
    ]) {
      if (!input.files.length) continue;
      const fileSignature = `${input.files[0].name}:${input.files[0].size}:${input.files[0].lastModified}`;
      if (input.dataset.reviewedSignature !== fileSignature) {
        blockers.push(`${label}: document check is pending.`);
      } else if (input.dataset.typeStatus !== 'MATCHED') {
        blockers.push(`${label}: ${statusNode.textContent || 'document type is not verified.'}`);
      } else if (input.dataset.matchStatus === 'MISMATCHED') {
        blockers.push(`${label}: document does not match this vehicle.`);
      }
    }
    return blockers;
  };

  const validate = () => {
    const optionalTypeUnverified = [insuranceInput, ltfrbInput].some(input =>
      input.files[0] && input.dataset.typeStatus !== 'MATCHED'
    );
    const blockers = documentBlockers();
    documentValidationWarning.hidden = blockers.length === 0;
    documentValidationWarning.textContent = blockers.length
      ? `Vehicle registration is blocked. ${blockers.join(' ')} Resolve or replace the document before registering.`
      : '';
    submit.disabled = registrationTypeMismatch || optionalTypeUnverified || blockers.length > 0
      || !form.checkValidity() || !fileInput.files.length;
    submit.title = blockers.length ? 'Resolve the document warning to enable registration.' : '';
  };

  const analyzeOptionalDocument = async (input, type, statusNode, review, reviewLabel) => {
    const file = input.files[0];
    const analysisToken = `${Date.now()}:${Math.random()}`;
    input.dataset.analysisToken = analysisToken;
    review.checked = false;
    review.required = false;
    const manualPlate = type === 'insurance' ? insuranceManualPlate : ltfrbManualPlate;
    manualPlate.required = false;
    manualPlate.value = '';
    reviewLabel.hidden = true;
    input.dataset.reviewedSignature = '';
    input.dataset.matchStatus = '';
    input.dataset.typeStatus = '';
    validate();
    if (!file) {
      statusNode.textContent = 'Upload if available; you can add it later.';
      validate();
      return;
    }

    statusNode.textContent = `Analyzing ${file.name}...`;
    const payload = new FormData();
    payload.append('document', file);
    payload.append('document_type', type);
    payload.append('csrf_token', form.elements.csrf_token.value);
    if (fileInput.files[0]) payload.append('registration_document', fileInput.files[0]);
    payload.append('plate_number', plate.value);
    try {
      const response = await fetch(`${window.TC_BASE_URL}/actions/vehicle-document-analyze.php`, {
        method: 'POST', body: payload, headers: { Accept: 'application/json' }
      });
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.error || 'Document analysis failed.');
      if (input.dataset.analysisToken !== analysisToken || input.files[0] !== file) return;
      const fields = Object.entries(result.document_data || {}).map(([key, value]) => `${key.replaceAll('_', ' ')}: ${value}`).join(' · ');
      const state = result.extraction_status === 'extracted' ? 'Extracted successfully' :
        result.extraction_status === 'needs_review' ? 'Needs review' : 'Not detected';
      const match = result.vehicle_match;
      const typeVerification = result.document_type_verification;
      input.dataset.matchStatus = match?.status || 'NEEDS_REVIEW';
      input.dataset.typeStatus = typeVerification?.status || 'NEEDS_REVIEW';
      let overallResult;
      if (typeVerification?.status === 'MISMATCHED') {
        overallResult = `REJECTED — Wrong document type. ${typeVerification.reason}`;
      } else if (typeVerification?.status !== 'MATCHED') {
        overallResult = `NEEDS REVIEW — Document type is not verified. ${typeVerification?.reason || 'Upload a clearer searchable document.'}`;
      } else if (match?.status === 'MISMATCHED') {
        overallResult = `REJECTED — Document belongs to another vehicle. ${match.reason}`;
      } else if (match?.status !== 'MATCHED') {
        overallResult = `NEEDS REVIEW — Vehicle identity is not verified. ${match?.reason || 'Upload a clearer document or review its identifiers.'}`;
      } else {
        overallResult = 'CHECKS PASSED — Document type and vehicle identity are verified.';
      }
      const statusLines = [
        `Overall result: ${overallResult}`,
        `Document type: ${typeVerification?.status || 'NEEDS_REVIEW'} — ${typeVerification?.reason || 'Document type could not be checked.'}`,
        `Vehicle match: ${match?.status || 'NEEDS_REVIEW'} — ${match?.reason || 'Vehicle match could not be checked.'}`,
        `Document extraction: ${state}.`,
        `Extracted fields: ${fields || 'No reliable vehicle identifier was detected.'}`,
      ];
      statusNode.innerHTML = statusLines.map(line => App.escapeHtml(line)).join('<br>');
      if (typeVerification?.status === 'MATCHED' && match?.status !== 'MISMATCHED'
        && (match?.status !== 'MATCHED' || result.extraction_status !== 'extracted')) {
        reviewLabel.hidden = false;
        review.required = true;
        manualPlate.required = match?.status !== 'MATCHED';
      }
      input.dataset.reviewedSignature = `${file.name}:${file.size}:${file.lastModified}`;
    } catch (error) {
      if (input.dataset.analysisToken !== analysisToken || input.files[0] !== file) return;
      statusNode.textContent = error.message;
    }
    validate();
  };

  const reanalyzeUploadedSupportingDocuments = async () => {
    const checks = [];
    if (insuranceInput.files[0]) {
      checks.push(analyzeOptionalDocument(
        insuranceInput, 'insurance', insuranceStatus, insuranceReview, insuranceReviewLabel
      ));
    }
    if (ltfrbInput.files[0]) {
      checks.push(analyzeOptionalDocument(
        ltfrbInput, 'ltfrb_permit', ltfrbStatus, ltfrbReview, ltfrbReviewLabel
      ));
    }
    await Promise.all(checks);
  };

  const clearSpecifications = () => {
    form.elements.capacity.value = '';
    form.elements.fuel_type.value = '';
  };

  const loadVariants = async () => {
    clearSelect(variant, 'Not specified');
    variantField.hidden = true;
    if (!model.value || !year.value) return;
    await loadItems(`${window.TC_BASE_URL}/actions/vehicle-catalog.php?resource=variants&parent_id=${encodeURIComponent(model.value)}&year=${encodeURIComponent(year.value)}`, variant, 'Not specified');
    if (variant._items?.length) {
      variantField.hidden = false;
    } else {
      variant.disabled = true;
    }
  };

  const applyExtractedVehicleData = async (data) => {
    const documentData = data.document_data || data;
    const yearModel = documentData.year_model || data.year_model;
    if (yearModel) year.value = yearModel;
    if (Number.isInteger(Number(documentData.passenger_capacity))
      && Number(documentData.passenger_capacity) >= 1 && Number(documentData.passenger_capacity) <= 100) {
      form.elements.capacity.value = String(documentData.passenger_capacity);
    }
    const allowedFuelTypes = ['Diesel', 'Gasoline', 'Hybrid', 'Electric'];
    const extractedFuelType = allowedFuelTypes.find(value => value === documentData.fuel_type);
    if (extractedFuelType) form.elements.fuel_type.value = extractedFuelType;
    const match = data.catalog_match;
    const partial = data.partial_match || {};
    const typeId = match?.vehicle_type_id || partial.vehicle_type_id;
    if (!typeId) return;
    type.value = String(typeId);
    await loadItems(`${window.TC_BASE_URL}/actions/vehicle-catalog.php?resource=brands&parent_id=${encodeURIComponent(type.value)}`, brand, 'Select brand');
    const brandId = match?.brand_id || partial.brand_id;
    if (!brandId || !brand.querySelector(`option[value="${CSS.escape(String(brandId))}"]`)) return;
    brand.value = String(brandId);
    await loadItems(`${window.TC_BASE_URL}/actions/vehicle-catalog.php?resource=models&parent_id=${encodeURIComponent(brand.value)}&type_id=${encodeURIComponent(type.value)}`, model, 'Select model');
    if (!match?.model_id) return;
    model.value = String(match.model_id);
    const matchedModelOption = model.selectedOptions[0];
    if (matchedModelOption && documentData.series_model) {
      matchedModelOption.textContent = String(documentData.series_model);
    }
    specs.hidden = false;
    await loadVariants();
  };

  const showDocument = async (file) => {
    if (!file) return;
    workflow.hidden = true;
    preview.hidden = true;
    extractionReview.hidden = true;
    extractionReview.textContent = '';
    registrationReview.checked = false;
    registrationReview.required = false;
    registrationReviewLabel.hidden = true;
    detectedPlate = '';
    registrationTypeMismatch = false;
    registrationTypeStatus = 'NEEDS_REVIEW';
    registrationTypeReason = '';
    plate.value = '';
    submit.disabled = true;
    uploadStatus.textContent = 'Extracting vehicle information from the document...';
    dropZone.setAttribute('aria-busy', 'true');

    const payload = new FormData();
    payload.append('registration_document', file);
    payload.append('csrf_token', form.elements.csrf_token.value);
    let extractionStatus = 'needs_review';
    try {
      const response = await fetch(`${window.TC_BASE_URL}/actions/vehicle-document-analyze.php`, {
        method: 'POST', body: payload, headers: { Accept: 'application/json' }
      });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || 'Document extraction failed.');
      extractionStatus = data.extraction_status || 'needs_review';
      const typeVerification = data.document_type_verification;
      registrationTypeStatus = typeVerification?.status || 'NEEDS_REVIEW';
      registrationTypeReason = typeVerification?.reason || 'TourSphere could not confirm that this is a CR/OR-CR document.';
      if (typeVerification?.status === 'MISMATCHED') {
        registrationTypeMismatch = true;
        fileInput.value = '';
        dropZone.hidden = false;
        workflow.hidden = true;
        uploadStatus.textContent = `${typeVerification.reason} Upload the Vehicle Registration Document instead.`;
        validate();
        return;
      }
      if (!data.plate_number) throw new Error('Plate number could not be detected from the uploaded document. Upload a clearer copy.');
      plate.value = data.plate_number;
      detectedPlate = data.plate_number;
      plateReview.textContent = 'Plate number detected and locked to the uploaded document; this is not LTO verification.';
      dropZone.hidden = true;
      workflow.hidden = false;
      specs.hidden = false;
      uploadStatus.textContent = data.message;
      const fieldLabels = {
        plate_number: 'Plate Number', mv_file_number: 'MV File Number', engine_number: 'Engine Number',
        chassis_number: 'Chassis Number', brand_make: 'Brand / Make', series_model: 'Series / Model',
        year_model: 'Year Model', body_type: 'Body Type', passenger_capacity: 'Passenger Capacity', fuel_type: 'Fuel Type',
        color: 'Color', classification: 'Classification', expiration_date: 'Expiration Date'
      };
      const extractedFields = Object.entries(data.document_data || {}).map(([key, value]) =>
        `<div><strong>${App.escapeHtml(fieldLabels[key] || key)}:</strong> ${App.escapeHtml(value)}</div>`
      ).join('');
      const typeLabel = typeVerification?.status === 'MATCHED'
        ? 'Registration document type confirmed.'
        : `${typeVerification?.status || 'NEEDS_REVIEW'}: ${typeVerification?.reason || 'Document type needs review.'}`;
      const extractionLabel = extractionStatus === 'extracted' ? 'Extracted successfully' :
        extractionStatus === 'needs_review' ? 'Needs review' : 'Not detected';
      extractionReview.innerHTML = `<div class="p-2 border rounded"><strong>Registration document review:</strong> ${App.escapeHtml(typeLabel)} · ${extractionLabel}${extractedFields ? `<div class="mt-1">${extractedFields}</div>` : '<div class="text-muted-custom mt-1">No reliable fields were detected.</div>'}</div>`;
      extractionReview.hidden = false;
      if (extractionStatus !== 'extracted' && typeVerification?.status === 'MATCHED') {
        registrationReviewLabel.hidden = false;
        registrationReview.required = true;
      }
      await applyExtractedVehicleData(data);
      await reanalyzeUploadedSupportingDocuments();
    } catch (error) {
      fileInput.value = '';
      uploadStatus.textContent = error.message;
      return;
    } finally {
      dropZone.removeAttribute('aria-busy');
    }

    if (previewUrl) URL.revokeObjectURL(previewUrl);
    previewUrl = URL.createObjectURL(file);
    filename.textContent = `${file.name} (${(file.size / 1024 / 1024).toFixed(2)} MB) — locked`;
    preview.hidden = false;
    previewImage.hidden = true;
    previewPdf.hidden = true;
    if (file.type === 'application/pdf') {
      previewPdf.src = `${previewUrl}#toolbar=0&navpanes=0`;
      previewPdf.hidden = false;
    } else {
      previewImage.src = previewUrl;
      previewImage.hidden = false;
    }
    status.textContent = extractionStatus === 'extracted'
      ? 'Review the detected document information and vehicle specifications before registration.'
      : 'OCR needs review. Confirm the document and enter any required vehicle information manually.';
    validate();
  };

  fileInput.addEventListener('change', () => showDocument(fileInput.files[0]));
  insuranceInput.addEventListener('change', () => analyzeOptionalDocument(insuranceInput, 'insurance', insuranceStatus, insuranceReview, insuranceReviewLabel));
  ltfrbInput.addEventListener('change', () => analyzeOptionalDocument(ltfrbInput, 'ltfrb_permit', ltfrbStatus, ltfrbReview, ltfrbReviewLabel));
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
    clearSelect(variant, 'Not specified');
    variantField.hidden = true;
    specs.hidden = true;
    clearSpecifications();
    if (type.value) loadItems(`${window.TC_BASE_URL}/actions/vehicle-catalog.php?resource=brands&parent_id=${encodeURIComponent(type.value)}`, brand, 'Select brand');
    validate();
  });
  brand.addEventListener('change', () => {
    clearSelect(model, 'Select model');
    clearSelect(variant, 'Not specified');
    variantField.hidden = true;
    specs.hidden = true;
    clearSpecifications();
    if (brand.value) loadItems(`${window.TC_BASE_URL}/actions/vehicle-catalog.php?resource=models&parent_id=${encodeURIComponent(brand.value)}&type_id=${encodeURIComponent(type.value)}`, model, 'Select model');
    validate();
  });
  model.addEventListener('change', () => {
    clearSelect(variant, 'Not specified');
    variantField.hidden = true;
    clearSpecifications();
    specs.hidden = !model.value;
    if (model.value) loadVariants();
    validate();
  });
  year.addEventListener('change', () => {
    clearSpecifications();
    if (model.value) specs.hidden = false;
    loadVariants();
    validate();
  });
  variant.addEventListener('change', () => {
    clearSpecifications();
    const option = variant.selectedOptions[0];
    if (!option?.value) { validate(); return; }
    const item = variant._items?.find(entry => String(entry.id) === option.value);
    if (item) {
      form.elements.capacity.value = item.passenger_capacity || '';
      form.elements.fuel_type.value = item.fuel_type || '';
      specs.hidden = false;
    }
    validate();
  });

  form.addEventListener('input', validate);
  form.addEventListener('change', validate);
  form.addEventListener('submit', event => {
    const blockers = documentBlockers();
    if (blockers.length) {
      event.preventDefault();
      validate();
      documentValidationWarning.focus();
      return;
    }
    if (!form.checkValidity()) {
      event.preventDefault();
      form.reportValidity();
      status.textContent = 'Complete all required fields before registering the vehicle.';
      return;
    }
    const optionalDocs = [
      [insuranceInput, insuranceStatus],
      [ltfrbInput, ltfrbStatus],
    ];
    const unverifiedType = optionalDocs.find(([input]) => input.files[0] && input.dataset.typeStatus !== 'MATCHED');
    if (unverifiedType) {
      event.preventDefault();
      unverifiedType[1].textContent = unverifiedType[0].dataset.typeStatus === 'MISMATCHED'
        ? `The uploaded document is not an ${unverifiedType[0] === insuranceInput ? 'Insurance' : 'LTFRB Permit'}.`
        : 'Document type is not verified. Upload a clearer searchable document; a plate number or manual confirmation cannot replace type verification.';
      return;
    }
    const pendingReview = optionalDocs.find(([input]) => {
      const file = input.files[0];
      return file && input.dataset.reviewedSignature !== `${file.name}:${file.size}:${file.lastModified}`;
    });
    if (pendingReview) {
      event.preventDefault();
      pendingReview[1].textContent = 'Wait for document analysis to finish before registering.';
      return;
    }
    const mismatch = optionalDocs.find(([input]) => input.files[0]
      && input.dataset.matchStatus === 'MISMATCHED');
    if (mismatch) {
      event.preventDefault();
      mismatch[1].textContent = `The uploaded ${mismatch[0] === insuranceInput ? 'Insurance' : 'LTFRB Permit'} document does not match the registration vehicle.`;
      return;
    }
    submit.disabled = true;
    submit.textContent = 'Registering...';
  });
});

function normalizePlate(value) {
  return String(value).toUpperCase().trim().replace(/[^A-Z0-9]+/g, '-').replace(/^-|-$/g, '');
}
