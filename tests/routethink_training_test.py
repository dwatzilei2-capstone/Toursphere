"""Preprocessing checks only; never train, store, or activate a model."""
import importlib.util
from pathlib import Path

source = Path(__file__).resolve().parents[1] / 'scripts' / 'train_routethink.py'
spec = importlib.util.spec_from_file_location('routethink', source)
training = importlib.util.module_from_spec(spec)
spec.loader.exec_module(training)
row = dict(distance_km=40, base_duration_mins=60, traffic_delay_ratio=1.2,
           waypoint_count=0, baseline_km_per_liter=8, vehicle_weight_class=1,
           cargo_load_ratio=0.25, highway_ratio=0.5, actual_fuel_liters=6)
X, y = training.clean_and_extract_features([row])
assert X.shape == (1, 8) and X[0, 6] == 0.25 and y[0] == 6
legacy = dict(row)
legacy['passenger_load_ratio'] = legacy.pop('cargo_load_ratio')
assert len(training.clean_and_extract_features([legacy])[0]) == 0
for field, value in [('cargo_load_ratio', -1), ('cargo_load_ratio', float('nan')),
                     ('baseline_km_per_liter', 0)]:
    assert len(training.clean_and_extract_features([dict(row, **{field: value})])[0]) == 0
incomplete = dict(row)
del incomplete['highway_ratio']
assert len(training.clean_and_extract_features([incomplete])[0]) == 0
print('6 cargo preprocessing checks passed; no model trained or written.')
