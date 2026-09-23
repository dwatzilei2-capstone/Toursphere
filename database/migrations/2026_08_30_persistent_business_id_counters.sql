 
 
INSERT INTO system_settings (setting_key, setting_value, description) VALUES
('sequence.reservations.id.res-2026', '1', 'Persistent sequential ID counter'),
('sequence.trips.id.trp', '1', 'Persistent sequential ID counter'),
('sequence.route_history.log_id.log-air', '1', 'Persistent sequential ID counter')
ON CONFLICT (setting_key) DO NOTHING;
