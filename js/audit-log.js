(() => {
 'use strict';
 const data = document.getElementById('audit-activities');
 const modal = document.getElementById('audit-details-modal');
 if (!data || !modal) return;
 const activities = JSON.parse(data.textContent);
 const body = document.getElementById('audit-modal-body');
 let trigger;
 const element = (tag, className, text) => { const node=document.createElement(tag); if(className)node.className=className; if(text!==undefined)node.textContent=text; return node; };
 const section = (title) => { const node=element('section','audit-section'); node.append(element('h3','',title)); body.append(node); return node; };
 const information = (title, pairs) => {
  if (!pairs.length) return;
  const block=section(title), list=element('dl','audit-info-list');
  pairs.forEach(([label,value])=>{ const row=element('div'); row.append(element('dt','',label),element('dd','',value)); list.append(row); }); block.append(list);
 };
 modal.addEventListener('show.bs.modal',event=>{
  trigger=event.relatedTarget;
  const activity=activities[Number(event.relatedTarget?.dataset.auditIndex)];
  if(!activity){event.preventDefault();return;}
  body.replaceChildren();
  const summary=element('div','audit-activity-summary');
  const icon=element('span','audit-activity-icon audit-'+activity.category[1]);
  icon.append(element('i', activity.module==='Login / Security'?'bi bi-shield-check':(activity.category[0]==='Archived'?'bi bi-archive':'bi bi-clock-history')));
  const copy=element('div','audit-summary-copy'); copy.append(element('h3','',activity.action),element('p','',activity.time));
  summary.append(icon,copy,element('span','audit-badge audit-'+activity.category[1],activity.category[0])); body.append(summary);
  information('Activity',[['Action',activity.action],['Module',activity.module],['Record',activity.record],['Date & Time',activity.time],['Complete Timestamp',activity.timestamp]]);
  const performer=section('Performed By'), person=element('div','audit-performer');
  person.append(element('span','audit-avatar',activity.name.slice(0,1).toUpperCase()));
  const personCopy=element('div');personCopy.append(element('div','audit-performer-name',activity.name),element('small','',activity.roleFull.replaceAll('_',' ')));
  if(activity.email){const email=element('a','',activity.email);email.href='mailto:'+activity.email;personCopy.append(email);}
  person.append(personCopy,element('span','audit-badge audit-'+(activity.role==='Unknown'?'muted':'primary'),activity.role));performer.append(person);
  if(activity.roleCurrent)performer.append(element('p','audit-current-role','Current account role shown; the historical role was not stored.'));
  if(activity.changes.length){
   const changes=section('Changes'),scroll=element('div','audit-change-scroll'),table=element('table','audit-changes'),head=element('thead'),header=element('tr'),rows=element('tbody');
   ['Field','Previous Value','New Value'].forEach(text=>{const th=element('th','',text);th.scope='col';header.append(th);});head.append(header);
   activity.changes.forEach(values=>{const row=element('tr');values.forEach(value=>row.append(element('td','',value)));rows.append(row);});table.append(head,rows);scroll.append(table);changes.append(scroll);
  } else {section('Activity Description').append(element('p','audit-description',activity.description));}
  information('Record Information',activity.recordInfo);
  information('Security Information',activity.security);
  information('Additional Details',activity.information);
  const technical=element('details','audit-technical');technical.append(element('summary','','Technical Details (Raw Data)'),element('pre','',JSON.stringify(activity.raw,null,2)));body.append(technical);
  body.scrollTop=0;
 });
 modal.addEventListener('hidden.bs.modal',()=>{body.replaceChildren();trigger?.focus({preventScroll:true});});
})();
