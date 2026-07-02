<?php

namespace Tests\Feature\GoogleAuth;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\User\Models\Role;
use Webkul\User\Models\User;
use Tests\TestCase;

class UninstallCommandTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Abort: rol de respaldo inexistente + usuarios con Básico → FAILURE, nada se toca.
     * No hace DDL, seguro con DatabaseTransactions.
     */
    public function test_uninstall_aborts_when_fallback_role_missing_and_users_exist(): void
    {
        $basico = Role::firstOrCreate(['name' => 'Básico'], [
            'permission_type' => 'custom', 'permissions' => ['dashboard'],
        ]);

        User::create([
            'name'  => 'Usuario Básico',
            'email' => 'basico-orphan@muci.org',
            'status' => 1,
            'password' => bcrypt('secret'),
            'role_id' => $basico->id,
        ]);

        config(['google-auth.uninstall_fallback_role' => 'NoExiste']);

        $this->artisan('google-auth:uninstall')->assertExitCode(1);

        // Rol Básico sigue existiendo — no fue borrado.
        $this->assertNotNull(DB::table('roles')->where('name', 'Básico')->first());
        // Columnas siguen existiendo — DDL no fue ejecutado.
        $this->assertTrue(Schema::hasColumn('users', 'google_id'));
        $this->assertTrue(Schema::hasColumn('users', 'auth_provider'));
    }

    /**
     * Happy path: rol Básico existe con un usuario asignado → usuario reasignado
     * a Administrator, rol borrado, columnas eliminadas.
     * ADVERTENCIA: hace DDL real (no transaccional). Restaurar esquema después.
     */
    public function test_uninstall_removes_role_and_columns(): void
    {
        $administrator = Role::firstOrCreate(['name' => 'Administrator'], ['permission_type' => 'all']);

        $basico = Role::firstOrCreate(['name' => 'Básico'], [
            'permission_type' => 'custom', 'permissions' => ['dashboard'],
        ]);

        $user = User::create([
            'name'  => 'Usuario Básico',
            'email' => 'basico-happy@muci.org',
            'status' => 1,
            'password' => bcrypt('secret'),
            'role_id' => $basico->id,
        ]);

        $this->artisan('google-auth:uninstall')->assertExitCode(0);

        // Rol Básico fue eliminado.
        $this->assertNull(DB::table('roles')->where('name', 'Básico')->first());
        // El usuario fue reasignado al rol Administrator.
        $this->assertEquals($administrator->id, DB::table('users')->where('id', $user->id)->value('role_id'));
        // Columnas eliminadas.
        $this->assertFalse(Schema::hasColumn('users', 'google_id'));
        $this->assertFalse(Schema::hasColumn('users', 'auth_provider'));
    }
}
