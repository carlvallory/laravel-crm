<?php

use CarlVallory\KrayinNetValue\Models\ExchangeRate;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

// Toca leads + lead_pipeline_stages + exchange_rates reales: rollback automático, no deja rastro.
uses(DatabaseTransactions::class);

beforeEach(function () {
    ExchangeRate::query()->delete();
    ExchangeRate::create(['date' => '2026-01-02', 'rate' => 7000.00, 'source' => 'bcp']);

    // lead_pipeline_stages NO tiene columnas timestamp (created_at/updated_at).
    DB::table('lead_pipeline_stages')->updateOrInsert(['id' => 5], [
        'code' => 'won', 'name' => 'Won', 'lead_pipeline_id' => 1, 'sort_order' => 5,
    ]);
    DB::table('lead_pipeline_stages')->updateOrInsert(['id' => 1], [
        'code' => 'new', 'name' => 'New', 'lead_pipeline_id' => 1, 'sort_order' => 1,
    ]);
});

function insertLead(array $attrs): int
{
    return DB::table('leads')->insertGetId(array_merge([
        'title' => 'L', 'lead_value' => 0, 'net_value' => 0, 'status' => 1,
        'lead_pipeline_id' => 1, 'created_at' => '2026-01-04 09:00:00', 'updated_at' => now(),
    ], $attrs));
}

it('calcula usd_rate y total_usd para leads ganados usando la tasa del día del pedido', function () {
    // pedido del domingo 2026-01-04 → usa cierre del 2026-01-02 (7000)
    $id = insertLead(['lead_pipeline_stage_id' => 5, 'net_value' => 7_000_000]);

    $this->artisan('leads:backfill-usd', ['year' => 2026])->assertSuccessful();

    $lead = DB::table('leads')->find($id);
    expect((float) $lead->usd_rate)->toBe(7000.0);
    expect((float) $lead->total_usd)->toBe(1000.0); // 7.000.000 / 7000
});

it('ignora leads no ganados', function () {
    $id = insertLead(['lead_pipeline_stage_id' => 1, 'net_value' => 7_000_000]);

    $this->artisan('leads:backfill-usd', ['year' => 2026])->assertSuccessful();

    expect(DB::table('leads')->find($id)->total_usd)->toBeNull();
});
