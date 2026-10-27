<?php

namespace Kanboard\Plugin\ModelContextProtocol\Schema;

const VERSION = 3;

function version_3($pdo)
{
    // Optional Kanboard user the token acts as (0 = unrestricted).
    $pdo->exec('ALTER TABLE mcp_tokens ADD COLUMN user_id INTEGER DEFAULT 0');
}

function version_2($pdo)
{
    // Store only a SHA-256 hash of each token from now on.
    $rq = $pdo->prepare('SELECT id, token FROM mcp_tokens');
    $rq->execute();
    $update = $pdo->prepare('UPDATE mcp_tokens SET token=? WHERE id=?');

    foreach ($rq->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $update->execute(array(hash('sha256', $row['token']), $row['id']));
    }
}

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