<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The register of offences, and what the policy says follows each one.
 *
 * Both are data rather than code, and each offence points at the policy
 * document it comes from. That is the whole point: a sanction handed down
 * here can be traced back to the paragraph it came from, and when the policy
 * is reissued the register is updated alongside it rather than drifting.
 *
 * The ladder is per occurrence, because the second time is treated differently
 * from the first almost everywhere, and by how much is a company's own answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offences', function (Blueprint $table) {
            $table->id();

            // The reference the policy itself uses, where it has one, so a
            // conversation can name "D4" and everybody finds the same entry.
            $table->string('code', 20)->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('severity')->default('minor');

            // Where in the handbook this comes from. Nullable so a register
            // can be written before the document is uploaded, but the page
            // says plainly which entries are not yet anchored to anything.
            $table->foreignId('policy_id')->nullable()->constrained('policies')->nullOnDelete();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'severity']);
        });

        Schema::create('sanctions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offence_id')->constrained()->cascadeOnDelete();

            // First occurrence, second, third. Ordered rather than dated: the
            // ladder is about how often, not about when.
            $table->unsignedTinyInteger('occurrence');
            $table->string('action');
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['offence_id', 'occurrence']);
        });

        // An upheld report can name the offence it was found to be, which is
        // what lets the desk read the sanction off the policy rather than
        // inventing one.
        Schema::table('reports', function (Blueprint $table) {
            $table->foreignId('offence_id')->nullable()->after('status')->constrained('offences')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('offence_id');
        });

        Schema::dropIfExists('sanctions');
        Schema::dropIfExists('offences');
    }
};
