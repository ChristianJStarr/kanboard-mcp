<?php

require_once __DIR__ . '/McpTestCase.php';

class SchemaSourceTest extends McpTestCase
{
    public function testVersionConstantIs2AndVersion3IsAbsent(): void
    {
        foreach (['Sqlite.php', 'Mysql.php', 'Postgres.php'] as $filename) {
            $source = file_get_contents(dirname(__DIR__) . '/Schema/' . $filename);
            $this->assertIsString($source);
            $this->assertStringContainsString('const VERSION = 2;', $source);
            $this->assertStringNotContainsString('function version_3', $source);
        }
    }
}
