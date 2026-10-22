<?php

namespace App\Services\Auth\Notices;

use App\Services\Auth\SecretValue;

final class FakeNoticeDelivery implements NoticeDelivery
{
    public array $messages = [];

    public DeliveryResult $result = DeliveryResult::Sent;

    public function send(SecretValue $recipient, NoticeType $type, string $occurredAt, string $messageId, int $timeoutSeconds): DeliveryResult
    {
        if (! app()->environment(['local', 'testing'])) {
            return DeliveryResult::Policy;
        }
        // Навіть fake не накопичує plaintext одержувачів.
        $this->messages[] = ['message_id' => $messageId, 'body' => $type->template(), 'occurred_at' => $occurredAt];

        return $this->result;
    }
}
