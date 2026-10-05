const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
const read = file => fs.readFileSync(path.join(__dirname, '..', file), 'utf8');
const source = read('js/app.js');
const start = source.indexOf('  setupModals() {');
const end = source.indexOf('\n  },', start) + 5;
const values = {}, events = {};
const viewport = {height: 720, offsetTop: 0, addEventListener: (type, callback) => {events[type] = callback;}};
const window = {visualViewport: viewport, innerHeight: 800, addEventListener() {}};
const app = vm.runInNewContext('({' + source.slice(start, end) + '})', {
  window, document: {documentElement: {style: {setProperty: (key, value) => {values[key] = value;}}}, querySelectorAll: () => []}
});
app.setupModals();
assert.equal(values['--tc-visible-height'], '720px');
viewport.height = 300; viewport.offsetTop = 130; events.resize();
assert.equal(values['--tc-visible-height'], '300px');
assert.equal(values['--tc-visible-top'], '130px');
window.visualViewport = null; events.resize();
assert.equal(values['--tc-visible-height'], '800px');
assert.equal(values['--tc-visible-top'], '0px');
const css = read('css/responsive-forms.css');
assert.match(css, /min-height: 0; min-width: 0; overflow-y: auto/);
assert.match(css, /\.tc-modal > form/);
assert.match(css, /drawer-driver-content/);
assert.match(css, /max-width: 575\.98px/);
assert.match(css, /safe-area-inset-bottom/);
console.log('10 responsive-form regression checks passed.');
