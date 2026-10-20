window.initCostOverview=()=>{
 'use strict';document.body.classList.add('cost-analysis-motion-ready');
 const existingTooltip=document.getElementById('co-finance-tooltip');if(existingTooltip)existingTooltip.hidden=true;
 const currency=window.fleetCurrencySymbol||'₱',money=value=>value===null?'Not recorded':(value<0?'−':'')+currency+Math.abs(Number(value)).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
 const empty=id=>{const canvas=document.getElementById(id);canvas.hidden=true;const message=document.createElement('div');message.className='co-chart-empty';message.textContent='No data available';canvas.parentElement.append(message);};
 const tooltip=({chart,tooltip})=>{let panel=document.getElementById('co-finance-tooltip');if(!panel){panel=document.createElement('div');panel.id='co-finance-tooltip';panel.className='co-finance-tooltip';panel.setAttribute('role','tooltip');document.body.append(panel);}if(!tooltip.opacity){panel.hidden=true;return;}panel.replaceChildren();const add=(value,cls)=>{const row=document.createElement('div');row.className=cls;row.textContent=value;panel.append(row);};(tooltip.title||[]).forEach(t=>add(t,'co-tip-title'));(tooltip.body||[]).forEach(b=>b.lines.forEach(t=>add(t,'')));(tooltip.footer||[]).forEach(t=>add(t,'co-tip-footer'));panel.hidden=false;const r=chart.canvas.getBoundingClientRect();panel.style.left=Math.max(8,Math.min(r.left+tooltip.caretX+10,innerWidth-panel.offsetWidth-8))+'px';panel.style.top=Math.max(8,Math.min(r.top+tooltip.caretY-panel.offsetHeight-10,innerHeight-panel.offsetHeight-8))+'px';};
 const scales=stacked=>({x:{stacked,grid:{color:'#edf3fc'},ticks:{maxRotation:0,autoSkip:true,maxTicksLimit:12,font:{size:10}}},y:{stacked,beginAtZero:true,grid:{color:'#edf3fc'},ticks:{font:{size:10},callback:value=>currency+Number(value).toLocaleString('en-PH',{notation:'compact',maximumFractionDigits:1})}}});
 if(window.Chart){const b=window.TC_COST_BREAKDOWN_DATA;
 if(b.hasData&&b.datasets.some(d=>d.data.some(v=>v!==null)))new Chart(document.getElementById('breakdown'),{type:'bar',data:b,options:{responsive:true,maintainAspectRatio:false,animation:false,scales:scales(true),interaction:{mode:'index',intersect:false},plugins:{legend:{display:false},tooltip:{enabled:false,external:tooltip,callbacks:{title:items=>{const i=items[0].dataIndex;return [b.labels[i],'Selected: '+b.currentDates[i],'Comparison: '+b.previousDates[i]];},label:c=>`${c.dataset.label}: ${money(c.parsed.y)}`,footer:items=>['current','previous'].map(stack=>`${stack==='current'?'Selected':'Comparison'} total: ${money(items.filter(i=>i.dataset.stack===stack).reduce((sum,i)=>sum+i.parsed.y,0))}`)}}}}});else empty('breakdown');
 const finance=window.TC_ALLOWANCE_COST_DATA;
 if(finance.hasData&&finance.datasets.some(d=>d.data.some(v=>v!==null))){
 const metrics=finance.metrics;
 finance.datasets.forEach((d,i)=>Object.assign(d,{borderColor:i?'#1677ff':'#00b67a',backgroundColor:i?'rgba(22,119,255,.06)':'rgba(0,182,122,.08)',borderWidth:2.5,pointRadius:3,pointHoverRadius:5,tension:.25,cubicInterpolationMode:'monotone',fill:'origin',spanGaps:false}));
 const s=scales(false);s.x.ticks.callback=function(value){const text=this.getLabelForValue(value);return new Set(finance.labels.map(l=>l.split(' ').pop())).size===1?text.split(' ')[0]:text;};
 const chart=new Chart(document.getElementById('allowance-cost'),{type:'line',data:finance,options:{responsive:true,maintainAspectRatio:false,animation:false,scales:s,interaction:{mode:'index',intersect:false},plugins:{legend:{display:false},tooltip:{enabled:false,external:tooltip,callbacks:{title:items=>[items[0].label,finance.dates[items[0].dataIndex]],label:c=>`${c.dataset.label}: ${money(c.parsed.y)}`,footer:items=>{const m=metrics[items[0].dataIndex];return [`Cost per completed delivery: ${money(m.average)}`,`Allowance utilization: ${m.utilization===null?'Comparison unavailable':m.utilization.toFixed(1)+'%'}`,`${m.comparable_count} comparable funded deliveries`, 'Utilization is spending against matched funding, not an efficiency score.'];}}}}}});
 const container=document.getElementById('co-revenue-highlights');container.replaceChildren();
 [['Highest Approved Allowance','allowance','↗',false],['Highest Operating Cost','cost','↑',false],['Lowest Cost per Completed Delivery','average','↓',true],['Highest Allowance Utilization','utilization','%',false]].forEach(([title,key,icon,lowest])=>{
  const candidates=metrics.map((m,i)=>({m,i})).filter(({m})=>m[key]!==null);const best=candidates.reduce((winner,item)=>!winner||(lowest?item.m[key]<winner.m[key]:item.m[key]>winner.m[key])?item:winner,null);
  const card=document.createElement('button');card.type='button';card.className='co-revenue-highlight';card.disabled=!best;
  const symbol=document.createElement('span');symbol.className='co-revenue-symbol';symbol.textContent=icon;symbol.setAttribute('aria-hidden','true');
  const content=document.createElement('span'),label=document.createElement('small'),month=document.createElement('span'),value=document.createElement('strong');label.textContent=title;month.textContent=best?best.m.label:'Insufficient data';value.textContent=best?(key==='utilization'?best.m[key].toFixed(1)+'%':money(best.m[key])):'—';
  if(key==='utilization')card.title='Highest spending as a share of comparable approved funding; higher is not automatically better.';
  content.append(label,month,value);card.append(symbol,content);container.append(card);
  if(best)card.addEventListener('click',()=>{const active=chart.data.datasets.map((d,datasetIndex)=>({datasetIndex,index:best.i})).filter(p=>chart.data.datasets[p.datasetIndex].data[p.index]!==null);chart.setActiveElements(active);chart.tooltip.setActiveElements(active,{x:chart.scales.x.getPixelForValue(best.i),y:chart.chartArea.top+20});chart.update();});
 });
 }else{empty('allowance-cost');document.getElementById('co-revenue-highlights').hidden=true;}
 }
 document.querySelectorAll('[data-table]').forEach(button=>button.addEventListener('click',()=>{const rows=[...document.querySelectorAll(`#${button.dataset.table} tbody tr`)];const expand=rows.some(row=>row.hidden);rows.forEach((row,i)=>row.hidden=!expand&&i>=5);button.textContent=expand?'Show recent 5 ‹':`View all (${rows.length}) ›`;}));
};
window.initCostOverview();
const hideOverviewFinanceTooltip=()=>{const tip=document.getElementById('co-finance-tooltip');if(tip)tip.hidden=true;};
window.addEventListener('scroll',hideOverviewFinanceTooltip,{passive:true,capture:true});
window.addEventListener('resize',hideOverviewFinanceTooltip,{passive:true});
