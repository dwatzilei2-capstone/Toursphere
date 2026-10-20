(() => {
  'use strict';
  const parse = value => {const [y,m,d]=value.split('-').map(Number);return new Date(y,m-1,d);};
  const iso = date => `${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}`;
  const pretty = value => parse(value).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
  function initialize() {
    const form=document.querySelector('.co-date-filter');if(!form)return;
    const from=form.elements.from,to=form.elements.to;
    const trigger=form.querySelector('.co-range-trigger'),popover=form.querySelector('.co-date-popover');
    const error=form.querySelector('.co-date-error'),status=form.querySelector('.co-date-status');
    const applied={from:from.value,to:to.value,source:form.elements.source.value};
    const view={from:parse(from.value),to:parse(to.value)};
    const today=parse(form.dataset.today),year=today.getFullYear(),month=today.getMonth();
    const presets={
      'this-month':[iso(new Date(year,month,1)),iso(new Date(year,month+1,0))],
      'last-month':[iso(new Date(year,month-1,1)),iso(new Date(year,month,0))],
      'this-year':[`${year}-01-01`,`${year}-12-31`],
      'last-year':[`${year-1}-01-01`,`${year-1}-12-31`]
    };
    function render() {
      let active='custom';for(const [key,dates] of Object.entries(presets))if(dates[0]===from.value&&dates[1]===to.value)active=key;
      form.querySelectorAll('[data-preset]').forEach(button=>{button.classList.toggle('active',button.dataset.preset===active);button.setAttribute('aria-pressed',String(button.dataset.preset===active));});
      form.querySelectorAll('[data-calendar]').forEach(calendar=>{
        const key=calendar.dataset.calendar,date=view[key];
        calendar.querySelector('strong').textContent=date.toLocaleDateString('en-US',{month:'long',year:'numeric'});
        const grid=calendar.querySelector('.co-calendar-grid');grid.replaceChildren();
        ['Su','Mo','Tu','We','Th','Fr','Sa'].forEach(day=>{const label=document.createElement('span');label.textContent=day;label.className='co-weekday';grid.append(label);});
        const first=new Date(date.getFullYear(),date.getMonth(),1),days=new Date(date.getFullYear(),date.getMonth()+1,0).getDate();
        for(let i=0;i<first.getDay();i++)grid.append(document.createElement('span'));
        for(let day=1;day<=days;day++){
          const value=iso(new Date(date.getFullYear(),date.getMonth(),day));const button=document.createElement('button');button.type='button';button.textContent=day;button.dataset.date=value;
          button.setAttribute('aria-label',pretty(value));button.setAttribute('aria-pressed',String(value===(key==='from'?from.value:to.value)));
          button.disabled=value<'2000-01-01'||value>'2100-12-31';
          if(value>=from.value&&value<=to.value)button.classList.add('in-range');
          if(value===from.value||value===to.value)button.classList.add('range-edge');
          if(value===form.dataset.today)button.classList.add('is-today');
          grid.append(button);
        }
      });
    }
    function close(cancel=false) {
      if(cancel){from.value=applied.from;to.value=applied.to;view.from=parse(from.value);view.to=parse(to.value);}
      popover.hidden=true;trigger.setAttribute('aria-expanded','false');error.hidden=true;
    }
    trigger.addEventListener('click',()=>{
      if(!popover.hidden){close(true);return;}
      render();popover.hidden=false;trigger.setAttribute('aria-expanded','true');popover.querySelector('[data-preset].active')?.focus();
    });
    form.addEventListener('click',event=>{
      if(event.target.closest('[data-date-reset]')){
        from.value=form.dataset.defaultFrom;to.value=form.dataset.defaultTo;
        view.from=parse(from.value);view.to=parse(to.value);close();form.requestSubmit();return;
      }
      const preset=event.target.closest('[data-preset]');
      if(preset){const dates=presets[preset.dataset.preset];if(dates){from.value=dates[0];to.value=dates[1];view.from=parse(from.value);view.to=parse(to.value);render();}else{form.querySelectorAll('[data-preset]').forEach(b=>{b.classList.toggle('active',b===preset);b.setAttribute('aria-pressed',String(b===preset));});from.focus();}error.hidden=true;}
      const step=event.target.closest('[data-month-step]');
      if(step){const key=step.closest('[data-calendar]').dataset.calendar;const d=view[key];const next=new Date(d.getFullYear(),d.getMonth()+Number(step.dataset.monthStep),1);if(next.getFullYear()>=2000&&next.getFullYear()<=2100){view[key]=next;render();}}
      const day=event.target.closest('[data-date]');
      if(day){const key=day.closest('[data-calendar]').dataset.calendar;form.elements[key].value=day.dataset.date;render();error.hidden=true;form.querySelector(`[data-calendar="${key}"] [data-date="${day.dataset.date}"]`)?.focus();}
      if(event.target.closest('[data-date-cancel]')){close(true);trigger.focus();}
    });
    [from,to].forEach(input=>input.addEventListener('change',()=>{if(input.validity.valid&&input.value){view[input.name]=parse(input.value);render();}error.hidden=true;}));
    form.addEventListener('keydown',event=>{if(event.key==='Escape'&&!popover.hidden){event.preventDefault();close(true);trigger.focus();}});
    const outside=event=>{if(!form.isConnected){document.removeEventListener('pointerdown',outside);return;}if(!form.contains(event.target))close(true);};
    document.addEventListener('pointerdown',outside);
    form.addEventListener('submit',async event=>{
      event.preventDefault();
      if(form.getAttribute('aria-busy')==='true')return;
      const invalid=!from.value||!to.value||from.value>to.value||Math.round((parse(to.value)-parse(from.value))/86400000)>3660;
      if(invalid){popover.hidden=false;trigger.setAttribute('aria-expanded','true');error.textContent='Choose a valid From and To date (up to 10 years), with From no later than To.';error.hidden=false;from.focus();return;}
      if(from.value===applied.from&&to.value===applied.to&&form.elements.source.value===applied.source){close();trigger.focus();return;}
      const url=new URL(location.href);url.searchParams.set('from',from.value);url.searchParams.set('to',to.value);url.searchParams.set('source',form.elements.source.value);
      form.querySelectorAll('button').forEach(b=>b.disabled=true);status.textContent='Updating…';form.setAttribute('aria-busy','true');
      try {
        const response=await fetch(url,{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});
        if(!response.ok)throw new Error('The selected range could not be loaded. Try again.');
        const html=new DOMParser().parseFromString(await response.text(),'text/html');
        const replacement=html.querySelector('#co-overview'),payload=html.querySelector('#co-filter-payload');
        if(!replacement||!payload)throw new Error('Your session may have expired. Sign in again, then retry.');
        const data=JSON.parse(payload.textContent);
        ['breakdown','allowance-cost'].forEach(id=>window.Chart?.getChart(id)?.destroy());
        document.querySelector('#co-overview').replaceWith(document.importNode(replacement,true));
        window.TC_ALLOWANCE_COST_DATA=data.allowance;window.TC_COST_BREAKDOWN_DATA=data.breakdown;
        window.initCostOverview();history.replaceState(null,'',url);initialize();
        document.querySelector('.co-range-trigger').focus();document.querySelector('.co-date-status').textContent='Date range updated.';
      } catch(err) {
        status.textContent=err.message;form.querySelectorAll('button').forEach(b=>b.disabled=false);form.removeAttribute('aria-busy');
      }
    });
  }
  initialize();
})();
