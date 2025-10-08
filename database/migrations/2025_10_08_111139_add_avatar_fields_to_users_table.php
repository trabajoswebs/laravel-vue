<?php

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
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_version', 40)->nullable()->after('email')->comment('Hash SHA1 del avatar para cache busting');
            $table->timestamp('avatar_updated_at')->nullable()->after('avatar_version')->comment('Fecha de última actualización del avatar');
            $table->index('avatar_version', 'users_avatar_version_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_avatar_version_index');
            $table->dropColumn(['avatar_version', 'avatar_updated_at']);
        });
    }
};
