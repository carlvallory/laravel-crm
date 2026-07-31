<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->actingAs(\Webkul\User\Models\User::find(1), 'user');
});

it('muestra los presets y los inputs de rango de fecha', function () {
    $response = $this->get(route('krayin.fundraising.index'));

    $response->assertOk();
    $response->assertSee('Este mes', false);
    $response->assertSee('Este año', false);
    $response->assertSee('Todo', false);
    $response->assertSee('name="date_from"', false);
    $response->assertSee('name="date_to"', false);
});

it('el src del datagrid arrastra el rango de fecha cuando está en la request', function () {
    $response = $this->get(route('krayin.fundraising.index', [
        'ver' => 'personas', 'date_from' => '2026-01-01', 'date_to' => '2026-01-31',
    ]));

    $response->assertOk();
    $response->assertSee('date_from=2026-01-01', false);
    $response->assertSee('date_to=2026-01-31', false);
});

it('preserva ver=organizaciones al filtrar por fecha', function () {
    $response = $this->get(route('krayin.fundraising.index', ['ver' => 'organizaciones']));

    $response->assertOk();
    $response->assertSee('ver=organizaciones', false);
});

it('el datagrid filtra por rango de fecha (end-to-end)', function () {
    DB::table('lead_pipeline_stages')->updateOrInsert(['id' => 5], [
        'code' => 'won', 'name' => 'Won', 'lead_pipeline_id' => 1, 'sort_order' => 5,
    ]);
    DB::table('persons')->insert([
        ['id' => 800, 'name' => 'Enero P', 'organization_id' => null, 'emails' => json_encode([]), 'contact_numbers' => json_encode([]), 'created_at' => now(), 'updated_at' => now()],
        ['id' => 801, 'name' => 'Marzo P', 'organization_id' => null, 'emails' => json_encode([]), 'contact_numbers' => json_encode([]), 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('leads')->insert([
        ['title' => 'E', 'person_id' => 800, 'lead_value' => 0, 'net_value' => 1_000_000, 'total_usd' => 100, 'status' => 1, 'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => 5, 'closed_at' => '2026-01-15 09:00:00', 'created_at' => '2026-01-15 09:00:00', 'updated_at' => now()],
        ['title' => 'M', 'person_id' => 801, 'lead_value' => 0, 'net_value' => 2_000_000, 'total_usd' => 200, 'status' => 1, 'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => 5, 'closed_at' => '2026-03-15 09:00:00', 'created_at' => '2026-03-15 09:00:00', 'updated_at' => now()],
    ]);

    $response = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
        ->getJson(route('krayin.fundraising.index', [
            'ver' => 'personas', 'date_from' => '2026-01-01', 'date_to' => '2026-01-31',
        ]));

    $response->assertOk();
    $names = collect($response->json('records'))->pluck('name');
    expect($names)->toContain('Enero P');
    expect($names)->not->toContain('Marzo P');
});
