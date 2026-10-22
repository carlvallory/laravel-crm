<?php

namespace Tests\Feature\AiRead;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

final class AiReadFixtures
{
    /** Los tests corren dentro de una transacción de la conexión default: las queries de la API tienen que verla. */
    public static function useDefaultConnection(): void
    {
        config([
            'krayin-ai-read.connection'    => config('database.default'),
            'krayin-ai-read.wc_connection' => config('database.default'),
        ]);
    }

    public static function stageId(string $code): int
    {
        return (int) DB::table('lead_pipeline_stages')->where('code', $code)->value('id');
    }

    public static function product(array $attrs = []): int
    {
        return DB::table('products')->insertGetId(array_merge([
            'sku'        => 'T-'.Str::upper(Str::random(10)),
            'name'       => 'Producto de prueba',
            'quantity'   => 0,
            'price'      => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    public static function lead(array $attrs = []): int
    {
        return DB::table('leads')->insertGetId(array_merge([
            'title'                  => 'Cliente: Prueba - Orden #'.random_int(9000000, 9999999),
            'lead_value'             => 0,
            'status'                 => 1,
            'lead_pipeline_id'       => 1,
            'lead_pipeline_stage_id' => self::stageId('new'),
            'created_at'             => '2031-03-01 09:00:00',
            'updated_at'             => now(),
        ], $attrs));
    }

    /** Venta concretada con closed_at dado. */
    public static function wonLead(string $closedAt, array $attrs = []): int
    {
        return self::lead(array_merge([
            'lead_pipeline_stage_id' => self::stageId('won'),
            'closed_at'              => $closedAt,
            'created_at'             => $closedAt,
        ], $attrs));
    }

    public static function leadProduct(int $leadId, int $productId, int $quantity, float $price): void
    {
        DB::table('lead_products')->insert([
            'lead_id'    => $leadId,
            'product_id' => $productId,
            'quantity'   => $quantity,
            'price'      => $price,
            'amount'     => $price * $quantity,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public static function person(string $createdAt): int
    {
        return DB::table('persons')->insertGetId([
            'name'       => 'Persona Prueba IA',
            'emails'     => json_encode([['value' => Str::random(12).'@example.test', 'label' => 'work']]),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    /** @param list<array{wc_user_id:int,sucursal:string,merch_point:string}> $rows */
    public static function pointsOfSale(array $rows): void
    {
        self::coreConfig('krayin_financial_reports.settings.points_of_sale', $rows);
    }

    /** @param array<string, list<int>> $map nombre del tag → ids de producto CRM */
    public static function productTags(array $map): void
    {
        self::coreConfig('krayin_financial_reports.settings.product_tags', $map);
    }

    public static function eav(string $entityType, int $entityId, string $code, string $value): void
    {
        $attributeId = DB::table('attributes')
            ->where('entity_type', $entityType)->where('code', $code)->value('id');

        DB::table('attribute_values')->insert([
            'entity_type'  => $entityType,
            'entity_id'    => $entityId,
            'attribute_id' => $attributeId,
            'text_value'   => $value,
        ]);
    }

    public static function token(string $consumidor = 'prueba'): string
    {
        return app(\CarlVallory\KrayinAiRead\Auth\IaTokens::class)->create($consumidor, now()->addDay());
    }

    /** Ruta GET con el mismo stack de middleware que las reales. */
    public static function rutaDePrueba(string $uri, \Closure $accion): void
    {
        Route::middleware(\CarlVallory\KrayinAiRead\Providers\KrayinAiReadServiceProvider::MIDDLEWARE)
            ->get('api/ia/v1/'.$uri, $accion);
    }

    private static function coreConfig(string $code, mixed $value): void
    {
        DB::table('core_config')->updateOrInsert(
            ['code' => $code],
            ['value' => json_encode($value), 'created_at' => now(), 'updated_at' => now()]
        );
    }
}
