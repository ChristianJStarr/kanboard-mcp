<?php

require_once __DIR__ . '/McpTestCase.php';

class SchemaSourceTest extends McpTestCase
{
    public function testVersionConstantIs3AndPlaintextColumnIsRemoved(): void
    {
        foreach (['Sqlite.php', 'Mysql.php', 'Postgres.php'] as $filename) {
            $source = file_get_contents(dirname(__DIR__) . '/Schema/' . $filename);
            $this->assertIsString($source);
            $this->assertStringContainsString('const VERSION = 3;', $source);
            $this->assertStringContainsString('function version_2', $source);
            $this->assertStringContainsString('function version_3', $source);
            $this->assertStringContainsString('token_plain', $source);
            $this->assertStringNotContainsString('token_plain TEXT', $source);
            $this->assertStringNotContainsString('token_plain VARCHAR', $source);
            $this->assertStringNotContainsString('token_plain CHAR', $source);
        }

        $mysql = file_get_contents(dirname(__DIR__) . '/Schema/Mysql.php');
        $this->assertStringContainsString('information_schema.TABLES', $mysql);
        $this->assertStringContainsString('DROP COLUMN token_plain', $mysql);
    }
}
