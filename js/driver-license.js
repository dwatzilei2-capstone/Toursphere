(() => {
 const form=document.getElementById('driver-license-form');if(!form)return;
 const file=form.elements.license_document,status=document.getElementById('driver-license-analysis'),save=document.getElementById('driver-license-save'),preview=document.getElementById('driver-license-preview');let token=0,url;
 const validate=()=>{save.disabled=!form.checkValidity()||form.elements.license_type.value!=='Professional'||!form.querySelector('[name="authorized_dl_codes[]"][value="C"]').checked;};
 document.querySelectorAll('[data-review-driver-license]').forEach(button=>button.addEventListener('click',()=>{
  form.reset();token++;if(url)URL.revokeObjectURL(url);preview.hidden=true;status.textContent='';const d=JSON.parse(button.dataset.reviewDriverLicense);form.elements.driver_id.value=d.id||'';form.elements.name.value=d.name||'';form.elements.license_no.value=d.license_no||'';form.elements.license_expiration.value=d.license_expiration||'';validate();App.openModal('modal-driver-license');
 }));
 form.addEventListener('input',validate);form.addEventListener('change',validate);
 file.addEventListener('change',async()=>{
  const current=++token;form.elements.manual_verified.checked=false;form.querySelectorAll('[name="authorized_dl_codes[]"]').forEach(c=>c.checked=false);form.elements.license_type.value='';validate();
  if(url)URL.revokeObjectURL(url);preview.hidden=!file.files.length;if(!file.files.length)return;
  url=URL.createObjectURL(file.files[0]);preview.href=url;status.textContent='Extracting license details...';save.disabled=true;
  const payload=new FormData();payload.append('csrf_token',form.elements.csrf_token.value);payload.append('license_document',file.files[0]);
  try{const response=await fetch(form.action+'?action=analyze',{method:'POST',body:payload});const result=await response.json();if(current!==token)return;if(!response.ok||!result.ok)throw Error(result.error||'Extraction unavailable.');const d=result.data;
   for(const key of ['license_no','license_expiration','license_type'])if(d[key])form.elements[key].value=d[key];
   form.querySelectorAll('[name="authorized_dl_codes[]"]').forEach(c=>c.checked=(d.authorized_dl_codes||[]).includes(c.value));
   status.textContent=d.test_fixture?'TEST ONLY license detected. Cannot verify a driver.':d.supported?'Extracted DL codes: '+(d.authorized_dl_codes.join(', ')||'not detected')+'. Review the original document before verifying.':'Text extraction is not supported for this document. Review both sides and enter the actual qualifications manually.';
  }catch(error){if(current===token)status.textContent=error.message+' Review the original document manually.';}finally{if(current===token)validate();}
 });
 validate();
})();
