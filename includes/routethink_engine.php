<?php
 











class RouteThinkEngine {
     
    const DEFAULT_FUEL_PRICE_PHP = 58.40;
    
     
    const DEFAULT_TOLL_RATE_PER_KM = 3.80;

     
    const CONGESTION_DRAG_COEFFICIENT = 0.40;  
    const PAYLOAD_LOAD_COEFFICIENT     = 0.12;  
    const WAYPOINT_IDLE_COEFFICIENT   = 0.20;  

     


    protected static array $baselineEconomy = [
        'Tour Bus'      => 3.8,
        'Coaster Bus'   => 6.4,
        'Executive Van' => 9.2,
        'VIP SUV'       => 10.5,
    ];

     


    protected static array $modeWeights = [
        'fastest' => [
            'time'     => 0.70,
            'fuel'     => 0.10,
            'distance' => 0.10,
            'cost'     => 0.10,
            'label'    => 'Fastest Express Corridor',
        ],
        'shortest' => [
            'time'     => 0.10,
            'fuel'     => 0.10,
            'distance' => 0.70,
            'cost'     => 0.10,
            'label'    => 'Shortest Geographical Distance',
        ],
        'fuelEfficient' => [
            'time'     => 0.15,
            'fuel'     => 0.65,
            'distance' => 0.10,
            'cost'     => 0.10,
            'label'    => 'Eco-Optimized & Fuel Efficient',
        ],
        'balanced' => [
            'time'     => 0.30,
            'fuel'     => 0.30,
            'distance' => 0.20,
            'cost'     => 0.20,
            'label'    => 'Balanced Route Recommendation',
        ],
    ];

     


    public static function getVehicleSpecs(?string $vehicleIdentifier): array {
        $default = [
            'id'                  => null,
            'name'                => 'Tour Bus (Grand View 45s)',
            'type'                => 'Tour Bus',
            'fuel_type'           => 'Diesel',
            'capacity'            => 45,
            'fuel_capacity'       => 250,
            'baseline_km_per_liter' => 3.8,
            'weight_class'        => 3,
        ];

        if (empty($vehicleIdentifier)) {
            return $default;
        }

        try {
            $pdo = db();
            $stmt = $pdo->prepare(
                "SELECT id, plate_number, type, brand, model, capacity, fuel_type, fuel_capacity, avg_fuel_km 
                 FROM vehicles 
                 WHERE id = ? OR plate_number = ? OR CONCAT(brand, ' ', model) LIKE ? OR type LIKE ? 
                 LIMIT 1"
            );
            $search = '%' . trim($vehicleIdentifier) . '%';
            $stmt->execute([$vehicleIdentifier, $vehicleIdentifier, $search, $search]);
            $row = $stmt->fetch();

            if ($row) {
                 
                $kmL = 6.0;
                if (!empty($row['avg_fuel_km']) && preg_match('/([\d\.]+)/', $row['avg_fuel_km'], $m)) {
                    $kmL = (float)$m[1];
                } else {
                    foreach (self::$baselineEconomy as $k => $val) {
                        if (stripos($row['type'], $k) !== false) {
                            $kmL = $val;
                            break;
                        }
                    }
                }

                $weightClass = 1;
                if (stripos($row['type'], 'Bus') !== false) $weightClass = 3;
                elseif (stripos($row['type'], 'Coaster') !== false) $weightClass = 2;

                return [
                    'id'                  => $row['id'],
                    'name'                => "{$row['brand']} {$row['model']} ({$row['plate_number']})",
                    'type'                => $row['type'],
                    'fuel_type'           => $row['fuel_type'] ?: 'Diesel',
                    'capacity'            => (int)$row['capacity'] ?: 14,
                    'fuel_capacity'       => (int)$row['fuel_capacity'] ?: 70,
                    'baseline_km_per_liter' => $kmL > 0 ? $kmL : 6.0,
                    'weight_class'        => $weightClass,
                ];
            }
        } catch (Throwable $e) {
             
        }

         
        $kmL = 6.0;
        $type = 'Executive Van';
        $weightClass = 1;
        $capacity = 14;

        foreach (self::$baselineEconomy as $k => $val) {
            if (stripos($vehicleIdentifier, $k) !== false) {
                $kmL = $val;
                $type = $k;
                if ($k === 'Tour Bus') { $weightClass = 3; $capacity = 45; }
                elseif ($k === 'Coaster Bus') { $weightClass = 2; $capacity = 29; }
                elseif ($k === 'VIP SUV') { $weightClass = 1; $capacity = 7; }
                break;
            }
        }

        return [
            'id'                  => null,
            'name'                => $vehicleIdentifier,
            'type'                => $type,
            'fuel_type'           => 'Diesel',
            'capacity'            => $capacity,
            'fuel_capacity'       => 100,
            'baseline_km_per_liter' => $kmL,
            'weight_class'        => $weightClass,
        ];
    }

     





