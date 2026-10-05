const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require('C:/Users/LAPTOP-3DS/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
(async()=>{
 const browser=await chromium.launch({headless:true,channel:'msedge'});
 const session=JSON.parse(fs.readFileSync(path.join(__dirname,'../tmp/audit-ui-sessions.json'),'utf8'));
 const context=await browser.newContext();await context.addCookies([{name:'PHPSESSID',value:session.fleet_admin,domain:'localhost',path:'/'}]);
 const page=await context.newPage();let checks=0;const errors=[];page.on('pageerror',e=>errors.push(e.message));
 fs.mkdirSync(path.join(__dirname,'../tmp/audit-ui-validation'),{recursive:true});
 try {
  for(const width of [390,768,1366,1920]){
   await page.setViewportSize({width,height:900});await page.goto('http://localhost/fleet/audit-log.php');await page.locator('.audit-table').waitFor();
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'No page overflow '+width);checks++;
   assert.equal(await page.locator('#sidebar').count(),1);checks++;
   const first=page.locator('[data-audit-index]').first();if(await first.count()){
    await first.click();await page.locator('#audit-details-modal').waitFor({state:'visible'});
    assert(await page.getByRole('heading',{name:'Audit Activity Details'}).isVisible());checks++;
    assert.equal(await page.locator('.audit-technical').getAttribute('open'),null);checks++;
    assert(await page.locator('.audit-modal').evaluate(el=>el.scrollWidth<=innerWidth));checks++;
    if(width===390||width===1366)await page.screenshot({path:path.join(__dirname,'../tmp/audit-ui-validation/modal-'+width+'.png')});
    await page.locator('#audit-details-modal .modal-footer button').click();await page.locator('#audit-details-modal').waitFor({state:'hidden'});
    await page.waitForFunction(()=>document.activeElement?.matches('[data-audit-index]'));assert(await first.evaluate(el=>el===document.activeElement));checks++;
   }
   if(width===390||width===1366)await page.screenshot({path:path.join(__dirname,'../tmp/audit-ui-validation/table-'+width+'.png'),fullPage:true});
  }
  await page.locator('#audit-role').selectOption('Admin');await page.getByRole('button',{name:'Apply',exact:true}).click();assert(page.url().includes('role=Admin'));checks++;
  const displayed=await page.locator('tbody tr td:nth-child(3)').allTextContents();assert(displayed.every(x=>x.trim()==='Admin'));checks++;
  await page.goto('http://localhost/fleet/audit-log.php?from=2026-10-05&to=2026-10-04');assert(await page.getByRole('alert').isVisible());checks++;
  await page.goto('http://localhost/fleet/audit-log.php?q=%3Cscript%3E');assert(await page.getByText('No activities found').isVisible());checks++;
  assert.deepEqual(errors,[]);checks++;
  console.log('PASS: '+checks+' responsive, modal, focus, filter, escaping and browser checks.');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
