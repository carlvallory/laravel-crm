<?php

it('registra la tab de configuración fundraising con sus 2 campos', function () {
    $config = collect(config('core_config'));

    expect($config->pluck('key'))->toContain('fundraising');                        // tab de primer nivel
    expect($config->pluck('key'))->toContain('fundraising.prospectos.exclusiones'); // grupo con campos

    $grupo = $config->firstWhere('key', 'fundraising.prospectos.exclusiones');
    $names = collect($grupo['fields'])->pluck('name');
    expect($names)->toContain('excluded_emails');
    expect($names)->toContain('excluded_documents');
});

it('no rompe las tabs existentes del core', function () {
    expect(collect(config('core_config'))->pluck('key'))->toContain('general');
});
