<?php

namespace Kanboard\Plugin\ModelContextProtocol\Schema;

const VERSION = 2;

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