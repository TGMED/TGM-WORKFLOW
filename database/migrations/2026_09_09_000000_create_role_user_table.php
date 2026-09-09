<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A person may hold more than one role.
 *
 * Until now a user carried a single `role_id`, which made the roles mutually
 * exclusive: naming somebody a team lead would have taken away the role they
 * already held. Heads of department and team leads sit alongside whatever
 * somebody already is, so the single column becomes a pivot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();

            // A person holds a role once or not at all.
            $table->unique(['user_id', 'role_id']);
        });

        // Everyone keeps exactly the role they had.
        DB::table('users')
            ->whereNotNull('role_id')
            ->orderBy('id')
            ->chunkById(500, function ($users): void {
                DB::table('role_user')->insert(
                    $users->map(fn ($user): array => [
                        'user_id' => $user->id,
                        'role_id' => $user->role_id,
                    ])->all(),
                );
            });

        // Order matters on MySQL. The composite index below is the only one
        // covering role_id, so it is the index the foreign key relies on, and
        // MySQL refuses to drop an index a constraint still needs. The
        // constraint goes first, then the index, then the column.
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role_id', 'is_active']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id')->nullable()->after('email');
        });

        // A user may hold several roles by now and the column holds one, so
        // the lowest role id wins: role ids are seeded in order of privilege,
        // and taking the first is the reading that cannot silently promote
        // somebody on the way back down.
        $held = DB::table('role_user')
            ->select('user_id', DB::raw('min(role_id) as role_id'))
            ->groupBy('user_id')
            ->get();

        foreach ($held as $row) {
            DB::table('users')->where('id', $row->user_id)->update(['role_id' => $row->role_id]);
        }

        DB::table('users')
            ->whereNull('role_id')
            ->update(['role_id' => DB::table('roles')->where('slug', 'staff')->value('id')]);

        // The index first, then the constraint that leans on it.
        Schema::table('users', function (Blueprint $table) {
            $table->index(['role_id', 'is_active']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('role_id')->references('id')->on('roles')->restrictOnDelete();
        });

        Schema::dropIfExists('role_user');
    }
};
