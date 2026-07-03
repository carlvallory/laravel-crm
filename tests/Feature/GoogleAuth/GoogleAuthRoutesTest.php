<?php

namespace Tests\Feature\GoogleAuth;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Webkul\User\Models\Role;
use Tests\TestCase;

class GoogleAuthRoutesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Credenciales dummy para que Socialite pueda construir la URL de redirect en tests.
        config(['services.google' => [
            'client_id'     => 'test-client-id',
            'client_secret' => 'test-secret',
            'redirect'      => 'http://localhost/login/google/callback',
        ]]);

        Role::firstOrCreate(['name' => 'Básico'], [
            'permission_type' => 'custom', 'permissions' => ['dashboard'],
        ]);
    }

    public function test_redirect_route_sends_to_google(): void
    {
        $response = $this->get(route('google-auth.redirect'));
        $response->assertRedirect();
        $this->assertStringContainsString('accounts.google.com', $response->headers->get('Location'));
    }

    public function test_callback_logs_in_approved_muci_user(): void
    {
        $abstractUser = Mockery::mock(\Laravel\Socialite\Contracts\User::class);
        $abstractUser->shouldReceive('getId')->andReturn('g-99');
        $abstractUser->shouldReceive('getEmail')->andReturn('staff@muci.org');
        $abstractUser->shouldReceive('getName')->andReturn('Staff');
        $abstractUser->shouldReceive('getAvatar')->andReturn(null);
        $abstractUser->user = ['hd' => 'muci.org', 'email_verified' => true];

        $provider = Mockery::mock('Laravel\Socialite\Two\GoogleProvider');
        $provider->shouldReceive('user')->andReturn($abstractUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get(route('google-auth.callback'));
        $response->assertRedirect(route('admin.dashboard.index'));
        $this->assertAuthenticated('user');
    }

    public function test_callback_rejects_pending_external_user(): void
    {
        $abstractUser = Mockery::mock(\Laravel\Socialite\Contracts\User::class);
        $abstractUser->shouldReceive('getId')->andReturn('g-100');
        $abstractUser->shouldReceive('getEmail')->andReturn('ext@gmail.com');
        $abstractUser->shouldReceive('getName')->andReturn('Ext');
        $abstractUser->shouldReceive('getAvatar')->andReturn(null);
        $abstractUser->user = ['email_verified' => true];

        $provider = Mockery::mock('Laravel\Socialite\Two\GoogleProvider');
        $provider->shouldReceive('user')->andReturn($abstractUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get(route('google-auth.callback'));
        $response->assertRedirect(route('admin.session.create'));
        $this->assertGuest('user');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
