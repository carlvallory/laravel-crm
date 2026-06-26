<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Lead\Repositories\LeadRepository;

// Rollback automático: el lead y los pipelines/stages creados no quedan persistidos.
uses(DatabaseTransactions::class);

it('preserva el created_at enviado al crear un lead', function () {
    // Pipeline + stage mínimos para el create del repo
    DB::table('lead_pipelines')->updateOrInsert(['id' => 1], [
        'name' => 'Default', 'is_default' => 1, 'rotten_days' => 30,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('lead_pipeline_stages')->updateOrInsert(['id' => 1], [
        'code' => 'new', 'name' => 'New', 'lead_pipeline_id' => 1, 'sort_order' => 1,
    ]);

    $repo = app(LeadRepository::class);

    expect(get_class($repo->getModel()))
        ->toBe(\CarlVallory\KrayinWoocommerce\Models\Lead::class);

    $past = '2026-01-15 10:30:00';
    $lead = $repo->create([
        'title' => 'TEST created_at', 'lead_value' => 100000, 'status' => 1,
        'lead_pipeline_stage_id' => 1, 'lead_pipeline_id' => 1, 'user_id' => 1,
        'entity_type' => 'leads',
        'created_at' => $past,
    ]);

    expect($lead->fresh()->created_at->format('Y-m-d H:i:s'))->toBe($past);
});
