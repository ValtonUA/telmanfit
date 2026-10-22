<?php

namespace App\Services\Auth\Notices;

use App\Services\Auth\SecretValue;

interface NoticeDelivery
{
    /** Adapter зобов'язаний обмежити transport до timeoutSeconds; не логувати recipient/exception. */
    public function send(SecretValue $recipient, NoticeType $type, string $occurredAt, string $messageId, int $timeoutSeconds): DeliveryResult;
}
