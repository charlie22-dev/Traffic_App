<?php

return [
    // Domain thresholds are added here with the phase that implements them.
    // No GPS pings are collected in Phase 0. Add and test the purge command
    // before accepting location records in a later phase.
    'pings_retention_days' => (int) env('PINGS_RETENTION_DAYS', 30),
];
