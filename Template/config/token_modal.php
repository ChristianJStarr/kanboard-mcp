<div class="page-header">
    <h2><?= t('Generate MCP token') ?></h2>
</div>
<form method="post" action="<?= $this->url->href('ModelContextProtocolController', 'generateToken', array('plugin' => 'ModelContextProtocol')) ?>" autocomplete="off">
    <?= $this->form->csrf() ?>

    <?= $this->form->label(t('Act as user'), 'user_id') ?>
    <?= $this->form->select('user_id', $users, $values) ?>
    <p class="form-help"><?= t('The MCP client gets exactly the project access and roles of the selected user. Tasks and comments are attributed to that user.') ?></p>

    <?php if ($has_token): ?>
    <div class="alert alert-warning">
        <p><?= t('The current token stops working as soon as a new one is generated.') ?></p>
    </div>
    <?php endif ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-red"><?= $has_token ? t('Generate New Token') : t('Generate Token') ?></button>
        <?= t('or') ?> <a href="#" class="js-modal-close"><?= t('cancel') ?></a>
    </div>
</form>
