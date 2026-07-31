<?php

use CarlVallory\KrayinFundraising\Services\KanbanService;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->actingAs(\Webkul\User\Models\User::find(1), 'user');
});

it('agrega una nota con autor y loguea note_added', function () {
    $note = app(KanbanService::class)->addNote(990, 'Llamar el lunes');

    expect($note->body)->toBe('Llamar el lunes');
    expect($note->user_id)->toBe(1);
    expect(app(KanbanService::class)->notesFor(990))->toHaveCount(1);
});

it('las notas sobreviven a quitar y re-agregar la tarjeta (ancladas a person_id)', function () {
    $service = app(KanbanService::class);
    $cols = \CarlVallory\KrayinFundraising\Models\KanbanColumn::orderBy('position')->get();

    $service->addCard(990, $cols[0]->id);
    $service->addNote(990, 'Nota histórica');
    $service->removeCard(990);
    $service->addCard(990, $cols[1]->id);

    expect($service->notesFor(990))->toHaveCount(1);
    expect($service->notesFor(990)->first()->body)->toBe('Nota histórica');
});
