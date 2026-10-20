const fs=require('node:fs'),assert=require('node:assert/strict');
const {chromium}=require('C:/Users/LAPTOP-3DS/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const sessions=JSON.parse(fs.readFileSync(`${__dirname}/../tmp/assignment-test-sessions.json`,'utf8'));
(async()=>{
 const browser=await chromium.launch({headless:true,channel:'msedge'});let checks=0;
 const check=(ok,label)=>{assert.ok(ok,label);checks++;};
 try{
  const context=await browser.newContext();await context.addCookies([{name:'PHPSESSID',value:sessions.fleet_admin,domain:'localhost',path:'/'}]);
  const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto('http://localhost/fleet/modules/vehicle-reservation-dispatch/reservations.php',{waitUntil:'domcontentloaded'});
  const response=await context.request.get('http://localhost/fleet/actions/reservation-availability.php?id=RES-2026-034');
  check(response.ok(),'Actual availability endpoint responds');const actual=await response.json();check(actual.ok,'Actual request loads');
  if(actual.reservation.status==='Pending Approval'){
   check(Boolean(actual.availability.vehicles)&&Boolean(actual.availability.drivers),'Actual cargo endpoint returns both resource groups');
   await page.evaluate(()=>openReservationDetails('RES-2026-034'));
   await page.locator('#reservation-details-body section h6').first().waitFor();
   check(await page.locator('#reservation-details-body section h6').count()===2,'Actual request renders Vehicle and Driver sections');
   check(await page.locator('#reservation-details-body .reservation-available-drivers li').count()===actual.availability.drivers.available_count,'Actual Driver list includes every independently available driver');
   await page.setViewportSize({width:390,height:900});
   check(await page.locator('#modal-reservation-details').evaluate(e=>e.scrollWidth<=e.clientWidth+1),'Actual full Driver list fits mobile modal');
   console.log(`Actual RES-2026-034: ${actual.availability.vehicles.available_count} vehicles, ${actual.availability.drivers.available_count} drivers available.`);
  }
  const sample=JSON.parse(JSON.stringify(actual));sample.can_review=false;
  sample.reservation.status='Pending Approval';sample.reservation.request_kind='Cargo';
  sample.availability={applicable:true,cargo:true,buffer_hours:4,vehicles:{matched_count:1,available_count:1,available_items:['TEST-CARGO · Heavy Truck · 5000 kg payload · 30 m³'],blockers:[]},drivers:{available_count:1,available_items:['Test Driver'],blockers:[]}};
  for(const width of [1440,390]){
   await page.setViewportSize({width,height:900});await page.evaluate(data=>{App.openModal('modal-reservation-details');renderReservationDetails(data);},sample);
   const text=await page.locator('#reservation-details-body').innerText();
   check(text.includes('Vehicle · 1 available')&&text.includes('Driver · 1 available'),`Both availability counts at ${width}px`);
   check(text.includes('Test Driver')&&text.includes('5000 kg payload'),`Names and cargo specifications at ${width}px`);
   check(!text.includes('passenger capacity'),`Cargo labels at ${width}px`);
   check(await page.locator('#modal-reservation-details').evaluate(e=>e.scrollWidth<=e.clientWidth+1),`No horizontal modal overflow at ${width}px`);
  }
  sample.availability.vehicles={matched_count:1,available_count:0,available_items:[],blockers:[{reason:'Existing trip or schedule conflict',count:1,details:['TEST-CARGO · Heavy Truck']}]};
  sample.availability.drivers={available_count:0,available_items:[],blockers:[{reason:'Driver has an active trip or another assignment overlapping this departure and dispatch buffer',count:1,details:['Test Driver']}]};
  await page.evaluate(data=>renderReservationDetails(data),sample);const blocked=await page.locator('#reservation-details-body').innerText();
  check(blocked.includes('Driver · 0 available')&&blocked.includes('Test Driver')&&blocked.includes('overlapping'),'Blocked driver shows count, name and reason');
  check(blocked.includes('Vehicle · 0 available')&&blocked.includes('TEST-CARGO')&&blocked.includes('schedule conflict'),'Blocked vehicle shows count, plate and reason');
  check(errors.length===0,`No browser errors: ${errors.join(', ')}`);
  await context.close();console.log(`${checks} cargo availability browser checks passed. Only GET requests and isolated DOM fixtures; no operational records changed.`);
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
