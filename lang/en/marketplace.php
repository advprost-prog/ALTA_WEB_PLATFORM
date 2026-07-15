<?php

return [
    'page_title' => 'Addon Marketplace',
    'operations' => [
        'heading' => 'Operations / Recovery', 'status' => 'Status', 'unresolved' => 'unresolved', 'manual' => 'manual',
        'corrupt' => 'corrupt backups', 'pending' => 'cleanup pending', 'refresh' => 'Refresh diagnostics',
        'operation' => 'Operation', 'addon' => 'Addon', 'state' => 'State', 'classification' => 'Classification',
        'evidence' => 'Evidence', 'actions' => 'Actions', 'dry_run' => 'Dry run', 'run_safe' => 'Run safe recovery',
        'rollback_preflight' => 'Rollback preflight', 'mark_manual' => 'Mark manual', 'manual_reason' => 'Manual intervention reason',
        'completed_rollback' => 'Completed update rollback', 'rollback_dry' => 'Rollback dry run', 'execute_rollback' => 'Execute rollback',
    ],
    'backups' => ['heading' => 'Backup retention', 'none' => 'No managed backups.', 'last_good' => 'last-known-good', 'reference' => 'unresolved reference', 'cleanup' => 'Cleanup exact backup'],
    'stale' => ['heading' => 'Stale recovery data', 'none' => 'No stale managed remnants.', 'cleanup' => 'Cleanup exact item'],
    'release_gate' => 'The production Registry is valid but empty. An authorized signed release is still required for production end-to-end verification.',
];
