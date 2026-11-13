<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documents added to a leave request after it was booked: the medical record
 * that follows a photo of the medicine, or the paper an approver asked for.
 * The document attached at booking stays where it was, on the request itself;
 * these sit alongside it and never replace it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained()->cascadeOnDelete();
            // On the private disk, like the booking document: this is medical
            // paperwork and is only served through a controller that checks.
            $table->string('path');
            $table->string('name');
            $table->unsignedBigInteger('size')->nullable();
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_documents');
    }
};
