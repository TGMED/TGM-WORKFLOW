<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which walkthroughs somebody has already been shown.
 *
 * On the record rather than in the browser, for the same reason the release
 * notes are: a person who was shown the tour on their laptop should not be
 * shown it again the first time they open the app on their phone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('tours_seen')->nullable()->after('whats_new_seen');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('tours_seen');
        });
    }
};
