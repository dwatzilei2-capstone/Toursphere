<?php
/** Planning assumptions for testing, not manufacturer or measured efficiency. */
function vehicle_efficiency_value(?string $value): ?float {
    if (!preg_match('/^\s*(\d+(?:\.\d+)?)\s*(?:km\s*\/\s*l)?\s*(?:\(est\.\))?\s*$/i', $value ?? '', $match)) return null;
    $number = (float)$match[1];
    return $number > 0 && is_finite($number) ? $number : null;
}
/** Use independently verified measurement distance, never purchased fuel or route estimates. */
function vehicle_efficiency_reference(PDO $pdo,string $vehicleId,?string $configured):array {
    $q=$pdo->prepare("SELECT DISTINCT ON (rh.actual_fuel_transaction_id) rh.actual_fuel_liters,rh.pre_trip_features_json FROM route_history rh JOIN fuel_transactions f ON f.id=rh.actual_fuel_transaction_id WHERE rh.vehicle_id=? AND rh.actual_fuel_verified=1 AND rh.actual_fuel_liters>0 AND f.verification_status='Verified' ORDER BY rh.actual_fuel_transaction_id,rh.id DESC LIMIT 20");$q->execute([$vehicleId]);
    $distance=0.0;$liters=0.0;
    foreach($q->fetchAll() as $row){$measurement=json_decode($row['pre_trip_features_json']??'{}',true)['fuel_measurement']??[];
        if(filter_var($measurement['distance_verified']??false,FILTER_VALIDATE_BOOLEAN)&&is_numeric($measurement['distance_km']??null)&&(float)$measurement['distance_km']>0){$distance+=(float)$measurement['distance_km'];$liters+=(float)$row['actual_fuel_liters'];}
    }
    if($liters>0)return ['value'=>$distance/$liters,'source'=>'Verified historical consumption and distance'];
    $value=str_contains(strtolower((string)$configured),'(est.)')?null:vehicle_efficiency_value($configured);
    return ['value'=>$value,'source'=>$value?'Configured vehicle reference (planning only)':'Reliable vehicle efficiency unavailable'];
}
function vehicle_efficiency_default(string $type): string {
    $values = [
        // Passenger tour fleet
        'Tour Bus'=>3.8, 'Coaster Bus'=>6.5, 'Executive Van'=>9.5, 'VIP SUV'=>10.5, 'Minibus'=>6.0,
        // Logistics 2 cargo fleet
        'Cargo Van'=>12.0, 'Delivery Van'=>11.0, 'Pickup Truck'=>10.5, 'Light Truck'=>9.0,
        'Medium Truck'=>6.5, 'Heavy Truck'=>4.0, 'Box Truck'=>7.5,
    ];
    if (!isset($values[$type])) throw new InvalidArgumentException('Fuel efficiency must be configured for this vehicle type.');
    return number_format($values[$type], 1) . ' km/L (est.)';
}
