const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require('C:/Users/LAPTOP-3DS/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
(async()=>{
 const browser=await chromium.launch({headless:true,channel:'msedge'});let checks=0;
 const check=(ok,label)=>{assert(ok,label);checks++};
 try {
  const page=await browser.newPage({viewport:{width:1000,height:700}});
  await page.setContent(`<style>body{margin:20px}.wide{width:500px;overflow:auto}.wide table{width:1100px;border-collapse:collapse}td{height:36px;min-width:250px}button{margin:5px}.fit{width:500px;overflow:auto}.fit table{width:100%}</style><div class="wide"><table><tbody>${Array.from({length:70},(_,i)=>`<tr><td>Safe cell ${i}</td><td><button>Action</button><a href="#test">Link</a><input type="checkbox"><select><option>Option</option></select></td><td>More columns</td></tr>`).join('')}</tbody></table></div><div class="fit"><table><tr><td>Fits</td></tr></table></div>`);
  await page.addStyleTag({path:path.join(__dirname,'../css/table-scroll.css')});await page.addScriptTag({path:path.join(__dirname,'../js/table-scroll.js')});
  await page.waitForFunction(()=>document.querySelector('.wide').classList.contains('tc-table-draggable'));
  const wrap=page.locator('.wide');const left=()=>wrap.evaluate(el=>el.scrollLeft);
  check(await wrap.evaluate(el=>getComputedStyle(el).cursor==='grab'),'Grab cursor');
  check(!await page.locator('.fit').evaluate(el=>el.classList.contains('tc-table-draggable')),'Fitting table unaffected');
  await page.mouse.move(220,38);await page.mouse.down();await page.mouse.move(70,38,{steps:12});check(await left()>100,'Drag at top');
  check(await wrap.evaluate(el=>getComputedStyle(el).cursor==='grabbing'),'Grabbing cursor');await page.mouse.up();
  check(await wrap.evaluate(el=>getComputedStyle(el).cursor==='grab'),'Cursor restored');
  await wrap.evaluate(el=>el.scrollLeft=0);await page.mouse.move(150,38);await page.mouse.down({button:'right'});await page.mouse.move(50,38);await page.mouse.up({button:'right'});check(await left()===0,'Right mouse unaffected');
  await page.keyboard.press('Escape');
  for(const selector of ['button','a','input','select']){
   await wrap.evaluate(el=>el.scrollLeft=0);const control=wrap.locator(selector).first();await control.scrollIntoViewIfNeeded();const baseline=await left();const box=await control.boundingBox();
   await page.mouse.move(box.x+box.width/2,box.y+box.height/2);await page.mouse.down();await page.mouse.move(box.x-80,box.y+box.height/2,{steps:5});await page.mouse.up();await page.keyboard.press('Escape');check(await left()===baseline,'Control excluded: '+selector);
  }
  await page.mouse.move(100,100);await page.keyboard.down('Shift');await page.mouse.wheel(0,150);await page.keyboard.up('Shift');await page.waitForTimeout(100);check(await left()>0,'Shift wheel');
  await wrap.evaluate(el=>el.scrollLeft=0);await page.mouse.wheel(150,0);await page.waitForTimeout(100);check(await left()>0,'Trackpad horizontal wheel');
  await page.mouse.wheel(0,400);await page.waitForTimeout(150);check(await page.evaluate(()=>scrollY)>0,'Vertical page scroll');
  const before=await left();const topBefore=await page.evaluate(()=>scrollY);await page.mouse.move(200,150);await page.mouse.down();await page.mouse.move(200,200,{steps:8});await page.mouse.up();check(await left()===before,'Vertical drag preserves horizontal position');
  check(await page.evaluate(()=>scrollY)<topBefore,'Drag down reveals earlier rows');
  const topAfter=await page.evaluate(()=>scrollY);await page.mouse.move(200,200);await page.mouse.down();await page.mouse.move(200,100,{steps:8});await page.mouse.up();check(await page.evaluate(()=>scrollY)>topAfter,'Drag up reveals lower rows');
  await page.evaluate(()=>window.scrollTo(0,900));await page.mouse.move(240,180);await page.mouse.down();await page.mouse.move(90,180,{steps:8});await page.mouse.up();check(await left()>before,'Drag from middle of long table');
  await page.evaluate(()=>{const el=document.createElement('div');el.id='dynamic';el.style='width:200px;overflow:auto';el.innerHTML='<table style="width:700px"><tr><td>Dynamic table</td></tr></table>';document.body.append(el)});
  await page.waitForFunction(()=>document.querySelector('#dynamic').classList.contains('tc-table-draggable'));checks++;
  await page.locator('#dynamic').evaluate(el=>el.style.width='900px');await page.waitForFunction(()=>!document.querySelector('#dynamic').classList.contains('tc-table-draggable'));checks++;
  const mobileContext=await browser.newContext({viewport:{width:390,height:700},isMobile:true,hasTouch:true});
  const mobile=await mobileContext.newPage();await mobile.setContent('<meta name="viewport" content="width=device-width,initial-scale=1"><div id="swipe" style="width:350px;overflow:auto"><table style="width:1100px;height:400px"><tr><td>Swipe table</td></tr></table></div>');
  await mobile.addStyleTag({path:path.join(__dirname,'../css/table-scroll.css')});await mobile.addScriptTag({path:path.join(__dirname,'../js/table-scroll.js')});
  const cdp=await mobileContext.newCDPSession(mobile);
  await cdp.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x:280,y:100}]});
  for(let x=260;x>=80;x-=20){await cdp.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x,y:100}]});await mobile.waitForTimeout(20)}
  await cdp.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});await mobile.waitForTimeout(200);
  check(await mobile.locator('#swipe').evaluate(el=>el.scrollLeft)>0,'Native touch swipe');
  check(!await mobile.locator('#swipe').evaluate(el=>el.classList.contains('tc-table-dragging')),'Touch remains native');
  await mobileContext.close();
  const sessions=JSON.parse(fs.readFileSync(path.join(__dirname,'../tmp/audit-ui-sessions.json'),'utf8'));
  await page.context().addCookies([{name:'PHPSESSID',value:sessions.fleet_admin,domain:'localhost',path:'/'}]);
  await page.setViewportSize({width:1000,height:800});await page.goto('http://localhost/fleet/modules/fleet-vehicle-management/vehicle-directory.php');
  await page.waitForFunction(()=>document.querySelector('.tc-table-draggable'));
  const actual=page.locator('.tc-table-draggable').first(),box=await actual.boundingBox();
  await page.mouse.move(box.x+200,box.y+20);await page.mouse.down();await page.mouse.move(box.x+50,box.y+20,{steps:10});await page.mouse.up();
  check(await actual.evaluate(el=>el.scrollLeft)>0,'Vehicle Directory real table drag');
  await page.goto('http://localhost/fleet/audit-log.php');await page.waitForFunction(()=>document.querySelector('.audit-table-scroll.tc-table-draggable'));checks++;
  console.log('PASS: '+checks+' shared table drag, controls, wheel, dynamic resize and real-page checks.');
 }finally{await browser.close()}
})().catch(e=>{console.error(e);process.exit(1)});
