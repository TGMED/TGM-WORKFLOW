<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            // How long one break may last at this site. Zero means staff take
            // no break here at all.
            $table->unsignedSmallInteger('break_minutes')->default(60)->after('grace_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('break_minutes');
        });
    }
};
