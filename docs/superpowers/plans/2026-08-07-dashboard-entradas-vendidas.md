# Dashboard de entradas vendidas del día (KrayinTicketSales) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Un tablero en el CRM que muestre las funciones programadas para hoy y cuántas entradas se vendieron para cada una, refrescado cada 5 minutos desde WooCommerce + FooEvents, visible solo para cuentas @muci.org.

**Architecture:** Paquete Laravel `CarlVallory/KrayinTicketSales` registrado por autodiscovery, sin tocar el core de Krayin. Una segunda conexión de base de datos **de solo lectura** apunta a la base `muci` (misma instancia MySQL que `krayin`, sin túnel). Un comando de consola corre cada 5 minutos, arma un snapshot del día y lo escribe en una tabla local de `krayin`; la vista lee únicamente esa tabla. El riesgo de parseo se concentra en cuatro clases puras testeables sin base de datos.

**Tech Stack:** PHP 8.2, Laravel 10, Krayin CRM, Pest (`tests/Unit` sin app, `tests/Feature` con app), MySQL/MariaDB.

**Spec:** `docs/superpowers/specs/2026-08-07-dashboard-entradas-vendidas-design.md`

## Global Constraints

- **No modificar el core:** nada en `packages/Webkul/*`, `vendor/*`, ni `app/Console/Kernel.php`. Todo vive en `packages/CarlVallory/KrayinTicketSales`. El scheduler se registra **desde el paquete** vía `$this->app->booted()`, no editando el Kernel.
- **Reversible por desinstalación:** toda migración con `down()` completo.
- **Solo lectura sobre `muci`:** ningún `insert`/`update`/`delete`/`Model::save()` sobre la conexión `woocommerce`. El grant de `anthropic_readonly` es `SELECT` puro y debe seguir siéndolo.
- **`CURDATE()` y `NOW()` están prohibidos** en las consultas del paquete. El servidor corre en UTC y el museo en `America/Asuncion` (offset −3, verificado). "Hoy" se calcula en PHP y se pasa como parámetro.
- **Enlace ticket→producto:** siempre `WooCommerceEventsProductID`. La meta `_wooevents_ticket_product_id` **no existe** en esta base y devuelve cero filas sin fallar. Nunca filtrar por `order_item_name`.
- **Parser de bookings:** debe soportar las dos formas de JSON (§5.1 del spec). Solo la forma A pierde 4 de los 7 shows.
- **Sin datos personales:** el snapshot no almacena ni la vista muestra nombres, cédulas, emails ni teléfonos de compradores.
- **`git add` explícito:** nunca `git add -A` en `laravel-crm` (se cuela un gitlink de `packages/Vallory/KrayinFormatter`).
- **Estándar visual MuCi:** tipografía Poppins y paleta de marca (#F17DB1, #00B26B, #000000, #6950A1, #F37043).
- **Naming:** namespace `CarlVallory\KrayinTicketSales\`; nombre composer `carlvallory/krayin-ticket-sales`.
- **Prod:** todo artisan/composer con `/usr/bin/php8.2` explícito. En prod no se toca git del CRM; los paquetes entran por composer desde GitHub.

## File Structure

| Archivo | Responsabilidad |
|---|---|
| `src/Providers/KrayinTicketSalesServiceProvider.php` | Registro: config, conexión `woocommerce`, rutas, vistas, migraciones, comando, scheduler |
| `src/Config/ticket-sales.php` | Zona horaria, umbral de dato viejo |
| `src/Config/menu.php` | Entrada en el menú admin |
| `src/Config/acl.php` | Permiso |
| `src/Support/SpanishDateParser.php` | `"agosto 7, 2026"` → `CarbonImmutable`. Pura |
| `src/Support/BusinessDay.php` | "Hoy" en `America/Asuncion`. Pura |
| `src/Support/BookingsOptionsParser.php` | JSON FooEvents (formas A y B) → funciones. Pura |
| `src/Support/DailySalesAggregator.php` | Programación + tickets + precios → filas del snapshot. Pura |
| `src/Repositories/TicketSalesRepository.php` | Tres consultas tontas a `muci` |
| `src/Models/TicketSalesSnapshot.php` | Modelo de la tabla local |
| `src/Database/Migrations/...` | Tabla del snapshot |
| `src/Console/SyncTicketSalesCommand.php` | Orquesta y escribe el snapshot |
| `src/Http/Controllers/TicketSalesController.php` | Lee snapshot, arma totales |
| `src/Http/routes.php` | Ruta admin |
| `src/Resources/views/index.blade.php` | Vista |

---

### Task 1: Scaffold del paquete + conexión de solo lectura a `muci`

**Files:**
- Create: `packages/CarlVallory/KrayinTicketSales/composer.json`
- Create: `packages/CarlVallory/KrayinTicketSales/src/Providers/KrayinTicketSalesServiceProvider.php`
- Create: `packages/CarlVallory/KrayinTicketSales/src/Config/ticket-sales.php`
- Modify: `composer.json` (raíz) — agregar `carlvallory/krayin-ticket-sales`
- Modify: `.env` y `.env.example` — credenciales de la conexión `woocommerce`
- Test: `tests/Feature/TicketSales/WoocommerceConnectionTest.php`

**Interfaces:**
- Produces: conexión `database.connections.woocommerce`; config `ticket-sales.timezone` y `ticket-sales.stale_after_minutes`; provider `KrayinTicketSalesServiceProvider`.

- [ ] **Step 1: Crear `composer.json` del paquete**

```json
{
    "name": "carlvallory/krayin-ticket-sales",
    "description": "Dashboard de entradas vendidas del día (WooCommerce + FooEvents) para Krayin",
    "type": "library",
    "license": "MIT",
    "authors": [
        {
            "name": "Carlos Vallory",
            "email": "carlos@vallory.com"
        }
    ],
    "require": {},
    "autoload": {
        "psr-4": {
            "CarlVallory\\KrayinTicketSales\\": "src/"
        }
    },
    "extra": {
        "laravel": {
            "providers": [
                "CarlVallory\\KrayinTicketSales\\Providers\\KrayinTicketSalesServiceProvider"
            ]
        }
    }
}
```

- [ ] **Step 2: Crear `src/Config/ticket-sales.php`**

```php
<?php

return [
    /*
     * Zona horaria del museo. El servidor corre en UTC; sin esto, desde las
     * 21:00 de Asunción el dashboard mostraría las funciones de mañana.
     */
    'timezone' => 'America/Asuncion',

    /*
     * A partir de cuántos minutos sin sincronizar el aviso de antigüedad
     * pasa a ser visualmente prominente.
     */
    'stale_after_minutes' => 15,
];
```

- [ ] **Step 3: Crear el ServiceProvider**

```php
<?php

namespace CarlVallory\KrayinTicketSales\Providers;

use Illuminate\Support\ServiceProvider;

class KrayinTicketSalesServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->loadViewsFrom(__DIR__ . '/../Resources/views', 'krayin-ticket-sales');
    }

    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../Config/ticket-sales.php', 'ticket-sales');

        $this->registerWoocommerceConnection();
    }

    /**
     * Conexión de SOLO LECTURA a la base de WooCommerce.
     *
     * Vive en la misma instancia MySQL que `krayin`, así que no hay túnel.
     * La imposibilidad de escribir la garantiza el grant del usuario
     * (SELECT puro), no este código.
     */
    protected function registerWoocommerceConnection(): void
    {
        config([
            'database.connections.woocommerce' => [
                'driver'    => 'mysql',
                'host'      => env('WC_DB_HOST', '127.0.0.1'),
                'port'      => env('WC_DB_PORT', '3306'),
                'database'  => env('WC_DB_DATABASE', 'muci'),
                'username'  => env('WC_DB_USERNAME'),
                'password'  => env('WC_DB_PASSWORD'),
                'prefix'    => env('WC_DB_PREFIX', 'wpzv_'),
                'charset'   => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'strict'    => false,
                'engine'    => null,
            ],
        ]);
    }
}
```

- [ ] **Step 4: Registrar el paquete en el `composer.json` raíz**

En la sección `require`, junto a los otros `carlvallory/*`, agregar:

```json
"carlvallory/krayin-ticket-sales": "@dev",
```

Luego correr:

```bash
cd /home/vallory/code/crm/laravel-crm
composer update carlvallory/krayin-ticket-sales --no-scripts
```

- [ ] **Step 5: Agregar credenciales al `.env` y `.env.example`**

En `.env` (host, base y prefijo verificados; la contraseña **no se escribe en este plan** — está en el gestor de credenciales del equipo y en el `.env` del servidor):

```
WC_DB_HOST=127.0.0.1
WC_DB_PORT=3306
WC_DB_DATABASE=muci
WC_DB_USERNAME=anthropic_readonly
WC_DB_PASSWORD=<contraseña de anthropic_readonly>
WC_DB_PREFIX=wpzv_
```

> Este archivo se commitea al repo: **no pegar la contraseña acá**. `.env` está en `.gitignore`.

En `.env.example` las mismas claves con valores vacíos salvo los defaults no sensibles:

```
WC_DB_HOST=127.0.0.1
WC_DB_PORT=3306
WC_DB_DATABASE=muci
WC_DB_USERNAME=
WC_DB_PASSWORD=
WC_DB_PREFIX=wpzv_
```

> **Nota para desarrollo local:** la base `muci` sólo existe en el servidor. En una máquina local sin ella, el test de este task se salta solo (ver Step 6). No es un fallo.

- [ ] **Step 6: Escribir el test de conexión**

Crear `tests/Feature/TicketSales/WoocommerceConnectionTest.php`:

```php
<?php

use Illuminate\Support\Facades\DB;

function woocommerceReachable(): bool
{
    try {
        DB::connection('woocommerce')->getPdo();

        return true;
    } catch (\Throwable) {
        return false;
    }
}

test('la conexión woocommerce está registrada', function () {
    expect(config('database.connections.woocommerce.database'))->toBe('muci');
    expect(config('database.connections.woocommerce.prefix'))->toBe('wpzv_');
});

test('la conexión lee la base muci', function () {
    if (! woocommerceReachable()) {
        $this->markTestSkipped('Base muci no disponible en este entorno.');
    }

    $count = DB::connection('woocommerce')->table('posts')
        ->where('post_type', 'event_magic_tickets')
        ->count();

    expect($count)->toBeGreaterThan(0);
});

test('la conexión no puede escribir en muci', function () {
    if (! woocommerceReachable()) {
        $this->markTestSkipped('Base muci no disponible en este entorno.');
    }

    expect(fn () => DB::connection('woocommerce')->statement('CREATE TABLE zz_probe_readonly (id INT)'))
        ->toThrow(\Illuminate\Database\QueryException::class);
});
```

- [ ] **Step 7: Correr los tests**

Run: `php artisan test tests/Feature/TicketSales/WoocommerceConnectionTest.php`
Expected: PASS (o SKIPPED los dos últimos si no hay acceso a `muci`).

- [ ] **Step 8: Commit**

```bash
cd /home/vallory/code/crm/laravel-crm
git add packages/CarlVallory/KrayinTicketSales/composer.json \
        packages/CarlVallory/KrayinTicketSales/src/Providers/KrayinTicketSalesServiceProvider.php \
        packages/CarlVallory/KrayinTicketSales/src/Config/ticket-sales.php \
        composer.json composer.lock .env.example \
        tests/Feature/TicketSales/WoocommerceConnectionTest.php
git commit -m "feat(ticket-sales): scaffold del paquete + conexión de solo lectura a muci"
```

---

### Task 2: `SpanishDateParser` — fechas en español del JSON de FooEvents

**Files:**
- Create: `packages/CarlVallory/KrayinTicketSales/src/Support/SpanishDateParser.php`
- Test: `tests/Unit/TicketSales/SpanishDateParserTest.php`

**Interfaces:**
- Produces: `SpanishDateParser::parse(?string $raw): ?CarbonImmutable` — devuelve la fecha a medianoche, o `null` si no se puede parsear. Nunca lanza excepción.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/Unit/TicketSales/SpanishDateParserTest.php`:

```php
<?php

use CarlVallory\KrayinTicketSales\Support\SpanishDateParser;

beforeEach(function () {
    $this->parser = new SpanishDateParser();
});

test('parsea el formato real de FooEvents', function () {
    expect($this->parser->parse('agosto 7, 2026')->format('Y-m-d'))->toBe('2026-08-07');
    expect($this->parser->parse('enero 1, 2027')->format('Y-m-d'))->toBe('2027-01-01');
    expect($this->parser->parse('diciembre 31, 2026')->format('Y-m-d'))->toBe('2026-12-31');
});

test('acepta septiembre y setiembre', function () {
    expect($this->parser->parse('septiembre 15, 2026')->format('Y-m-d'))->toBe('2026-09-15');
    expect($this->parser->parse('setiembre 15, 2026')->format('Y-m-d'))->toBe('2026-09-15');
});

test('tolera mayúsculas, espacios de más y espacio duro', function () {
    expect($this->parser->parse('  Agosto 7, 2026  ')->format('Y-m-d'))->toBe('2026-08-07');
    expect($this->parser->parse("agosto\u{a0}7, 2026")->format('Y-m-d'))->toBe('2026-08-07');
    expect($this->parser->parse('AGOSTO 7,2026')->format('Y-m-d'))->toBe('2026-08-07');
});

test('devuelve null sin lanzar ante entrada inválida', function () {
    expect($this->parser->parse(null))->toBeNull();
    expect($this->parser->parse(''))->toBeNull();
    expect($this->parser->parse('basura'))->toBeNull();
    expect($this->parser->parse('smarch 7, 2026'))->toBeNull();
    expect($this->parser->parse('2026-08-07'))->toBeNull();
    expect($this->parser->parse('febrero 30, 2026'))->toBeNull();
});
```

- [ ] **Step 2: Correr el test para verificar que falla**

Run: `php artisan test tests/Unit/TicketSales/SpanishDateParserTest.php`
Expected: FAIL con `Class "CarlVallory\KrayinTicketSales\Support\SpanishDateParser" not found`.

- [ ] **Step 3: Implementar**

Crear `packages/CarlVallory/KrayinTicketSales/src/Support/SpanishDateParser.php`:

```php
<?php

namespace CarlVallory\KrayinTicketSales\Support;

use Carbon\CarbonImmutable;

/**
 * FooEvents guarda las fechas de las funciones como texto localizado
 * ("agosto 7, 2026"). No hay una versión ISO en la base, así que hay que
 * parsear el texto.
 */
