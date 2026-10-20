const fs=require('fs'),assert=require('assert/strict'),cp=require('child_process');
const {chromium}=require('C:/Users/LAPTOP-3DS/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const counts=()=>JSON.parse(cp.execFileSync('C:/xampp/php/php.exe',['tests/cost_by_vehicle_session.php','--counts'],{encoding:'utf8'}));
(async()=>{const before=counts(),browser=await chromium.launch({headless:true,channel:'msedge'});try{
 const ctx=await browser.newContext();const s=JSON.parse(fs.readFileSync('tmp/cost-by-vehicle-session.json','utf8'));await ctx.addCookies([{name:'PHPSESSID',value:s.fleet_admin,domain:'localhost',path:'/'}]);
 const page=await ctx.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 const base='http://localhost/fleet/modules/cost-analysis/cost-by-vehicle.php';
 await page.goto(base+'?source=preview&from=2026-09-01&to=2026-09-30&vehicle=TEST-VC-V01&view=3');
 assert.equal(await page.locator('h1').innerText(),'Cost by Vehicle');assert.equal(await page.locator('.vc-section').count(),4);
 assert.equal(await page.locator('.vc-analysis tbody tr').count(),5);assert.equal(await page.locator('.vc-chart canvas').count(),4);
 assert.equal(await page.locator('.vc-funding th').count(),8);assert.ok(!(await page.locator('#content-container').innerText()).includes('Passenger'));
 const headers=await page.locator('th').allTextContents();assert.ok(!headers.some(t=>/Actions|View|Trend/.test(t)));
 const payload=await page.evaluate(()=>window.TC_VEHICLE_COST_DATA);assert.equal(payload.series.length,3);assert.equal(payload.series[2].count,3);
 const fixture=JSON.parse(fs.readFileSync('database/preview/cost-by-vehicle.json','utf8'));const latest=fixture.deliveries.filter(r=>r.vehicle_id==='TEST-VC-V01'&&r.date.startsWith('2026-09'));
 const total=latest.reduce((sum,r)=>sum+r.fuel+r.maintenance+r.toll+r.other,0)/100;
 assert.ok((await page.locator('.vc-analysis tbody tr').first().innerText()).includes(total.toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2})));
 assert.equal(await page.evaluate(()=>Chart.getChart('vc-profit').config.type),'bar');assert.equal(await page.evaluate(()=>Chart.getChart('vc-profit').data.datasets.length),1);assert.ok(!(await page.locator('.vc-analysis').innerText()).match(/Revenue|Profit/));assert.ok(!(await page.locator('.vc-kpis').innerText()).match(/Revenue|Profit/));
 for(const [width,height] of [[1920,1080],[1536,1024],[1024,900],[768,1024],[390,844],[320,700]]){
 await page.setViewportSize({width,height});await page.waitForFunction(()=>document.documentElement.scrollWidth<=innerWidth+1,{}, {timeout:3000});await page.evaluate(()=>scrollTo(0,0));await page.screenshot({path:`storage/cost-by-vehicle-${width}.png`,fullPage:true});
 assert.ok(await page.evaluate(()=>[...document.querySelectorAll('.vc-plot')].every(e=>e.clientWidth>120&&e.clientHeight>=130)),'Chart sizes');}
 await page.goto(base+'?source=preview&from=2026-09-01&to=2026-09-30&vehicle=TEST-VC-V04&view=3');
 assert.equal(await page.evaluate(()=>window.TC_VEHICLE_COST_DATA.series.length),2,'Newly acquired vehicle uses available Aug–Sep history');
 assert.ok((await page.locator('.vc-efficiency').innerText()).includes('Not available'),'Missing distance/consumption');
 await page.goto(base+'?source=preview&from=2026-09-01&to=2026-09-30&vehicle=TEST-VC-V05&view=3');assert.equal(await page.locator('.vc-chart canvas').count(),0);assert.equal(await page.locator('.vc-chart .vc-empty').count(),4);
 await page.goto(base+'?source=preview&from=2025-01-01&to=2026-09-30&comparison=yearly&view=selected&vehicle=TEST-VC-V01');assert.equal(await page.evaluate(()=>window.TC_VEHICLE_COST_DATA.series.length),2);assert.ok(await page.locator('.vc-history-note').isVisible(),'Partial-year comparisons suppressed');
 await page.goto(base+'?source=preview&from=2027-01-01&to=2027-12-31&view=selected');assert.equal(await page.locator('.vc-chart canvas').count(),0,'Future records excluded');
 await page.goto(base+'?source=preview&from=2026-09-01&to=2026-09-30&type=Cargo+Van');assert.equal(await page.locator('.vc-analysis tbody tr').count(),1,'Type filter');
 await page.goto(base+'?source=live&from=2026-01-01&to=2026-10-15&view=selected');assert.equal(await page.locator('h1').count(),1,'Live page renders: '+(await page.locator('body').innerText()).slice(0,600));assert.ok((await page.locator('.vc-source').innerText()).includes('LIVE RECORDS'));assert.ok(!(await page.locator('.vc-analysis').innerText()).includes('TEST-VC-'));
 assert.deepEqual(errors,[]);assert.deepEqual(counts(),before,'Operational table counts unchanged');
 console.log('Cost by Vehicle browser checks passed: four sections/charts, calculations, funding, monthly/yearly/missing/future cases, type filtering, live/preview separation, responsive widths 320–1920px, no operational inserts.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1)});
