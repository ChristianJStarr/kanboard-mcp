<?php

namespace Kanboard\Plugin\ModelContextProtocol\Schema;

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
    // MySQL commits DROP TABLE immediately. If CREATE then fails, the schema
    // version stays at 1 and the next boot must be able to create the table.
    if (!mcp_mysql_table_exists($pdo, 'mcp_tokens')) {
        mcp_mysql_create_tokens($pdo);
        return;
    }

    // The per-user table is already in place. Its UNIQUE keys are the indexes.
    if (mcp_mysql_column_exists($pdo, 'mcp_tokens', 'user_id')) {
        return;
    }

    // Drop legacy global tokens; they are not tied to any user.
    $pdo->exec('DELETE FROM mcp_tokens');
    $pdo->exec('DROP TABLE mcp_tokens');
    mcp_mysql_create_tokens($pdo);
}

function version_3($pdo)
{
    if (!mcp_mysql_table_exists($pdo, 'mcp_tokens')) {
        mcp_mysql_create_tokens($pdo);
        return;
    }

    if (!mcp_mysql_column_exists($pdo, 'mcp_tokens', 'token_plain')) {
        return;
    }

    $pdo->exec('ALTER TABLE mcp_tokens DROP COLUMN token_plain');
}

function mcp_mysql_create_tokens($pdo)
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS mcp_tokens (
            id          INT          NOT NULL AUTO_INCREMENT,
            user_id     INT          NOT NULL,
            token       VARCHAR(64)  NOT NULL,
            created_at  INT          NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_mcp_user  (user_id),
            UNIQUE KEY idx_mcp_token (token)
        ) ENGINE=InnoDB CHARSET=utf8
    ');
}

function mcp_mysql_table_exists($pdo, $table)
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $statement->execute([$table]);

    return (int) $statement->fetchColumn() > 0;
}

function mcp_mysql_column_exists($pdo, $table, $column)
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $statement->execute([$table, $column]);

    return (int) $statement->fetchColumn() > 0;
}
