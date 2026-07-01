<?php

namespace Tests\Feature\Migrations;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BasicoRoleSeedTest extends TestCase
{
    public function test_basico_role_exists_with_custom_permission_type(): void
    {
        $role = DB::table('roles')->where('name', 'Básico')->first();

        $this->assertNotNull($role, 'El rol Básico debe existir');
        $this->assertEquals('custom', $role->permission_type);

        $permissions = json_decode($role->permissions, true);
        $this->assertIsArray($permissions);
        $this->assertContains('dashboard', $permissions);
        $this->assertNotContains('leads.delete', $permissions, 'Básico no debe poder borrar');
    }
}
