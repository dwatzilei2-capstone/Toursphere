<?php
function audit_can_view(): bool
{
    return has_role('fleet_admin');
}

function audit_require_access(): void
{
    require_login();
    if (!audit_can_view()) {
        http_response_code(403);
        exit('Forbidden');
    }
}

// Request-scoped database context; never accept actor identity from form data.
function audit_set_actor(PDO $pdo, ?array $user): void
{
    $pdo->prepare("SELECT set_config('toursphere.actor_id', ?, false)")
        ->execute([$user ? (string)$user['id'] : '']);
}

function audit_security(string $action, ?array $user = null, array $details = []): void
{
    $pdo = db();
    $pdo->prepare('INSERT INTO audit_logs(action,user_id,entity_type,entity_id,details) VALUES (?,?,?,?,?)')
        ->execute([$action, $user['id'] ?? null, 'security', isset($user['id']) ? (string)$user['id'] : null,
            json_encode($details + ['ip' => $_SERVER['REMOTE_ADDR'] ?? null], JSON_THROW_ON_ERROR)]);
}
