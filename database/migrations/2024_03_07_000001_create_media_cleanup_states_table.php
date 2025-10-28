<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_cleanup_states', function (Blueprint $table): void {
            $table->string('media_id')->primary();
            $table->string('collection')->nullable();
            $table->string('model_type')->nullable();
            $table->string('model_id')->nullable();
            $table->json('conversions')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('flagged_at')->nullable();
            $table->timestamp('payload_queued_at')->nullable();
            $table->timestamps();

            $table->index('flagged_at');
            $table->index('payload_queued_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_cleanup_states');
    }
};
