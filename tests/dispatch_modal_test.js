const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
const source=fs.readFileSync(path.join(__dirname,'../js/app.js'),'utf8');
const start=source.indexOf('  openDispatchModal('),end=source.indexOf('\n  },',start)+5;
const body={innerHTML:''};const context={document:{getElementById:()=>body},window:{TC_BASE_URL:'/fleet',TC_CAN_DISPATCH:true,location:{pathname:'/fleet/reservations'},TC_KANBAN_RESERVATIONS:[{id:'R',status:'Assigned',assignedVehicleId:'V1',assignedDriverId:'D1',passengerCount:5}],TC_DISPATCH_DATA:{vehicles:[],drivers:[]}}};
const app=vm.runInNewContext('({'+source.slice(start,end)+'})',context);let call=null;
app.escapeHtml=value=>String(value??'');app.openModal=()=>{};app.renderAssignmentForm=(...args)=>{call=args;};
app.openDispatchModal('R','assign');assert.equal(call[1],'assign');assert.equal(call[2],'V1');assert.equal(call[3],'D1');
app.openDispatchModal('R');assert.equal(call[1],'dispatch');
for(const status of ['Pending','Pending Approval','Dispatched','In Transit','Completed','Cancelled']){call=null;context.window.TC_KANBAN_RESERVATIONS[0].status=status;app.openDispatchModal('R','assign');assert.equal(call,null,status+' cannot assign');}
context.window.TC_KANBAN_RESERVATIONS[0].status='Approved';context.window.TC_CAN_DISPATCH=false;call=null;app.openDispatchModal('R','assign');assert.equal(call,null,'Assignment permission required');
console.log('11 dispatch modal permission and intent checks passed.');
