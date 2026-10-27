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
        
        // Only the MCP endpoint itself is public (it authenticates with the MCP
        // token). Token management stays admin-only; anything else falls back
        // to Kanboard's default role.
        $this->applicationAccessMap->add('ModelContextProtocolController', 'handle', Role::APP_PUBLIC);
        $this->applicationAccessMap->add('ModelContextProtocolController', array('settings', 'generateToken'), Role::APP_ADMIN);
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