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

        $pending = new User([
            'name' => 'Pend', 'email' => 'pend@gmail.com', 'status' => 0,
            'role_id' => $basico->id,
        ]);
        $pending->auth_provider = 'google';
        $pending->google_id     = 'g-200';
        $pending->save();

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

    public function test_approving_already_active_user_returns_404(): void
    {
        $role = Role::firstOrCreate(['name' => 'Administrator'], ['permission_type' => 'all']);

        $activeUser = User::create([
            'name' => 'Active', 'email' => 'active-f3@muci.org', 'status' => 1,
            'password' => bcrypt('secret'), 'role_id' => $role->id,
        ]);

        $response = $this->actingAs($this->admin(), 'user')
            ->post(route('google-auth.pending.approve', $activeUser->id));

        $response->assertStatus(404);
    }

    public function test_approving_non_google_pending_user_returns_404(): void
    {
        $role = Role::firstOrCreate(['name' => 'Administrator'], ['permission_type' => 'all']);

        $nativeUser = User::create([
            'name' => 'NativeUser', 'email' => 'native-f3@muci.org', 'status' => 0,
            'password' => bcrypt('secret'), 'role_id' => $role->id,
        ]);

        $response = $this->actingAs($this->admin(), 'user')
            ->post(route('google-auth.pending.approve', $nativeUser->id));

        $response->assertStatus(404);
    }

    public function test_basico_user_cannot_access_pending_list(): void
    {
        $basico = Role::firstOrCreate(['name' => 'Básico'], [
            'permission_type' => 'custom',
            'permissions'     => ['dashboard'],
        ]);

        $basicoUser = User::create([
            'name' => 'Basico User', 'email' => 'basico-f2@muci.org', 'status' => 1,
            'password' => bcrypt('secret'), 'role_id' => $basico->id,
        ]);

        $response = $this->actingAs($basicoUser, 'user')
            ->get(route('google-auth.pending.index'));

        $response->assertStatus(401);
    }

    public function test_basico_user_cannot_approve_pending_user(): void
    {
        $basico = Role::firstOrCreate(['name' => 'Básico'], [
            'permission_type' => 'custom',
            'permissions'     => ['dashboard'],
        ]);

        $basicoUser = User::create([
            'name' => 'Basico User2', 'email' => 'basico-f2b@muci.org', 'status' => 1,
            'password' => bcrypt('secret'), 'role_id' => $basico->id,
        ]);

        $pending = new User([
            'name' => 'Pending', 'email' => 'pending-f2@gmail.com', 'status' => 0,
            'role_id' => $basico->id,
        ]);
        $pending->auth_provider = 'google';
        $pending->google_id     = 'g-f2';
        $pending->save();

        $response = $this->actingAs($basicoUser, 'user')
            ->post(route('google-auth.pending.approve', $pending->id));

        $response->assertStatus(401);
    }

    public function test_custom_role_with_exact_google_pending_key_can_list_pending(): void
    {
        // Positive: a role with permission_type='custom' and EXACTLY ['settings.user.users.google_pending']
        // must pass both middleware (ACL key match) and the controller guard (same key).
        $role = Role::firstOrCreate(['name' => 'Google Approver'], [
            'permission_type' => 'custom',
            'permissions'     => ['settings.user.users.google_pending'],
        ]);

        $approver = User::create([
            'name' => 'Approver', 'email' => 'approver-f2c@muci.org', 'status' => 1,
            'password' => bcrypt('secret'), 'role_id' => $role->id,
        ]);

        $response = $this->actingAs($approver, 'user')
            ->get(route('google-auth.pending.index'));

        $response->assertStatus(200);
    }

    public function test_custom_role_with_exact_google_pending_key_can_approve_pending_user(): void
    {
        // Positive: same role as above should also be able to POST approve a pending Google user.
        $basico = Role::firstOrCreate(['name' => 'Básico'], [
            'permission_type' => 'custom',
            'permissions'     => ['dashboard'],
        ]);

        $role = Role::firstOrCreate(['name' => 'Google Approver'], [
            'permission_type' => 'custom',
            'permissions'     => ['settings.user.users.google_pending'],
        ]);

        $approver = User::create([
            'name' => 'Approver2', 'email' => 'approver2-f2d@muci.org', 'status' => 1,
            'password' => bcrypt('secret'), 'role_id' => $role->id,
        ]);

        $pending = new User([
            'name' => 'PendingG', 'email' => 'pending-f2d@gmail.com', 'status' => 0,
            'role_id' => $basico->id,
        ]);
        $pending->auth_provider = 'google';
        $pending->google_id     = 'g-f2d';
        $pending->save();

        $response = $this->actingAs($approver, 'user')
            ->post(route('google-auth.pending.approve', $pending->id));

        $response->assertRedirect();
        $this->assertEquals(1, $pending->fresh()->status);
    }
}
