<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (has_role('customer')) redirect_to(BASE_URL . '/' . home_path());

$sections = ['general' => ['General', 'bi-sliders2', 'Company profile & preferences']];
if (can('vehicles.manage')) $sections['maintenance'] = ['Maintenance', 'bi-tools', 'Service intervals & reminders'];
if (can('dispatch.manage')) $sections['dispatch'] = ['Reservation & Dispatch', 'bi-calendar2-check', 'Booking & scheduling rules'];
if (can('settings.manage')) $sections['fare'] = ['Fare Configuration', 'bi-cash-coin', 'Customer base fare & distance rate'];
if (has_role('fleet_admin')) $sections['schedule-management'] = ['Schedule Management', 'bi-clock', 'Operating days & departure times'];
if (has_role('fleet_admin')) $sections['ai-model-training'] = ['AI Model Training', 'bi-cpu', 'RouteThink models & diagnostics'];
$tab = is_string($_GET['tab'] ?? null) && isset($sections[$_GET['tab']]) ? $_GET['tab'] : 'general';
$_SESSION['settings_csrf'] ??= bin2hex(random_bytes(32));
$error = '';
$posted = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uploadedPath = null;
    try {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['settings_csrf'], $_POST['csrf'])) throw new RuntimeException('Your session expired. Refresh the page and try again.');
        $section = is_string($_POST['section'] ?? null) ? $_POST['section'] : '';
        $values = [];
        if ($section === 'schedule-management') {
            if (!has_role('fleet_admin')) throw new RuntimeException('Only administrators can manage operating schedules.');
            $tab = 'schedule-management';
            $action = trim((string)($_POST['schedule_action'] ?? ''));
            $scheduleId = filter_var($_POST['schedule_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            $pdo = db();
            if ($action === 'toggle') {
                if (!$scheduleId) throw new RuntimeException('Schedule not found.');
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("UPDATE operating_schedules SET status=CASE status WHEN 'Active' THEN 'Inactive' ELSE 'Active' END, updated_at=CURRENT_TIMESTAMP WHERE id=?");
                $stmt->execute([$scheduleId]);
                if (!$stmt->rowCount()) throw new RuntimeException('Schedule not found.');
                $pdo->commit();
            } elseif (in_array($action, ['create', 'update'], true)) {
                $name = trim((string)($_POST['schedule_name'] ?? ''));
                $time = trim((string)($_POST['departure_time'] ?? ''));
                $days = array_values(array_unique(array_map('intval', is_array($_POST['operating_days'] ?? null) ? $_POST['operating_days'] : [])));
                if ($name === '' || mb_strlen($name) > 120) throw new RuntimeException('Enter a schedule name up to 120 characters.');
                if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) throw new RuntimeException('Enter a valid departure time.');
                if (!$days || array_diff($days, range(1, 7))) throw new RuntimeException('Select at least one valid operating day.');
                $pdo->beginTransaction();
                if ($action === 'create') {
                    $stmt = $pdo->prepare("INSERT INTO operating_schedules(name,departure_time,status,created_by) VALUES (?,?,'Active',?) RETURNING id");
                    $stmt->execute([$name, $time, current_user()['id']]); $scheduleId = (int)$stmt->fetchColumn();
                } else {
                    if (!$scheduleId) throw new RuntimeException('Schedule not found.');
                    $stmt = $pdo->prepare('UPDATE operating_schedules SET name=?,departure_time=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
                    $stmt->execute([$name, $time, $scheduleId]);
                    if (!$stmt->rowCount() && !(bool)$pdo->query('SELECT 1 FROM operating_schedules WHERE id=' . (int)$scheduleId)->fetchColumn()) throw new RuntimeException('Schedule not found.');
                    $pdo->prepare('DELETE FROM operating_schedule_days WHERE schedule_id=?')->execute([$scheduleId]);
                }
                $dayStmt = $pdo->prepare('INSERT INTO operating_schedule_days(schedule_id,iso_weekday) VALUES (?,?)');
                foreach ($days as $day) $dayStmt->execute([$scheduleId, $day]);
                $pdo->commit();
            } else throw new RuntimeException('Unknown schedule action.');
            $_SESSION['settings_saved'] = 'Schedule Management has been updated.';
            redirect_to(BASE_URL . '/settings.php?tab=schedule-management');
        } elseif ($section === 'appearance') {
            $theme = $_POST['theme'] ?? '';
            if (!in_array($theme, ['light', 'dark'], true)) throw new RuntimeException('Choose light or dark mode.');
            $values['user.' . current_user()['id'] . '.theme'] = $theme;
        } elseif ($section === 'company' && can('settings.manage')) {
            foreach (['system.brand' => 100, 'company.email' => 150, 'company.phone' => 40, 'company.address' => 400] as $key => $max) {
                $raw = $_POST[str_replace('.', '_', $key)] ?? '';
                if (!is_string($raw) || strlen(trim($raw)) > $max) throw new RuntimeException('Check the length of your company details.');
                $values[$key] = trim($raw);
            }
            if ($values['system.brand'] === '') throw new RuntimeException('Company name is required.');
            if ($values['company.email'] !== '' && !filter_var($values['company.email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid contact email.');
            $logo = $_FILES['logo'] ?? null;
            if ($logo && ($logo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if ($logo['error'] !== UPLOAD_ERR_OK || $logo['size'] > 2 * 1024 * 1024 || !is_uploaded_file($logo['tmp_name'])) throw new RuntimeException('Choose an image smaller than 2 MB.');
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($logo['tmp_name']);
                $types = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
                $size = @getimagesize($logo['tmp_name']);
                if (!isset($types[$mime]) || !$size || $size[0] > 4096 || $size[1] > 4096) throw new RuntimeException('Use a PNG, JPG or WebP image up to 4096 × 4096 pixels.');
                $dir = ROOT_PATH . '/uploads/company';
                if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new RuntimeException('Could not prepare the logo upload.');
                $values['company.logo'] = '/uploads/company/logo-' . bin2hex(random_bytes(12)) . '.' . $types[$mime];
                $uploadedPath = ROOT_PATH . $values['company.logo'];
                if (!move_uploaded_file($logo['tmp_name'], $uploadedPath)) throw new RuntimeException('Could not save the logo. Try again.');
            }
        } elseif ($section === 'regional' && can('settings.manage')) {
            $zone = $_POST['timezone'] ?? '';
            $currency = $_POST['currency'] ?? '';
            if (!is_string($zone) || !in_array($zone, timezone_identifiers_list(), true)) throw new RuntimeException('Choose a valid timezone.');
            if (!is_string($currency) || !isset(supported_currencies()[$currency])) throw new RuntimeException('Choose a supported currency.');
            $values = ['company.timezone' => $zone, 'company.currency' => $currency];
        } elseif ($section === 'fare' && can('settings.manage')) {
            $tab = 'fare';
            $baseRaw = trim((string)($_POST['fare_base_fare'] ?? ''));
            $rateRaw = trim((string)($_POST['fare_rate_per_km'] ?? ''));
            if ($baseRaw === '' || !is_numeric($baseRaw) || (float)$baseRaw < 0) throw new RuntimeException('Base Fare must be a number greater than or equal to zero.');
            if ($rateRaw === '' || !is_numeric($rateRaw) || (float)$rateRaw < 0) throw new RuntimeException('Rate Per Kilometer must be a number greater than or equal to zero.');
            if ((float)$baseRaw > 99999999.99 || (float)$rateRaw > 99999999.99) throw new RuntimeException('Fare values are too large.');
            $values = ['fare.base_fare' => number_format((float)$baseRaw, 2, '.', ''), 'fare.rate_per_km' => number_format((float)$rateRaw, 2, '.', '')];
        } else {
            $fields = [];
            if ($section === 'maintenance' && can('vehicles.manage')) {
                $tab = 'maintenance';
                $fields = ['maintenance.interval_days' => [1, 730], 'maintenance.reminder_days' => [0, 90]];
            } elseif ($section === 'dispatch' && can('dispatch.manage')) {
                $tab = 'dispatch';
                $fields = ['reservation.notice_hours' => [0, 720], 'dispatch.buffer_hours' => [1, 72]];
            } else throw new RuntimeException('You do not have permission to change these settings.');
            foreach ($fields as $key => [$min, $max]) {
                $v = filter_var($_POST[str_replace('.', '_', $key)] ?? null, FILTER_VALIDATE_INT);
                if ($v === false || $v === null || $v < $min || $v > $max) throw new RuntimeException('Enter whole numbers within the limits shown.');
                $values[$key] = (string)$v;
            }
            if ($section === 'maintenance' && (int)$values['maintenance.reminder_days'] > (int)$values['maintenance.interval_days']) throw new RuntimeException('Reminder days cannot exceed the service interval.');
        }
        $previousFare = $section === 'fare' ? ['base_fare' => fleet_setting('fare.base_fare', ''), 'rate_per_km' => fleet_setting('fare.rate_per_km', '')] : null;
        db()->beginTransaction();
        foreach ($values as $key => $value) save_fleet_setting($key, $value);
        if ($section === 'fare') {
            db()->prepare('INSERT INTO audit_logs (action, user_id, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?)')->execute([
                'fare_configuration_updated', current_user()['id'], 'system_settings', 'customer_fare',
                json_encode(['previous' => $previousFare, 'new' => ['base_fare' => $values['fare.base_fare'], 'rate_per_km' => $values['fare.rate_per_km']]], JSON_UNESCAPED_SLASHES),
            ]);
        }
        db()->commit();
        $_SESSION['settings_saved'] = 'Your changes have been saved.';
        redirect_to(BASE_URL . '/settings.php?tab=' . $tab);
    } catch (Throwable $ex) {
        if (db()->inTransaction()) db()->rollBack();
        unset($GLOBALS['fleet_settings_cache']);
        if ($uploadedPath && is_file($uploadedPath)) unlink($uploadedPath);
        $error = $ex instanceof RuntimeException && !($ex instanceof PDOException) ? $ex->getMessage() : 'We could not save your changes. Please try again.';
        $posted = $_POST;
    }
}
function settings_value(string $key, string $default = ''): string {
    global $posted;
    $v = $posted[str_replace('.', '_', $key)] ?? null;
    return is_string($v) ? $v : fleet_setting($key, $default);
}
function settings_token(string $section): void {
    global $posted;
    $saved = match ($section) {
        'company' => ['system_brand' => company_name(), 'company_email' => fleet_setting('company.email'), 'company_phone' => fleet_setting('company.phone'), 'company_address' => fleet_setting('company.address')],
        'regional' => ['timezone' => company_timezone(), 'currency' => currency_code()],
        'maintenance' => ['maintenance_interval_days' => fleet_setting('maintenance.interval_days', '90'), 'maintenance_reminder_days' => fleet_setting('maintenance.reminder_days', '7')],
        'dispatch' => ['reservation_notice_hours' => fleet_setting('reservation.notice_hours', '0'), 'dispatch_buffer_hours' => fleet_setting('dispatch.buffer_hours', '4')],
        'fare' => ['fare_base_fare' => fleet_setting('fare.base_fare', ''), 'fare_rate_per_km' => fleet_setting('fare.rate_per_km', '')],
    };
    $editing = ($posted['section'] ?? '') === $section;
?>
  <input type="hidden" name="csrf" value="<?= e($_SESSION['settings_csrf']) ?>"><input type="hidden" name="section" value="<?= e($section) ?>">
  <fieldset class="settings-fields" id="settings-fields-<?= e($section) ?>" data-saved-values="<?= e(json_encode($saved)) ?>" data-editing="<?= $editing ? 'true' : 'false' ?>" <?= $editing ? '' : 'disabled' ?>>
<?php }
function settings_actions(string $section, string $label, string $hint): void {
    global $posted;
    $editing = ($posted['section'] ?? '') === $section;
?>
  </fieldset>
  <div class="settings-card-footer"><span><?= e($hint) ?></span><div class="settings-actions">
    <button type="button" class="tc-btn tc-btn-secondary" data-settings-edit aria-label="Edit <?= e($label) ?>" aria-controls="settings-fields-<?= e($section) ?>" aria-expanded="<?= $editing ? 'true' : 'false' ?>" <?= $editing ? 'hidden' : '' ?>><i class="bi bi-pencil" aria-hidden="true"></i> Edit</button>
    <button type="button" class="tc-btn tc-btn-secondary" data-settings-cancel <?= $editing ? '' : 'hidden' ?>>Cancel</button>
    <button type="submit" class="tc-btn tc-btn-primary" data-settings-save <?= $editing ? '' : 'hidden disabled' ?>>Save <?= e($label) ?></button>
  </div></div>
<?php }
function settings_number(string $key, string $label, string $hint, int $default, int $min, int $max, string $unit): void { ?>
  <div class="settings-row"><div><label for="<?= e($key) ?>"><?= e($label) ?></label><p><?= e($hint) ?></p></div><div class="settings-number"><div><input id="<?= e($key) ?>" name="<?= e(str_replace('.', '_', $key)) ?>" type="number" min="<?= $min ?>" max="<?= $max ?>" required value="<?= e(settings_value($key, (string)$default)) ?>"><span><?= e($unit) ?></span></div><small><?= $min ?>–<?= $max ?> <?= e($unit) ?></small></div></div>
<?php }
$active_page = 'settings'; $page_title = 'Settings';
$managedSchedules = [];
if ($tab === 'schedule-management' && has_role('fleet_admin')) {
    $managedSchedules = db()->query("SELECT s.id,s.name,to_char(s.departure_time,'HH24:MI') AS departure_time,s.status,ARRAY_AGG(d.iso_weekday ORDER BY d.iso_weekday) AS operating_days FROM operating_schedules s JOIN operating_schedule_days d ON d.schedule_id=s.id GROUP BY s.id ORDER BY s.departure_time,s.name")->fetchAll();
}
require ROOT_PATH . '/includes/header.php';
?>
<div class="settings-shell">
  <header class="settings-heading"><div><span class="settings-eyebrow">WORKSPACE</span><h1>Settings</h1><p>Make your workspace work for you.</p></div><span class="settings-role"><i class="bi bi-shield-check"></i><?= e(current_user()['role_name']) ?></span></header>
  <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if (isset($_SESSION['settings_saved'])): ?><div class="settings-saved" role="status"><i class="bi bi-check-circle-fill"></i><?= e($_SESSION['settings_saved']) ?></div><?php unset($_SESSION['settings_saved']); endif; ?>
  <div class="settings-layout">
    <nav class="settings-nav" aria-label="Settings sections">
      <?php foreach ($sections as $id => [$title, $icon, $hint]): ?><a href="<?= BASE_URL ?>/settings.php?tab=<?= $id ?>" class="<?= $tab === $id ? 'is-active' : '' ?>" <?= $tab === $id ? 'aria-current="page"' : '' ?>><i class="bi <?= $icon ?>"></i><span><strong><?= e($title) ?></strong><small><?= e($hint) ?></small></span></a><?php endforeach; ?>
      <div class="settings-nav-note"><i class="bi bi-lock"></i><p>You only see settings available to your role.</p></div>
    </nav>
    <div class="settings-content">
    <?php if ($tab === 'general'): ?>
      <div class="settings-section-title"><h2>General settings</h2><p>Your company identity, regional preferences and appearance.</p></div>
      <?php if (can('settings.manage')): ?>
      <form method="post" enctype="multipart/form-data" class="settings-card">
        <?php settings_token('company'); ?>
        <div class="settings-card-heading"><div><h3>Company profile</h3><p>How your company appears across the application.</p></div><span class="settings-scope">Workspace</span></div>
        <div class="settings-card-body">
          <div class="settings-logo-row"><div class="settings-logo"><img id="company-logo-preview" src="<?= e(company_logo()) ?>" alt="Current company logo"></div><div><label for="company-logo" class="tc-btn tc-btn-secondary tc-btn-sm"><i class="bi bi-upload"></i> Upload logo</label><input id="company-logo" type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="settings-file"><p id="logo-help">PNG, JPG or WebP. Max 2 MB.</p></div></div>
          <div class="settings-form-grid">
            <div class="settings-field settings-full"><label for="company-name">Company name <span>*</span></label><input id="company-name" name="system_brand" maxlength="100" required value="<?= e(settings_value('system.brand', 'Toursphere')) ?>" autocomplete="organization"></div>
            <div class="settings-field"><label for="contact-email">Contact email</label><input id="contact-email" name="company_email" type="email" maxlength="150" value="<?= e(settings_value('company.email')) ?>" placeholder="contact@company.com" autocomplete="email"></div>
            <div class="settings-field"><label for="contact-phone">Phone number</label><input id="contact-phone" name="company_phone" type="tel" maxlength="40" value="<?= e(settings_value('company.phone')) ?>" placeholder="+63" autocomplete="tel"></div>
            <div class="settings-field settings-full"><label for="company-address">Company address</label><textarea id="company-address" name="company_address" maxlength="400" rows="2" placeholder="Street, city, province and postal code" autocomplete="street-address"><?= e(settings_value('company.address')) ?></textarea></div>
          </div>
        </div><?php settings_actions('company', 'company profile', 'Visible to your team.'); ?>
      </form>
      <form method="post" class="settings-card">
        <?php settings_token('regional'); ?>
        <div class="settings-card-heading"><div><h3>Regional preferences</h3><p>Keep schedules and amounts consistent across your workspace.</p></div><span class="settings-scope">Workspace</span></div>
        <div class="settings-card-body settings-form-grid">
          <div class="settings-field"><label for="timezone">Timezone</label><select id="timezone" name="timezone"><?php foreach (timezone_identifiers_list() as $zone): ?><option value="<?= e($zone) ?>" <?= ($posted['timezone'] ?? company_timezone()) === $zone ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $zone)) ?></option><?php endforeach; ?></select><small>Used for system dates and booking notice checks. Existing scheduled times stay as entered.</small></div>
          <div class="settings-field"><label for="currency">Currency</label><select id="currency" name="currency"><?php foreach (supported_currencies() as $code => $name): ?><option value="<?= $code ?>" <?= ($posted['currency'] ?? currency_code()) === $code ? 'selected' : '' ?>><?= $code ?> — <?= e($name) ?></option><?php endforeach; ?></select><small>Sets the currency shown with amounts. Existing amounts are not converted.</small></div>
        </div><?php settings_actions('regional', 'regional preferences', 'Applies to all accounts.'); ?>
      </form>
      <?php endif; ?>
      <form method="post" class="settings-card" id="appearance-form">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['settings_csrf']) ?>"><input type="hidden" name="section" value="appearance">
        <div class="settings-card-heading"><div><h3>Appearance</h3><p>Choose the look that feels right for you.</p></div><span class="settings-scope">Only you</span></div>
        <div class="settings-card-body"><div class="settings-themes">
          <?php foreach (['light' => ['Light', 'bi-sun'], 'dark' => ['Dark', 'bi-moon-stars']] as $theme => [$name, $icon]): ?>
          <label class="settings-theme"><input type="radio" name="theme" value="<?= $theme ?>" <?= account_theme() === $theme ? 'checked' : '' ?>><span class="settings-theme-preview preview-<?= $theme ?>" aria-hidden="true"><span class="preview-sidebar"></span><span class="preview-workspace"><span class="preview-heading"></span><span class="preview-cards"><i></i><i></i></span><span class="preview-line"></span></span></span><span class="settings-theme-label"><i class="bi <?= $icon ?>"></i><?= $name ?><i class="bi bi-check-circle-fill theme-check"></i></span></label>
          <?php endforeach; ?>
        </div><p class="settings-help">Choose a theme, then select Save appearance to apply it to your account.</p></div>
        <div class="settings-card-footer"><span>Your teammates keep their own appearance.</span><button class="tc-btn tc-btn-primary" type="submit">Save appearance</button></div>
      </form>
    <?php elseif ($tab === 'maintenance'): ?>
      <div class="settings-section-title"><h2>Maintenance settings</h2><p>Plan regular service and identify upcoming work.</p></div>
      <form method="post" class="settings-card"><?php settings_token('maintenance'); ?><div class="settings-card-heading"><div><h3>Preventive maintenance</h3><p>Default rules for the fleet.</p></div><span class="settings-scope">Workspace</span></div>
      <?php settings_number('maintenance.interval_days', 'Service interval', 'Sets the next service date when a work order is completed.', 90, 1, 730, 'days'); ?>
      <?php settings_number('maintenance.reminder_days', 'Upcoming service notice', 'Marks scheduled work orders as due soon on the Maintenance page.', 7, 0, 90, 'days'); ?>
      <?php settings_actions('maintenance', 'maintenance settings', 'Existing service dates stay unchanged.'); ?></form>
    <?php elseif ($tab === 'dispatch'): ?>
      <div class="settings-section-title"><h2>Reservation &amp; dispatch</h2><p>Set booking lead times and prevent schedule conflicts.</p></div>
      <form method="post" class="settings-card"><?php settings_token('dispatch'); ?><div class="settings-card-heading"><div><h3>Scheduling rules</h3><p>Applies when creating reservations and dispatching trips.</p></div><span class="settings-scope">Workspace</span></div>
      <?php settings_number('reservation.notice_hours', 'Minimum booking notice', 'How far ahead a new reservation must be made. Zero allows same-day bookings.', 0, 0, 720, 'hours'); ?>
      <?php settings_number('dispatch.buffer_hours', 'Trip schedule separation', 'Blocks another unfinished trip for the same driver or vehicle within this window before or after departure.', 4, 1, 72, 'hours'); ?>
      <?php settings_actions('dispatch', 'scheduling rules', 'Times use ' . company_timezone() . '.'); ?></form>
    <?php elseif ($tab === 'fare' && can('settings.manage')): ?>
      <div class="settings-section-title"><h2>Fare Configuration</h2><p>Set the customer price used for new reservation estimates.</p></div>
      <form method="post" class="settings-card"><?php settings_token('fare'); ?>
        <div class="settings-card-heading"><div><h3>Customer transportation fare</h3><p>Each passenger pays the base fare plus the verified road distance multiplied by the distance rate.</p></div><span class="settings-scope">Future reservations</span></div>
        <div class="settings-card-body settings-form-grid">
          <div class="settings-field"><label for="fare-base-fare">Base Fare</label><div class="input-group"><span class="input-group-text"><?= e(currency_symbol()) ?></span><input id="fare-base-fare" name="fare_base_fare" type="number" min="0" max="99999999.99" step="0.01" required value="<?= e(settings_value('fare.base_fare')) ?>"></div><small>Starting fare charged per passenger.</small></div>
          <div class="settings-field"><label for="fare-rate-per-km">Rate Per Kilometer</label><div class="input-group"><span class="input-group-text"><?= e(currency_symbol()) ?></span><input id="fare-rate-per-km" name="fare_rate_per_km" type="number" min="0" max="99999999.99" step="0.01" required value="<?= e(settings_value('fare.rate_per_km')) ?>"><span class="input-group-text">/ km</span></div><small>Applied to the verified road-route distance for each passenger.</small></div>
        </div>
        <div class="px-4 pb-3 small text-muted-custom">Pricing changes apply only to new submissions. Existing reservations retain their saved pricing snapshot.</div>
      <?php settings_actions('fare', 'fare configuration', 'Vehicle capacity does not add a fare charge.'); ?></form>
    <?php elseif ($tab === 'schedule-management' && has_role('fleet_admin')): ?>
      <div class="settings-section-title"><h2>Schedule Management</h2><p>Configure reusable departure schedules offered during customer reservations.</p></div>
      <form method="post" class="settings-card" id="schedule-editor">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['settings_csrf']) ?>"><input type="hidden" name="section" value="schedule-management"><input type="hidden" name="schedule_action" id="schedule-action" value="create"><input type="hidden" name="schedule_id" id="schedule-id" value="">
        <div class="settings-card-heading"><div><h3 id="schedule-editor-title">Add operating schedule</h3><p>Customers only see active schedules on their configured operating days.</p></div><span class="settings-scope">Admin only</span></div>
        <div class="settings-card-body settings-form-grid">
          <div class="settings-field"><label for="schedule-name">Schedule name <span>*</span></label><input id="schedule-name" name="schedule_name" maxlength="120" required></div>
          <div class="settings-field"><label for="schedule-time">Departure time <span>*</span></label><input id="schedule-time" name="departure_time" type="time" required></div>
          <fieldset class="settings-field settings-full"><legend class="fs-6 fw-semibold mb-2">Operating days</legend><div class="d-flex flex-wrap gap-3"><?php foreach ([1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday'] as $day=>$label): ?><label class="d-flex align-items-center gap-2"><input type="checkbox" name="operating_days[]" value="<?= $day ?>"> <?= $label ?></label><?php endforeach; ?></div></fieldset>
        </div>
        <div class="settings-card-footer"><span>Schedules are reusable; deactivate them instead of deleting history.</span><div class="settings-actions"><button type="button" class="tc-btn tc-btn-secondary" id="schedule-cancel-edit" hidden>Cancel edit</button><button type="submit" class="tc-btn tc-btn-primary" id="schedule-save">Add schedule</button></div></div>
      </form>
      <div class="settings-card">
        <div class="settings-card-heading"><div><h3>Operating schedules</h3><p>Inactive schedules remain available to historical reservations but are hidden from new bookings.</p></div><span class="settings-scope"><?= count($managedSchedules) ?> configured</span></div>
        <div class="table-responsive"><table class="tc-table mb-0"><thead><tr><th>Name</th><th>Departure</th><th>Operating days</th><th>Status</th><th class="text-end">Actions</th></tr></thead><tbody>
        <?php if (!$managedSchedules): ?><tr><td colspan="5" class="text-center text-muted-custom py-4">No operating schedules configured.</td></tr><?php endif; ?>
        <?php foreach ($managedSchedules as $schedule): $daysRaw=trim((string)$schedule['operating_days'],'{}'); $days=$daysRaw===''?[]:array_map('intval',explode(',',$daysRaw)); ?>
          <tr><td class="fw-semibold"><?= e($schedule['name']) ?></td><td><?= e(date('g:i A', strtotime($schedule['departure_time']))) ?></td><td><?= e(implode(', ', array_map(fn($d)=>[1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun'][$d], $days))) ?></td><td><span class="status-badge <?= $schedule['status']==='Active'?'status-available':'status-cancelled' ?>"><?= e($schedule['status']) ?></span></td><td><div class="d-flex justify-content-end gap-2"><button type="button" class="tc-btn tc-btn-secondary tc-btn-sm schedule-edit" data-id="<?= (int)$schedule['id'] ?>" data-name="<?= e($schedule['name']) ?>" data-time="<?= e($schedule['departure_time']) ?>" data-days="<?= e(json_encode($days)) ?>">Edit</button><form method="post"><input type="hidden" name="csrf" value="<?= e($_SESSION['settings_csrf']) ?>"><input type="hidden" name="section" value="schedule-management"><input type="hidden" name="schedule_action" value="toggle"><input type="hidden" name="schedule_id" value="<?= (int)$schedule['id'] ?>"><button class="tc-btn tc-btn-sm <?= $schedule['status']==='Active'?'tc-btn-outline-danger':'tc-btn-primary' ?>" type="submit"><?= $schedule['status']==='Active'?'Deactivate':'Activate' ?></button></form></div></td></tr>
        <?php endforeach; ?></tbody></table></div>
      </div>
    <?php elseif ($tab === 'ai-model-training' && has_role('fleet_admin')): ?>
      <?php define('TOURSPHERE_EMBED_AI_MODEL_TRAINING', true); ?>
      <?php require ROOT_PATH . '/modules/ai-route-optimization/ai-model-training.php'; ?>
    <?php endif; ?>
    </div>
  </div>
</div>
<script src="<?= BASE_URL ?>/js/settings.js?v=<?= (int)filemtime(ROOT_PATH . '/js/settings.js') ?>"></script>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