class SpanishDateParser
{
    private const MESES = [
        'enero'      => 1,
        'febrero'    => 2,
        'marzo'      => 3,
        'abril'      => 4,
        'mayo'       => 5,
        'junio'      => 6,
        'julio'      => 7,
        'agosto'     => 8,
        'septiembre' => 9,
        'setiembre'  => 9,
        'octubre'    => 10,
        'noviembre'  => 11,
        'diciembre'  => 12,
    ];

    public function parse(?string $raw): ?CarbonImmutable
    {
        if ($raw === null) {
            return null;
        }

        // El espacio duro (U+00A0) aparece en datos reales de esta base.
        $normalized = str_replace("\u{a0}", ' ', $raw);
        $normalized = mb_strtolower(trim($normalized));

        if (! preg_match('/^([a-záéíóúñ]+)\s+(\d{1,2})\s*,\s*(\d{4})$/u', $normalized, $matches)) {
            return null;
        }

        $month = self::MESES[$matches[1]] ?? null;

        if ($month === null) {
            return null;
        }

        $day  = (int) $matches[2];
        $year = (int) $matches[3];

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, 'UTC');
    }
}
```

- [ ] **Step 4: Correr el test para verificar que pasa**

Run: `php artisan test tests/Unit/TicketSales/SpanishDateParserTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add packages/CarlVallory/KrayinTicketSales/src/Support/SpanishDateParser.php \
        tests/Unit/TicketSales/SpanishDateParserTest.php
git commit -m "feat(ticket-sales): parser de fechas en español de FooEvents"
```

---

### Task 3: `BusinessDay` — "hoy" en la zona del museo

**Files:**
- Create: `packages/CarlVallory/KrayinTicketSales/src/Support/BusinessDay.php`
- Test: `tests/Unit/TicketSales/BusinessDayTest.php`

**Interfaces:**
- Consumes: config `ticket-sales.timezone` (con default `'America/Asuncion'` si config no está disponible, para poder usarse en `tests/Unit` sin app).
- Produces: `BusinessDay::__construct(string $timezone = 'America/Asuncion')`, `BusinessDay::todayString(?CarbonImmutable $now = null): string` (formato `Y-m-d`), `BusinessDay::today(?CarbonImmutable $now = null): CarbonImmutable`.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/Unit/TicketSales/BusinessDayTest.php`:

```php
<?php

use Carbon\CarbonImmutable;
use CarlVallory\KrayinTicketSales\Support\BusinessDay;

beforeEach(function () {
    $this->day = new BusinessDay('America/Asuncion');
});

test('a media tarde el día es el mismo en UTC y en Asunción', function () {
    $now = CarbonImmutable::create(2026, 8, 7, 20, 30, 0, 'UTC'); // 17:30 en Asunción

    expect($this->day->todayString($now))->toBe('2026-08-07');
});

test('a las 22:00 de Asunción sigue siendo hoy, aunque en UTC ya sea mañana', function () {
    // 01:00 UTC del 8 = 22:00 del 7 en Asunción. Este es el bug que CURDATE() causaría.
    $now = CarbonImmutable::create(2026, 8, 8, 1, 0, 0, 'UTC');

    expect($this->day->todayString($now))->toBe('2026-08-07');
});

test('pasada la medianoche local ya es el día siguiente', function () {
    $now = CarbonImmutable::create(2026, 8, 8, 3, 30, 0, 'UTC'); // 00:30 del 8 en Asunción

    expect($this->day->todayString($now))->toBe('2026-08-08');
});

test('today devuelve medianoche en la zona del museo', function () {
    $now   = CarbonImmutable::create(2026, 8, 8, 1, 0, 0, 'UTC');
    $today = $this->day->today($now);

    expect($today->format('Y-m-d H:i:s'))->toBe('2026-08-07 00:00:00');
    expect($today->timezoneName)->toBe('America/Asuncion');
});

test('sin argumento usa la hora actual', function () {
    expect($this->day->todayString())->toMatch('/^\d{4}-\d{2}-\d{2}$/');
});

test('la tzdata del entorno da -3 en agosto', function () {
    // Paraguay abolió el horario de verano en 2024 y quedó fijo en UTC-3.
    // Una tzdata anterior devolvería -4 en agosto y correría el día un hora.
    // Verificado contra la base: post_date vs post_date_gmt = -3 exacto.
    $offset = (new DateTime('2026-08-07 12:00:00', new DateTimeZone('America/Asuncion')))->getOffset();

    expect($offset)->toBe(-10800); // -3 horas en segundos
});
```

- [ ] **Step 2: Correr el test para verificar que falla**

Run: `php artisan test tests/Unit/TicketSales/BusinessDayTest.php`
Expected: FAIL con `Class "CarlVallory\KrayinTicketSales\Support\BusinessDay" not found`.

- [ ] **Step 3: Implementar**

Crear `packages/CarlVallory/KrayinTicketSales/src/Support/BusinessDay.php`:

```php
<?php

namespace CarlVallory\KrayinTicketSales\Support;

use Carbon\CarbonImmutable;

/**
 * El servidor corre en UTC y el museo en America/Asuncion (offset -3).
 * Usar CURDATE() de MySQL haría que, desde las 21:00 locales, el dashboard
 * mostrara las funciones de mañana justo cuando se cierra la caja del día.
 */
class BusinessDay
{
    public function __construct(
        private string $timezone = 'America/Asuncion'
    ) {
    }

    public function today(?CarbonImmutable $now = null): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now('UTC'))
            ->setTimezone($this->timezone)
            ->startOfDay();
    }

    public function todayString(?CarbonImmutable $now = null): string
    {
        return $this->today($now)->format('Y-m-d');
    }
}
```

- [ ] **Step 4: Correr el test para verificar que pasa**

Run: `php artisan test tests/Unit/TicketSales/BusinessDayTest.php`
Expected: PASS, 6 tests. Si falla el de tzdata, actualizar la base de datos de zonas horarias del entorno antes de seguir — el dashboard mostraría el día equivocado de madrugada.

