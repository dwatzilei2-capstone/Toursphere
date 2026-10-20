# Development verification

The explicit `license_development_assignment_enabled` and `license_development_verified_at` database fields allow simulated assignments only when APP_ENV is development/testing and the license has not expired. The small Dev label identifies this scope. Actual `license_verification_status` and authorized DL codes remain unchanged. Production application checks reject these simulated qualifications; the database assignment guard also requires the non-production connection environment. Active describes availability independently of license eligibility.
