const fs=require('node:fs'),assert=require('node:assert/strict');
const {chromium}=require('C:/Users/LAPTOP-3DS/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const sessions=JSON.parse(fs.readFileSync(`${__dirname}/../tmp/assignment-test-sessions.json`,'utf8'));
(async()=>{
 const browser=await chromium.launch({headless:true,channel:'msedge'});let checks=0;
 const check=(ok,label)=>{assert.ok(ok,label);checks++;};
 try{
  const context=await browser.newContext();await context.addCookies([{name:'PHPSESSID',value:sessions.fleet_admin,domain:'localhost',path:'/'}]);
  const page=await context.newPage();const errors=[];let writes=0,routeRequests=0;
  // Fail closed before the external lookup unless this is the user's public-location practice request.
  const preflight=await context.request.get('http://localhost/fleet/actions/reservation-availability.php?id=RES-2026-034');
  const practice=(await preflight.json()).reservation;
  assert.equal(practice.origin,'Manila City Hall, Manila, Metro Manila, Philippines');
  assert.equal(practice.destination,'Makati City Hall, Makati, Metro Manila, Philippines');
  assert.ok(practice.special_handling.includes('Local system test only; no actual delivery.'));
  page.on('pageerror',e=>errors.push(e.message));page.on('request',r=>{if(r.method()==='POST')writes++;if(/route_ai\.php|route-preparation\.php|route-start\.php/.test(r.url()))routeRequests++;});
  const routeState=async()=>{const response=await context.request.get('http://localhost/fleet/actions/route-planner-state.php?trip_id=TRP-024');assert.ok(response.ok());return (await response.json()).state;};
  const before=await routeState();
  await page.goto('http://localhost/fleet/modules/vehicle-reservation-dispatch/reservations.php',{waitUntil:'domcontentloaded'});
  const responsePromise=page.waitForResponse(r=>r.url().includes('/actions/trip-funding.php')&&r.request().method()==='GET');
  await page.evaluate(()=>TripFunding.open('RES-2026-034','TRP-024'));
  const response=await responsePromise;check(response.ok(),'Real Trip Funding endpoint responds');const payload=await response.json();check(payload.ok,'Real funding estimate succeeds');
  const data=payload.data,e=data.estimate;
  check(Number(e.distance_km)>0,'Google driving distance calculated automatically');
  check(Number(e.expected_km_per_liter)>0,'Configured vehicle planning efficiency available');
  check(e.estimated_liters>0&&e.estimated_fuel_cost>0,'Fuel quantity and expense calculated');
  check(Math.abs(e.estimated_fuel_cost-Math.round(e.distance_km/e.expected_km_per_liter*e.reference_price*100)/100)<0.001,'Unrounded inputs produce correct rounded cost');
  check(e.estimated_total_cost===e.estimated_fuel_cost,'Transportation estimate counts fuel once');
  check(e.toll==='Not Included in Estimate'&&e.parking==='Not Included in Estimate','Unsupported toll/parking excluded');
  check(e.issues.length===0,'No distance or efficiency blocker remains');
  await page.locator('#funding-request-form').waitFor();
  check(Number(await page.locator('#funding-amount').inputValue())===e.estimated_total_cost,'Requested amount defaults to estimate');
  check(await page.locator('#funding-fuel-amount, [name=expected_km_per_liter], [name=efficiency_reference]').count()===0,'Unnecessary fuel allocation and manual efficiency inputs removed');
  check(await page.locator('#funding-request-form input, #funding-request-form select, #funding-request-form textarea').count()===2,'Request form keeps only funding method and requested amount');
  for(const width of [1440,390]){
   await page.setViewportSize({width,height:900});
   const text=await page.locator('#trip-funding-body').innerText();
   check(text.includes(Number(e.distance_km).toFixed(2)+' km'),`Distance shown at ${width}px`);
   check(text.includes(Number(e.estimated_total_cost).toFixed(2)),`Cost shown at ${width}px`);
   check(text.includes('planning estimate'),`Assumption labelled at ${width}px`);
   check(await page.locator('#trip-funding-modal').evaluate(el=>el.scrollWidth<=el.clientWidth+1),`No horizontal overflow at ${width}px`);
  }
  check(JSON.stringify(await routeState())===JSON.stringify(before),'Driver route state remains unchanged');
  check(data.status==='Not Requested'&&!data.request,'Opening estimate does not submit/approve funding');
  check(writes===0&&routeRequests===0,'No Apply Route, navigation or funding POST requests');
  check(errors.length===0,`No browser errors: ${errors.join(', ')}`);
  console.log(JSON.stringify({trip:data.trip.id,distance_km:e.distance_km,efficiency_km_l:e.expected_km_per_liter,fuel_liters:e.estimated_liters,reference_price:e.reference_price,estimated_cost:e.estimated_total_cost,funding_status:data.status}));
  await context.close();console.log(`${checks} live automatic-funding browser checks passed. Driver route untouched; no funding request submitted.`);
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
