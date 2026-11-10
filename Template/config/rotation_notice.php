<?php

if (!$this->task->userSession->isLogged()) {
    return;
}

$this->task->mcpTokenModel->notifyUser((int) $this->task->userSession->getId());
