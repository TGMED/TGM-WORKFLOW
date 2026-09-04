<?php

use App\Enums\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What each role may do.
 *
 * The catalogue of permissions is an enum in code, since every entry guards a
 * route that only exists in code. Only the assignment is data, so a role can
 * be given or denied something from the roles page without a deploy, and a
 * permission can never be assigned that nothing checks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('permission', 60);

            // A role holds a permission once or not at all.
            $table->unique(['role_id', 'permission']);
        });

        $this->seedSystemRoles();
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }

    /**
     * The three roles the app ships with, given exactly what they could
     * already do before permissions existed. Super admins are deliberately
     * left empty: they hold everything implicitly, and a stored list would
     * only be a list somebody could take away from.
     */
    protected function seedSystemRoles(): void
    {
        $approver = DB::table('roles')->where('slug', Role::APPROVER)->value('id');

        if ($approver === null) {
            return;
        }

        DB::table('role_permissions')->insert([
            ['role_id' => $approver, 'permission' => Permission::ApproveRequests->value],
        ]);
    }
};
