<?php

use App\Modules\Shared\Infrastructure\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('employee_code', 20)->nullable();
            $table->string('pin_hash')->nullable();
            $table->unsignedSmallInteger('pin_failed_attempts')->default(0);
            $table->timestampTz('pin_locked_until')->nullable();
            $table->boolean('is_active')->default(true);
            // Undangan untuk akun yang sudah ada harus diterima pemiliknya dulu.
            $table->timestampTz('invited_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            // Token/sesi yang dibuat sebelum waktu ini ditolak untuk company ini (FR-AUTH-09).
            $table->timestampTz('sessions_revoked_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->unique(['company_id', 'user_id']);
            $table->index(['company_id', 'is_active']);
        });
        Rls::enable('company_users');

        // Cakupan akses per brand/outlet (FR-AUTH-06). Tanpa baris = seluruh company.
        Schema::create('role_scopes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('company_user_id')->constrained('company_users')->cascadeOnDelete();
            $table->string('scope_type', 10);
            $table->uuid('scope_id');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['company_id', 'company_user_id', 'scope_type', 'scope_id']);
        });
        Rls::enable('role_scopes');

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuidMorphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->uuid('company_id')->nullable()->index();
            $table->uuid('device_id')->nullable()->index();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestampsTz();
        });
        Rls::grant('personal_access_tokens');

        // spatie/laravel-permission dengan teams (team = company) dan UUID.
        Schema::create('permissions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('guard_name');
            $table->string('group', 30)->nullable();
            $table->timestampsTz();
            $table->unique(['name', 'guard_name']);
        });
        Rls::grant('permissions');

        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->string('name');
            $table->string('label', 60)->nullable();
            $table->string('guard_name');
            $table->boolean('is_system')->default(false);
            $table->decimal('max_discount_percent', 5, 2)->default(0)->comment('BR-14');
            $table->timestampsTz();
            $table->index('company_id', 'roles_team_foreign_key_index');
            $table->unique(['company_id', 'name', 'guard_name']);
        });
        Rls::enable('roles', 'company_id', allowNullShared: true);

        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->uuid('model_uuid');
            $table->uuid('company_id');
            $table->index(['company_id', 'model_uuid', 'model_type'], 'model_has_permissions_model_idx');
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->primary(['company_id', 'permission_id', 'model_uuid', 'model_type'], 'model_has_permissions_permission_model_type_primary');
        });
        Rls::enable('model_has_permissions');

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->uuid('role_id');
            $table->string('model_type');
            $table->uuid('model_uuid');
            $table->uuid('company_id');
            $table->index(['company_id', 'model_uuid', 'model_type'], 'model_has_roles_model_idx');
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->primary(['company_id', 'role_id', 'model_uuid', 'model_type'], 'model_has_roles_role_model_type_primary');
        });
        Rls::enable('model_has_roles');

        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->uuid('role_id');
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id'], 'role_has_permissions_permission_id_role_id_primary');
        });
        Rls::grant('role_has_permissions');
    }

    public function down(): void
    {
        foreach (['role_has_permissions', 'model_has_roles', 'model_has_permissions', 'roles', 'permissions', 'personal_access_tokens', 'role_scopes', 'company_users'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
