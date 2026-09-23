<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The company's equipment: what it is, where it is kept, and who has it.
 *
 * Who holds an asset now sits on the asset itself, for the lists; every hand
 * it has passed through is kept in asset_assignments, since "who had the
 * laptop in March" is the question that gets asked when one comes back broken.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            // The label stuck on the thing itself.
            $table->string('tag')->unique();
            $table->string('name');
            $table->foreignId('asset_category_id')->constrained('asset_categories')->restrictOnDelete();
            $table->string('serial_number')->nullable();

            // Which site it belongs to, and where on it: a room, a desk, a store.
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->string('spot')->nullable();

            $table->string('status')->default('available');
            $table->string('condition')->default('good');
            $table->date('purchased_on')->nullable();
            $table->decimal('purchase_cost', 14, 2)->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'asset_category_id']);
            $table->index('assigned_user_id');
        });

        Schema::create('asset_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('returned_at')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'returned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_assignments');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('asset_categories');
    }
};
