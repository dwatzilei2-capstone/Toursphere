const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const {webcrypto}=require('node:crypto');
const source=fs.readFileSync(`${__dirname}/../js/map-route.js`,'utf8');
const fixture=JSON.parse(fs.readFileSync(`${__dirname}/../tmp/route-planner-evaluations.json`,'utf8'));
let checks=0;
function check(value,label){assert.ok(value,label);checks++;}
class LatLng {constructor(lat,lng){this.a=lat;this.b=lng;}lat(){return this.a;}lng(){return this.b;}equals(other){return this.a===other.lat()&&this.b===other.lng();}toJSON(){return {lat:this.a,lng:this.b};}}
class Polyline {constructor(options){this.path=options.path;this.map=options.map;}setMap(map){this.map=map;}setPath(path){this.path=path;}getPath(){return {getLength:()=>this.path.length};}}
function element(value=''){return {value,style:{},dataset:{},classList:{add(){},remove(){},toggle(){}},setAttribute(){},addEventListener(){},querySelector(){return null;},querySelectorAll(){return [];},replaceChildren(){},appendChild(){},textContent:'',innerHTML:''};}
function makePage(role,store){
 const ids={};for(const id of ['route-origin-input','route-dest-input','route-vehicle-select','btn-generate-ai-route','btn-start-navigation','map-card-container','ai-res-title','ai-res-distance','ai-res-duration','ai-res-fuel','ai-res-score','ai-res-reason','waypoints-container'])ids[id]=element();
 ids['route-origin-input'].value='Origin';ids['route-dest-input'].value='Destination';ids['route-vehicle-select'].value='Test vehicle';
 ids['route-vehicle-select'].selectedOptions=[{dataset:{vehicleId:'TEST-V',consumption:'9.5',fuelPrice:'95.95',capacity:'15'}}];
 const cards=['balanced','fastest','fuelEfficient','shortest'].map(mode=>({...element(),dataset:{mode}}));
 const document={getElementById:id=>ids[id]||null,querySelectorAll:selector=>selector.includes('opt-option-card')?cards:[],querySelector:()=>null,createElement:()=>({...element(),remove(){}}),addEventListener(){}};
 const window={TC_BASE_URL:'/fleet',TC_ROUTE_CONTEXT:{tripId:'TEST-T',vehicleId:'TEST-V',tripStatus:'Dispatched',stateCsrf:'test',canApply:role!=='fleet_manager',isDriver:role==='driver',passengerCount:5},addEventListener(){},showAppToast(...args){(store.toasts ||= []).push(args);}};
 const requests=[];
 const fetch=async(url,options={})=>{
  requests.push({url,body:options.body});
  if(url.includes('route_ai.php')){
   if(store.evaluationGate)await store.evaluationGate;
   const current=options.body.get('mode');const evaluation=fixture.modeEvaluations[current];
   return {ok:true,json:async()=>({ok:true,data:{...evaluation,vehicleSpecs:fixture.vehicleSpecs,modeEvaluations:fixture.modeEvaluations,modeSelections:Object.fromEntries(Object.entries(fixture.modeEvaluations).map(([key,result])=>[key,result.selectedIndex]))}})};
  }
  if(url.includes('route-start.php')){
   if(store.blockStart)return new Promise(()=>{});
   check(!options.body.has('route_data_json') && !options.body.has('candidates_json'),'Start Navigation sends route identity without uploading saved geometry again');
   assert.equal(options.body.get('route_id'),store.record.state_data.applied.routeId);
   assert.equal(options.body.get('mode'),store.record.state_data.applied.mode);
   store.record.lifecycle='NAVIGATING';store.active=1;store.starts++;
   return {ok:true,json:async()=>({ok:true,log_id:'TEST-LOG',already_saved:store.starts>1})};
  }
  assert.ok(url.includes('route-planner-state.php'));
  if(options.body && store.blockSave)return new Promise((resolve,reject)=>{options.signal.addEventListener('abort',()=>{const error=new Error('Request aborted');error.name='AbortError';reject(error);});});
  if(options.body && store.failSave)return {ok:false,status:503,json:async()=>({ok:false,error:'Database unavailable for test save'})};
  if(!options.body)return {ok:true,json:async()=>{
   const record=store.record?JSON.parse(JSON.stringify(store.record)):null;
   if(record)for(const key of ['selected','applied']){
    const route=record.state_data[key];if(!route)continue;
    if(route.directionsRef==='generated'){route.directions=record.state_data.directions;delete route.directionsRef;}
    for(const field of ['geometry','prePickupPath'])if(route[field+'Ref']==='selected'){route[field]=record.state_data.selected[field];delete route[field+'Ref'];}
   }
   return {ok:true,state:record,trip_status:'In Transit',navigation_active:store.active};
  }};
  if(Number(options.body.get('revision'))!==(store.record?.revision||0))return {ok:false,status:409,json:async()=>({ok:false,error:'Route state changed in another page. Reload before applying.',error_code:'ROUTE_REVISION_CONFLICT'})};
  store.record={revision:(store.record?.revision||0)+1,lifecycle:options.body.get('lifecycle'),state_data:JSON.parse(options.body.get('state'))};
  return {ok:true,json:async()=>({ok:true,revision:store.record.revision})};
 };
 const coordinate=(point,key)=>typeof point[key]==='function'?point[key]():point[key];
 const sandbox={window,document,google:{maps:{LatLng,Polyline,LatLngBounds:class{},TravelMode:{DRIVING:'DRIVING'},TrafficModel:{BEST_GUESS:'BEST_GUESS'},DirectionsStatus:{OK:'OK'},geometry:{spherical:{computeDistanceBetween:(a,b)=>Math.hypot(coordinate(a,'lat')-coordinate(b,'lat'),coordinate(a,'lng')-coordinate(b,'lng'))*111320}}}},navigator:{geolocation:{watchPosition:()=>1,clearWatch(){},getCurrentPosition:callback=>callback({coords:{latitude:15,longitude:122,accuracy:10,heading:null}})}},crypto:webcrypto,fetch,FormData,URLSearchParams,AbortController,console,setTimeout,clearTimeout};
 vm.createContext(sandbox);vm.runInContext(source.slice(0,source.indexOf('window.aiRouteEngine ='))+'\nthis.RouteEngine=AIRouteEngine;',sandbox);
 const engine=new sandbox.RouteEngine();engine.map={};engine.fitMapBounds=()=>{};engine.acquireNavigationPosition=()=>{};
 return {engine,ids,requests,window,acquireGps:sandbox.RouteEngine.prototype.acquireNavigationPosition.bind(engine)};
}
(async()=>{
 for(const role of ['fleet_admin','dispatcher','driver']){
  for(const [mode,winner] of Object.entries({fastest:0,shortest:1,fuelEfficient:2,balanced:2})){
   const store={record:null,active:0,starts:0};const page=makePage(role,store);const e=page.engine;
   e.currentMode=mode;e.processGoogleDirectionsResult(fixture.directions);await e.evaluationPromise;await e.persistQueue;
   check(e.currentEvaluationData.selectedIndex===winner,`${role} ${mode}: objective`);
   check(JSON.stringify(e.selectedRoutePolyline.path)===JSON.stringify(fixture.directions.routes[winner].overview_path),`${role} ${mode}: map geometry`);
   check(page.ids['ai-res-distance'].textContent===e.currentRouteData.distance,`${role} ${mode}: displayed distance`);
   check(e.currentRouteData.durationMins===fixture.modeEvaluations[mode].selectedCandidate.durationMins,`${role} ${mode}: own ETA`);
   check(e.currentRouteData.fuelEstimateLiters===fixture.modeEvaluations[mode].selectedCandidate.fuelEstimateLiters,`${role} ${mode}: own fuel`);
   if(role==='driver'){
    check(page.ids['btn-start-navigation'].style.display==='none',`${mode}: navigation hidden before selection and apply`);
    await e.applySelectedRoute();check(!e.appliedRoute,`${mode}: explicit route selection required`);
    e.selectMode(mode);await e.persistQueue;
    check(page.ids['btn-generate-ai-route'].textContent===`Apply ${e.getRouteModeLabel(mode)}`,`${mode}: selected route Apply label`);
   }
   await e.applySelectedRoute();check(page.ids['btn-generate-ai-route'].textContent===(role==='driver'?`${e.getRouteModeLabel(mode)} — Applied`:'Applied')&&page.ids['btn-generate-ai-route'].disabled,`${role} ${mode}: applied button`);
   check(page.ids['btn-start-navigation'].style.display==='block',`${role} ${mode}: navigation appears after successful apply`);
   const appliedId=e.appliedRoute.routeId;
   let approachRequests=0;e.directionsService={route:()=>{approachRequests++;}};
   e.computeDriverToPickupPath(15,122);
   check(approachRequests===0,`${role} ${mode}: GPS approach cannot replace applied geometry`);
   await e.applySelectedRoute();check(e.appliedRoute.routeId===appliedId,`${role} ${mode}: duplicate apply prevented`);
   const restored=makePage(role,store);await restored.engine.restorePlannerState();
   check(restored.engine.appliedRoute.routeId===appliedId&&restored.engine.currentMode===mode,`${role} ${mode}: page restoration`);
   check(!restored.requests.some(request=>request.url.includes('route_ai.php')),`${role} ${mode}: no regeneration on reopen`);
   const appliedGeometry=JSON.stringify(restored.engine.selectedRoutePolyline.path);
   await restored.engine.startNavigation();await restored.engine.persistQueue;
   check(JSON.stringify(restored.engine.selectedRoutePolyline.path)===appliedGeometry,`${role} ${mode}: exact geometry preserved on Start Navigation`);
   let routeRequests=0;
   restored.engine.directionsService={route:()=>{routeRequests++;}};
   restored.engine.updateUserLocationMarker=()=>{};restored.engine.focusNavigationCamera=()=>{};
   restored.engine.isPositionOnSelectedRoute=()=>false;
   restored.acquireGps('auto');restored.acquireGps(true);
   restored.engine.renderLiveNavigationRoute(15,122);
   check(routeRequests===0,`${role} ${mode}: initial GPS/recenter never requests a route`);
   check(restored.engine.appliedRoute.routeId===appliedId && JSON.stringify(restored.engine.selectedRoutePolyline.path)===appliedGeometry,`${role} ${mode}: GPS/camera preserve applied identity and geometry`);
   check(restored.engine.isNavigating&&restored.engine.currentMode===mode,`${role} ${mode}: applied navigation`);
   restored.engine.selectMode(mode==='fastest'?'shortest':'fastest');check(restored.engine.currentMode===mode,`${role} ${mode}: navigation objective locked`);
   const resumed=makePage(role,store);await resumed.engine.restorePlannerState();await resumed.engine.persistQueue;
   check(JSON.stringify(resumed.engine.selectedRoutePolyline.path)===appliedGeometry,`${role} ${mode}: exact geometry restored after module switch`);
   check(resumed.engine.isNavigating&&resumed.engine.currentRouteData.routeId===appliedId,`${role} ${mode}: navigation resumed`);
   check(!resumed.requests.some(request=>request.url.includes('route_ai.php')),`${role} ${mode}: no route recalculation on resume`);
   resumed.engine.hasReachedPickup=true;resumed.engine.directionsService={route:(request,callback)=>callback(fixture.directions,'OK')};
   resumed.engine.offRouteConsecutiveCount=3;resumed.engine.renderLiveNavigationRoute(14.62,121.02,{confirmedOffRoute:true});await resumed.engine.evaluationPromise;await resumed.engine.persistQueue;
   check(resumed.engine.appliedRoute.mode===mode&&resumed.engine.currentMode===mode,`${role} ${mode}: reroute preserves objective`);
   check(resumed.engine.appliedRoute.routeId!==appliedId&&store.record.lifecycle==='NAVIGATING',`${role} ${mode}: reroute snapshot persists`);
   check(resumed.engine.currentEvaluationData.selectedIndex===winner,`${role} ${mode}: reroute optimizes its own candidates`);
  }
  const store={record:null,active:0,starts:0},page=makePage(role,store);page.engine.processGoogleDirectionsResult(fixture.directions);await page.engine.evaluationPromise;if(role==='driver')page.engine.selectMode('balanced');await page.engine.applySelectedRoute();
  const firstId=page.engine.appliedRoute.routeId;page.engine.selectMode('shortest');await page.engine.applySelectedRoute();
  check(page.engine.appliedRoute.routeId!==firstId&&store.record.state_data.applied.mode==='shortest',`${role}: another applied strategy allowed before navigation`);
 }
 const store={record:null,active:0,starts:0},viewer=makePage('fleet_manager',store);viewer.engine.processGoogleDirectionsResult(fixture.directions);await viewer.engine.evaluationPromise;await viewer.engine.applySelectedRoute();
 check(!viewer.engine.appliedRoute&&viewer.ids['btn-generate-ai-route'].disabled,'Read-only role cannot apply routes');
 const driverStore={record:null,active:0,starts:0},driver=makePage('driver',driverStore),engine=driver.engine;
 engine.updateApplyButton();check(driver.ids['btn-generate-ai-route'].textContent==='Generate ROUTETHINK Route','Empty planner is not incorrectly marked Applied');
 engine.processGoogleDirectionsResult(fixture.directions);await engine.evaluationPromise;await engine.persistQueue;
 engine.selectMode('balanced');await engine.persistQueue;
 const persist=engine.persistPlannerState.bind(engine);let release;
 engine.persistPlannerState=()=>new Promise(resolve=>{release=resolve;});
 const applying=engine.applySelectedRoute();await new Promise(resolve=>setImmediate(resolve));
 check(driver.ids['btn-start-navigation'].style.display==='none','Navigation remains hidden while Apply is saving');
 engine.selectMode('shortest');check(engine.currentMode==='balanced','Selection cannot change during Apply');
 release();await applying;engine.persistPlannerState=persist;await persist();
 check(driver.ids['btn-generate-ai-route'].textContent==='Balance — Applied','Confirmed Balance status');
 const confirmedId=engine.appliedRoute.routeId;
 engine.selectMode('shortest');await engine.persistQueue;
 check(driver.ids['btn-start-navigation'].style.display==='none','Unapplied preview cannot start navigation');
 const returned=makePage('driver',driverStore);await returned.engine.restorePlannerState();
 check(returned.engine.currentMode==='balanced'&&returned.engine.currentRouteData.routeId===confirmedId,'Returning from another module restores applied route instead of unapplied preview');
 engine.persistPlannerState=async()=>{throw new Error('Simulated save failure');};
 await engine.applySelectedRoute();
 check(engine.appliedRoute.routeId===confirmedId,'Failed Apply preserves previously confirmed route');
 check(driver.ids['btn-start-navigation'].style.display==='none','Failed Apply does not enable navigation for preview');
 for(const mode of ['fastest','shortest','fuelEfficient','balanced']){
  const store={record:null,active:0,starts:0},page=makePage('driver',store),e=page.engine;
  e.processGoogleDirectionsResult(fixture.directions);await e.evaluationPromise;await e.persistQueue;
  e.selectMode(mode);await e.persistQueue;
  e.evaluationPromise=new Promise(()=>{});
  const before=page.requests.length;
  await e.applySelectedRoute();
  check(Boolean(e.appliedRoute)&&!e.applyingRoute,`${mode}: Apply does not wait on stalled background evaluation`);
  check(!page.requests.slice(before).some(r=>r.url.includes('route_ai.php')),`${mode}: Apply never calls evaluation/regeneration API`);
 }
 {
  const store={record:null,active:0,starts:0},page=makePage('driver',store),e=page.engine;
  e.processGoogleDirectionsResult(fixture.directions);await e.evaluationPromise;await e.persistQueue;
  const before=page.requests.filter(r=>r.body && r.url.includes('route-planner-state.php')).length;
  e.selectMode('fastest');e.selectMode('shortest');e.selectMode('fuelEfficient');
  await e.applySelectedRoute();
  const sent=page.requests.filter(r=>r.body && r.url.includes('route-planner-state.php')).length-before;
  check(sent===1,'Three superseded previews collapse into one applied save');
  check(e.appliedRoute.mode==='fuelEfficient','Coalescing preserves the last selected route');
 }
 {
  let release;const store={record:null,active:0,starts:0,evaluationGate:new Promise(resolve=>{release=resolve;})};
  const page=makePage('driver',store),e=page.engine;
  e.processGoogleDirectionsResult(fixture.directions);e.selectMode('fastest');
  const id=e.currentRouteData.routeId,geometry=JSON.stringify(e.selectedRoutePolyline.path),evaluations=JSON.stringify(e.modeEvaluations);
  release();await e.evaluationPromise;
  check(e.currentRouteData.routeId===id && JSON.stringify(e.selectedRoutePolyline.path)===geometry,'Late AI response cannot replace explicitly selected preview');
  check(JSON.stringify(e.modeEvaluations)===evaluations,'Late AI response cannot remap route choices after selection');
  await e.applySelectedRoute();check(e.appliedRoute.routeId===id,'Apply saves exact pre-evaluation selection');
 }
 {
  const store={record:null,active:0,starts:0},page=makePage('driver',store),e=page.engine;let approachCallback;
  e.updateUserLocationMarker=()=>{};e.directionsRenderer={setDirections(){},setOptions(){},setRouteIndex(){}};
  e.directionsService={route:(request,callback)=>{if(typeof request.origin==='object')approachCallback=callback;else callback(fixture.directions,'OK');}};
  e.generateRoute();await new Promise(resolve=>setImmediate(resolve));
  check(e.isGenerating && !e.currentRouteData,'Generate waits for current GPS-to-terminal routing before showing choices');
  e.selectMode('shortest');check(!e.routeSelectionConfirmed,'Incomplete generation cannot accept an early route choice');
  approachCallback({routes:[fixture.directions.routes[0]]},'OK');await new Promise(resolve=>setImmediate(resolve));
  check(!e.isGenerating && e.prePickupPath.length>0 && e.currentRouteData,'Generation includes pickup leg before route selection');
  e.selectMode('fastest');await e.applySelectedRoute();
  check(e.appliedRoute.geometry.length>fixture.directions.routes[0].overview_path.length,'Applied geometry includes Driver-to-A and A-to-B');
 }
 for(const conflict of ['preview','applied','navigation']){
  const store={record:null,active:0,starts:0},page=makePage('driver',store),e=page.engine;
  e.processGoogleDirectionsResult(fixture.directions);await e.evaluationPromise;e.selectMode('fastest');await e.persistQueue;
  store.record.revision++;
  if(conflict==='applied'){store.record.state_data.applied={routeId:'other-applied-route'};store.record.lifecycle='APPLIED';}
  if(conflict==='navigation')store.record.lifecycle='NAVIGATING';
  await e.applySelectedRoute();
  check(conflict==='preview'?e.appliedRoute?.mode==='fastest':!e.appliedRoute,`${conflict}: stale Apply retries only unchanged unapplied preview`);
  if(conflict==='applied')check(store.record.state_data.applied.routeId==='other-applied-route','Conflict recovery never overwrites another applied route');
 }
 {
  const store={record:null,active:0,starts:0},page=makePage('driver',store),e=page.engine;
  e.processGoogleDirectionsResult(fixture.directions);await e.evaluationPromise;e.selectMode('fastest');await e.persistQueue;
  // PostgreSQL jsonb returns key order different from plannerInputs().
  store.record.state_data.inputs=Object.fromEntries(Object.entries(store.record.state_data.inputs).sort(([a],[b])=>a.localeCompare(b)));
  const restored=makePage('driver',store);await restored.engine.restorePlannerState();restored.engine.selectMode('fuelEfficient');
  const selectedId=restored.engine.currentRouteData.routeId;let generations=0;
  restored.engine.directionsService={route:()=>{generations++;}};
  await restored.engine.generateRoute();
  check(generations===0,'Apply after jsonb restoration never treats reordered keys as changed inputs');
  check(restored.engine.appliedRoute?.routeId===selectedId,'Real Apply button handler saves restored selection without regenerating');
  check(!restored.engine.plannerInputsMatch({...restored.engine.plannerInputs(),destination:'Changed'},restored.engine.generatedInputs),'Actual changed input still requires generation');
 }
 {
  const store={record:null,active:0,starts:0},page=makePage('driver',store),e=page.engine;
  e.processGoogleDirectionsResult(fixture.directions);await e.evaluationPromise;e.prePickupRoutes=fixture.directions.routes;
  e.selectMode('fastest');await e.applySelectedRoute();
  const restored=makePage('driver',store);await restored.engine.restorePlannerState();restored.engine.selectMode('shortest');
  check(restored.engine.prePickupRoutes.length===3,'GPS pickup alternatives persist across module changes');
  check(restored.engine.prePickupSelectedIndex===1,'Restored pickup leg switches with shortest mode before Apply');
 }
 {
  const store={record:null,active:0,starts:0},page=makePage('driver',store),e=page.engine;
  e.processGoogleDirectionsResult(fixture.directions);await e.evaluationPromise;e.selectMode('fuelEfficient');await e.applySelectedRoute();
  const restored=makePage('driver',store);let markerUpdates=0,routeRequests=0,cameraFocus=0;
  restored.engine.updateUserLocationMarker=()=>{markerUpdates++;};restored.engine.focusNavigationCamera=()=>{cameraFocus++;};
  restored.engine.directionsService={route:()=>{routeRequests++;}};restored.engine.acquireNavigationPosition=restored.acquireGps;
  await restored.engine.restorePlannerState();
  check(markerUpdates===1 && restored.engine.hasLiveGpsFix,'Refresh automatically restores blue dot from a fresh GPS fix');
  check(routeRequests===0 && cameraFocus===0,'Restored GPS marker does not regenerate route or zoom away from its bounds');
  check(restored.engine.appliedRoute.routeId===e.appliedRoute.routeId && JSON.stringify(restored.engine.selectedRoutePolyline.path)===JSON.stringify(e.selectedRoutePolyline.path),'GPS restoration preserves exact applied identity and geometry');
 }
 {
  const store={record:null,active:0,starts:0},page=makePage('driver',store),e=page.engine;
  e.processGoogleDirectionsResult(fixture.directions);await e.evaluationPromise;e.prePickupRoutes=fixture.directions.routes;
  for(const [mode,winner] of Object.entries({fastest:0,shortest:1,fuelEfficient:2,balanced:2})){
   e.selectMode(mode);
   check(e.prePickupSelectedIndex===winner,`${mode}: Driver-to-A uses matching optimization objective`);
   check(e.currentEvaluationData.selectedIndex===winner,`${mode}: A-to-B uses matching optimization objective`);
   const expected=[...fixture.directions.routes[winner].overview_path,...fixture.directions.routes[winner].overview_path.slice(1)];
   check(JSON.stringify(e.selectedRoutePolyline.path)===JSON.stringify(expected),`${mode}: both selected road segments draw together immediately`);
   check(page.ids['route-origin-input'].value==='Origin' && page.ids['route-dest-input'].value==='Destination',`${mode}: terminal locations remain assigned locations`);
  }
  await e.persistQueue;
 }
 {
  const store={record:null,active:0,starts:0},page=makePage('driver',store),e=page.engine;let late;
  e.acquireDriverPlanningPosition=()=>Promise.resolve();e.directionsRequestTimeoutMs=10;
  e.directionsRenderer={setDirections(){},setOptions(){},setRouteIndex(){}};
  e.directionsService={route:(request,callback)=>{late=callback;}};
  e.generateRoute();await new Promise(resolve=>setTimeout(resolve,25));
  check(!e.isGenerating && !page.ids['btn-generate-ai-route'].disabled,'Unresponsive Maps provider releases Generate loading state');
  await late(fixture.directions,'OK');
  check(!e.currentRouteData,'Late Maps response after timeout cannot replace planner state');
 }
 for(const fault of ['blockStart','startQueue']){
  const store={record:null,active:0,starts:0},page=makePage('driver',store),e=page.engine;
  e.processGoogleDirectionsResult(fixture.directions);await e.evaluationPromise;await e.persistQueue;
  e.selectMode('fastest');await e.applySelectedRoute();await e.persistQueue;
  const identity=e.appliedRoute.routeId,geometry=JSON.stringify(e.selectedRoutePolyline.path);
  e.routeRequestTimeoutMs=10;
  if(fault==='blockStart')store.blockStart=true;else e.persistQueue=new Promise(()=>{});
  await e.startNavigation();
  check(!e.navigationStarting && !e.isNavigating,`${fault}: no stuck loading or false navigation success`);
  check(e.routeSaveUncertain && page.ids['btn-start-navigation'].disabled,`${fault}: reload required to reconcile uncertain save`);
  check(e.appliedRoute.routeId===identity && JSON.stringify(e.selectedRoutePolyline.path)===geometry,`${fault}: exact applied route preserved on timeout`);
  check(store.starts===0,`${fault}: no unconfirmed start or automatic retry`);
 }
 for(const fault of ['blockSave','failSave','queue']){
  const store={record:null,active:0,starts:0},page=makePage('driver',store),e=page.engine;
  e.processGoogleDirectionsResult(fixture.directions);await e.evaluationPromise;await e.persistQueue;e.selectMode('fastest');await e.persistQueue;
  e.routeRequestTimeoutMs=15;e.routeApplyTimeoutMs=35;
  let releaseQueue;
  if(fault==='queue')e.persistQueue=new Promise(resolve=>{releaseQueue=resolve;});else store[fault]=true;
  await e.applySelectedRoute();
  check(!e.applyingRoute && !e.appliedRoute,`${fault}: failed Apply always leaves loading and never reports success`);
  check(page.ids['btn-start-navigation'].style.display==='none',`${fault}: failed save cannot start navigation`);
  check(store.toasts.some(t=>t[0]==='Apply Failed' && (fault==='failSave'?t[1].includes('Database unavailable'):t[1].includes('timed out'))),`${fault}: actual error/timeout is displayed`);
  if(releaseQueue){const before=page.requests.length;releaseQueue();await e.persistQueue;check(page.requests.length===before,'Expired queued Apply cannot save later');}
 }
 console.log(`${checks} lifecycle checks passed across Admin, Dispatcher, Driver and read-only access. Routing-provider responses are controlled fixtures; no real trips are changed.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
