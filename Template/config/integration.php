<div class="panel" id="mcp-token-panel">
    <h3><i class="fa fa-plug fa-fw"></i>&nbsp;<?= t('Model Context Protocol (MCP)') ?></h3>
    <p><?= t('Control Kanboard via Model Context Protocol. Each user has their own token that grants only their own board access.') ?></p>

    <?php
    // Always show the token of the session user, never the profile user.
    // The real <form> is rendered from template:layout:bottom. Controls here
    // only point at it, so they are not submitted with Kanboard's Save button.
    $this->task->memoryCache->set('mcp_integration_forms', true);
    $mcpUserId   = (int) $this->task->userSession->getId();
    $mcpHasToken = $this->task->mcpTokenModel->hasToken($mcpUserId);
    $mcpToken    = $this->task->mcpTokenModel->takeReveal($mcpUserId);
    $baseUrl     = $this->helper->url->base();
    ?>

    <?php if ($mcpToken): ?>
        <?php
        $mcpUrl = $baseUrl
            . '?controller=ModelContextProtocolController&action=handle&plugin=ModelContextProtocol';
        ?>

        <div class="form-group">
            <label><strong><?= t('MCP Client Configuration') ?></strong></label>
            <textarea readonly
                      class="form-control"
                      style="background-color:#f5f5f5;font-family:monospace;height:120px;resize:vertical;width:100%;"
                      onclick="this.select();">{
  "mcpServers": {
    "kanboard": {
      "url": "<?= $this->text->e($mcpUrl) ?>",
      "headers": {
        "Authorization": "Bearer <?= $this->text->e($mcpToken) ?>"
      }
    }
  }
}</textarea>
            <p class="form-help"><?= t('Copy this configuration to your MCP client settings (e.g., Claude Desktop app config). The server URL and authentication token are included.') ?></p>
            <p class="form-help"><?= t('Copy this token now. It is shown only once.') ?></p>
            <input type="text" class="form-control" readonly="readonly" value="<?= $this->text->e($mcpToken) ?>" onclick="this.select();">
        </div>

        <div class="alert alert-warning">
            <p><strong><?= t('Security Note:') ?></strong>
                <?= t('This URL provides full access to your own Kanboard boards. Keep it secure and do not share it publicly.') ?>
            </p>
        </div>

        <button type="submit" form="mcp-token-form" class="btn btn-default">
            <i class="fa fa-refresh fa-fw"></i>&nbsp;<?= t('Autogenerar') ?>
        </button>

    <?php elseif ($mcpHasToken): ?>

        <div class="alert alert-info">
            <p><?= t('An MCP token already exists for your account. Generate a new one to replace it. The current token is not shown again.') ?></p>
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
