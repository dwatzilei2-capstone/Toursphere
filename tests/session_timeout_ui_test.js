const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(path.join(__dirname,'../js/session-timeout.js'),'utf8');
const nodes = new Map(); const listeners = new Map(); const intervals=[];
let now=0,remaining=59,redirect=null,shown=0,hidden=0,expired=false;
const node = id => {
  if(!nodes.has(id))nodes.set(id,{textContent:'',disabled:false,contains:()=>false,focus:()=>{},addEventListener:(type,handler)=>listeners.set(`${id}:${type}`,handler)});
  return nodes.get(id);
};
const response = data => ({status:expired?401:200,ok:!expired,headers:{get:()=>null},json:async()=>data});
const context={
  document:{getElementById:node,hidden:false,addEventListener:(type,handler)=>listeners.set(type,handler),querySelectorAll:()=>[]},
  bootstrap:{Modal:class {show(){shown++;}hide(){hidden++;}}},performance:{now:()=>now},
  location:{href:'http://localhost/fleet/dashboard.php',origin:'http://localhost'},URL,FormData,
  setInterval:handler=>intervals.push(handler),setTimeout:()=>1,clearTimeout:()=>{},
  window:{TC_SESSION:{remaining:59,csrf:'test',userId:1},TC_BASE_URL:'/fleet',
    location:{replace:url=>{redirect=url;}},addEventListener:(type,handler)=>listeners.set(type,handler),
    fetch:async(url,options)=>{ if(options?.body?.get('action')==='activity')remaining=300; return response({ok:!expired,remaining}); }
  }
};
const flush=async()=>{for(let i=0;i<8;i++)await Promise.resolve();};
(async()=>{
  vm.runInNewContext(source,context); await flush();
  assert.equal(shown,1); assert.equal(node('session-warning-countdown').textContent,'00:59');
  assert.equal(listeners.has('mousemove'),false);
  listeners.get('session-stay:click')();await flush(); assert.equal(hidden,1);
  now=1000;intervals[0](); assert.equal(node('session-warning-countdown').textContent,'04:59');
  // Local zero is only a prompt for backend verification, not authoritative logout.
  remaining=280;now=302000;intervals[0]();await flush();assert.equal(redirect,null);
  expired=true;now=600000;intervals[0]();await flush();
  assert.match(redirect,/login\.php\?error=/);
  console.log('7 warning/countdown/frontend-authority assertions passed.');
})().catch(error=>{console.error(error);process.exitCode=1;});
