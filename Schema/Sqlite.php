<?php

namespace Kanboard\Plugin\ModelContextProtocol\Schema;

const VERSION = 2;

function version_1($pdo)
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS mcp_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token TEXT NOT NULL UNIQUE,
            is_active INTEGER DEFAULT 1,
            created_at INTEGER NOT NULL
        )
    ');

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mcp_token ON mcp_tokens (token)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mcp_active ON mcp_tokens (is_active)');
}

function version_2($pdo)
{
    // Drop legacy global tokens; they are not tied to any user
    $pdo->exec('DELETE FROM mcp_tokens');

    // SQLite cannot DROP COLUMN before 3.35, so recreate the table.
    // token       = SHA-256 hash used for authentication
    // token_plain = raw token shown once on the integrations page
    $pdo->exec('
        CREATE TABLE mcp_tokens_new (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id     INTEGER NOT NULL UNIQUE,
            token       TEXT    NOT NULL UNIQUE,
            token_plain TEXT    NOT NULL,
            created_at  INTEGER NOT NULL
        )
    ');
    $pdo->exec('DROP TABLE mcp_tokens');
    $pdo->exec('ALTER TABLE mcp_tokens_new RENAME TO mcp_tokens');

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mcp_token ON mcp_tokens (token)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mcp_user  ON mcp_tokens (user_id)');
}
