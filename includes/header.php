<?php
 



$page_title = $page_title ?? 'Toursphere — Fleet & Transportation Management';
$include_leaflet = $include_leaflet ?? false;
$is_driver = ($current_user['role_code'] ?? '') === 'driver';

if (!function_exists('notification_target_url')) {
     
    function notification_target_url(?string $target): string
    {
        if (is_string($target) && str_starts_with($target, 'trip-details:')) {
            $tripId = trim(substr($target, strlen('trip-details:')));
            if ($tripId !== '') {
                return BASE_URL . '/trip-details.php?id=' . rawurlencode($tripId);
            }
        }

        $map = [
            'dashboard'        => '/dashboard.php',
            'dispatch-board'   => '/modules/vehicle-reservation-dispatch/dispatch-board.php',
            'reservations'     => '/modules/vehicle-reservation-dispatch/reservations.php',
            'trip-schedule'    => '/modules/vehicle-reservation-dispatch/trip-schedule.php',
            'ai-route-planner' => '/modules/ai-route-optimization/ai-route-planner.php',
            'route-history'    => '/modules/ai-route-optimization/route-history.php',
            'maintenance'      => '/modules/fleet-vehicle-management/maintenance.php',
            'fuel-transactions'=> '/modules/fuel-management/fuel-transactions.php',
            'drivers'          => '/modules/driver-trip-performance/driver-performance.php',
            'vehicles'         => '/modules/fleet-vehicle-management/vehicle-directory.php',
            'vehicle-assignment'=> '/modules/fleet-vehicle-management/vehicle-assignment.php',
            'driver-dashboard' => '/modules/driver-portal/driver-dashboard.php',
            'driver-trips'     => '/modules/driver-portal/driver-trips.php',
            'driver-route'     => '/modules/driver-portal/driver-route.php',
            'trip-details'     => '/trip-details.php',
            'notifications'    => '/notifications.php',
        ];
        return BASE_URL . ($map[$target] ?? '/notifications.php');
    }
}

if (!function_exists('notification_icon_class')) {
    function notification_icon_class(string $type): string
    {
        return [
            'info'    => 'bi-info-circle-fill text-primary',
            'success' => 'bi-check-circle-fill text-success',
            'warning' => 'bi-exclamation-triangle-fill text-warning',
            'danger'  => 'bi-x-circle-fill text-danger',
        ][$type] ?? 'bi-info-circle-fill text-primary';
    }
}

