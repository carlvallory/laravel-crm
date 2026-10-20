<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\AiRead\AiReadFixtures as F;

uses(DatabaseTransactions::class);

beforeEach(function () {
    F::useDefaultConnection();
    $this->token = F::token();

    $a = F::person('2030-12-01 10:00:00');   // ya compraba antes → recurrente en marzo
    $b = F::person('2031-03-01 10:00:00');   // primera compra en marzo → nuevo
    F::person('2031-03-02 10:00:00');        // alta sin compra

    F::wonLead('2031-01-15 10:00:00', ['person_id' => $a, 'lead_value' => 1000, 'branch' => 'Online']);
    F::wonLead('2031-03-05 10:00:00', ['person_id' => $a, 'lead_value' => 1000, 'branch' => 'Online']);
    F::wonLead('2031-03-07 10:00:00', ['person_id' => $b, 'lead_value' => 1000, 'branch' => 'Online']);
    F::wonLead('2031-03-20 10:00:00', ['person_id' => $b, 'lead_value' => 1000, 'branch' => 'Online']);
});

it('cuenta altas, compradores nuevos y recurrentes por período', function () {
    $r = $this->withToken($this->token)
        ->getJson('/api/ia/v1/contactos/resumen?desde=2031-03-01&hasta=2031-03-31')
        ->assertOk();

    expect($r->json('datos'))->toBe([[
        'periodo' => '2031-03', 'altas' => 2, 'compradores_nuevos' => 1, 'compradores_recurrentes' => 1,
    ]]);
});

it('una venta Excluida previa no convierte al comprador en recurrente', function () {
    $c = F::person('2030-11-01 10:00:00');
    F::wonLead('2031-02-10 10:00:00', ['person_id' => $c, 'lead_value' => 1000, 'branch' => 'Excluida']);
    F::wonLead('2031-03-10 10:00:00', ['person_id' => $c, 'lead_value' => 1000, 'branch' => 'Online']);

    $r = $this->withToken($this->token)
        ->getJson('/api/ia/v1/contactos/resumen?desde=2031-03-01&hasta=2031-03-31')
        ->assertOk();

    expect($r->json('datos'))->toBe([[
        'periodo' => '2031-03', 'altas' => 2, 'compradores_nuevos' => 2, 'compradores_recurrentes' => 1,
    ]]);
});

it('no devuelve datos personales', function () {
    $json = $this->withToken($this->token)
        ->getJson('/api/ia/v1/contactos/resumen?desde=2031-01-01&hasta=2031-03-31')
        ->assertOk()->getContent();

    expect($json)->not->toContain('Persona Prueba IA')->not->toContain('@example.test');
});
