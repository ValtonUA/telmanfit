<?php

namespace App\Services\Auth;

final class MfaDenied extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Не вдалося підтвердити автентифікацію. Почніть вхід повторно.');
    }
}
