<?php

/** Online baseline calibration: cumulative sufficient statistics, not batch retraining. */
function ai_learning_context(array $features): string
{
    return 'weight:' . (int)$features['vehicle_weight_class'] . ':traffic:'
        . ((float)$features['traffic_delay_ratio'] >= 1.5 ? 'heavy' : ((float)$features['traffic_delay_ratio'] > 1.1 ? 'moderate' : 'light'));
}

function ai_learning_observations(array $row): array
{
    $f = json_decode($row['pre_trip_features_json'] ?? '', true);
    $routeData = json_decode($row['route_data_json'] ?? '', true);
    if (!is_array($f) || !empty($routeData['demo']) || str_contains(strtolower($row['model_version'] ?? ''), 'demo')) return [];
    foreach (['distance_km', 'base_duration_mins', 'traffic_delay_ratio', 'baseline_km_per_liter', 'vehicle_weight_class', 'passenger_load_ratio', 'waypoint_count', 'highway_ratio'] as $key) {
        if (!isset($f[$key]) || !is_numeric($f[$key]) || !is_finite((float)$f[$key])) return [];
    }
    if ($f['distance_km'] <= 0 || $f['base_duration_mins'] <= 0 || $f['baseline_km_per_liter'] <= 0
        || $f['traffic_delay_ratio'] < 0.5 || $f['traffic_delay_ratio'] > 4
        || $f['passenger_load_ratio'] < 0 || $f['passenger_load_ratio'] > 1
        || $f['waypoint_count'] < 0 || $f['waypoint_count'] > 15
        || $f['vehicle_weight_class'] < 1 || $f['vehicle_weight_class'] > 4
        || $f['highway_ratio'] < 0 || $f['highway_ratio'] > 1) return [];
    $duration = $f['base_duration_mins'] * $f['traffic_delay_ratio'];
    $fuel = RouteThinkEngine::calculateKinematicFuel((float)$f['distance_km'], (float)$f['base_duration_mins'], $duration,
        ['baseline_km_per_liter' => $f['baseline_km_per_liter'], 'capacity' => 1000],
        (int)$f['waypoint_count'], (int)round($f['passenger_load_ratio'] * 1000));
    $result = [];
    foreach (['duration_mins' => $duration, 'fuel_liters' => $fuel] as $target => $baseline) {
        if ($target === 'fuel_liters' && (int)($row['actual_fuel_verified'] ?? 0) !== 1) continue;
        $actual = (float)($row['actual_' . $target] ?? 0);
        $ratio = $baseline > 0 ? $actual / $baseline : 0;
        // Reject implausible outcomes rather than silently learning clamped/fabricated labels.
        if (!is_finite($actual) || $actual <= 0 || $ratio < 0.2 || $ratio > 5) continue;
        $result[$target] = ['context' => ai_learning_context($f), 'ratio' => $ratio,
            'fingerprint' => hash('sha256', json_encode([$f, $actual, $row['trip_id'], $row['vehicle_id']]))];
    }
    return $result;
}

function ai_learning_sync(PDO $pdo, ?string $tripId = null): int
{
    if ($pdo->inTransaction()) throw new LogicException('Learning must run after trip data is committed.');
    $pdo->beginTransaction();
    try {
        // Serialize writers so retries and concurrent completions cannot double-count outcomes.
        $pdo->query('SELECT pg_advisory_xact_lock(867530102)');
        $q = $pdo->prepare("SELECT rh.* FROM route_history rh JOIN trips t ON t.route_history_id = rh.log_id
            WHERE t.status = 'Completed' AND t.vehicle_id IS NOT NULL AND t.actual_departure IS NOT NULL
              AND t.actual_arrival > t.actual_departure AND (?::varchar IS NULL OR t.id = ?)");
        $q->execute([$tripId, $tripId]);
        $changed = 0;
        foreach ($q->fetchAll() as $row) {
            $observations = ai_learning_observations($row);
            $existingQuery = $pdo->prepare('SELECT * FROM ai_learning_samples WHERE log_id = ?');
            $existingQuery->execute([$row['log_id']]);
            foreach ($existingQuery->fetchAll() as $existing) {
                if (isset($observations[$existing['target']])) continue;
                $pdo->prepare('UPDATE ai_learning_stats SET samples = samples - 1, ratio_sum = ratio_sum - ?, updated_at = clock_timestamp() WHERE target = ? AND context_key = ?')
                    ->execute([$existing['ratio'], $existing['target'], $existing['context_key']]);
                $pdo->prepare('DELETE FROM ai_learning_samples WHERE log_id = ? AND target = ?')->execute([$row['log_id'], $existing['target']]);
                $changed++;
            }
            foreach ($observations as $target => $sample) {
                $oldQuery = $pdo->prepare('SELECT * FROM ai_learning_samples WHERE log_id = ? AND target = ?');
                $oldQuery->execute([$row['log_id'], $target]);
                $old = $oldQuery->fetch();
                if ($old && $old['fingerprint'] === $sample['fingerprint']) continue;
                if ($old) {
                    $pdo->prepare('UPDATE ai_learning_stats SET samples = samples - 1, ratio_sum = ratio_sum - ?, updated_at = clock_timestamp() WHERE target = ? AND context_key = ?')
                        ->execute([$old['ratio'], $target, $old['context_key']]);
                }
                $pdo->prepare('INSERT INTO ai_learning_stats (target, context_key, samples, ratio_sum) VALUES (?, ?, 1, ?)
                    ON CONFLICT (target, context_key) DO UPDATE SET samples = ai_learning_stats.samples + 1,
                    ratio_sum = ai_learning_stats.ratio_sum + EXCLUDED.ratio_sum, updated_at = clock_timestamp()')
                    ->execute([$target, $sample['context'], $sample['ratio']]);
                $pdo->prepare('INSERT INTO ai_learning_samples (log_id, target, context_key, ratio, fingerprint) VALUES (?, ?, ?, ?, ?)
                    ON CONFLICT (log_id, target) DO UPDATE SET context_key = EXCLUDED.context_key,
                    ratio = EXCLUDED.ratio, fingerprint = EXCLUDED.fingerprint, learned_at = clock_timestamp()')
                    ->execute([$row['log_id'], $target, $sample['context'], $sample['ratio'], $sample['fingerprint']]);
                $changed++;
            }
        }
        $pdo->commit();
        return $changed;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function ai_learning_after_trip(PDO $pdo, ?string $tripId): void
{
    try { ai_learning_sync($pdo, $tripId); }
    catch (Throwable $e) { error_log('Continuous route learning update unavailable; historical data retained for retry.'); }
}

function ai_learning_adjust(float $baseline, string $target, array $features, array $stats): float
{
    $row = $stats[$target][ai_learning_context($features)] ?? null;
    if (!$row || (int)$row['samples'] < 1) return $baseline;
    $n = (int)$row['samples'];
    // Baseline pseudo-observations provide smooth shrinkage even with a single real trip.
    $influence = $n / ($n + 30.0);
    $factor = 1 + $influence * ((float)$row['ratio_sum'] / $n - 1);
    return max(0.1, $baseline * $factor);
}

function ai_learning_stats(PDO $pdo): array
{
    $result = [];
    foreach ($pdo->query('SELECT * FROM ai_learning_stats WHERE samples > 0')->fetchAll() as $row) {
        $result[$row['target']][$row['context_key']] = $row;
    }
    return $result;
}
