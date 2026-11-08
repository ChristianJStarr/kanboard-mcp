<?php

namespace Kanboard\Plugin\ModelContextProtocol;

use Kanboard\Core\Plugin\Base;
use Kanboard\Core\Translator;
use Kanboard\Core\Security\Role;

/**
 * Model Context Protocol Plugin
 *
 * @package  Kanboard\Plugin\ModelContextProtocol
 */
class Plugin extends Base
{
    public function initialize()
    {
        // Attach the MCP panel to Settings → Integrations (admin view)
        $this->template->hook->attach(
            'template:config:integrations',
            'ModelContextProtocol:config/integration'
        );

        // Attach the same panel to the per-user integrations page so that
        // regular (non-admin) users can reach and rotate their own token
        $this->template->hook->attach(
            'template:user:integrations',
            'ModelContextProtocol:config/integration'
        );

        // Outside the settings/profile <form>. The partial sets a request
        // flag; this template no-ops on every other page.
        $this->template->hook->attach(
            'template:layout:bottom',
            'ModelContextProtocol:config/forms'
        );

        // External file: Kanboard's CSP is default-src 'self', so an inline
        // onchange handler is ignored and the checkbox never posts.
        $this->hook->on('template:layout:js', [
            'template' => 'plugins/ModelContextProtocol/Assets/js/url-token-setting.js',
        ]);

        // handle: public endpoint – no session required, token-authenticated
        $this->applicationAccessMap->add(
            'ModelContextProtocolController',
            'handle',
            Role::APP_PUBLIC
        );

        // generateToken: any authenticated user may rotate their own token
        $this->applicationAccessMap->add(
            'ModelContextProtocolController',
            'generateToken',
            Role::APP_USER
        );

        // saveUrlTokenSetting: instance-wide query-string policy, admins only
        $this->applicationAccessMap->add(
            'ModelContextProtocolController',
            'saveUrlTokenSetting',
            Role::APP_ADMIN
        );

        // saveGateway: the session user may toggle only their own endpoints
        $this->applicationAccessMap->add(
            'ModelContextProtocolController',
            'saveGateway',
            Role::APP_USER
        );
    }

    public function onStartup()
    {
        Translator::load($this->languageModel->getCurrentLanguage(), __DIR__ . '/Locale');
    }

    public function getClasses()
    {
        return [
            'Plugin\ModelContextProtocol\Controller' => [
                'ModelContextProtocolController',
            ],
            'Plugin\ModelContextProtocol\Model' => [
                'McpTokenModel',
                'McpEndpointGatewayModel',
            ],
        ];
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
        return '1.2.3';
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
