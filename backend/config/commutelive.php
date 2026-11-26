<?php

return [
    'pings_retention_days' => (int) env('PINGS_RETENTION_DAYS', 30),
    'segment_length_m' => (float) env('SEGMENT_LENGTH_M', 250),
    'stop_max_offset_m' => (float) env('STOP_MAX_OFFSET_M', 100),
    'route_data_path' => env('ROUTE_DATA_PATH', dirname(__DIR__, 2).'/data'),
    'ping_batch_limit' => (int) env('PING_BATCH_LIMIT', 200),
    'ping_max_accuracy_m' => (float) env('PING_MAX_ACCURACY_M', 50),
    'ping_max_offset_m' => (float) env('PING_MAX_OFFSET_M', 100),
    'ping_max_speed_kph' => (float) env('PING_MAX_SPEED_KPH', 160),
    'ping_future_tolerance_seconds' => (int) env('PING_FUTURE_TOLERANCE_SECONDS', 15),
    'vehicle_stale_seconds' => (int) env('VEHICLE_STALE_SECONDS', 60),
    'occupancy_stale_seconds' => (int) env('OCCUPANCY_STALE_SECONDS', 300),
    'incident_ttl_minutes' => (int) env('INCIDENT_TTL_MINUTES', 30),
    'incident_max_offset_m' => (float) env('INCIDENT_MAX_OFFSET_M', 100),
    'ingestion_requests_per_minute' => (int) env('INGESTION_REQUESTS_PER_MINUTE', 60),
    'driver_updates_per_minute' => (int) env('DRIVER_UPDATES_PER_MINUTE', 30),
    'auth_requests_per_minute' => (int) env('AUTH_REQUESTS_PER_MINUTE', 5),
    'auth_ip_requests_per_minute' => (int) env('AUTH_IP_REQUESTS_PER_MINUTE', 20),
    'token_lifetime_minutes' => (int) env('TOKEN_LIFETIME_MINUTES', 1440),
    'privacy_notice_version' => '2026-11-26',
    'demo_password' => env('DEMO_PASSWORD'),
];
