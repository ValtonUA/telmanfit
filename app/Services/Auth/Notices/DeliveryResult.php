<?php

namespace App\Services\Auth\Notices;

enum DeliveryResult: string
{
    case Sent = 'sent';
    case Transient = 'transport';
    case Timeout = 'timeout';
    case Permanent = 'permanent';
    case Policy = 'policy';
}
