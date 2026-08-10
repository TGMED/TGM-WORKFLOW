<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            // System roles are wired into middleware and cannot be deleted.
            $table->boolean('is_system')->default(true);
            $table->timestamps();
        });

        // Seeded here rather than in a seeder: the app cannot authenticate
        // anyone without these three, so every database needs them.
        $now = now();

        DB::table('roles')->insert([
            [
                'slug' => 'super_admin',
                'name' => 'Super Admin',
                'description' => 'Runs the system. Manages staff, sites and settings.',
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'approver',
                'name' => 'Approver',
                'description' => 'Works a shift and decides on leave and lateness requests.',
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'staff',
                'name' => 'Staff',
                'description' => 'Clocks in and raises requests.',
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
