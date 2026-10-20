/**
 * Fuel type UI test: extraction into the Add Vehicle form, the Admin confirmation gate,
 * and the Fuel type shown in the Vehicle Directory and Vehicle Details at desktop and mobile widths.
 * Run tests/assignment_http_sessions.php first so the shared admin session exists.
 */
const fs = require('fs'), path = require('path'), assert = require('assert/strict'), cp = require('child_process');
const { chromium } = require('C:/Users/LAPTOP-3DS/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const root = path.join(__dirname, '..');
const sessions = JSON.parse(fs.readFileSync(path.join(root, 'tmp/assignment-test-sessions.json')));
const base = 'http://localhost/fleet', directory = base + '/modules/fleet-vehicle-management/vehicle-directory.php';
let checks = 0;
const check = (value, label) => { assert.ok(value, label); checks++; };
const php = (...args) => cp.execFileSync('C:/xampp/php/php.exe', [path.join(__dirname, 'fuel_type_ui_fixture.php'), ...args], { cwd: root, encoding: 'utf8' }).trim();

(async () => {
  let browser;
  const documentPath = php();
  try {
    browser = await chromium.launch({ headless: true, channel: 'msedge' });
    const ctx = await browser.newContext();
    await ctx.addCookies([{ name: 'PHPSESSID', value: sessions.fleet_admin, domain: 'localhost', path: '/' }]);
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto(directory);
    await page.waitForFunction(() => !!window.TC_VEHICLES_DATA);

    // ---------------------------------------------------------------- Directory
    const headers = await page.locator('.tc-table thead th').allTextContents();
    check(headers.map((header) => header.trim()).includes('Fuel'), 'Vehicle Directory shows a Fuel column');
    const vehicles = await page.evaluate(() => window.TC_VEHICLES_DATA);
    const ids = Object.keys(vehicles);
    check(ids.length > 0, 'Directory lists real vehicles to inspect');
    for (const id of ids) {
      const expected = vehicles[id].fuelTypeLabel || vehicles[id].fuelType || 'Not recorded';
      const cell = await page.locator(`[data-vehicle-id="${id}"] td:nth-child(5)`).textContent();
      check(cell.trim() === expected, `Directory fuel cell for ${id} reads "${expected}"`);
    }
    check(ids.every((id) => vehicles[id].fuelTypeLabel !== undefined), 'Every directory record carries its fuel label for the details view');

    // ------------------------------------------- Details modal at every width
    for (const [width, height] of [[1366, 900], [768, 1024], [390, 844], [320, 568]]) {
      await page.setViewportSize({ width, height });
      await page.evaluate((id) => App.viewVehicleDetails(id), ids[0]);
      await page.locator('#modal-vehicle-details-body').waitFor({ state: 'visible' });
      const row = page.locator('#modal-vehicle-details-body tr').filter({ hasText: 'Fuel Type:' }).first();
      check(await row.count() === 1, `Details modal shows one Fuel Type row at ${width}`);
      const expected = vehicles[ids[0]].fuelTypeLabel || vehicles[ids[0]].fuelType || 'Not recorded';
      check((await row.textContent()).includes(expected), `Details modal Fuel Type reads "${expected}" at ${width}`);
      check(await page.locator('#modal-vehicle-details-body').evaluate((e) => e.scrollWidth <= e.clientWidth + 1), `Details modal has no horizontal overflow at ${width}`);
      await page.evaluate(() => App.closeModal('modal-vehicle-details'));
    }

    // ------------------------------------------------ Directory at mobile widths
    for (const [width, height] of [[390, 844], [320, 568]]) {
      await page.setViewportSize({ width, height });
      await page.evaluate(() => window.scrollTo(0, 0));
      check(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1), `Directory page stays within the viewport at ${width}`);
      const scroller = page.locator('.tc-table-container').first();
      check(await scroller.count() === 1, `Directory table container present at ${width}`);
      check(await scroller.evaluate((e) => e.closest('table')?.querySelector('thead th:nth-child(5)') !== null), `Directory table carries the Fuel column at ${width}`);
      await scroller.evaluate((e) => { e.scrollLeft = e.scrollWidth; });
      check(await page.locator('[data-vehicle-id="' + ids[0] + '"] td:nth-child(5)').isVisible(), `Fuel cell remains visible while scrolling at ${width}`);
      await scroller.evaluate((e) => { e.scrollLeft = 0; });
    }

    // ------------------------------------------------------- Add Vehicle form
    await page.setViewportSize({ width: 1366, height: 900 });
    await page.evaluate(() => App.openModal('modal-add-vehicle'));
    await page.locator('#vehicle-registration-form').waitFor({ state: 'attached' });
    check(await page.locator('#vehicle-fuel-type').getAttribute('required') !== null, 'Fuel Type select is required');
    const options = await page.locator('#vehicle-fuel-type option').evaluateAll((nodes) => nodes.map((node) => ({ value: node.value, text: node.textContent.trim() })));
    check(options.length === 1 + 10, 'Fuel select offers the placeholder plus the ten configured options');
    for (const [value, text] of [['Diesel', 'Diesel'], ['Gasoline', 'Gasoline'], ['LPG', 'LPG / Autogas'], ['CNG', 'CNG'], ['LNG', 'LNG'], ['Biodiesel', 'Biodiesel'], ['Ethanol / Flex Fuel', 'Ethanol / Flex Fuel'], ['Other', 'Other']]) {
      check(options.some((option) => option.value === value && option.text === text), `Fuel option ${value} labelled "${text}"`);
    }
    check(options.some((option) => option.value === 'Hybrid') && options.some((option) => option.value === 'Electric'), 'Fuel select keeps the Hybrid and Electric options');
    check(await page.locator('#vehicle-fuel-other').getAttribute('maxlength') === '22', 'The Other description is capped at 22 characters');
    check(await page.locator('#vehicle-fuel-type-source').inputValue() === 'manual', 'Fuel source starts as the Admin selection');
    check((await page.locator('#vehicle-fuel-type-note').textContent()).includes('never inferred from the brand'), 'The fuel hint states that fuel is never inferred from the brand or model');
    check(await page.locator('[name="fuel_type_confirmed"]').count() === 1, 'Exactly one fuel confirmation control is rendered');
    check(await page.locator('#vehicle-fuel-confirmed').getAttribute('required') !== null, 'Fuel type confirmation is a required control');
    check(!(await page.locator('#vehicle-fuel-confirmed').isChecked()), 'Fuel type confirmation starts unchecked');

    // Extraction: the uploaded document states AUTOGAS, so the form must pre-fill LPG / Autogas.
    await page.setInputFiles('#vehicle-document', documentPath);
    await page.waitForFunction(() => document.getElementById('vehicle-fuel-type-source')?.value === 'document', null, { timeout: 30000 });
    check(await page.locator('#vehicle-fuel-type').inputValue() === 'LPG', 'AUTOGAS on the uploaded document pre-fills LPG / Autogas');
    check((await page.locator('#vehicle-fuel-type-note').textContent()).includes('extracted from the uploaded document'), 'The form says the fuel came from the document');
    check(!(await page.locator('#vehicle-fuel-confirmed').isChecked()), 'The Admin still has to confirm the extracted fuel');
    check(await page.locator('#vehicle-payload').inputValue() === '1200', 'The extracted payload is filled beside the fuel type');

    // The Admin reveals the fuel step the way the workflow does: type, brand, and model.
    await page.selectOption('#vehicle-type', { index: 1 });
    await page.waitForFunction(() => document.querySelectorAll('#vehicle-brand option').length > 1);
    await page.selectOption('#vehicle-brand', { index: 1 });
    await page.waitForFunction(() => document.querySelectorAll('#vehicle-model option').length > 1);
    await page.selectOption('#vehicle-model', { index: 1 });
    await page.locator('#vehicle-fuel-type').waitFor({ state: 'visible' });
    check(await page.locator('#vehicle-fuel-type').inputValue() === '', 'Changing the configuration clears the previous document fuel value');
    check(await page.locator('#vehicle-fuel-type-source').inputValue() === 'manual', 'A cleared fuel value is treated as the Admin selection');
    const confirm = page.locator('#vehicle-fuel-confirmed');
    check(await confirm.evaluate((node) => node.checkValidity()) === false, 'An unconfirmed fuel type leaves the form invalid');
    await confirm.check();
    check(await confirm.evaluate((node) => node.checkValidity()) === true, 'Confirming the fuel type satisfies the form');
    await confirm.uncheck();

    const otherField = page.locator('#vehicle-fuel-other-field');
    check(await otherField.isHidden(), 'The Other description is hidden while another fuel is selected');
    await page.selectOption('#vehicle-fuel-type', 'Ethanol / Flex Fuel');
    check(await page.locator('#vehicle-fuel-type').inputValue() === 'Ethanol / Flex Fuel', 'The Admin can select Ethanol / Flex Fuel manually');
    check((await page.locator('#vehicle-fuel-type-note').textContent()).includes('never inferred from the brand'), 'A manual selection keeps the manual hint');
    await page.selectOption('#vehicle-fuel-type', 'Other');
    await otherField.waitFor({ state: 'visible' });
    check(await otherField.isVisible(), 'Choosing Other reveals the description field');
    await page.fill('#vehicle-fuel-other', 'Hydrogen');
    check(await page.locator('#vehicle-fuel-other').inputValue() === 'Hydrogen', 'The Other description accepts the Admin value');
    await page.selectOption('#vehicle-fuel-type', 'Diesel');
    check(await otherField.isHidden(), 'Switching back to a listed fuel hides the Other description');
    check(await page.locator('#vehicle-fuel-other').inputValue() === '', 'The Other description is cleared when it no longer applies');
    await page.locator('#vehicle-fuel-confirmed').check();
    await page.locator('#vehicle-fuel-type').scrollIntoViewIfNeeded();
    await page.screenshot({ path: path.join(root, 'tmp/fuel-type-form-desktop.png'), animations: 'disabled' });

    await page.setViewportSize({ width: 390, height: 844 });
    check(await page.locator('#vehicle-fuel-type').isVisible() && await page.locator('#vehicle-fuel-confirmed').isVisible(), 'Fuel controls remain visible on a mobile viewport');
    check(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1), 'Add Vehicle form stays within the viewport at 390');
    await page.locator('#vehicle-fuel-type').scrollIntoViewIfNeeded();
    check(await page.locator('#vehicle-fuel-type').evaluate((node) => { const box = node.getBoundingClientRect(); return box.left >= 0 && box.right <= window.innerWidth + 1 && box.width > 120; }), 'Fuel select fits the mobile viewport at 390');
    await page.screenshot({ path: path.join(root, 'tmp/fuel-type-form-mobile.png'), animations: 'disabled' });

    check(errors.length === 0, 'No page errors during the fuel type walkthrough: ' + errors.join(' | '));
    console.log(`${checks} fuel type UI checks passed.`);
  } finally {
    if (browser) await browser.close();
    try { php('--clean'); } catch (error) { console.error('Fixture cleanup failed: ' + error.message); }
  }
})().catch((error) => { console.error(error.stack || error.message); process.exit(1); });
