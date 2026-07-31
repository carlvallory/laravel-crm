<?php

use CarlVallory\KrayinFundraising\Services\KanbanService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->actingAs(\Webkul\User\Models\User::find(1), 'user');
    DB::table('persons')->insert([
        'id' => 990, 'name' => 'Instituto A', 'organization_id' => null,
        'emails' => json_encode([]), 'contact_numbers' => json_encode([]),
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

it('agrega una nota vía POST y la lista vía GET', function () {
    $this->postJson(route('krayin.fundraising.kanban.notes.store', 990), ['body' => 'Llamar el lunes'])
        ->assertOk();

    $response = $this->getJson(route('krayin.fundraising.kanban.notes.index', 990));
    $response->assertOk();
    expect($response->json('notes'))->toHaveCount(1);
    expect($response->json('notes.0.body'))->toBe('Llamar el lunes');
});

it('rechaza una nota vacía con 422', function () {
    $this->postJson(route('krayin.fundraising.kanban.notes.store', 990), ['body' => ''])
        ->assertStatus(422);
});
