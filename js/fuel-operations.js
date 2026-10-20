(() => {
 const filters=document.querySelector('.fuel-filterbar');
 if(filters){
  const search=filters.elements.namedItem('q');let timer;
  const submit=()=>{clearTimeout(timer);filters.requestSubmit();};
  for(const name of ['status','vehicle'])filters.elements.namedItem(name)?.addEventListener('change',submit);
  search?.addEventListener('input',event=>{
   clearTimeout(timer);if(event.isComposing)return;
   timer=setTimeout(()=>{try{sessionStorage.setItem('fuel-search-focus',String(search.selectionStart??search.value.length));}catch{}submit();},600);
  });
  search?.addEventListener('compositionend',()=>search.dispatchEvent(new Event('input')));
  filters.addEventListener('submit',()=>clearTimeout(timer));
  try{const cursor=sessionStorage.getItem('fuel-search-focus');if(cursor!==null){sessionStorage.removeItem('fuel-search-focus');search.focus();search.setSelectionRange(Number(cursor),Number(cursor));}}catch{}
 }
 const details=document.getElementById('modal-fuel-details');
 if(details){
  document.body.append(details);
  const priorOverflow=document.body.style.overflow;
  const returnTarget=[...document.querySelectorAll('.fuel-row-actions a')].find(link=>link.href===location.href);
  document.body.style.overflow='hidden';
  const close=()=>{App.closeModal('modal-fuel-details');document.body.style.overflow=priorOverflow;const url=new URL(location.href);url.searchParams.delete('fuel_trip');url.searchParams.delete('fuel_record');url.searchParams.delete('fuel_review');history.replaceState(null,'',url);returnTarget?.focus();};
  details.querySelectorAll('[data-fuel-details-close]').forEach(button=>button.addEventListener('click',close));
  details.addEventListener('click',event=>{if(event.target===details)close();});
  details.querySelector('[data-fuel-details-close]')?.focus();
  document.addEventListener('keydown',event=>{
   if(document.fullscreenElement||!details.classList.contains('show')||document.querySelector('.modal.show,#modal-log-fuel.show,#modal-fuel-assessment.show'))return;
   if(event.key==='Escape'){event.preventDefault();close();}
   if(event.key==='Tab'){
    const items=[...details.querySelectorAll('a[href],button,input,select,summary,[tabindex="0"]')].filter(el=>!el.disabled&&el.getClientRects().length);
    const first=items[0],last=items.at(-1);
    if(event.shiftKey&&document.activeElement===first){event.preventDefault();last?.focus();}
    else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first?.focus();}
   }
  });
 }
 document.querySelectorAll('[data-fuel-assess-trip]').forEach(button=>button.addEventListener('click',()=>{
  App.openModal('modal-fuel-assessment');const select=document.getElementById('fuel-assessment-trip');select.value=button.dataset.fuelAssessTrip;select.dispatchEvent(new Event('change'));
 }));
 document.querySelectorAll('[data-fuel-submit-authorization]').forEach(button=>button.addEventListener('click',()=>{
  App.openModal('modal-log-fuel');const select=document.getElementById('fuel-authorization-select');select.value=button.dataset.fuelSubmitAuthorization;select.dispatchEvent(new Event('change'));
 }));
 const assessmentTrip=document.getElementById('fuel-assessment-trip');
 if(assessmentTrip)assessmentTrip.addEventListener('change',()=>{
  const raw=assessmentTrip.selectedOptions[0]?.dataset.estimate,estimate=raw?JSON.parse(raw):null;
  const note=document.getElementById('fuel-assessment-estimate');
  note.textContent=estimate?(estimate.issues.length?estimate.issues.join(' '):`${estimate.distance_km} km ÷ ${estimate.expected_km_per_liter} km/L = ${Number(estimate.estimated_liters).toFixed(2)} L estimated. ${estimate.efficiency_source}.`):'';
  if(estimate?.estimated_liters!=null)assessmentTrip.form.elements.namedItem('estimated_liters').value=Number(estimate.estimated_liters).toFixed(3);
 });
 const form=document.getElementById('fuel-submission-form'),select=document.getElementById('fuel-authorization-select');
 if(form&&select)select.addEventListener('change',()=>{
  const option=select.selectedOptions[0],data=option?.dataset.fuelAuthorization?JSON.parse(option.dataset.fuelAuthorization):null;
  for(const key of ['fuel_type','liters','price_per_liter','total_cost','odometer','station','receipt_no','revision','transaction_date']){
   const field=form.elements.namedItem(key);let value=data?.[key]??'';
   if(key==='transaction_date'&&value)value=value.replace(' ','T').slice(0,16);
   if(key==='revision')value=value||0;
   field.value=value;
  }
  form.elements.namedItem('receipt').required=!data?.transaction_id;
 });
 document.querySelectorAll('[data-fuel-review]').forEach(form=>{
  const panel=form.closest('[data-fuel-review-panel]'),toggle=panel.querySelector('[data-fuel-review-toggle]'),content=panel.querySelector('.fuel-review-content');
  const reason=form.elements.namedItem('reason'),error=form.querySelector('.fuel-review-error'),hint=form.querySelector('[data-fuel-reason-label]');let submitting=false;
  const close=()=>{if(toggle){content.hidden=true;toggle.setAttribute('aria-expanded','false');toggle.focus();}else document.querySelector('[data-fuel-details-close]')?.click();};
  toggle?.addEventListener('click',()=>{content.hidden=!content.hidden;toggle.setAttribute('aria-expanded',String(!content.hidden));});
  form.querySelector('[data-fuel-review-cancel]').addEventListener('click',()=>{form.reset();error.hidden=true;reason.removeAttribute('aria-invalid');update();close();});
  const states={
   verify:{title:'Verify Transaction',message:'This will verify the transaction and include the amount in operational expenses.',notes:'Verification Notes',placeholder:'Add verification notes if needed…',action:'Confirm Verification',tone:'verify',icon:'check-circle-fill',submitIcon:'check-lg'},
   confirm:{title:'Confirm Transaction',message:'This will confirm the transaction and send it for Admin verification.',notes:'Confirmation Notes',placeholder:'Add confirmation notes if needed…',action:'Confirm Transaction',tone:'verify',icon:'check-circle-fill',submitIcon:'check-lg'},
   return:{title:'Return for Correction',message:'This transaction will be returned for correction.',notes:'Reason for Correction',placeholder:'Enter the reason for correction…',action:'Return for Correction',tone:'return',icon:'arrow-counterclockwise',submitIcon:'arrow-counterclockwise'},
   reject:{title:'Reject Transaction',message:'This will reject the transaction. Its amount will not be included in operational expenses.',notes:'Reason for Rejection',placeholder:'Enter the reason for rejection…',action:'Reject Transaction',tone:'reject',icon:'x-circle-fill',submitIcon:'x-lg'}
  };
  const update=()=>{
   const value=form.querySelector('input[name="decision"]:checked')?.value,state=states[value];
   const required=['return','reject'].includes(value),submit=form.querySelector('[data-fuel-review-submit]');
   form.querySelector('.fuel-review-followup').hidden=!state;form.querySelector('[data-fuel-review-initial]').hidden=!!state;
   submit.hidden=!state;submit.disabled=submitting||!state;reason.disabled=!state;reason.required=required;
   hint.textContent=required?'(required)':'(optional)';form.dataset.reviewDecision=state?.tone??'';
   if(state){
    form.querySelector('[data-fuel-decision-title]').textContent=state.title;
    form.querySelector('[data-fuel-decision-message]').textContent=state.message;
    form.querySelector('[data-fuel-notes-title]').textContent=state.notes;reason.placeholder=state.placeholder;
    form.querySelector('[data-fuel-submit-label]').textContent=state.action;
    form.querySelector('[data-fuel-decision-icon]').className='bi bi-'+state.icon;
    form.querySelector('[data-fuel-submit-icon]').className='bi bi-'+state.submitIcon;
   }
   reason.setCustomValidity(required&&!reason.value.trim()?'A reason is required for correction or rejection.':'');
   if(reason.validity.valid){error.hidden=true;reason.removeAttribute('aria-invalid');}
  };
  form.addEventListener('change',event=>{if(event.target.name==='decision')update();});reason.addEventListener('input',update);
  reason.addEventListener('invalid',event=>{event.preventDefault();error.textContent=reason.validationMessage;error.hidden=false;reason.setAttribute('aria-invalid','true');reason.focus();});
  form.addEventListener('submit',event=>{
   if(submitting){event.preventDefault();return;}
   update();if(!form.checkValidity()){event.preventDefault();return;}
   submitting=true;reason.readOnly=true;form.setAttribute('aria-busy','true');
   form.querySelectorAll('button').forEach(button=>button.disabled=true);
   form.querySelectorAll('input[type="radio"]').forEach(input=>input.closest('label').classList.add('is-submitting'));
   form.querySelector('.fuel-review-progress').textContent='Submitting review…';
   // Keep decision, revision and CSRF enabled for the existing POST handler.
  });update();
 });
})();
