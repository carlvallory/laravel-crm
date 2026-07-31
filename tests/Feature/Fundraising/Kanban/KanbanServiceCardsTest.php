<?php

use CarlVallory\KrayinFundraising\Models\Activity;
use CarlVallory\KrayinFundraising\Models\KanbanCard;
use CarlVallory\KrayinFundraising\Models\KanbanColumn;
use CarlVallory\KrayinFundraising\Services\KanbanService;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->actingAs(\Webkul\User\Models\User::find(1), 'user');
    $this->cols = KanbanColumn::orderBy('position')->get();  // las 3 sembradas
});

it('agrega una tarjeta y loguea card_added', function () {
    $card = app(KanbanService::class)->addCard(990, $this->cols[0]->id);

    expect($card->person_id)->toBe(990);
    expect($card->column_id)->toBe($this->cols[0]->id);
    expect(Activity::where('event', 'card_added')->where('person_id', 990)->exists())->toBeTrue();
});

it('agregar una persona ya presente la mueve, no duplica', function () {
    $service = app(KanbanService::class);
    $service->addCard(990, $this->cols[0]->id);
    $service->addCard(990, $this->cols[1]->id);  // segunda vez → movimiento

    expect(KanbanCard::where('person_id', 990)->count())->toBe(1);
    expect(KanbanCard::where('person_id', 990)->first()->column_id)->toBe($this->cols[1]->id);
    expect(Activity::where('event', 'card_moved')->where('person_id', 990)->exists())->toBeTrue();
});

it('mueve una tarjeta entre columnas y loguea from/to', function () {
    $service = app(KanbanService::class);
    $service->addCard(990, $this->cols[0]->id);
    $service->moveCard(990, $this->cols[2]->id, 0);

    $moved = Activity::where('event', 'card_moved')->where('person_id', 990)->first();
    expect($moved->from_column_id)->toBe($this->cols[0]->id);
    expect($moved->to_column_id)->toBe($this->cols[2]->id);
});

it('quita una tarjeta y loguea card_removed', function () {
    $service = app(KanbanService::class);
    $service->addCard(990, $this->cols[0]->id);
    $service->removeCard(990);

    expect(KanbanCard::where('person_id', 990)->exists())->toBeFalse();
    expect(Activity::where('event', 'card_removed')->where('person_id', 990)->exists())->toBeTrue();
});
