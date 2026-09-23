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
         
        $tripsCount = (int)$pdo->query("SELECT COUNT(*) FROM trips WHERE status = 'Completed'")->fetchColumn();
        $fuelLogsCount = (int)$pdo->query("SELECT COUNT(*) FROM fuel_transactions")->fetchColumn();
        $routeLogsCount = (int)$pdo->query("SELECT COUNT(*) FROM route_history")->fetchColumn();
        
        $fuelSamples = (int)$pdo->query("SELECT COUNT(*) FROM route_history WHERE pre_trip_features_json IS NOT NULL AND pre_trip_features_json <> '' AND pre_trip_features_json <> 'null' AND actual_fuel_liters > 0 AND actual_fuel_verified = 1")->fetchColumn();
        $durationSamples = (int)$pdo->query("SELECT COUNT(*) FROM route_history WHERE pre_trip_features_json IS NOT NULL AND pre_trip_features_json <> '' AND pre_trip_features_json <> 'null' AND actual_duration_mins > 0")->fetchColumn();
        $totalUsableSamples = max($fuelSamples, $durationSamples);

         
        $lifecycleState = 'ColdStart';
        if ($totalUsableSamples >= 100) {
            $lifecycleState = 'Validated';
        } elseif ($totalUsableSamples >= 30) {
            $lifecycleState = 'Experimental';
        }

         
        $deployedFuel = $pdo->query(
            "SELECT * FROM ai_model_registry WHERE target_variable = 'fuel_liters' AND status = 'Deployed' ORDER BY deployed_at DESC LIMIT 1"
        )->fetch() ?: null;

        $deployedDuration = $pdo->query(
            "SELECT * FROM ai_model_registry WHERE target_variable = 'duration_mins' AND status = 'Deployed' ORDER BY deployed_at DESC LIMIT 1"
        )->fetch() ?: null;

         
        $models = $pdo->query("SELECT * FROM ai_model_registry ORDER BY id DESC LIMIT 20")->fetchAll();

        echo json_encode([
            'ok'                   => true,
            'lifecycle_state'      => $lifecycleState,
            'total_usable_samples' => $totalUsableSamples,
            'completed_trips'      => $tripsCount,
            'fuel_transactions'    => $fuelLogsCount,
            'route_history_logs'   => $routeLogsCount,
            'fuel_samples'         => $fuelSamples,
            'duration_samples'     => $durationSamples,
            'deployed_fuel_model'  => $deployedFuel,
            'deployed_duration_model' => $deployedDuration,
            'models'               => $models,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'train') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'POST method required for training.']);
            exit;
        }

        $targetVar = sanitize_text($_POST['target'] ?? 'actual_fuel_liters');
        if (!in_array($targetVar, ['actual_fuel_liters', 'actual_duration_mins', 'fuel_liters', 'duration_mins'], true)) {
            $targetVar = 'actual_fuel_liters';
        }
        $targetDbKey = str_contains($targetVar, 'duration') ? 'duration_mins' : 'fuel_liters';
        $targetVar = $targetDbKey === 'duration_mins' ? 'actual_duration_mins' : 'actual_fuel_liters';

         
        $records = [];
        $targetColumn = $targetDbKey === 'duration_mins' ? 'actual_duration_mins' : 'actual_fuel_liters';
        $verifiedFuelClause = $targetDbKey === 'fuel_liters' ? ' AND actual_fuel_verified = 1' : '';
        $rhStmt = $pdo->query(
            "SELECT pre_trip_features_json, actual_fuel_liters, actual_duration_mins 
             FROM route_history 
             WHERE pre_trip_features_json IS NOT NULL
               AND pre_trip_features_json <> ''
               AND pre_trip_features_json <> 'null'
               AND {$targetColumn} IS NOT NULL
               AND {$targetColumn} > 0
               {$verifiedFuelClause}"
        );
        while ($rh = $rhStmt->fetch()) {
            if (!empty($rh['pre_trip_features_json'])) {
                $features = json_decode($rh['pre_trip_features_json'], true);
                if (is_array($features)) {
                    $features['actual_fuel_liters'] = (float)($rh['actual_fuel_liters'] ?? 0);
                    $features['actual_duration_mins'] = (int)($rh['actual_duration_mins'] ?? 0);
                    if (($features[$targetVar] ?? 0) > 0 && ($features['distance_km'] ?? 0) > 0) {
                        $records[] = $features;
                    }
                }
            }
        }

        if (count($records) < 30) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Cold Start: ' . count($records) . ' valid paired sample(s) available for this target. At least 30 real completed-trip samples are required.']);
            exit;
        }

         
        $tempJsonPath = sys_get_temp_dir() . '/routethink_train_' . time() . '_' . uniqid() . '.json';
        file_put_contents($tempJsonPath, json_encode($records, JSON_UNESCAPED_UNICODE));

         
        $scriptPath = dirname(__DIR__) . '/scripts/train_routethink.py';
        $cmd = "python " . escapeshellarg($scriptPath) . " " . escapeshellarg($tempJsonPath) . " --target " . escapeshellarg($targetVar);
        
        $output = shell_exec($cmd);
        @unlink($tempJsonPath);

        if (!$output) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Python training pipeline execution failed.']);
            exit;
        }

        $trainResult = json_decode($output, true);
        if (!$trainResult || empty($trainResult['ok'])) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => $trainResult['error'] ?? 'Invalid training response.', 'raw' => $output]);
            exit;
        }

         
        $stmt = $pdo->prepare(
            'INSERT INTO ai_model_registry (
                model_id, version, algorithm, target_variable, training_samples, 
                validation_samples, mae, rmse, r2_score, feature_list_json, 
                hyperparameters_json, weights_json, status, created_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $trainResult['model_id'],
            $trainResult['version'],
            $trainResult['algorithm'],
            $targetDbKey,
            $trainResult['training_samples'],
            $trainResult['validation_samples'],
            $trainResult['mae'],
            $trainResult['rmse'],
            $trainResult['r2_score'],
            json_encode($trainResult['feature_list']),
            json_encode($trainResult['hyperparameters']),
            json_encode($trainResult['weights']),
            $trainResult['status'],
        ]);

        $newId = (int)$pdo->query("SELECT currval(pg_get_serial_sequence('ai_model_registry', 'id'))")->fetchColumn();

        echo json_encode([
            'ok'             => true,
            'id'             => $newId,
            'model_id'       => $trainResult['model_id'],
            'version'        => $trainResult['version'],
            'target_variable'=> $targetDbKey,
            'status'         => $trainResult['status'],
            'training_samples'=> $trainResult['training_samples'],
            'validation_samples'=> $trainResult['validation_samples'],
            'mae'            => $trainResult['mae'],
            'rmse'           => $trainResult['rmse'],
            'r2_score'       => $trainResult['r2_score'],
            'message'        => $trainResult['message'],
        ], JSON_UNESCAPED_UNICODE);
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

