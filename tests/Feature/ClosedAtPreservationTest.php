<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Lead\Repositories\LeadRepository;

// Rollback automático: leads/pipelines/stages creados no quedan persistidos.
uses(DatabaseTransactions::class);

beforeEach(function () {
    DB::table('lead_pipelines')->updateOrInsert(['id' => 1], [
        'name' => 'Default', 'is_default' => 1, 'rotten_days' => 30,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('lead_pipeline_stages')->updateOrInsert(['id' => 5], [
        'code' => 'won', 'name' => 'Won', 'lead_pipeline_id' => 1, 'sort_order' => 5,
    ]);
    DB::table('lead_pipeline_stages')->updateOrInsert(['id' => 1], [
        'code' => 'new', 'name' => 'New', 'lead_pipeline_id' => 1, 'sort_order' => 1,
    ]);
});

/**
 * El controller REST (vendor/krayin/rest-api LeadController@store, línea 103) machaca
 * incondicionalmente closed_at = now() para stages won/lost al CREAR, descartando la
 * fecha real que manda el cliente (el plugin envía get_date_completed()). No podemos
 * editar vendor: el paquete escucha `lead.create.after` (que el mismo store dispara,
 * línea 108) y restaura el closed_at que vino en el request.
 *
 * Este test reproduce el estado post-clobber (closed_at = hoy) y verifica que el
 * listener lo restaura a la fecha enviada.
 */
it('restaura el closed_at enviado al crear un lead won (revierte el clobber del core)', function () {
    $past = '2025-03-20 14:00:00';
    $now  = now()->format('Y-m-d H:i:s');

    // El request lleva el closed_at real (como lo manda el plugin).
    request()->merge(['closed_at' => $past]);

    // Simula el resultado del controller: lead won ya persistido con closed_at = hoy.
    $lead = app(LeadRepository::class)->create([
        'title' => 'HIST won', 'lead_value' => 500000, 'status' => 1,
        'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => 5, 'user_id' => 1,
        'entity_type' => 'leads',
        'closed_at' => $now,
    ]);

    expect($lead->fresh()->closed_at->format('Y-m-d H:i:s'))->toBe($now); // sanity: quedó "hoy"

    event('lead.create.after', $lead);

    expect($lead->fresh()->closed_at->format('Y-m-d H:i:s'))->toBe($past);
});

it('no toca closed_at si el request no trae la fecha', function () {
    $now = now()->format('Y-m-d H:i:s');

    request()->replace([]); // sin closed_at

    $lead = app(LeadRepository::class)->create([
        'title' => 'won sin fecha', 'lead_value' => 100000, 'status' => 1,
        'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => 5, 'user_id' => 1,
        'entity_type' => 'leads',
        'closed_at' => $now,
    ]);

    event('lead.create.after', $lead);

    expect($lead->fresh()->closed_at->format('Y-m-d H:i:s'))->toBe($now);
});

it('no toca closed_at en stages no terminales (new)', function () {
    request()->merge(['closed_at' => '2025-03-20 14:00:00']);

    $lead = app(LeadRepository::class)->create([
        'title' => 'new lead', 'lead_value' => 100000, 'status' => 1,
        'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => 1, 'user_id' => 1,
        'entity_type' => 'leads',
        'closed_at' => null,
    ]);

    event('lead.create.after', $lead);

    expect($lead->fresh()->closed_at)->toBeNull();
});
