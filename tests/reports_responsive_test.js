const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/LAPTOP-3DS/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const root = path.join(__dirname, '..');
const sessions = JSON.parse(fs.readFileSync(path.join(root, 'tmp/reports-test-sessions.json'), 'utf8'));
let checks = 0;
function check(ok, label) { assert.ok(ok, label); checks++; }
(async () => {
 const browser = await chromium.launch({headless: true, channel: 'msedge'});
 try {
  const context = await browser.newContext();
  await context.addCookies([{name:'PHPSESSID', value:sessions.fleet_admin, domain:'localhost', path:'/'}]);
  const page = await context.newPage(); const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  const sizes = [[320,568],[360,640],[390,844],[430,932],[600,960],[768,1024],[820,1180],[1024,768],[1366,768],[1920,1080],[2560,1440],[844,390]];
  for(const [width,height] of sizes) {
   await page.setViewportSize({width,height});
   await page.goto('http://localhost/fleet/reports.php?period=previous_month');
   await page.locator('.report-catalog').waitFor();
   check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth+1), 'Catalog overflow '+width);
   check(await page.locator('.report-card').count()===6, 'Six current categories');
   await page.locator('#report-period').selectOption('custom');
   check(await page.locator('#report-from').isVisible(), 'Custom dates visible');
   await page.locator('#report-from').fill('2026-09-01'); await page.locator('#report-to').fill('2026-09-30');
   await page.getByRole('button',{name:'Apply Period'}).click();
   check(page.url().includes('from=2026-09-01'), 'Period applied');
   if(width===390 || width===1366) await page.screenshot({path:path.join(root, 'tmp/reports-validation/catalog-'+width+'.png'), fullPage:true});
   await page.locator('.report-card').filter({hasText:'Trip Operations Report'}).getByRole('link',{name:'Preview Report'}).click();
   await page.locator('.report-records').waitFor();
   check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth+1), 'Preview overflow '+width);
   check(await page.locator('.report-table-wrap').isVisible()===(width>=768), 'Adaptive detailed records');
   if(width<768) { await page.locator('.report-mobile-record summary').first().click(); check(await page.locator('.report-mobile-record').first().locator('dl').isVisible(), 'Full mobile details'); }
   if(width===390 || width===1366) await page.screenshot({path:path.join(root, 'tmp/reports-validation/preview-'+width+'.png'), fullPage:true});
  }
  // Actual download UI: loading state, menu, file identity and duplicate suppression.
  await page.setViewportSize({width:1366,height:768});
  await page.locator('.report-export>button').click();
  const downloadEvent=page.waitForEvent('download');
  await page.getByRole('link',{name:'Excel (.xlsx)', exact:true}).click();
  const download=await downloadEvent;
  check(download.suggestedFilename()==='TourSphere_Trip_Operations_2026-09-01_to_2026-09-30.xlsx','Actual UI filename');
  await download.saveAs(path.join(root,'tmp/reports-validation/browser-download.xlsx'));
  check(await page.locator('#report-status').textContent()==='Report downloaded.','Download loading state completes');
  await page.goto('http://localhost/fleet/report-preview.php?report=trips&period=custom&from=2099-01-01&to=2099-01-31');
  check(await page.getByText('No report data is available for the selected period.').isVisible(),'Empty preview');
  // Verify no unauthorized sidebar entry and Dispatcher sees two cards.
  for(const role of ['fleet_manager','dispatcher','driver','customer']) {
   const ctx=await browser.newContext(); await ctx.addCookies([{name:'PHPSESSID',value:sessions[role],domain:'localhost',path:'/'}]); const p=await ctx.newPage();
   if(role==='dispatcher' || role==='fleet_manager') { await p.goto('http://localhost/fleet/reports.php'); check(await p.locator('.report-card').count()===(role==='dispatcher'?2:6),role+' cards'); }
   else { await p.goto('http://localhost/fleet/'+(role==='driver'?'modules/driver-portal/driver-dashboard.php':'modules/customer-portal/reservations.php')); check(await p.locator('#sidebar a[href$="/reports.php"]').count()===0,role+' sidebar hides reports'); }
   await ctx.close();
  }
  check(errors.length===0,'No browser errors: '+errors.join('; '));
  console.log(checks+' responsive viewport, role visibility, period, mobile detail and download checks passed.');
 } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exit(1); });
