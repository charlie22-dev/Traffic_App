<?php

return [
    // Domain thresholds are added here with the phase that implements them.
    // No GPS pings are collected in Phase 0. Add and test the purge command
    // before accepting location records in a later phase.
    'pings_retention_days' => (int) env('PINGS_RETENTION_DAYS', 30),
    'segment_length_m' => (float) env('SEGMENT_LENGTH_M', 250),
    'stop_max_offset_m' => (float) env('STOP_MAX_OFFSET_M', 100),
    'route_data_path' => env('ROUTE_DATA_PATH', dirname(__DIR__, 2).'/data'),
];
