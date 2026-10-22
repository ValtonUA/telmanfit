<?php

namespace App\Services\Auth\Recovery;

final class RecoveryDenied extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Recovery unavailable');
    }
}
