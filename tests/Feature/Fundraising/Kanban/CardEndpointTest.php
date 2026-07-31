<?php

use CarlVallory\KrayinFundraising\Models\KanbanCard;
use CarlVallory\KrayinFundraising\Models\KanbanColumn;
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
    $this->cols = KanbanColumn::orderBy('position')->get();
});

it('agrega una tarjeta vía POST', function () {
    $this->postJson(route('krayin.fundraising.kanban.cards.store'), [
        'person_id' => 990, 'column_id' => $this->cols[0]->id,
    ])->assertOk();

    expect(KanbanCard::where('person_id', 990)->first()->column_id)->toBe($this->cols[0]->id);
});

it('rechaza person_id inexistente con 422', function () {
    $this->postJson(route('krayin.fundraising.kanban.cards.store'), [
        'person_id' => 777777, 'column_id' => $this->cols[0]->id,
    ])->assertStatus(422);
});

it('mueve una tarjeta vía PATCH', function () {
    app(KanbanService::class)->addCard(990, $this->cols[0]->id);

    $this->patchJson(route('krayin.fundraising.kanban.cards.update', 990), [
        'column_id' => $this->cols[1]->id, 'position' => 0,
    ])->assertOk();

    expect(KanbanCard::where('person_id', 990)->first()->column_id)->toBe($this->cols[1]->id);
});

it('devuelve 404 al mover una tarjeta inexistente', function () {
    $this->patchJson(route('krayin.fundraising.kanban.cards.update', 990), [
        'column_id' => $this->cols[0]->id,
    ])->assertStatus(404);
});

it('quita una tarjeta vía DELETE', function () {
    app(KanbanService::class)->addCard(990, $this->cols[0]->id);

    $this->deleteJson(route('krayin.fundraising.kanban.cards.destroy', 990))->assertOk();

    expect(KanbanCard::where('person_id', 990)->exists())->toBeFalse();
});
