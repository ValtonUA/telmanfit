<?php

namespace App\Services\Auth\Notices;

enum RecipientSlot: string
{
    case Old = 'old';
    case Current = 'current';
    case Independent = 'independent';
}
