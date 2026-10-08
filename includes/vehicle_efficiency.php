<?php
/** Planning assumptions for testing, not manufacturer or measured efficiency. */
function vehicle_efficiency_value(?string $value): ?float {
    if (!preg_match('/^\s*(\d+(?:\.\d+)?)\s*(?:km\s*\/\s*l)?\s*(?:\(est\.\))?\s*$/i', $value ?? '', $match)) return null;
    $number = (float)$match[1];
    return $number > 0 && is_finite($number) ? $number : null;
}
function vehicle_efficiency_default(string $type): string {
    $values = ['Tour Bus'=>3.8, 'Coaster Bus'=>6.5, 'Executive Van'=>9.5, 'VIP SUV'=>10.5, 'Minibus'=>6.0];
    if (!isset($values[$type])) throw new InvalidArgumentException('Fuel efficiency must be configured for this vehicle type.');
    return number_format($values[$type], 1) . ' km/L (est.)';
}
