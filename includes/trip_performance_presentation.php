<?php
/** Presentation only: readable stored evaluation facts, without technical payloads. */
function performance_display_facts($stored): array {
 $data=is_array($stored)?$stored:json_decode((string)$stored,true);
 if(!is_array($data))return [];
 $labels=['trip_id'=>'Trip','status'=>'Status','kind'=>'Evidence type','description'=>'Description','notes'=>'Notes','reason'=>'Reason','resolution'=>'Resolution','decision'=>'Decision','overall_score'=>'Overall score','overall'=>'Overall score','delay_cause'=>'Delay responsibility','delay_notes'=>'Delay explanation','verification_notes'=>'Verification notes','scheduled_arrival'=>'Scheduled delivery','actual_arrival'=>'Actual arrival','completion_arrival'=>'Completion arrival','predicted_arrival'=>'Predicted arrival','variance_minutes'=>'Delivery variance (minutes)','actual_source'=>'Arrival basis','scheduled_source'=>'Schedule basis','submitted_at'=>'Submitted','finalized_at'=>'Finalized','change_reason'=>'Review reason'];
 $facts=[];
 foreach($labels as $key=>$label)if(array_key_exists($key,$data)&&is_scalar($data[$key]))$facts[$label]=(string)$data[$key];
 foreach(['timing','timing_snapshot'] as $key)if(isset($data[$key]))$facts+=performance_display_facts($data[$key]);
 if(!empty($data['scoring_basis']['description']))$facts['Score calculation basis']=$data['scoring_basis']['description'];
 foreach(['liters'=>'Fuel (liters)','actual_fuel_liters'=>'Actual fuel (liters)','distance_km'=>'Recorded distance (km)','transaction_date'=>'Fuel transaction date','odometer'=>'Odometer','final_odometer'=>'Final odometer','efficiency'=>'Fuel efficiency','receipt_no'=>'Receipt'] as $key=>$label)if(isset($data[$key])&&is_scalar($data[$key]))$facts[$label]=(string)$data[$key];
 foreach(['scores','weights'] as $key)if(isset($data[$key])&&is_string($data[$key]))$data[$key]=json_decode($data[$key],true);
 if(isset($data['weights'])&&is_array($data['weights']))foreach($data['weights'] as $key=>$weight)if(isset(PERFORMANCE_CRITERIA[$key])&&is_numeric($weight))$facts[PERFORMANCE_CRITERIA[$key].' weight']=$weight.'%';
 if(isset($data['scores'])&&is_array($data['scores']))foreach($data['scores'] as $key=>$score){
  $label=PERFORMANCE_CRITERIA[$key]??null;if(!$label||!is_array($score))continue;
  $facts[$label]=isset($score['score'])?$score['score'].' / 100':(($score['status']??'')==='excluded'?'Excluded — no recorded fuel consumption':'Not evaluated');
  if(!empty($score['notes']))$facts[$label.' explanation']=$score['notes'];
  if(in_array($score['delivery_outcome']??null,['on_time','late'],true))$facts['Warehouse assessment']=$score['delivery_outcome']==='on_time'?'On time':'Late';
 }
 return $facts;
}
function performance_render_facts(array $facts): void {
 if(!$facts){echo '<p class="small text-muted-custom mb-0">No additional display details recorded.</p>';return;}
 echo '<dl class="performance-facts mb-0">';
 foreach($facts as $label=>$value)echo '<div><dt>'.e($label).'</dt><dd>'.e($value).'</dd></div>';
 echo '</dl>';
}