    public static function calculateKinematicFuel(
        float $distanceKm,
        float $baseDurationMins,
        float $trafficDurationMins,
        array $vehicleSpecs,
        int $waypointCount = 0,
        int $passengerCount = 0
    ): float {
        $baseEconomy = max(1.0, (float)($vehicleSpecs['baseline_km_per_liter'] ?? 6.0));
        
         
        $delayRatio = ($baseDurationMins > 0 && $trafficDurationMins > $baseDurationMins)
            ? ($trafficDurationMins - $baseDurationMins) / $baseDurationMins
            : 0.0;
        $congestionFactor = 1.0 + (self::CONGESTION_DRAG_COEFFICIENT * min(2.0, $delayRatio));

         
        $capacity = max(1, (int)($vehicleSpecs['capacity'] ?? 14));
        $effectivePax = min($capacity, max(0, $passengerCount));
        $loadRatio = $effectivePax / $capacity;
        $payloadFactor = 1.0 + (self::PAYLOAD_LOAD_COEFFICIENT * $loadRatio);

         
        $waypointPenalty = self::WAYPOINT_IDLE_COEFFICIENT * max(0, $waypointCount);

        $baseBurn = ($distanceKm / $baseEconomy) * $congestionFactor * $payloadFactor;
        return round($baseBurn + $waypointPenalty, 2);
    }

     


    public static function getActiveModel(string $targetVariable = 'fuel_liters'): ?array {
        try {
            $pdo = db();
            $stmt = $pdo->prepare(
                "SELECT * FROM ai_model_registry 
                 WHERE target_variable = ? AND status = 'Deployed' 
                 ORDER BY deployed_at DESC, id DESC LIMIT 1"
            );
            $stmt->execute([$targetVariable]);
            $model = $stmt->fetch();
            if ($model) {
                $model['feature_list'] = json_decode($model['feature_list_json'] ?? '[]', true) ?: [];
                $model['weights'] = json_decode($model['weights_json'] ?? '{}', true) ?: [];
                $model['hyperparameters'] = json_decode($model['hyperparameters_json'] ?? '{}', true) ?: [];
                return $model;
            }
        } catch (Throwable $e) {
             
        }
        return null;
    }

     


    public static function predictWithML(array $features, ?array $model): ?float {
        if (!$model || empty($model['weights'])) {
            return null;
        }

        $weights = $model['weights'];
        $intercept = (float)($weights['__intercept__'] ?? ($weights['intercept'] ?? 0.0));
        $prediction = $intercept;

        foreach ($features as $key => $val) {
            if (isset($weights[$key])) {
                $coef = (float)$weights[$key];
                $scalerMean = (float)($model['hyperparameters']['scaler_mean'][$key] ?? 0.0);
                $scalerScale = (float)($model['hyperparameters']['scaler_scale'][$key] ?? 1.0);
                if ($scalerScale != 0.0) {
                    $scaledVal = ((float)$val - $scalerMean) / $scalerScale;
                } else {
                    $scaledVal = (float)$val;
                }
                $prediction += $coef * $scaledVal;
            }
        }

        return max(0.1, round($prediction, 2));
    }

     









