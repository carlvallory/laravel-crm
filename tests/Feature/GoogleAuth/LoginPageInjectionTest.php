<?php

namespace Tests\Feature\GoogleAuth;

use Tests\TestCase;

class LoginPageInjectionTest extends TestCase
{
    public function test_login_page_shows_google_button(): void
    {
        $response = $this->get(route('admin.session.create'));
        $response->assertStatus(200);
        $response->assertSee('Entrar con Google');
        $response->assertSee(route('google-auth.redirect'));
    }
}
