<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('email')->constrained()->restrictOnDelete();
        });

        foreach (DB::table('roles')->pluck('id', 'slug') as $slug => $id) {
            DB::table('users')->where('role', $slug)->update(['role_id' => $id]);
        }

        // Anything unrecognised falls back to the least privileged role.
        DB::table('users')
            ->whereNull('role_id')
            ->update(['role_id' => DB::table('roles')->where('slug', 'staff')->value('id')]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role', 'is_active']);
            $table->dropColumn('role');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable(false)->change();
            $table->index(['role_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('staff')->after('email');
        });

        foreach (DB::table('roles')->pluck('slug', 'id') as $id => $slug) {
            DB::table('users')->where('role_id', $id)->update(['role' => $slug]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role_id', 'is_active']);
            $table->dropConstrainedForeignId('role_id');
            $table->index(['role', 'is_active']);
        });
    }
};
