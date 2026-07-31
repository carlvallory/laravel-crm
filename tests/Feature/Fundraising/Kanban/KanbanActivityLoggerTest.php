<?php

use CarlVallory\KrayinFundraising\Models\Activity;
use CarlVallory\KrayinFundraising\Services\KanbanActivityLogger;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->actingAs(\Webkul\User\Models\User::find(1), 'user');
});

it('registra card_moved con autor y columnas origen/destino', function () {
    $activity = app(KanbanActivityLogger::class)->cardMoved(990, 1, 2);

    expect($activity->event)->toBe('card_moved');
    expect($activity->user_id)->toBe(1);
    expect($activity->person_id)->toBe(990);
    expect($activity->from_column_id)->toBe(1);
    expect($activity->to_column_id)->toBe(2);
    expect($activity->created_at)->not->toBeNull();
});

it('registra column_renamed con el nombre en meta', function () {
    $activity = app(KanbanActivityLogger::class)->columnRenamed(3, 'Nuevo nombre');

    expect($activity->event)->toBe('column_renamed');
    expect($activity->to_column_id)->toBe(3);
    expect($activity->meta['name'])->toBe('Nuevo nombre');
});

it('registra note_added anclada a la persona', function () {
    app(KanbanActivityLogger::class)->noteAdded(990);

    expect(Activity::where('event', 'note_added')->where('person_id', 990)->exists())->toBeTrue();
});
