(() => {
  'use strict';
  document.body.classList.add('cost-analysis-motion-ready');
  document.querySelectorAll('.vc-selection select').forEach(select=>select.addEventListener('change',()=>document.getElementById('vc-filter').requestSubmit()));
  const payload=window.TC_VEHICLE_COST_DATA;if(!payload?.hasData||!window.Chart)return;
  const series=payload.series;
  const currency=window.fleetCurrencySymbol||'₱';
  const money=value=>currency+Number(value).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
  const values=key=>series.map(s=>s.count?(s[key]===null?null:(key==='utilization'?s[key]:s[key]/100)):null);
  const colors={fuel:'#1677ff',maintenance:'#ff923e',toll:'#8c60ed',other:'#64748b'};
  const dataset=(label,key,color,type='line',axis='y')=>({label,data:values(key),type,yAxisID:axis,borderColor:color,backgroundColor:type==='bar'?color:color+'22',borderWidth:type==='bar'?0:2,pointRadius:3,pointHoverRadius:5,tension:.25,spanGaps:false,fill:false,maxBarThickness:35});
  const axis={beginAtZero:true,border:{display:false},grid:{color:'#edf2f8'},ticks:{font:{size:9},maxTicksLimit:5,callback:v=>currency+Number(v).toLocaleString('en-PH',{notation:'compact',maximumFractionDigits:1})}};
  // Render tooltips outside the small canvas and clipped chart-card containers.
  const floatingTooltip=({chart,tooltip})=>{
    let panel=document.getElementById('vc-floating-tooltip');
    if(!panel){panel=document.createElement('div');panel.id='vc-floating-tooltip';panel.className='vc-floating-tooltip';panel.setAttribute('role','tooltip');document.body.append(panel);}
    if(!tooltip.opacity){panel.hidden=true;return;}
    panel.replaceChildren();
    const add=(text,className)=>{const line=document.createElement('div');line.className=className;line.textContent=text;panel.append(line);};
    (tooltip.title||[]).forEach(line=>add(line,'vc-tip-title'));
    (tooltip.body||[]).forEach((body,index)=>body.lines.forEach(line=>{const row=document.createElement('div');row.className='vc-tip-row';const swatch=document.createElement('i');const point=tooltip.dataPoints[index];swatch.style.backgroundColor=point?chart.data.datasets[point.datasetIndex].borderColor:'#1677ff';const label=document.createElement('span');label.textContent=line;row.append(swatch,label);panel.append(row);}));
    (tooltip.footer||[]).forEach(line=>add(line,'vc-tip-footer'));
    panel.hidden=false;
    const box=chart.canvas.getBoundingClientRect(),width=panel.offsetWidth,height=panel.offsetHeight;
    const x=box.left+tooltip.caretX,y=box.top+tooltip.caretY;
    panel.style.left=Math.max(8,Math.min(x+12,window.innerWidth-width-8))+'px';
    panel.style.top=Math.max(8,Math.min(y-height-12,window.innerHeight-height-8))+'px';
  };
  const hideTooltip=()=>{const panel=document.getElementById('vc-floating-tooltip');if(panel)panel.hidden=true;};
  window.addEventListener('scroll',hideTooltip,{passive:true,capture:true});
  window.addEventListener('resize',hideTooltip,{passive:true});
  const chartValueLabels={
    id:'vehicleReadableValues',
    afterDatasetsDraw(chart){
      if(!['vc-trend','vc-profit'].includes(chart.canvas.id))return;
      const {ctx,chartArea}=chart;
      const lastIndex=chart.data.labels.length-1;
      const labelStride=Math.max(1,Math.ceil(chart.data.labels.length*48/chartArea.width));
      const labelAt=index=>(lastIndex-index)%labelStride===0;
      const compact=value=>currency+Number(value).toLocaleString('en-PH',{notation:'compact',maximumFractionDigits:1});
      const draw=(text,x,y,color)=>{ctx.save();ctx.font='600 9px Poppins, system-ui, sans-serif';ctx.textAlign='center';ctx.textBaseline='middle';const half=ctx.measureText(text).width/2;x=Math.max(half+2,Math.min(x,chart.width-half-2));y=Math.max(chartArea.top+6,Math.min(y,chartArea.bottom-5));ctx.lineWidth=3;ctx.strokeStyle=color==='#fff'?'rgba(0,100,62,.25)':'rgba(255,255,255,.95)';ctx.strokeText(text,x,y);ctx.fillStyle=color;ctx.fillText(text,x,y);ctx.restore();};
      if(chart.canvas.id==='vc-trend'){
        chart.data.labels.forEach((label,index)=>{
          if(!labelAt(index))return;
          let total=0,top=Infinity,x=null;
          chart.data.datasets.forEach((dataset,di)=>{if(!chart.isDatasetVisible(di)||dataset.data[index]===null)return;const point=chart.getDatasetMeta(di).data[index];if(!point)return;total+=dataset.data[index];top=Math.min(top,point.y);x=point.x;});
          if(x!==null)draw(compact(total),x,top-8,'#273c70');
        });
      }else{
        chart.data.datasets.forEach((dataset,di)=>{if(!chart.isDatasetVisible(di))return;chart.getDatasetMeta(di).data.forEach((point,index)=>{const value=dataset.data[index];if(value===null||!labelAt(index))return;draw(compact(value),point.x,point.y-8,'#273c70');});});
      }
    }
  };
  function render(id,data,extra={}){
    const canvas=document.getElementById(id);if(!canvas)return;
    if(!data.some(d=>d.data.some(v=>v!==null))){canvas.hidden=true;const empty=document.createElement('div');empty.className='vc-empty';empty.textContent='Insufficient Data';canvas.parentElement.append(empty);return;}
    new Chart(canvas,{plugins:[chartValueLabels,{id:'compactVehiclePresentation',afterInit(chart){const labels=chart.options.plugins.legend.labels;labels.font={...labels.font,size:9};labels.padding=8;labels.boxWidth=7;labels.boxHeight=7;for(const [key,scale] of Object.entries(chart.options.scales)){if(!scale.ticks)continue;scale.ticks.font={...scale.ticks.font,size:9};scale.ticks.padding=4;scale.ticks.maxTicksLimit=key==='x'?6:4;}}}],type:id==='vc-profit'?'bar':'line',data:{labels:series.map(s=>s.label),datasets:data},options:{responsive:true,maintainAspectRatio:false,animation:false,layout:{padding:{top:3,right:3,bottom:0,left:0}},interaction:{mode:'index',intersect:false},scales:{x:{grid:{display:false},ticks:{maxRotation:0,autoSkip:true,maxTicksLimit:6,font:{size:9}}},y:{...axis},...extra},plugins:{legend:{position:'bottom',labels:{boxWidth:7,boxHeight:7,font:{size:9},padding:8}},tooltip:{enabled:false,external:floatingTooltip,callbacks:{label:c=>`${c.dataset.label}: ${c.dataset.yAxisID==='margin'?Number(c.parsed.y).toFixed(1)+'%':money(c.parsed.y)}`,title:items=>{const s=series[items[0].dataIndex];return [s.label,s.from+' – '+s.to];},footer:items=>{const s=series[items[0].dataIndex];return [`Approved allowance: ${s.allowance===null?'Not funded':money(s.allowance/100)}`,`Remaining / Shortfall: ${s.allowance_balance===null?'Comparison unavailable':money(s.allowance_balance/100)}`,`Allowance utilization: ${s.utilization===null?'Unavailable':s.utilization.toFixed(1)+'%'}`,`${s.comparable_count} comparable funded deliveries`,`${s.count} completed deliveries`];}}}}}});
  }
  render('vc-trend',Object.entries(colors).map(([key,color])=>({...dataset(key==='other'?'Other':key[0].toUpperCase()+key.slice(1),key,color,'bar'),stack:'operating',borderRadius:2})),{x:{stacked:true,grid:{display:false},ticks:{maxRotation:0,autoSkip:true,maxTicksLimit:6,font:{size:9}}},y:{...axis,stacked:true,grace:'20%'}});
  render('vc-revenue',[dataset('Approved Allowance','allowance','#00b67a','bar'),dataset('Actual Operating Cost','cost','#1677ff','bar')]);
  render('vc-profit',[dataset('Cost per Kilometer','eligible_cost_km','#8c60ed','bar')],{y:{...axis,grace:'20%'}});
  render('vc-average',[{...dataset('Cost per Delivery','average','#8c60ed'),fill:'origin'}]);
})();

