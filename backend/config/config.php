<?php

return [
    'db' => [
        'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
        'port' => $_ENV['DB_PORT'] ?? '3306',
        'name' => $_ENV['DB_NAME'] ?? 'order_system',
        'user' => $_ENV['DB_USER'] ?? 'root',
        'pass' => $_ENV['DB_PASS'] ?? '',
        'charset' => 'utf8mb4',
    ],
    'state_machine' => [
        'strict_validation' => $_ENV['STATE_MACHINE_STRICT_VALIDATION'] ?? true,
        'allow_force_transition' => $_ENV['STATE_MACHINE_ALLOW_FORCE_TRANSITION'] ?? false,
        'transition_log_enabled' => $_ENV['STATE_MACHINE_TRANSITION_LOG_ENABLED'] ?? true,
        'rollback_enabled' => $_ENV['STATE_MACHINE_ROLLBACK_ENABLED'] ?? true,
        'max_rollback_depth' => $_ENV['STATE_MACHINE_MAX_ROLLBACK_DEPTH'] ?? 3,
    ],
    'security' => [
        'operation_password_required' => $_ENV['OPERATION_PASSWORD_REQUIRED'] ?? false,
        'ip_whitelist_enabled' => $_ENV['IP_WHITELIST_ENABLED'] ?? false,
    ],
];
