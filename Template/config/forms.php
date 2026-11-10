<?php
// Rendered from template:layout:bottom, which sits outside Kanboard's
// settings and profile forms. The integrations partial only sets the flag.
if ($this->task->memoryCache->get('mcp_integration_forms') !== true) {
    return;
}

$generateUrl = $this->helper->url->to(
    'ModelContextProtocolController',
    'generateToken',
    ['plugin' => 'ModelContextProtocol']
);
$saveSettingsUrl = $this->helper->url->to(
    'ModelContextProtocolController',
    'saveInstanceSettings',
    ['plugin' => 'ModelContextProtocol']
);
$saveUrl = $this->helper->url->to(
    'ModelContextProtocolController',
    'saveGateway',
    ['plugin' => 'ModelContextProtocol']
);
$csrfToken = $this->task->token->getReusableCSRFToken();
$mcpTarget = $this->app->getRouterController() === 'UserViewController' ? 'user' : 'config';
$mcpUserId = (int) $this->task->userSession->getId();
$hasToken = $this->task->mcpTokenModel->hasToken($mcpUserId);
?>
<form id="mcp-token-form"
      method="post"
      action="<?= $this->text->e($generateUrl) ?>"
      <?= $hasToken ? 'onsubmit="return confirm(\'' . t('This will invalidate the current token. Are you sure?') . '\')"' : '' ?>>
    <input type="hidden" name="csrf_token" value="<?= $this->text->e($csrfToken) ?>">
    <input type="hidden" name="target" value="<?= $this->text->e($mcpTarget) ?>">
</form>
<form id="mcp-settings-form" method="post" action="<?= $this->text->e($saveSettingsUrl) ?>">
    <input type="hidden" name="csrf_token" value="<?= $this->text->e($csrfToken) ?>">
    <input type="hidden" name="target" value="<?= $this->text->e($mcpTarget) ?>">
</form>
<form id="mcp-gateway-form" method="post" action="<?= $this->text->e($saveUrl) ?>">
    <input type="hidden" name="csrf_token" value="<?= $this->text->e($csrfToken) ?>">
    <input type="hidden" name="target" value="<?= $this->text->e($mcpTarget) ?>">
    <input type="hidden" name="gateway_save" value="1">
</form>
