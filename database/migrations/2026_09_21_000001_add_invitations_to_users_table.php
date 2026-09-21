<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nobody signs up any more: the people team adds a person and the app
     * emails them a link to choose their own password. The link's token is
     * kept only as a hash, and is cleared once it has been used, so a row
     * with one is somebody who has not got in yet.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('invitation_token', 64)->nullable()->unique()->after('remember_token');
            $table->timestamp('invited_at')->nullable()->after('invitation_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['invitation_token']);
            $table->dropColumn(['invitation_token', 'invited_at']);
        });
    }
};
