<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The documents staff are held to: the handbook and the policies under it.
 *
 * Versioned rather than overwritten. A policy is the thing somebody is judged
 * against, and when a case turns on what the rule said in March, replacing the
 * file in April would have destroyed the only answer. A new version supersedes
 * the old one, which stops being offered but stays readable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policies', function (Blueprint $table) {
            $table->id();

            $table->string('title');
            $table->string('category')->default('other');
            // Free text rather than a number: companies write "2.1", "2024-A"
            // and "Rev C", and none of them is wrong.
            $table->string('version', 40)->nullable();
            $table->text('summary')->nullable();

            // The file lives on the private disk and is served through a
            // controller, as leave evidence is. A handbook is not a secret,
            // but it is not the public's either.
            $table->string('file_path');
            $table->string('file_name');
            $table->unsignedInteger('file_size')->default(0);
            $table->string('mime_type', 120)->nullable();

            $table->date('effective_from');
            $table->boolean('is_active')->default(true);

            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            // The version this one replaced, so the history reads as a chain
            // rather than as a pile of documents with similar names.
            $table->foreignId('supersedes_id')->nullable()->constrained('policies')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policies');
    }
};
