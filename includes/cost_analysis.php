<?php

/** Shared, read-only financial rules for Transport Cost Analysis. */

function cost_date_range(array $input, ?string $defaultFrom = null, ?string $defaultTo = null): array
{
    $defaultFrom ??= date('Y-m-01');
    $defaultTo ??= date('Y-m-d');
    $from = trim((string)($input['from'] ?? $defaultFrom));
    $to = trim((string)($input['to'] ?? $defaultTo));
    $valid = static function (string $value): bool {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        return $date !== false && $date->format('Y-m-d') === $value
            && ($errors === false || (!$errors['warning_count'] && !$errors['error_count']));
    };
    if (!$valid($from) || !$valid($to) || $from > $to
        || $from < '2000-01-01' || $to > '2100-12-31'
        || (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > 3660) {
        $from = $defaultFrom;
        $to = $defaultTo;
    }
    $start = new DateTimeImmutable($from);
    $endExclusive = (new DateTimeImmutable($to))->modify('+1 day');
    $days = (int)$start->diff($endExclusive)->days;
    $previousEnd = $start;
    $previousStart = $start->modify('-' . $days . ' days');
    return [
        'from' => $from, 'to' => $to,
        'start' => $start->format('Y-m-d'), 'end' => $endExclusive->format('Y-m-d'),
        'previous_start' => $previousStart->format('Y-m-d'), 'previous_end' => $previousEnd->format('Y-m-d'),
    ];
}

function cost_sources_sql(): string
{
    return "SELECT transaction_date::timestamp AS occurred_at, vehicle_id, trip_id, 'Fuel'::text AS category, total_cost::numeric AS amount
              FROM fuel_transactions
            UNION ALL
            SELECT scheduled_date::timestamp, vehicle_id, source_trip_id, 'Maintenance'::text, COALESCE(estimated_cost,0)::numeric
              FROM maintenance_orders WHERE status='Completed'
            UNION ALL
            SELECT actual_arrival, vehicle_id, id, 'Toll'::text, COALESCE(toll_fee,0)::numeric
              FROM trips WHERE status='Completed' AND actual_arrival IS NOT NULL";
}

function cost_summary(PDO $pdo, string $start, string $end): array
{
    $costSql = cost_sources_sql();
    $stmt = $pdo->prepare("WITH costs AS ($costSql),
        cost_total AS (SELECT COALESCE(SUM(amount),0) total FROM costs WHERE occurred_at>=?::date AND occurred_at<?::date),
        completed AS (SELECT COUNT(*) count FROM trips WHERE status='Completed' AND actual_arrival>=?::date AND actual_arrival<?::date),
        revenue AS (
          SELECT COALESCE(SUM(r.total_booking_fare),0) total
            FROM reservations r
           WHERE r.status='Completed' AND r.fare_status='Confirmed' AND r.total_booking_fare IS NOT NULL
             AND EXISTS (SELECT 1 FROM trips t WHERE t.reservation_id=r.id AND t.status='Completed'
                          AND t.actual_arrival>=?::date AND t.actual_arrival<?::date)
        )
        SELECT cost_total.total cost, revenue.total revenue, completed.count trip_count FROM cost_total,revenue,completed");
    $stmt->execute([$start, $end, $start, $end, $start, $end]);
    $row = $stmt->fetch() ?: [];
    $cost = (float)($row['cost'] ?? 0);
    $revenue = (float)($row['revenue'] ?? 0);
    $trips = (int)($row['trip_count'] ?? 0);
    return ['cost'=>$cost, 'revenue'=>$revenue, 'margin'=>$revenue-$cost, 'trip_count'=>$trips, 'average'=>$trips ? $cost/$trips : 0.0];
}

function cost_comparison(?float $current, ?float $previous): ?float
{
    if ($previous === null || abs($previous) < 0.00001) return null;
    return (($current - $previous) / abs($previous)) * 100;
}

function cost_comparison_label(?float $value): string
{
    if ($value === null) return 'No previous-period data';
    return ($value >= 0 ? '+' : '') . number_format($value, 1) . '% vs previous period';
}

function cost_breakdown(PDO $pdo, string $start, string $end): array
{
    $sql = cost_sources_sql();
    $stmt = $pdo->prepare("WITH costs AS ($sql) SELECT category,COALESCE(SUM(amount),0) amount FROM costs WHERE occurred_at>=?::date AND occurred_at<?::date GROUP BY category HAVING SUM(amount)>0 ORDER BY amount DESC");
    $stmt->execute([$start, $end]);
    return $stmt->fetchAll();
}

function cost_monthly_series(PDO $pdo, string $start, string $end): array
{
    $sql = cost_sources_sql();
    $stmt = $pdo->prepare("WITH months AS (SELECT generate_series(date_trunc('month',?::date),date_trunc('month',(?::date - interval '1 day')),interval '1 month') AS month_start),
      costs AS ($sql), cost_months AS (SELECT date_trunc('month',occurred_at) month_start,category,SUM(amount) amount FROM costs WHERE occurred_at>=?::date AND occurred_at<?::date GROUP BY 1,2),
      revenue_months AS (SELECT date_trunc('month',t.actual_arrival) month_start,SUM(r.total_booking_fare) amount FROM reservations r JOIN trips t ON t.reservation_id=r.id WHERE r.status='Completed' AND r.fare_status='Confirmed' AND r.total_booking_fare IS NOT NULL AND t.status='Completed' AND t.actual_arrival>=?::date AND t.actual_arrival<?::date GROUP BY 1)
      SELECT to_char(m.month_start,'Mon YYYY') label,m.month_start,COALESCE(SUM(cm.amount),0) cost,COALESCE(MAX(rm.amount),0) revenue,
             COALESCE(SUM(cm.amount) FILTER(WHERE cm.category='Fuel'),0) fuel,
             COALESCE(SUM(cm.amount) FILTER(WHERE cm.category='Maintenance'),0) maintenance,
             COALESCE(SUM(cm.amount) FILTER(WHERE cm.category='Toll'),0) toll
        FROM months m LEFT JOIN cost_months cm ON cm.month_start=m.month_start LEFT JOIN revenue_months rm ON rm.month_start=m.month_start GROUP BY m.month_start ORDER BY m.month_start");
    $stmt->execute([$start,$end,$start,$end,$start,$end]);
    return $stmt->fetchAll();
}
