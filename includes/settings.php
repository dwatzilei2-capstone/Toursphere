<?php
function fleet_setting(string $key, string $default = ''): string {
    if (isset($GLOBALS['fleet_settings_cache'][$key])) return $GLOBALS['fleet_settings_cache'][$key];
    $stmt = db()->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
    $stmt->execute([$key]); $value = $stmt->fetchColumn();
    return $value === false || $value === null ? $default : ($GLOBALS['fleet_settings_cache'][$key] = (string)$value);
}
function save_fleet_setting(string $key, string $value): void {
    db()->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value')->execute([$key, $value]);
    $GLOBALS['fleet_settings_cache'][$key] = $value;
}
function account_theme(): string {
    $u = current_user(); return $u && fleet_setting('user.' . $u['id'] . '.theme', 'light') === 'dark' ? 'dark' : 'light';
}
function reservation_notice_valid(string $date, string $time): bool {
    if ($date === '' || $time === '') return false;
    try { $departure = new DateTimeImmutable($date . ' ' . $time, new DateTimeZone(company_timezone())); }
    catch (Exception $e) { return false; }
    $hours = max(0, (int)fleet_setting('reservation.notice_hours', '0'));
    return $departure->getTimestamp() >= time() + ($hours * 3600);
}

function company_name(): string { return fleet_setting('system.brand', 'Toursphere'); }
function company_logo(): string {
    $path = fleet_setting('company.logo', '/assets/images/toursphere-logo.png');
    return BASE_URL . (preg_match('#^/uploads/company/logo-[a-f0-9]+\.(png|jpg|webp)$#', $path) ? $path : '/assets/images/toursphere-logo.png');
}
function company_timezone(): string {
    $zone = fleet_setting('company.timezone', 'Asia/Manila');
    return in_array($zone, timezone_identifiers_list(), true) ? $zone : 'Asia/Manila';
}
function supported_currencies(): array {
    return ['PHP' => 'Philippine peso', 'USD' => 'US dollar', 'EUR' => 'Euro', 'GBP' => 'British pound', 'SGD' => 'Singapore dollar', 'JPY' => 'Japanese yen'];
}
function currency_code(): string {
    $code = fleet_setting('company.currency', 'PHP');
    return isset(supported_currencies()[$code]) ? $code : 'PHP';
}
function currency_symbol(): string {
    return ['PHP' => '₱', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'SGD' => 'S$', 'JPY' => '¥'][currency_code()];
}
