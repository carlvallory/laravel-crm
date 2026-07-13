<?php

use CarlVallory\KrayinFundraising\Queries\ProspectQuery;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

beforeEach(function () {
    DB::table('lead_pipeline_stages')->updateOrInsert(['id' => 5], [
        'code' => 'won', 'name' => 'Won', 'lead_pipeline_id' => 1, 'sort_order' => 5,
    ]);
    DB::table('organizations')->insert([
        'id' => 770, 'name' => 'Empresa X', 'created_at' => now(), 'updated_at' => now(),
    ]);
    // Dos personas de la MISMA organización
    DB::table('persons')->insert([
        ['id' => 880, 'name' => 'Emp X - Ana', 'organization_id' => 770,
         'emails' => json_encode([]), 'contact_numbers' => json_encode([]), 'created_at' => now(), 'updated_at' => now()],
        ['id' => 881, 'name' => 'Emp X - Beto', 'organization_id' => 770,
         'emails' => json_encode([]), 'contact_numbers' => json_encode([]), 'created_at' => now(), 'updated_at' => now()],
        // Persona SIN organización (no debe aparecer en la vista de orgs)
        ['id' => 882, 'name' => 'Suelto', 'organization_id' => null,
         'emails' => json_encode([]), 'contact_numbers' => json_encode([]), 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('leads')->insert([
        ['title' => 'X-Ana', 'person_id' => 880, 'lead_value' => 0, 'net_value' => 5_000_000, 'total_usd' => 700,
         'status' => 1, 'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => 5,
         'closed_at' => '2026-01-10 09:00:00', 'created_at' => '2026-01-10 09:00:00', 'updated_at' => now()],
        ['title' => 'X-Beto', 'person_id' => 881, 'lead_value' => 0, 'net_value' => 2_000_000, 'total_usd' => 300,
         'status' => 1, 'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => 5,
         'closed_at' => '2026-02-15 09:00:00', 'created_at' => '2026-02-15 09:00:00', 'updated_at' => now()],
        ['title' => 'Suelto-1', 'person_id' => 882, 'lead_value' => 0, 'net_value' => 9_000_000, 'total_usd' => 1000,
         'status' => 1, 'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => 5,
         'closed_at' => '2026-03-01 09:00:00', 'created_at' => '2026-03-01 09:00:00', 'updated_at' => now()],
    ]);
});

it('agrega por organización sumando las compras de todas sus personas', function () {
    $rows = ProspectQuery::forOrganizations()->get()->keyBy('prospect_id');

    expect((float) $rows[770]->total_pyg)->toBe(7_000_000.0); // 5M (Ana) + 2M (Beto)
    expect((int) $rows[770]->purchases)->toBe(2);
    expect($rows[770]->name)->toBe('Empresa X');
});

it('excluye personas sin organización', function () {
    $rows = ProspectQuery::forOrganizations()->get();
    // Solo la Empresa X; el "Suelto" (sin org) no genera fila
    expect($rows)->toHaveCount(1);
});
