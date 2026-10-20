const fs=require('fs'),path=require('path'),assert=require('assert/strict'),cp=require('child_process');
const {chromium}=require('C:/Users/LAPTOP-3DS/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const root=path.join(__dirname,'..'),sessions=JSON.parse(fs.readFileSync(path.join(root,'tmp/assignment-test-sessions.json')));
const base='http://localhost/fleet',directory=base+'/modules/fleet-vehicle-management/vehicle-directory.php';
let checks=0;const check=(v,label)=>{assert.ok(v,label);checks++;};
const php=(mode)=>cp.execFileSync('C:/xampp/php/php.exe',[path.join(__dirname,'vehicle_photo_http_fixture.php'),mode],{cwd:root,encoding:'utf8'});
const fixture=JSON.parse(php('create'));
(async()=>{
 let browser;
 try{
  browser=await chromium.launch({headless:true,channel:'msedge'});
  const ctx=await browser.newContext();await ctx.addCookies([{name:'PHPSESSID',value:sessions.fleet_admin,domain:'localhost',path:'/'}]);
  const page=await ctx.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(directory);await page.evaluate(()=>document.querySelectorAll('[data-vehicle-photo]').forEach(i=>i.loading='eager'));
  await page.waitForFunction(()=>Array.from(document.querySelectorAll('.vehicle-directory-thumbnail img')).every(i=>i.complete&&i.naturalWidth>0));
  const data=await page.evaluate(()=>window.TC_VEHICLES_DATA);
  check(await page.locator('.vehicle-directory-thumbnail').count()===Object.keys(data).length,'Every directory row has a loaded thumbnail');
  for(const [id,v] of Object.entries(data)){
   check(v.photo.vehicleId===id,'Directory photo belongs to '+id);
   check(v.photo.source===(v.photo.hasActual?'actual':'placeholder'),'Uploaded photo or clean placeholder labeled '+id);
   check(!('sampleSrc' in v.photo),'Built-in passenger illustrations retired '+id);
   check(await page.locator(`[data-vehicle-id="${id}"] .vehicle-photo-label`).textContent()===(v.photo.hasActual?'':'No photo'),'Visible source label '+id);
  }
  check(!(await page.locator('#vehicle-photo').getAttribute('required')),'Add Vehicle photo remains optional');
  check(await page.locator('#vehicle-photo').getAttribute('name')==='vehicle_photo','Separate Add Vehicle upload field');
  for(const [width,height] of [[1366,900],[768,1024],[390,844],[320,568]]){
   await page.setViewportSize({width,height});await page.evaluate(id=>App.viewVehicleDetails(id),fixture.id);
   await page.locator('.vehicle-photo-profile').waitFor({state:'visible'});
   check(await page.locator('#modal-vehicle-details-body').evaluate(e=>e.scrollWidth<=e.clientWidth+1),'Profile no overflow '+width);
   check(await page.locator('.vehicle-photo-profile img').evaluate(i=>i.complete&&i.naturalWidth>0),'Profile image loaded '+width);
   check(await page.locator('.vehicle-photo-profile button').textContent()==='Upload Actual Photo','Profile upload control '+width);
   if(width===1366||width===390)await page.screenshot({path:path.join(root,`tmp/vehicle-photo-profile-${width}.png`),animations:'disabled'});
   await page.evaluate(()=>App.closeModal('modal-vehicle-details'));
  }
  await page.setViewportSize({width:1366,height:900});await page.evaluate(id=>App.viewVehicleDetails(id),fixture.id);
  const csrf=await page.locator('.vehicle-photo-profile [name="csrf_token"]').inputValue();
  const sample=Buffer.from(await page.evaluate(()=>{const canvas=document.createElement('canvas');canvas.width=80;canvas.height=50;const c=canvas.getContext('2d');c.fillStyle='#286ac6';c.fillRect(0,0,80,50);return canvas.toDataURL('image/png').split(',')[1];}),'base64');
  const upload=async(name,mimeType,buffer,token=csrf,request=ctx.request)=>request.post(base+'/actions/vehicle-photo-upload.php',{maxRedirects:0,multipart:{vehicle_id:fixture.id,csrf_token:token,vehicle_photo:{name,mimeType,buffer}}});
  const photoState=()=>{const state=JSON.parse(php('inspect'));return {...state,actual:state.photo?.actual_filename??null,mime:state.photo?.actual_mime??null};};
  const beforeActual=photoState().actual;
  await upload('test.png','image/png',sample,'wrong-token');
  check(photoState().actual===beforeActual,'Invalid CSRF cannot update photo');
  await upload('test.php','image/png',sample);
  check(!photoState().actual,'Executable extension rejected through HTTP');
  await upload('fake.png','image/png',Buffer.from('<?php echo "fake";'));
  check(!photoState().actual,'Fake PNG rejected through HTTP');
  await upload('large.png','image/png',Buffer.alloc(5*1024*1024+1));
  check(!photoState().actual,'Oversize upload rejected through HTTP');
  const low=await browser.newContext();await low.addCookies([{name:'PHPSESSID',value:sessions.driver,domain:'localhost',path:'/'}]);
  await upload('test.png','image/png',sample,csrf,low.request);
  check(!photoState().actual,'Unauthorized driver cannot update vehicle photo');await low.close();
  const formats=await page.evaluate(()=>{const canvas=document.createElement('canvas');canvas.width=80;canvas.height=50;const c=canvas.getContext('2d');c.fillStyle='#286ac6';c.fillRect(0,0,80,50);return {jpg:canvas.toDataURL('image/jpeg').split(',')[1],webp:canvas.toDataURL('image/webp').split(',')[1]};});
  let previous=null;
  for(const [name,mime,buffer] of [['fixture.png','image/png',sample],['fixture.jpg','image/jpeg',Buffer.from(formats.jpg,'base64')],['fixture.webp','image/webp',Buffer.from(formats.webp,'base64')]]){
   const response=await upload(name,mime,buffer);check(response.status()===302,'Successful actual upload '+mime);
   const state=photoState();
   check(state.mime===mime,'Detected MIME stored '+mime);
   check(/^[a-f0-9]{48}\.(png|jpg|webp)$/.test(state.actual),'Safe unique filename '+mime);
   check(state.vehicle.status===fixture.initial.status&&state.vehicle.type===fixture.initial.type&&state.vehicle.capacity===fixture.initial.capacity&&state.vehicle.assigned_driver_id===null,'Operational fields unchanged '+mime);
   if(previous)check(!fs.existsSync(path.join(root,'storage/private/vehicle-photos',previous)),'Previous actual file removed');
   previous=state.actual;
   await page.goto(directory+'?vehicle='+fixture.id);
   check(await page.locator('.vehicle-photo-profile button').textContent()==='Change Photo','Profile changes control after actual upload');
   const photo=await page.evaluate(id=>window.TC_VEHICLES_DATA[id].photo,fixture.id);
   check(photo.source==='actual','Actual takes priority in shared payload');
   check(await page.locator(`[data-vehicle-id="${fixture.id}"] .vehicle-photo img`).getAttribute('src')===photo.src,'Directory uses profile source');
   const served=await ctx.request.get('http://localhost'+photo.src);check(served.headers()['content-type']===mime,'Image endpoint serves correct MIME');
   check(served.headers()['x-content-type-options'].includes('nosniff'),'Image content cannot be sniffed');
   check((await served.body()).equals(buffer),'Uploaded image served without alteration');
   const denied=await ctx.request.get(base+'/storage/private/vehicle-photos/'+state.actual);check(denied.status()===403,'Stored originals inaccessible directly');
  }
  const savedPhoto=await page.evaluate(id=>window.TC_VEHICLES_DATA[id].photo,fixture.id);
  await page.goto(base+'/modules/vehicle-reservation-dispatch/reservations.php');
  // Several live reservations currently have no eligible vehicle (compliance/schedule state), so pick
  // the first candidate that actually offers one; the card layout is what this section verifies.
  const live=await page.evaluate(async()=>{
   const candidates=window.TC_KANBAN_RESERVATIONS.filter(r=>['Approved','Assigned','Confirmed'].includes(r.status));
   let fallback=null;
   for(const r of candidates){
    const response=await fetch('/fleet/actions/reservation-assignment-options.php?reservation_id='+encodeURIComponent(r.id));
    const data=await response.json();
    if(!fallback)fallback={id:r.id,data};
    if(data.vehicles&&data.vehicles.some(v=>v.eligible))return {id:r.id,data};
   }
   return fallback;
  });
  check(!!live,'Live assignment available for shared-source verification');
  const selected=live.data.vehicles.find(v=>v.id===fixture.id);check(selected.photo.src===savedPhoto.src&&selected.photo.source==='actual','Assignment reads same updated actual source');
  for(const v of live.data.vehicles) check(v.photo.vehicleId===v.id,'Assignment photo belongs to '+v.id);
  for(const [width,height] of [[1366,900],[390,844],[320,568]]){
   await page.setViewportSize({width,height});await page.evaluate(id=>App.openDispatchModal(id,'assign'),live.id);
   await page.locator('.assignment-vehicle-card').first().waitFor();
   await page.locator('.assignment-vehicle-card img').first().waitFor({state:'visible'});
   await page.waitForFunction(()=>{const img=document.querySelector('.assignment-vehicle-card img');return img.complete&&img.naturalWidth>0;});
   check(await page.locator('.assignment-vehicle-card img').first().evaluate(i=>i.naturalWidth>0),'Recommended/first card photo actually loaded '+width);
   if(width===1366||width===390)await page.screenshot({path:path.join(root,`tmp/vehicle-photo-assignment-${width}.png`),animations:'disabled'});
   if(live.data.recommended_id){
    const recommendation=live.data.vehicles.find(v=>v.id===live.data.recommended_id);
    check(await page.locator('.assignment-vehicle-card.is-recommended img').getAttribute('src')===recommendation.photo.src,'Recommended photo matches record '+width);
    const alternative=live.data.vehicles.find(v=>v.eligible&&v.id!==recommendation.id);
    if(alternative){await page.locator(`[data-vehicle-id="${alternative.id}"]`).click();check(await page.locator('.assignment-vehicle-card.is-selected img').getAttribute('src')===alternative.photo.src,'Photo follows alternative vehicle selection '+width);}
   }
   await page.locator('.assignment-unavailable > summary').click();
   await page.locator(`[data-vehicle-id="${fixture.id}"]`).scrollIntoViewIfNeeded();
   check(await page.locator(`[data-vehicle-id="${fixture.id}"] img`).getAttribute('src')===savedPhoto.src,'Unavailable card shares updated photo '+width);
   check(await page.locator(`[data-vehicle-id="${fixture.id}"]`).isDisabled(),'Unavailable vehicle remains disabled '+width);
   check(await page.locator('#modal-dispatch').evaluate(e=>e.scrollWidth<=e.clientWidth+1),'Assignment no image overflow '+width);
   check(await page.locator('.assignment-vehicle-thumbnail').count()===live.data.vehicles.length,'Every recommendation/eligible/unavailable card has a photo '+width);
   await page.evaluate(()=>App.closeModal('modal-dispatch'));
  }
  // Exercise browser-side actual→placeholder recovery independently of disk timing.
  const fallbacks=await page.evaluate(()=>{
   const placeholder=window.TC_BASE_URL+'/assets/images/vehicle-placeholder.svg';
   const host=document.createElement('div');host.innerHTML=App.renderVehiclePhoto({source:'actual',src:'/missing-actual',placeholderSrc:placeholder,label:'Actual vehicle photo'},'Test');document.body.append(host);const img=host.querySelector('img');App.handleVehiclePhotoError(img);const first={src:img.getAttribute('src'),source:img.dataset.source,label:host.querySelector('.vehicle-photo-label').textContent};App.handleVehiclePhotoError(img);const second={src:img.getAttribute('src'),source:img.dataset.source,icon:!!host.querySelector('i'),hidden:img.hidden};host.remove();return {first,second};
  });
  check(fallbacks.first.source==='placeholder'&&fallbacks.first.src.endsWith('vehicle-placeholder.svg'),'Browser falls back a missing upload to the placeholder');
  check(fallbacks.first.label==='No photo','Fallback labels the placeholder');
  check(fallbacks.second.icon===true&&fallbacks.second.hidden===true,'Failed placeholder falls back to the inline icon');
  check(errors.length===0,'No browser errors: '+errors.join('; '));
  console.log(`${checks} vehicle photo browser, upload, authorization, shared-source, and responsive checks passed.`);
 }finally{if(browser)await browser.close();console.log(php('clean').trim());}
})().catch(e=>{console.error(e);process.exitCode=1;});
