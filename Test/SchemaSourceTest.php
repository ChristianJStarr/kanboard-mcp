<?php

require_once __DIR__ . '/McpTestCase.php';

use Kanboard\Core\Plugin\SchemaHandler;
use Kanboard\Plugin\ModelContextProtocol\Model\McpTokenModel;

class SchemaSourceTest extends McpTestCase
{
    public function testVersionConstantIs3AndGrantsTableExistsInEveryDriver(): void
    {
        $insertSql = [
            'Sqlite.php' => 'INSERT OR IGNORE INTO mcp_endpoint_grants',
            'Mysql.php' => 'INSERT IGNORE INTO mcp_endpoint_grants',
            'Postgres.php' => 'ON CONFLICT (user_id, endpoint) DO NOTHING',
        ];

        foreach ($insertSql as $filename => $sql) {
            $source = file_get_contents(dirname(__DIR__) . '/Schema/' . $filename);
            $this->assertIsString($source);
            $this->assertStringContainsString('const VERSION = 3;', $source);
            $this->assertStringContainsString('function version_3', $source);
            $this->assertStringContainsString('mcp_endpoint_grants', $source);
            $this->assertStringContainsString('McpCatalog.php', $source);
            $this->assertStringContainsString(':user_id', $source);
            $this->assertStringContainsString(':endpoint', $source);
            $this->assertStringContainsString($sql, $source);
            $this->assertStringNotContainsString('FOREIGN KEY', $source);
            $this->assertStringNotContainsString('REFERENCES', $source);
        }
    }

    public function testLoadSchemaReachesVersion3AndExistingTokenStillAuthenticates(): void
    {
        $this->assertSame(3, \Kanboard\Plugin\ModelContextProtocol\Schema\VERSION);
        $this->assertTrue(function_exists('\Kanboard\Plugin\ModelContextProtocol\Schema\version_3'));

        $schema = new SchemaHandler($this->container);
        $this->assertSame(3, $schema->getSchemaVersion('ModelContextProtocol'));

        $userId = $this->createUser('schema-auth-user');
        $plain = $this->container['mcpTokenModel']->regenerateForUser($userId);
        $hash = $this->container['db']->table(McpTokenModel::TABLE)->eq('user_id', $userId)->findOneColumn('token');

        $schema->loadSchema('ModelContextProtocol');

        $this->assertSame(3, $schema->getSchemaVersion('ModelContextProtocol'));
        $this->assertSame(
            $hash,
            $this->container['db']->table(McpTokenModel::TABLE)->eq('user_id', $userId)->findOneColumn('token')
        );
        $this->assertSame($userId, $this->container['mcpTokenModel']->authenticate($plain));
    }
}
