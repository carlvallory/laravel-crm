<?php

namespace Tests\Feature\GoogleAuth;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UninstallCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_uninstall_removes_role_and_columns(): void
    {
        $this->artisan('google-auth:uninstall')->assertExitCode(0);

        $this->assertNull(DB::table('roles')->where('name', 'Básico')->first());
        $this->assertFalse(Schema::hasColumn('users', 'google_id'));
        $this->assertFalse(Schema::hasColumn('users', 'auth_provider'));
    }
}
