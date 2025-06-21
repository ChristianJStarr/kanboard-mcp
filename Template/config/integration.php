<div class="panel">
    <h3><i class="fa fa-cog fa-fw"></i>&nbsp;<?= t('Model Context Protocol') ?></h3>
    
    <p><?= t('Control Kanboard via Model Context Protocol.') ?></p>
    
    <?php 
    // Auto-generate token if it doesn't exist
    if (!$this->task->mcpTokenModel->tokenExists()) {
        $this->task->mcpTokenModel->generateToken();
    }
    
    // Get current token and build MCP URL
    $token = $this->task->mcpTokenModel->getCurrentToken();
    $mcpUrl = $this->helper->url->base() . '?controller=ModelContextProtocolController&action=handle&plugin=ModelContextProtocol&token=' . $token;
    ?>
    
    <div class="form-group">
        <label><strong><?= t('MCP Client Configuration') ?></strong></label>
        <textarea readonly 
                  class="form-control" 
                  style="background-color: #f5f5f5; font-family: monospace; height: 120px; resize: vertical; width: 100%;"
                  onclick="this.select();">{
  "mcpServers": {
    "kanboard": {
      "url": "<?= $this->text->e($mcpUrl) ?>"
    }
  }
}</textarea>
        <p class="form-help"><?= t('Copy this configuration to your MCP client settings (e.g., Claude Desktop app config). The server URL and authentication token are included.') ?></p>
    </div>
    
    <div class="alert alert-info">
        <p><strong><?= t('Security Note:') ?></strong> <?= t('This URL provides full access to your Kanboard instance. Keep it secure.') ?></p>
    </div>
</div> 