<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AiRead\AiReadFixtures as F;

uses(DatabaseTransactions::class);

beforeEach(function () {
    F::useDefaultConnection();
    $this->token = F::token();
});

function pm(string $ruta, string $query = ''): \Illuminate\Testing\TestResponse
{
    return test()->withToken(test()->token)->getJson("/api/ia/v1/{$ruta}?{$query}")->assertOk();
}

it('resume leads por pipeline y etapa, con antigüedad y aviso', function () {
    F::lead(['created_at' => '2031-03-02 10:00:00']);
    F::lead(['created_at' => '2031-03-03 10:00:00']);
    F::wonLead('2031-03-10 10:00:00', ['created_at' => '2031-03-04 10:00:00']);

    $r = pm('pipeline/resumen', 'desde=2031-03-01&hasta=2031-03-31');
    $porCodigo = collect($r->json('datos'))->keyBy('codigo');

    expect($porCodigo['new']['leads'])->toBe(2)
        ->and($porCodigo['won']['leads'])->toBe(1)
        ->and($porCodigo['won']['dias_promedio'])->toEqual(6.0) // JSON serializa 6.0 como 6
        ->and(implode(' ', $r->json('avisos')))->toContain('no guarda');
});

it('cuenta actividades pendientes y vencidas por tipo y responsable, sin textos', function () {
    $antes = collect(pm('actividades/resumen')->json('datos'))
        ->first(fn ($r) => $r['tipo'] === 'call' && $r['responsable_id'] === 1);
    $base = ['pendientes' => $antes['pendientes'] ?? 0, 'vencidas' => $antes['vencidas'] ?? 0];

    $actividad = fn (array $a) => DB::table('activities')->insert(array_merge([
        'title' => 'Llamar a Juan Pérez', 'type' => 'call', 'is_done' => 0, 'user_id' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ], $a));
    $actividad(['schedule_to' => now()->subDay()]);           // vencida
    $actividad(['schedule_to' => now()->addDay()]);           // pendiente
    $actividad(['schedule_to' => now()->subDay(), 'is_done' => 1]); // hecha: no cuenta
    $actividad(['type' => 'note', 'schedule_to' => null]);    // nota: no cuenta

    $r = pm('actividades/resumen');
    $despues = collect($r->json('datos'))->first(fn ($x) => $x['tipo'] === 'call' && $x['responsable_id'] === 1);

    expect($despues['pendientes'] - $base['pendientes'])->toBe(1)
        ->and($despues['vencidas'] - $base['vencidas'])->toBe(1)
        ->and($r->getContent())->not->toContain('Juan Pérez');
});

it('cuenta tarjetas por columna del kanban de Fundraising', function () {
    $col = DB::table('fundraising_kanban_columns')->insertGetId([
        'name' => 'Columna prueba IA', 'position' => 999, 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ([F::person('2031-03-01 10:00:00'), F::person('2031-03-01 11:00:00')] as $i => $personId) {
        DB::table('fundraising_kanban_cards')->insert([
            'person_id' => $personId, 'column_id' => $col, 'position' => $i, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $fila = collect(pm('fundraising/resumen')->json('datos'))->firstWhere('columna_id', $col);

    expect($fila)->toBe(['columna_id' => $col, 'columna' => 'Columna prueba IA', 'tarjetas' => 2]);
});
