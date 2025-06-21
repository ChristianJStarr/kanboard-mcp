<?php

namespace Kanboard\Plugin\ModelContextProtocol;

use Kanboard\Core\Plugin\Base;
use Kanboard\Core\Translator;
use Kanboard\Core\Security\Role;

/**
 * Model Context Protocol Plugin
 *
 * @package  Kanboard\Plugin\ModelContextProtocol
 * @author   Plugin Author
 */
class Plugin extends Base
{
    public function initialize()
    {
        $this->template->hook->attach('template:config:integrations', 'ModelContextProtocol:config/integration');
        
        // Allow public access to MCP controller (bypass authentication for API)
        $this->applicationAccessMap->add('ModelContextProtocolController', '*', Role::APP_PUBLIC);
        
        // But require admin access for token generation
        $this->applicationAccessMap->add('ModelContextProtocolController', 'generateToken', Role::APP_ADMIN);
    }

    public function onStartup()
    {
        Translator::load($this->languageModel->getCurrentLanguage(), __DIR__.'/Locale');
    }

    public function getClasses()
    {
        return array(
            'Plugin\ModelContextProtocol\Controller' => array(
                'ModelContextProtocolController',
            ),
            'Plugin\ModelContextProtocol\Model' => array(
                'McpTokenModel',
            )
        );
    }

    public function getPluginName()
    {
        return 'ModelContextProtocol';
    }

    public function getPluginDescription()
    {
        return t('Provides MCP server functionality for full Kanboard control via Model Context Protocol');
    }

    public function getPluginAuthor()
    {
        return 'Christian Starr';
    }

    public function getPluginVersion()
    {
        return '1.0.0';
    }

    public function getPluginHomepage()
    {
        return 'https://github.com/ChristianJStarr/kanboard-mcp';
    }

    public function getCompatibleVersion()
    {
        return '>=1.2.0';
    }
}