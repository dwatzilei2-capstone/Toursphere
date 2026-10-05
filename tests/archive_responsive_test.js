const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/LAPTOP-3DS/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const root = path.join(__dirname, '..');
const sessions = JSON.parse(fs.readFileSync(path.join(root,'tmp/archive-test-sessions.json'),'utf8'));
const populated = fs.readFileSync(path.join(root,'tmp/archive-test-populated.html'),'utf8');
const detail = fs.readFileSync(path.join(root,'tmp/archive-test-details.html'),'utf8');
let checks = 0;
const check = (ok, text) => { assert.ok(ok,text); checks++; };
(async () => {
 const browser = await chromium.launch({headless:true,channel:'msedge'});
 let diagnosticPage;
 try {
  const context = await browser.newContext();
  await context.addCookies([{name:'PHPSESSID',value:sessions.fleet_admin,domain:'localhost',path:'/'}]);
  const page = await context.newPage(); const failures=[];
  diagnosticPage=page;
  page.on('pageerror',error=>failures.push(error.message));
  const widths=[[320,568],[360,640],[390,844],[430,932],[600,960],[768,1024],[820,1180],[1024,768],[1366,768],[1920,1080],[2560,1440],[844,390]];
  for(const [width,height] of (process.argv.includes('--flows')?[]:widths)) {
   await page.setViewportSize({width,height});
   await page.goto('http://localhost/fleet/archive.php?category=completed');
   await page.locator('.archive-tabs').waitFor();
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`Empty page overflow ${width}x${height}`);
   check(await page.locator('text=No archived records available.').count()===1,'Real database empty state');
   await page.getByRole('button',{name:'Filter',exact:false}).click();
   await page.locator('#archive-filter-modal.show').waitFor();
   const filter=await page.locator('#archive-filter-modal .modal-content').boundingBox();
   check(filter.x>=0 && filter.x+filter.width<=width+1,`Filter width ${width}`);
   const apply=await page.getByRole('button',{name:'Apply Filters'}).boundingBox();
   check(apply.y>=0 && apply.y+apply.height<=height+1,`Filter actions visible ${width}x${height}`);
   await page.locator('#archive-filter-modal .btn-close').click();
   await page.locator('#archive-filter-modal').waitFor({state:'hidden'});
   await page.route('**/archive.php?fixture=populated',route=>route.fulfill({contentType:'text/html',body:populated}));
   await page.goto('http://localhost/fleet/archive.php?fixture=populated');
   check(await page.locator('.archive-table tbody tr').count()===15,'Populated page is paginated');
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`Populated page overflow ${width}x${height}`);
   check(await page.locator('.archive-table thead').isVisible()===(width>=768),'Table changes to mobile cards');
   if(width===390 || width===1366) await page.screenshot({path:path.join(root,`tmp/archive-${width}.png`),fullPage:true});
   await page.route('**/archive-details.php?fixture=details',route=>route.fulfill({contentType:'text/html',body:detail}));
   await page.goto('http://localhost/fleet/archive-details.php?fixture=details');
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`Details page overflow ${width}x${height}`);
  }
  // Use the existing mobile navigation; Archive must remain reachable after Reports.
  await page.setViewportSize({width:390,height:844});
  await page.goto('http://localhost/fleet/archive.php?category=completed');
  await page.locator('#mobile-menu-btn').click();
  const nav=page.locator('#sidebar a[href$="/archive.php"]');
  await nav.scrollIntoViewIfNeeded(); check(await nav.isVisible(),'Archive accessible in existing mobile drawer');
  await nav.click();
  check((await page.url()).includes('/archive.php'),'Mobile Archive navigates');
  // Retirement must have two distinct steps and never select the recommendation automatically.
  await page.goto('http://localhost/fleet/modules/fleet-vehicle-management/vehicle-directory.php');
  let writes=0;
  await page.route('**/actions/archive.php**',async route=>{
   if(route.request().method()==='GET') await route.fulfill({contentType:'application/json',body:JSON.stringify({vehicle:{id:'TEST-VEH',plate_number:'TEST123',status:'Available'},recommendation:{reason:'Repeated Mechanical Failure',basis:'Three documented failures in isolated test history.'}})});
   else {writes++; await route.fulfill({status:422,contentType:'application/json',body:JSON.stringify({error:'Test submission intercepted; no vehicle was retired.'})});}
  });
  await page.locator('[data-retire-vehicle]').first().click();
  await page.getByRole('button',{name:'Continue',exact:true}).waitFor();
  check(await page.locator('#archive-reason').inputValue()==='','Recommendation does not select final reason');
  await page.locator('#archive-reason').selectOption('Other');
  await page.locator('#archive-explanation').fill('Approved disposal after review');
  await page.getByRole('button',{name:'Continue',exact:true}).click();
  check(writes===0,'Continue does not execute retirement');
  check(await page.getByRole('heading',{name:'Confirm Vehicle Retirement?'}).isVisible(),'Final confirmation displayed');
  const confirm=await page.getByRole('button',{name:'Confirm Retirement',exact:true}).boundingBox();
  check(confirm.y+confirm.height<=844,'Retirement action within mobile viewport');
  await page.getByRole('button',{name:'Confirm Retirement',exact:true}).click();
  await page.locator('#archive-action-error').filter({hasText:'Test submission intercepted'}).waitFor();
  check(writes===1,'Only final confirmation sends mutation');
  await page.locator('#archive-action-modal .btn-close').click();
  await page.goto('http://localhost/fleet/modules/vehicle-reservation-dispatch/dispatch-board.php');
  const archiveButtons=page.locator('[data-archive-trip]');
  check(await archiveButtons.count()>0,'Closed trips expose manual Archive');
  await archiveButtons.first().click();
  await page.getByRole('heading',{name:'Archive Trip?'}).waitFor();
  check(await page.getByRole('heading',{name:'Archive Trip?'}).isVisible(),'Trip requires confirmation');
  check(writes===1,'Opening trip confirmation does not archive');
  check(failures.length===0,`No browser script errors: ${failures.join(', ')}`);
  await context.close();
 } catch(error) {
   if(diagnosticPage) { console.error('Failed page:',diagnosticPage.url(),await diagnosticPage.title()); await diagnosticPage.screenshot({path:path.join(root,'tmp/archive-failure.png'),fullPage:true}); }
   throw error;
 } finally { await browser.close(); }
 console.log(`${checks} Archive browser checks passed across 12 desktop, tablet, mobile and landscape viewports. No real records changed.`);
})().catch(error=>{console.error(error);process.exit(1)});
