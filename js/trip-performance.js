(() => {
  const modal = document.getElementById('modal-trip-performance');
  if (!modal) return;
  // Keep the shared fixed modal outside animated page containers.
  document.body.appendChild(modal);
  // Presentation only: keep original submit controls associated with their form.
  const footer = modal.querySelector('.tc-card-footer');
  const evaluationActions = modal.querySelector('.performance-submit-actions');
  if (footer && evaluationActions) footer.appendChild(evaluationActions);
  modal.querySelector('[data-evidence-jump]')?.addEventListener('click', () => {
    const section = modal.querySelector('.performance-evidence-section');
    const add = Array.from(section.querySelectorAll('details')).find(el => el.querySelector('summary')?.textContent.trim() === 'Add Trip Evidence');
    if (add) add.open = true;
    section.scrollIntoView({block: 'start', behavior: 'smooth'});
  });
  const form = modal.querySelector('#performance-evaluation-form');
  if (form) {
    const summary = document.createElement('div');
    summary.className = 'performance-validation-summary alert alert-warning';
    summary.setAttribute('role', 'alert'); summary.hidden = true;
    form.prepend(summary);
    const draftKey = `trip-evaluation:${modal.dataset.evaluationActor}:${modal.dataset.tripId}:${form.elements.revision.value}`;
    const draftFields = () => Array.from(form.elements).filter(el => /^(scores\[|delay_cause$|delay_notes$|change_reason$)/.test(el.name));
    // Keep entered assessment values across evidence review and rejected submissions.
    try {
      const draft = JSON.parse(sessionStorage.getItem(draftKey) || 'null');
      if (draft) draftFields().forEach(el => {
        if (!Object.hasOwn(draft, el.name) || el.dataset.recordsUnavailable === 'true') return;
        if (el.type === 'checkbox') el.checked = draft[el.name].includes(el.value);
        else el.value = draft[el.name][0] ?? '';
      });
    } catch (_) { /* Storage is optional; form submission still works. */ }
    const updateEvidenceCounts = () => {
      form.querySelectorAll('[data-criterion]').forEach(row => {
        const label = row.querySelector('details > summary');
        if (label) label.textContent = label.textContent.replace(/^\d+ linked/, `${row.querySelectorAll('input[type="checkbox"]:checked').length} linked`);
      });
    };
    updateEvidenceCounts();
    const saveDraft = () => {
      updateEvidenceCounts();
      const draft = {};
      draftFields().forEach(el => {
        draft[el.name] ||= [];
        if (el.type !== 'checkbox' || el.checked) draft[el.name].push(el.value);
      });
      try { sessionStorage.setItem(draftKey, JSON.stringify(draft)); } catch (_) {}
    };
    let attempted = false, finalize = false;
    const validate = () => {
      const issues = [];
      form.querySelectorAll('[aria-invalid]').forEach(el => el.removeAttribute('aria-invalid'));
      const add = (el, message) => { el?.setAttribute('aria-invalid', 'true'); issues.push({el, message}); };
      for (const row of form.querySelectorAll('[data-criterion]')) {
        const key = row.dataset.criterion, label = row.dataset.criterionLabel;
        const score = form.elements[`scores[${key}][score]`], notes = form.elements[`scores[${key}][notes]`];
        if (score.value.trim() === '') { if (finalize && Number(row.dataset.weight) > 0 && row.dataset.excludedCriterion !== 'true') add(score, `${label}: a score and evidence are required for final approval. Initial submission may leave this blank.`); continue; }
        if (!Number.isFinite(Number(score.value)) || Number(score.value) < 0 || Number(score.value) > 100) add(score, `${label}: enter a score from 0 to 100.`);
        if (!notes.value.trim()) add(notes, `${label}: explain why you gave this score.`);
        const chosen = Array.from(row.querySelectorAll('input[type="checkbox"]:checked'));
        const kinds = chosen.flatMap(el => el.dataset.evidenceKind === 'Supporting Document' && el.dataset.includesPod === '1' ? ['Supporting Document', 'POD'] : [el.dataset.evidenceKind]);
        if (!chosen.length) add(score, `${label}: select verified evidence below. If none is available, leave the score blank.`);
        if (key === 'completion' && chosen.length && (!kinds.includes('Delivery Verification') || !kinds.includes('POD'))) add(score, `${label}: select both a verified Delivery Verification record and a verified POD / delivery receipt.`);
        if (key === 'fuel' && chosen.length && (!kinds.includes('Fuel') || !(Number(form.dataset.distanceKm) > 0) || (!chosen.some(el => el.dataset.sourceTable === 'route_history' && el.dataset.evidenceKind === 'Fuel') && !kinds.includes('Supporting Document')))) add(score, `${label}: actual fuel consumption evidence and recorded distance are needed; a fuel purchase alone is insufficient.`);
        if (key === 'on_time') {
          const outcome = form.elements['scores[on_time][delivery_outcome]'];
          const hasTiming = form.dataset.deliveryVariance !== '';
          if (!outcome.value && !hasTiming) add(outcome, `${label}: choose On time or Late according to the warehouse document.`);
          if (outcome.value && chosen.length && !kinds.includes('Supporting Document')) add(outcome, `${label}: select the verified warehouse Supporting Document for this assessment.`);
          if (!outcome.value && hasTiming && chosen.length && !kinds.includes('Arrival')) add(outcome, `${label}: select verified Arrival evidence, or choose a warehouse assessment with its supporting document.`);
          const late = outcome.value ? outcome.value === 'late' : hasTiming && Number(form.dataset.deliveryVariance) > 0;
          if (late) {
            const cause = form.elements.delay_cause;
            if (['Unknown', 'None'].includes(cause.value)) add(cause, `${label}: review delay responsibility before scoring a late delivery, or leave this score blank.`);
            if (cause.value === 'External' && Number(score.value) < 100) add(score, `${label}: existing rules do not allow penalizing the driver for an external delay.`);
            if (!kinds.includes('Supporting Document')) add(score, `${label}: delay responsibility needs a verified Supporting Document.`);
          }
        }
      }
      if (form.dataset.deliveryVariance !== '' && Number(form.dataset.deliveryVariance) > 0 && form.elements.delay_cause.value !== 'Unknown' && !form.elements.delay_notes.value.trim()) add(form.elements.delay_notes, 'Delay Explanation: explain the reviewed cause of delay.');
      summary.replaceChildren(); summary.hidden = !issues.length;
      if (issues.length) {
        const title = document.createElement('strong');title.textContent = `Please review ${issues.length} item${issues.length === 1 ? '' : 's'} before submitting:`;summary.appendChild(title);
        const list = document.createElement('ul');
        issues.forEach(({el, message}) => {
          const item = document.createElement('li'), link = document.createElement('button');link.type = 'button';link.className = 'performance-validation-link';link.textContent = message;
          link.addEventListener('click', () => { el?.closest('details')?.setAttribute('open', '');el?.focus(); });item.appendChild(link);list.appendChild(item);
        });summary.appendChild(list);
      }
      return issues;
    };
    form.addEventListener('input', () => { saveDraft(); if (attempted) validate(); });
    form.addEventListener('change', () => { saveDraft(); if (attempted) validate(); });
    form.addEventListener('submit', event => {
      attempted = true;finalize = event.submitter?.value === 'finalize';saveDraft();
      if (validate().length) { event.preventDefault();summary.scrollIntoView({block: 'start', behavior: 'smooth'}); }
    });
  }
  const priorOverflow = document.body.style.overflow;
  document.body.style.overflow = 'hidden';
  const closeButtons = Array.from(modal.querySelectorAll('[data-performance-close]'));
  const close = () => {
    modal.classList.remove('show');
    document.body.style.overflow = priorOverflow;
    const url = new URL(window.location.href);
    url.searchParams.delete('trip');
    window.history.replaceState(null, '', url);
    const link = Array.from(document.querySelectorAll('[aria-haspopup="dialog"]')).find(item => new URL(item.href).searchParams.get('trip') === modal.dataset.tripId);
    link?.focus();
  };
  closeButtons.forEach(button => button.addEventListener('click', close));
  modal.addEventListener('click', event => { if (event.target === modal) close(); });
  document.addEventListener('keydown', event => {
    if (!modal.classList.contains('show')) return;
    if (event.key === 'Escape') { event.preventDefault(); close(); return; }
    if (event.key !== 'Tab') return;
    const controls = Array.from(modal.querySelectorAll('a[href],button,input,textarea,select,summary,[tabindex="0"]')).filter(item => !item.disabled && item.getClientRects().length);
    const first = controls[0], last = controls[controls.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
  });
  closeButtons[0]?.focus();
})();
