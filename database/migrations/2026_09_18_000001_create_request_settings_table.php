<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The rules requests are filed under, as opposed to how many people must
 * approve them, which `approval_settings` already holds per module.
 *
 * One row, owned by the people team. The numbers here are policy rather than
 * arithmetic, so they belong on a settings page and not in config: a company
 * that wants two hours' warning of a late arrival should not need a deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_settings', function (Blueprint $table) {
            $table->id();

            // How long before the start of work a lateness request must be in.
            // Measured back from that site's resumption time on the day, so a
            // person notifies ahead of the morning rather than accounting for
            // it afterwards.
            $table->unsignedSmallInteger('lateness_cutoff_minutes')->default(60);

            $table->timestamps();
            $table->softDeletes();
        });

        DB::table('request_settings')->insert([
            'lateness_cutoff_minutes' => 60,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('request_settings');
    }
};