- [ ] **Step 5: Registrar el binding en el ServiceProvider**

En `register()` de `KrayinTicketSalesServiceProvider`, después de `registerWoocommerceConnection();`:

```php
        $this->app->singleton(\CarlVallory\KrayinTicketSales\Support\BusinessDay::class, function () {
            return new \CarlVallory\KrayinTicketSales\Support\BusinessDay(
                config('ticket-sales.timezone', 'America/Asuncion')
            );
        });
```

- [ ] **Step 6: Commit**

```bash
git add packages/CarlVallory/KrayinTicketSales/src/Support/BusinessDay.php \
        packages/CarlVallory/KrayinTicketSales/src/Providers/KrayinTicketSalesServiceProvider.php \
        tests/Unit/TicketSales/BusinessDayTest.php
git commit -m "feat(ticket-sales): cálculo de 'hoy' en America/Asuncion"
```

---

### Task 4: `BookingsOptionsParser` — las dos formas de JSON

**Files:**
- Create: `packages/CarlVallory/KrayinTicketSales/src/Support/BookingsOptionsParser.php`
- Test: `tests/Unit/TicketSales/BookingsOptionsParserTest.php`

**Interfaces:**
- Consumes: `SpanishDateParser` por constructor.
- Produces: `BookingsOptionsParser::__construct(SpanishDateParser $dates)` y `parse(?string $json): array`, que devuelve una lista de `['slot' => string, 'date' => string /* Y-m-d */, 'stock' => int]`. Nunca lanza.

**Contexto:** este es el punto más frágil del sistema. Ver §5.1 del spec. Un parser que solo entienda la forma A pierde 4 de los 7 shows del día.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/Unit/TicketSales/BookingsOptionsParserTest.php`:

```php
<?php

use CarlVallory\KrayinTicketSales\Support\BookingsOptionsParser;
use CarlVallory\KrayinTicketSales\Support\SpanishDateParser;

beforeEach(function () {
    $this->parser = new BookingsOptionsParser(new SpanishDateParser());
});

test('forma A: add_date anidado', function () {
    // Producto 192862 real, recortado.
    $json = json_encode([
        'ouxdnafhvfajelesmudp' => [
            'label'          => 'Entrada general',
            'formatted_time' => '(10:30)',
            'add_date'       => [
                'bhwytbybxprriwmnyrje' => ['date' => 'agosto 4, 2026', 'stock' => '27'],
                'dtlyptyudclrczpuazvb' => ['date' => 'agosto 7, 2026', 'stock' => '0'],
            ],
        ],
    ]);

    expect($this->parser->parse($json))->toBe([
        ['slot' => 'Entrada general (10:30)', 'date' => '2026-08-04', 'stock' => 27],
        ['slot' => 'Entrada general (10:30)', 'date' => '2026-08-07', 'stock' => 0],
    ]);
});

test('forma B: claves planas con sufijo _add_date', function () {
    // Producto 194099 real, recortado. Sin formatted_time: se compone de hour/minute.
    $json = json_encode([
        'fnblmbjmeyvmozuctqep' => [
            'label'                          => 'Entrada general',
            'hour'                           => '19',
            'minute'                         => '00',
            'period'                         => '',
            'add_time'                       => 'enabled',
            'pkkamubfpsgfajqiceka_add_date'  => 'agosto 1, 2026',
            'pkkamubfpsgfajqiceka_stock'     => '3',
            'hcilttetcjeihjxtouov_add_date'  => 'agosto 15, 2026',
            'hcilttetcjeihjxtouov_stock'     => '30',
        ],
    ]);

    expect($this->parser->parse($json))->toBe([
        ['slot' => 'Entrada general (19:00)', 'date' => '2026-08-01', 'stock' => 3],
        ['slot' => 'Entrada general (19:00)', 'date' => '2026-08-15', 'stock' => 30],
    ]);
});

test('el label puede traer su propio horario, distinto al del slot', function () {
    // Producto 192637 real: el ticket guarda "BioEstanque (16:00) (17:00)".
    $json = json_encode([
        'k' => [
            'label'          => 'BioEstanque (16:00)',
            'formatted_time' => '(17:00)',
            'add_date'       => ['d' => ['date' => 'agosto 7, 2026', 'stock' => '18']],
        ],
    ]);

    expect($this->parser->parse($json)[0]['slot'])->toBe('BioEstanque (16:00) (17:00)');
});

test('stock viene como string o como int', function () {
    $json = json_encode([
        'k' => [
            'label'          => 'X',
            'formatted_time' => '(10:00)',
            'add_date'       => [
                'a' => ['date' => 'agosto 13, 2026', 'stock' => '13'],
                'b' => ['date' => 'agosto 14, 2026', 'stock' => 14],
            ],
        ],
    ]);

    expect(array_column($this->parser->parse($json), 'stock'))->toBe([13, 14]);
});

test('devuelve lista vacía sin lanzar ante metadatos degenerados', function () {
    expect($this->parser->parse(null))->toBe([]);
    expect($this->parser->parse(''))->toBe([]);
    expect($this->parser->parse('[]'))->toBe([]);
    expect($this->parser->parse('{}'))->toBe([]);
    expect($this->parser->parse('null'))->toBe([]);
    expect($this->parser->parse('{roto'))->toBe([]);
    expect($this->parser->parse(json_encode(['k' => 'no es un array'])))->toBe([]);
});

test('descarta fechas ilegibles pero conserva el resto del slot', function () {
    $json = json_encode([
        'k' => [
            'label'          => 'X',
            'formatted_time' => '(10:00)',
            'add_date'       => [
                'a' => ['date' => 'basura', 'stock' => '5'],
                'b' => ['date' => 'agosto 7, 2026', 'stock' => '9'],
            ],
        ],
    ]);

    expect($this->parser->parse($json))->toBe([
        ['slot' => 'X (10:00)', 'date' => '2026-08-07', 'stock' => 9],
    ]);
});

test('un slot sin horario usa solo el label', function () {
    $json = json_encode([
        'k' => [
            'label'    => 'Entrada única',
            'add_date' => ['a' => ['date' => 'agosto 7, 2026', 'stock' => '5']],
        ],
    ]);

    expect($this->parser->parse($json)[0]['slot'])->toBe('Entrada única');
});
```

- [ ] **Step 2: Correr el test para verificar que falla**

Run: `php artisan test tests/Unit/TicketSales/BookingsOptionsParserTest.php`
Expected: FAIL con `Class "CarlVallory\KrayinTicketSales\Support\BookingsOptionsParser" not found`.

- [ ] **Step 3: Implementar**

Crear `packages/CarlVallory/KrayinTicketSales/src/Support/BookingsOptionsParser.php`:

```php
<?php

namespace CarlVallory\KrayinTicketSales\Support;

/**
 * Lee la meta `fooevents_bookings_options_serialized` de un producto.
 *
 * En esta base conviven DOS formatos incompatibles:
 *
 *   Forma A (anidada):  {slot: {label, formatted_time, add_date: {k: {date, stock}}}}
 *   Forma B (plana):    {slot: {label, hour, minute, "k_add_date": "...", "k_stock": "..."}}
 *
 * Soportar solo la forma A pierde 4 de los 7 shows del día. Se elige por
 * presencia de la clave `add_date`.
 */
class BookingsOptionsParser
{
    public function __construct(
        private SpanishDateParser $dates
    ) {
    }

    /**
     * @return array<int, array{slot: string, date: string, stock: int}>
     */
    public function parse(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return [];
        }

        $functions = [];

        foreach ($decoded as $slotOptions) {
            if (! is_array($slotOptions)) {
                continue;
            }

            $slot = $this->slotName($slotOptions);

            foreach ($this->datePairs($slotOptions) as [$rawDate, $rawStock]) {
                $date = $this->dates->parse($rawDate);

                if ($date === null) {
                    continue;
                }

                $functions[] = [
                    'slot'  => $slot,
                    'date'  => $date->format('Y-m-d'),
                    'stock' => (int) $rawStock,
                ];
            }
        }

        return $functions;
    }

    /**
     * El ticket guarda el slot como "label formatted_time". El label puede
     * traer su propio horario distinto al del slot; no se intenta limpiarlo.
     */
    private function slotName(array $slotOptions): string
    {
        $label = trim((string) ($slotOptions['label'] ?? ''));
        $time  = trim((string) ($slotOptions['formatted_time'] ?? ''));

        if ($time === '' && isset($slotOptions['hour'])) {
            $hour   = str_pad((string) $slotOptions['hour'], 2, '0', STR_PAD_LEFT);
            $minute = str_pad((string) ($slotOptions['minute'] ?? '0'), 2, '0', STR_PAD_LEFT);
            $time   = "({$hour}:{$minute})";
        }

        return trim($label . ' ' . $time);
    }

    /**
     * @return array<int, array{0: string, 1: mixed}> pares [fechaCruda, stockCrudo]
     */
    private function datePairs(array $slotOptions): array
    {
        if (isset($slotOptions['add_date']) && is_array($slotOptions['add_date'])) {
            return $this->nestedPairs($slotOptions['add_date']);
        }

        return $this->flatPairs($slotOptions);
    }

    private function nestedPairs(array $addDate): array
    {
        $pairs = [];

        foreach ($addDate as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $pairs[] = [(string) ($entry['date'] ?? ''), $entry['stock'] ?? 0];
        }

        return $pairs;
    }

    private function flatPairs(array $slotOptions): array
    {
        $suffix = '_add_date';
        $pairs  = [];

        foreach ($slotOptions as $key => $value) {
            if (! is_string($key) || ! str_ends_with($key, $suffix) || ! is_scalar($value)) {
                continue;
            }

            $prefix  = substr($key, 0, -strlen($suffix));
            $pairs[] = [(string) $value, $slotOptions[$prefix . '_stock'] ?? 0];
        }

        return $pairs;
    }
}
```

- [ ] **Step 4: Correr el test para verificar que pasa**

Run: `php artisan test tests/Unit/TicketSales/BookingsOptionsParserTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 5: Commit**

