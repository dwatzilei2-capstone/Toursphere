<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';
function audit_assert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$pdo = db();
$original = $current_user;
try {
    foreach (['fleet_admin'=>true,'dispatcher'=>false,'driver'=>false,'customer'=>false,'user'=>false] as $role=>$allowed) {
        $current_user = ['id'=>1,'role_code'=>$role];
        $current_permissions = ['audit.view'];
        audit_assert(audit_can_view() === $allowed, 'Role access: ' . $role);
    }
    $current_user = null;
    audit_assert(!audit_can_view(), 'Anonymous access denied');
    $pdo->beginTransaction();
    $users = $pdo->query("SELECT DISTINCT ON (r.code) u.id,u.name,u.email,r.code,r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.status='Active' ORDER BY r.code,u.id")->fetchAll();
    foreach ($users as $user) {
        audit_set_actor($pdo,$user);
        $q = $pdo->prepare('SELECT recovery_email FROM users WHERE id=?'); $q->execute([$user['id']]); $old = $q->fetchColumn();
        $pdo->prepare('UPDATE users SET recovery_email=? WHERE id=?')->execute(['audit-verification@example.invalid',$user['id']]);
        $q=$pdo->prepare("SELECT * FROM audit_logs WHERE entity_type='users' AND entity_id=? ORDER BY id DESC LIMIT 1"); $q->execute([(string)$user['id']]); $log=$q->fetch();
        audit_assert((int)$log['user_id']===(int)$user['id'] && $log['actor_role']===$user['role_name'], 'Actor snapshot: '.$user['code']);
        $before=json_decode($log['old_values'],true); $after=json_decode($log['new_values'],true);
        audit_assert($before['recovery_email']===$old && $after['recovery_email']==='audit-verification@example.invalid', 'Before/after captured');
        audit_assert(!array_key_exists('password_hash',$after), 'Credentials redacted');
    }
    foreach (['UPDATE audit_logs SET action=action','DELETE FROM audit_logs WHERE false','TRUNCATE audit_logs'] as $sql) {
        $pdo->exec('SAVEPOINT readonly_test');
        $blocked=false;
        try { $pdo->exec($sql); } catch (PDOException $e) { $blocked=str_contains($e->getMessage(),'append-only'); }
        $pdo->exec('ROLLBACK TO SAVEPOINT readonly_test');
        audit_assert($blocked,'Audit mutation rejected');
    }
    $pdo->rollBack();
    echo "PASS: role restrictions, all available account roles, actor snapshots, old/new values, secret redaction and append-only integrity. Test changes rolled back.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $current_user=$original; audit_set_actor($pdo,$original);
}
