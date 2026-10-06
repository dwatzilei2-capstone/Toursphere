const fs=require('node:fs'),assert=require('node:assert/strict');
const {chromium}=require('C:/Users/LAPTOP-3DS/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
(async()=>{
 const sessions=JSON.parse(fs.readFileSync('tmp/trip-funding-sessions.json','utf8'));
 const browser=await chromium.launch({headless:true,channel:'msedge'});
 try{
  const context=await browser.newContext();await context.addCookies([{name:'PHPSESSID',value:sessions.fleet_admin,domain:'localhost',path:'/'}]);
  const page=await context.newPage();const errors=[];page.on('pageerror',error=>errors.push(error.message));
  await page.goto('http://localhost/fleet/modules/fuel-management/fuel-transactions.php');
  assert(await page.getByText('Not Uploaded',{exact:true}).count()>0);
  assert.equal(await page.locator('[data-fuel-receipt]').count(),0);
  assert.equal(await page.locator('input[name="receipt"]').getAttribute('required'),null);
  assert.equal(await page.locator('form[action$="/actions/fuel.php"]').getAttribute('enctype'),'multipart/form-data');
  // Test-only UI response; upload and authorized byte streaming are checked separately through the real backend.
  let pdf=false;
  await page.route('**/actions/fuel-receipt.php?**',async route=>{
   if(new URL(route.request().url()).searchParams.has('info'))await route.fulfill({contentType:'application/json',body:JSON.stringify({id:'RECEIPT-UI-TEST',vehicle:'TEST',trip:null,date:'Oct 6, 2026',fuel_type:'Diesel',liters:'2.00 L',price:'₱95.95',total:'₱191.90',driver:'Isolated test',efficiency:'8 km/L',station:'Isolated test',mime:pdf?'application/pdf':'image/png'})});
   else await route.fulfill({contentType:pdf?'application/pdf':'image/png',body:pdf?Buffer.from('%PDF-1.4\n%%EOF'):Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=','base64')});
  });
  await page.evaluate(()=>{const b=document.createElement('button');b.textContent='Open isolated receipt';b.dataset.fuelReceipt='RECEIPT-UI-TEST';document.querySelector('main').prepend(b)});
  for(const width of [390,1366]){
   await page.setViewportSize({width,height:900});await page.getByText('Open isolated receipt',{exact:true}).click();
   await page.locator('#fuel-receipt-content').waitFor({state:'visible'});
   await page.waitForFunction(()=>document.querySelector('#fuel-receipt-preview img')?.naturalWidth>0);
   assert(await page.locator('#fuel-receipt-info').innerText().then(text=>text.includes('₱191.90')));
   assert((await page.locator('#fuel-receipt-download').getAttribute('href')).includes('download=1'));
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1));
   await page.getByRole('button',{name:'Close receipt preview'}).click();await page.locator('#fuel-receipt-modal').waitFor({state:'hidden'});
  }
  pdf=true;await page.getByText('Open isolated receipt',{exact:true}).click();await page.getByTitle('Uploaded fuel receipt PDF').waitFor();await page.getByRole('button',{name:'Close receipt preview'}).click();
  assert.deepEqual(errors,[]);
  console.log('PASS: optional multipart upload, truthful missing-receipt state, compact desktop/mobile image preview, PDF preview element, transaction details and download link.');
 }finally{await browser.close()}
})().catch(error=>{console.error(error);process.exitCode=1});
