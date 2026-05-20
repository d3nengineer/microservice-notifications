<?php

declare(strict_types=1);

namespace App\Enums;

enum GatewayResultStatus: string
{
    case Succeeded = 'succeeded';
    case TemporaryFailed = 'temporary_failed';
    case PermanentlyFailed = 'permanently_failed';
}
