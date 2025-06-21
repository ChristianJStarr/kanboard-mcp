<?php

namespace Kanboard\Plugin\ModelContextProtocol\Schema;

const VERSION = 1;

function version_1($pdo)
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS mcp_tokens (
            id SERIAL PRIMARY KEY,
            token VARCHAR(255) NOT NULL UNIQUE,
            is_active BOOLEAN DEFAULT TRUE,
            created_at INTEGER NOT NULL
        )
    ');
    
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mcp_token ON mcp_tokens (token)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mcp_active ON mcp_tokens (is_active)');
} 