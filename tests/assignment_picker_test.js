const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(require('node:path').join(__dirname, '../js/app.js'), 'utf8');
const start = source.indexOf('  enhanceAssignmentPickers(');
const end = source.indexOf('\n  },', start) + 5;
class Element {
  constructor(tag) { this.tag = tag; this.children = []; this.events = {}; this.attributes = {}; this.classList = {add() {}}; }
  append(...nodes) { this.children.push(...nodes); }
  replaceChildren(...nodes) { this.children = nodes; }
  setAttribute(key, value) { this.attributes[key] = value; }
  addEventListener(key, callback) { this.events[key] = callback; }
  focus() { this.focused = true; }
}
const app = vm.runInNewContext('({' + source.slice(start, end) + '})', {
  document: {createElement: tag => new Element(tag), createTextNode: text => ({textContent: text})},
  Event: class {constructor(type) {this.type = type;}}, setTimeout
});
for (const label of ['Assign Vehicle', 'Assign Driver']) {
  const select = new Element('select');
  select.dataset = {assignmentPicker: label};
  select.options = [
    {value: 'available', textContent: 'Available resource', disabled: false, dataset: {}},
    {value: 'blocked', textContent: 'Blocked resource — UNAVAILABLE', disabled: true, dataset: {reason: 'On Trip'}}
  ];
  select.selectedOptions = [select.options[0]];
  select.after = node => {select.picker = node;};
  select.dispatchEvent = event => select.events[event.type]?.();
  app.enhanceAssignmentPickers({querySelectorAll: () => [select]});
  const [trigger, list] = select.picker.children;
  const badge = row => row.children[0].children[0].children[1];
  assert.equal(badge(list.children[0]).textContent, 'Available');
  assert.equal(badge(list.children[1]).textContent, 'Unavailable');
  assert.equal(list.children[1].disabled, true);
  assert.equal(list.children[1].children[0].children[1].textContent, 'On Trip');
  list.children[1].events.click();
  assert.equal(select.value, undefined);
  list.children[0].events.click();
  assert.equal(select.value, 'available');
  assert.equal(select.picker.open, false);
  assert.equal(trigger.focused, true);
  select.disabled = true;
  select.value = 'unchanged';
  list.children[0].events.click();
  assert.equal(select.value, 'unchanged');
}
console.log('18 assignment-picker assertions passed.');
