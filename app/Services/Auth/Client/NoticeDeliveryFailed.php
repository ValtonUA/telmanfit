<?php

namespace App\Services\Auth\Client;

final class NoticeDeliveryFailed extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Security notice delivery failed.');
    }
}
