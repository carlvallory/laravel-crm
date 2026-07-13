<?php

use CarlVallory\KrayinFundraising\Queries\ProspectQuery;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

beforeEach(function () {
    DB::table('lead_pipeline_stages')->updateOrInsert(['id' => 5], [
        'code' => 'won', 'name' => 'Won', 'lead_pipeline_id' => 1, 'sort_order' => 5,
    ]);
    DB::table('lead_pipeline_stages')->updateOrInsert(['id' => 2], [
        'code' => 'follow_up', 'name' => 'Follow Up', 'lead_pipeline_id' => 1, 'sort_order' => 2,
    ]);

    // Persona 990 (Instituto A): 1 compra real + 1 cortesía (valor 0)
    DB::table('persons')->insert([
        'id' => 990, 'name' => 'Instituto A', 'organization_id' => null,
        'emails' => json_encode([['value' => 'a@inst.py', 'label' => 'work']]),
        'contact_numbers' => json_encode([['value' => '0981111', 'label' => 'work']]),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    // Persona 991 (Juan B): 1 compra real
    DB::table('persons')->insert([
        'id' => 991, 'name' => 'Juan B', 'organization_id' => null,
        'emails' => json_encode([]), 'contact_numbers' => json_encode([]),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('leads')->insert([
        // Instituto A: compra real
        ['title' => 'A1', 'person_id' => 990, 'lead_value' => 0, 'net_value' => 7_000_000, 'total_usd' => 1000,
         'status' => 1, 'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => 5,
         'closed_at' => '2026-01-04 09:00:00', 'created_at' => '2026-01-04 09:00:00', 'updated_at' => now()],
        // Instituto A: cortesía (valor 0)
        ['title' => 'A2-cortesia', 'person_id' => 990, 'lead_value' => 0, 'net_value' => 0, 'total_usd' => 0,
         'status' => 1, 'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => 5,
         'closed_at' => '2026-02-01 09:00:00', 'created_at' => '2026-02-01 09:00:00', 'updated_at' => now()],
        // Juan B: compra real
        ['title' => 'B1', 'person_id' => 991, 'lead_value' => 0, 'net_value' => 3_000_000, 'total_usd' => 400,
         'status' => 1, 'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => 5,
         'closed_at' => '2026-03-10 09:00:00', 'created_at' => '2026-03-10 09:00:00', 'updated_at' => now()],
        // Juan B: lead NO won (debe ignorarse)
        ['title' => 'B2-open', 'person_id' => 991, 'lead_value' => 9_000_000, 'net_value' => 9_000_000, 'total_usd' => 1200,
         'status' => 1, 'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => 2,
         'closed_at' => null, 'created_at' => '2026-03-11 09:00:00', 'updated_at' => now()],
    ]);
});

it('agrega por persona: volumen, recurrencia sin cortesías, USD y última compra', function () {
    $rows = ProspectQuery::forPersons()->get()->keyBy('prospect_id');

    expect((float) $rows[990]->total_pyg)->toBe(7_000_000.0);
    expect((float) $rows[990]->total_usd)->toBe(1000.0);
    expect((int) $rows[990]->purchases)->toBe(1);           // la cortesía NO cuenta
    expect($rows[990]->last_purchase)->toContain('2026-02-01'); // MAX(closed_at) incluye la cortesía

    expect((float) $rows[991]->total_pyg)->toBe(3_000_000.0); // el lead no-won se ignora
    expect((int) $rows[991]->purchases)->toBe(1);
});

it('filtra por período y por monto mínimo', function () {
    $soloEnero = ProspectQuery::forPersons(['date_from' => '2026-01-01', 'date_to' => '2026-01-31'])->get()->keyBy('prospect_id');
    expect($soloEnero->has(991))->toBeFalse();               // Juan B compró en marzo
    expect((float) $soloEnero[990]->total_pyg)->toBe(7_000_000.0);

    $grandes = ProspectQuery::forPersons(['min_amount' => 5_000_000])->get()->keyBy('prospect_id');
    expect($grandes->has(990))->toBeTrue();
    expect($grandes->has(991))->toBeFalse();                 // 3M < 5M
});
