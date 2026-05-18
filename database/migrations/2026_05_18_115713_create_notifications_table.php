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
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('notification_batches')->cascadeOnDelete();
            $table->string('recipient_id');
            $table->string('channel');
            $table->text('message');
            $table->string('priority');
            $table->string('status')->index();
            $table->string('deduplication_key')->unique();
            $table->timestamps();

            $table->index(['recipient_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
