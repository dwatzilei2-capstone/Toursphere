const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
const read = file => fs.readFileSync(path.join(__dirname, '..', file), 'utf8');
const source = read('js/map-route.js');
const start = source.indexOf('  updateDispatchStartButton() {');
const end = source.indexOf('\n  }', start) + 4;
const button = {};
const context = {window: {TC_ROUTE_CONTEXT: {isDriver: true}}, document: {getElementById: () => button}};
const engine = vm.runInNewContext('({' + source.slice(start, end) + '})', context);
engine.appliedRoute={routeId:'test'};
engine.currentRouteData={routeId:'test'};
for (const status of ['Scheduled','Assigned','Ready for Dispatch','Confirmed','Cancelled','Rejected','Completed',null]) {
  context.window.TC_ROUTE_CONTEXT.tripStatus = status;
  engine.updateDispatchStartButton();
  assert.equal(button.disabled, true);
  assert.equal(button.textContent, 'Waiting for Dispatch');
}
for (const status of ['Dispatched','In Transit','Returning to Depot']) {
  context.window.TC_ROUTE_CONTEXT.tripStatus = status;
  engine.updateDispatchStartButton();
  assert.equal(button.disabled, false);
}
const route = read('actions/route-start.php');
engine.currentRouteData={routeId:'preview'};engine.updateDispatchStartButton();assert.equal(button.disabled,true);assert.equal(button.textContent,'Apply Route First');
engine.currentRouteData={routeId:'test'};engine.arrivalConfirmed=true;engine.updateDispatchStartButton();assert.equal(button.disabled,true);assert.equal(button.textContent,'Destination Reached');
assert.ok(route.indexOf("!in_array($trip['status'], ['Dispatched', 'In Transit', 'Returning to Depot']") < route.indexOf('INSERT INTO route_history'));
assert.match(route, /AND driver_id = \?/);
assert.match(route, /assigned_vehicle_id.*!==.*trip\['vehicle_id'\]/);
const transition = read('actions/trip-status.php');
assert.ok(transition.indexOf("$lockedTrip['status'] !== 'Dispatched'") < transition.indexOf('UPDATE reservations SET status'));
assert.match(transition, /SELECT \* FROM trips WHERE reservation_id=\? FOR UPDATE/);
assert.match(transition, /active maintenance repair/);
assert.match(read('actions/dispatch.php'), /UPDATE trips t SET status='Dispatched'/);
console.log('26 workflow UI and server-guard regression checks passed.');
