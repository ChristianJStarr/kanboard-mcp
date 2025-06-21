<?php

namespace Kanboard\Plugin\ModelContextProtocol\Schema;

const VERSION = 1;

function version_1($pdo)
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS mcp_tokens (
            id INT NOT NULL AUTO_INCREMENT,
            token VARCHAR(255) NOT NULL UNIQUE,
            is_active TINYINT(1) DEFAULT 1,
            created_at INT NOT NULL,
            PRIMARY KEY(id),
            KEY idx_token (token),
            KEY idx_active (is_active)
        ) ENGINE=InnoDB CHARSET=utf8
    ');
} 