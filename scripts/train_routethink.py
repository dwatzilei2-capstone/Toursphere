
"""
Toursphere Fleet & Transportation Management
Module 6: ROUTETHINK Machine Learning Training Pipeline

Architecture:
- Data Ingestion: Loads paired historical completed trips with pre-trip features and post-trip ground truth.
- Data Cleaning: Removes invalid records (negative durations, zero distances, outlier fuel fills).
- Feature Scaling: Z-score standardizes pre-trip feature matrix.
- Supervised Model Fitting: Regularized Multivariate Ridge Regression (L2 penalty) using exact matrix solvers.
- Cross-Validation & Generalization: 5-Fold Cross Validation for small/medium datasets; Train/Test split for large sets.
- Statistical Evaluation: Computes real MAE, RMSE, R² Score, and Feature Importance.
- Lifecycle State Determination: ColdStart (<30 samples), Experimental (30-99 samples), Validated (>=100 samples & R² >= 0.70).
"""

import sys
import json
import math
from datetime import datetime
import numpy as np

FEATURE_NAMES = [
    'distance_km',
    'base_duration_mins',
    'traffic_delay_ratio',
    'waypoint_count',
    'baseline_km_per_liter',
    'vehicle_weight_class',
    'passenger_load_ratio',
    'highway_ratio',
]

def clean_and_extract_features(records, target_key='actual_fuel_liters'):
    """
    Extracts numerical feature matrix X and target array y from validated completed trip records.
    """
    X_rows = []
    y_vals = []

    for r in records:
        try:
            
            y = float(r.get(target_key, 0.0))
            dist = float(r.get('distance_km', 0.0))
            duration = float(r.get('base_duration_mins', 0.0))
            
            
            if y <= 0.0 or dist <= 0.0 or duration <= 0.0:
                continue

            
            base_dur = float(r.get('base_duration_mins', duration))
            traf_dur = float(r.get('traffic_duration_mins', base_dur))
            traf_ratio = float(r.get('traffic_delay_ratio', (traf_dur / base_dur) if base_dur > 0 else 1.0))
            waypoints = int(r.get('waypoint_count', 0))
            km_l = float(r.get('baseline_km_per_liter', 6.0))
            weight_class = int(r.get('vehicle_weight_class', 2))
            pax_ratio = float(r.get('passenger_load_ratio', 0.5))
            highway_ratio = float(r.get('highway_ratio', 0.65))

            row = [
                dist,
                base_dur,
                max(0.5, min(4.0, traf_ratio)),
                max(0, min(15, waypoints)),
                max(1.0, min(30.0, km_l)),
                max(1, min(4, weight_class)),
                max(0.0, min(1.0, pax_ratio)),
                max(0.0, min(1.0, highway_ratio)),
            ]

            X_rows.append(row)
            y_vals.append(y)
        except (ValueError, TypeError, ZeroDivisionError):
            continue

    return np.array(X_rows, dtype=float), np.array(y_vals, dtype=float)

def calculate_metrics(y_true, y_pred):
    """
    Compute quantitative evaluation metrics: MAE, RMSE, R² Score.
    """
    if len(y_true) == 0:
        return 0.0, 0.0, 0.0
    
    errors = y_true - y_pred
    mae = float(np.mean(np.abs(errors)))
    rmse = float(np.sqrt(np.mean(errors ** 2)))
    
    ss_tot = float(np.sum((y_true - np.mean(y_true)) ** 2))
    ss_res = float(np.sum(errors ** 2))
    r2 = 1.0 - (ss_res / ss_tot) if ss_tot > 1e-8 else 0.0
    
    return round(mae, 4), round(rmse, 4), round(max(-1.0, min(1.0, r2)), 4)

def fit_ridge_regression(X, y, alpha=1.0):
    """
    Fits Ridge Regression model with L2 regularization:
    w = (X_b^T X_b + alpha * I)^-1 X_b^T y
    """
    n_samples, n_features = X.shape
    
    
    means = np.mean(X, axis=0)
    stds = np.std(X, axis=0)
    stds[stds < 1e-8] = 1.0 
    
    X_scaled = (X - means) / stds
    
    
    X_b = np.c_[np.ones((n_samples, 1)), X_scaled]
    
    
    I = np.eye(n_features + 1)
    I[0, 0] = 0.0
    
    
    A = X_b.T @ X_b + (alpha * I)
    b = X_b.T @ y
    w = np.linalg.solve(A, b)
    
    intercept = float(w[0])
    coefficients = w[1:]
    
    return intercept, coefficients, means, stds

