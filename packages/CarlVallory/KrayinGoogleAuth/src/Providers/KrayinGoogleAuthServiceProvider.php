<?php

namespace CarlVallory\KrayinGoogleAuth\Providers;

use Illuminate\Support\ServiceProvider;

class KrayinGoogleAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../Config/google-auth.php', 'google-auth');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');

        // Empuja las credenciales a services.google para Socialite sin editar config/services.php
        config(['services.google' => config('google-auth.credentials')]);
    }
}
