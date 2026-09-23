<?php
 



$active_page = $active_page ?? '';
$is_driver = ($current_user['role_code'] ?? '') === 'driver';

if (!function_exists('nav_active')) {
    function nav_active($key): string
    {
        global $active_page;
        return $active_page === $key ? 'active' : '';
    }
}
?>
<div id="sidebar-backdrop"></div>

 
<aside id="sidebar">
  <a href="<?= BASE_URL ?>/dashboard.php" class="sidebar-brand">
    <img src="<?= e(company_logo()) ?>" alt="<?= e(company_name()) ?> logo" class="brand-logo-img">
    <div class="brand-details">
      <div class="brand-title"><?= e(company_name()) ?></div>
      <div class="brand-subsystem-pill">Fleet & Transportation</div>
    </div>
  </a>

  <div class="sidebar-nav-container">
    <?php if ($is_driver): ?>
       
      <div class="sidebar-section-title">Driver Portal</div>
      <ul class="p-0 m-0">
        <li class="nav-item-custom">
          <a class="nav-link-custom <?= nav_active('driver-dashboard') ?>" href="<?= BASE_URL ?>/modules/driver-portal/driver-dashboard.php">
            <i class="bi bi-grid-1x2"></i>
            <span class="nav-label">Dashboard</span>
          </a>
        </li>
        <li class="nav-item-custom">
          <a class="nav-link-custom <?= nav_active('driver-trips') ?>" href="<?= BASE_URL ?>/modules/driver-portal/driver-trips.php">
            <i class="bi bi-calendar2-check"></i>
            <span class="nav-label">My Trips</span>
          </a>
        </li>
        <li class="nav-item-custom">
          <a class="nav-link-custom <?= nav_active('driver-vehicle') ?>" href="<?= BASE_URL ?>/modules/driver-portal/driver-vehicle.php">
            <i class="bi bi-truck"></i>
            <span class="nav-label">My Vehicle</span>
          </a>
        </li>
        <li class="nav-item-custom">
          <a class="nav-link-custom <?= nav_active('driver-route') ?>" href="<?= BASE_URL ?>/modules/driver-portal/driver-route.php">
            <i class="bi bi-signpost-split"></i>
            <span class="nav-label">Route Info</span>
          </a>
        </li>
      </ul>

       
      <div class="sidebar-section-title">Operations & Navigation</div>
      <ul class="p-0 m-0">
        <?php if (can('fuel.view')): 
          $is_driver_fuel_active = in_array($active_page, ['fuel-dashboard','fuel-transactions']);
        ?>
        <li class="nav-item-custom">
          <a class="nav-link-custom nav-has-sub <?= $is_driver_fuel_active ? 'active expanded open' : '' ?>" href="javascript:void(0);">
            <i class="bi bi-fuel-pump"></i>
            <span class="nav-label">Fuel Management</span>
            <i class="bi bi-chevron-down ms-auto nav-chevron small"></i>
          </a>
          <ul class="nav-submenu <?= $is_driver_fuel_active ? 'open' : '' ?>">
            <li><a class="nav-sublink <?= nav_active('fuel-dashboard') ?>" href="<?= BASE_URL ?>/modules/fuel-management/fuel-overview.php">Fuel Overview</a></li>
            <li><a class="nav-sublink <?= nav_active('fuel-transactions') ?>" href="<?= BASE_URL ?>/modules/fuel-management/fuel-transactions.php">Transactions Log</a></li>
          </ul>
        </li>
        <?php endif; ?>

        <?php if (can('ai.view')): 
          $is_driver_ai_active = in_array($active_page, ['ai-route-planner','route-comparison','route-history']);
        ?>
        <li class="nav-item-custom">
          <a class="nav-link-custom nav-has-sub <?= $is_driver_ai_active ? 'active expanded open' : '' ?>" href="javascript:void(0);">
            <i class="bi bi-cpu text-primary-custom"></i>
            <span class="nav-label fw-semibold">AI Route Optimization</span>
            <span class="sidebar-badge bg-primary-subtle text-primary border me-2 ms-auto">AI</span>
            <i class="bi bi-chevron-down nav-chevron small"></i>
          </a>
          <ul class="nav-submenu <?= $is_driver_ai_active ? 'open' : '' ?>">
            <li><a class="nav-sublink <?= nav_active('ai-route-planner') ?>" href="<?= BASE_URL ?>/modules/ai-route-optimization/ai-route-planner.php">AI Route Planner</a></li>
            <li><a class="nav-sublink <?= nav_active('route-history') ?>" href="<?= BASE_URL ?>/modules/ai-route-optimization/route-history.php">My Route History</a></li>
          </ul>
        </li>
        <?php endif; ?>
      </ul>

       
    <?php else: ?>

       
      <div class="sidebar-section-title">Main</div>
      <ul class="p-0 m-0">
        <li class="nav-item-custom">
          <a class="nav-link-custom <?= nav_active('dashboard') ?>" href="<?= BASE_URL ?>/dashboard.php">
            <i class="bi bi-grid-1x2"></i>
            <span class="nav-label">Dashboard</span>
          </a>
        </li>
      </ul>

       
      <div class="sidebar-section-title">Fleet & Transportation Management</div>
      <ul class="p-0 m-0">
        <?php if (can('vehicles.view')): 
          $is_m1_active = in_array($active_page, ['vehicles','vehicle-assignment','maintenance']);
        ?>
        <li class="nav-item-custom">
          <a class="nav-link-custom nav-has-sub <?= $is_m1_active ? 'active expanded open' : '' ?>" href="javascript:void(0);">
            <i class="bi bi-truck"></i>
            <span class="nav-label">1. Fleet & Vehicles</span>
            <i class="bi bi-chevron-down ms-auto nav-chevron small"></i>
          </a>
          <ul class="nav-submenu <?= $is_m1_active ? 'open' : '' ?>">
            <li><a class="nav-sublink <?= nav_active('vehicles') ?>" href="<?= BASE_URL ?>/modules/fleet-vehicle-management/vehicle-directory.php">Vehicle Directory</a></li>
            <?php if (can('vehicles.assign')): ?>
            <li><a class="nav-sublink <?= nav_active('vehicle-assignment') ?>" href="<?= BASE_URL ?>/modules/fleet-vehicle-management/vehicle-assignment.php">Vehicle Assignment</a></li>
            <?php endif; ?>
            <li><a class="nav-sublink <?= nav_active('maintenance') ?>" href="<?= BASE_URL ?>/modules/fleet-vehicle-management/maintenance.php">Maintenance Info</a></li>
          </ul>
        </li>
        <?php endif; ?>

        <?php if (can('dispatch.view')): 
          $is_m2_active = in_array($active_page, ['reservations','dispatch-board','trip-schedule']);
        ?>
        <li class="nav-item-custom">
          <a class="nav-link-custom nav-has-sub <?= $is_m2_active ? 'active expanded open' : '' ?>" href="javascript:void(0);">
            <i class="bi bi-calendar2-check"></i>
            <span class="nav-label">2. Reservation & Dispatch</span>
            <i class="bi bi-chevron-down ms-auto nav-chevron small"></i>
          </a>
          <ul class="nav-submenu <?= $is_m2_active ? 'open' : '' ?>">
            <li><a class="nav-sublink <?= nav_active('reservations') ?>" href="<?= BASE_URL ?>/modules/vehicle-reservation-dispatch/reservations.php">Reservations</a></li>
            <li><a class="nav-sublink <?= nav_active('dispatch-board') ?>" href="<?= BASE_URL ?>/modules/vehicle-reservation-dispatch/dispatch-board.php">Dispatch Board</a></li>
            <li><a class="nav-sublink <?= nav_active('trip-schedule') ?>" href="<?= BASE_URL ?>/modules/vehicle-reservation-dispatch/trip-schedule.php">Trip Schedule</a></li>
          </ul>
        </li>
        <?php endif; ?>

        <?php if (can('drivers.view')): 
          $is_m3_active = in_array($active_page, ['drivers','trip-performance']);
        ?>
        <li class="nav-item-custom">
          <a class="nav-link-custom nav-has-sub <?= $is_m3_active ? 'active expanded open' : '' ?>" href="javascript:void(0);">
            <i class="bi bi-person-badge"></i>
            <span class="nav-label">3. Driver & Trip Monitoring</span>
            <i class="bi bi-chevron-down ms-auto nav-chevron small"></i>
          </a>
          <ul class="nav-submenu <?= $is_m3_active ? 'open' : '' ?>">
            <li><a class="nav-sublink <?= nav_active('drivers') ?>" href="<?= BASE_URL ?>/modules/driver-trip-performance/driver-performance.php">Driver Performance</a></li>
            <li><a class="nav-sublink <?= nav_active('trip-performance') ?>" href="<?= BASE_URL ?>/modules/driver-trip-performance/trip-performance.php">Trip Performance</a></li>
          </ul>
        </li>
        <?php endif; ?>

        <?php if (can('fuel.view')): 
          $is_m4_active = in_array($active_page, ['fuel-dashboard','fuel-transactions']);
        ?>
        <li class="nav-item-custom">
          <a class="nav-link-custom nav-has-sub <?= $is_m4_active ? 'active expanded open' : '' ?>" href="javascript:void(0);">
            <i class="bi bi-fuel-pump"></i>
            <span class="nav-label">4. Fuel Management</span>
            <i class="bi bi-chevron-down ms-auto nav-chevron small"></i>
          </a>
          <ul class="nav-submenu <?= $is_m4_active ? 'open' : '' ?>">
            <li><a class="nav-sublink <?= nav_active('fuel-dashboard') ?>" href="<?= BASE_URL ?>/modules/fuel-management/fuel-overview.php">Fuel Overview</a></li>
            <li><a class="nav-sublink <?= nav_active('fuel-transactions') ?>" href="<?= BASE_URL ?>/modules/fuel-management/fuel-transactions.php">Transactions Log</a></li>
          </ul>
        </li>
        <?php endif; ?>

        <?php if (can('costs.view')): 
          $is_m5_active = in_array($active_page, ['cost-overview','cost-by-vehicle','cost-trends']);
        ?>
        <li class="nav-item-custom">
          <a class="nav-link-custom nav-has-sub <?= $is_m5_active ? 'active expanded open' : '' ?>" href="javascript:void(0);">
            <i class="bi bi-calculator"></i>
            <span class="nav-label">5. Cost Analysis (TCAO)</span>
            <i class="bi bi-chevron-down ms-auto nav-chevron small"></i>
          </a>
          <ul class="nav-submenu <?= $is_m5_active ? 'open' : '' ?>">
            <li><a class="nav-sublink <?= nav_active('cost-overview') ?>" href="<?= BASE_URL ?>/modules/cost-analysis/cost-overview.php">Cost Overview</a></li>
            <li><a class="nav-sublink <?= nav_active('cost-by-vehicle') ?>" href="<?= BASE_URL ?>/modules/cost-analysis/cost-by-vehicle.php">Cost by Vehicle</a></li>
            <?php if (can('costs.manage') || can('vehicles.manage')): ?>
            <li><a class="nav-sublink <?= nav_active('cost-trends') ?>" href="<?= BASE_URL ?>/modules/cost-analysis/cost-trends.php">Cost Trends</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>

        <?php if (can('ai.view')): 
          $is_m6_active = in_array($active_page, ['ai-route-planner','route-comparison','route-history']);
        ?>
        <li class="nav-item-custom">
          <a class="nav-link-custom nav-has-sub <?= $is_m6_active ? 'active expanded open' : '' ?>" href="javascript:void(0);">
            <i class="bi bi-cpu text-primary-custom"></i>
            <span class="nav-label fw-semibold">6. AI Route Optimization</span>
            <span class="sidebar-badge bg-primary-subtle text-primary border me-2 ms-auto">AI</span>
            <i class="bi bi-chevron-down nav-chevron small"></i>
          </a>
          <ul class="nav-submenu <?= $is_m6_active ? 'open' : '' ?>">
            <li><a class="nav-sublink <?= nav_active('ai-route-planner') ?>" href="<?= BASE_URL ?>/modules/ai-route-optimization/ai-route-planner.php">AI Route Planner</a></li>
            <li><a class="nav-sublink <?= nav_active('route-comparison') ?>" href="<?= BASE_URL ?>/modules/ai-route-optimization/route-comparison.php">Route Comparison</a></li>
            <li><a class="nav-sublink <?= nav_active('route-history') ?>" href="<?= BASE_URL ?>/modules/ai-route-optimization/route-history.php">Route History</a></li>
          </ul>
        </li>
        <?php endif; ?>
      </ul>

       
      <div class="sidebar-section-title">System</div>
      <ul class="p-0 m-0">
        <?php if (can('reports.view')): ?>
        <li class="nav-item-custom">
          <a class="nav-link-custom <?= nav_active('reports') ?>" href="<?= BASE_URL ?>/reports.php">
            <i class="bi bi-file-earmark-bar-graph"></i>
            <span class="nav-label">Reports</span>
          </a>
        </li>
        <?php endif; ?>
      </ul>
    <?php endif; ?>
  </div>
</aside>
<script>
  (function() {
    try {
      var saved = sessionStorage.getItem("tc-sidebar-scroll") || localStorage.getItem("tc-sidebar-scroll");
      if (saved !== null) {
        var top = parseInt(saved, 10);
        if (!isNaN(top) && top > 0) {
          var sb = document.getElementById("sidebar");
          if (sb) sb.scrollTop = top;
        }
      }
    } catch (_) {}
  })();
</script>
