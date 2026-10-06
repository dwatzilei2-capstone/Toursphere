const fs=require('node:fs'),assert=require('node:assert/strict');
const {chromium}=require('C:/Users/LAPTOP-3DS/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const sessions=JSON.parse(fs.readFileSync(`${__dirname}/../tmp/assignment-test-sessions.json`,'utf8'));
const fixture=JSON.parse(fs.readFileSync(`${__dirname}/../tmp/route-planner-evaluations.json`,'utf8'));
(async()=>{
 const browser=await chromium.launch({headless:true,channel:'msedge'});let checks=0;
 const check=(ok,label)=>{assert.ok(ok,label);checks++;};
 try{
  for(const role of ['fleet_admin','dispatcher','driver']){
   const context=await browser.newContext();await context.addCookies([{name:'PHPSESSID',value:sessions[role],domain:'localhost',path:'/'}]);
   const page=await context.newPage();let record=null,active=0,generations=0;const errors=[];
   page.on('pageerror',error=>errors.push(error.message));
   await page.route('**/maps.googleapis.com/**',route=>route.abort());await page.route('**/maps.gstatic.com/**',route=>route.abort());
   await page.route('**/actions/route-navigation-state.php*',route=>route.fulfill({json:{ok:true,trips:[{id:'TEST-T',status:active?'In Transit':'Dispatched'}]}}));
   await page.route('**/actions/route_ai.php',async route=>{
    const mode=new URLSearchParams(route.request().postData()).get('mode');
    // Multipart FormData is used by the real planner; all-mode evaluation data is independent of the requested profile.
    const result=fixture.modeEvaluations[mode]||fixture.modeEvaluations.balanced;
    await route.fulfill({json:{ok:true,data:{...result,vehicleSpecs:fixture.vehicleSpecs,modeEvaluations:fixture.modeEvaluations,modeSelections:Object.fromEntries(Object.entries(fixture.modeEvaluations).map(([key,value])=>[key,value.selectedIndex]))}}});
   });
   await page.route('**/actions/route-planner-state.php*',async route=>{
    if(route.request().method()==='POST'){
     const body=new URLSearchParams(route.request().postData());assert.equal(Number(body.get('revision')),record?.revision||0);
     record={revision:(record?.revision||0)+1,lifecycle:body.get('lifecycle'),state_data:JSON.parse(body.get('state'))};
     await route.fulfill({json:{ok:true,revision:record.revision}});
    }else await route.fulfill({json:{ok:true,state:record,navigation_active:active}});
   });
   await page.route('**/actions/route-start.php',async route=>{
    assert.ok(record.state_data.applied);record.lifecycle='NAVIGATING';active=1;
    await route.fulfill({json:{ok:true,log_id:'ISOLATED-BROWSER-LOG',already_saved:active===1}});
   });
   async function openPlanner(){
    await page.goto('http://localhost/fleet/modules/ai-route-optimization/ai-route-planner.php',{waitUntil:'domcontentloaded'});
    await page.waitForFunction(()=>Boolean(window.aiRouteEngine));
    await page.evaluate(({fixture,role})=>{
     class LatLng{constructor(lat,lng){this.a=lat;this.b=lng;}lat(){return this.a;}lng(){return this.b;}equals(other){return this.a===other.lat()&&this.b===other.lng();}toJSON(){return {lat:this.a,lng:this.b};}}
     class Polyline{constructor(options){this.path=options.path;this.map=options.map;}setMap(map){this.map=map;}getPath(){return {getLength:()=>this.path.length};}}
     window.google={maps:{LatLng,Polyline,LatLngBounds:class{},TravelMode:{DRIVING:'DRIVING'},TrafficModel:{BEST_GUESS:'BEST_GUESS'},DirectionsStatus:{OK:'OK'}}};
     Object.assign(window.TC_ROUTE_CONTEXT,{tripId:'TEST-T',reservationId:'TEST-R',vehicleId:'TEST-V',tripStatus:'Dispatched',hasActiveTrip:true,passengerCount:5,waypoints:[],isDriver:role==='driver',canApply:true,routePhase:'outbound'});
     const origin=document.getElementById('route-origin-input'),destination=document.getElementById('route-dest-input'),vehicle=document.getElementById('route-vehicle-select');
     origin.value='Origin';destination.value='Destination';
     const option=new Option('Test vehicle','Test vehicle');Object.assign(option.dataset,{vehicleId:'TEST-V',consumption:'9.5',fuelPrice:'95.95',capacity:'15'});vehicle.add(option);vehicle.value='Test vehicle';
     document.getElementById('waypoints-container').replaceChildren();
     const engine=window.aiRouteEngine;engine.map={};engine.fitMapBounds=()=>{};engine.acquireNavigationPosition=()=>{};engine.acquireDriverPlanningPosition=()=>{};
     engine.directionsRenderer={setMap(){},setOptions(){},setDirections(){},setRouteIndex(index){window.testRenderedRouteIndex=index;}};
     engine.directionsService={route(request,callback){window.testDirectionsCalls=(window.testDirectionsCalls||0)+1;callback(fixture.directions,'OK');}};
     engine.plannerRestorePending=false;engine.plannerRestoreCompleted=false;
     document.getElementById('btn-generate-ai-route').disabled=false;
    },{fixture,role});
    await page.evaluate(()=>aiRouteEngine.restorePlannerState());
   }
   for(const [mode,winner] of Object.entries({fastest:0,shortest:1,fuelEfficient:2,balanced:2})){
    record=null;active=0;await openPlanner();
    await page.locator('#btn-generate-ai-route').click();await page.waitForFunction(()=>Boolean(aiRouteEngine.currentRouteData));
    await page.evaluate(async()=>{await aiRouteEngine.evaluationPromise;await aiRouteEngine.persistQueue;});generations++;
    // Compare all four objectives on the same generated directions before applying this mode.
    for(const [comparison,index] of Object.entries({fastest:0,shortest:1,fuelEfficient:2,balanced:2})){
     await page.locator(`.opt-option-card[data-mode="${comparison}"]`).click();
     check(await page.evaluate(index=>testRenderedRouteIndex===index&&aiRouteEngine.currentEvaluationData.selectedIndex===index,index),`${role} ${comparison}: renderer and metrics`);
     check(await page.locator('#ai-res-distance').textContent()===(await page.evaluate(()=>aiRouteEngine.currentRouteData.distance)),`${role} ${comparison}: results update`);
    }
    await page.locator(`.opt-option-card[data-mode="${mode}"]`).click();await page.locator('#btn-generate-ai-route').click();
    await page.waitForFunction(()=>document.getElementById('btn-generate-ai-route').textContent==='Applied');
    const applied=await page.evaluate(()=>aiRouteEngine.appliedRoute.routeId);
    check(await page.locator('#btn-generate-ai-route').isDisabled(),`${role} ${mode}: applied disabled`);
    await page.goto('http://localhost/fleet/modules/fuel-management/fuel-overview.php',{waitUntil:'domcontentloaded'});await openPlanner();
    check(await page.evaluate(id=>aiRouteEngine.appliedRoute.routeId===id&&document.getElementById('btn-generate-ai-route').textContent==='Applied',applied),`${role} ${mode}: same applied route after another module`);
    check(await page.evaluate(()=>!window.testDirectionsCalls),`${role} ${mode}: restore without directions request`);
    await page.locator('#btn-start-navigation').click();await page.waitForFunction(()=>aiRouteEngine.isNavigating);await page.evaluate(()=>aiRouteEngine.persistQueue);
    check(await page.evaluate(({id,mode})=>aiRouteEngine.currentRouteData.routeId===id&&aiRouteEngine.currentMode===mode,{id:applied,mode}),`${role} ${mode}: navigation consumes applied route`);
    await page.goto('http://localhost/fleet/modules/fuel-management/fuel-overview.php',{waitUntil:'domcontentloaded'});await openPlanner();await page.evaluate(()=>aiRouteEngine.persistQueue);
    check(await page.evaluate(({id,mode})=>aiRouteEngine.isNavigating&&aiRouteEngine.currentRouteData.routeId===id&&aiRouteEngine.currentMode===mode,{id:applied,mode}),`${role} ${mode}: active navigation restored`);
    check(await page.evaluate(()=>!window.testDirectionsCalls),`${role} ${mode}: resume without route regeneration`);
   }
   check(errors.length===0,`${role}: browser errors: ${errors.join(', ')}`);console.log(`Browser workflows passed for ${role}.`);await context.close();
  }
  console.log(`${checks} browser lifecycle checks passed; 12 independent mode/role workflows. Provider/state responses intercepted; no operational trip records changed.`);
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
