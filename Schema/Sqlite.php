<?php

namespace Kanboard\Plugin\ModelContextProtocol\Schema;

const VERSION = 3;

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
    // A failed earlier run can leave the old table missing. MySQL commits
    // DROP TABLE immediately; this check keeps the other drivers safe too.
    if (!mcp_sqlite_table_exists($pdo, 'mcp_tokens')) {
        mcp_sqlite_create_tokens($pdo);
        return;
    }

    // Already on the per-user table. Do not delete those tokens.
    if (mcp_sqlite_column_exists($pdo, 'mcp_tokens', 'user_id')) {
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mcp_token ON mcp_tokens (token)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mcp_user  ON mcp_tokens (user_id)');
        return;
    }

    // Drop legacy global tokens; they are not tied to any user.
    $pdo->exec('DELETE FROM mcp_tokens');
    if (mcp_sqlite_table_exists($pdo, 'mcp_tokens_new')) {
        $pdo->exec('DROP TABLE mcp_tokens_new');
    }

    $pdo->exec('
        CREATE TABLE mcp_tokens_new (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id     INTEGER NOT NULL UNIQUE,
            token       TEXT    NOT NULL UNIQUE,
            created_at  INTEGER NOT NULL
        )
    ');
    $pdo->exec('DROP TABLE mcp_tokens');
    $pdo->exec('ALTER TABLE mcp_tokens_new RENAME TO mcp_tokens');

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mcp_token ON mcp_tokens (token)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mcp_user  ON mcp_tokens (user_id)');
}

function version_3($pdo)
{
    // Installations that already applied the previous version_2 still have
    // token_plain. Copy the hash rows and drop that column.
    if (!mcp_sqlite_table_exists($pdo, 'mcp_tokens')) {
        mcp_sqlite_create_tokens($pdo);
        return;
    }

    if (!mcp_sqlite_column_exists($pdo, 'mcp_tokens', 'token_plain')) {
        return;
    }

    if (mcp_sqlite_table_exists($pdo, 'mcp_tokens_v3')) {
        $pdo->exec('DROP TABLE mcp_tokens_v3');
    }

    $pdo->exec('
        CREATE TABLE mcp_tokens_v3 (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id     INTEGER NOT NULL UNIQUE,
            token       TEXT    NOT NULL UNIQUE,
            created_at  INTEGER NOT NULL
        )
    ');
    $pdo->exec('
        INSERT INTO mcp_tokens_v3 (id, user_id, token, created_at)
        SELECT id, user_id, token, created_at FROM mcp_tokens
    ');
    $pdo->exec('DROP TABLE mcp_tokens');
    $pdo->exec('ALTER TABLE mcp_tokens_v3 RENAME TO mcp_tokens');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mcp_token ON mcp_tokens (token)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mcp_user  ON mcp_tokens (user_id)');
}

function mcp_sqlite_create_tokens($pdo)
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS mcp_tokens (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id     INTEGER NOT NULL UNIQUE,
            token       TEXT    NOT NULL UNIQUE,
            created_at  INTEGER NOT NULL
        )
    ');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mcp_token ON mcp_tokens (token)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mcp_user  ON mcp_tokens (user_id)');
}

function mcp_sqlite_table_exists($pdo, $table)
{
    $statement = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
    $statement->execute([$table]);

    return (bool) $statement->fetchColumn();
}

function mcp_sqlite_column_exists($pdo, $table, $column)
{
    $statement = $pdo->query('PRAGMA table_info(' . $table . ')');
    if ($statement === false) {
        return false;
    }

    foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        if (($row['name'] ?? '') === $column) {
            return true;
        }
    }

    return false;
}
