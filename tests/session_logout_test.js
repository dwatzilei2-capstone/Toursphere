const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');
const source = fs.readFileSync(require('node:path').join(__dirname,'../js/session-timeout.js'),'utf8');
const flush = async () => { for(let i=0;i<20;i++) await Promise.resolve(); };
function setup() {
  const events = {}, state = {reply:{ok:true,remaining:300}, status:200,redirect:null};
  const element = id => ({contains:()=>false,focus(){},addEventListener:(type,fn)=>{events[id+':'+type]=fn;}});
  const response = () => ({status:state.status,ok:state.status===200,url:'http://localhost/fleet/actions/session.php',headers:{get:()=>null},json:async()=>state.reply,clone(){return this;}});
  const window = {TC_SESSION:{remaining:300,userId:1,csrf:'test'},TC_BASE_URL:'/fleet',fetch:async()=>response(),location:{replace:url=>{state.redirect=url;}},addEventListener:(type,fn)=>{events[type]=fn;}};
  const channel = {addEventListener:(type,fn)=>{events.channel=fn;},postMessage(){}};
  vm.runInNewContext(source,{window,document:{getElementById:element,querySelectorAll:()=>[],hidden:false,addEventListener:(type,fn)=>{events[type]=fn;}},bootstrap:{Modal:class{show(){}hide(){}}},performance:{now:()=>0},URL,FormData,location:{href:'http://localhost/fleet/dashboard.php',origin:'http://localhost'},setInterval(){},setTimeout(){},clearTimeout(){},BroadcastChannel:class{constructor(){return channel;}}});
  return {state,events,window};
}
(async()=>{
  for(const expired of [false,true]) {
    const t=setup(); await flush(); t.state.status=401;t.state.reply={session_expired:expired};
    await t.window.fetch('/fleet/actions/notifications.php');
    assert.equal(t.state.redirect.includes('?error='),expired);
  }
  const modal=setup();await flush();modal.state.status=401;modal.state.reply={session_expired:true};modal.events['session-logout:click']();await flush();
  assert.equal(modal.state.redirect,'/fleet/login.php');
  const link=setup();await flush();link.events.click({type:'click',button:0,isTrusted:true,target:{closest:()=>({href:'http://localhost/fleet/logout.php'})}});
  link.state.status=401;link.state.reply={session_expired:true};await link.window.fetch('/fleet/actions/notifications.php');
  assert.equal(link.state.redirect,null);
  const other=setup();await flush();other.state.status=401;other.state.reply={session_expired:false};other.events.channel();await flush();
  assert.equal(other.state.redirect,'/fleet/login.php');
  console.log('5 manual-logout, real-expiry, pending-request, and cross-tab checks passed.');
})().catch(e=>{console.error(e);process.exitCode=1;});
