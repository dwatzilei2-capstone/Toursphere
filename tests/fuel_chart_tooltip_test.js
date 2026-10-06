const fs = require('node:fs');
const assert = require('node:assert/strict');
const { chromium } = require('C:/Users/LAPTOP-3DS/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
(async () => {
  const sessions = JSON.parse(fs.readFileSync('tmp/trip-funding-sessions.json', 'utf8'));
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  try {
    const context = await browser.newContext();
    await context.addCookies([{ name: 'PHPSESSID', value: sessions.fleet_admin, domain: 'localhost', path: '/' }]);
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto('http://localhost/fleet/modules/fuel-management/fuel-overview.php');
    await page.locator('#chart-fuel-monthly').scrollIntoViewIfNeeded();
    await page.waitForTimeout(1200);
    for (const index of [0, 8, 9, 11]) {
      const point = await page.evaluate(index => {
        const chart = Chart.getChart('chart-fuel-monthly');
        const bounds = chart.canvas.getBoundingClientRect();
        const point = chart.getDatasetMeta(0).data[index];
        return { x: bounds.x + point.x, y: bounds.y + point.y };
      }, index);
      await page.mouse.move(point.x, point.y);
      await page.waitForTimeout(500);
      const result = await page.evaluate(index => {
        const chart = Chart.getChart('chart-fuel-monthly');
        return {
          visible: chart.tooltip.opacity,
          title: chart.tooltip.title[0],
          expected: chart.data.labels[index],
          body: chart.tooltip.body[0]?.lines.join(' '),
          value: chart.data.datasets[0].data[index]
        };
      }, index);
      assert.equal(result.visible, 1);
      assert.equal(result.title, result.expected);
      assert(result.body.includes('Fuel Expense'));
      assert(result.body.includes(Number(result.value).toLocaleString('en-US', { maximumFractionDigits: 2 })));
    }
    await page.mouse.move(10, 10);
    await page.waitForTimeout(500);
    assert.equal(await page.evaluate(() => Chart.getChart('chart-fuel-monthly').tooltip.opacity), 0);
    assert.deepEqual(errors, []);
    console.log('PASS: fuel chart tooltips show correct months and amounts including zero values, dismiss on mouse leave, and produce no browser errors.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
