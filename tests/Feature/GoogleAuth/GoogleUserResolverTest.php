<?php

namespace Tests\Feature\GoogleAuth;

use CarlVallory\KrayinGoogleAuth\DataObjects\GoogleAccount;
use CarlVallory\KrayinGoogleAuth\Services\GoogleUserResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\User\Models\Role;
use Webkul\User\Models\User;
use Tests\TestCase;

class GoogleUserResolverTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'Básico'], [
            'permission_type' => 'custom',
            'permissions'     => ['dashboard'],
        ]);
    }

    private function resolver(): GoogleUserResolver
    {
        return app(GoogleUserResolver::class);
    }

    public function test_new_muci_user_is_auto_approved(): void
    {
        $result = $this->resolver()->resolve(new GoogleAccount(
            email: 'nuevo@muci.org', googleId: 'g-1', name: 'Nuevo', hostedDomain: 'muci.org'
        ));

        $this->assertTrue($result->allowed);
        $this->assertEquals(1, $result->user->status);
        $this->assertEquals('Básico', $result->user->role->name);
        $this->assertNull($result->user->password);
    }

    public function test_new_external_user_is_pending(): void
    {
        $result = $this->resolver()->resolve(new GoogleAccount(
            email: 'persona@gmail.com', googleId: 'g-2', name: 'Externa', hostedDomain: null
        ));

        $this->assertFalse($result->allowed);
        $this->assertEquals('pending', $result->reason);
        $this->assertEquals(0, $result->user->status);
    }

    public function test_existing_active_user_is_linked_and_allowed(): void
    {
        $user = User::create([
            'name' => 'Pre', 'email' => 'pre@otra.com', 'status' => 1,
            'role_id' => Role::where('name', 'Básico')->first()->id,
        ]);

        $result = $this->resolver()->resolve(new GoogleAccount(
            email: 'pre@otra.com', googleId: 'g-3', name: 'Pre', hostedDomain: 'otra.com'
        ));

        $this->assertTrue($result->allowed);
        $this->assertEquals($user->id, $result->user->id);
        $this->assertEquals('g-3', $result->user->fresh()->google_id);
    }

    public function test_existing_pending_user_is_rejected(): void
    {
        User::create([
            'name' => 'Pend', 'email' => 'pend@gmail.com', 'status' => 0,
            'auth_provider' => 'google', 'google_id' => 'g-4',
            'role_id' => Role::where('name', 'Básico')->first()->id,
        ]);

        $result = $this->resolver()->resolve(new GoogleAccount(
            email: 'pend@gmail.com', googleId: 'g-4', name: 'Pend', hostedDomain: null
        ));

        $this->assertFalse($result->allowed);
        $this->assertEquals('pending', $result->reason);
    }

    public function test_lookalike_email_without_hd_is_treated_as_external(): void
    {
        $result = $this->resolver()->resolve(new GoogleAccount(
            email: 'fake@muci.org.evil.com', googleId: 'g-5', name: 'Fake', hostedDomain: null
        ));

        $this->assertFalse($result->allowed);
        $this->assertEquals(0, $result->user->status);
    }
}