if (!function_exists('notification_badge_class')) {
    function notification_badge_class(string $category): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '-', $category) ?? '');
    }
}
$header_pdo = db();
if (!isset($unread_count)) {
    $headerCountStmt = $header_pdo->prepare('SELECT COUNT(*) FROM notifications WHERE is_read = 0 AND (user_id IS NULL OR user_id = ?)');
    $headerCountStmt->execute([$current_user['id']]);
    $unread_count = (int)$headerCountStmt->fetchColumn();
}
if (!isset($header_notifications)) {
    $headerNotifStmt = $header_pdo->prepare('SELECT * FROM notifications WHERE user_id IS NULL OR user_id = ? ORDER BY created_at DESC LIMIT 5');
    $headerNotifStmt->execute([$current_user['id']]);
    $header_notifications = $headerNotifStmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= account_theme() ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($page_title) ?> · <?= e(company_name()) ?></title>
  <meta name="description" content="Enterprise Fleet & Transportation Management System with AI-Driven Route Planning and Vehicle Management for Travel and Tours.">
  <link rel="icon" href="<?= e(company_logo()) ?>">

   
  <link href="<?= BASE_URL ?>/css/vendor/bootstrap.min.css" rel="stylesheet">
   
  <link href="<?= BASE_URL ?>/css/vendor/bootstrap-icons.min.css" rel="stylesheet">
  <?php if ($include_leaflet): ?>
   
  <link rel="stylesheet" href="<?= BASE_URL ?>/css/vendor/leaflet/leaflet.css">
  <?php endif; ?>
   
  <link rel="stylesheet" href="<?= BASE_URL ?>/css/style.css?v=<?= (int)@filemtime(ROOT_PATH . '/css/style.css') ?>">
  <?php if (($active_page ?? '') === 'settings'): ?><link rel="stylesheet" href="<?= BASE_URL ?>/css/settings.css?v=<?= (int)filemtime(ROOT_PATH . '/css/settings.css') ?>"><?php endif; ?>
  <link rel="stylesheet" href="<?= BASE_URL ?>/css/theme.css?v=<?= (int)filemtime(ROOT_PATH . '/css/theme.css') ?>">
  <script>window.fleetCurrencySymbol = <?= json_encode(currency_symbol(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
</head>
<body<?= !empty($body_class) ? ' class="' . e($body_class) . '"' : '' ?>>

  <div id="app-layout">
    <?php require ROOT_PATH . '/includes/sidebar.php'; ?>

     
    <div id="main-wrapper">
       
      <header id="top-header">
        <div class="d-flex align-items-center gap-3">
          <button id="sidebar-toggle-btn" class="btn btn-sm btn-light border d-none d-lg-inline-flex" title="Toggle Sidebar">
            <i class="bi bi-layout-sidebar-inset"></i>
          </button>
          <button id="mobile-menu-btn" class="btn btn-sm btn-light border d-inline-flex d-lg-none" title="Open Mobile Menu">
            <i class="bi bi-list fs-5"></i>
          </button>

          <?php if (!$is_driver): ?>
           
          <form class="input-group input-group-sm" style="width: 280px;" id="global-search-wrapper" action="<?= BASE_URL ?>/modules/fleet-vehicle-management/vehicle-directory.php" method="get">
            <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search"></i></span>
            <input type="text" id="global-search-input" name="q" class="form-control border-start-0 ps-0" placeholder="Search plates, drivers, trips, routes..." value="<?= e($_GET['q'] ?? '') ?>">
          </form>
          <?php endif; ?>
        </div>

        <div class="d-flex align-items-center gap-3">
          <?php if (can('ai.view')): ?>
          <a class="tc-btn tc-btn-primary tc-btn-sm" id="header-plan-route-btn" href="<?= BASE_URL ?>/modules/ai-route-optimization/ai-route-planner.php">
            <i class="bi bi-stars"></i> Plan AI Route
          </a>
          <?php endif; ?>

           
          <div class="dropdown">
            <button class="btn btn-sm btn-light position-relative border" id="header-notif-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Notifications">
              <i class="bi bi-bell"></i>
              <span id="header-notif-badge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 10px; padding: 3px 6px; <?= $unread_count > 0 ? '' : 'display:none;' ?>"><?= (int)$unread_count ?></span>
            </button>
            <div class="dropdown-menu dropdown-menu-end shadow-sm mt-2 notifications-dropdown" aria-labelledby="header-notif-btn">
              <div class="d-flex align-items-center justify-content-between px-3 py-2 notifications-dropdown-header">
                <span class="fw-semibold small"><i class="bi bi-bell-fill me-1 text-primary"></i>Notifications</span>
                <a href="<?= BASE_URL ?>/notifications.php" class="small fw-semibold text-primary text-decoration-none">View All</a>
              </div>
              <div class="notifications-dropdown-list" id="notifications-dropdown-list">
                <?php if (empty($header_notifications)): ?>
                  <div class="px-3 py-4 text-center text-muted-custom small">
                    <i class="bi bi-bell-slash d-block mb-1" style="font-size: 22px;"></i>
                    No notifications
                  </div>
                <?php else: ?>
                  <?php foreach ($header_notifications as $n): ?>
                  <a href="<?= notification_target_url($n['target']) ?>" data-notif-id="<?= (int)$n['id'] ?>" class="notifications-item d-block px-3 py-3 text-decoration-none <?= $n['is_read'] ? '' : 'unread' ?>" onclick="App.markNotificationRead(<?= (int)$n['id'] ?>, event)">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                      <span class="fw-semibold small <?= $n['type'] === 'warning' ? 'text-danger' : 'text-dark' ?>"><?= e($n['title']) ?></span>
                      <span class="notif-badge <?= notification_badge_class($n['category']) ?> flex-shrink-0"><?= e($n['category']) ?></span>
                    </div>
                    <div class="text-muted-custom small mt-1"><?= e($n['body']) ?></div>
                  </a>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>
          </div>

           
          <div class="dropdown">
            <button class="account-profile-chip d-flex align-items-center gap-2 border-0 bg-transparent p-1 rounded-3" type="button" id="account-profile-menu-btn" data-bs-toggle="dropdown" aria-expanded="false">
              <img data-current-user-avatar src="<?= e($current_user['avatar'] ?? '') ?>" alt="Avatar" class="rounded-circle" style="width: 34px; height: 34px; object-fit: cover;" onerror="this.src='<?= BASE_URL ?>/assets/images/toursphere-logo.png';">
              <div class="d-none d-md-block lh-sm text-start">
                <div class="fw-semibold small" id="current-user-name"><?= e($current_user['name'] ?? '') ?></div>
                <div class="text-muted-custom" style="font-size: 11px;" id="current-user-role-badge"><?= e($current_user['role_name'] ?? '') ?></div>
              </div>
              <i class="bi bi-chevron-down small text-muted-custom d-none d-md-block"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm mt-2 p-1 account-profile-menu" aria-labelledby="account-profile-menu-btn">
              <li>
                <a class="dropdown-item rounded-2 d-flex align-items-center gap-2" href="#" onclick="App.openAccountProfile(); return false;">
                  <i class="bi bi-person fs-6 text-muted-custom"></i> Profile
                </a>
              </li>
              <?php if (is_logged_in()): ?>
              <li>
                <a class="dropdown-item rounded-2 d-flex align-items-center gap-2" href="<?= BASE_URL ?>/settings.php">
                  <i class="bi bi-gear fs-6 text-muted-custom"></i> Settings
                </a>
              </li>
              <?php endif; ?>
              <li><hr class="dropdown-divider my-1"></li>
              <li>
                <a class="dropdown-item rounded-2 d-flex align-items-center gap-2 text-danger" href="<?= BASE_URL ?>/logout.php">
                  <i class="bi bi-box-arrow-right fs-6"></i> Logout
                </a>
              </li>
            </ul>
          </div>
        </div>
      </header>

       
      <main id="content-container">
