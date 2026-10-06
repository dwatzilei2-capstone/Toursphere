const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const {webcrypto}=require('node:crypto');
const source=fs.readFileSync(`${__dirname}/../js/map-route.js`,'utf8');
const fixture=JSON.parse(fs.readFileSync(`${__dirname}/../tmp/route-planner-evaluations.json`,'utf8'));
let checks=0;
function check(value,label){assert.ok(value,label);checks++;}
class LatLng {constructor(lat,lng){this.a=lat;this.b=lng;}lat(){return this.a;}lng(){return this.b;}equals(other){return this.a===other.lat()&&this.b===other.lng();}toJSON(){return {lat:this.a,lng:this.b};}}
class Polyline {constructor(options){this.path=options.path;this.map=options.map;}setMap(map){this.map=map;}getPath(){return {getLength:()=>this.path.length};}}
function element(value=''){return {value,style:{},dataset:{},classList:{add(){},remove(){},toggle(){}},setAttribute(){},addEventListener(){},querySelector(){return null;},querySelectorAll(){return [];},replaceChildren(){},appendChild(){},textContent:'',innerHTML:''};}
function makePage(role,store){
 const ids={};for(const id of ['route-origin-input','route-dest-input','route-vehicle-select','btn-generate-ai-route','btn-start-navigation','map-card-container','ai-res-title','ai-res-distance','ai-res-duration','ai-res-fuel','ai-res-score','ai-res-reason','waypoints-container'])ids[id]=element();
 ids['route-origin-input'].value='Origin';ids['route-dest-input'].value='Destination';ids['route-vehicle-select'].value='Test vehicle';
 ids['route-vehicle-select'].selectedOptions=[{dataset:{vehicleId:'TEST-V',consumption:'9.5',fuelPrice:'95.95',capacity:'15'}}];
 const cards=['balanced','fastest','fuelEfficient','shortest'].map(mode=>({...element(),dataset:{mode}}));
 const document={getElementById:id=>ids[id]||null,querySelectorAll:selector=>selector.includes('opt-option-card')?cards:[],querySelector:()=>null,createElement:()=>({...element(),remove(){}}),addEventListener(){}};
 const window={TC_BASE_URL:'/fleet',TC_ROUTE_CONTEXT:{tripId:'TEST-T',vehicleId:'TEST-V',tripStatus:'Dispatched',stateCsrf:'test',canApply:role!=='fleet_manager',isDriver:role==='driver',passengerCount:5},addEventListener(){},showAppToast(){}};
 const requests=[];
 const fetch=async(url,options={})=>{
  requests.push({url,body:options.body});
  if(url.includes('route_ai.php')){
   const current=options.body.get('mode');const evaluation=fixture.modeEvaluations[current];
   return {ok:true,json:async()=>({ok:true,data:{...evaluation,vehicleSpecs:fixture.vehicleSpecs,modeEvaluations:fixture.modeEvaluations,modeSelections:Object.fromEntries(Object.entries(fixture.modeEvaluations).map(([key,result])=>[key,result.selectedIndex]))}})};
  }
  if(url.includes('route-start.php')){
   assert.equal(options.body.get('route_id'),store.record.state_data.applied.routeId);
   assert.equal(options.body.get('mode'),store.record.state_data.applied.mode);
   store.record.lifecycle='NAVIGATING';store.active=1;store.starts++;
   return {ok:true,json:async()=>({ok:true,log_id:'TEST-LOG',already_saved:store.starts>1})};
  }
  assert.ok(url.includes('route-planner-state.php'));
  if(!options.body)return {ok:true,json:async()=>({ok:true,state:store.record,trip_status:'In Transit',navigation_active:store.active})};
  assert.equal(Number(options.body.get('revision')),store.record?.revision||0);
  store.record={revision:(store.record?.revision||0)+1,lifecycle:options.body.get('lifecycle'),state_data:JSON.parse(options.body.get('state'))};
  return {ok:true,json:async()=>({ok:true,revision:store.record.revision})};
 };
 const coordinate=(point,key)=>typeof point[key]==='function'?point[key]():point[key];
 const sandbox={window,document,google:{maps:{LatLng,Polyline,LatLngBounds:class{},TravelMode:{DRIVING:'DRIVING'},TrafficModel:{BEST_GUESS:'BEST_GUESS'},DirectionsStatus:{OK:'OK'},geometry:{spherical:{computeDistanceBetween:(a,b)=>Math.hypot(coordinate(a,'lat')-coordinate(b,'lat'),coordinate(a,'lng')-coordinate(b,'lng'))*111320}}}},navigator:{},crypto:webcrypto,fetch,FormData,URLSearchParams,console,setTimeout,clearTimeout};
 vm.createContext(sandbox);vm.runInContext(source.slice(0,source.indexOf('window.aiRouteEngine ='))+'\nthis.RouteEngine=AIRouteEngine;',sandbox);
 const engine=new sandbox.RouteEngine();engine.map={};engine.fitMapBounds=()=>{};engine.acquireNavigationPosition=()=>{};
 return {engine,ids,requests,window};
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
   await e.applySelectedRoute();check(page.ids['btn-generate-ai-route'].textContent==='Applied'&&page.ids['btn-generate-ai-route'].disabled,`${role} ${mode}: applied button`);
   const appliedId=e.appliedRoute.routeId;await e.applySelectedRoute();check(e.appliedRoute.routeId===appliedId,`${role} ${mode}: duplicate apply prevented`);
   const restored=makePage(role,store);await restored.engine.restorePlannerState();
   check(restored.engine.appliedRoute.routeId===appliedId&&restored.engine.currentMode===mode,`${role} ${mode}: page restoration`);
   check(!restored.requests.some(request=>request.url.includes('route_ai.php')),`${role} ${mode}: no regeneration on reopen`);
   await restored.engine.startNavigation();await restored.engine.persistQueue;
   check(restored.engine.isNavigating&&restored.engine.currentMode===mode,`${role} ${mode}: applied navigation`);
   restored.engine.selectMode(mode==='fastest'?'shortest':'fastest');check(restored.engine.currentMode===mode,`${role} ${mode}: navigation objective locked`);
   const resumed=makePage(role,store);await resumed.engine.restorePlannerState();await resumed.engine.persistQueue;
   check(resumed.engine.isNavigating&&resumed.engine.currentRouteData.routeId===appliedId,`${role} ${mode}: navigation resumed`);
   check(!resumed.requests.some(request=>request.url.includes('route_ai.php')),`${role} ${mode}: no route recalculation on resume`);
   resumed.engine.hasReachedPickup=true;resumed.engine.directionsService={route:(request,callback)=>callback(fixture.directions,'OK')};
   resumed.engine.renderLiveNavigationRoute(14.62,121.02);await resumed.engine.evaluationPromise;await resumed.engine.persistQueue;
   check(resumed.engine.appliedRoute.mode===mode&&resumed.engine.currentMode===mode,`${role} ${mode}: reroute preserves objective`);
   check(resumed.engine.appliedRoute.routeId!==appliedId&&store.record.lifecycle==='NAVIGATING',`${role} ${mode}: reroute snapshot persists`);
   check(resumed.engine.currentEvaluationData.selectedIndex===winner,`${role} ${mode}: reroute optimizes its own candidates`);
  }
  const store={record:null,active:0,starts:0},page=makePage(role,store);page.engine.processGoogleDirectionsResult(fixture.directions);await page.engine.evaluationPromise;await page.engine.applySelectedRoute();
  const firstId=page.engine.appliedRoute.routeId;page.engine.selectMode('shortest');await page.engine.applySelectedRoute();
  check(page.engine.appliedRoute.routeId!==firstId&&store.record.state_data.applied.mode==='shortest',`${role}: another applied strategy allowed before navigation`);
 }
 const store={record:null,active:0,starts:0},viewer=makePage('fleet_manager',store);viewer.engine.processGoogleDirectionsResult(fixture.directions);await viewer.engine.evaluationPromise;await viewer.engine.applySelectedRoute();
 check(!viewer.engine.appliedRoute&&viewer.ids['btn-generate-ai-route'].disabled,'Read-only role cannot apply routes');
 console.log(`${checks} lifecycle checks passed across Admin, Dispatcher, Driver and read-only access. Routing-provider responses are controlled fixtures; no real trips are changed.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
