<div class="panel" id="mcp-token-panel">
    <h3><i class="fa fa-plug fa-fw"></i>&nbsp;<?= t('Model Context Protocol (MCP)') ?></h3>
    <p><?= t('Control Kanboard via Model Context Protocol. Each user has their own token that grants only their own board access.') ?></p>

    <?php
    // Always show the token of the session user, never the profile user.
    // The real <form> elements are rendered from template:layout:bottom.
    // Controls here only point at them, so Kanboard's Save button does not
    // submit them and saving access does not rotate the token.
    $this->task->memoryCache->set('mcp_integration_forms', true);
    $mcpUserId          = (int) $this->task->userSession->getId();
    $mcpHasToken        = $this->task->mcpTokenModel->hasToken($mcpUserId);
    $mcpToken           = $this->task->mcpTokenModel->takeReveal($mcpUserId);
    $mcpDisableUrlToken = $this->task->mcpTokenModel->urlTokensDisabled();
    $mcpRotationEnabled = $this->task->mcpTokenModel->rotationEnabled();
    $mcpRotationDays    = $this->task->mcpTokenModel->rotationDays();
    $mcpExpiresAt       = $this->task->mcpTokenModel->expiresAtForUser($mcpUserId);
    $mcpIsAdmin         = $this->task->userSession->isAdmin();
    $baseUrl            = $this->helper->url->base();
    $mcpUrl             = $baseUrl
        . '?controller=ModelContextProtocolController&action=handle&plugin=ModelContextProtocol';
    $mcpGatewayRows = $this->task->mcpEndpointGatewayModel->rowsForUser($mcpUserId);
    $mcpGroups = [];
    foreach ($mcpGatewayRows as $mcpRow) {
        $mcpGroups[$mcpRow['group']][] = $mcpRow;
    }
    ?>

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

        <button type="submit" form="mcp-token-form" class="btn btn-blue">
            <i class="fa fa-key fa-fw"></i>&nbsp;<?= t('Generate Token') ?>
        </button>

    <?php endif; ?>

    <fieldset id="mcp-instance-settings">
        <legend><?= t('Instance settings') ?></legend>

        <div id="mcp-url-token-setting">
            <input type="hidden"
                   name="mcp_disable_url_token_present"
                   value="1"
                   form="mcp-settings-form">
            <label>
                <input type="checkbox"
                       name="mcp_disable_url_token"
                       form="mcp-settings-form"
                       value="1"
                       id="mcp-disable-url-token"
                       <?= $mcpDisableUrlToken ? 'checked="checked"' : '' ?>
                       <?= $mcpIsAdmin ? '' : 'disabled="disabled"' ?>>
                <?= t('Disable token in URL (query string)') ?>
            </label>
            <p class="form-help"><?= t('Tokens in the URL are written to access logs.') ?></p>
        </div>

        <div id="mcp-rotation-setting">
            <label>
                <input type="checkbox"
                       name="mcp_token_rotation_enabled"
                       form="mcp-settings-form"
                       value="1"
                       id="mcp-token-rotation-enabled"
                       <?= $mcpRotationEnabled ? 'checked="checked"' : '' ?>
                       <?= $mcpIsAdmin ? '' : 'disabled="disabled"' ?>>
                <?= t('Enable MCP token rotation') ?>
            </label>

            <label for="mcp-token-rotation-days"><?= t('Expires after') ?></label>
            <select name="mcp_token_rotation_days"
                    id="mcp-token-rotation-days"
                    form="mcp-settings-form"
                    <?= $mcpIsAdmin ? '' : 'disabled="disabled"' ?>>
                <?php foreach ([30, 60, 90, 180, 360] as $mcpRotationOption): ?>
                    <option value="<?= $mcpRotationOption ?>" <?= $mcpRotationDays === $mcpRotationOption ? 'selected="selected"' : '' ?>>
                        <?= t($mcpRotationOption . ' days') ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="form-help"><?= t('A notice appears in My notifications 14 days before expiry.') ?></p>
            <?php if ($mcpExpiresAt !== null): ?>
                <p id="mcp-token-expiry">
                    <?php if ($mcpExpiresAt <= time()): ?>
                        <?= t('This token expired on %s. Generate a new one.', date('Y-m-d', $mcpExpiresAt)) ?>
                    <?php else: ?>
                        <?= t('This token expires on %s.', date('Y-m-d', $mcpExpiresAt)) ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>

        <?php if ($mcpIsAdmin): ?>
            <div class="form-actions">
                <button type="submit" form="mcp-settings-form" class="btn btn-blue" id="mcp-save-instance-settings">
                    <?= t('Save') ?>
                </button>
            </div>
        <?php else: ?>
            <p class="form-help"><?= t('Only administrators can change this instance setting.') ?></p>
        <?php endif; ?>
    </fieldset>
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

    <div class="form-actions">
        <button type="submit" form="mcp-gateway-form" class="btn btn-blue">
            <?= t('Save access') ?>
        </button>
    </div>
</div>