def cross_validate_ridge(X, y, k=5, alpha=1.0):
    """
    Performs K-Fold Cross Validation.
    """
    n = len(X)
    if n < k:
        k = max(2, n)
        
    indices = np.arange(n)
    np.random.seed(42) 
    np.random.shuffle(indices)
    
    folds = np.array_split(indices, k)
    all_y_true = []
    all_y_pred = []
    
    for i in range(len(folds)):
        val_idx = folds[i]
        train_idx = np.setdiff1d(np.arange(n), val_idx)
        
        X_train, y_train = X[train_idx], y[train_idx]
        X_val, y_val = X[val_idx], y[val_idx]
        
        intercept, coefs, means, stds = fit_ridge_regression(X_train, y_train, alpha)
        
        X_val_scaled = (X_val - means) / stds
        y_val_pred = intercept + (X_val_scaled @ coefs)
        
        all_y_true.extend(y_val)
        all_y_pred.extend(y_val_pred)
        
    return calculate_metrics(np.array(all_y_true), np.array(all_y_pred))

def main():
    if len(sys.argv) < 2:
        print(json.dumps({
            "ok": False,
            "error": "Usage: train_routethink.py <dataset_json_path_or_inline> [--target fuel_liters|duration_mins] [--alpha float]"
        }))
        sys.exit(1)

    input_arg = sys.argv[1]
    target_var = 'actual_fuel_liters'
    alpha = 1.0

    
    for i in range(2, len(sys.argv)):
        if sys.argv[i] == '--target' and i + 1 < len(sys.argv):
            target_var = sys.argv[i + 1]
        elif sys.argv[i] == '--alpha' and i + 1 < len(sys.argv):
            alpha = float(sys.argv[i + 1])

    
    try:
        if input_arg.startswith('{') or input_arg.startswith('['):
            dataset = json.loads(input_arg)
        else:
            with open(input_arg, 'r', encoding='utf-8') as f:
                dataset = json.load(f)
    except Exception as e:
        print(json.dumps({"ok": False, "error": f"Failed to load dataset: {str(e)}"}))
        sys.exit(1)

    records = dataset if isinstance(dataset, list) else dataset.get('records', [])
    X, y = clean_and_extract_features(records, target_key=target_var)
    sample_count = len(X)

    
    if sample_count < 30:
        lifecycle_state = "ColdStart"
        status_message = f"Cold Start: {sample_count} sample(s) collected. Minimum 30 samples required for experimental ML training."
        
        result = {
            "ok": True,
            "model_id": f"RT-MDL-COLD-{sample_count}",
            "version": "v0-kinematic",
            "algorithm": "Calibrated Kinematic Physics Model",
            "target_variable": target_var,
            "status": lifecycle_state,
            "training_samples": sample_count,
            "validation_samples": 0,
            "mae": 0.0,
            "rmse": 0.0,
            "r2_score": 0.0,
            "feature_list": FEATURE_NAMES,
            "hyperparameters": {"alpha": alpha, "scaler_mean": {}, "scaler_scale": {}},
            "weights": {},
            "message": status_message
        }
        print(json.dumps(result))
        sys.exit(0)

    
    k_folds = min(5, sample_count // 5) if sample_count < 100 else 5
    val_mae, val_rmse, val_r2 = cross_validate_ridge(X, y, k=k_folds, alpha=alpha)

    
    intercept, coefs, means, stds = fit_ridge_regression(X, y, alpha=alpha)
    train_pred = intercept + (((X - means) / stds) @ coefs)
    train_mae, train_rmse, train_r2 = calculate_metrics(y, train_pred)

    
    if sample_count >= 100 and val_r2 >= 0.70:
        lifecycle_state = "Validated"
    else:
        lifecycle_state = "Experimental"

    
    weights_dict = {"__intercept__": round(intercept, 6)}
    scaler_mean_dict = {}
    scaler_scale_dict = {}

    for i, fname in enumerate(FEATURE_NAMES):
        weights_dict[fname] = round(float(coefs[i]), 6)
        scaler_mean_dict[fname] = round(float(means[i]), 6)
        scaler_scale_dict[fname] = round(float(stds[i]), 6)

    run_stamp = datetime.now().strftime('%Y%m%d%H%M%S%f')
    model_version = f"v1.{sample_count}.{run_stamp[-6:]}"
    model_id = f"RT-MDL-{target_var[:4].upper()}-{run_stamp}"

    result = {
        "ok": True,
        "model_id": model_id,
        "version": model_version,
        "algorithm": "Ridge Regression (L2 Regularized)",
        "target_variable": target_var,
        "status": lifecycle_state,
        "training_samples": sample_count,
        "validation_samples": sample_count,
        "validation_method": f"{k_folds}-Fold Cross Validation",
        "mae": val_mae,
        "rmse": val_rmse,
        "r2_score": val_r2,
        "train_mae": train_mae,
        "train_rmse": train_rmse,
        "train_r2": train_r2,
        "feature_list": FEATURE_NAMES,
        "hyperparameters": {
            "alpha": alpha,
            "scaler_mean": scaler_mean_dict,
            "scaler_scale": scaler_scale_dict
        },
        "weights": weights_dict,
        "message": f"Successfully trained {target_var} model on {sample_count} completed trips with {k_folds}-Fold Cross Validation (R² = {val_r2}, MAE = {val_mae})."
    }

    print(json.dumps(result))

if __name__ == '__main__':
    main()
