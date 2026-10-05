const fs=require('fs'),path=require('path'),assert=require('assert/strict');
const {chromium}=require('C:/Users/LAPTOP-3DS/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const root=path.join(__dirname,'..'),sessions=JSON.parse(fs.readFileSync(path.join(root,'tmp/assignment-test-sessions.json')));
let checks=0;const check=(value,label)=>{assert.ok(value,label);checks++;};
const driver={id:'D1',name:'Designated Driver',reason:'',reassignment:false,current_vehicles:[{id:'V1',plate:'TEST14'}]};
const vehicle=(id,extra={})=>({id,name:'Toyota Grandia Tourer with a long operational vehicle name',plate:'TEST14',type:'Van',capacity:14,driver,drivers:[],eligible:true,reasons:[],priority:1,status:'Available',...extra});
const fixture={recommended_id:'V1',minimum_capacity:14,vehicles:[vehicle('V1'),vehicle('V2',{driver:{...driver,id:'D2',name:'Alternative Driver'}}),vehicle('V3',{driver:null,drivers:[{id:'DU',name:'Unassigned Driver',reassignment:false,current_vehicles:[]},{id:'DR',name:'Reassignment Driver',reassignment:true,current_vehicles:[{id:'OLD',plate:'OLD14'}]}],priority:2,status:'Driver Required'}),...Array.from({length:24},(_,i)=>vehicle('LOCK'+i,{eligible:false,status:'Unavailable',reasons:['Required insurance expired and the designated driver has an overlapping scheduled trip. Review the documents and schedule before assigning.']}))]};
(async()=>{
 const browser=await chromium.launch({headless:true,channel:'msedge'});
 try {
  const context=await browser.newContext();await context.addCookies([{name:'PHPSESSID',value:sessions.fleet_admin,domain:'localhost',path:'/'}]);const page=await context.newPage();const errors=[];page.on('pageerror',error=>errors.push(error.message));
  await page.goto('http://localhost/fleet/modules/vehicle-reservation-dispatch/reservations.php');
  check(await page.locator('#modal-dispatch').count()===1,'Actual reservations page loaded');
  const live=await page.evaluate(async()=>{const r=window.TC_KANBAN_RESERVATIONS.find(r=>['Approved','Assigned','Confirmed'].includes(r.status));if(!r)return null;const response=await fetch('/fleet/actions/reservation-assignment-options.php?reservation_id='+encodeURIComponent(r.id));return {id:r.id,status:response.status,data:await response.json()};});
  if(live){check(live.status===200,'Real database options endpoint');check(Array.isArray(live.data.vehicles),'Real fleet returned');
   await page.setViewportSize({width:1366,height:900});
   await page.evaluate(id=>App.openDispatchModal(id,'assign'),live.id);
   await page.locator('.assignment-vehicle-card').first().waitFor();
   await page.waitForFunction(()=>{const img=document.querySelector('.assignment-vehicle-card img');return img?.complete&&img.naturalWidth>0;});
   await page.screenshot({path:path.join(root,'tmp/assignment-live-desktop.png')});
   await page.setViewportSize({width:390,height:844});
   await page.evaluate(id=>App.openDispatchModal(id,'assign'),live.id);
   await page.locator('.assignment-vehicle-card').first().waitFor();
   await page.screenshot({path:path.join(root,'tmp/assignment-live-phone.png')});
  }
  await page.route('**/actions/reservation-assignment-options.php?**',route=>route.fulfill({contentType:'application/json',body:JSON.stringify(fixture)}));
  const open=()=>page.evaluate(()=>{window.TC_KANBAN_RESERVATIONS=[{id:'TEST-RES',status:'Approved',clientName:'Test Group',origin:'Navotas',destination:'Quezon City',passengerCount:13,requiredCapacity:14,requiredVehicleType:'Van',departureDate:'2030-01-10',departureTime:'07:00',notes:''}];window.TC_CAN_DISPATCH=true;App.openDispatchModal('TEST-RES','assign');});
  for(const [width,height] of [[1920,1080],[1440,900],[1366,768],[1024,600],[768,1024],[820,1180],[430,932],[390,844],[375,667],[320,568],[844,390],[667,375]]){
   await page.setViewportSize({width,height});await open();await page.locator('.assignment-vehicle-card.is-selected').waitFor();
   const rect=await page.locator('.assignment-modal').boundingBox();check(rect.x>=0 && rect.x+rect.width<=width+1 && rect.y>=0 && rect.y+rect.height<=height+1,`Modal fits ${width}x${height}: ${JSON.stringify(rect)}`);
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`No horizontal page overflow ${width}`);
   check(await page.locator('#modal-dispatch').evaluate(e=>e.scrollWidth<=e.clientWidth),`No modal horizontal overflow ${width}`);
   if(width===390 || width===1366)await page.screenshot({path:path.join(root,`tmp/assignment-${width}.png`)});
   check(!(await page.locator('.assignment-unavailable').evaluate(e=>e.open)),`Unavailable section starts collapsed ${width}`);
   await page.locator('.assignment-unavailable > summary').click();
   check(await page.locator('.assignment-unavailable-list').evaluate(e=>e.scrollHeight>e.clientHeight),`Unavailable fleet internally scrolls ${width}`);
   check(await page.locator('.assignment-unavailable-list .assignment-vehicle-card').first().isDisabled(),`Unavailable records remain disabled ${width}`);
   await page.locator('.assignment-unavailable > summary').click();
   const footer=await page.locator('.assignment-form-footer').boundingBox();check(footer.y>=0 && footer.y+footer.height<=height+1,`Footer visible ${width}x${height}`);
  }
  await page.setViewportSize({width:1366,height:900});await open();await page.locator('.assignment-vehicle-card.is-selected').waitFor();
  check(await page.locator('.assignment-vehicle-thumbnail').count()===fixture.vehicles.length,'Every vehicle has a neutral thumbnail');
  check(await page.locator('.assignment-other-vehicles h6').textContent()==='Other Eligible Vehicles (2)','Eligible count follows real response');
  check((await page.locator('.assignment-unavailable > summary').textContent()).includes('(24)'),'Unavailable count follows real response');
  const departureBox=await page.locator('.assignment-trip-fields section').first().boundingBox();
  const notesBox=await page.locator('.assignment-trip-fields section').last().boundingBox();
  check(Math.abs(departureBox.y-notesBox.y)<2 && notesBox.x>departureBox.x,'Trip fields sit side by side on desktop');
  const bodyBox=await page.locator('.assignment-form-content').boundingBox(),footerBox=await page.locator('.assignment-form-footer').boundingBox();
  check(bodyBox.y+bodyBox.height<=footerBox.y+1,'Scrolling content stays above footer');
  check(await page.locator('.assignment-choice-indicator').count()===fixture.vehicles.length,'Selection indicators present');
  check(await page.locator('[name="driver_id"]').inputValue()==='D1','Driver inherited');check(await page.locator('[data-driver-info] select').count()===0,'No independent dropdown');check(!(await page.locator('#modal-dispatch-body').textContent()).includes('Fuel Allowance'),'Fuel field absent');
  await page.locator('[data-vehicle-id="V2"]').click();check(await page.locator('[name="driver_id"]').inputValue()==='D2','Alternative resolves driver');
  await page.locator('[data-vehicle-id="V3"]').click();check(await page.locator('button[type="submit"][name="dispatch_action"]').isDisabled(),'No driver blocks confirmation');
  await page.locator('.eligible-driver-panel summary').click();await page.locator('[data-driver-id="DU"]').click();check(await page.locator('[name="driver_id"]').inputValue()==='DU','Unassigned driver selectable');
  await page.locator('.eligible-driver-panel summary').click();await page.locator('[data-driver-id="DR"]').click();check(await page.locator('.assignment-reassignment-dialog').isVisible(),'Reassignment modal opens');check(await page.locator('[name="driver_id"]').inputValue()==='','Reassignment not silently accepted');
  await page.locator('[data-cancel-reassignment]').click();check(await page.locator('[name="driver_id"]').inputValue()==='','Cancelled reassignment blocked');
  await page.locator('[data-driver-id="DR"]').click();await page.locator('[data-confirm-reassignment]').click();check(await page.locator('[name="reassignment_from"]').inputValue()==='OLD','Explicit previous vehicle confirmation');
  await page.locator('[name="departure"]').fill('2030-01-20T08:00');await page.locator('[name="departure"]').dispatchEvent('change');await page.locator('.assignment-vehicle-card.is-selected').waitFor();check(await page.locator('[name="driver_id"]').inputValue()==='','Departure change clears manual driver confirmation');
  await page.evaluate(()=>{window.TC_KANBAN_RESERVATIONS[0].status='Assigned';window.TC_KANBAN_RESERVATIONS[0].assignedVehicleId='V1';window.TC_KANBAN_RESERVATIONS[0].assignedDriverId='D1';App.openDispatchModal('TEST-RES','dispatch');});await page.locator('.assignment-vehicle-card.is-selected').waitFor();check(await page.locator('[data-vehicle-id="V2"]').isDisabled(),'Dispatch cannot change saved vehicle');check(await page.locator('button[value="dispatch"]').isEnabled(),'Saved valid pairing can dispatch');
  fixture.recommended_id=null;fixture.vehicles.forEach(v=>{v.eligible=false;v.status='Unavailable';v.reasons=['Unavailable'];});await open();await page.getByText('No valid vehicle-driver combination is available.',{exact:false}).waitFor();check(await page.locator('button[value="assign"]').isDisabled(),'No-valid-combination disables confirmation');
  check(errors.length===0,'No browser errors: '+errors.join('; '));
  for(const role of ['dispatcher','driver','customer']){const ctx=await browser.newContext();await ctx.addCookies([{name:'PHPSESSID',value:sessions[role],domain:'localhost',path:'/'}]);const response=await ctx.request.get('http://localhost/fleet/actions/reservation-assignment-options.php?reservation_id=UNKNOWN');check(response.status()===(role==='dispatcher'?422:403),role+' endpoint authorization');await ctx.close();}
  console.log(`${checks} browser, responsive, workflow and permission checks passed.`);
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
