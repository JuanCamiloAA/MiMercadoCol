<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('permission.table_names.roles', 'roles');

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $blueprint->string('display_name')->nullable()->after('guard_name');
            $blueprint->string('description')->nullable()->after('display_name');
            $blueprint->string('color', 9)->default('#0E6E4E')->after('description');
            $blueprint->boolean('is_system')->default(false)->after('color');
        });

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->dropUnique(['name', 'guard_name']);
            $blueprint->unique(['name', 'guard_name', 'company_id'], 'roles_name_guard_company_unique');
        });
    }

    public function down(): void
    {
        $table = config('permission.table_names.roles', 'roles');

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->dropUnique('roles_name_guard_company_unique');
            $blueprint->dropConstrainedForeignId('company_id');
            $blueprint->dropColumn(['display_name', 'description', 'color', 'is_system']);
            $blueprint->unique(['name', 'guard_name']);
        });
    }
};
