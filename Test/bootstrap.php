<?php

/**
 * PHPUnit bootstrap for the ModelContextProtocol plugin.
 *
 * Resolves a Kanboard checkout, then loads tests/units/Base.php:
 *   1. KANBOARD_ROOT environment variable
 *   2. a sibling directory named "kanboard"
 *   3. the documented path /home/alejivo/Documents/GitRepos/kanboard
 *
 * Kanboard core and composer install are required. This plugin does not
 * vendor Kanboard. From a Kanboard 1.2.45 tree, with this plugin available
 * as plugins/ModelContextProtocol:
 *
 *   vendor/bin/phpunit -c plugins/ModelContextProtocol/phpunit.xml.dist
 *
 * When this checkout is not already plugins/ModelContextProtocol, PLUGINS_DIR
 * is pointed at a temporary directory that symlinks this tree under that name
 * so SchemaHandler and the plugin PSR-4 prefix resolve.
 */

$pluginRoot = dirname(__DIR__);

$candidates = [];
$fromEnv = getenv('KANBOARD_ROOT');
if (is_string($fromEnv) && $fromEnv !== '') {
    $candidates[] = $fromEnv;
}
$candidates[] = dirname($pluginRoot) . DIRECTORY_SEPARATOR . 'kanboard';
$candidates[] = '/home/alejivo/Documents/GitRepos/kanboard';

$kanboardRoot = '';
foreach ($candidates as $candidate) {
    $base = rtrim($candidate, DIRECTORY_SEPARATOR) . '/tests/units/Base.php';
    $autoload = rtrim($candidate, DIRECTORY_SEPARATOR) . '/vendor/autoload.php';
    if (is_file($base) && is_file($autoload)) {
        $kanboardRoot = rtrim($candidate, DIRECTORY_SEPARATOR);
        break;
    }
}

if ($kanboardRoot === '') {
    fwrite(STDERR, "Kanboard core and composer install are required.\n");
    fwrite(STDERR, "Set KANBOARD_ROOT to a Kanboard 1.2.x checkout that already has vendor/ installed.\n");
    fwrite(STDERR, "Looked for tests/units/Base.php and vendor/autoload.php in:\n");
    foreach ($candidates as $candidate) {
        fwrite(STDERR, "  - {$candidate}\n");
    }
    exit(1);
}

if (!defined('PLUGINS_DIR')) {
    $installed = $kanboardRoot . '/plugins/ModelContextProtocol';
    $pluginReal = realpath($pluginRoot);
    $installedReal = is_dir($installed) ? realpath($installed) : false;

    if ($pluginReal === false || $installedReal === false || $pluginReal !== $installedReal) {
        $stub = sys_get_temp_dir() . '/kanboard-mcp-phpunit-plugins';
        if (!is_dir($stub) && !mkdir($stub, 0777, true) && !is_dir($stub)) {
            fwrite(STDERR, "Kanboard core and composer install are required.\n");
            fwrite(STDERR, "Could not create a PLUGINS_DIR stub for ModelContextProtocol.\n");
            exit(1);
        }

        $link = $stub . '/ModelContextProtocol';
        if ((is_link($link) || file_exists($link)) && realpath($link) !== $pluginReal) {
            if (is_link($link)) {
                unlink($link);
            }
        }
        if (!file_exists($link) && !symlink($pluginRoot, $link)) {
            fwrite(STDERR, "Kanboard core and composer install are required.\n");
            fwrite(STDERR, "Could not symlink this plugin as ModelContextProtocol.\n");
            exit(1);
        }

        define('PLUGINS_DIR', $stub);
    }
}

require $kanboardRoot . '/tests/units/Base.php';
