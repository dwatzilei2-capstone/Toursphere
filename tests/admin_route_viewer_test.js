const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(`${__dirname}/../js/map-route.js`,'utf8');
const start=source.indexOf('  restoreViewedTripRoute() {');
const end=source.indexOf('\n  getVehicleEconomy(',start);
const road=[{lat:14.6,lng:121},{lat:14.61,lng:121.01},{lat:14.62,lng:121.02}];
let requests=0,checks=0;
function check(ok,label){assert.ok(ok,label);checks++;}
function page(saved){
 const ids={};const element=()=>({textContent:'',style:{},replaceChildren(){},appendChild(){}});
 const sandbox={window:{TC_ROUTE_CONTEXT:{readOnly:true,tripStatus:'Completed',savedRoute:saved}},
  document:{getElementById:id=>ids[id] ||= element(),createElement:element},
  fetch:()=>{requests++;throw Error('Historical viewing must not request a new route');},
  google:{maps:{Polyline:class {constructor(options){this.path=options.path;}setMap(){}},Marker:class {},LatLngBounds:class {extend(){}},geometry:{encoding:{decodePath:()=>road}}}}};
 const engine=vm.runInNewContext('({'+source.slice(start,end)+'})',sandbox);
 engine.lockTripRouteViewer=()=>{engine.locked=true;};engine.getModeTitle=()=> 'Fastest';engine.hydrateDirections=value=>value;
 engine.googleMarkers=[];engine.map={fitBounds(){engine.fitted=true;}};
 return {engine,ids};
}
for(const kind of ['steps','encodedSteps','overview','encodedOverview']){
 const route={legs:[{start_location:road[0],end_location:road[2],steps:[]}]};
 if(kind==='steps')route.legs[0].steps=[{path:road}];
 if(kind==='encodedSteps')route.legs[0].steps=[{polyline:{points:'saved-polyline'}}];
 if(kind==='overview')route.overview_path=road;
 if(kind==='encodedOverview')route.overview_polyline={points:'saved-polyline'};
 const saved={mode:'fastest',data:{distanceKm:12.83},evaluation:{selectedIndex:0},directions:{routes:[route]}};
 const {engine,ids}=page(saved);
 // The snapshot remains available until delayed Maps initialization calls the viewer.
 engine.restoreViewedTripRoute();
 check(JSON.stringify(engine.selectedRoutePolyline.path)===JSON.stringify(road),`${kind}: exact saved geometry renders`);
 check(engine.locked && engine.fitted,`${kind}: read-only map fits saved route`);
 check(engine.googleMarkers.length===2,`${kind}: saved route endpoints render`);
 check(ids['ai-res-badge'].textContent==='Saved outbound route',`${kind}: outbound identity is explicit`);
 check(!engine.isNavigating,`${kind}: admin viewing never starts navigation`);
}
for(const saved of [null,{evaluation:{selectedIndex:0},directions:{routes:[{legs:[]}]}}]){
 const {engine,ids}=page(saved);engine.restoreViewedTripRoute();
 check(ids['ai-res-title'].textContent.includes('unavailable'),'Missing historical data gives an explicit message');
 check(!engine.selectedRoutePolyline,'Missing history is not replaced with a newly generated route');
}
check(requests===0,'Viewing saved geometry uses no routing or save request');
console.log(`${checks} admin historical-route rendering checks passed.`);
