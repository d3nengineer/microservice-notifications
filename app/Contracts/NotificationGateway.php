<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTO\GatewayResult;
use App\Models\Notification;

interface NotificationGateway
{
    /**
     * Send a notification through an external gateway boundary.
     *
     * Implementations should log external calls at DEBUG or INFO, failures at
     * WARNING or ERROR, and must only include safe metadata such as notification
     * id, channel, priority, gateway name, and provider error codes.
     */
    public function send(Notification $notification): GatewayResult;
}
