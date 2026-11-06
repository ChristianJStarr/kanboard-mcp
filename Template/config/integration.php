<div class="panel" id="mcp-token-panel">
    <h3><i class="fa fa-plug fa-fw"></i>&nbsp;<?= t('Model Context Protocol (MCP)') ?></h3>
    <p><?= t('Control Kanboard via Model Context Protocol. Each user has their own token that grants only their own board access.') ?></p>

    <?php
    // Always show the token of the session user, never the profile user.
    // The real <form> elements are rendered from template:layout:bottom.
    // Controls here only point at them, so Kanboard's Save button does not
    // submit them and saving access does not rotate the token.
    $this->task->memoryCache->set('mcp_integration_forms', true);
    $mcpUserId   = (int) $this->task->userSession->getId();
    $mcpToken    = $this->task->mcpTokenModel->getTokenForUser($mcpUserId);
    $baseUrl     = $this->helper->url->base();
    $mcpGatewayRows = $this->task->mcpEndpointGatewayModel->rowsForUser($mcpUserId);
    $mcpGroups = [];
    foreach ($mcpGatewayRows as $mcpRow) {
        $mcpGroups[$mcpRow['group']][] = $mcpRow;
    }
    ?>

    <?php if ($mcpToken): ?>
        <?php
        $mcpUrl = $baseUrl
            . '?controller=ModelContextProtocolController&action=handle&plugin=ModelContextProtocol&token='
            . $mcpToken;
        ?>

        <div class="form-group">
            <label><strong><?= t('MCP Client Configuration') ?></strong></label>
            <textarea readonly
                      class="form-control"
                      style="background-color:#f5f5f5;font-family:monospace;height:120px;resize:vertical;width:100%;"
                      onclick="this.select();">{
  "mcpServers": {
    "kanboard": {
      "url": "<?= $this->text->e($mcpUrl) ?>"
    }
  }
}</textarea>
            <p class="form-help"><?= t('Copy this configuration to your MCP client settings (e.g., Claude Desktop app config). The server URL and authentication token are included.') ?></p>
        </div>

        <div class="alert alert-warning">
            <p><strong><?= t('Security Note:') ?></strong>
                <?= t('This URL provides full access to your own Kanboard boards. Keep it secure and do not share it publicly.') ?>
            </p>
        </div>

        <button type="submit" form="mcp-token-form" class="btn btn-default">
            <i class="fa fa-refresh fa-fw"></i>&nbsp;<?= t('Autogenerar') ?>
        </button>

    <?php else: ?>

        <div class="alert alert-info">
            <p><?= t('No MCP token has been generated yet. Generate a token to enable MCP access.') ?></p>
        </div>

        <button type="submit" form="mcp-token-form" class="btn btn-success">
            <i class="fa fa-key fa-fw"></i>&nbsp;<?= t('Generate Token') ?>
        </button>

    <?php endif; ?>
</div>

<div class="panel" id="mcp-access-panel">
    <h3><?= t('MCP access') ?></h3>
    <p><?= t('Enable or disable each MCP endpoint for your own token. Only endpoints your role allows are shown. A disabled endpoint cannot be called.') ?></p>

    <?php foreach ($mcpGroups as $mcpGroup => $mcpGroupRows): ?>
        <h4><?= $this->text->e(t($mcpGroup)) ?></h4>
        <?php foreach ($mcpGroupRows as $mcpEndpoint): ?>
            <div class="form-group">
                <label>
                    <input type="checkbox"
                           name="endpoints[]"
                           form="mcp-gateway-form"
                           value="<?= $this->text->e($mcpEndpoint['name']) ?>"
                           <?= $mcpEndpoint['enabled'] ? 'checked="checked"' : '' ?>>
                    <code><?= $this->text->e($mcpEndpoint['name']) ?></code>
                </label>
            </div>
        <?php endforeach ?>
    <?php endforeach ?>

    <button type="submit" form="mcp-gateway-form" class="btn btn-default">
        <?= t('Save access') ?>
    </button>
</div>
