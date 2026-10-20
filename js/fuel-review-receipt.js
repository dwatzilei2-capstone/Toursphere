(() => {
 const documentPanel=document.querySelector('.fuel-review-document');if(!documentPanel)return;
 const stage=documentPanel.querySelector('[data-review-document-stage]'),status=stage.querySelector('[role="status"]'),fullscreen=documentPanel.querySelector('[data-review-fullscreen]');
 const unavailable=message=>{stage.querySelector('img,iframe')?.remove();status.hidden=false;status.textContent=message;fullscreen.disabled=true;};
 if(documentPanel.dataset.reviewReceiptAvailable!=='1')return;
 const url=`${window.TC_BASE_URL}/actions/fuel-receipt.php?id=${encodeURIComponent(documentPanel.dataset.reviewReceiptId)}`;
 const IMAGES=['image/jpeg','image/png'],PDF='application/pdf';
 // Zoom only applies to the inline image; the PDF frame keeps its own fit behaviour.
 const setZoom=(image,zoomed)=>{image.classList.toggle('is-zoomed',zoomed);stage.classList.toggle('is-zoomed',zoomed);image.title=zoomed?'Uploaded receipt image — click to fit':'Uploaded receipt image — click to zoom';};
 fetch(url+'&info=1',{credentials:'same-origin'}).then(async response=>{
  if(!response.ok)throw new Error('The uploaded receipt is unavailable or access was denied.');
  const data=await response.json();const isPdf=data.mime===PDF,isImage=IMAGES.includes(data.mime);
  if(!isPdf&&!isImage)throw new Error('This receipt format cannot be previewed.');
  const view=document.createElement(isPdf?'iframe':'img');
  if(isPdf){
   view.title='Actual uploaded fuel receipt PDF';
   view.className='fuel-review-pdf-preview';
   view.addEventListener('load',()=>{status.hidden=true;fullscreen.disabled=false;});
   view.addEventListener('error',()=>unavailable('The uploaded receipt could not be displayed.'));
   // Native PDF open parameters hide viewer chrome without altering the file.
   view.src=url+'#toolbar=0&navpanes=0&view=Fit';
  }else{
   // The submitted receipt is a real image: show the uploaded pixels, not a document frame.
   view.className='fuel-review-image-preview';
   view.alt='Actual uploaded receipt for '+data.id;
   view.decoding='async';
   view.addEventListener('load',()=>{status.hidden=true;fullscreen.disabled=false;});
   view.addEventListener('error',()=>unavailable('The uploaded receipt could not be displayed.'));
   view.addEventListener('click',()=>setZoom(view,!view.classList.contains('is-zoomed')));
   view.src=url;
  }
  stage.append(view);
 }).catch(error=>unavailable(error.message));
 fullscreen.addEventListener('click',async()=>{
  try{if(document.fullscreenElement)await document.exitFullscreen();else await documentPanel.requestFullscreen();}
  catch{
   let note=documentPanel.querySelector('.fuel-review-fullscreen-note');
   if(!note){note=document.createElement('p');note.className='fuel-review-fullscreen-note';note.setAttribute('role','status');documentPanel.querySelector('header').after(note);}
   note.textContent='Fullscreen is unavailable in this browser. You can still review the receipt below.';
  }
 });
 document.addEventListener('fullscreenchange',()=>{
  fullscreen.querySelector('span').textContent=document.fullscreenElement?'Exit Fullscreen':'View Fullscreen';
  const zoomed=stage.querySelector('.fuel-review-image-preview.is-zoomed');
  if(!document.fullscreenElement&&zoomed)setZoom(zoomed,false);
 });
})();
