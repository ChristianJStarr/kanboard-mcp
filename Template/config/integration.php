<div class="panel">
    <h3><i class="fa fa-plug fa-fw"></i>&nbsp;<?= t('Model Context Protocol (MCP)') ?></h3>
    <p><?= t('Control Kanboard via Model Context Protocol. Each user has their own token that grants only their own board access.') ?></p>

    <?php
    // Always show the token of the session user, never the profile user.
    $mcpUserId   = $this->task->userSession->getId();
    $mcpToken    = $this->task->mcpTokenModel->getTokenForUser($mcpUserId);
    $baseUrl     = $this->helper->url->base();
    $generateUrl = $this->helper->url->to(
        'ModelContextProtocolController',
        'generateToken',
        ['plugin' => 'ModelContextProtocol']
    );
    $csrfToken = $this->task->token->getReusableCSRFToken();
    $mcpTarget = $this->app->getRouterController() === 'UserViewController' ? 'user' : 'config';
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

        <form method="post"
              action="<?= $this->text->e($generateUrl) ?>"
              onsubmit="return confirm('<?= t('This will invalidate the current token. Are you sure?') ?>')">
            <input type="hidden" name="csrf_token" value="<?= $this->text->e($csrfToken) ?>">
            <input type="hidden" name="target" value="<?= $this->text->e($mcpTarget) ?>">
            <button type="submit" class="btn btn-default">
                <i class="fa fa-refresh fa-fw"></i>&nbsp;<?= t('Autogenerar') ?>
            </button>
        </form>

    <?php else: ?>

        <div class="alert alert-info">
            <p><?= t('No MCP token has been generated yet. Generate a token to enable MCP access.') ?></p>
        </div>

        <form method="post" action="<?= $this->text->e($generateUrl) ?>">
            <input type="hidden" name="csrf_token" value="<?= $this->text->e($csrfToken) ?>">
            <input type="hidden" name="target" value="<?= $this->text->e($mcpTarget) ?>">
            <button type="submit" class="btn btn-success">
                <i class="fa fa-key fa-fw"></i>&nbsp;<?= t('Generate Token') ?>
            </button>
        </form>

    <?php endif; ?>
</div>
