<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            // Null means the type is uncapped and no balance is enforced.
            $table->unsignedSmallInteger('days_per_year')->nullable();
            $table->boolean('is_paid')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // The two types the business runs today. More are added from the
        // request settings page.
        $now = now();

        DB::table('leave_types')->insert([
            [
                'slug' => 'annual',
                'name' => 'Annual leave',
                'description' => 'Paid time off taken from the yearly allowance.',
                'days_per_year' => 20,
                'is_paid' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'sick',
                'name' => 'Sick leave',
                'description' => 'Time off for illness or medical appointments.',
                'days_per_year' => 10,
                'is_paid' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
