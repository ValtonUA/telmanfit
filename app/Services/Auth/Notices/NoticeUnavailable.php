<?php

namespace App\Services\Auth\Notices;

final class NoticeUnavailable extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('security_notice_unavailable');
    }
}
