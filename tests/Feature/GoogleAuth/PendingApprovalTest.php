<?php

namespace Tests\Feature\GoogleAuth;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\User\Models\Role;
use Webkul\User\Models\User;
use Tests\TestCase;

class PendingApprovalTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        $role = Role::firstOrCreate(['name' => 'Administrator'], ['permission_type' => 'all']);

        return User::create([
            'name' => 'Admin', 'email' => 'admin-test@muci.org', 'status' => 1,
            'password' => bcrypt('secret'), 'role_id' => $role->id,
        ]);
    }

    public function test_admin_can_approve_a_pending_user(): void
    {
        $basico = Role::firstOrCreate(['name' => 'Básico'], [
            'permission_type' => 'custom', 'permissions' => ['dashboard'],
        ]);

        $pending = User::create([
            'name' => 'Pend', 'email' => 'pend@gmail.com', 'status' => 0,
            'auth_provider' => 'google', 'google_id' => 'g-200', 'role_id' => $basico->id,
        ]);

        $response = $this->actingAs($this->admin(), 'user')
            ->post(route('google-auth.pending.approve', $pending->id));

        $response->assertRedirect();
        $this->assertEquals(1, $pending->fresh()->status);
    }

    public function test_guest_cannot_access_pending_list(): void
    {
        $response = $this->get(route('google-auth.pending.index'));
        $response->assertRedirect(route('admin.session.create'));
    }
}
