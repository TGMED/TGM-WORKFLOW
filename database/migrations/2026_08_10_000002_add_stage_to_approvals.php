<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            // 'relief' marks the cover sign-off, which gates the run rather
            // than counting as one of the approvals required.
            $table->string('stage')->default('approval')->after('step');
        });
    }

    public function down(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->dropColumn('stage');
        });
    }
};
