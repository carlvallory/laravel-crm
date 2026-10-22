<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('registra las conexiones de solo lectura', function () {
    expect(config('database.connections.ia_lectura.driver'))->toBe('mysql')
        ->and(config('database.connections.wc_lectura.driver'))->toBe('mysql')
        ->and(config('krayin-ai-read.connection'))->toBe('ia_lectura')
        ->and(config('krayin-ai-read.statement_timeout'))->toBe(5);
});

it('agrega las columnas crudas a leads y products', function () {
    expect(Schema::hasColumns('leads', ['pos_user_id', 'payment_method', 'payment_method_title']))->toBeTrue()
        ->and(Schema::hasColumn('products', 'product_kind'))->toBeTrue();
});

it('crea los atributos EAV que manda el plugin', function () {
    $leads = DB::table('attributes')->where('entity_type', 'leads')
        ->whereIn('code', ['wc_pos_user_id', 'wc_payment_method', 'wc_payment_method_title'])
        ->pluck('type', 'code')->all();
    ksort($leads);
    $products = DB::table('attributes')->where('entity_type', 'products')
        ->where('code', 'wc_product_kind')->value('type');

    expect($leads)->toBe([
        'wc_payment_method'       => 'text',
        'wc_payment_method_title' => 'text',
        'wc_pos_user_id'          => 'text',
    ])->and($products)->toBe('text');
});

it('crea la tabla ia_tokens', function () {
    expect(Schema::hasColumns('ia_tokens', [
        'id', 'consumidor', 'token_hash', 'vence_en', 'revocado_en', 'ultimo_uso_en', 'created_at',
    ]))->toBeTrue();
});
