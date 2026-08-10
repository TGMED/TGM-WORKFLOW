<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_settings', function (Blueprint $table) {
            $table->id();
            // One row per request module: leave, lateness.
            $table->string('module')->unique();
            $table->unsignedTinyInteger('approvers_required')->default(1);
            $table->timestamps();
        });

        $now = now();

        DB::table('approval_settings')->insert([
            ['module' => 'leave', 'approvers_required' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['module' => 'lateness', 'approvers_required' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_settings');
    }
};
