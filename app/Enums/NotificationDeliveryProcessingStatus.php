<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationDeliveryProcessingStatus: string
{
    case Consumed = 'consumed';
    case Skipped = 'skipped';
    case Missing = 'missing';
    case Invalid = 'invalid';
}
