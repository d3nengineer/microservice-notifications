<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationPriority: string
{
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';
}
