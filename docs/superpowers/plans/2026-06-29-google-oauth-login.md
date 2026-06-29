# Google OAuth Login (KrayinGoogleAuth) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir login al CRM (Krayin admin) vía Google OAuth — @muci.org auto-aprobado, otros dominios pendientes de aprobación admin — en un paquete propio desinstalable, sin tocar el core.

**Architecture:** Paquete Laravel `CarlVallory/KrayinGoogleAuth` registrado vía el autodiscovery de Laravel. Usa `laravel/socialite` para el flujo Google. Inyecta el botón en el login mediante el hook `view_render_event` existente (sin modificar Blade del core). Reutiliza la tabla `users` (password ya nullable, columna `status` para pendiente/aprobado) y agrega 2 columnas + un rol "Básico" sembrado, todo reversible por `down()`.

**Tech Stack:** PHP 8.1+, Laravel 10, Krayin CRM, Laravel Socialite ^5.0, PHPUnit con `DatabaseTransactions`.

## Global Constraints

- **No modificar el core:** nada en `packages/Webkul/*`, `vendor/*`, `config/auth.php`, la vista de login ni el modelo `User`. Todo en `packages/CarlVallory/KrayinGoogleAuth`.
- **Reversible por desinstalación:** toda migración con `down()` completo; comando `google-auth:uninstall`.
- **Guard de auth:** `auth()->guard('user')` (no `web`).
- **Validación de dominio:** contra el claim `hd` de Google Workspace, nunca el texto del email.
- **Logging:** `try/catch` robusto; no saturar Sentry (solo fallos críticos).
- **Naming:** namespace `CarlVallory\KrayinGoogleAuth\`; nombre composer `carlvallory/krayin-google-auth`.
- **git add explícito:** nunca `git add -A` en `laravel-crm` (se cuela un gitlink de `packages/Vallory/KrayinFormatter`).
- **Estándar visual MuCi:** botón en tipografía Poppins y paleta de marca (#F17DB1, #00B26B, #000000, #6950A1, #F37043).
- **Tests:** usar `DatabaseTransactions` (rollback; no ensucian la BD local ya migrada).

---

### Task 1: Scaffold del paquete + registro + Socialite

**Files:**
- Create: `packages/CarlVallory/KrayinGoogleAuth/composer.json`
- Create: `packages/CarlVallory/KrayinGoogleAuth/src/Providers/KrayinGoogleAuthServiceProvider.php`
- Create: `packages/CarlVallory/KrayinGoogleAuth/src/Config/google-auth.php`
- Modify: `composer.json` (root) — agregar `laravel/socialite` y `carlvallory/krayin-google-auth`

**Interfaces:**
- Produces: config key `google-auth` con `allowed_domains`, `default_role_name`, `show_password_login`, `uninstall_fallback_role`, `credentials`. Provider `KrayinGoogleAuthServiceProvider`.

- [ ] **Step 1: Crear `composer.json` del paquete**

```json
{
    "name": "carlvallory/krayin-google-auth",
    "description": "Login con Google OAuth para Krayin CRM (MuCi)",
    "license": "MIT",
    "authors": [
        { "name": "Carl Vallory", "email": "carlos@muci.org" }
    ],
    "require": {
        "laravel/socialite": "^5.0"
    },
    "autoload": {
        "psr-4": {
            "CarlVallory\\KrayinGoogleAuth\\": "src/"
        }
    },
    "extra": {
        "laravel": {
            "providers": [
                "CarlVallory\\KrayinGoogleAuth\\Providers\\KrayinGoogleAuthServiceProvider"
            ],
            "aliases": {}
        }
    },
    "minimum-stability": "dev"
}
```

- [ ] **Step 2: Crear `src/Config/google-auth.php`**

```php
<?php

