<?php

use CarlVallory\KrayinFundraising\Queries\ProspectQuery;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

function setFundraisingExclusion(string $code, string $value): void
{
    DB::table('core_config')->updateOrInsert(
        ['code' => "fundraising.prospectos.exclusiones.$code"],
        ['value' => $value, 'created_at' => now(), 'updated_at' => now()]
    );
}

beforeEach(function () {
    DB::table('lead_pipeline_stages')->updateOrInsert(['id' => 5], [
        'code' => 'won', 'name' => 'Won', 'lead_pipeline_id' => 1, 'sort_order' => 5,
    ]);
    DB::table('persons')->insert([
        ['id' => 700, 'name' => 'Cajera A', 'organization_id' => null,
         'emails' => json_encode([['value' => 'cajera@x.com', 'label' => 'work']]),
         'contact_numbers' => json_encode([]), 'created_at' => now(), 'updated_at' => now()],
        ['id' => 701, 'name' => 'Cliente B', 'organization_id' => null,
         'emails' => json_encode([['value' => 'cliente@real.com', 'label' => 'work']]),
         'contact_numbers' => json_encode([]), 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('leads')->insert([
        ['id' => 7000, 'title' => 'LA', 'person_id' => 700, 'lead_value' => 0, 'net_value' => 5_000_000, 'total_usd' => 700,
         'status' => 1, 'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => 5,
         'closed_at' => '2026-01-10 09:00:00', 'created_at' => '2026-01-10 09:00:00', 'updated_at' => now()],
        ['id' => 7001, 'title' => 'LB', 'person_id' => 701, 'lead_value' => 0, 'net_value' => 3_000_000, 'total_usd' => 400,
         'status' => 1, 'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => 5,
         'closed_at' => '2026-02-10 09:00:00', 'created_at' => '2026-02-10 09:00:00', 'updated_at' => now()],
    ]);
});

it('excluye compradores por email', function () {
    setFundraisingExclusion('excluded_emails', 'cajera@x.com');

    $rows = ProspectQuery::forPersons()->get()->keyBy('prospect_id');

    expect($rows->has(700))->toBeFalse();  // cajera excluida por email
    expect($rows->has(701))->toBeTrue();
});

it('excluye leads por CI/RUC', function () {
    $rucId = DB::table('attributes')->where('code', 'ruc_ci')->where('entity_type', 'leads')->value('id');
    // attribute_values NO tiene timestamps
    DB::table('attribute_values')->insert([
        'entity_type' => 'leads', 'entity_id' => 7000, 'attribute_id' => $rucId, 'text_value' => '9999999',
    ]);
    setFundraisingExclusion('excluded_documents', '9999999');

    $rows = ProspectQuery::forPersons()->get()->keyBy('prospect_id');

    expect($rows->has(700))->toBeFalse();  // su único lead quedó fuera por CI
    expect($rows->has(701))->toBeTrue();
});

it('sin configuración no excluye a nadie', function () {
    $rows = ProspectQuery::forPersons()->get()->keyBy('prospect_id');

    expect($rows->has(700))->toBeTrue();
    expect($rows->has(701))->toBeTrue();
});
