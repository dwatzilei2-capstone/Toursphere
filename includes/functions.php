<?php
 




 
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

 
function sanitize_text($value, int $maxLength = 500): string
{
    $text = trim(strip_tags((string)$value));
    return function_exists('mb_substr') ? mb_substr($text, 0, $maxLength) : substr($text, 0, $maxLength);
}

 
function money($amount): string
{
    return currency_symbol() . number_format((float)$amount, currency_code() === 'JPY' ? 0 : 2);
}

 
function money0($amount): string
{
    return currency_symbol() . number_format((float)$amount, 0);
}

 
function redirect_to(string $path): void
{
    header('Location: ' . $path);
    exit;
}

 
function redirect_with_toast(string $path, string $message, string $type = 'success'): void
{
    $sep = strpos($path, '?') === false ? '?' : '&';
    redirect_to($path . $sep . 'msg=' . rawurlencode($message) . '&type=' . rawurlencode($type));
}

 
function status_badge_class(string $status): string
{
    switch (strtolower($status)) {
        case 'available':
        case 'active':
        case 'completed':
            return 'status-available';
        case 'on trip':
        case 'in transit':
            return 'status-ontrip';
        case 'assigned':
        case 'confirmed':
            return 'status-assigned';
        case 'dispatched':
            return 'status-dispatched';
        case 'maintenance':
        case 'in repair':
            return 'status-maintenance';
        case 'scheduled':
            return 'status-scheduled';
        default:
            return 'status-pending';
    }
}

 
function driver_short_name(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    if (count($parts) < 2) {
        return $name;
    }
    return mb_substr($parts[0], 0, 1) . '. ' . $parts[count($parts) - 1];
}

 
function trip_schedule_slot(string $time): string
{
    $hour = (int)substr($time, 0, 2);
    if ($hour <= 7) return '06:00 AM';
    if ($hour === 8) return '08:00 AM';
    return '09:30 AM';
}

 
function next_sequential_id(PDO $pdo, string $table, string $id_column, string $prefix, int $pad = 3): string
{
    if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $table) || !preg_match('/^[a-z_][a-z0-9_]*$/i', $id_column)) {
        throw new InvalidArgumentException('Invalid sequential ID source.');
    }

     
     
    $sequenceKey = 'sequence.' . $table . '.' . $id_column . '.' . strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($prefix, '-')));
    $maxSql = "SELECT COALESCE(MAX(CAST(SUBSTRING({$id_column} FROM '([0-9]+)$') AS BIGINT)), 0) FROM {$table}";
    $currentMax = (int)$pdo->query($maxSql)->fetchColumn();
    $stmt = $pdo->prepare(
        "INSERT INTO system_settings (setting_key, setting_value, description)
         VALUES (?, ?, 'Persistent sequential ID counter')
         ON CONFLICT (setting_key) DO UPDATE
           SET setting_value = (GREATEST(CAST(system_settings.setting_value AS BIGINT), ?) + 1)::text
         RETURNING setting_value"
    );
    $stmt->execute([$sequenceKey, (string)($currentMax + 1), $currentMax]);
    $num = (int)$stmt->fetchColumn();
    return $prefix . str_pad((string)$num, $pad, '0', STR_PAD_LEFT);
}
