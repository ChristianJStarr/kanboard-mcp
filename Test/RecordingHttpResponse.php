<?php

/**
 * Records status and redirect without calling header().
 *
 * Kanboard's Response::status() and Response::redirect() both call header().
 */
class RecordingHttpResponse
{
    /** @var int|null */
    public $status;

    /** @var string|null */
    public $redirectUrl;

    /** @var bool */
    public $redirectSelf = false;

    /**
     * @param int $statusCode
     */
    public function status($statusCode): void
    {
        $this->status = (int) $statusCode;
    }

    /**
     * @param string $url
     * @param bool   $self
     */
    public function redirect($url, $self = false): void
    {
        $this->redirectUrl = (string) $url;
        $this->redirectSelf = (bool) $self;
    }
}
