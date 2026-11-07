<div class="panel" id="mcp-token-panel">
    <h3><i class="fa fa-plug fa-fw"></i>&nbsp;<?= t('Model Context Protocol (MCP)') ?></h3>
    <p><?= t('Control Kanboard via Model Context Protocol. Each user has their own token that grants only their own board access.') ?></p>

    <?php
    // Always show the token of the session user, never the profile user.
    // The real <form> is rendered from template:layout:bottom. Controls here
    // only point at it, so they are not submitted with Kanboard's Save button.
    $this->task->memoryCache->set('mcp_integration_forms', true);
    $mcpUserId          = (int) $this->task->userSession->getId();
    $mcpHasToken        = $this->task->mcpTokenModel->hasToken($mcpUserId);
    $mcpToken           = $this->task->mcpTokenModel->takeReveal($mcpUserId);
    $mcpDisableUrlToken = $this->task->mcpTokenModel->urlTokensDisabled();
    $mcpIsAdmin         = $this->task->userSession->isAdmin();
    $baseUrl            = $this->helper->url->base();
    $mcpUrl             = $baseUrl
        . '?controller=ModelContextProtocolController&action=handle&plugin=ModelContextProtocol';
    ?>

    <div class="form-group" id="mcp-url-token-setting">
        <label>
            <input type="checkbox"
                   name="mcp_disable_url_token"
                   form="mcp-url-token-form"
                   value="1"
                   id="mcp-disable-url-token"
                   <?= $mcpDisableUrlToken ? 'checked="checked"' : '' ?>
                   <?= $mcpIsAdmin ? '' : 'disabled="disabled"' ?>>
            <?= t('Disable token in URL (query string)') ?>
        </label>
        <p class="form-help"><?= t('When this is checked, MCP clients must send the token in the Authorization header. A token in the URL can be written to web server and proxy access logs.') ?></p>
        <?php if (!$mcpIsAdmin): ?>
            <p class="form-help"><?= t('Only administrators can change this instance setting.') ?></p>
        <?php endif; ?>
    </div>

    <?php if ($mcpIsAdmin): ?>
        <button type="submit" form="mcp-url-token-form" class="btn btn-default" id="mcp-save-url-token-setting">
            <?= t('Save URL token setting') ?>
        </button>
    <?php endif; ?>

    <?php if ($mcpToken): ?>

        <div class="form-group">
            <label><strong><?= t('Recommended: Authorization header') ?></strong></label>
            <textarea readonly
                      id="mcp-header-config"
                      class="form-control"
                      style="background-color:#f5f5f5;font-family:monospace;height:150px;resize:vertical;width:100%;"
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
            <p class="form-help"><?= t('Copy this configuration into your MCP client. The token is sent as Authorization: Bearer and is not placed in the URL.') ?></p>
        </div>

        <div class="form-group">
            <label><strong><?= t('Compatibility: token in the URL') ?></strong></label>
            <div class="alert alert-warning">
                <p><strong><?= t('Security Note:') ?></strong>
                    <?= t('This method puts the token in the query string. Web servers and reverse proxies often write the full URL, including the token, to access logs.') ?>
                </p>
                <?php if ($mcpDisableUrlToken): ?>
                    <p><?= t('This URL will not authenticate while token-in-URL is disabled. Use the Authorization header configuration, or uncheck the setting above.') ?></p>
                <?php endif; ?>
            </div>
            <textarea readonly
                      id="mcp-url-config"
                      class="form-control"
                      style="background-color:#f5f5f5;font-family:monospace;height:120px;resize:vertical;width:100%;"
                      onclick="this.select();">{
  "mcpServers": {
    "kanboard": {
      "url": "<?= $this->text->e($mcpUrl . '&token=' . $mcpToken) ?>"
    }
  }
}</textarea>
            <p class="form-help"><?= t('Use this only with MCP clients that cannot send an Authorization header.') ?></p>
            <p class="form-help"><?= t('Copy this token now. It is shown only once.') ?></p>
            <input type="text" class="form-control" readonly="readonly" value="<?= $this->text->e($mcpToken) ?>" onclick="this.select();">
        </div>

        <div class="alert alert-warning">
            <p><strong><?= t('Security Note:') ?></strong>
                <?= t('This token provides full access to your own Kanboard boards. Keep it secure and do not share it publicly.') ?>
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
