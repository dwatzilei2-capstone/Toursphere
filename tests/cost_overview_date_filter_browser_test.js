const fs=require('fs'),assert=require('assert/strict');
const {chromium}=require('C:/Users/LAPTOP-3DS/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
(async()=>{const browser=await chromium.launch({headless:true,channel:'msedge'});try{
 const ctx=await browser.newContext();const session=JSON.parse(fs.readFileSync('tmp/cost-overview-session.json','utf8'));
 await ctx.addCookies([{name:'PHPSESSID',value:session.fleet_admin,domain:'localhost',path:'/'}]);
 const page=await ctx.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));let navigations=0;page.on('request',request=>{if(request.isNavigationRequest()&&request.frame()===page.mainFrame())navigations++;});
 await page.goto('http://localhost/fleet/modules/cost-analysis/cost-overview.php?source=preview&from=2026-01-01&to=2026-10-15');
 const initialNav=navigations;await page.evaluate(()=>window.pickerTestMarker='preserved');
 const trigger=()=>page.locator('.co-range-trigger'),popup=()=>page.locator('#co-date-popover');
 assert.ok((await trigger().innerText()).includes('Jan 1, 2026'));assert.equal(await popup().isVisible(),false);
 await trigger().click();assert.equal(await page.locator('[data-preset]').count(),5);
 const today=await page.locator('.co-date-filter').getAttribute('data-today');const [y,m]=today.split('-').map(Number);
 const monthEnd=new Date(Date.UTC(y,m,0)).toISOString().slice(0,10),lastStart=new Date(Date.UTC(y,m-2,1)).toISOString().slice(0,10),lastEnd=new Date(Date.UTC(y,m-1,0)).toISOString().slice(0,10);
 for(const [key,from,to] of [['this-month',today.slice(0,7)+'-01',monthEnd],['last-month',lastStart,lastEnd],['this-year',y+'-01-01',y+'-12-31'],['last-year',(y-1)+'-01-01',(y-1)+'-12-31']]){
   await page.locator(`[data-preset="${key}"]`).click();assert.equal(await page.locator('[name="from"]').inputValue(),from);assert.equal(await page.locator('[name="to"]').inputValue(),to);
 }
 await page.locator('[data-preset="last-year"]').click();assert.equal(await page.locator('[name="from"]').inputValue(),'2025-01-01');
 await page.locator('[data-date-cancel]').click();assert.equal(await page.locator('[name="from"]').inputValue(),'2026-01-01');
 await trigger().click();await page.locator('[data-preset="last-year"]').click();await popup().locator('[type="submit"]').click();
 await page.waitForFunction(()=>document.querySelector('.co-date-status')?.textContent==='Date range updated.');
 assert.equal(await page.locator('[name="from"]').inputValue(),'2025-01-01');assert.ok(page.url().includes('to=2025-12-31'));
 assert.equal(navigations,initialNav,'No full document navigation');assert.equal(await page.evaluate(()=>window.pickerTestMarker),'preserved');
 assert.equal(await page.locator('.co-kpi strong').first().innerText(),'₱165,732.00');assert.equal(await page.evaluate(()=>Chart.getChart('allowance-cost').data.labels.length),12);
 await trigger().click();await page.locator('[data-preset="custom"]').click();
 await page.locator('[name="from"]').fill('2025-03-06');await page.locator('[name="from"]').dispatchEvent('change');
 await page.locator('[name="to"]').fill('2025-03-12');await page.locator('[name="to"]').dispatchEvent('change');
 await page.locator('[data-calendar="from"] [data-date="2025-03-07"]').click();
 await popup().locator('[type="submit"]').click();await page.waitForFunction(()=>document.querySelector('.co-date-status')?.textContent==='Date range updated.');
 assert.ok(page.url().includes('from=2025-03-07'));assert.ok((await page.locator('#co-recent').innerText()).includes('Mar 12, 2025'));
 assert.equal(await page.locator('#co-recent tbody tr').count(),1);
 await trigger().click();await page.locator('[name="from"]').fill('2025-04-01');await popup().locator('[type="submit"]').click();
 assert.equal(await page.locator('.co-date-error').isVisible(),true);assert.ok(page.url().includes('from=2025-03-07'));
 await page.keyboard.press('Escape');assert.equal(await popup().isVisible(),false);assert.equal(await page.locator('[name="from"]').inputValue(),'2025-03-07');
 for(const width of [1536,1024,768,390,320]){
  await page.setViewportSize({width,height:900});await trigger().click();
  const box=await popup().boundingBox();assert.ok(box.x>=0&&box.x+box.width<=width+1,'Popover fits '+width);
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Page fits '+width);
  await page.screenshot({path:`storage/cost-overview-date-picker-${width}.png`,fullPage:true});await page.keyboard.press('Escape');
 }
 assert.equal(navigations,initialNav);assert.deepEqual(errors,[]);
 console.log('Date picker checks passed: presets, selected values, custom calendar, validation, Cancel/Escape, AJAX totals/charts, no full-page reload, 320–1536px layouts.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1)});
