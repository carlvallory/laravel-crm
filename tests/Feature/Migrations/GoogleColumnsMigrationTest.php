<?php

namespace Tests\Feature\Migrations;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GoogleColumnsMigrationTest extends TestCase
{
    public function test_users_table_has_google_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'google_id'));
        $this->assertTrue(Schema::hasColumn('users', 'auth_provider'));
    }
}
