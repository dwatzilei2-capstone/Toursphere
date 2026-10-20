const fs=require('node:fs'),assert=require('node:assert/strict');
const {chromium}=require('C:/Users/LAPTOP-3DS/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
(async()=>{
 const browser=await chromium.launch({headless:true,channel:'msedge'});let checks=0;
 const check=(ok,label)=>{assert.ok(ok,label);checks++;};
 try{
  for(const role of ['dispatcher','manager','driver']){
   const context=await browser.newContext();const page=await context.newPage();let html,lastPost;
   await page.route('**/modules/driver-trip-performance/trip-performance.php*',r=>r.fulfill({contentType:'text/html',body:html}));
   await page.route('**/actions/**',r=>{if(r.request().method()==='POST'){lastPost=r.request().postData();return r.fulfill({status:204});}return r.abort();});
   const load=async suffix=>{html=fs.readFileSync(`${__dirname}/../tmp/evaluation-${role}${suffix}.html`,'utf8').replaceAll('tmp/','/fleet/');await page.goto('http://localhost/fleet/modules/driver-trip-performance/trip-performance.php?trip=T-PERF',{waitUntil:'domcontentloaded'});await page.waitForFunction(()=>document.querySelector('#modal-trip-performance')?.parentElement===document.body);};
   const controls=()=>page.evaluate(()=>Array.from(document.querySelectorAll('#modal-trip-performance input,#modal-trip-performance select,#modal-trip-performance textarea,#modal-trip-performance button')).map(el=>({tag:el.tagName,type:el.type,name:el.name,value:el.name==='csrf'?'TOKEN':el.value,required:el.required||false,min:el.min||'',max:el.max||'',step:el.step||'',maxLength:el.maxLength||'',accept:el.accept||'',disabled:el.disabled,readOnly:el.readOnly||false,options:el.options?Array.from(el.options).map(o=>[o.value,o.text,o.selected]):null,action:el.form?.getAttribute('action')||'',method:el.form?.method||'',encoding:el.form?.enctype||''})).sort((a,b)=>JSON.stringify(a).localeCompare(JSON.stringify(b))));
   await load('-before');const before=await controls();await load('');
   assert.deepEqual(await controls(),before,`${role}: complete field, button, value, validation and form contract preserved`);checks++;
   check(await page.locator('#modal-trip-performance pre').count()===0,`${role}: no raw JSON blocks`);
   check(await page.locator('#modal-trip-performance .performance-matrix tbody tr').count()===6,`${role}: six criteria`);
   check((await page.locator('#modal-trip-performance .tc-card-footer button').count())===(role==='manager'?3:role==='dispatcher'?2:1),`${role}: footer keeps role-specific actions`);
   for(const width of [1440,1024,768,390,320]){
    await page.setViewportSize({width,height:900});
    const dimensions=await page.evaluate(()=>{const m=document.querySelector('#modal-trip-performance .tc-modal'),b=m.querySelector('.performance-modal-body'),h=m.querySelector('.tc-card-header'),f=m.querySelector('.tc-card-footer');b.scrollTop=b.scrollHeight;return {width:m.getBoundingClientRect().width,bodyOverflow:b.scrollWidth>b.clientWidth+1,header:h.getBoundingClientRect().top,footer:f.getBoundingClientRect().bottom,scrolled:b.scrollTop>0};});
    check(dimensions.width<=Math.min(980,width),`${role} ${width}: bounded modal width`);
    check(!dimensions.bodyOverflow,`${role} ${width}: no horizontal body overflow`);
    check(dimensions.header>=0&&dimensions.footer<=901&&dimensions.scrolled,`${role} ${width}: fixed header/footer and independent scroll`);
    if(role==='dispatcher'&&[1440,768,390].includes(width)){await page.evaluate(()=>document.querySelector('.performance-modal-body').scrollTop=0);await page.screenshot({path:`${__dirname}/../tmp/evaluation-ui-${width}.png`});}
   }
   await page.setViewportSize({width:1440,height:900});
   if(role==='dispatcher'){
    await page.getByText('Add Trip Evidence',{exact:true}).click();
    const upload=page.locator('#modal-trip-performance form').filter({has:page.locator('input[name="source"][value="upload"]')});
    await upload.locator('[name="kind"]').selectOption('Supporting Document');await upload.locator('[name="description"]').fill('Isolated UI upload contract check');
    await upload.locator('[name="criteria[]"][value="on_time"]').check();
    await upload.locator('[name="evidence_file"]').setInputFiles({name:'ui-fixture.pdf',mimeType:'application/pdf',buffer:Buffer.from('%PDF-1.4\nIsolated UI fixture\n%%EOF')});
    await upload.getByRole('button',{name:'Upload Evidence',exact:true}).click();await page.waitForTimeout(100);
    check(lastPost?.includes('ui-fixture.pdf')&&lastPost.includes('on_time'), 'Upload, criteria and file selection keep multipart contract; POST intercepted');
    const link=page.locator('#modal-trip-performance form').filter({has:page.locator('select[name="source"]')});
    await link.locator('[name="source"]').selectOption('arrival');await link.locator('[name="description"]').fill('Isolated UI link contract check');
    await link.getByRole('button',{name:'Link Record',exact:true}).click();await page.waitForTimeout(100);
    check(new URLSearchParams(lastPost).get('source')==='arrival','Existing Record selection and Link Record contract retained; POST intercepted');
    await page.locator('[name="delay_cause"]').selectOption('External');await page.locator('[name="delay_notes"]').fill('Warehouse record explains delay');
    await page.locator('.tc-card-footer').getByRole('button',{name:'Submit Initial Evaluation'}).click();await page.waitForTimeout(100);
    check(new URLSearchParams(lastPost).get('operation')==='submit'&&new URLSearchParams(lastPost).get('delay_cause')==='External','Fixed footer submits original evaluation form and delay fields; POST intercepted');
   }
   const summary=page.locator('#modal-trip-performance .performance-audit > summary');await summary.click();check(await summary.locator('..').getAttribute('open')!==null,`${role}: audit expands`);
   await page.locator('#modal-trip-performance [data-performance-close]').last().click();check(!await page.locator('#modal-trip-performance').evaluate(el=>el.classList.contains('show')),`${role}: Close works`);
   await load('');await page.keyboard.press('Escape');check(!await page.locator('#modal-trip-performance').evaluate(el=>el.classList.contains('show')),`${role}: Escape works`);
   await context.close();
  }
  console.log(`${checks} presentation browser checks passed across Dispatcher, Manager and Driver; all browser POSTs intercepted, no operational writes.`);
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
