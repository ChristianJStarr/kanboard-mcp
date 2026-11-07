<?php

namespace Kanboard\Plugin\ModelContextProtocol\Schema;

require_once __DIR__ . '/../Core/McpCatalog.php';

const VERSION = 4;

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
    if (!mcp_postgres_table_exists($pdo, 'mcp_tokens')) {
        mcp_postgres_create_tokens($pdo);
        return;
    }

    if (mcp_postgres_column_exists($pdo, 'mcp_tokens', 'user_id')) {
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mcp_token ON mcp_tokens (token)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mcp_user  ON mcp_tokens (user_id)');
        return;
    }

    // Drop legacy global tokens; they are not tied to any user.
    $pdo->exec('DELETE FROM mcp_tokens');
    $pdo->exec('DROP TABLE mcp_tokens');
    mcp_postgres_create_tokens($pdo);
}

function version_3($pdo)
{
    if (!mcp_postgres_table_exists($pdo, 'mcp_tokens')) {
        mcp_postgres_create_tokens($pdo);
        return;
    }

    if (!mcp_postgres_column_exists($pdo, 'mcp_tokens', 'token_plain')) {
        return;
    }

    $pdo->exec('ALTER TABLE mcp_tokens DROP COLUMN token_plain');
}

function version_4($pdo)
{
    version_3($pdo);

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS mcp_endpoint_grants (
            user_id  INTEGER     NOT NULL,
            endpoint VARCHAR(64) NOT NULL,
            enabled  SMALLINT    NOT NULL,
            PRIMARY KEY (user_id, endpoint)
        )
    ');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mcp_endpoint_grants_user ON mcp_endpoint_grants (user_id)');

    $insert = $pdo->prepare(
        'INSERT INTO mcp_endpoint_grants (user_id, endpoint, enabled) VALUES (:user_id, :endpoint, 1) ON CONFLICT (user_id, endpoint) DO NOTHING'
    );
    mcp_backfill_write_grants($pdo, $insert);
}

/**
 * Enable every write-class catalog endpoint for users who already have a token.
 * Names come from the catalog constant. user_id and endpoint are bound parameters.
 */
function mcp_backfill_write_grants($pdo, $insert)
{
    $users = $pdo->query('SELECT user_id FROM mcp_tokens');
    if ($users === false) {
        return;
    }

    foreach ($users->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        foreach (\Kanboard\Plugin\ModelContextProtocol\Core\McpCatalog::ENDPOINTS as $endpoint) {
            if ($endpoint['defaultEnabled'] === true) {
                continue;
            }
            $insert->execute([
                ':user_id' => (int) $row['user_id'],
                ':endpoint' => $endpoint['name'],
            ]);
        }
    }
}

function mcp_postgres_create_tokens($pdo)
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS mcp_tokens (
            id          SERIAL  PRIMARY KEY,
            user_id     INTEGER NOT NULL,
            token       CHAR(64) NOT NULL,
            created_at  INTEGER NOT NULL,
            CONSTRAINT uq_mcp_user  UNIQUE (user_id),
            CONSTRAINT uq_mcp_token UNIQUE (token)
        )
    ');

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mcp_token ON mcp_tokens (token)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mcp_user  ON mcp_tokens (user_id)');
}

function mcp_postgres_table_exists($pdo, $table)
{
    $statement = $pdo->prepare('SELECT to_regclass(?)');
    $statement->execute(['public.' . $table]);
    $name = $statement->fetchColumn();

    return $name !== null && $name !== false && $name !== '';
}

function mcp_postgres_column_exists($pdo, $table, $column)
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?'
    );
    $statement->execute([$table, $column]);

    return (int) $statement->fetchColumn() > 0;
}
