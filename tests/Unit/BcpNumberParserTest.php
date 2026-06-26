<?php

use CarlVallory\KrayinNetValue\Services\Bcp\BcpNumberParser;

it('parsea el formato paraguayo a float', function () {
    expect(BcpNumberParser::parse('6.719,39'))->toBe(6719.39);
    expect(BcpNumberParser::parse('6.011,46'))->toBe(6011.46);
    expect(BcpNumberParser::parse('  7.000,00 '))->toBe(7000.0);
});

it('devuelve null para ND o vacío', function () {
    expect(BcpNumberParser::parse('ND'))->toBeNull();
    expect(BcpNumberParser::parse(''))->toBeNull();
    expect(BcpNumberParser::parse('  '))->toBeNull();
});
