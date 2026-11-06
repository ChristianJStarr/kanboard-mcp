<?php

namespace Kanboard\Plugin\ModelContextProtocol\Schema;

const VERSION = 2;

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

function version_2($pdo)
{
    // Drop legacy global tokens; they are not tied to any user
    $pdo->exec('DELETE FROM mcp_tokens');

    // Replace the table with the per-user schema.
    // token       = SHA-256 hex hash used for authentication
    // token_plain = raw token shown once on the integrations page
    $pdo->exec('DROP TABLE mcp_tokens');
    $pdo->exec('
        CREATE TABLE mcp_tokens (
            id          SERIAL  PRIMARY KEY,
            user_id     INTEGER NOT NULL,
            token       CHAR(64) NOT NULL,
            token_plain CHAR(64) NOT NULL,
            created_at  INTEGER NOT NULL,
            CONSTRAINT uq_mcp_user  UNIQUE (user_id),
            CONSTRAINT uq_mcp_token UNIQUE (token)
        )
    ');

    $pdo->exec('CREATE INDEX idx_mcp_token ON mcp_tokens (token)');
    $pdo->exec('CREATE INDEX idx_mcp_user  ON mcp_tokens (user_id)');
}
