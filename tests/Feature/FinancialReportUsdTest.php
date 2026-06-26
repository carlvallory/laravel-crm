<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

// Inserta lead + stage reales: rollback automático.
uses(DatabaseTransactions::class);

beforeEach(function () {
    // lead_pipeline_stages NO tiene columnas timestamp.
    DB::table('lead_pipeline_stages')->updateOrInsert(['id' => 5], [
        'code' => 'won', 'name' => 'Won', 'lead_pipeline_id' => 1, 'sort_order' => 5,
    ]);
    $year = date('Y');
    DB::table('leads')->insert([
        'title' => 'L1', 'lead_value' => 0, 'net_value' => 7_000_000, 'total_usd' => 1000,
        'status' => 1, 'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => 5,
        'created_at' => "{$year}-01-04 09:00:00", 'closed_at' => "{$year}-01-04 09:00:00",
        'updated_at' => now(),
    ]);
});

it('expone los agregados USD a la vista', function () {
    // Guard 'user' = panel admin de Krayin (middleware de la ruta).
    $this->actingAs(\Webkul\User\Models\User::find(1), 'user');

    $response = $this->get(route('krayin.financial-reports.index'));

    $response->assertOk();
    $response->assertViewHas('totalRevenueUsd', 1000.0);
    $response->assertViewHas('chartDataUsd');
});
