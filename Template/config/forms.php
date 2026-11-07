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
