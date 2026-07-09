<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

// Toca leads reales: rollback automático, no deja rastro.
uses(DatabaseTransactions::class);

function insertLeadForNetBackfill(array $attrs): int
{
    return DB::table('leads')->insertGetId(array_merge([
        'title' => 'L', 'lead_value' => 0, 'status' => 1,
        'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => 1,
        'created_at' => '2026-01-04 09:00:00', 'updated_at' => now(),
    ], $attrs));
}

it('copia lead_value a net_value cuando net_value es NULL', function () {
    $id = insertLeadForNetBackfill(['lead_value' => 123456.78, 'net_value' => null]);

    $this->artisan('leads:backfill-net-value')->assertSuccessful();

    expect((float) DB::table('leads')->find($id)->net_value)->toBe(123456.78);
});

it('no sobrescribe net_value ya poblado (idempotente)', function () {
    $id = insertLeadForNetBackfill(['lead_value' => 123456.78, 'net_value' => 50000.00]);

    $this->artisan('leads:backfill-net-value')->assertSuccessful();

    expect((float) DB::table('leads')->find($id)->net_value)->toBe(50000.00);
});

it('ignora leads sin lead_value', function () {
    $id = insertLeadForNetBackfill(['lead_value' => null, 'net_value' => null]);

    $this->artisan('leads:backfill-net-value')->assertSuccessful();

    expect(DB::table('leads')->find($id)->net_value)->toBeNull();
});
