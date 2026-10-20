<?php

use CarlVallory\KrayinAiRead\Classification\SaleClassifier;

function clasificador(): SaleClassifier
{
    return new SaleClassifier([
        729 => 'San Cosmos',
        3   => 'Tatakualab',
        2   => 'Excluida',
        5   => ' excluida ',
        9   => 'Una sucursal con un nombre larguísimo de más de treinta',
    ]);
}

it('clasifica sucursal, variación y tipo', function (?int $pos, ?string $titulo, ?float $valor, array $kinds, string $branch, string $variation, string $tipo) {
    $c = clasificador()->classify($pos, $titulo, $valor, $kinds);

    expect([$c->branch, $c->variation, $c->orderType])->toBe([$branch, $variation, $tipo]);
})->with([
    'online pagado merch'          => [null,  'Bancard',   50000.0, ['merch'],            'Online',      'Online',    'merch'],
    'caja 0 cuenta como online'    => [0,     'Efectivo',  5000.0,  ['merch'],            'Online',      'Online',    'merch'],
    'caja San Cosmos entradas'     => [729,   'Efectivo',  5000.0,  ['entrada'],          'San Cosmos',  'En puerta', 'entradas'],
    'caja sin cargar'              => [14616, 'Efectivo',  5000.0,  ['merch'],            'Sin asignar', 'En puerta', 'merch'],
    'caja excluida'                => [2,     'Efectivo',  5000.0,  ['merch'],            'Excluida',    'En puerta', 'merch'],
    'excluida con espacios'        => [5,     'Efectivo',  5000.0,  ['merch'],            'Excluida',    'En puerta', 'merch'],
    'cortesía por valor 0'         => [null,  'Bancard',   0.0,     ['entrada'],          'Online',      'Cortesía',  'entradas'],
    'cortesía por medio de pago'   => [3,     'Cortesía',  5000.0,  ['entrada'],          'Tatakualab',  'Cortesía',  'entradas'],
    'cortesia sin tilde'           => [3,     'cortesia',  5000.0,  ['entrada'],          'Tatakualab',  'Cortesía',  'entradas'],
    'CORTESÍA con espacio'         => [3,     'CORTESÍA ', 5000.0,  ['entrada'],          'Tatakualab',  'Cortesía',  'entradas'],
    'valor null no es cortesía'    => [null,  null,        null,    ['merch'],            'Online',      'Online',    'merch'],
    'mixto'                        => [729,   'Efectivo',  9000.0,  ['entrada', 'merch'], 'San Cosmos',  'En puerta', 'mixto'],
    'sin productos'                => [729,   'Efectivo',  9000.0,  [],                   'San Cosmos',  'En puerta', 'desconocido'],
    'producto sin clasificar'      => [729,   'Efectivo',  9000.0,  ['entrada', null],    'San Cosmos',  'En puerta', 'desconocido'],
    'varias entradas'              => [729,   'Efectivo',  9000.0,  ['entrada', 'entrada'], 'San Cosmos', 'En puerta', 'entradas'],
    'kind desconocido'             => [null,  'Bancard',   9000.0,  ['otro'],             'Online',      'Online',    'desconocido'],
]);

it('recorta la sucursal a 30 caracteres (largo de la columna)', function () {
    expect(clasificador()->classify(9, 'Efectivo', 1.0, ['merch'])->branch)
        ->toBe('Una sucursal con un nombre lar');
});

it('normaliza texto', function () {
    expect(SaleClassifier::normalize('  CortesÍa '))->toBe('cortesia')
        ->and(SaleClassifier::normalize(null))->toBe('');
});