    public static function evaluateCandidates(
        array $candidates,
        array $vehicleSpecs,
        string $optimizationMode = 'balanced',
        int $passengerCount = 0,
        int $waypointCount = 0
    ): array {
        if (empty($candidates)) {
            return [
                'ok'    => false,
                'error' => 'No candidate routes provided for evaluation.',
            ];
        }

        $validModes = ['balanced', 'fastest', 'fuelEfficient', 'shortest'];
        if (!in_array($optimizationMode, $validModes, true)) {
            $optimizationMode = 'balanced';
        }

        $weights = self::$modeWeights[$optimizationMode] ?? self::$modeWeights['balanced'];
        $activeFuelModel = self::getActiveModel('fuel_liters');
        $activeDurationModel = self::getActiveModel('duration_mins');

        $evaluated = [];
        $rawDistances = [];
        $rawTimes = [];
        $rawFuels = [];
        $rawCosts = [];

         
        foreach ($candidates as $idx => $cand) {
            $distanceKm = (float)($cand['distanceKm'] ?? (($cand['distanceMeters'] ?? 0) / 1000.0));
            $exactDistanceMeters = (float)($cand['distanceMeters'] ?? ($distanceKm * 1000.0));
            $exactDurationSecs = (float)($cand['durationSecs'] ?? (($cand['trafficDurationMins'] ?? $cand['durationMins'] ?? 0) * 60.0));
            $baseDurationMins = (int)($cand['baseDurationMins'] ?? round(($cand['baseDurationSecs'] ?? 0) / 60.0));
            $trafficDurationMins = (int)($cand['trafficDurationMins'] ?? round(($cand['trafficDurationSecs'] ?? ($cand['durationSecs'] ?? 0)) / 60.0));
            if ($trafficDurationMins <= 0) $trafficDurationMins = $baseDurationMins;
            if ($baseDurationMins <= 0) $baseDurationMins = $trafficDurationMins;

            $trafficRatio = $baseDurationMins > 0 ? round($trafficDurationMins / $baseDurationMins, 3) : 1.0;
            $highwayRatio = (float)($cand['highwayRatio'] ?? 0.65);  
            $summaryTitle = $cand['summary'] ?? ($cand['title'] ?? "Route Alternative " . ($idx + 1));

             
            $kinematicFuel = self::calculateKinematicFuel(
                $distanceKm,
                (float)$baseDurationMins,
                (float)$trafficDurationMins,
                $vehicleSpecs,
                $waypointCount,
                $passengerCount
            );

             
            $featureVector = [
                'distance_km'             => $distanceKm,
                'base_duration_mins'      => $baseDurationMins,
                'traffic_delay_ratio'     => $trafficRatio,
                'waypoint_count'          => $waypointCount,
                'baseline_km_per_liter'   => (float)$vehicleSpecs['baseline_km_per_liter'],
                'vehicle_weight_class'    => (int)$vehicleSpecs['weight_class'],
                'passenger_load_ratio'    => round($passengerCount / max(1, $vehicleSpecs['capacity']), 3),
                'highway_ratio'           => $highwayRatio,
            ];

             
            $mlFuel = self::predictWithML($featureVector, $activeFuelModel);
            $effectiveFuel = $mlFuel !== null ? $mlFuel : $kinematicFuel;
            $exactFuelScore = ($exactDistanceMeters / 1000.0)
                / max(0.1, (float)$vehicleSpecs['baseline_km_per_liter'])
                * (1.0 + 0.40 * max(0.0, $trafficRatio - 1.0));

            $mlDuration = self::predictWithML($featureVector, $activeDurationModel);
            $effectiveDurationMins = $mlDuration !== null ? round($mlDuration) : $trafficDurationMins;

             
            $fuelCost = round($effectiveFuel * self::DEFAULT_FUEL_PRICE_PHP);
             
            $tollEstimate = round($distanceKm * $highwayRatio * self::DEFAULT_TOLL_RATE_PER_KM);
            $totalCost = $fuelCost + $tollEstimate;

            $evaluated[] = [
                'index'                 => $idx,
                'summary'               => $summaryTitle,
                'distanceKm'            => $distanceKm,
                'exactDistanceMeters'   => $exactDistanceMeters,
                'exactDurationSecs'     => $exactDurationSecs,
                'exactFuelScore'        => $exactFuelScore,
                'durationMins'          => $effectiveDurationMins,
                'baseDurationMins'      => $baseDurationMins,
                'trafficDurationMins'   => $trafficDurationMins,
                'trafficDelayRatio'     => $trafficRatio,
                'fuelEstimateLiters'    => $effectiveFuel,
                'fuelCost'              => $fuelCost,
                'tollEstimate'          => $tollEstimate,
                'totalTripCost'         => $totalCost,
                'isMlPredicted'         => ($mlFuel !== null),
                'isFuelMlPredicted'     => ($mlFuel !== null),
                'isDurationMlPredicted' => ($mlDuration !== null),
                'isFullyMlActive'       => ($mlFuel !== null && $mlDuration !== null),
                'fuelModelVersion'      => $activeFuelModel['version'] ?? null,
                'durationModelVersion'  => $activeDurationModel['version'] ?? null,
                'modelVersion'          => $activeFuelModel['version'] ?? 'v0-kinematic',
                'rawRoute'              => $cand,
                'features'              => $featureVector,
            ];

            $rawDistances[] = $exactDistanceMeters;
            $rawTimes[]     = $exactDurationSecs;
            $rawFuels[]     = $exactFuelScore;
            $rawCosts[]     = $totalCost;
        }

         
        $minDist = min($rawDistances); $maxDist = max($rawDistances);
        $minTime = min($rawTimes);     $maxTime = max($rawTimes);
        $minFuel = min($rawFuels);     $maxFuel = max($rawFuels);
        $minCost = min($rawCosts);     $maxCost = max($rawCosts);

        $distSpan = max(0.001, $maxDist - $minDist);
        $timeSpan = max(0.001, $maxTime - $minTime);
        $fuelSpan = max(0.001, $maxFuel - $minFuel);
        $costSpan = max(0.001, $maxCost - $minCost);

         
        $bestScore = -1;
        $selectedIndex = 0;

        foreach ($evaluated as $k => &$item) {
             
            $normDist = ($count = count($evaluated)) > 1 ? ($item['exactDistanceMeters'] - $minDist) / $distSpan : 0.0;
            $normTime = $count > 1 ? ($item['exactDurationSecs'] - $minTime) / $timeSpan : 0.0;
            $normFuel = $count > 1 ? ($item['exactFuelScore'] - $minFuel) / $fuelSpan : 0.0;
            $normCost = $count > 1 ? ($item['totalTripCost'] - $minCost) / $costSpan : 0.0;

            $penalty = ($weights['time'] * $normTime)
                     + ($weights['fuel'] * $normFuel)
                     + ($weights['distance'] * $normDist)
                     + ($weights['cost'] * $normCost);

             
            $score = (int)round(100.0 * (1.0 - $penalty));
            $score = max(50, min(99, $score));
            $item['compositeScore'] = $score;

            if ($score > $bestScore) {
                $bestScore = $score;
                $selectedIndex = $k;
            }
        }
        unset($item);

         
         
        if ($optimizationMode === 'fastest') {
            $selectedIndex = array_reduce(array_keys($evaluated), static function ($best, $index) use ($evaluated) {
                return $evaluated[$index]['exactDurationSecs'] < $evaluated[$best]['exactDurationSecs'] ? $index : $best;
            }, 0);
        } elseif ($optimizationMode === 'shortest') {
            $selectedIndex = array_reduce(array_keys($evaluated), static function ($best, $index) use ($evaluated) {
                return $evaluated[$index]['exactDistanceMeters'] < $evaluated[$best]['exactDistanceMeters'] ? $index : $best;
            }, 0);
        } elseif ($optimizationMode === 'fuelEfficient') {
            $selectedIndex = array_reduce(array_keys($evaluated), static function ($best, $index) use ($evaluated) {
                return $evaluated[$index]['exactFuelScore'] < $evaluated[$best]['exactFuelScore'] ? $index : $best;
            }, 0);
        }

        $winner = $evaluated[$selectedIndex];

         
        $explanation = self::generateDecisionExplanation(
            $winner,
            $evaluated,
            $optimizationMode,
            $vehicleSpecs
        );

         
        foreach ($evaluated as &$item) {
            $h = floor($item['durationMins'] / 60);
            $m = $item['durationMins'] % 60;
            $item['durationFormatted'] = $h > 0 ? "{$h} hr" . ($h > 1 ? 's ' : ' ') . "{$m} min" . ($m > 1 ? 's' : '') : "{$m} mins";
            $item['distanceFormatted'] = number_format($item['distanceKm'], 1) . " km";
            $item['fuelFormatted']     = number_format($item['fuelEstimateLiters'], 1) . " L";
            $item['costFormatted']     = "₱" . number_format($item['totalTripCost']);
            $item['tollFormatted']     = "₱" . number_format($item['tollEstimate']);
        }
        unset($item);

        return [
            'ok'                  => true,
            'optimizationMode'    => $optimizationMode,
            'modeTitle'           => $weights['label'],
            'candidateCount'      => count($evaluated),
            'selectedIndex'       => $selectedIndex,
            'selectedCandidate'   => $evaluated[$selectedIndex],
            'candidates'          => $evaluated,
            'explanation'         => $explanation,
            'modelVersion'        => $winner['modelVersion'],
            'isMlActive'          => $winner['isMlPredicted'],
        ];
    }

     


