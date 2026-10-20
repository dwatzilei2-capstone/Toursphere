const fs=require('fs'),assert=require('assert/strict'),cp=require('child_process');
const {chromium}=require('C:/Users/LAPTOP-3DS/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
(async()=>{
  const snapshot=()=>cp.execFileSync('C:/xampp/php/php.exe',['tests/dashboard_operations_test.php','--snapshot'],{encoding:'utf8'});
  const before=snapshot(),browser=await chromium.launch({headless:true,channel:'msedge'});
  try{
    const ctx=await browser.newContext({viewport:{width:1440,height:1000}});
    await ctx.addCookies([{name:'PHPSESSID',value:JSON.parse(fs.readFileSync('tmp/dashboard-sessions.json')).fleet_admin,domain:'127.0.0.1',path:'/'}]);
    const page=await ctx.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
    await page.goto('http://127.0.0.1:8098/fleet/dashboard.php?sample=1');
    await page.waitForFunction(()=>typeof Chart!=='undefined'&&Chart.getChart('do-trend-chart')&&document.querySelectorAll('.do-event').length===20);
    assert.ok(await page.evaluate(()=>Chart.getChart('do-trend-chart').data.datasets.every(d=>new Set(d.data).size>=3)));
    assert.ok(await page.evaluate(()=>{const d=Chart.getChart('do-trend-chart').data.datasets[0].data;return Math.max(...d)-Math.min(...d)>=20;}));
    const kpis=await page.locator('.stat-card').allTextContents();
    await page.locator('.do-event').first().click();
    assert.ok((await page.locator('#do-event-dialog').innerText()).includes('Fictional preview only'));
    await page.keyboard.press('Escape');
    for(const width of [1440,390]){
      await page.setViewportSize({width,height:1000});
      await page.evaluate(()=>scrollTo(0,0));
      await page.waitForTimeout(300);
      assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
      await page.screenshot({path:`storage/dashboard-samples-${width}.png`,fullPage:true});
    }
    await page.getByRole('link',{name:'Show actual data',exact:true}).click();
    await page.waitForFunction(()=>document.querySelector('#dashboard-operations')?.dataset.source==='actual'&&typeof Chart!=='undefined'&&Chart.getChart('do-trend-chart'));
    assert.deepEqual(await page.locator('.stat-card').allTextContents(),kpis);
    assert.equal(await page.locator('.do-event').filter({hasText:'SAMPLE-'}).count(),0);
    assert.equal(snapshot(),before);
    assert.deepEqual(errors,[]);
    console.log('PASS: 20 calendar examples; varied trend lines with peaks and dips; labeled read-only details; desktop/mobile; actual-data switch; unchanged KPIs and operational records.');
  }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