return [
    'allowed_domains'         => ['muci.org'],
    'default_role_name'       => 'Básico',
    'show_password_login'     => env('GOOGLE_AUTH_SHOW_PASSWORD_LOGIN', false),
    'uninstall_fallback_role' => 'Administrator',

    'credentials' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_REDIRECT_URI'),
    ],
];
```

- [ ] **Step 3: Crear el ServiceProvider (mínimo, se amplía en tareas siguientes)**

```php
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
```

- [ ] **Step 4: Registrar el paquete y Socialite en el `composer.json` raíz**

En la sección `require`, agregar (en orden alfabético dentro de los `carlvallory/*`):
```json
"carlvallory/krayin-google-auth": "@dev",
```
Y junto a las demás dependencias:
```json
"laravel/socialite": "^5.0",
```

- [ ] **Step 5: Instalar y verificar que bootea**

Run:
```bash
cd /home/vallory/code/crm/laravel-crm
composer require laravel/socialite:^5.0 --no-interaction
composer dump-autoload
php artisan package:discover --ansi
php artisan tinker --execute="echo config('google-auth.default_role_name');"
```
Expected: imprime `Básico` sin errores de boot ni de provider.

- [ ] **Step 6: Commit**

```bash
git add packages/CarlVallory/KrayinGoogleAuth/composer.json \
        packages/CarlVallory/KrayinGoogleAuth/src/Providers/KrayinGoogleAuthServiceProvider.php \
        packages/CarlVallory/KrayinGoogleAuth/src/Config/google-auth.php \
        composer.json composer.lock
git commit -m "feat(google-auth): scaffold del paquete KrayinGoogleAuth + Socialite"
```

---

### Task 2: Migración — columnas `google_id` y `auth_provider` en `users`

**Files:**
- Create: `packages/CarlVallory/KrayinGoogleAuth/src/Database/Migrations/2026_06_29_100000_add_google_columns_to_users_table.php`
- Test: `packages/CarlVallory/KrayinGoogleAuth/tests/Migrations/GoogleColumnsMigrationTest.php`

**Interfaces:**
- Produces: `users.google_id` (string nullable unique), `users.auth_provider` (string nullable).

- [ ] **Step 1: Escribir el test (migración reversible)**

```php
<?php

namespace CarlVallory\KrayinGoogleAuth\Tests\Migrations;

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
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --filter=GoogleColumnsMigrationTest`
Expected: FAIL — las columnas no existen todavía.

- [ ] **Step 3: Escribir la migración**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'google_id')) {
                $table->string('google_id')->nullable()->unique()->after('email');
            }
            if (! Schema::hasColumn('users', 'auth_provider')) {
                $table->string('auth_provider')->nullable()->after('google_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'google_id')) {
                $table->dropUnique('users_google_id_unique');
                $table->dropColumn('google_id');
            }
            if (Schema::hasColumn('users', 'auth_provider')) {
                $table->dropColumn('auth_provider');
            }
        });
    }
};
```

- [ ] **Step 4: Aplicar la migración y correr el test**

Run:
```bash
php artisan migrate --force
php artisan test --filter=GoogleColumnsMigrationTest
```
Expected: migración corre OK; test PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/CarlVallory/KrayinGoogleAuth/src/Database/Migrations/2026_06_29_100000_add_google_columns_to_users_table.php \
        packages/CarlVallory/KrayinGoogleAuth/tests/Migrations/GoogleColumnsMigrationTest.php
git commit -m "feat(google-auth): columnas google_id y auth_provider en users (reversible)"
```

---

### Task 3: Migración — sembrar rol "Básico" (solo lectura)

**Files:**
- Create: `packages/CarlVallory/KrayinGoogleAuth/src/Database/Migrations/2026_06_29_100100_seed_basico_role.php`
- Test: `packages/CarlVallory/KrayinGoogleAuth/tests/Migrations/BasicoRoleSeedTest.php`

**Interfaces:**
- Produces: fila en `roles` con `name='Básico'`, `permission_type='custom'`, `permissions` = set solo-lectura.

- [ ] **Step 1: Confirmar las claves de permiso reales**

Run: `grep -E "'key'\s*=>" packages/Webkul/Admin/src/Config/acl.php | head -60`
Expected: lista de claves (`dashboard`, `leads`, `leads.view`, `contacts.persons`, etc.). Usar SOLO claves de lectura/navegación existentes para el array de abajo; ajustar si alguna no existe textualmente.

- [ ] **Step 2: Escribir el test**

```php
<?php

namespace CarlVallory\KrayinGoogleAuth\Tests\Migrations;

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
```

- [ ] **Step 3: Correr el test y verificar que falla**

Run: `php artisan test --filter=BasicoRoleSeedTest`
Expected: FAIL — el rol no existe.

- [ ] **Step 4: Escribir la migración**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

return new class extends Migration
{
    private array $permissions = [
        'dashboard',
        'leads',
        'leads.view',
        'contacts',
        'contacts.persons',
        'contacts.persons.view',
        'contacts.organizations',
        'contacts.organizations.view',
    ];

    public function up(): void
    {
        $exists = DB::table('roles')->where('name', 'Básico')->exists();

        if (! $exists) {
            DB::table('roles')->insert([
                'name'            => 'Básico',
                'description'     => 'Rol por defecto de bajo privilegio (solo lectura) para usuarios que ingresan por Google OAuth.',
                'permission_type' => 'custom',
                'permissions'     => json_encode($this->permissions),
                'created_at'      => Carbon::now(),
                'updated_at'      => Carbon::now(),
            ]);
        }
    }

    public function down(): void
    {
        $basico = DB::table('roles')->where('name', 'Básico')->first();

        if (! $basico) {
            return;
        }

        // Reasigna usuarios del rol Básico a un rol de respaldo antes de borrarlo (evita role_id huérfano).
        $fallbackName = config('google-auth.uninstall_fallback_role', 'Administrator');
        $fallback = DB::table('roles')->where('name', $fallbackName)->first();

        if ($fallback) {
            DB::table('users')->where('role_id', $basico->id)->update(['role_id' => $fallback->id]);
        }

        DB::table('roles')->where('id', $basico->id)->delete();
    }
};
```

- [ ] **Step 5: Aplicar y correr el test**

Run:
```bash
php artisan migrate --force
php artisan test --filter=BasicoRoleSeedTest
```
Expected: test PASS.

- [ ] **Step 6: Commit**

```bash
git add packages/CarlVallory/KrayinGoogleAuth/src/Database/Migrations/2026_06_29_100100_seed_basico_role.php \
        packages/CarlVallory/KrayinGoogleAuth/tests/Migrations/BasicoRoleSeedTest.php
git commit -m "feat(google-auth): sembrar rol Basico solo-lectura (reversible con reasignacion)"
```

---

### Task 4: `GoogleAccount` DTO + `GoogleUserResolver` (lógica central)

**Files:**
- Create: `packages/CarlVallory/KrayinGoogleAuth/src/DataObjects/GoogleAccount.php`
- Create: `packages/CarlVallory/KrayinGoogleAuth/src/DataObjects/ResolutionResult.php`
- Create: `packages/CarlVallory/KrayinGoogleAuth/src/Services/GoogleUserResolver.php`
- Test: `packages/CarlVallory/KrayinGoogleAuth/tests/Services/GoogleUserResolverTest.php`

**Interfaces:**
- Consumes: modelo `Webkul\User\Models\User`, `Webkul\User\Models\Role`.
- Produces:
  - `GoogleAccount` readonly: `email:string`, `googleId:string`, `name:string`, `avatar:?string`, `hostedDomain:?string`.
  - `ResolutionResult` readonly: `user:User`, `allowed:bool`, `reason:?string` (`'pending'` cuando no allowed).
  - `GoogleUserResolver::resolve(GoogleAccount $account): ResolutionResult`.

- [ ] **Step 1: Escribir los DTOs**

`src/DataObjects/GoogleAccount.php`:
```php
<?php

namespace CarlVallory\KrayinGoogleAuth\DataObjects;

class GoogleAccount
{
    public function __construct(
        public readonly string $email,
        public readonly string $googleId,
        public readonly string $name,
        public readonly ?string $avatar = null,
        public readonly ?string $hostedDomain = null,
    ) {}
}
```

`src/DataObjects/ResolutionResult.php`:
```php
<?php

namespace CarlVallory\KrayinGoogleAuth\DataObjects;

use Webkul\User\Models\User;

class ResolutionResult
{
    public function __construct(
        public readonly User $user,
        public readonly bool $allowed,
        public readonly ?string $reason = null,
    ) {}
}
```

- [ ] **Step 2: Escribir el test de los 5 escenarios**

```php
<?php

namespace CarlVallory\KrayinGoogleAuth\Tests\Services;

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
```

- [ ] **Step 3: Correr el test y verificar que falla**

Run: `php artisan test --filter=GoogleUserResolverTest`
Expected: FAIL — `GoogleUserResolver` no existe.

- [ ] **Step 4: Implementar el resolver**

`src/Services/GoogleUserResolver.php`:
```php
<?php

namespace CarlVallory\KrayinGoogleAuth\Services;

use CarlVallory\KrayinGoogleAuth\DataObjects\GoogleAccount;
use CarlVallory\KrayinGoogleAuth\DataObjects\ResolutionResult;
use Webkul\User\Models\Role;
use Webkul\User\Models\User;

class GoogleUserResolver
{
    public function resolve(GoogleAccount $account): ResolutionResult
    {
        $user = User::where('google_id', $account->googleId)->first()
            ?? User::where('email', $account->email)->first();

        if ($user) {
            if (! $user->google_id) {
                $user->google_id = $account->googleId;
                $user->save();
            }

            $allowed = (int) $user->status === 1;

            return new ResolutionResult($user, $allowed, $allowed ? null : 'pending');
        }

        $allowedDomains = config('google-auth.allowed_domains', []);
        $isAllowedDomain = $account->hostedDomain !== null
            && in_array($account->hostedDomain, $allowedDomains, true);

        $role = Role::where('name', config('google-auth.default_role_name'))->first();

        $user = User::create([
            'name'          => $account->name,
            'email'         => $account->email,
            'google_id'     => $account->googleId,
            'image'         => $account->avatar,
            'auth_provider' => 'google',
            'role_id'       => $role?->id,
            'status'        => $isAllowedDomain ? 1 : 0,
            'password'      => null,
        ]);

        return new ResolutionResult($user, $isAllowedDomain, $isAllowedDomain ? null : 'pending');
    }
}
```

- [ ] **Step 5: Correr el test y verificar que pasa**

Run: `php artisan test --filter=GoogleUserResolverTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Commit**

```bash
git add packages/CarlVallory/KrayinGoogleAuth/src/DataObjects/ \
        packages/CarlVallory/KrayinGoogleAuth/src/Services/GoogleUserResolver.php \
        packages/CarlVallory/KrayinGoogleAuth/tests/Services/GoogleUserResolverTest.php
git commit -m "feat(google-auth): GoogleUserResolver con logica dominio/pendiente/aprobado"
```

---

### Task 5: Controller + rutas (redirect + callback)

**Files:**
- Create: `packages/CarlVallory/KrayinGoogleAuth/src/Http/Controllers/GoogleAuthController.php`
- Create: `packages/CarlVallory/KrayinGoogleAuth/src/Routes/routes.php`
- Modify: `packages/CarlVallory/KrayinGoogleAuth/src/Providers/KrayinGoogleAuthServiceProvider.php` (cargar rutas)
- Test: `packages/CarlVallory/KrayinGoogleAuth/tests/Feature/GoogleAuthRoutesTest.php`

**Interfaces:**
- Consumes: `GoogleUserResolver`, `GoogleAccount`, Socialite.
- Produces: rutas `google-auth.redirect` (`GET /login/google`) y `google-auth.callback` (`GET /login/google/callback`).

- [ ] **Step 1: Escribir el test de rutas**

```php
<?php

namespace CarlVallory\KrayinGoogleAuth\Tests\Feature;

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
        $abstractUser->user = ['hd' => 'muci.org'];

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
        $abstractUser->user = [];

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
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --filter=GoogleAuthRoutesTest`
Expected: FAIL — rutas/controller no existen.

- [ ] **Step 3: Escribir el controller**

`src/Http/Controllers/GoogleAuthController.php`:
```php
<?php

namespace CarlVallory\KrayinGoogleAuth\Http\Controllers;

use CarlVallory\KrayinGoogleAuth\DataObjects\GoogleAccount;
use CarlVallory\KrayinGoogleAuth\Services\GoogleUserResolver;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    public function __construct(private GoogleUserResolver $resolver) {}

    public function redirect()
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback()
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Throwable $e) {
            Log::error('[google-auth] callback fallo: ' . $e->getMessage());
            session()->flash('error', 'No se pudo completar el ingreso con Google. Intenta de nuevo.');

            return redirect()->route('admin.session.create');
        }

        if (! $googleUser->getEmail()) {
            session()->flash('error', 'Tu cuenta de Google no expone un correo válido.');

            return redirect()->route('admin.session.create');
        }

        $account = new GoogleAccount(
            email: $googleUser->getEmail(),
            googleId: $googleUser->getId(),
            name: $googleUser->getName() ?: $googleUser->getEmail(),
            avatar: $googleUser->getAvatar(),
            hostedDomain: is_array($googleUser->user ?? null) ? ($googleUser->user['hd'] ?? null) : null,
        );

        $result = $this->resolver->resolve($account);

        if (! $result->allowed) {
            session()->flash('warning', 'Tu acceso está pendiente de aprobación de un administrador.');

            return redirect()->route('admin.session.create');
        }

        auth()->guard('user')->login($result->user);

        return redirect()->intended(route('admin.dashboard.index'));
    }
}
```

- [ ] **Step 4: Escribir las rutas**

`src/Routes/routes.php`:
```php
<?php

use CarlVallory\KrayinGoogleAuth\Http\Controllers\GoogleAuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function () {
    Route::get('/login/google', [GoogleAuthController::class, 'redirect'])->name('google-auth.redirect');
    Route::get('/login/google/callback', [GoogleAuthController::class, 'callback'])->name('google-auth.callback');
});
```

- [ ] **Step 5: Cargar las rutas en el provider**

En `boot()` de `KrayinGoogleAuthServiceProvider`, agregar:
```php
$this->loadRoutesFrom(__DIR__ . '/../Routes/routes.php');
```

- [ ] **Step 6: Correr el test y verificar que pasa**

Run: `php artisan test --filter=GoogleAuthRoutesTest`
Expected: PASS (3 tests). Si el redirect requiere credenciales, exportar dummies antes: `GOOGLE_CLIENT_ID=x GOOGLE_CLIENT_SECRET=y GOOGLE_REDIRECT_URI=http://localhost/login/google/callback`.

- [ ] **Step 7: Commit**

```bash
git add packages/CarlVallory/KrayinGoogleAuth/src/Http/ \
        packages/CarlVallory/KrayinGoogleAuth/src/Routes/ \
        packages/CarlVallory/KrayinGoogleAuth/src/Providers/KrayinGoogleAuthServiceProvider.php \
        packages/CarlVallory/KrayinGoogleAuth/tests/Feature/GoogleAuthRoutesTest.php
git commit -m "feat(google-auth): controller + rutas redirect/callback"
```

---

### Task 6: Frontend — inyectar botón Google y ocultar form nativo

**Files:**
- Create: `packages/CarlVallory/KrayinGoogleAuth/src/Resources/views/google-button.blade.php`
- Modify: `packages/CarlVallory/KrayinGoogleAuth/src/Providers/KrayinGoogleAuthServiceProvider.php` (loadViewsFrom + Event::listen)
- Test: `packages/CarlVallory/KrayinGoogleAuth/tests/Feature/LoginPageInjectionTest.php`

**Interfaces:**
- Consumes: hook `admin.sessions.login.form_controls.before`, namespace de vistas `google-auth`.

- [ ] **Step 1: Escribir el test de la página de login**

```php
<?php

namespace CarlVallory\KrayinGoogleAuth\Tests\Feature;

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
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --filter=LoginPageInjectionTest`
Expected: FAIL — el botón no aparece.

- [ ] **Step 3: Escribir el partial Blade**

`src/Resources/views/google-button.blade.php`:
```blade
@php($showPassword = config('google-auth.show_password_login'))

@unless($showPassword)
    <style>
        form[action="{{ route('admin.session.store') }}"] .form-container,
        form[action="{{ route('admin.session.store') }}"] button[type="submit"] {
            display: none !important;
        }
    </style>
@endunless

<div style="font-family: 'Poppins', sans-serif; margin-bottom: 16px;">
    @if(session('warning'))
        <p style="color:#F37043; font-weight:600;">{{ session('warning') }}</p>
    @endif
    @if(session('error'))
        <p style="color:#F37043; font-weight:600;">{{ session('error') }}</p>
    @endif

    <a href="{{ route('google-auth.redirect') }}"
       style="display:flex; align-items:center; justify-content:center; gap:8px;
              background:#6950A1; color:#fff; font-weight:700; text-decoration:none;
              padding:12px 16px; border-radius:8px;">
        <img src="https://www.gstatic.com/firebasejs/ui/2.0.0/images/auth/google.svg" alt="" width="18" height="18" style="background:#fff;border-radius:2px;padding:2px;">
        Entrar con Google
    </a>
</div>
```

> Nota: el selector CSS de ocultamiento puede necesitar ajuste según el markup real del login (`login.blade.php`). Verificar las clases reales en `packages/Webkul/Admin/src/Resources/views/sessions/login.blade.php` y afinar el selector para ocultar inputs de email/password y el botón submit.

- [ ] **Step 4: Registrar vista + listener en el provider**

En `boot()`:
```php
$this->loadViewsFrom(__DIR__ . '/../Resources/views', 'google-auth');

\Illuminate\Support\Facades\Event::listen(
    'admin.sessions.login.form_controls.before',
    function ($viewRenderEventManager) {
        $viewRenderEventManager->addTemplate('google-auth::google-button');
    }
);
```

- [ ] **Step 5: Correr el test y verificar que pasa**

Run: `php artisan test --filter=LoginPageInjectionTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add packages/CarlVallory/KrayinGoogleAuth/src/Resources/ \
        packages/CarlVallory/KrayinGoogleAuth/src/Providers/KrayinGoogleAuthServiceProvider.php \
        packages/CarlVallory/KrayinGoogleAuth/tests/Feature/LoginPageInjectionTest.php
git commit -m "feat(google-auth): boton Google en login + ocultar form nativo (toggle de emergencia)"
```

---

### Task 7: UX de aprobación de pendientes

**Files:**
- Create: `packages/CarlVallory/KrayinGoogleAuth/src/Http/Controllers/PendingUserController.php`
- Create: `packages/CarlVallory/KrayinGoogleAuth/src/Resources/views/pending/index.blade.php`
- Modify: `packages/CarlVallory/KrayinGoogleAuth/src/Routes/routes.php` (rutas admin protegidas)
- Test: `packages/CarlVallory/KrayinGoogleAuth/tests/Feature/PendingApprovalTest.php`

**Interfaces:**
- Consumes: guard `user`, modelo `User`.
- Produces: rutas `google-auth.pending.index` (`GET`) y `google-auth.pending.approve` (`POST`), bajo middleware `user`.

- [ ] **Step 1: Escribir el test de aprobación**

```php
<?php

namespace CarlVallory\KrayinGoogleAuth\Tests\Feature;

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
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --filter=PendingApprovalTest`
Expected: FAIL — rutas/controller no existen.

- [ ] **Step 3: Escribir el controller**

`src/Http/Controllers/PendingUserController.php`:
```php
<?php

namespace CarlVallory\KrayinGoogleAuth\Http\Controllers;

use Illuminate\Routing\Controller;
use Webkul\User\Models\User;

class PendingUserController extends Controller
{
    public function index()
    {
        $pending = User::where('auth_provider', 'google')
            ->where('status', 0)
            ->get();

        return view('google-auth::pending.index', compact('pending'));
    }

    public function approve(int $id)
    {
        $user = User::findOrFail($id);
        $user->status = 1;
        $user->save();

        session()->flash('success', 'Usuario aprobado.');

        return redirect()->route('google-auth.pending.index');
    }
}
```

- [ ] **Step 4: Agregar las rutas protegidas**

En `src/Routes/routes.php`, agregar dentro del grupo `web` un subgrupo con middleware `user`:
```php
Route::middleware(['web', 'user'])->prefix('admin/google-auth')->group(function () {
    Route::get('/pending', [\CarlVallory\KrayinGoogleAuth\Http\Controllers\PendingUserController::class, 'index'])
        ->name('google-auth.pending.index');
    Route::post('/pending/{id}/approve', [\CarlVallory\KrayinGoogleAuth\Http\Controllers\PendingUserController::class, 'approve'])
        ->name('google-auth.pending.approve');
});
```
> Verificar el alias del middleware de auth admin: en `config/concord` / Kernel el guard es `user`; el middleware aplicado en rutas admin de Krayin es el que protege `admin.*`. Si Krayin usa un alias distinto (p.ej. `auth:user`), usar ese en vez de `user`.

- [ ] **Step 5: Escribir la vista mínima**

`src/Resources/views/pending/index.blade.php`:
```blade
<x-admin::layouts>
    <x-slot:title>Aprobaciones pendientes</x-slot:title>

    <div style="font-family:'Poppins',sans-serif; padding:16px;">
        <h1 style="font-weight:700;">Usuarios pendientes de aprobación</h1>

        @if(session('success'))
            <p style="color:#00B26B; font-weight:600;">{{ session('success') }}</p>
        @endif

        <table style="width:100%; margin-top:12px;">
            <thead><tr><th>Nombre</th><th>Correo</th><th></th></tr></thead>
            <tbody>
            @forelse($pending as $user)
                <tr>
                    <td>{{ $user->name }}</td>
                    <td>{{ $user->email }}</td>
                    <td>
                        <form method="POST" action="{{ route('google-auth.pending.approve', $user->id) }}">
                            @csrf
                            <button type="submit" style="background:#00B26B; color:#fff; font-weight:700; border:0; padding:8px 12px; border-radius:6px;">
                                Aprobar
                            </button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="3">No hay usuarios pendientes.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-admin::layouts>
```

- [ ] **Step 6: Correr el test y verificar que pasa**

Run: `php artisan test --filter=PendingApprovalTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add packages/CarlVallory/KrayinGoogleAuth/src/Http/Controllers/PendingUserController.php \
        packages/CarlVallory/KrayinGoogleAuth/src/Resources/views/pending/ \
        packages/CarlVallory/KrayinGoogleAuth/src/Routes/routes.php \
        packages/CarlVallory/KrayinGoogleAuth/tests/Feature/PendingApprovalTest.php
git commit -m "feat(google-auth): pantalla y accion de aprobacion de usuarios pendientes"
```

---

### Task 8: Comando de desinstalación

**Files:**
- Create: `packages/CarlVallory/KrayinGoogleAuth/src/Console/UninstallCommand.php`
- Modify: `packages/CarlVallory/KrayinGoogleAuth/src/Providers/KrayinGoogleAuthServiceProvider.php` (registrar comando)
- Test: `packages/CarlVallory/KrayinGoogleAuth/tests/Feature/UninstallCommandTest.php`

**Interfaces:**
- Consumes: las migraciones de Task 2 y 3.
- Produces: comando artisan `google-auth:uninstall`.

- [ ] **Step 1: Escribir el test del comando**

```php
<?php

namespace CarlVallory\KrayinGoogleAuth\Tests\Feature;

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
```
> Nota: este test deja el esquema sin las columnas/rol dentro de la transacción; el rollback de `DatabaseTransactions` cubre filas, pero los `ALTER TABLE` no son transaccionales en MySQL. Correr este test el último, y re-aplicar migraciones después (`php artisan migrate --force`) para restaurar el esquema local.

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --filter=UninstallCommandTest`
Expected: FAIL — el comando no existe.

- [ ] **Step 3: Escribir el comando**

`src/Console/UninstallCommand.php`:
```php
<?php

namespace CarlVallory\KrayinGoogleAuth\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UninstallCommand extends Command
{
    protected $signature = 'google-auth:uninstall';

    protected $description = 'Revierte el esquema de KrayinGoogleAuth (rol Básico + columnas en users).';

    public function handle(): int
    {
        // 1. Reasigna usuarios del rol Básico al rol de respaldo y borra el rol.
        $basico = DB::table('roles')->where('name', 'Básico')->first();

        if ($basico) {
            $fallbackName = config('google-auth.uninstall_fallback_role', 'Administrator');
            $fallback = DB::table('roles')->where('name', $fallbackName)->first();

            if ($fallback) {
                DB::table('users')->where('role_id', $basico->id)->update(['role_id' => $fallback->id]);
            }

            DB::table('roles')->where('id', $basico->id)->delete();
            $this->info('Rol Básico eliminado.');
        }

        // 2. Quita las columnas agregadas a users.
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'google_id')) {
                $table->dropUnique('users_google_id_unique');
                $table->dropColumn('google_id');
            }
            if (Schema::hasColumn('users', 'auth_provider')) {
                $table->dropColumn('auth_provider');
            }
        });

        $this->info('Columnas google_id y auth_provider eliminadas.');
        $this->warn('Quita el paquete de composer.json y borra GOOGLE_* del .env para completar la desinstalación.');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Registrar el comando en el provider**

En `boot()`:
```php
if ($this->app->runningInConsole()) {
    $this->commands([
        \CarlVallory\KrayinGoogleAuth\Console\UninstallCommand::class,
    ]);
}
```

- [ ] **Step 5: Correr el test y verificar que pasa, luego restaurar esquema**

Run:
```bash
php artisan test --filter=UninstallCommandTest
php artisan migrate --force
```
Expected: test PASS; migrate restaura columnas y rol.

- [ ] **Step 6: Commit**

```bash
git add packages/CarlVallory/KrayinGoogleAuth/src/Console/UninstallCommand.php \
        packages/CarlVallory/KrayinGoogleAuth/src/Providers/KrayinGoogleAuthServiceProvider.php \
        packages/CarlVallory/KrayinGoogleAuth/tests/Feature/UninstallCommandTest.php
git commit -m "feat(google-auth): comando google-auth:uninstall (revierte rol + columnas)"
```

---

### Task 9: Suite completa + documentación del paquete

**Files:**
- Create: `packages/CarlVallory/KrayinGoogleAuth/README.md`

- [ ] **Step 1: Correr toda la suite del paquete**

Run: `php artisan test --filter=KrayinGoogleAuth`
Expected: todos verdes. Anotar conteo de tests/asserts.

- [ ] **Step 2: Escribir el README del paquete**

Incluir: propósito, instalación (`.env` GOOGLE_*, crear credenciales OAuth en Google Cloud con redirect URI), comportamiento (@muci.org auto-aprobado vs pendiente), cómo aprobar pendientes (`/admin/google-auth/pending`), toggle de emergencia `GOOGLE_AUTH_SHOW_PASSWORD_LOGIN=true`, y desinstalación (`php artisan google-auth:uninstall` + quitar de composer + .env).

- [ ] **Step 3: Commit**

```bash
git add packages/CarlVallory/KrayinGoogleAuth/README.md
git commit -m "docs(google-auth): README del paquete (instalacion, uso, desinstalacion)"
```

---

## Notas de verificación manual (post-implementación)

Estos pasos requieren credenciales reales de Google y NO están cubiertos por tests automáticos:

1. Crear credenciales OAuth 2.0 en Google Cloud Console (tipo "Web"), con redirect URI = `GOOGLE_REDIRECT_URI`.
2. Poblar `.env`: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`.
3. Probar login real con una cuenta @muci.org → entra directo.
4. Probar con una cuenta externa → queda pendiente; aprobarla desde `/admin/google-auth/pending`.
5. Probar el toggle de emergencia `GOOGLE_AUTH_SHOW_PASSWORD_LOGIN=true` → reaparece el form de contraseña.
