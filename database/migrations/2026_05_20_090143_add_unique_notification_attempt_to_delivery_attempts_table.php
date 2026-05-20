<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('delivery_attempts', function (Blueprint $table) {
            $table->unique(['notification_id', 'attempt_number'], 'delivery_attempts_notification_attempt_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivery_attempts', function (Blueprint $table) {
            $table->dropUnique('delivery_attempts_notification_attempt_unique');
        });
    }
};
