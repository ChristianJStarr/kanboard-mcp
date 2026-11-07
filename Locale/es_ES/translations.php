<?php

return [
    'Model Context Protocol (MCP)'                                                                                       => 'Model Context Protocol (MCP)',
    'Model Context Protocol'                                                                                             => 'Model Context Protocol',
    'Control Kanboard via Model Context Protocol. Each user has their own token that grants only their own board access.' => 'Controla Kanboard vía Model Context Protocol. Cada usuario tiene su propio token que otorga acceso solo a sus tableros.',
    'Provides MCP server functionality for full Kanboard control via Model Context Protocol'                             => 'Proporciona funcionalidad de servidor MCP para el control completo de Kanboard mediante Model Context Protocol',

    // Panel de token
    'MCP Client Configuration'                                                                                           => 'Configuración del cliente MCP',
    'Copy this configuration to your MCP client settings (e.g., Claude Desktop app config). The server URL and authentication token are included.' => 'Copia esta configuración en tu cliente MCP (p. ej., configuración de Claude Desktop). Se incluyen la URL del servidor y el token de autenticación.',
    'Disable token in URL (query string)'                                                                                => 'Desactivar el token en la URL (cadena de consulta)',
    'When this is checked, MCP clients must send the token in the Authorization header. A token in the URL can be written to web server and proxy access logs.' => 'Si está marcado, los clientes MCP deben enviar el token en la cabecera Authorization. Un token en la URL puede quedar escrito en los registros de acceso del servidor web y de los proxies.',
    'Save URL token setting'                                                                                             => 'Guardar ajuste del token en URL',
    'MCP URL token setting saved'                                                                                        => 'Ajuste del token MCP en URL guardado',
    'Only administrators can change this instance setting.'                                                              => 'Solo los administradores pueden cambiar este ajuste de la instancia.',
    'Recommended: Authorization header'                                                                                  => 'Recomendado: cabecera Authorization',
    'Copy this configuration into your MCP client. The token is sent as Authorization: Bearer and is not placed in the URL.' => 'Copia esta configuración en tu cliente MCP. El token se envía como Authorization: Bearer y no se coloca en la URL.',
    'Compatibility: token in the URL'                                                                                    => 'Compatibilidad: token en la URL',
    'This method puts the token in the query string. Web servers and reverse proxies often write the full URL, including the token, to access logs.' => 'Este método pone el token en la cadena de consulta. Los servidores web y los proxies inversos suelen escribir la URL completa, incluido el token, en los registros de acceso.',
    'This URL will not authenticate while token-in-URL is disabled. Use the Authorization header configuration, or uncheck the setting above.' => 'Esta URL no autenticará mientras el token en la URL esté desactivado. Usa la configuración con cabecera Authorization, o desmarca el ajuste de arriba.',
    'Use this only with MCP clients that cannot send an Authorization header.'                                           => 'Úsalo solo con clientes MCP que no puedan enviar una cabecera Authorization.',
    'Security Note:'                                                                                                     => 'Nota de seguridad:',
    'This URL provides full access to your own Kanboard boards. Keep it secure and do not share it publicly.'            => 'Esta URL proporciona acceso completo a tus tableros de Kanboard. Mantenla segura y no la compartas públicamente.',
    'This token provides full access to your own Kanboard boards. Keep it secure and do not share it publicly.'          => 'Este token proporciona acceso completo a tus tableros de Kanboard. Mantenlo seguro y no lo compartas públicamente.',
    'Autogenerar'                                                                                                        => 'Autogenerar',
    'Generate Token'                                                                                                     => 'Generar token',
    'This will invalidate the current token. Are you sure?'                                                              => 'Esto invalidará el token actual. ¿Estás seguro?',
    'No MCP token has been generated yet. Generate a token to enable MCP access.'                                        => 'Aún no se ha generado ningún token MCP. Genera un token para habilitar el acceso MCP.',
    'Copy this token now. It is shown only once.'                                                                        => 'Copia este token ahora. Solo se muestra una vez.',
    'An MCP token already exists for your account. Generate a new one to replace it. The current token is not shown again.' => 'Ya existe un token MCP para tu cuenta. Genera uno nuevo para reemplazarlo. El token actual no se vuelve a mostrar.',
    'New MCP token generated successfully'                                                                               => 'Nuevo token MCP generado correctamente',
    'Unable to generate MCP token'                                                                                       => 'No se pudo generar el token MCP',
];