```bash
git add packages/CarlVallory/KrayinTicketSales/src/Support/BookingsOptionsParser.php \
        tests/Unit/TicketSales/BookingsOptionsParserTest.php
git commit -m "feat(ticket-sales): parser de bookings de FooEvents con soporte de ambos formatos"
```

---

### Task 5: Tabla del snapshot + modelo

**Files:**
- Create: `packages/CarlVallory/KrayinTicketSales/src/Database/Migrations/2026_08_07_120000_create_muci_ticket_sales_snapshot_table.php`
- Create: `packages/CarlVallory/KrayinTicketSales/src/Models/TicketSalesSnapshot.php`
- Modify: `packages/CarlVallory/KrayinTicketSales/src/Providers/KrayinTicketSalesServiceProvider.php` (cargar migraciones)
- Test: `tests/Feature/TicketSales/TicketSalesSnapshotTest.php`

**Interfaces:**
- Produces: tabla `muci_ticket_sales_snapshot` en la conexión por defecto (`krayin`); modelo `TicketSalesSnapshot` con `$fillable = ['function_date','product_id','product_name','slot','sort_time','tickets','pending_tickets','stock','revenue','synced_at']` y cast de `synced_at` a `datetime`, `revenue` a `decimal:2`.

- [ ] **Step 1: Escribir la migración**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('muci_ticket_sales_snapshot', function (Blueprint $table) {
            $table->id();
            $table->date('function_date');
            $table->unsignedBigInteger('product_id');
            $table->string('product_name');
            $table->string('slot');
            $table->string('sort_time', 5)->nullable();
            $table->unsignedInteger('tickets')->default(0);
            $table->unsignedInteger('pending_tickets')->default(0);
            $table->integer('stock')->nullable();
            $table->decimal('revenue', 14, 2)->default(0);
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->index(['function_date', 'sort_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('muci_ticket_sales_snapshot');
    }
};
```

> `stock` es nullable e `integer` con signo a propósito: es `null` cuando la función ya no figura en la programación pero tiene ventas, y FooEvents podría entregar un valor negativo.

- [ ] **Step 2: Escribir el modelo**

Crear `packages/CarlVallory/KrayinTicketSales/src/Models/TicketSalesSnapshot.php`:

```php
<?php

namespace CarlVallory\KrayinTicketSales\Models;

use Illuminate\Database\Eloquent\Model;

class TicketSalesSnapshot extends Model
{
    protected $table = 'muci_ticket_sales_snapshot';

    protected $fillable = [
        'function_date',
        'product_id',
        'product_name',
        'slot',
        'sort_time',
        'tickets',
        'pending_tickets',
        'stock',
        'revenue',
        'synced_at',
    ];

    protected $casts = [
        'function_date'   => 'date',
        'tickets'         => 'integer',
        'pending_tickets' => 'integer',
        'stock'           => 'integer',
        'revenue'         => 'decimal:2',
        'synced_at'       => 'datetime',
    ];
}
```

- [ ] **Step 3: Cargar las migraciones desde el ServiceProvider**

En `boot()` de `KrayinTicketSalesServiceProvider`, agregar:

```php
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
```

- [ ] **Step 4: Escribir el test**

Crear `tests/Feature/TicketSales/TicketSalesSnapshotTest.php`:

```php
<?php

use CarlVallory\KrayinTicketSales\Models\TicketSalesSnapshot;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

test('el snapshot se persiste y se lee', function () {
    TicketSalesSnapshot::create([
        'function_date'   => '2026-08-07',
        'product_id'      => 194099,
        'product_name'    => 'Mundos en órbita',
        'slot'            => 'Entrada 2x1 (19:00)',
        'sort_time'       => '19:00',
        'tickets'         => 7,
        'pending_tickets' => 0,
        'stock'           => 0,
        'revenue'         => 280000,
        'synced_at'       => now(),
    ]);

    $row = TicketSalesSnapshot::where('product_id', 194099)->first();

    expect($row->tickets)->toBe(7);
    expect($row->slot)->toBe('Entrada 2x1 (19:00)');
    expect((float) $row->revenue)->toBe(280000.0);
});

test('stock acepta null para funciones fuera de la programación', function () {
    $row = TicketSalesSnapshot::create([
        'function_date'   => '2026-08-07',
        'product_id'      => 1,
        'product_name'    => 'X',
        'slot'            => 'Y',
        'sort_time'       => null,
        'tickets'         => 1,
        'pending_tickets' => 0,
        'stock'           => null,
        'revenue'         => 0,
        'synced_at'       => now(),
    ]);

    expect($row->fresh()->stock)->toBeNull();
});
```

- [ ] **Step 5: Correr migración y tests**

Run:
```bash
php artisan migrate
php artisan test tests/Feature/TicketSales/TicketSalesSnapshotTest.php
```
Expected: migración `Ran`, 2 tests PASS.

- [ ] **Step 6: Commit**

```bash
git add packages/CarlVallory/KrayinTicketSales/src/Database/Migrations/ \
        packages/CarlVallory/KrayinTicketSales/src/Models/TicketSalesSnapshot.php \
        packages/CarlVallory/KrayinTicketSales/src/Providers/KrayinTicketSalesServiceProvider.php \
        tests/Feature/TicketSales/TicketSalesSnapshotTest.php
git commit -m "feat(ticket-sales): tabla y modelo del snapshot diario"
```

---

### Task 6: `TicketSalesRepository` — las tres consultas a `muci`

**Files:**
- Create: `packages/CarlVallory/KrayinTicketSales/src/Repositories/TicketSalesRepository.php`
- Test: `tests/Feature/TicketSales/TicketSalesRepositoryTest.php`

**Interfaces:**
- Produces:
  - `bookingProducts(): Collection` — filas `{product_id: int, product_name: string, bookings: string}` de productos publicados con la meta de bookings.
  - `ticketsFor(string $date): Collection` — **una fila por ticket**: `{ticket_id: int, product_id: int, product_name: ?string, slot: ?string, order_id: ?int, order_status: ?string}`.
  - `unitPricesFor(array $orderIds): array` — mapa `"{orderId}:{productId}" => float` con el precio unitario de cada línea.

**Diseño:** las consultas son deliberadamente tontas y devuelven filas crudas; toda la agregación vive en `DailySalesAggregator` (Task 7), que se testea sin base de datos. El volumen de un día son decenas de filas, así que traerlas sin agregar no tiene costo.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/Feature/TicketSales/TicketSalesRepositoryTest.php`:

```php
<?php

use CarlVallory\KrayinTicketSales\Repositories\TicketSalesRepository;
use Illuminate\Support\Facades\DB;

function muciReachable(): bool
{
    try {
        DB::connection('woocommerce')->getPdo();

        return true;
    } catch (\Throwable) {
        return false;
    }
}

beforeEach(function () {
    if (! muciReachable()) {
        $this->markTestSkipped('Base muci no disponible en este entorno.');
    }

    $this->repo = app(TicketSalesRepository::class);
});

test('trae productos publicados con meta de bookings', function () {
    $products = $this->repo->bookingProducts();

    expect($products)->not->toBeEmpty();
    expect($products->first())->toHaveKeys(['product_id', 'product_name', 'bookings']);
    expect($products->pluck('product_id'))->toContain(194099);
});

test('trae una fila por ticket de una fecha con ventas conocidas', function () {
    // 2026-08-08: 7 entradas verificadas para el producto 194099.
    $tickets = $this->repo->ticketsFor('2026-08-08');

    expect($tickets->where('product_id', 194099))->toHaveCount(7);
    expect($tickets->first())->toHaveKeys([
        'ticket_id', 'product_id', 'product_name', 'slot', 'order_id', 'order_status',
    ]);
});

test('una fecha sin funciones devuelve colección vacía', function () {
    expect($this->repo->ticketsFor('1999-01-01'))->toBeEmpty();
});

test('los precios unitarios se resuelven por orden y producto', function () {
    $tickets  = $this->repo->ticketsFor('2026-08-08');
    $orderIds = $tickets->pluck('order_id')->filter()->unique()->values()->all();

    $prices = $this->repo->unitPricesFor($orderIds);

    expect($prices)->not->toBeEmpty();
    // ₲40.000 por entrada, verificado en la sesión del 2026-08-06.
    expect(array_values($prices))->each->toBeGreaterThan(0);
});

test('unitPricesFor con lista vacía no consulta y devuelve vacío', function () {
    expect($this->repo->unitPricesFor([]))->toBe([]);
});
```

- [ ] **Step 2: Correr el test para verificar que falla**

Run: `php artisan test tests/Feature/TicketSales/TicketSalesRepositoryTest.php`
Expected: FAIL con `Target class [CarlVallory\KrayinTicketSales\Repositories\TicketSalesRepository] does not exist.`

- [ ] **Step 3: Implementar**

Crear `packages/CarlVallory/KrayinTicketSales/src/Repositories/TicketSalesRepository.php`:

```php
<?php

namespace CarlVallory\KrayinTicketSales\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Consultas de SOLO LECTURA contra la base de WooCommerce.
 *
 * Devuelven filas crudas a propósito: la agregación vive en
 * DailySalesAggregator, que se puede testear sin base de datos.
 */
class TicketSalesRepository
{
    private const CONNECTION = 'woocommerce';

    public function connection()
    {
        return DB::connection(self::CONNECTION);
    }

    /**
     * Productos publicados que tienen programación de FooEvents.
     */
    public function bookingProducts(): Collection
    {
        return $this->connection()->table('posts as p')
            ->join('postmeta as m', function ($join) {
                $join->on('m.post_id', '=', 'p.ID')
                    ->where('m.meta_key', '=', 'fooevents_bookings_options_serialized');
            })
            ->where('p.post_type', 'product')
            ->where('p.post_status', 'publish')
            ->select([
                'p.ID as product_id',
                'p.post_title as product_name',
                'm.meta_value as bookings',
            ])
            ->get()
            ->map(fn ($row) => [
                'product_id'   => (int) $row->product_id,
                'product_name' => (string) $row->product_name,
                'bookings'     => (string) $row->bookings,
            ]);
    }

    /**
     * Una fila por ticket de la fecha dada.
     *
     * El enlace ticket->producto es WooCommerceEventsProductID: la meta
     * _wooevents_ticket_product_id NO existe en esta base y filtrar por
     * order_item_name es frágil ante renombres.
     *
     * $date es la fecha LOCAL del museo, calculada en PHP. Nunca CURDATE().
     */
    public function ticketsFor(string $date): Collection
    {
        return $this->connection()->table('posts as t')
            ->join('postmeta as bd', function ($join) {
                $join->on('bd.post_id', '=', 't.ID')
                    ->where('bd.meta_key', '=', 'WooCommerceEventsBookingDateMySQLFormat');
            })
            ->join('postmeta as pid', function ($join) {
                $join->on('pid.post_id', '=', 't.ID')
                    ->where('pid.meta_key', '=', 'WooCommerceEventsProductID');
            })
            ->leftJoin('postmeta as sl', function ($join) {
                $join->on('sl.post_id', '=', 't.ID')
                    ->where('sl.meta_key', '=', 'WooCommerceEventsBookingSlot');
            })
            ->leftJoin('postmeta as oid', function ($join) {
                $join->on('oid.post_id', '=', 't.ID')
                    ->where('oid.meta_key', '=', 'WooCommerceEventsOrderID');
            })
            ->leftJoin('posts as prod', function ($join) {
                $join->on('prod.ID', '=', DB::raw('CAST(pid.meta_value AS UNSIGNED)'));
            })
            ->leftJoin('wc_orders as o', function ($join) {
                $join->on('o.id', '=', DB::raw('CAST(oid.meta_value AS UNSIGNED)'));
            })
            ->where('t.post_type', 'event_magic_tickets')
            ->whereRaw('DATE(bd.meta_value) = ?', [$date])
            ->select([
                't.ID as ticket_id',
                'pid.meta_value as product_id',
                'prod.post_title as product_name',
                'sl.meta_value as slot',
                'oid.meta_value as order_id',
                'o.status as order_status',
            ])
            ->get()
            ->map(fn ($row) => [
                'ticket_id'    => (int) $row->ticket_id,
                'product_id'   => (int) $row->product_id,
                'product_name' => $row->product_name !== null ? (string) $row->product_name : null,
                'slot'         => $row->slot !== null ? (string) $row->slot : null,
                'order_id'     => $row->order_id !== null ? (int) $row->order_id : null,
                'order_status' => $row->order_status !== null ? (string) $row->order_status : null,
            ]);
    }

    /**
     * Precio unitario por (orden, producto): _line_total / _qty.
     *
     * Se resuelve por línea y no por ticket porque una orden puede tener
     * entradas para fechas distintas; multiplicar el unitario por la cantidad
     * de tickets de cada fecha reparte bien el total.
     *
     * @param  array<int, int>  $orderIds
     * @return array<string, float>  mapa "{orderId}:{productId}" => precio unitario
     */
    public function unitPricesFor(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $rows = $this->connection()->table('woocommerce_order_items as oi')
            ->join('woocommerce_order_itemmeta as pm', function ($join) {
                $join->on('pm.order_item_id', '=', 'oi.order_item_id')
                    ->where('pm.meta_key', '=', '_product_id');
            })
            ->join('woocommerce_order_itemmeta as qm', function ($join) {
                $join->on('qm.order_item_id', '=', 'oi.order_item_id')
                    ->where('qm.meta_key', '=', '_qty');
            })
            ->join('woocommerce_order_itemmeta as tm', function ($join) {
                $join->on('tm.order_item_id', '=', 'oi.order_item_id')
                    ->where('tm.meta_key', '=', '_line_total');
            })
            ->where('oi.order_item_type', 'line_item')
            ->whereIn('oi.order_id', $orderIds)
            ->select([
                'oi.order_id',
                'pm.meta_value as product_id',
                'qm.meta_value as qty',
                'tm.meta_value as line_total',
            ])
            ->get();

        $prices = [];

        foreach ($rows as $row) {
            $qty = (float) $row->qty;

            if ($qty <= 0) {
                continue;
            }

            $prices["{$row->order_id}:{$row->product_id}"] = (float) $row->line_total / $qty;
        }

        return $prices;
    }
}
```

- [ ] **Step 4: Correr el test para verificar que pasa**

Run: `php artisan test tests/Feature/TicketSales/TicketSalesRepositoryTest.php`
Expected: PASS, 5 tests (o SKIPPED sin acceso a `muci`).

- [ ] **Step 5: Commit**

```bash
git add packages/CarlVallory/KrayinTicketSales/src/Repositories/TicketSalesRepository.php \
        tests/Feature/TicketSales/TicketSalesRepositoryTest.php
git commit -m "feat(ticket-sales): repositorio de lectura sobre WooCommerce + FooEvents"
```

---

### Task 7: `DailySalesAggregator` — cruce de programación y ventas

**Files:**
- Create: `packages/CarlVallory/KrayinTicketSales/src/Support/DailySalesAggregator.php`
- Test: `tests/Unit/TicketSales/DailySalesAggregatorTest.php`

**Interfaces:**
- Produces: `DailySalesAggregator::aggregate(array $scheduled, iterable $tickets, array $unitPrices): array`.
  - `$scheduled`: filas `['product_id' => int, 'product_name' => string, 'slot' => string, 'stock' => int]`.
  - `$tickets`: filas tal como las devuelve `TicketSalesRepository::ticketsFor()`.
  - `$unitPrices`: mapa de `TicketSalesRepository::unitPricesFor()`.
  - Devuelve filas `['product_id','product_name','slot','sort_time','tickets','pending_tickets','stock','revenue']` ordenadas por `sort_time` y luego por `product_name`.

**Reglas:**
1. Toda función programada aparece, aunque tenga cero ventas.
2. Todo ticket aparece, aunque su función ya no figure en la programación (con `stock = null`). Perder ventas es peor que mostrar una función de más.
3. `pending_tickets` cuenta los tickets cuya orden **no** está en `wc-completed`.
4. `sort_time` sale del **último** `(HH:MM)` del slot, porque el label puede traer un horario propio antes.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/Unit/TicketSales/DailySalesAggregatorTest.php`:

```php
<?php

use CarlVallory\KrayinTicketSales\Support\DailySalesAggregator;

beforeEach(function () {
    $this->agg = new DailySalesAggregator();
});

test('una función programada sin ventas aparece en cero', function () {
    $scheduled = [
        ['product_id' => 192637, 'product_name' => 'Bioestanque', 'slot' => 'BioEstanque (17:00) (18:00)', 'stock' => 20],
    ];

    $result = $this->agg->aggregate($scheduled, [], []);

    expect($result)->toHaveCount(1);
    expect($result[0]['tickets'])->toBe(0);
    expect($result[0]['revenue'])->toBe(0.0);
    expect($result[0]['stock'])->toBe(20);
});

test('cruza ventas con la programación y calcula recaudación', function () {
    $scheduled = [
        ['product_id' => 194099, 'product_name' => 'Mundos en órbita', 'slot' => 'Entrada 2x1 (19:00)', 'stock' => 0],
    ];

    $tickets = [
        ['ticket_id' => 1, 'product_id' => 194099, 'product_name' => 'Mundos en órbita', 'slot' => 'Entrada 2x1 (19:00)', 'order_id' => 500, 'order_status' => 'wc-completed'],
        ['ticket_id' => 2, 'product_id' => 194099, 'product_name' => 'Mundos en órbita', 'slot' => 'Entrada 2x1 (19:00)', 'order_id' => 500, 'order_status' => 'wc-completed'],
    ];

    $result = $this->agg->aggregate($scheduled, $tickets, ['500:194099' => 40000.0]);

    expect($result[0]['tickets'])->toBe(2);
    expect($result[0]['revenue'])->toBe(80000.0);
    expect($result[0]['pending_tickets'])->toBe(0);
});

test('un ticket cuya función ya no está programada igual aparece, con stock null', function () {
    $tickets = [
        ['ticket_id' => 1, 'product_id' => 999, 'product_name' => 'Show retirado', 'slot' => 'Entrada general (20:00)', 'order_id' => 1, 'order_status' => 'wc-completed'],
    ];

    $result = $this->agg->aggregate([], $tickets, ['1:999' => 50000.0]);

    expect($result)->toHaveCount(1);
    expect($result[0]['product_name'])->toBe('Show retirado');
    expect($result[0]['tickets'])->toBe(1);
    expect($result[0]['stock'])->toBeNull();
});

test('cuenta como pendientes los tickets de órdenes no completadas', function () {
    $tickets = [
        ['ticket_id' => 1, 'product_id' => 1, 'product_name' => 'X', 'slot' => 'S (10:00)', 'order_id' => 7, 'order_status' => 'wc-completed'],
        ['ticket_id' => 2, 'product_id' => 1, 'product_name' => 'X', 'slot' => 'S (10:00)', 'order_id' => 8, 'order_status' => 'wc-pending'],
        ['ticket_id' => 3, 'product_id' => 1, 'product_name' => 'X', 'slot' => 'S (10:00)', 'order_id' => null, 'order_status' => null],
    ];

    $result = $this->agg->aggregate([], $tickets, []);

    expect($result[0]['tickets'])->toBe(3);
    expect($result[0]['pending_tickets'])->toBe(2);
});

test('sort_time toma el último horario del slot', function () {
    $scheduled = [
        ['product_id' => 1, 'product_name' => 'B', 'slot' => 'BioEstanque (16:00) (17:00)', 'stock' => 1],
        ['product_id' => 2, 'product_name' => 'A', 'slot' => 'Entrada general (10:30)', 'stock' => 1],
        ['product_id' => 3, 'product_name' => 'C', 'slot' => 'Sin horario', 'stock' => 1],
    ];

    $result = $this->agg->aggregate($scheduled, [], []);

    expect(array_column($result, 'sort_time'))->toBe(['10:30', '17:00', null]);
});

test('un precio unitario faltante no rompe: recaudación cero', function () {
    $tickets = [
        ['ticket_id' => 1, 'product_id' => 1, 'product_name' => 'X', 'slot' => 'S (10:00)', 'order_id' => 42, 'order_status' => 'wc-completed'],
    ];

    $result = $this->agg->aggregate([], $tickets, []);

    expect($result[0]['tickets'])->toBe(1);
    expect($result[0]['revenue'])->toBe(0.0);
});

test('un ticket sin slot se agrupa bajo etiqueta explícita', function () {
    $tickets = [
        ['ticket_id' => 1, 'product_id' => 1, 'product_name' => 'X', 'slot' => null, 'order_id' => 1, 'order_status' => 'wc-completed'],
    ];

    $result = $this->agg->aggregate([], $tickets, []);

    expect($result[0]['slot'])->toBe('(sin horario)');
});
```

- [ ] **Step 2: Correr el test para verificar que falla**

Run: `php artisan test tests/Unit/TicketSales/DailySalesAggregatorTest.php`
Expected: FAIL con `Class "CarlVallory\KrayinTicketSales\Support\DailySalesAggregator" not found`.

- [ ] **Step 3: Implementar**

Crear `packages/CarlVallory/KrayinTicketSales/src/Support/DailySalesAggregator.php`:

```php
<?php

namespace CarlVallory\KrayinTicketSales\Support;

/**
 * Cruza la programación (JSON de FooEvents) con las ventas (tickets).
 *
 * Ambas direcciones importan: una función programada sin ventas debe
 * mostrarse en cero, y un ticket cuya función ya no figura en la
 * programación debe mostrarse igual — perder ventas es peor que mostrar
 * una función de más.
 */
class DailySalesAggregator
{
    private const COMPLETED  = 'wc-completed';
    private const NO_SLOT    = '(sin horario)';

    /**
     * @param  array<int, array{product_id:int, product_name:string, slot:string, stock:int}>  $scheduled
     * @param  iterable  $tickets
     * @param  array<string, float>  $unitPrices
     * @return array<int, array{product_id:int, product_name:string, slot:string, sort_time:?string, tickets:int, pending_tickets:int, stock:?int, revenue:float}>
     */
    public function aggregate(array $scheduled, iterable $tickets, array $unitPrices): array
    {
        $rows = [];

        foreach ($scheduled as $function) {
            $slot = $function['slot'] !== '' ? $function['slot'] : self::NO_SLOT;
            $key  = $this->key((int) $function['product_id'], $slot);

            $rows[$key] = $this->blankRow(
                (int) $function['product_id'],
                (string) $function['product_name'],
                $slot,
                (int) $function['stock'],
            );
        }

        foreach ($tickets as $ticket) {
            $slot = ($ticket['slot'] ?? null) ?: self::NO_SLOT;
            $key  = $this->key((int) $ticket['product_id'], $slot);

            if (! isset($rows[$key])) {
                $rows[$key] = $this->blankRow(
                    (int) $ticket['product_id'],
                    (string) ($ticket['product_name'] ?? 'Producto ' . $ticket['product_id']),
                    $slot,
                    null,
                );
            }

            $rows[$key]['tickets']++;

            if (($ticket['order_status'] ?? null) !== self::COMPLETED) {
                $rows[$key]['pending_tickets']++;
            }

            $priceKey = $ticket['order_id'] . ':' . $ticket['product_id'];
            $rows[$key]['revenue'] += $unitPrices[$priceKey] ?? 0.0;
        }

        $rows = array_values($rows);

        usort($rows, function ($a, $b) {
            return [$a['sort_time'] ?? '99:99', $a['product_name']]
               <=> [$b['sort_time'] ?? '99:99', $b['product_name']];
        });

        return $rows;
    }

    private function blankRow(int $productId, string $productName, string $slot, ?int $stock): array
    {
        return [
            'product_id'      => $productId,
            'product_name'    => $this->normalizeName($productName),
            'slot'            => $slot,
            'sort_time'       => $this->sortTime($slot),
            'tickets'         => 0,
            'pending_tickets' => 0,
            'stock'           => $stock,
            'revenue'         => 0.0,
        ];
    }

    private function key(int $productId, string $slot): string
    {
        return $productId . '|' . $slot;
    }

    /**
     * El label del slot puede traer su propio horario antes del horario real
     * ("BioEstanque (16:00) (17:00)"), así que se toma el ÚLTIMO.
     */
    private function sortTime(string $slot): ?string
    {
        if (! preg_match_all('/\((\d{1,2}):(\d{2})\)/', $slot, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $last = end($matches);

        return str_pad($last[1], 2, '0', STR_PAD_LEFT) . ':' . $last[2];
    }

    /**
     * Hay títulos con espacio duro (U+00A0) en datos reales.
     */
    private function normalizeName(string $name): string
    {
        return trim(str_replace("\u{a0}", ' ', $name));
    }
}
```

- [ ] **Step 4: Correr el test para verificar que pasa**

Run: `php artisan test tests/Unit/TicketSales/DailySalesAggregatorTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 5: Commit**

```bash
git add packages/CarlVallory/KrayinTicketSales/src/Support/DailySalesAggregator.php \
        tests/Unit/TicketSales/DailySalesAggregatorTest.php
git commit -m "feat(ticket-sales): agregador que cruza programación y ventas del día"
```

---

### Task 8: `SyncTicketSalesCommand` + scheduler cada 5 minutos

**Files:**
- Create: `packages/CarlVallory/KrayinTicketSales/src/Console/SyncTicketSalesCommand.php`
- Modify: `packages/CarlVallory/KrayinTicketSales/src/Providers/KrayinTicketSalesServiceProvider.php`
- Test: `tests/Feature/TicketSales/SyncTicketSalesCommandTest.php`

**Interfaces:**
- Consumes: `TicketSalesRepository`, `BookingsOptionsParser`, `DailySalesAggregator`, `BusinessDay`, `TicketSalesSnapshot`.
- Produces: comando `ticket-sales:sync {--date=}`; registro en el scheduler cada 5 minutos.

- [ ] **Step 1: Escribir el comando**

Crear `packages/CarlVallory/KrayinTicketSales/src/Console/SyncTicketSalesCommand.php`:

```php
<?php

namespace CarlVallory\KrayinTicketSales\Console;

use CarlVallory\KrayinTicketSales\Models\TicketSalesSnapshot;
use CarlVallory\KrayinTicketSales\Repositories\TicketSalesRepository;
use CarlVallory\KrayinTicketSales\Support\BookingsOptionsParser;
use CarlVallory\KrayinTicketSales\Support\BusinessDay;
use CarlVallory\KrayinTicketSales\Support\DailySalesAggregator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncTicketSalesCommand extends Command
{
    protected $signature = 'ticket-sales:sync {--date= : Fecha YYYY-MM-DD; por defecto hoy en la zona del museo}';

    protected $description = 'Sincroniza el snapshot de entradas vendidas para las funciones del día';

    public function handle(
        TicketSalesRepository $repository,
        BookingsOptionsParser $bookingsParser,
        DailySalesAggregator $aggregator,
        BusinessDay $businessDay,
    ): int {
        $date = $this->option('date') ?: $businessDay->todayString();

        $this->info("Sincronizando entradas para {$date}...");

        try {
            $scheduled = $this->scheduledFunctions($repository, $bookingsParser, $date);
            $tickets   = $repository->ticketsFor($date);

            $orderIds = collect($tickets)
                ->pluck('order_id')
                ->filter()
                ->unique()
                ->values()
                ->all();

            $rows = $aggregator->aggregate($scheduled, $tickets, $repository->unitPricesFor($orderIds));
        } catch (\Throwable $e) {
            Log::error('ticket-sales:sync falló', ['date' => $date, 'exception' => $e]);

            $this->error('Falló la sincronización: ' . $e->getMessage());
            $this->warn('El dashboard seguirá mostrando el último snapshot bueno.');

            return self::FAILURE;
        }

        $this->writeSnapshot($date, $rows);

        $this->info(sprintf(
            '%d funciones, %d entradas.',
            count($rows),
            array_sum(array_column($rows, 'tickets')),
        ));

        return self::SUCCESS;
    }

    /**
     * Programación del día: se parsean TODOS los productos y se filtra por fecha.
     */
    private function scheduledFunctions(
        TicketSalesRepository $repository,
        BookingsOptionsParser $parser,
        string $date,
    ): array {
        $scheduled = [];
        $unreadable = [];

        foreach ($repository->bookingProducts() as $product) {
            $functions = $parser->parse($product['bookings']);

            // Los parsers son puros y no loguean; el conteo se informa acá.
            // Una meta con contenido real que no produce ninguna función es
            // sospechosa: puede ser un tercer formato que todavía no conocemos.
            // Los productos con la meta vacía son normales (10 de 28) y no
            // deben generar ruido cada 5 minutos.
            if ($functions === [] && ! in_array(trim($product['bookings']), ['', '[]', '{}', 'null'], true)) {
                $unreadable[] = $product['product_id'];
            }

            foreach ($functions as $function) {
                if ($function['date'] !== $date) {
                    continue;
                }

                $scheduled[] = [
                    'product_id'   => $product['product_id'],
                    'product_name' => $product['product_name'],
                    'slot'         => $function['slot'],
                    'stock'        => $function['stock'],
                ];
            }
        }

        if ($unreadable !== []) {
            Log::warning('ticket-sales: productos con bookings ilegibles', [
                'product_ids' => $unreadable,
            ]);

            $this->warn(count($unreadable) . ' producto(s) con programación ilegible: ' . implode(', ', $unreadable));
        }

        return $scheduled;
    }

    /**
     * Reemplazo atómico: la vista nunca lee un estado a medio escribir.
     */
    private function writeSnapshot(string $date, array $rows): void
    {
        $syncedAt = now();

        DB::transaction(function () use ($date, $rows, $syncedAt) {
            TicketSalesSnapshot::where('function_date', $date)->delete();

            foreach ($rows as $row) {
                TicketSalesSnapshot::create($row + [
                    'function_date' => $date,
                    'synced_at'     => $syncedAt,
                ]);
            }
        });
    }
}
```

- [ ] **Step 2: Registrar comando y scheduler en el ServiceProvider**

En `boot()`, agregar:

```php
        if ($this->app->runningInConsole()) {
            $this->commands([
                \CarlVallory\KrayinTicketSales\Console\SyncTicketSalesCommand::class,
            ]);
        }

        // El scheduler se registra desde el paquete para no tocar app/Console/Kernel.php.
        $this->app->booted(function () {
            $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);

            $schedule->command('ticket-sales:sync')
                ->everyFiveMinutes()
                ->withoutOverlapping()
                ->runInBackground();
        });
```

- [ ] **Step 3: Escribir el test**

Crear `tests/Feature/TicketSales/SyncTicketSalesCommandTest.php`:

```php
<?php

use CarlVallory\KrayinTicketSales\Models\TicketSalesSnapshot;
use CarlVallory\KrayinTicketSales\Repositories\TicketSalesRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->fakeRepo = new class extends TicketSalesRepository
    {
        public function bookingProducts(): \Illuminate\Support\Collection
        {
            return collect([
                [
                    'product_id'   => 194099,
                    'product_name' => 'Mundos en órbita',
                    // Forma B a propósito: si el parser la ignorara, este producto desaparecería.
                    'bookings'     => json_encode([
                        's1' => [
                            'label'       => 'Entrada 2x1',
                            'hour'        => '19',
                            'minute'      => '00',
                            'd1_add_date' => 'agosto 7, 2026',
                            'd1_stock'    => '0',
                        ],
                    ]),
                ],
                [
                    'product_id'   => 192637,
                    'product_name' => 'Entrada Bioestanque',
                    'bookings'     => json_encode([
                        's2' => [
                            'label'          => 'BioEstanque (17:00)',
                            'formatted_time' => '(18:00)',
                            'add_date'       => ['d' => ['date' => 'agosto 7, 2026', 'stock' => '20']],
                        ],
                    ]),
                ],
            ]);
        }

        public function ticketsFor(string $date): \Illuminate\Support\Collection
        {
            return collect([
                ['ticket_id' => 1, 'product_id' => 194099, 'product_name' => 'Mundos en órbita', 'slot' => 'Entrada 2x1 (19:00)', 'order_id' => 500, 'order_status' => 'wc-completed'],
                ['ticket_id' => 2, 'product_id' => 194099, 'product_name' => 'Mundos en órbita', 'slot' => 'Entrada 2x1 (19:00)', 'order_id' => 500, 'order_status' => 'wc-completed'],
            ]);
        }

        public function unitPricesFor(array $orderIds): array
        {
            return ['500:194099' => 40000.0];
        }
    };

    $this->app->instance(TicketSalesRepository::class, $this->fakeRepo);
});

test('escribe el snapshot del día', function () {
    $this->artisan('ticket-sales:sync', ['--date' => '2026-08-07'])->assertSuccessful();

    $rows = TicketSalesSnapshot::where('function_date', '2026-08-07')->get();

    expect($rows)->toHaveCount(2);
    expect($rows->sum('tickets'))->toBe(2);
});

test('la función sin ventas queda en cero, no desaparece', function () {
    $this->artisan('ticket-sales:sync', ['--date' => '2026-08-07'])->assertSuccessful();

    $bio = TicketSalesSnapshot::where('product_id', 192637)->first();

    expect($bio)->not->toBeNull();
    expect($bio->tickets)->toBe(0);
    expect($bio->stock)->toBe(20);
});

test('el producto en forma B de JSON aparece', function () {
    $this->artisan('ticket-sales:sync', ['--date' => '2026-08-07'])->assertSuccessful();

    $row = TicketSalesSnapshot::where('product_id', 194099)->first();

    expect($row)->not->toBeNull();
    expect($row->slot)->toBe('Entrada 2x1 (19:00)');
    expect($row->tickets)->toBe(2);
    expect((float) $row->revenue)->toBe(80000.0);
});

test('correr dos veces reemplaza en vez de duplicar', function () {
    $this->artisan('ticket-sales:sync', ['--date' => '2026-08-07'])->assertSuccessful();
    $this->artisan('ticket-sales:sync', ['--date' => '2026-08-07'])->assertSuccessful();

    expect(TicketSalesSnapshot::where('function_date', '2026-08-07')->count())->toBe(2);
});

test('si el repositorio falla, el comando falla sin borrar el snapshot previo', function () {
    $this->artisan('ticket-sales:sync', ['--date' => '2026-08-07'])->assertSuccessful();

    $this->app->instance(TicketSalesRepository::class, new class extends TicketSalesRepository
    {
        public function bookingProducts(): \Illuminate\Support\Collection
        {
            throw new \RuntimeException('base caída');
        }
    });

    $this->artisan('ticket-sales:sync', ['--date' => '2026-08-07'])->assertFailed();

    expect(TicketSalesSnapshot::where('function_date', '2026-08-07')->count())->toBe(2);
});
```

- [ ] **Step 4: Correr los tests**

Run: `php artisan test tests/Feature/TicketSales/SyncTicketSalesCommandTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 5: Verificar contra la base real**

Run: `php artisan ticket-sales:sync --date=2026-08-07`

Expected: **11 funciones**. Si informa 7, el parser está ignorando la forma B — ver §5.1 y §13 del spec.

- [ ] **Step 6: Verificar que el scheduler quedó registrado**

Run: `php artisan schedule:list`
Expected: aparece `ticket-sales:sync` con cadencia `*/5 * * * *`.

- [ ] **Step 7: Commit**

```bash
git add packages/CarlVallory/KrayinTicketSales/src/Console/SyncTicketSalesCommand.php \
        packages/CarlVallory/KrayinTicketSales/src/Providers/KrayinTicketSalesServiceProvider.php \
        tests/Feature/TicketSales/SyncTicketSalesCommandTest.php
git commit -m "feat(ticket-sales): comando de sincronización cada 5 minutos"
```

---

### Task 9: Vista, ruta, menú y ACL

**Files:**
- Create: `packages/CarlVallory/KrayinTicketSales/src/Http/Controllers/TicketSalesController.php`
- Create: `packages/CarlVallory/KrayinTicketSales/src/Http/routes.php`
- Create: `packages/CarlVallory/KrayinTicketSales/src/Config/menu.php`
- Create: `packages/CarlVallory/KrayinTicketSales/src/Config/acl.php`
- Create: `packages/CarlVallory/KrayinTicketSales/src/Resources/views/index.blade.php`
- Modify: `packages/CarlVallory/KrayinTicketSales/src/Providers/KrayinTicketSalesServiceProvider.php`
- Test: `tests/Feature/TicketSales/TicketSalesDashboardTest.php`

**Interfaces:**
- Consumes: `TicketSalesSnapshot`, `BusinessDay`, config `ticket-sales.stale_after_minutes`.
- Produces: ruta con nombre `krayin.ticket-sales.index` en `admin/ticket-sales`.

- [ ] **Step 1: Crear el controlador**

```php
<?php

namespace CarlVallory\KrayinTicketSales\Http\Controllers;

use CarlVallory\KrayinTicketSales\Models\TicketSalesSnapshot;
use CarlVallory\KrayinTicketSales\Support\BusinessDay;
use Illuminate\Routing\Controller;

class TicketSalesController extends Controller
{
    public function index(BusinessDay $businessDay)
    {
        $date = $businessDay->todayString();

        $functions = TicketSalesSnapshot::where('function_date', $date)
            ->orderByRaw('sort_time IS NULL, sort_time')
            ->orderBy('product_name')
            ->get();

        $syncedAt = $functions->first()?->synced_at;

        return view('krayin-ticket-sales::index', [
            'date'           => $date,
            'functions'      => $functions,
            'totalTickets'   => (int) $functions->sum('tickets'),
            'totalPending'   => (int) $functions->sum('pending_tickets'),
            'totalRevenue'   => (float) $functions->sum('revenue'),
            'showCount'      => $functions->pluck('product_id')->unique()->count(),
            'syncedAt'       => $syncedAt,
            'isStale'        => $syncedAt === null
                || $syncedAt->diffInMinutes(now()) > config('ticket-sales.stale_after_minutes', 15),
        ]);
    }
}
```

- [ ] **Step 2: Crear rutas, menú y ACL**

`src/Http/routes.php`:

```php
<?php

use CarlVallory\KrayinTicketSales\Http\Controllers\TicketSalesController;
use Illuminate\Support\Facades\Route;

Route::group(['middleware' => ['web', 'admin_locale', 'user'], 'prefix' => 'admin/ticket-sales'], function () {
    Route::get('', [TicketSalesController::class, 'index'])->name('krayin.ticket-sales.index');
});
```

`src/Config/menu.php`:

```php
<?php

return [
    [
        'key'        => 'ticket_sales',
        'name'       => 'Entradas del día',
        'route'      => 'krayin.ticket-sales.index',
        'sort'       => 3,
        'icon-class' => 'icon-dashboard',
    ],
];
```

`src/Config/acl.php`:

```php
<?php

return [
    [
        'key'   => 'ticket_sales',
        'name'  => 'Entradas del día',
        'route' => 'krayin.ticket-sales.index',
        'sort'  => 3,
    ],
];
```

- [ ] **Step 3: Registrar rutas, menú y ACL en el ServiceProvider**

En `boot()`, junto a `loadViewsFrom`:

```php
        $this->loadRoutesFrom(__DIR__ . '/../Http/routes.php');
```

En `register()`:

```php
        $this->mergeConfigFrom(__DIR__ . '/../Config/menu.php', 'menu.admin');
        $this->mergeConfigFrom(__DIR__ . '/../Config/acl.php', 'acl');
```

- [ ] **Step 4: Crear la vista**

Crear `src/Resources/views/index.blade.php`:

```blade
<x-admin::layouts>
    <x-slot:title>
        Entradas del día
    </x-slot>

    {{-- El snapshot se refresca cada 5 minutos; la página se recarga a la par. --}}
    <meta http-equiv="refresh" content="300">

    <style>
        .muci-ticket-card { border-left: 4px solid #6950A1; }
        .muci-ticket-card--sales { border-left-color: #00B26B; }
        .muci-ticket-card--revenue { border-left-color: #F37043; }
        .muci-stale { background: #F17DB1; color: #000; }
    </style>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap mb-5">
        <div class="grid gap-1.5">
            <p class="text-2xl font-semibold dark:text-white" style="font-family: Poppins, sans-serif;">
                Entradas del día
            </p>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Funciones del {{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}
            </p>
        </div>
        <div class="text-sm {{ $isStale ? 'muci-stale px-3 py-1.5 rounded-md font-semibold' : 'text-gray-500 dark:text-gray-400' }}">
            @if ($syncedAt)
                Actualizado {{ $syncedAt->diffForHumans() }}
            @else
                Sin datos todavía — correr <code>php artisan ticket-sales:sync</code>
            @endif
        </div>
    </div>

    <div class="mt-3.5 flex gap-4 max-sm:flex-wrap">
        <div class="muci-ticket-card flex flex-1 flex-col gap-2 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="text-base font-semibold text-gray-600 dark:text-gray-300">Funciones</p>
            <p class="text-3xl font-bold text-gray-800 dark:text-white">{{ $functions->count() }}</p>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $showCount }} shows</p>
        </div>

        <div class="muci-ticket-card muci-ticket-card--sales flex flex-1 flex-col gap-2 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="text-base font-semibold text-gray-600 dark:text-gray-300">Entradas vendidas</p>
            <p class="text-3xl font-bold text-gray-800 dark:text-white">{{ $totalTickets }}</p>
            @if ($totalPending > 0)
                <p class="text-sm" style="color: #F37043;">{{ $totalPending }} en órdenes no completadas</p>
            @endif
        </div>

        <div class="muci-ticket-card muci-ticket-card--revenue flex flex-1 flex-col gap-2 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="text-base font-semibold text-gray-600 dark:text-gray-300">Recaudación</p>
            <p class="text-3xl font-bold text-gray-800 dark:text-white">{{ core()->formatBasePrice($totalRevenue) }}</p>
        </div>
    </div>

    <div class="mt-4 rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900 overflow-x-auto">
        <table class="w-full text-left">
            <thead class="border-b border-gray-200 dark:border-gray-800">
                <tr class="text-sm font-semibold text-gray-600 dark:text-gray-300">
                    <th class="p-4">Show</th>
                    <th class="p-4">Horario</th>
                    <th class="p-4 text-right">Entradas</th>
                    <th class="p-4 text-right">Cupos habilitados</th>
                    <th class="p-4 text-right">Recaudación</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($functions as $function)
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <td class="p-4 dark:text-white">{{ $function->product_name }}</td>
                        <td class="p-4 text-gray-600 dark:text-gray-400">{{ $function->slot }}</td>
                        <td class="p-4 text-right font-semibold dark:text-white">
                            {{ $function->tickets }}
                            @if ($function->pending_tickets > 0)
                                <span class="text-xs font-normal" style="color: #F37043;">({{ $function->pending_tickets }} pend.)</span>
                            @endif
                        </td>
                        <td class="p-4 text-right text-gray-600 dark:text-gray-400">
                            {{ $function->stock === null ? '—' : $function->stock }}
                        </td>
                        <td class="p-4 text-right dark:text-white">{{ core()->formatBasePrice($function->revenue) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="p-8 text-center text-gray-500 dark:text-gray-400">
                            No hay funciones programadas para hoy.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
        «Cupos habilitados» es el remanente de venta online que informa FooEvents. Un 0 significa venta online cerrada, no sala llena — por eso no se muestra un porcentaje de ocupación.
    </p>
</x-admin::layouts>
```

- [ ] **Step 5: Escribir el test**

Crear `tests/Feature/TicketSales/TicketSalesDashboardTest.php`:

```php
<?php

use CarlVallory\KrayinTicketSales\Models\TicketSalesSnapshot;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

test('un visitante sin sesión es redirigido al login', function () {
    $this->get(route('krayin.ticket-sales.index'))->assertRedirect();
});

test('un usuario autenticado ve las funciones del día', function () {
    $user = User::first();

    if (! $user) {
        $this->markTestSkipped('No hay usuarios en la base local.');
    }

    $today = app(\CarlVallory\KrayinTicketSales\Support\BusinessDay::class)->todayString();

    TicketSalesSnapshot::create([
        'function_date'   => $today,
        'product_id'      => 194099,
        'product_name'    => 'Mundos en órbita',
        'slot'            => 'Entrada 2x1 (19:00)',
        'sort_time'       => '19:00',
        'tickets'         => 7,
        'pending_tickets' => 0,
        'stock'           => 0,
        'revenue'         => 280000,
        'synced_at'       => now(),
    ]);

    $this->actingAs($user, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertOk()
        ->assertSee('Mundos en órbita')
        ->assertSee('Entrada 2x1 (19:00)');
});

test('sin funciones muestra el estado vacío', function () {
    $user = User::first();

    if (! $user) {
        $this->markTestSkipped('No hay usuarios en la base local.');
    }

    $this->actingAs($user, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertOk()
        ->assertSee('No hay funciones programadas para hoy');
});
```

- [ ] **Step 6: Correr los tests**

Run: `php artisan test tests/Feature/TicketSales/TicketSalesDashboardTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 7: Correr toda la suite del paquete**

Run: `php artisan test --filter=TicketSales`
Expected: todo PASS.

- [ ] **Step 8: Commit**

```bash
git add packages/CarlVallory/KrayinTicketSales/src/Http/ \
        packages/CarlVallory/KrayinTicketSales/src/Config/menu.php \
        packages/CarlVallory/KrayinTicketSales/src/Config/acl.php \
        packages/CarlVallory/KrayinTicketSales/src/Resources/views/index.blade.php \
        packages/CarlVallory/KrayinTicketSales/src/Providers/KrayinTicketSalesServiceProvider.php \
        tests/Feature/TicketSales/TicketSalesDashboardTest.php
git commit -m "feat(ticket-sales): vista del dashboard, ruta, menú y ACL"
```

---

### Task 10: Documentación de despliegue

**Files:**
- Create: `packages/CarlVallory/KrayinTicketSales/README.md`
- Create: `packages/CarlVallory/KrayinTicketSales/docs/DEPLOY.md`

- [ ] **Step 1: Escribir el README del paquete**

Crear `packages/CarlVallory/KrayinTicketSales/README.md` con este contenido:

````markdown
# KrayinTicketSales

Dashboard de entradas vendidas para las funciones del día, leyendo WooCommerce + FooEvents.

Ruta: `/admin/ticket-sales` · Comando: `ticket-sales:sync` (cada 5 minutos vía scheduler)

## Cómo funciona

Un comando programado cruza dos fuentes de la base `muci` y escribe un snapshot en
`muci_ticket_sales_snapshot` (base `krayin`). La vista lee sólo ese snapshot, así que
la página no toca nunca las tablas de 4,6M de filas de WordPress.

- **Programación** — meta `fooevents_bookings_options_serialized` de cada producto.
- **Ventas** — posts `event_magic_tickets`, enlazados por `WooCommerceEventsProductID`.

## Tres trampas de esta base

**1. Conviven dos formatos de JSON de FooEvents.** Uno anidado (`add_date`) y uno plano
(claves `..._add_date` / `..._stock`). Soportar sólo el anidado pierde 4 de los 7 shows
del día. `BookingsOptionsParser` maneja los dos.

**2. El servidor corre en UTC y el museo en `America/Asuncion` (−3).** `CURDATE()` de
MySQL está prohibido en este paquete: desde las 21:00 locales devuelve la fecha de
mañana. "Hoy" lo calcula `BusinessDay` en PHP.

**3. `stock` es remanente, no aforo, y `0` no significa agotado.** Significa venta online
cerrada. Por eso el dashboard muestra "cupos habilitados" y **no** un porcentaje de
ocupación: `vendidas / (vendidas + stock)` daría 100% en una sala casi vacía.

## Solo lectura

La conexión `woocommerce` usa un usuario con grant `SELECT` puro. La imposibilidad de
escribir la garantiza MySQL, no este código. No darle permisos de escritura.

## Datos personales

El snapshot es agregado: no almacena ni muestra nombres, cédulas, emails ni teléfonos
de compradores.
````

- [ ] **Step 2: Escribir `docs/DEPLOY.md`**

Siguiendo la convención de `crm/DEPLOY-PRODUCCION.md`, con estos pasos exactos:

```bash
# En el servidor, como el usuario del deploy, en /var/www/crm
composer config repositories.krayin-ticket-sales vcs https://github.com/CarlVallory/krayin-ticket-sales
/usr/bin/php8.2 /usr/local/bin/composer require carlvallory/krayin-ticket-sales:dev-main --update-no-dev

# Credenciales de la conexión de lectura en .env
#   WC_DB_HOST=127.0.0.1
#   WC_DB_PORT=3306
#   WC_DB_DATABASE=muci
#   WC_DB_USERNAME=anthropic_readonly
#   WC_DB_PASSWORD=<la contraseña>
#   WC_DB_PREFIX=wpzv_

/usr/bin/php8.2 artisan migrate --force
/usr/bin/php8.2 artisan config:cache

# Primera corrida manual
/usr/bin/php8.2 artisan ticket-sales:sync

# Verificar que el scheduler lo tomó
/usr/bin/php8.2 artisan schedule:list | grep ticket-sales
```

Incluir esta verificación de aceptación:

> Contrastar el resultado contra la base en el momento. La primera corrida del 2026-08-07 debía dar **11 funciones sobre 7 shows**. Si da 7 sobre 4, el parser está ignorando la forma B del JSON.

Y esta advertencia:

> El usuario `anthropic_readonly` debe conservar su grant `SELECT` puro. Si alguna vez se le dan permisos de escritura, se pierde la garantía de que el dashboard no puede dañar la base de WooCommerce.

- [ ] **Step 3: Commit**

```bash
git add packages/CarlVallory/KrayinTicketSales/README.md \
        packages/CarlVallory/KrayinTicketSales/docs/DEPLOY.md
git commit -m "docs(ticket-sales): README y runbook de despliegue"
```

---

## Verificación final

- [ ] `php artisan test --filter=TicketSales` — todo verde.
- [ ] `php artisan ticket-sales:sync --date=2026-08-07` informa **11 funciones**, no 7.
- [ ] `php artisan schedule:list` muestra `ticket-sales:sync` cada 5 minutos.
- [ ] El dashboard muestra las funciones sin ventas en cero, no las esconde.
- [ ] `php artisan migrate:rollback` deja la base como estaba (tabla del snapshot eliminada).
- [ ] Ningún archivo de `packages/Webkul/*`, `vendor/*` ni `app/Console/Kernel.php` fue modificado:
      `git diff --name-only main... | grep -E '^(packages/Webkul|vendor|app/Console)'` → sin resultados.
