<?php

declare(strict_types=1);

namespace App\Value;

enum AuthResult: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case SoftFail = 'softfail';
    case Neutral = 'neutral';
    case None = 'none';
    case TempError = 'temperror';
    case PermError = 'permerror';
}
