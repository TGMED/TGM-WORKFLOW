<?php

use App\Enums\ReportStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An incident a member of staff has raised: something that happened to them,
 * something they saw, or the conduct of a colleague.
 *
 * The reporter is recorded. It is held back from everyone but the handful of
 * administrators who hold the reports permission — never the person reported,
 * never the reporter's own line manager on the strength of managing them —
 * but it is on the row, and the wording staff are shown says so rather than
 * promising an anonymity the schema does not give.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();

            // Who filed it. Nullable only so that deleting a leaver's account
            // does not take the case file with it; the report outlives them.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // The colleague the report is about, when it is about a person
            // rather than a situation. Optional: plenty of reports name no one.
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->nullOnDelete();

            // A free-text name for someone the reporter could not find in the
            // staff list — a contractor, a visitor, somebody they only know by
            // sight. Kept beside the relation rather than instead of it.
            $table->string('subject_name')->nullable();

            $table->string('category', 30);
            $table->string('subject');
            $table->text('body');

            $table->date('occurred_on')->nullable();
            $table->string('place')->nullable();

            // Optional supporting file, on the private disk. Nothing about a
            // report is served off a guessable URL.
            $table->string('evidence_path')->nullable();
            $table->string('evidence_name')->nullable();

            $table->string('status', 20)->default(ReportStatus::Submitted->value);

            // Whoever picked the case up, and what they concluded. The note is
            // internal; the reporter sees the status, not the note.
            $table->foreignId('handled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->text('resolution_note')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
