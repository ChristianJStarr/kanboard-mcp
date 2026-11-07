<?php

return [
    'Model Context Protocol (MCP)'                                                                                       => 'Model Context Protocol (MCP)',
    'Model Context Protocol'                                                                                             => 'Model Context Protocol',
    'Control Kanboard via Model Context Protocol. Each user has their own token that grants only their own board access.' => 'Control Kanboard via Model Context Protocol. Each user has their own token that grants only their own board access.',
    'Provides MCP server functionality for full Kanboard control via Model Context Protocol'                             => 'Provides MCP server functionality for full Kanboard control via Model Context Protocol',

    // Token panel
    'MCP Client Configuration'                                                                                           => 'MCP Client Configuration',
    'Copy this configuration to your MCP client settings (e.g., Claude Desktop app config). The server URL and authentication token are included.' => 'Copy this configuration to your MCP client settings (e.g., Claude Desktop app config). The server URL and authentication token are included.',
    'Disable token in URL (query string)'                                                                                => 'Disable token in URL (query string)',
    'When this is checked, MCP clients must send the token in the Authorization header. A token in the URL can be written to web server and proxy access logs.' => 'When this is checked, MCP clients must send the token in the Authorization header. A token in the URL can be written to web server and proxy access logs.',
    'Save URL token setting'                                                                                             => 'Save URL token setting',
    'MCP URL token setting saved'                                                                                        => 'MCP URL token setting saved',
    'Only administrators can change this instance setting.'                                                              => 'Only administrators can change this instance setting.',
    'Recommended: Authorization header'                                                                                  => 'Recommended: Authorization header',
    'Copy this configuration into your MCP client. The token is sent as Authorization: Bearer and is not placed in the URL.' => 'Copy this configuration into your MCP client. The token is sent as Authorization: Bearer and is not placed in the URL.',
    'Compatibility: token in the URL'                                                                                    => 'Compatibility: token in the URL',
    'This method puts the token in the query string. Web servers and reverse proxies often write the full URL, including the token, to access logs.' => 'This method puts the token in the query string. Web servers and reverse proxies often write the full URL, including the token, to access logs.',
    'This URL will not authenticate while token-in-URL is disabled. Use the Authorization header configuration, or uncheck the setting above.' => 'This URL will not authenticate while token-in-URL is disabled. Use the Authorization header configuration, or uncheck the setting above.',
    'Use this only with MCP clients that cannot send an Authorization header.'                                           => 'Use this only with MCP clients that cannot send an Authorization header.',
    'Security Note:'                                                                                                     => 'Security Note:',
    'This URL provides full access to your own Kanboard boards. Keep it secure and do not share it publicly.'            => 'This URL provides full access to your own Kanboard boards. Keep it secure and do not share it publicly.',
    'This token provides full access to your own Kanboard boards. Keep it secure and do not share it publicly.'          => 'This token provides full access to your own Kanboard boards. Keep it secure and do not share it publicly.',
    'Autogenerar'                                                                                                        => 'Regenerate Token',
    'Generate Token'                                                                                                     => 'Generate Token',
    'This will invalidate the current token. Are you sure?'                                                              => 'This will invalidate the current token. Are you sure?',
    'No MCP token has been generated yet. Generate a token to enable MCP access.'                                        => 'No MCP token has been generated yet. Generate a token to enable MCP access.',
    'Copy this token now. It is shown only once.'                                                                        => 'Copy this token now. It is shown only once.',
    'An MCP token already exists for your account. Generate a new one to replace it. The current token is not shown again.' => 'An MCP token already exists for your account. Generate a new one to replace it. The current token is not shown again.',
    'New MCP token generated successfully'                                                                               => 'New MCP token generated successfully',
    'Unable to generate MCP token'                                                                                       => 'Unable to generate MCP token',
];
