const fs = require('fs'), assert = require('assert/strict'), cp = require('child_process');
const {chromium} = require('C:/Users/LAPTOP-3DS/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const base = 'http://127.0.0.1:8098/fleet';
const snapshot = () => cp.execFileSync('C:/xampp/php/php.exe', ['tests/dashboard_operations_test.php', '--snapshot'], {encoding: 'utf8'});
(async () => {
  const before = snapshot(), sessions = JSON.parse(fs.readFileSync('tmp/dashboard-sessions.json'));
  const browser = await chromium.launch({headless: true, channel: 'msedge'});
  const results = [], pass = message => {results.push(`PASS: ${message}`); console.log(results.at(-1));};
  let page, original;
  try {
    const context = await browser.newContext({viewport: {width: 1440, height: 1000}});
    await context.addCookies([{name: 'PHPSESSID', value: sessions.fleet_admin, domain: '127.0.0.1', path: '/'}]);
    page = await context.newPage();
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    await page.goto(`${base}/dashboard.php`);
    await page.waitForFunction(() => typeof Chart !== 'undefined' && Chart.getChart('do-trend-chart'));
    original = await page.locator('#do-show-calendar').isChecked();
    async function toggle(visible) {
      if (await page.locator('#do-show-calendar').isChecked() === visible) return;
      const response = page.waitForResponse(r => r.url().endsWith('/actions/dashboard-operations.php') && r.request().method() === 'POST');
      await page.locator('#do-show-calendar').setChecked(visible); assert.equal((await response).status(), 200);
      await page.waitForFunction(() => !document.querySelector('#do-show-calendar').disabled);
    }
    await toggle(true);
    await page.waitForFunction(() => document.querySelectorAll('.do-cell').length === 35);
    assert.equal(await page.locator('.stat-card').count(), 4);
    for (const id of ['chart-vehicle-availability', 'chart-vehicle-operations', 'chart-weekly-trips']) assert.ok(await page.evaluate(id => !!Chart.getChart(id), id), id);
    assert.equal(await page.locator('.tc-table thead th').count(), 6);
    for (const title of ['Active Trips & Dispatches', 'Vehicle Availability', 'Vehicle Operations', 'Completed Trips', 'Active Maintenance Alerts']) assert.ok((await page.locator('.dashboard-page-shell').innerText()).includes(title));
    const oldKpis = await page.locator('.stat-card').allTextContents();
    const oldTable = await page.locator('.tc-table').innerHTML();
    pass('Existing KPI cards, active trips table, three charts and maintenance section render');
    for (const [view, count] of [['week', 7], ['day', 1], ['month', 35]]) {
      await page.locator(`[data-calendar-view="${view}"]`).click();
      await page.waitForFunction(n => document.querySelectorAll('.do-cell').length === n, count);
    }
    pass('Calendar Month, Week and Day views');
    const label = await page.locator('#do-calendar-date').innerText();
    await page.locator('[data-calendar-nav="1"]').click(); await page.waitForFunction(text => document.querySelector('#do-calendar-date').textContent !== text, label);
    await page.locator('[data-calendar-nav="-1"]').click(); await page.waitForFunction(text => document.querySelector('#do-calendar-date').textContent === text, label);
    await page.locator('#do-today').click(); await page.waitForFunction(() => document.querySelectorAll('.do-cell').length === 35);
    pass('Calendar previous, next and Today navigation');
    if (await page.locator('.do-event').count()) {
      await page.locator('.do-event').first().click();
      assert.equal(await page.locator('#do-event-dialog').isVisible(), true);
      assert.equal(await page.locator('#do-event-dialog input, #do-event-dialog select, #do-event-dialog textarea, #do-event-dialog a').count(), 0);
      assert.ok((await page.locator('#do-event-details').innerText()).includes('Reservation'));
      await page.keyboard.press('Escape'); pass('Live calendar event details and read-only controls');
    } else results.push('NOT TESTED: Current month event dialog (no events)');
    const top = await page.locator('#do-trend-title').evaluate(el => el.getBoundingClientRect().top + scrollY);
    await toggle(false);
    assert.equal(await page.locator('#do-calendar').isVisible(), false);
    const hiddenTop = await page.locator('#do-trend-title').evaluate(el => el.getBoundingClientRect().top + scrollY);
    assert.ok(top - hiddenTop > 300);
    await page.reload(); assert.equal(await page.locator('#do-show-calendar').isChecked(), false);
    await page.goto(`${base}/modules/vehicle-reservation-dispatch/trip-schedule.php`);
    await page.goto(`${base}/dashboard.php`); assert.equal(await page.locator('#do-show-calendar').isChecked(), false);
    pass('Show/hide collapse, layout reflow, refresh and module-navigation persistence');
    cp.execFileSync('C:/xampp/php/php.exe', ['tests/dashboard_operations_test.php', '--sessions']);
    const freshSessions = JSON.parse(fs.readFileSync('tmp/dashboard-sessions.json'));
    const freshContext = await browser.newContext();
    await freshContext.addCookies([{name:'PHPSESSID',value:freshSessions.fleet_admin,domain:'127.0.0.1',path:'/'}]);
    const freshPage = await freshContext.newPage();
    await freshPage.goto(`${base}/dashboard.php`);
    assert.equal(await freshPage.locator('#do-show-calendar').isChecked(),false);
    await freshContext.close();
    pass('Preference persists into a separate authenticated session for the same user');
    await toggle(true); await page.waitForFunction(() => document.querySelectorAll('.do-cell').length === 35);
    assert.deepEqual(await page.locator('.stat-card').allTextContents(), oldKpis);
    assert.equal(await page.locator('.tc-table').innerHTML(), oldTable);
    pass('Calendar restoration preserves existing KPIs and table');
    for (const [view, count] of [['day', 24], ['year', 12], ['month', 31]]) {
      await page.locator(`[data-trend-view="${view}"]`).click();
      await page.waitForFunction(n => Chart.getChart('do-trend-chart')?.data.labels.length === n, count);
    }
    pass('Day/Month/Year chart filtering');
    await page.locator('#do-trend-date').fill('1900-01'); await page.locator('#do-trend-date').dispatchEvent('change');
    await page.waitForFunction(() => document.querySelector('#do-trend-status').textContent.includes('No delivery activity'));
    assert.ok(await page.evaluate(() => Chart.getChart('do-trend-chart').data.datasets.every(d => d.data.every(n => n === 0))));
    pass('Empty analytics state without fabricated data');
    await page.route('**/actions/dashboard-operations.php?kind=trend*', route => route.fulfill({status:503,contentType:'application/json',body:'{"error":"Temporary test outage"}'}));
    await page.locator('#do-trend-retry').click();
    await page.waitForFunction(() => document.querySelector('#do-trend-status').textContent === 'Temporary test outage');
    await page.unroute('**/actions/dashboard-operations.php?kind=trend*');
    pass('Failed analytics request displays an error instead of stale chart data');
    await page.locator('#do-trend-date').fill('2026-10'); await page.locator('#do-trend-date').dispatchEvent('change');
    await page.waitForFunction(() => !!Chart.getChart('do-trend-chart'));
    for (const width of [1440, 1024, 768, 390, 320]) {
      await page.setViewportSize({width, height: 1000});
      await page.waitForTimeout(300);
      assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `Overflow at ${width}`);
      await page.evaluate(() => scrollTo(0, 0)); await page.screenshot({path: `storage/dashboard-operations-${width}.png`, fullPage: true});
    }
    pass('Desktop/mobile layout at 320, 390, 768, 1024 and 1440 pixels');
    for(const selector of ['a[href$="/reports.php"]','a[href$="/modules/vehicle-reservation-dispatch/reservations.php"]','a[href$="/modules/vehicle-reservation-dispatch/dispatch-board.php"]']){
      const href=await page.locator('.dashboard-page-shell').locator(selector).first().getAttribute('href');
      assert.equal((await context.request.get(new URL(href,base).href)).status(),200);
    }
    pass('Existing Export Summary, New Reservation and View Full Board destinations respond');
    await page.setViewportSize({width: 1440, height: 1000});
    for (const [role, sid] of Object.entries(sessions)) {
      const c = await browser.newContext();
      await c.addCookies([{name: 'PHPSESSID', value: sid, domain: '127.0.0.1', path: '/'}]);
      const response = await c.request.get(`${base}/actions/dashboard-operations.php?kind=calendar&view=month&date=2026-10-20`);
      if (['customer', 'driver'].includes(role)) assert.equal(response.status(), 403);
      else assert.ok([200, 403].includes(response.status()), role);
      const invalid = await c.request.post(`${base}/actions/dashboard-operations.php`, {form: {visible: '0', csrf: 'invalid'}});
      assert.equal(invalid.status(), 403);
      results.push(`PASS: ${role} access (${response.status()}) and rejected invalid preference token`);
      await c.close();
    }
    const anonymous = await browser.newContext();
    assert.equal((await anonymous.request.get(`${base}/actions/dashboard-operations.php?kind=trend&view=year&date=2026-01-01`)).status(), 401);
    await anonymous.close();
    pass('Unauthenticated access denied');
    assert.deepEqual(errors, []);
    assert.equal(snapshot(), before);
    pass('No JavaScript errors and operational records unchanged');
    await toggle(original);
  } finally {
    if (page && original !== undefined && !page.isClosed()) {
      await page.evaluate(async visible => {
        const root = document.querySelector('#dashboard-operations');
        if(root) await fetch(root.dataset.endpoint, {method:'POST', body: new URLSearchParams({visible:visible?'1':'0',csrf:root.dataset.csrf})});
      }, original).catch(() => {});
    }
    await browser.close();
    fs.writeFileSync('tmp/dashboard-browser-results.txt', results.join('\n'));
  }
})().catch(error => {console.error(error); process.exit(1);});
