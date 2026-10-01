<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A holiday can be kept by more than one site, for a state holiday two
     * offices in the same state both observe. No sites still means every site
     * is off. The one site each holiday already had moves across.
     */
    public function up(): void
    {
        Schema::create('location_public_holiday', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('public_holiday_id')->constrained()->cascadeOnDelete();

            $table->unique(['public_holiday_id', 'location_id']);
        });

        DB::table('public_holidays')
            ->whereNotNull('location_id')
            ->orderBy('id')
            ->each(fn (object $holiday) => DB::table('location_public_holiday')->insert([
                'location_id' => $holiday->location_id,
                'public_holiday_id' => $holiday->id,
            ]));

        Schema::table('public_holidays', function (Blueprint $table): void {
            $table->dropIndex(['date', 'location_id']);
            $table->dropConstrainedForeignId('location_id');
            $table->index('date');
        });
    }

    /**
     * Back to one site a holiday. One kept by several keeps only the first,
     * so rolling back loses the rest.
     */
    public function down(): void
    {
        Schema::table('public_holidays', function (Blueprint $table): void {
            $table->dropIndex(['date']);
            $table->foreignId('location_id')->nullable()->after('date')->constrained()->cascadeOnDelete();
            $table->index(['date', 'location_id']);
        });

        DB::table('location_public_holiday')
            ->selectRaw('public_holiday_id, min(location_id) as location_id')
            ->groupBy('public_holiday_id')
            ->get()
            ->each(fn (object $row) => DB::table('public_holidays')
                ->where('id', $row->public_holiday_id)
                ->update(['location_id' => $row->location_id]));

        Schema::dropIfExists('location_public_holiday');
    }
};
