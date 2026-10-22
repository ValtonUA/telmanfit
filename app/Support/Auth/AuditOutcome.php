<?php

namespace App\Support\Auth;

enum AuditOutcome: string
{
    case Success = 'success';
    case Denied = 'denied';
    case Error = 'error';
}
