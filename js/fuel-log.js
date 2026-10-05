(() => {
 'use strict';
 const form=document.querySelector('#modal-log-fuel form');if(!form)return;
 const context=window.TC_FUEL_CONTEXT||{},defaults=window.TC_FUEL_DEFAULTS||{};
 const hidden=(name,value)=>{let el=form.elements[name];if(!el){el=document.createElement('input');el.type='hidden';el.name=name;form.append(el)}el.value=value??'';};
 hidden('csrf',context.csrf);hidden('system_fuel_price_id','');
 const price=form.elements.price_per_liter,liters=form.elements.liters,type=form.elements.fuel_type;
 const total=document.createElement('div');total.className='fuel-total-preview col-12';total.setAttribute('aria-live','polite');form.querySelector('.row').append(total);
 const description=document.createElement('small');description.className='text-muted-custom';price.parentElement.append(description);
 if(context.trip_id){const row=document.createElement('div');row.className='col-12';row.textContent='Trip: '+context.trip_id;form.querySelector('.row').prepend(row);}
 function calculate(){const cost=Number(liters.value)*Number(price.value);total.textContent='Total Fuel Cost: '+(window.fleetCurrencySymbol||'₱')+(Number.isFinite(cost)&&cost>0?cost:0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});}
 function apply(){const d=defaults[context.vehicle_id||form.elements.vehicle_id?.value];price.value=d?.price||'';hidden('system_fuel_price_id',d?.price_id);if(d?.fuel_type){if(!Array.from(type.options).some(o=>o.value===d.fuel_type))type.add(new Option(d.fuel_type,d.fuel_type));type.value=d.fuel_type;}type.disabled=!!d;description.textContent=d?.price?'System default — editable for the actual pump price.':'Current fuel price unavailable. Enter the actual pump price.';calculate();}
 price.addEventListener('input',calculate);liters.addEventListener('input',calculate);form.elements.vehicle_id?.addEventListener('change',apply);apply();
})();
