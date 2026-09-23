<?php
 
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_permission('notifications.view');

$pdo = db();

$notificationsStmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id IS NULL OR user_id = ? ORDER BY id DESC');
$notificationsStmt->execute([$current_user['id']]);
$notifications = $notificationsStmt->fetchAll();
$categories = ['All'];
foreach ($notifications as $n) {
    if ($n['category'] && !in_array($n['category'], $categories, true)) {
        $categories[] = $n['category'];
    }
}

$active_page = 'notifications';
$page_title  = 'System Notifications Center';
require ROOT_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="mb-1">System Notifications Center</h1>
    <p class="text-muted-custom mb-0">Operational dispatch alerts, vehicle maintenance notices, and route optimization updates.</p>
  </div>
  <button class="tc-btn tc-btn-secondary tc-btn-sm" onclick="App.markAllNotificationsRead()">
    <i class="bi bi-check2-all"></i> Mark All as Read
  </button>
</div>

 
<div class="tc-tabs" id="notifications-tabs">
  <?php foreach ($categories as $cat): ?>
    <button class="tc-tab-btn" data-notif-cat="<?= e($cat) ?>" onclick="App.filterNotifications('<?= e($cat) ?>')"><?= e($cat) ?></button>
  <?php endforeach; ?>
</div>

<div class="tc-card" id="notifications-full-list">
  <?php if (empty($notifications)): ?>
    <div class="text-center py-5 text-muted-custom">
      <i class="bi bi-bell-slash" style="font-size: 32px; display: block; margin-bottom: 8px;"></i>
      No notifications found.
    </div>
  <?php else: foreach ($notifications as $n): ?>
    <div class="p-3 border-bottom d-flex gap-3 align-items-start notifications-item-row <?= $n['is_read'] ? '' : 'unread' ?>"
         data-notif-row="<?= (int)$n['id'] ?>" data-notif-cat="<?= e($n['category']) ?>" data-notif-target="<?= e(notification_target_url($n['target'])) ?>"
         role="button" tabindex="0" onclick="App.openNotification(<?= (int)$n['id'] ?>, event)">
      <div class="stat-icon-wrapper flex-shrink-0">
        <i class="bi <?= notification_icon_class($n['type']) ?>"></i>
      </div>
      <div class="flex-grow-1">
        <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
          <h5 class="mb-0 fw-semibold"><?= e($n['title']) ?></h5>
          <span class="notif-badge <?= notification_badge_class($n['category']) ?> flex-shrink-0 ms-1"><?= e($n['category']) ?></span>
        </div>
        <p class="text-muted-custom mb-2 small"><?= e($n['body']) ?></p>
        <div class="d-flex gap-3 align-items-center">
          <span class="text-muted-custom small"><?= e($n['time_label']) ?></span>
          <?php if (!$n['is_read']): ?>
            <button class="btn btn-link p-0 text-primary small text-decoration-none" data-mark-read-btn="<?= (int)$n['id'] ?>" onclick="event.stopPropagation(); App.markNotificationRead(<?= (int)$n['id'] ?>)">Mark as read</button>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; endif; ?>
</div>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
