<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\NotificationBatchController;
use App\Http\Controllers\Api\V1\SubscriberNotificationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('notification-batches', [NotificationBatchController::class, 'store'])
        ->name('api.v1.notification-batches.store');

    Route::get('subscribers/{recipientId}/notifications', [SubscriberNotificationController::class, 'index'])
        ->name('api.v1.subscribers.notifications.index');
});