    public static function generateDecisionExplanation(
        array $winner,
        array $allCandidates,
        string $mode,
        array $vehicleSpecs
    ): string {
        $count = count($allCandidates);
        $summary = $winner['summary'];

        if ($count <= 1) {
            return "Single viable road corridor available ({$summary}). ROUTETHINK validated travel time ({$winner['durationMins']} mins) and estimated fuel burn ({$winner['fuelEstimateLiters']} L) for the assigned {$vehicleSpecs['name']}.";
        }

         
        $others = array_values(array_filter($allCandidates, fn($c) => $c['index'] !== $winner['index']));
        usort($others, fn($a, $b) => $b['compositeScore'] <=> $a['compositeScore']);
        $runnerUp = $others[0] ?? null;

        if (!$runnerUp) {
            return "Selected {$summary} with optimal RouteThink efficiency score of {$winner['compositeScore']}/100.";
        }

        $timeDelta = $runnerUp['durationMins'] - $winner['durationMins'];
        $fuelDelta = $runnerUp['fuelEstimateLiters'] - $winner['fuelEstimateLiters'];
        $distDelta = $runnerUp['distanceKm'] - $winner['distanceKm'];
        $costDelta = $runnerUp['totalTripCost'] - $winner['totalTripCost'];

        switch ($mode) {
            case 'fastest':
                if ($timeDelta > 0) {
                    $pct = round(($timeDelta / max(1, $runnerUp['durationMins'])) * 100, 1);
                    return "Selected {$summary}: Saves approximately {$timeDelta} mins ({$pct}% faster) transit time compared to {$runnerUp['summary']} by prioritizing high-capacity expressway corridors.";
                }
                return "Selected {$summary}: Delivers the lowest transit duration ({$winner['durationMins']} mins) among all {$count} evaluated road corridors.";

            case 'shortest':
                if ($distDelta > 0) {
                    $pct = round(($distDelta / max(0.1, $runnerUp['distanceKm'])) * 100, 1);
                    return "Selected {$summary}: Minimizes total odometer distance by {$distDelta} km ({$pct}% shorter) compared to {$runnerUp['summary']}.";
                }
                return "Selected {$summary}: Direct geographical routing with lowest total distance ({$winner['distanceKm']} km).";

            case 'fuelEfficient':
                if ($fuelDelta > 0) {
                    $pct = round(($fuelDelta / max(0.1, $runnerUp['fuelEstimateLiters'])) * 100, 1);
                    $addedTime = $winner['durationMins'] - $runnerUp['durationMins'];
                    $timeClause = $addedTime > 0 ? " with only an estimated {$addedTime} min difference in transit time" : "";
                    return "Selected {$summary}: Reduces fuel consumption by an estimated {$fuelDelta} L ({$pct}% savings) compared to {$runnerUp['summary']}{$timeClause}.";
                }
                return "Selected {$summary}: Eco-optimized corridor providing the lowest estimated fuel burn ({$winner['fuelEstimateLiters']} L) for {$vehicleSpecs['name']}.";

            case 'balanced':
            default:
                $reasons = [];
                if ($timeDelta >= 0) $reasons[] = "optimal transit speed ({$winner['durationMins']} mins)";
                if ($fuelDelta >= 0) $reasons[] = "favorable fuel burn ({$winner['fuelEstimateLiters']} L)";
                if ($distDelta >= 0) $reasons[] = "direct alignment ({$winner['distanceKm']} km)";
                $reasonStr = !empty($reasons) ? implode(', ', $reasons) : "balanced speed and fuel economy";
            return "Selected {$summary}: Achieves the highest composite balance across {$reasonStr} with a relative RouteThink score of {$winner['compositeScore']}/100.";
        }
    }
}


