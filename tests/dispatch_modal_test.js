const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../js/app.js'), 'utf8');
const start = source.indexOf('  openDispatchModal(');
const end = source.indexOf('\n  },', start) + 5;
const body = {innerHTML: ''};
const context = {
  document: {getElementById: () => body},
  window: {
    TC_BASE_URL: '/fleet', location: {pathname: '/fleet/reservations'},
    TC_KANBAN_RESERVATIONS: [{id:'R',status:'Assigned',assignedVehicleId:'V1',assignedDriverId:'D1',assignedVehicle:'V1 (AAA)',assignedDriver:'Test',passengerCount:5}],
    TC_DISPATCH_DATA: {
      vehicles: ['V1','V2'].map(id => ({id,capacity:10,is_operational:true,status:'Available',plate_number:id,brand:'Test',model:'Van'})),
      drivers: [{id:'D1',name:'Test',status:'Assigned'},{id:'D2',name:'Other',status:'Active'}]
    }
  }
};
const app = vm.runInNewContext('({' + source.slice(start,end) + '})',context);
app.escapeHtml = value => String(value ?? ''); app.openModal = () => {};
app.openDispatchModal('R','assign');
assert.match(body.innerHTML, /name="vehicle_id" required/);
assert.match(body.innerHTML, /name="driver_id" required/);
assert.match(body.innerHTML, /Save Assignment/);
assert.doesNotMatch(body.innerHTML, /value="dispatch"/);
app.openDispatchModal('R');
assert.match(body.innerHTML, /Assigned vehicle \(locked\)/);
assert.match(body.innerHTML, /Assigned driver \(locked\)/);
assert.match(body.innerHTML, /value="dispatch"/);
assert.doesNotMatch(body.innerHTML, /Save Assignment/);
assert.doesNotMatch(body.innerHTML, /<option value="V2"/);
assert.doesNotMatch(body.innerHTML, /<option value="D2"/);
console.log('10 dispatch-modal assertions passed.');
