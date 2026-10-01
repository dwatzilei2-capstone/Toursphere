<?php
 



require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/routethink_engine.php';
require_login();

header('Content-Type: application/json; charset=UTF-8');

if (!has_role('fleet_admin')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'AI Model Training is available to administrators only.']);
    exit;
}

$action = $_GET['action'] ?? ($_POST['action'] ?? 'status');

try {
    $pdo = db();

    if ($action === 'status') {
        echo json_encode(['ok' => true, 'learning_mode' => 'continuous', 'minimum_samples' => 1,
            'statistics' => ai_learning_stats($pdo)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'train') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'POST required.']);
            exit;
        }
        if (!is_string($_POST['learning_csrf'] ?? null) || !hash_equals($_SESSION['learning_csrf'] ?? '', $_POST['learning_csrf'])) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Refresh the learning page and try again.']);
            exit;
        }
        $updated = ai_learning_sync($pdo);
        echo json_encode(['ok' => true, 'message' => "$updated new or corrected outcomes learned. Existing learning was preserved."]);
        exit;
    }

    if ($action === 'deploy') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'POST method required for deployment.']);
            exit;
        }

        $modelId = sanitize_text($_POST['model_id'] ?? '');
        if (empty($modelId)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Model ID is required.']);
            exit;
        }

        $mStmt = $pdo->prepare('SELECT * FROM ai_model_registry WHERE model_id = ?');
        $mStmt->execute([$modelId]);
        $model = $mStmt->fetch();

        if (!$model) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Model not found in registry.']);
            exit;
        }

        if ($model['status'] !== 'Validated') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Only a model that passed validation can be deployed.']);
            exit;
        }

         
        $pdo->prepare(
            "UPDATE ai_model_registry SET status = 'Validated' WHERE target_variable = ? AND status = 'Deployed'"
        )->execute([$model['target_variable']]);

         
        $pdo->prepare(
            "UPDATE ai_model_registry SET status = 'Deployed', deployed_at = NOW() WHERE model_id = ?"
        )->execute([$modelId]);

        echo json_encode([
            'ok'      => true,
            'message' => "Model {$modelId} ({$model['version']}) successfully deployed for {$model['target_variable']} prediction in RouteThink.",
            'model_id'=> $modelId,
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => "Unknown action: {$action}"]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'AI Training Controller Error: ' . $e->getMessage()]);
}
