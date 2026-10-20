<?php

use CarlVallory\KrayinAiRead\Auth\IaTokens;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Feature\AiRead\AiReadFixtures as F;

uses(DatabaseTransactions::class);

beforeEach(function () {
    F::rutaDePrueba('_prueba', fn () => response()->json(['ok' => true]));
});

it('acepta un token válido y registra el último uso', function () {
    $token = F::token('grok');

    $this->withToken($token)->getJson('/api/ia/v1/_prueba')->assertOk()->assertJson(['ok' => true]);

    expect(DB::table('ia_tokens')->where('consumidor', 'grok')->value('ultimo_uso_en'))->not->toBeNull();
});

it('guarda solo el hash del token', function () {
    $token = F::token('grok');

    expect(DB::table('ia_tokens')->where('token_hash', hash('sha256', $token))->exists())->toBeTrue()
        ->and(DB::table('ia_tokens')->where('token_hash', $token)->exists())->toBeFalse();
});

it('rechaza sin header, con token inexistente o por query string', function (array $headers, string $url) {
    $this->withHeaders($headers)->getJson($url)
        ->assertStatus(401)->assertJsonPath('error', 'token_invalido');
})->with([
    'sin header'     => [[], '/api/ia/v1/_prueba'],
    'inexistente'    => [['Authorization' => 'Bearer iar_noexiste'], '/api/ia/v1/_prueba'],
    'query string'   => [[], '/api/ia/v1/_prueba?token=iar_loquesea'],
]);

it('rechaza un token vencido con token_vencido', function () {
    $token = F::token('grok');
    DB::table('ia_tokens')->where('consumidor', 'grok')->update(['vence_en' => now()->subMinute()]);

    $this->withToken($token)->getJson('/api/ia/v1/_prueba')
        ->assertStatus(401)->assertJsonPath('error', 'token_vencido');
});

it('rechaza un token revocado', function () {
    $token = F::token('grok');
    app(IaTokens::class)->revoke('grok');

    $this->withToken($token)->getJson('/api/ia/v1/_prueba')
        ->assertStatus(401)->assertJsonPath('error', 'token_invalido');
});

it('no acepta tokens de Sanctum', function () {
    $sanctum = getDefaultAdmin()->createToken('prueba-ia')->plainTextToken;

    $this->withToken($sanctum)->getJson('/api/ia/v1/_prueba')->assertStatus(401);
});

it('el token del paquete no sirve en /api/v1', function () {
    $this->withToken(F::token('grok'))->getJson('/api/v1/leads')->assertStatus(401);
});

it('limita las consultas por minuto por consumidor', function () {
    config(['krayin-ai-read.rate_per_minute' => 2]);
    $token = F::token('grok');

    $this->withToken($token)->getJson('/api/ia/v1/_prueba')->assertOk();
    $this->withToken($token)->getJson('/api/ia/v1/_prueba')->assertOk();
    $this->withToken($token)->getJson('/api/ia/v1/_prueba')
        ->assertStatus(429)->assertJsonPath('error', 'demasiadas_consultas');

    // Otro consumidor tiene su propio cupo.
    $this->withToken(F::token('claude'))->getJson('/api/ia/v1/_prueba')->assertOk();
});

it('registra cada acceso sin el token', function () {
    $path = storage_path('framework/testing/ia-accesos-test.log');
    @mkdir(dirname($path), 0777, true);
    @unlink($path);
    config(['logging.channels.ia_accesos' => ['driver' => 'single', 'path' => $path, 'level' => 'info']]);
    Log::forgetChannel('ia_accesos');

    $token = F::token('grok');
    $this->withToken($token)->getJson('/api/ia/v1/_prueba')->assertOk();
    // withToken() deja el header puesto para los requests siguientes del test.
    $this->withoutToken()->getJson('/api/ia/v1/_prueba')->assertStatus(401);

    $log = file_get_contents($path);
    expect($log)->toContain('"consumidor":"grok"')
        ->toContain('"ruta":"api/ia/v1/_prueba"')
        ->toContain('"http":200')
        ->toContain('"http":401')
        ->not->toContain($token);
});

it('el comando crea, lista y revoca tokens', function () {
    $this->artisan('crm:ia-token', ['accion' => 'crear', 'consumidor' => 'grok', '--vence' => now()->addYear()->toDateString()])
        ->expectsOutputToContain('UNA sola vez')
        ->assertSuccessful();

    $this->artisan('crm:ia-token', ['accion' => 'listar'])
        ->expectsOutputToContain('grok')
        ->assertSuccessful();

    $this->artisan('crm:ia-token', ['accion' => 'revocar', 'consumidor' => 'grok'])
        ->expectsOutputToContain('Revocados: 1')
        ->assertSuccessful();

    expect(DB::table('ia_tokens')->where('consumidor', 'grok')->whereNull('revocado_en')->count())->toBe(0);
});

it('el comando exige un vencimiento futuro y válido', function (?string $vence) {
    $this->artisan('crm:ia-token', array_filter(['accion' => 'crear', 'consumidor' => 'grok', '--vence' => $vence]))
        ->assertFailed();
})->with([null, '2020-01-01', '2031-02-30', 'mañana']);
