<?php

require_once __DIR__ . '/McpTestCase.php';

use Kanboard\Core\Plugin\SchemaHandler;
use Kanboard\Plugin\ModelContextProtocol\Model\McpTokenModel;

class SchemaSourceTest extends McpTestCase
{
    public function testVersionConstantIs4AndGrantsFollowTheTokenCleanup(): void
    {
        $insertSql = [
            'Sqlite.php' => 'INSERT OR IGNORE INTO mcp_endpoint_grants',
            'Mysql.php' => 'INSERT IGNORE INTO mcp_endpoint_grants',
            'Postgres.php' => 'ON CONFLICT (user_id, endpoint) DO NOTHING',
        ];

        foreach ($insertSql as $filename => $sql) {
            $source = file_get_contents(dirname(__DIR__) . '/Schema/' . $filename);
            $this->assertIsString($source);
            $this->assertStringContainsString('const VERSION = 6;', $source);
            $this->assertStringContainsString('function version_3', $source);
            $this->assertStringContainsString('function version_4', $source);
            $this->assertStringContainsString('function version_5', $source);
            $this->assertStringContainsString('function version_6', $source);
            $this->assertStringContainsString('expires_at', $source);
            $this->assertStringContainsString('mcp_endpoint_grants', $source);
            $this->assertStringContainsString('McpCatalog.php', $source);
            $this->assertStringContainsString(':user_id', $source);
            $this->assertStringContainsString(':endpoint', $source);
            $this->assertStringContainsString($sql, $source);
            $this->assertStringContainsString('token_plain', $source);
            $this->assertStringNotContainsString('token_plain TEXT', $source);
            $this->assertStringNotContainsString('token_plain VARCHAR', $source);
            $this->assertStringNotContainsString('token_plain CHAR', $source);
            $this->assertStringNotContainsString('FOREIGN KEY', $source);
            $this->assertStringNotContainsString('REFERENCES', $source);
        }

        $mysql = file_get_contents(dirname(__DIR__) . '/Schema/Mysql.php');
        $this->assertStringContainsString('information_schema.TABLES', $mysql);
        $this->assertStringContainsString('DROP COLUMN token_plain', $mysql);
    }

    public function testLoadSchemaReachesVersion4AndExistingTokenStillAuthenticates(): void
    {
        $this->assertSame(6, \Kanboard\Plugin\ModelContextProtocol\Schema\VERSION);
        $this->assertTrue(function_exists('\Kanboard\Plugin\ModelContextProtocol\Schema\version_6'));

        $schema = new SchemaHandler($this->container);
        $this->assertSame(6, $schema->getSchemaVersion('ModelContextProtocol'));

        $userId = $this->createUser('schema-auth-user');
        $plain = $this->container['mcpTokenModel']->regenerateForUser($userId);
        $hash = $this->container['db']->table(McpTokenModel::TABLE)->eq('user_id', $userId)->findOneColumn('token');

        $schema->loadSchema('ModelContextProtocol');

        $this->assertSame(6, $schema->getSchemaVersion('ModelContextProtocol'));
        $this->assertSame(
            $hash,
            $this->container['db']->table(McpTokenModel::TABLE)->eq('user_id', $userId)->findOneColumn('token')
        );
        $this->assertSame($userId, $this->container['mcpTokenModel']->authenticate($plain));
    }
}
