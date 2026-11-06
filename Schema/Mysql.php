<?php

namespace Kanboard\Plugin\ModelContextProtocol\Schema;

require_once __DIR__ . '/../Core/McpCatalog.php';

const VERSION = 3;

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
            id          INT          NOT NULL AUTO_INCREMENT,
            user_id     INT          NOT NULL,
            token       VARCHAR(64)  NOT NULL,
            token_plain VARCHAR(64)  NOT NULL,
            created_at  INT          NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_mcp_user  (user_id),
            UNIQUE KEY idx_mcp_token (token)
        ) ENGINE=InnoDB CHARSET=utf8
    ');
}

function version_3($pdo)
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS mcp_endpoint_grants (
            user_id  INT         NOT NULL,
            endpoint VARCHAR(64) NOT NULL,
            enabled  TINYINT     NOT NULL,
            PRIMARY KEY (user_id, endpoint),
            KEY idx_mcp_endpoint_grants_user (user_id)
        ) ENGINE=InnoDB CHARSET=utf8
    ');

    $insert = $pdo->prepare(
        'INSERT IGNORE INTO mcp_endpoint_grants (user_id, endpoint, enabled) VALUES (:user_id, :endpoint, 1)'
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
