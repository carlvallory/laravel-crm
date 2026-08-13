# Servicio intermedio FooEvents — Plan de implementación

> **Para agentes:** SUB-SKILL REQUERIDA: usar `superpowers:subagent-driven-development` (recomendado) o `superpowers:executing-plans` para implementar tarea por tarea. Los pasos usan `- [ ]` para seguimiento.

**Goal:** Un servicio HTTP que expone `GET /v1/funciones?fecha=YYYY-MM-DD` con una fila por función del día —entradas vendidas, cupos y recaudación— y que es el único proceso con la credencial de la base `muci`.

**Architecture:** Laravel liviano, sin base propia, stateless. Una conexión de solo lectura a `muci`. Un repositorio devuelve filas crudas, tres clases puras hacen todo el parseo y la agregación, un controlador serializa. Escucha en `127.0.0.1:8081` detrás de nginx.

**Tech Stack:** PHP 8.4, Laravel 12, Pest, MySQL (solo lectura), nginx + php-fpm.

**Spec:** `docs/superpowers/specs/2026-08-12-servicio-fooevents-design.md`

## Global Constraints

- **PHP 8.4 explícito.** En el servidor, todo `composer`/`artisan` se corre con `/usr/bin/php8.4`. El `php` del CLI es un 8.5 incompleto sin `pdo_mysql`.
- **La base `muci` es de solo lectura.** Usuario `anthropic_readonly`, grant `SELECT` puro. Ningún `Model::save()`, ninguna migración sobre esa conexión.
- **`CURDATE()` y `NOW()` están prohibidos** en toda consulta. La fecha siempre entra como parámetro.
- **Prefijo de tablas `wpzv_`.** HPOS activo: los pedidos están en `wpzv_wc_orders`, no en `wpzv_posts`.
- **Nada de PII en la respuesta.** Ni nombres, ni correos, ni teléfonos.
- **Montos en enteros de guaraníes.** Sin decimales en la respuesta.
- **Los parsers son puros y mudos:** no loguean, no lanzan, no conocen Laravel.
- **No existe la base `muci` en local.** Los tests que la tocan se saltean solos; eso no es un fallo.

---

## Desviaciones encontradas al ejecutar (Task 1, 2026-08-12)

Anotadas acá para que las tareas 2 a 7 no repitan el tropiezo:

- **Laravel 12, no 11.** `create-project` sin versión trae **Laravel 13, que exige PHP ^8.3** y no arrancaría en el php-fpm 8.2 que producción tenía entonces. Se pide `laravel/laravel:^12.0`.
- **En local se usa `php` (8.3), no `/usr/bin/php8.4`,** que no existe en la máquina de desarrollo. El `/usr/bin/php8.4` de los comandos de abajo aplica **solo en el servidor**.
- **`composer config platform.php 8.2.99`.** Sin eso el lock resuelve paquetes que en prod no cargan; ya atajó a `laravel/pint` v1.30.5. **Superado:** el pin está en `8.4.99` desde el 2026-08-13, ver la sección de abajo.
- **`install:api` arrastra Sanctum,** que el spec descartó. Hay que quitar el paquete, `config/sanctum.php` y la migración de `personal_access_tokens`.
- **Pest 3 se instala a mano:** `php artisan pest:install` no existe; hay que crear `tests/Pest.php`.

## Desviaciones encontradas al ejecutar (Task 2, 2026-08-13)

- **`SpanishDateParserTest.php` también glob-ea los fixtures.** El Step 1 solo
  manda arreglar la ruta en `BookingsOptionsParserTest.php`, pero el test
  `parsea todas las cadenas de fecha reales de la base` tiene el mismo
  `__DIR__ . '/../../Fixtures/'`. Hay que arreglar los dos archivos.
- **Los fixtures son 19 archivos, no 18:** los 18 `bookings_*.json` más
  `products.json`. El `cp *.json` del Step 1 los trae todos, así que no cambia
  nada, pero el conteo del plan no cuadra con `ls`.
- **El Step 5 no falla como dice.** Esperaba `FAIL` con "Undefined array key
  hora"; en PHP 8.3 la clave ausente es un *warning* y evalúa a `null`, así que
  fallan 2 de los 3 tests nuevos y `un slot sin horario tiene hora null` pasa en
  vacío. Después de implementar pasa por el motivo correcto y el warning se va.
- **Un test más que los del plan:** `una fecha ausente no se avisa como
  ilegible`. Una mutación que borra solo el guard `trim(...) !== ''` del Step 11
  sobrevivía a toda la suite. Importa porque la Task 4 convierte `descartadas`
  en el aviso `fecha_no_parseable`: sin el guard, un slot sin clave `date`
  avisaría sobre una cadena vacía, que no le da a nadie nada que corregir.

## Cambio de plataforma: producción pasa a PHP 8.4 (2026-08-13)

Decisión de Carlos. **Supersede el objetivo 8.2** de las dos secciones de arriba;
lo que dicen sobre por qué se eligió Laravel 12 y por qué existe el pin sigue
siendo válido como historia, pero el número cambió. Todos los
`/usr/bin/php8.2` de este plan ya pasaron a `/usr/bin/php8.4`, y el socket del
pool también.

Estado real del parque al 2026-08-13:

| Dónde | Versión | Nota |
|---|---|---|
| WordPress/WooCommerce (prod) | **8.4** | Ya está ahí. Implica que `php8.4-fpm` ya existe en esa máquina. |
| CRM Krayin (prod) | 8.2 | Carlos lo quiere subir a 8.4; todavía no pasó. |
| CLI del servidor | 8.5 | **Incompleto, sin `pdo_mysql`.** Nunca usar `php` a secas allá. |
| Máquina de desarrollo | 8.3 (`php`) y **8.4 con paridad completa** | Desarrollar con `/usr/bin/php8.4`. |

**El servicio no depende del upgrade de Krayin.** Tiene vhost y pool propios, así
que puede correr en `php8.4-fpm` mientras Krayin sigue en 8.2. Verificar en la
Task 7 que el pool 8.4 esté realmente instalado, no darlo por hecho.

**El 8.4 local se igualó al 8.3 y el pin ya se movió** (commit `60bb370` del
servicio). El `php8.4` venía pelado —le faltaban 20 extensiones, entre ellas
`mbstring`, que `SpanishDateParser` usa en `mb_strtolower()`, y
`sqlite3`/`pdo_sqlite`, sobre los que el `phpunit.xml` corre la suite—. Se
instaló el espejo exacto de los `php8.3-*`:

    sudo apt install php8.4-bcmath php8.4-bz2 php8.4-curl php8.4-fpm \
                     php8.4-gd php8.4-imagick php8.4-intl php8.4-mbstring \
                     php8.4-mysql php8.4-soap php8.4-sqlite3 php8.4-xml php8.4-zip

Hoy la paridad de extensiones entre 8.3 y 8.4 es total, en los dos sentidos.
`config.platform.php` quedó en **`8.4.99`** y `require.php` en **`^8.4`**.

**Desarrollar con `/usr/bin/php8.4`, no con `php`.** El `php` de la máquina de
desarrollo sigue siendo 8.3, así que dev y prod solo coinciden si se invoca el
8.4 explícito. Los `Run:` de este plan ya dicen `/usr/bin/php8.4` y ahora aplican
igual en local y en el servidor.

Qué se movió en el lock al reapuntar: `laravel/pint` 1.30.4 → 1.30.5 (el paquete
que el pin viejo atajaba) y varios componentes de Symfony de 7.4 a 8.1.
`laravel/framework` se quedó en 12.66.0 y `symfony/console` en 7.4.16.
`laravel/tinker` **no** subió a 3.x: ese mayor pide Laravel 13, no era el pin.
La suite quedó 26/26 en verde bajo 8.4, sin deprecations.

**Pint reporta `fail`,** y se dejó así a propósito: quiere reformatear 8 archivos,
incluidos los dos parsers migrados, porque su preset por defecto no coincide con
el estilo que traen del CRM. Nunca se corrió pint en este repo y no hay
`pint.json`. Si se adopta, es su propia tarea y arranca por escribir ese config.

**Task 5 desbloqueada por partida doble:** necesita `pdo_mysql`, que ahora está
tanto en el 8.3 como en el 8.4 local.

Laravel 13 queda habilitado (exige ^8.3), pero **no se migra**: Laravel 12 es
soportado y corre en 8.4, el servicio tiene 4 commits, y un salto de mayor del
framework agrega riesgo sin darle nada a este servicio. Si algún día se hace, es
su propia tarea.

## Desviaciones encontradas al ejecutar (Task 3, 2026-08-13)

- **`repartir()` va en enteros, no en punto flotante.** El plan calculaba
  `$total * $n / $totalEntradas` y sacaba piso y resto de un float. Para los
  montos de esta base da lo mismo, pero la promesa de la clase es que no se
  pierde un guaraní, y con `intdiv()` y `%` eso no necesita un argumento sobre
  precisión de 53 bits para creerse. El contrato público no cambia.
- **Dos tests más que los del plan, los dos por mutaciones que sobrevivían.** Los
  6 tests del Step 1 no distinguían el algoritmo decidido de dos impostores:
  - Poner **todos los restos en 0** dejaba la suite verde. El test `el resto va a
    la función con mayor resto` pasaba por casualidad, porque ahí la de mayor
    resto es además la primera de la lista y el desempate por orden de inserción
    da el mismo número. Lo cubre `el guaraní suelto va al mayor resto aunque no
    sea el primero`.
  - Usar **la cantidad de entradas en lugar del resto** también dejaba la suite
    verde, y son criterios distintos: `(total * n) % totalEntradas` no crece con
    `n`. Con 100 entre 7 entradas, la función con 3 entradas deja resto 6 y la de
    4 deja resto 1, así que gana la que menos vendió. Lo cubre `gana el mayor
    resto, no la que más entradas vendió`.
- **Se agregó `un empate de restos lo gana la función que vino primero`,** que
  fija el desempate. El sort de PHP es estable desde la 8.0; sin un test eso es
  un detalle de implementación del que dependería la reproducibilidad.
- **`$entradas === []` es redundante** y se dejó igual. `array_sum([])` es `0`,
  así que `$totalEntradas <= 0` ya cubre el arreglo vacío; quitarla no rompe
  ningún test. Queda porque documenta el caso explícito.

## Estructura de archivos

| Archivo | Responsabilidad |
|---|---|
| `config/fooevents.php` | Token, conexión a `muci`, zona horaria |
| `app/Support/SpanishDateParser.php` | `"agosto 7, 2026"` → `CarbonImmutable`. Migrado del CRM |
| `app/Support/BookingsOptionsParser.php` | JSON de bookings (formas A y B) → funciones. Migrado del CRM, más el campo `hora` |
| `app/Support/Prorrateo.php` | Reparte un total entero entre funciones por resto mayor |
| `app/Repositories/FooEventsRepository.php` | Las tres consultas a `muci`. Devuelve filas crudas, no agrega |
| `app/Services/FuncionesDelDia.php` | Cruce programación × ventas × plata → funciones y avisos |
| `app/Http/Middleware/TokenEstatico.php` | `hash_equals` contra el token de config |
| `app/Http/Controllers/FuncionesController.php` | Valida `fecha`, orquesta, serializa |
| `routes/api.php` | La única ruta |

Las tres clases de `app/Support` y `FuncionesDelDia` concentran todo el riesgo y se testean sin base de datos.

---

### Task 1: Scaffold, autenticación y validación de la fecha

Al terminar esta tarea el endpoint responde `401`, `422` y `200` correctamente, con la lista de funciones todavía vacía.

**Files:**
- Create: repo nuevo `servicio-fooevents` (esqueleto `laravel/laravel` 11)
- Create: `config/fooevents.php`
- Create: `app/Http/Middleware/TokenEstatico.php`
- Create: `app/Http/Controllers/FuncionesController.php`
- Modify: `routes/api.php`, `bootstrap/app.php`, `.env.example`
- Test: `tests/Feature/FuncionesEndpointTest.php`

**Interfaces:**
- Consumes: nada
- Produces: ruta `GET /v1/funciones`, alias de middleware `token`, claves de config `fooevents.token`, `fooevents.timezone`

- [ ] **Step 1: Crear el esqueleto**

```bash
/usr/bin/php8.4 /usr/local/bin/composer create-project laravel/laravel servicio-fooevents
cd servicio-fooevents && git init && git add -A && git commit -m "chore: esqueleto laravel"
```

- [ ] **Step 2: Escribir la config**

`config/fooevents.php`:

```php
<?php

return [
    'token'    => env('FOOEVENTS_TOKEN'),
    'timezone' => env('FOOEVENTS_TIMEZONE', 'America/Asuncion'),
];
```

En `.env.example`, y en `config/database.php` dentro de `connections`:

```php
'muci' => [
    'driver'   => 'mysql',
    'host'     => env('MUCI_DB_HOST', '127.0.0.1'),
    'port'     => env('MUCI_DB_PORT', '3306'),
    'database' => env('MUCI_DB_DATABASE', 'muci'),
    'username' => env('MUCI_DB_USERNAME'),
    'password' => env('MUCI_DB_PASSWORD'),
    'prefix'   => env('MUCI_DB_PREFIX', 'wpzv_'),
    'charset'  => 'utf8mb4',
    'strict'   => false,
],
```

- [ ] **Step 3: Escribir los tests que fallan**

`tests/Feature/FuncionesEndpointTest.php`:

```php
<?php

beforeEach(function () {
    config()->set('fooevents.token', 'token-de-prueba');
});

function pedir(string $query = '?fecha=2026-08-07', ?string $token = 'token-de-prueba')
{
    $headers = $token === null ? [] : ['Authorization' => "Bearer {$token}"];

    return test()->getJson("/v1/funciones{$query}", $headers);
}

test('401 sin token', function () {
    pedir(token: null)->assertStatus(401)->assertJsonPath('error', 'no_autorizado');
});

test('401 con token equivocado', function () {
    pedir(token: 'otro')->assertStatus(401)->assertJsonPath('error', 'no_autorizado');
});

test('422 sin fecha', function () {
    pedir('')->assertStatus(422)->assertJsonPath('error', 'fecha_invalida');
});

test('422 con fecha mal formada', function () {
    foreach (['?fecha=7-8-2026', '?fecha=2026-13-01', '?fecha=hoy', '?fecha='] as $q) {
        pedir($q)->assertStatus(422)->assertJsonPath('error', 'fecha_invalida');
    }
});

test('200 devuelve la forma completa', function () {
    pedir()
        ->assertOk()
        ->assertJsonPath('fecha', '2026-08-07')
        ->assertJsonStructure(['fecha', 'generado_en', 'avisos', 'funciones']);
});
```

- [ ] **Step 4: Correr y verificar que fallan**

Run: `/usr/bin/php8.4 artisan test tests/Feature/FuncionesEndpointTest.php`
Expected: FAIL — la ruta no existe (404 en todos).

- [ ] **Step 5: Implementar el middleware**

`app/Http/Middleware/TokenEstatico.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class TokenEstatico
{
    public function handle(Request $request, Closure $next)
    {
        $esperado = (string) config('fooevents.token');
        $recibido = (string) $request->bearerToken();

        if ($esperado === '' || ! hash_equals($esperado, $recibido)) {
            return response()->json([
                'error'   => 'no_autorizado',
                'mensaje' => 'Token ausente o incorrecto.',
            ], 401);
        }

        return $next($request);
    }
}
```

Registrar el alias `token` en `bootstrap/app.php`, dentro de `withMiddleware`:

```php
$middleware->alias(['token' => \App\Http\Middleware\TokenEstatico::class]);
```

- [ ] **Step 6: Implementar el controlador y la ruta**

`app/Http/Controllers/FuncionesController.php`:

```php
<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class FuncionesController extends Controller
{
    public function __invoke(Request $request)
    {
        $fecha = (string) $request->query('fecha', '');

        if (! $this->fechaValida($fecha)) {
            return response()->json([
                'error'   => 'fecha_invalida',
                'mensaje' => 'El parámetro fecha es obligatorio, con formato YYYY-MM-DD.',
            ], 422);
        }

        return response()->json([
            'fecha'       => $fecha,
            'generado_en' => CarbonImmutable::now(config('fooevents.timezone'))->toIso8601String(),
            'avisos'      => [],
            'funciones'   => [],
        ]);
    }

    private function fechaValida(string $fecha): bool
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fecha, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
```

`routes/api.php`:

```php
use App\Http\Controllers\FuncionesController;
use Illuminate\Support\Facades\Route;

Route::middleware('token')->get('/v1/funciones', FuncionesController::class);
```

> Nota: en Laravel 11 hay que habilitar las rutas de API con
> `/usr/bin/php8.4 artisan install:api`, o registrar `routes/api.php` a mano en
> `bootstrap/app.php`. Sin eso la ruta no existe y los tests siguen en 404.
> El prefijo `api` por defecto debe quitarse: la ruta es `/v1/funciones`, no
> `/api/v1/funciones`.

- [ ] **Step 7: Correr y verificar que pasan**

Run: `/usr/bin/php8.4 artisan test tests/Feature/FuncionesEndpointTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat: endpoint /v1/funciones con token estático y validación de fecha"
```

---

### Task 2: Migrar los dos parsers, con sus fixtures y la prueba de aceptación

**Files:**
- Create: `app/Support/SpanishDateParser.php`, `app/Support/BookingsOptionsParser.php`
- Create: `tests/Fixtures/fooevents/*.json` (18 archivos, copiados de `laravel-crm/tests/Fixtures/fooevents/`)
- Test: `tests/Unit/SpanishDateParserTest.php`, `tests/Unit/BookingsOptionsParserTest.php`

**Interfaces:**
- Consumes: nada
- Produces:
  - `SpanishDateParser::parse(?string $raw): ?CarbonImmutable`
  - `BookingsOptionsParser::__construct(SpanishDateParser $dates)`
  - `BookingsOptionsParser::parse(?string $json): array` → `array<int, array{slot: string, hora: ?string, date: string, stock: int}>`

- [ ] **Step 1: Copiar archivos y fixtures**

```bash
CRM=~/code/crm/ticket-sales
cp $CRM/packages/CarlVallory/KrayinTicketSales/src/Support/SpanishDateParser.php app/Support/
cp $CRM/packages/CarlVallory/KrayinTicketSales/src/Support/BookingsOptionsParser.php app/Support/
mkdir -p tests/Fixtures/fooevents && cp $CRM/tests/Fixtures/fooevents/*.json tests/Fixtures/fooevents/
cp $CRM/tests/Unit/TicketSales/SpanishDateParserTest.php tests/Unit/
cp $CRM/tests/Unit/TicketSales/BookingsOptionsParserTest.php tests/Unit/
```

En los cuatro archivos PHP, cambiar `namespace CarlVallory\KrayinTicketSales\Support;` por `namespace App\Support;` y los `use` correspondientes por `App\Support\...`.

En `tests/Unit/BookingsOptionsParserTest.php`, la ruta de los fixtures pasa de `__DIR__ . '/../../Fixtures/fooevents/'` a `__DIR__ . '/../Fixtures/fooevents/'`.

- [ ] **Step 2: Correr los tests migrados sin tocar la lógica**

Run: `/usr/bin/php8.4 artisan test tests/Unit`
Expected: PASS. Si la prueba de aceptación `11 funciones sobre 7 shows` no queda en verde, **parar**: la mudanza perdió algo y no hay que seguir.

- [ ] **Step 3: Commit de la mudanza limpia**

```bash
git add -A
git commit -m "feat: migrar SpanishDateParser y BookingsOptionsParser desde el CRM"
```

- [ ] **Step 4: Escribir el test que falla para el campo `hora`**

El contrato necesita la hora por separado, para ordenar. Sale de `formatted_time`, nunca del label — el label puede traer un horario propio y distinto.

En `tests/Unit/BookingsOptionsParserTest.php`:

```php
test('extrae la hora de formatted_time, no del label', function () {
    $json = json_encode([
        'k' => [
            'label'          => 'BioEstanque (16:00)',
            'formatted_time' => '(17:00)',
            'add_date'       => ['d' => ['date' => 'agosto 7, 2026', 'stock' => '18']],
        ],
    ]);

    $f = $this->parser->parse($json)[0];

    expect($f['slot'])->toBe('BioEstanque (16:00) (17:00)');
    expect($f['hora'])->toBe('17:00');
});

test('la forma B compone la hora de hour y minute', function () {
    $json = json_encode([
        'k' => [
            'label'  => 'Entrada general',
            'hour'   => '19',
            'minute' => '00',
            'a_add_date' => 'agosto 1, 2026',
            'a_stock'    => '3',
        ],
    ]);

    expect($this->parser->parse($json)[0]['hora'])->toBe('19:00');
});

test('un slot sin horario tiene hora null', function () {
    $json = json_encode([
        'k' => [
            'label'    => 'Entrada única',
            'add_date' => ['a' => ['date' => 'agosto 7, 2026', 'stock' => '5']],
        ],
    ]);

    expect($this->parser->parse($json)[0]['hora'])->toBeNull();
});
```

- [ ] **Step 5: Correr y verificar que fallan**

Run: `/usr/bin/php8.4 artisan test tests/Unit/BookingsOptionsParserTest.php`
Expected: FAIL con "Undefined array key \"hora\"".

- [ ] **Step 6: Implementar `hora`**

En `BookingsOptionsParser`, agregar el método y usarlo en `parse()`:

```php
/**
 * La hora del slot sale de formatted_time, o se compone de hour/minute en la
 * forma B. Nunca del label: el label puede traer un horario propio distinto
 * ("BioEstanque (16:00)" en un slot de las 17:00).
 */
private function slotHora(array $slotOptions): ?string
{
    $time = trim((string) ($slotOptions['formatted_time'] ?? ''));

    if (preg_match('/(\d{1,2}):(\d{2})/', $time, $m)) {
        return str_pad($m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2];
    }

    if (isset($slotOptions['hour'])) {
        $hour   = str_pad((string) $slotOptions['hour'], 2, '0', STR_PAD_LEFT);
        $minute = str_pad((string) ($slotOptions['minute'] ?? '0'), 2, '0', STR_PAD_LEFT);

        return "{$hour}:{$minute}";
    }

    return null;
}
```

Dentro del `foreach` de `parse()`, antes del bucle de fechas: `$hora = $this->slotHora($slotOptions);`. Y en el array que se acumula, agregar `'hora' => $hora,` **entre** `'slot'` y `'date'`.

- [ ] **Step 7: Arreglar los tests que comparan arrays completos**

Los tests migrados usan `->toBe([...])` con las claves exactas, así que agregar `hora` los rompe. Hay que agregar `'hora'` a los arrays esperados de `forma A: add_date anidado`, `forma B: claves planas con sufijo _add_date` y `descarta fechas ilegibles pero conserva el resto del slot`. Ejemplo del primero:

```php
expect($this->parser->parse($json))->toBe([
    ['slot' => 'Entrada general (10:30)', 'hora' => '10:30', 'date' => '2026-08-04', 'stock' => 27],
    ['slot' => 'Entrada general (10:30)', 'hora' => '10:30', 'date' => '2026-08-07', 'stock' => 0],
]);
```

- [ ] **Step 8: Correr toda la suite**

Run: `/usr/bin/php8.4 artisan test`
Expected: PASS. La prueba de aceptación `11 funciones sobre 7 shows` sigue verde.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "feat: el parser de bookings devuelve la hora del slot por separado"
```

- [ ] **Step 10: Escribir el test que falla para las fechas descartadas**

El spec §4.1 pide un aviso `fecha_no_parseable`, pero el parser es mudo: descarta la fecha y no lo cuenta a nadie. Se agrega un segundo método que devuelve las dos cosas, sin tocar `parse()` ni los tests que ya pasan.

```php
test('parseConAvisos informa las fechas que no pudo leer', function () {
    $json = json_encode([
        'k' => [
            'label'          => 'X',
            'formatted_time' => '(10:00)',
            'add_date'       => [
                'a' => ['date' => 'basura',         'stock' => '5'],
                'b' => ['date' => 'agosto 7, 2026', 'stock' => '9'],
                'c' => ['date' => 'brumario 3, 2026', 'stock' => '1'],
            ],
        ],
    ]);

    $r = $this->parser->parseConAvisos($json);

    expect($r['funciones'])->toHaveCount(1);
    expect($r['descartadas'])->toBe(['basura', 'brumario 3, 2026']);
});

test('parse devuelve exactamente las funciones de parseConAvisos', function () {
    $json = json_encode([
        'k' => ['label' => 'X', 'formatted_time' => '(10:00)',
                'add_date' => ['a' => ['date' => 'agosto 7, 2026', 'stock' => '9']]],
    ]);

    expect($this->parser->parse($json))->toBe($this->parser->parseConAvisos($json)['funciones']);
});
```

- [ ] **Step 11: Implementar `parseConAvisos`**

En `BookingsOptionsParser`, renombrar el cuerpo actual de `parse()` a `parseConAvisos()`, acumulando las fechas ilegibles, y dejar `parse()` como delegación:

```php
/**
 * @return array{funciones: array<int, array{slot: string, hora: ?string, date: string, stock: int}>, descartadas: array<int, string>}
 */
public function parseConAvisos(?string $json): array
{
    // ... mismo cuerpo que tenía parse(), con dos cambios:
    //
    // 1. Antes del bucle:            $descartadas = [];
    // 2. Donde hoy dice:             if ($date === null) { continue; }
    //    pasa a decir:               if ($date === null) {
    //                                    if (trim((string) $rawDate) !== '') {
    //                                        $descartadas[] = (string) $rawDate;
    //                                    }
    //                                    continue;
    //                                }
    // 3. El return final:            return ['funciones' => $functions, 'descartadas' => $descartadas];
    //
    // Las salidas tempranas (json null, vacío, no-array) devuelven
    // ['funciones' => [], 'descartadas' => []].
}

/**
 * @return array<int, array{slot: string, hora: ?string, date: string, stock: int}>
 */
public function parse(?string $json): array
{
    return $this->parseConAvisos($json)['funciones'];
}
```

- [ ] **Step 12: Correr toda la suite y commitear**

Run: `/usr/bin/php8.4 artisan test`
Expected: PASS. Los tests migrados no cambian: siguen usando `parse()`.

```bash
git add -A
git commit -m "feat: el parser informa las fechas que no pudo leer"
```

---

### Task 3: Prorrateo por resto mayor

**Files:**
- Create: `app/Support/Prorrateo.php`
- Test: `tests/Unit/ProrrateoTest.php`

**Interfaces:**
- Consumes: nada
- Produces: `Prorrateo::repartir(int $total, array $entradas): array` — recibe `array<string, int>` (clave de función → entradas) y devuelve `array<string, int>` (clave de función → monto). La suma de los montos es **exactamente** `$total`.

- [ ] **Step 1: Escribir los tests que fallan**

`tests/Unit/ProrrateoTest.php`:

```php
<?php

use App\Support\Prorrateo;

test('una sola función se lleva todo', function () {
    expect(Prorrateo::repartir(70000, ['a' => 2]))->toBe(['a' => 70000]);
});

test('reparto exacto cuando divide justo', function () {
    expect(Prorrateo::repartir(90000, ['a' => 2, 'b' => 1]))->toBe(['a' => 60000, 'b' => 30000]);
});

test('el resto va a la función con mayor resto y la suma cierra', function () {
    // 100 entre 3 entradas: 33,33 por entrada. 2 entradas -> 66,66 y 1 -> 33,33.
    $r = Prorrateo::repartir(100, ['a' => 2, 'b' => 1]);

    expect(array_sum($r))->toBe(100);
    expect($r)->toBe(['a' => 67, 'b' => 33]);
});

test('nunca pierde ni inventa guaraníes, con cualquier reparto', function () {
    foreach ([[1, 1, 1], [5, 3, 2], [7, 1, 1, 1], [100, 1]] as $entradas) {
        $claves = [];
        foreach ($entradas as $i => $n) {
            $claves["f{$i}"] = $n;
        }

        foreach ([1, 7, 99999, 1234567] as $total) {
            expect(array_sum(Prorrateo::repartir($total, $claves)))->toBe($total);
        }
    }
});

test('sin entradas devuelve lista vacía', function () {
    expect(Prorrateo::repartir(50000, []))->toBe([]);
});

test('funciones con cero entradas reciben cero', function () {
    expect(Prorrateo::repartir(1000, ['a' => 0, 'b' => 2]))->toBe(['a' => 0, 'b' => 1000]);
});
```

- [ ] **Step 2: Correr y verificar que fallan**

Run: `/usr/bin/php8.4 artisan test tests/Unit/ProrrateoTest.php`
Expected: FAIL con "Class App\Support\Prorrateo not found".

- [ ] **Step 3: Implementar**

`app/Support/Prorrateo.php`:

```php
<?php

namespace App\Support;

/**
 * Las líneas de pedido no dicen a qué función pertenece cada entrada, así que
 * la plata de un par pedido+producto se reparte entre sus funciones en
 * proporción a las entradas de cada una.
 *
 * El reparto es por resto mayor: la suma de las partes es exactamente el total,
 * siempre. Nadie encuentra después un guaraní perdido.
 */
class Prorrateo
{
    /**
     * @param  array<string, int> $entradas clave de función => entradas
     * @return array<string, int>           clave de función => monto
     */
    public static function repartir(int $total, array $entradas): array
    {
        $totalEntradas = array_sum($entradas);

        if ($entradas === [] || $totalEntradas <= 0) {
            return array_map(fn () => 0, $entradas);
        }

        $montos  = [];
        $restos  = [];
        $asignado = 0;

        foreach ($entradas as $clave => $n) {
            $exacto        = $total * $n / $totalEntradas;
            $piso          = (int) floor($exacto);
            $montos[$clave] = $piso;
            $restos[$clave] = $exacto - $piso;
            $asignado      += $piso;
        }

        // Los guaraníes que quedaron sueltos van a los mayores restos.
        arsort($restos);

        foreach (array_keys($restos) as $clave) {
            if ($asignado >= $total) {
                break;
            }

            $montos[$clave]++;
            $asignado++;
        }

        return $montos;
    }
}
```

- [ ] **Step 4: Correr y verificar que pasan**

Run: `/usr/bin/php8.4 artisan test tests/Unit/ProrrateoTest.php`
Expected: PASS, 6 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Support/Prorrateo.php tests/Unit/ProrrateoTest.php
git commit -m "feat: prorrateo de recaudación por resto mayor"
```

---

### Task 4: El agregador — cruce de programación, ventas y plata

Es la clase que decide qué es una función y cuántas entradas tiene. Pura: recibe arrays, devuelve arrays.

**Files:**
- Create: `app/Services/FuncionesDelDia.php`
- Test: `tests/Unit/FuncionesDelDiaTest.php`

**Interfaces:**
- Consumes: `Prorrateo::repartir()` de la Task 3
- Produces: `FuncionesDelDia::armar(array $programacion, array $tickets, array $lineas): array` con la forma `['funciones' => array<int, array{...}>, 'avisos' => array<int, array{tipo: string, detalle: string}>]`
  - `$programacion`: `array<int, array{producto_id: int, show: string, slot: string, hora: ?string, stock: int}>` — ya filtrada a la fecha pedida
  - `$tickets`: `array<int, array{producto_id: int, pedido_id: int, slot: string, hora: ?string, estado: string}>` — una fila por entrada
  - `$lineas`: `array<string, array{neto: int, bruto: int, precios_distintos: int}>` — clave `"{pedido_id}:{producto_id}"`. `precios_distintos` es opcional y vale 1 si no viene

- [ ] **Step 1: Escribir los tests que fallan**

`tests/Unit/FuncionesDelDiaTest.php`:

```php
<?php

use App\Services\FuncionesDelDia;

function prog(int $id, string $slot, int $stock, ?string $hora = '10:00', string $show = 'Show'): array
{
    return ['producto_id' => $id, 'show' => $show, 'slot' => $slot, 'hora' => $hora, 'stock' => $stock];
}

function tick(int $id, string $slot, int $pedido, string $estado = 'wc-completed', ?string $hora = '10:00'): array
{
    return ['producto_id' => $id, 'pedido_id' => $pedido, 'slot' => $slot, 'hora' => $hora, 'estado' => $estado];
}

test('una función programada sin ventas aparece en cero', function () {
    $r = FuncionesDelDia::armar([prog(1, 'A', 20)], [], []);

    expect($r['funciones'])->toHaveCount(1);
    expect($r['funciones'][0])->toMatchArray([
        'producto_id'          => 1,
        'slot'                 => 'A',
        'entradas_vendidas'    => 0,
        'entradas_reagendadas' => 0,
        'cupos_habilitados'    => 20,
        'recaudacion_neta'     => 0,
        'recaudacion_bruta'    => 0,
    ]);
});

test('un ticket sin programación aparece con cupos null', function () {
    $r = FuncionesDelDia::armar([], [tick(1, 'A', 10)], ['10:1' => ['neto' => 5000, 'bruto' => 5500]]);

    expect($r['funciones'])->toHaveCount(1);
    expect($r['funciones'][0]['cupos_habilitados'])->toBeNull();
    expect($r['funciones'][0]['entradas_vendidas'])->toBe(1);
});

test('solo wc-completed cuenta como venta', function () {
    $r = FuncionesDelDia::armar(
        [prog(1, 'A', 20)],
        [tick(1, 'A', 10), tick(1, 'A', 11, 'wc-cancelled'), tick(1, 'A', 12, 'wc-refunded')],
        ['10:1' => ['neto' => 5000, 'bruto' => 5500]],
    );

    expect($r['funciones'][0]['entradas_vendidas'])->toBe(1);
    expect($r['avisos'])->toBe([]);
});

test('wc-reagendado va aparte y no suma', function () {
    $r = FuncionesDelDia::armar(
        [prog(1, 'A', 20)],
        [tick(1, 'A', 10), tick(1, 'A', 11, 'wc-reagendado')],
        ['10:1' => ['neto' => 5000, 'bruto' => 5500]],
    );

    expect($r['funciones'][0]['entradas_vendidas'])->toBe(1);
    expect($r['funciones'][0]['entradas_reagendadas'])->toBe(1);
});

test('un estado desconocido no cuenta y genera aviso', function () {
    $r = FuncionesDelDia::armar([prog(1, 'A', 20)], [tick(1, 'A', 10, 'wc-loquesea')], []);

    expect($r['funciones'][0]['entradas_vendidas'])->toBe(0);
    expect($r['avisos'])->toHaveCount(1);
    expect($r['avisos'][0]['tipo'])->toBe('estado_desconocido');
    expect($r['avisos'][0]['detalle'])->toContain('wc-loquesea');
});

test('la plata de un pedido se reparte entre las funciones de ese producto', function () {
    // Un pedido, un producto, 3 entradas repartidas 2/1 entre dos funciones.
    $r = FuncionesDelDia::armar(
        [prog(1, 'A', 20, '10:00'), prog(1, 'B', 20, '11:00')],
        [tick(1, 'A', 10), tick(1, 'A', 10), tick(1, 'B', 10, hora: '11:00')],
        ['10:1' => ['neto' => 90000, 'bruto' => 99000]],
    );

    $porSlot = array_column($r['funciones'], null, 'slot');

    expect($porSlot['A']['recaudacion_neta'])->toBe(60000);
    expect($porSlot['B']['recaudacion_neta'])->toBe(30000);
    expect($porSlot['A']['recaudacion_bruta'] + $porSlot['B']['recaudacion_bruta'])->toBe(99000);
});

test('avisa cuando el prorrateo es ambiguo, pero reparte igual', function () {
    // Un par con precios unitarios distintos entre líneas Y entradas en más de
    // una función: el promedio puede repartir mal. Hoy no existe ningún caso
    // así en la base, pero puede aparecer mañana. Perder la recaudación sería
    // peor que repartirla: a nivel producto y a nivel día el total es exacto.
    $r = FuncionesDelDia::armar(
        [prog(1, 'A', 20, '10:00'), prog(1, 'B', 20, '11:00')],
        [tick(1, 'A', 10), tick(1, 'B', 10, hora: '11:00')],
        ['10:1' => ['neto' => 90000, 'bruto' => 99000, 'precios_distintos' => 2]],
    );

    expect(array_sum(array_column($r['funciones'], 'recaudacion_neta')))->toBe(90000);
    expect($r['avisos'])->toHaveCount(1);
    expect($r['avisos'][0]['tipo'])->toBe('prorrateo_ambiguo');
});

test('precios distintos en una sola función no es ambiguo', function () {
    $r = FuncionesDelDia::armar(
        [prog(1, 'A', 20)],
        [tick(1, 'A', 10), tick(1, 'A', 10)],
        ['10:1' => ['neto' => 90000, 'bruto' => 99000, 'precios_distintos' => 2]],
    );

    expect($r['avisos'])->toBe([]);
    expect($r['funciones'][0]['recaudacion_neta'])->toBe(90000);
});

test('las funciones vienen ordenadas por hora y luego por show', function () {
    $r = FuncionesDelDia::armar([
        prog(2, 'X', 5, '18:00', 'Zeta'),
        prog(1, 'Y', 5, '09:00', 'Alfa'),
        prog(3, 'Z', 5, '18:00', 'Beta'),
        prog(4, 'W', 5, null,    'Sin hora'),
    ], [], []);

    expect(array_column($r['funciones'], 'show'))->toBe(['Alfa', 'Beta', 'Zeta', 'Sin hora']);
});

test('el título se limpia del espacio duro', function () {
    $r = FuncionesDelDia::armar([prog(1, 'A', 5, '10:00', "De estrellas\u{a0}a supernovas")], [], []);

    expect($r['funciones'][0]['show'])->toBe('De estrellas a supernovas');
});
```

- [ ] **Step 2: Correr y verificar que fallan**

Run: `/usr/bin/php8.4 artisan test tests/Unit/FuncionesDelDiaTest.php`
Expected: FAIL con "Class App\Services\FuncionesDelDia not found".

- [ ] **Step 3: Implementar**

`app/Services/FuncionesDelDia.php`:

```php
<?php

namespace App\Services;

use App\Support\Prorrateo;

/**
 * Cruza la programación del día con las entradas vendidas y la plata.
 *
 * Regla del cruce, en las dos direcciones: una función programada sin ventas
 * aparece en cero, y una entrada cuya función ya no figura en la programación
 * aparece igual, con cupos null. Perder una venta es peor que mostrar una
 * función de más.
 */
class FuncionesDelDia
{
    private const CUENTA     = 'wc-completed';
    private const REAGENDADO = 'wc-reagendado';
    private const IGNORADOS  = ['wc-cancelled', 'wc-refunded', 'wc-pending'];

    public static function armar(array $programacion, array $tickets, array $lineas): array
    {
        $avisos    = [];
        $funciones = [];

        foreach ($programacion as $p) {
            $funciones[self::clave($p['producto_id'], $p['slot'])] = [
                'producto_id'          => $p['producto_id'],
                'show'                 => self::limpiar($p['show']),
                'slot'                 => $p['slot'],
                'hora'                 => $p['hora'],
                'entradas_vendidas'    => 0,
                'entradas_reagendadas' => 0,
                'cupos_habilitados'    => $p['stock'],
                'recaudacion_neta'     => 0,
                'recaudacion_bruta'    => 0,
            ];
        }

        // Entradas por función, y entradas por par pedido+producto+función
        // (esto último es lo que necesita el prorrateo).
        $porPar = [];

        foreach ($tickets as $t) {
            $clave = self::clave($t['producto_id'], $t['slot']);

            if (! isset($funciones[$clave])) {
                $funciones[$clave] = [
                    'producto_id'          => $t['producto_id'],
                    'show'                 => '',
                    'slot'                 => $t['slot'],
                    'hora'                 => $t['hora'],
                    'entradas_vendidas'    => 0,
                    'entradas_reagendadas' => 0,
                    'cupos_habilitados'    => null,
                    'recaudacion_neta'     => 0,
                    'recaudacion_bruta'    => 0,
                ];
            }

            if ($t['estado'] === self::CUENTA) {
                $funciones[$clave]['entradas_vendidas']++;
                $par = $t['pedido_id'] . ':' . $t['producto_id'];
                $porPar[$par][$clave] = ($porPar[$par][$clave] ?? 0) + 1;

                continue;
            }

            if ($t['estado'] === self::REAGENDADO) {
                $funciones[$clave]['entradas_reagendadas']++;

                continue;
            }

            if (! in_array($t['estado'], self::IGNORADOS, true)) {
                $avisos[] = [
                    'tipo'    => 'estado_desconocido',
                    'detalle' => "Pedido {$t['pedido_id']} en estado {$t['estado']}: no se contó.",
                ];
            }
        }

        foreach ($porPar as $par => $entradasPorFuncion) {
            $linea = $lineas[$par] ?? ['neto' => 0, 'bruto' => 0];

            if (($linea['precios_distintos'] ?? 1) > 1 && count($entradasPorFuncion) > 1) {
                $avisos[] = [
                    'tipo'    => 'prorrateo_ambiguo',
                    'detalle' => "Par {$par}: precios distintos entre líneas y entradas en "
                        . count($entradasPorFuncion) . ' funciones. Se repartió con el promedio.',
                ];
            }

            foreach (['neto', 'bruto'] as $tipo) {
                foreach (Prorrateo::repartir($linea[$tipo], $entradasPorFuncion) as $clave => $monto) {
                    $funciones[$clave]["recaudacion_{$tipo}"] += $monto;
                }
            }
        }

        return ['funciones' => self::ordenar($funciones), 'avisos' => $avisos];
    }

    private static function clave(int $productoId, string $slot): string
    {
        return $productoId . '|' . $slot;
    }

    /** El espacio duro U+00A0 aparece en títulos reales de esta base. */
    private static function limpiar(string $texto): string
    {
        return trim(str_replace("\u{a0}", ' ', $texto));
    }

    /** Por hora, y a igual hora por show. Las funciones sin hora van al final. */
    private static function ordenar(array $funciones): array
    {
        $funciones = array_values($funciones);

        usort($funciones, function (array $a, array $b) {
            if ($a['hora'] === $b['hora']) {
                return strcmp($a['show'], $b['show']);
            }

            return match (true) {
                $a['hora'] === null => 1,
                $b['hora'] === null => -1,
                default             => strcmp($a['hora'], $b['hora']),
            };
        });

        return $funciones;
    }
}
```

- [ ] **Step 4: Correr y verificar que pasan**

Run: `/usr/bin/php8.4 artisan test tests/Unit/FuncionesDelDiaTest.php`
Expected: PASS, 8 tests.

- [ ] **Step 5: Correr toda la suite**

Run: `/usr/bin/php8.4 artisan test`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/FuncionesDelDia.php tests/Unit/FuncionesDelDiaTest.php
git commit -m "feat: agregador de funciones del día con avisos y prorrateo"
```

---

### Task 5: El repositorio contra `muci`

Devuelve filas crudas a propósito: toda la agregación queda en las clases puras, que se testean sin base.

**Files:**
- Create: `app/Repositories/FooEventsRepository.php`
- Test: `tests/Feature/FooEventsRepositoryTest.php`

**Interfaces:**
- Consumes: conexión `muci` de la Task 1
- Produces:
  - `productosConBookings(): array` → `array<int, array{producto_id: int, show: string, bookings_json: ?string}>`
  - `ticketsDeLaFecha(string $fecha): array` → `array<int, array{producto_id: int, pedido_id: int, slot: string, hora: ?string, estado: string}>`
  - `lineasDe(array $pares): array` → `array<string, array{neto: int, bruto: int, precios_distintos: int}>`, clave `"{pedido_id}:{producto_id}"`

- [ ] **Step 1: Escribir los tests de integración**

`tests/Feature/FooEventsRepositoryTest.php`:

```php
<?php

use App\Repositories\FooEventsRepository;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    try {
        DB::connection('muci')->getPdo();
    } catch (\Throwable $e) {
        // La base muci no existe fuera del servidor. No es un fallo.
        test()->markTestSkipped('Sin acceso a la base muci.');
    }

    $this->repo = new FooEventsRepository();
});

test('trae los productos dateslot publicados con su meta de bookings', function () {
    $productos = $this->repo->productosConBookings();

    expect(count($productos))->toBeGreaterThan(10);
    expect($productos[0])->toHaveKeys(['producto_id', 'show', 'bookings_json']);
});

test('los tickets de una fecha traen slot, hora y estado del pedido', function () {
    $tickets = $this->repo->ticketsDeLaFecha('2026-08-07');

    expect($tickets)->not->toBeEmpty();

    foreach ($tickets as $t) {
        expect($t['slot'])->not->toBe('');
        expect($t['estado'])->toStartWith('wc-');
    }
});

test('el 2026-08-07 hay 26 entradas completadas sobre 6 funciones', function () {
    // Referencia del spec del 2026-08-07 §13, confirmada el 2026-08-12.
    $tickets = array_filter(
        $this->repo->ticketsDeLaFecha('2026-08-07'),
        fn ($t) => $t['estado'] === 'wc-completed'
    );

    $funciones = array_unique(array_map(
        fn ($t) => $t['producto_id'] . '|' . $t['slot'],
        $tickets
    ));

    expect($tickets)->toHaveCount(26);
    expect($funciones)->toHaveCount(6);
});

test('las líneas de un par pedido+producto traen neto y bruto enteros', function () {
    $tickets = $this->repo->ticketsDeLaFecha('2026-08-07');
    $pares   = array_values(array_unique(array_map(
        fn ($t) => $t['pedido_id'] . ':' . $t['producto_id'],
        $tickets
    )));

    $lineas = $this->repo->lineasDe(array_slice($pares, 0, 5));

    foreach ($lineas as $l) {
        expect($l['neto'])->toBeInt();
        expect($l['bruto'])->toBeGreaterThanOrEqual($l['neto']);
    }
});
```

- [ ] **Step 2: Correr y verificar que fallan**

Run: `/usr/bin/php8.4 artisan test tests/Feature/FooEventsRepositoryTest.php`
Expected: FAIL con "Class App\Repositories\FooEventsRepository not found". En local, si la base no está, deben aparecer como **skipped** una vez creada la clase.

- [ ] **Step 3: Implementar**

`app/Repositories/FooEventsRepository.php`:

```php
<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

/**
 * Las tres consultas a la base muci. Devuelve filas crudas a propósito: la
 * agregación vive en clases puras que se pueden testear sin base.
 *
 * CURDATE() y NOW() están prohibidos acá: el servidor corre en UTC y el museo
 * en America/Asuncion. La fecha entra siempre como parámetro.
 */
class FooEventsRepository
{
    private function db()
    {
        return DB::connection('muci');
    }

    /** @return array<int, array{producto_id: int, show: string, bookings_json: ?string}> */
    public function productosConBookings(): array
    {
        $filas = $this->db()->select(<<<'SQL'
            SELECT p.ID AS producto_id,
                   p.post_title AS show_titulo,
                   bm.meta_value AS bookings_json
            FROM wpzv_posts p
            JOIN wpzv_postmeta mm ON mm.post_id = p.ID
                 AND mm.meta_key = 'WooCommerceEventsBookingsMethod'
                 AND mm.meta_value = 'dateslot'
            LEFT JOIN wpzv_postmeta bm ON bm.post_id = p.ID
                 AND bm.meta_key = 'fooevents_bookings_options_serialized'
            WHERE p.post_type = 'product' AND p.post_status = 'publish'
        SQL);

        return array_map(fn ($f) => [
            'producto_id'   => (int) $f->producto_id,
            'show'          => (string) $f->show_titulo,
            'bookings_json' => $f->bookings_json,
        ], $filas);
    }

    /**
     * Una fila por entrada. La fuente son los posts event_magic_tickets, no las
     * líneas de pedido: 206 de 375 líneas no traen meta de evento y perderíamos
     * la mayoría de las ventas, incluidas todas las de colegios.
     *
     * @return array<int, array{producto_id: int, pedido_id: int, slot: string, hora: ?string, estado: string}>
     */
    public function ticketsDeLaFecha(string $fecha): array
    {
        $filas = $this->db()->select(<<<'SQL'
            SELECT CAST(mp.meta_value AS UNSIGNED) AS producto_id,
                   CAST(mo.meta_value AS UNSIGNED) AS pedido_id,
                   ms.meta_value AS slot,
                   md.meta_value AS booking_date,
                   o.status AS estado
            FROM wpzv_posts p
            JOIN wpzv_postmeta mo ON mo.post_id = p.ID AND mo.meta_key = 'WooCommerceEventsOrderID'
            JOIN wpzv_postmeta mp ON mp.post_id = p.ID AND mp.meta_key = 'WooCommerceEventsProductID'
            JOIN wpzv_postmeta md ON md.post_id = p.ID
                 AND md.meta_key = 'WooCommerceEventsBookingDateMySQLFormat'
                 AND md.meta_value LIKE ?
            JOIN wpzv_postmeta ms ON ms.post_id = p.ID
                 AND ms.meta_key = 'WooCommerceEventsBookingSlot' AND ms.meta_value <> ''
            JOIN wpzv_wc_orders o ON o.id = CAST(mo.meta_value AS UNSIGNED)
            LEFT JOIN wpzv_wc_orders_meta cm ON cm.order_id = o.id
                 AND cm.meta_key = 'WooCommerceEventsOrderCanceled' AND cm.meta_value = '1'
            WHERE p.post_type = 'event_magic_tickets' AND cm.order_id IS NULL
        SQL, [$fecha . '%']);

        return array_map(fn ($f) => [
            'producto_id' => (int) $f->producto_id,
            'pedido_id'   => (int) $f->pedido_id,
            'slot'        => (string) $f->slot,
            'hora'        => substr((string) $f->booking_date, 11, 5) ?: null,
            'estado'      => (string) $f->estado,
        ], $filas);
    }

    /**
     * `precios_distintos` cuenta los precios unitarios distintos entre las
     * líneas del mismo par. Si es mayor a 1 y el par reparte entradas en varias
     * funciones, el prorrateo es ambiguo y el agregador lo avisa. Al 2026-08-12
     * esa intersección es 0 en toda la base, pero puede aparecer mañana.
     *
     * @param  array<int, string> $pares  claves "{pedido_id}:{producto_id}"
     * @return array<string, array{neto: int, bruto: int, precios_distintos: int}>
     */
    public function lineasDe(array $pares): array
    {
        if ($pares === []) {
            return [];
        }

        $pedidos   = array_values(array_unique(array_map(fn ($p) => (int) explode(':', $p)[0], $pares)));
        $marcadores = implode(',', array_fill(0, count($pedidos), '?'));

        $filas = $this->db()->select(<<<SQL
            SELECT oi.order_id AS pedido_id,
                   CAST(pm.meta_value AS UNSIGNED) AS producto_id,
                   SUM(CAST(lt.meta_value AS DECIMAL(16,4))) AS neto,
                   SUM(CAST(lt.meta_value AS DECIMAL(16,4))
                       + COALESCE(CAST(tx.meta_value AS DECIMAL(16,4)), 0)) AS bruto,
                   COUNT(DISTINCT CAST(lt.meta_value AS DECIMAL(16,4))
                       / NULLIF(CAST(qm.meta_value AS SIGNED), 0)) AS precios_distintos
            FROM wpzv_woocommerce_order_items oi
            JOIN wpzv_woocommerce_order_itemmeta pm ON pm.order_item_id = oi.order_item_id
                 AND pm.meta_key = '_product_id'
            LEFT JOIN wpzv_woocommerce_order_itemmeta lt ON lt.order_item_id = oi.order_item_id
                 AND lt.meta_key = '_line_total'
            LEFT JOIN wpzv_woocommerce_order_itemmeta tx ON tx.order_item_id = oi.order_item_id
                 AND tx.meta_key = '_line_tax'
            LEFT JOIN wpzv_woocommerce_order_itemmeta qm ON qm.order_item_id = oi.order_item_id
                 AND qm.meta_key = '_qty'
            WHERE oi.order_item_type = 'line_item' AND oi.order_id IN ({$marcadores})
            GROUP BY 1, 2
        SQL, $pedidos);

        $resultado = [];

        foreach ($filas as $f) {
            $clave = $f->pedido_id . ':' . $f->producto_id;

            if (in_array($clave, $pares, true)) {
                $resultado[$clave] = [
                    'neto'              => (int) round((float) $f->neto),
                    'bruto'             => (int) round((float) $f->bruto),
                    'precios_distintos' => (int) $f->precios_distintos,
                ];
            }
        }

        return $resultado;
    }
}
```

- [ ] **Step 4: Correr los tests**

Run: `/usr/bin/php8.4 artisan test tests/Feature/FooEventsRepositoryTest.php`
Expected en local: 4 SKIPPED. Expected en el servidor: PASS.

- [ ] **Step 5: Correr contra la base real desde el servidor**

```bash
ssh -i ~/.ssh/muci anthropic_readonly@muci.org
# con el repo desplegado y el .env apuntando a muci:
/usr/bin/php8.4 artisan test tests/Feature/FooEventsRepositoryTest.php
```

Expected: PASS, incluida `el 2026-08-07 hay 26 entradas completadas sobre 6 funciones`. Si ese test falla, **parar**: el cruce está mal y todo lo que sigue hereda el error.

- [ ] **Step 6: Commit**

```bash
git add app/Repositories/FooEventsRepository.php tests/Feature/FooEventsRepositoryTest.php
git commit -m "feat: repositorio de lectura de la base muci"
```

---

### Task 6: Conectar el controlador y fijar la respuesta canónica

**Files:**
- Modify: `app/Http/Controllers/FuncionesController.php`
- Create: `tests/Fixtures/respuesta-ejemplo.json`
- Test: `tests/Feature/FuncionesEndpointTest.php` (agregar casos), `tests/Feature/RespuestaCanonicaTest.php`

**Interfaces:**
- Consumes: `FooEventsRepository` (Task 5), `FuncionesDelDia::armar()` (Task 4), `BookingsOptionsParser::parse()` (Task 2)
- Produces: `tests/Fixtures/respuesta-ejemplo.json`, que el CRM copia como fixture de `Http::fake()`

- [ ] **Step 1: Escribir los tests que fallan**

Agregar a `tests/Feature/FuncionesEndpointTest.php`:

```php
test('una fecha sin funciones devuelve 200 con lista vacía, no 404', function () {
    $this->mock(\App\Repositories\FooEventsRepository::class, function ($m) {
        $m->shouldReceive('productosConBookings')->andReturn([]);
        $m->shouldReceive('ticketsDeLaFecha')->andReturn([]);
        $m->shouldReceive('lineasDe')->andReturn([]);
    });

    pedir('?fecha=2030-01-01')->assertOk()->assertJsonPath('funciones', []);
});

test('503 si la base muci no responde', function () {
    $this->mock(\App\Repositories\FooEventsRepository::class, function ($m) {
        $m->shouldReceive('productosConBookings')
          ->andThrow(new \Illuminate\Database\QueryException('muci', 'select 1', [], new \Exception('caída')));
    });

    pedir()->assertStatus(503)->assertJsonPath('error', 'origen_no_disponible');
});

test('un producto con JSON ilegible genera aviso y no rompe el día', function () {
    $this->mock(\App\Repositories\FooEventsRepository::class, function ($m) {
        $m->shouldReceive('productosConBookings')->andReturn([
            ['producto_id' => 1, 'show' => 'Roto',  'bookings_json' => '{roto'],
            ['producto_id' => 2, 'show' => 'Sano',  'bookings_json' => json_encode([
                'k' => ['label' => 'A', 'formatted_time' => '(10:00)',
                        'add_date' => ['d' => ['date' => 'agosto 7, 2026', 'stock' => '5']]],
            ])],
        ]);
        $m->shouldReceive('ticketsDeLaFecha')->andReturn([]);
        $m->shouldReceive('lineasDe')->andReturn([]);
    });

    $r = pedir()->assertOk();

    expect($r->json('funciones'))->toHaveCount(1);
    expect($r->json('avisos.0.tipo'))->toBe('json_ilegible');
    expect($r->json('avisos.0.detalle'))->toContain('1');
});

test('una fecha ilegible genera aviso y conserva el resto del slot', function () {
    $this->mock(\App\Repositories\FooEventsRepository::class, function ($m) {
        $m->shouldReceive('productosConBookings')->andReturn([
            ['producto_id' => 7, 'show' => 'Mixto', 'bookings_json' => json_encode([
                'k' => ['label' => 'A', 'formatted_time' => '(10:00)', 'add_date' => [
                    'a' => ['date' => 'brumario 3, 2026', 'stock' => '1'],
                    'b' => ['date' => 'agosto 7, 2026',   'stock' => '5'],
                ]],
            ])],
        ]);
        $m->shouldReceive('ticketsDeLaFecha')->andReturn([]);
        $m->shouldReceive('lineasDe')->andReturn([]);
    });

    $r = pedir()->assertOk();

    expect($r->json('funciones'))->toHaveCount(1);
    expect($r->json('avisos.0.tipo'))->toBe('fecha_no_parseable');
});

test('la meta vacía no genera aviso', function () {
    $this->mock(\App\Repositories\FooEventsRepository::class, function ($m) {
        $m->shouldReceive('productosConBookings')->andReturn([
            ['producto_id' => 1, 'show' => 'Vacío', 'bookings_json' => null],
            ['producto_id' => 2, 'show' => 'Vacío', 'bookings_json' => '[]'],
        ]);
        $m->shouldReceive('ticketsDeLaFecha')->andReturn([]);
        $m->shouldReceive('lineasDe')->andReturn([]);
    });

    pedir()->assertOk()->assertJsonPath('avisos', []);
});
```

`tests/Feature/RespuestaCanonicaTest.php`:

```php
<?php

test('la respuesta canónica del fixture coincide con la que produce el servicio', function () {
    config()->set('fooevents.token', 'token-de-prueba');

    $this->mock(\App\Repositories\FooEventsRepository::class, function ($m) {
        $m->shouldReceive('productosConBookings')->andReturn([
            ['producto_id' => 192637, 'show' => 'Entrada Bioestanque', 'bookings_json' => json_encode([
                'k' => ['label' => 'BioEstanque (16:00)', 'formatted_time' => '(17:00)',
                        'add_date' => ['d' => ['date' => 'agosto 7, 2026', 'stock' => '18']]],
            ])],
        ]);
        $m->shouldReceive('ticketsDeLaFecha')->andReturn([
            ['producto_id' => 192637, 'pedido_id' => 555, 'slot' => 'BioEstanque (16:00) (17:00)',
             'hora' => '17:00', 'estado' => 'wc-completed'],
            ['producto_id' => 192637, 'pedido_id' => 555, 'slot' => 'BioEstanque (16:00) (17:00)',
             'hora' => '17:00', 'estado' => 'wc-completed'],
        ]);
        $m->shouldReceive('lineasDe')->andReturn(['555:192637' => ['neto' => 63636, 'bruto' => 70000]]);
    });

    $actual = $this->getJson('/v1/funciones?fecha=2026-08-07', [
        'Authorization' => 'Bearer token-de-prueba',
    ])->json();

    // generado_en es la hora de corrida; no forma parte de la comparación.
    unset($actual['generado_en']);

    $esperado = json_decode(file_get_contents(__DIR__ . '/../Fixtures/respuesta-ejemplo.json'), true);
    unset($esperado['generado_en']);

    expect($actual)->toBe($esperado);
});
```

`tests/Fixtures/respuesta-ejemplo.json`:

```json
{
  "fecha": "2026-08-07",
  "generado_en": "2026-08-07T17:30:00-03:00",
  "avisos": [],
  "funciones": [
    {
      "producto_id": 192637,
      "show": "Entrada Bioestanque",
      "slot": "BioEstanque (16:00) (17:00)",
      "hora": "17:00",
      "entradas_vendidas": 2,
      "entradas_reagendadas": 0,
      "cupos_habilitados": 18,
      "recaudacion_neta": 63636,
      "recaudacion_bruta": 70000
    }
  ]
}
```

- [ ] **Step 2: Correr y verificar que fallan**

Run: `/usr/bin/php8.4 artisan test tests/Feature`
Expected: FAIL — el controlador todavía devuelve `funciones: []` siempre.

- [ ] **Step 3: Implementar el controlador**

Reemplazar el cuerpo de `__invoke` después de la validación de fecha:

```php
public function __invoke(Request $request, FooEventsRepository $repo, BookingsOptionsParser $parser)
{
    $fecha = (string) $request->query('fecha', '');

    if (! $this->fechaValida($fecha)) {
        return response()->json([
            'error'   => 'fecha_invalida',
            'mensaje' => 'El parámetro fecha es obligatorio, con formato YYYY-MM-DD.',
        ], 422);
    }

    try {
        [$programacion, $avisosParseo] = $this->programacion($repo, $parser, $fecha);

        $tickets = $repo->ticketsDeLaFecha($fecha);
        $pares   = array_values(array_unique(array_map(
            fn ($t) => $t['pedido_id'] . ':' . $t['producto_id'],
            array_filter($tickets, fn ($t) => $t['estado'] === 'wc-completed')
        )));

        $armado = FuncionesDelDia::armar($programacion, $tickets, $repo->lineasDe($pares));
    } catch (QueryException $e) {
        report($e);

        return response()->json([
            'error'   => 'origen_no_disponible',
            'mensaje' => 'No se pudo leer la base de WooCommerce.',
        ], 503);
    }

    return response()->json([
        'fecha'       => $fecha,
        'generado_en' => CarbonImmutable::now(config('fooevents.timezone'))->toIso8601String(),
        'avisos'      => array_merge($avisosParseo, $armado['avisos']),
        'funciones'   => $armado['funciones'],
    ]);
}

/**
 * Un producto cuyo JSON no se entiende se saltea con aviso; uno con la meta
 * vacía es normal y no genera nada. Si la meta vacía se volviera ruidosa, los
 * avisos dejarían de leerse y el mecanismo moriría.
 *
 * @return array{0: array<int, array>, 1: array<int, array{tipo: string, detalle: string}>}
 */
private function programacion(FooEventsRepository $repo, BookingsOptionsParser $parser, string $fecha): array
{
    $programacion = [];
    $avisos       = [];

    foreach ($repo->productosConBookings() as $producto) {
        $json = $producto['bookings_json'];

        if ($json === null || trim($json) === '' || in_array(trim($json), ['[]', '{}', 'null'], true)) {
            continue;
        }

        $leido     = $parser->parseConAvisos($json);
        $funciones = $leido['funciones'];

        foreach ($leido['descartadas'] as $cruda) {
            $avisos[] = [
                'tipo'    => 'fecha_no_parseable',
                'detalle' => "Producto {$producto['producto_id']}: fecha \"{$cruda}\" ilegible, se descartó.",
            ];
        }

        if ($funciones === []) {
            $avisos[] = [
                'tipo'    => 'json_ilegible',
                'detalle' => "Producto {$producto['producto_id']}: no se pudo leer la meta de bookings.",
            ];

            continue;
        }

        foreach ($funciones as $f) {
            if ($f['date'] !== $fecha) {
                continue;
            }

            $programacion[] = [
                'producto_id' => $producto['producto_id'],
                'show'        => $producto['show'],
                'slot'        => $f['slot'],
                'hora'        => $f['hora'],
                'stock'       => $f['stock'],
            ];
        }
    }

    return [$programacion, $avisos];
}
```

Agregar los `use` de `App\Repositories\FooEventsRepository`, `App\Services\FuncionesDelDia`, `App\Support\BookingsOptionsParser` e `Illuminate\Database\QueryException`.

- [ ] **Step 4: Correr y verificar que pasan**

Run: `/usr/bin/php8.4 artisan test`
Expected: PASS, toda la suite.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: el endpoint devuelve las funciones reales del día"
```

---

### Task 7: Despliegue y verificación contra producción

**Files:**
- Create: `deploy/nginx-servicio-fooevents.conf`, `deploy/php-fpm-pool.conf`
- Create: `README.md` con el procedimiento de verificación

**Interfaces:**
- Consumes: todo lo anterior
- Produces: el endpoint vivo en `127.0.0.1:8081`, y el token que va al `.env` del CRM

- [ ] **Step 1: Escribir el vhost de nginx**

`deploy/nginx-servicio-fooevents.conf`:

```nginx
server {
    listen 127.0.0.1:8081;
    server_name 127.0.0.1;
    root /var/www/servicio-fooevents/public;

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm-servicio-fooevents.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

Solo `listen 127.0.0.1:8081`: sin superficie de red nueva. No hace falta HTTPS porque el tráfico no sale de la máquina.

- [ ] **Step 2: Desplegar**

```bash
# En el servidor
sudo mkdir -p /var/www/servicio-fooevents
sudo chown -R www-data:www-data /var/www/servicio-fooevents
# clonar el repo ahí, y después:
cd /var/www/servicio-fooevents
/usr/bin/php8.4 /usr/local/bin/composer install --no-dev --optimize-autoloader
cp .env.example .env && /usr/bin/php8.4 artisan key:generate
# completar en .env: FOOEVENTS_TOKEN, MUCI_DB_USERNAME, MUCI_DB_PASSWORD
/usr/bin/php8.4 artisan config:cache
sudo ln -s /etc/nginx/sites-available/servicio-fooevents /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

El token se genera con `/usr/bin/php8.4 -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'` y va idéntico en los dos `.env`.

- [ ] **Step 3: Verificar que el endpoint responde y que está cerrado desde afuera**

```bash
# En el servidor: debe dar 200
curl -s -o /dev/null -w '%{http_code}\n' -H "Authorization: Bearer $TOKEN" \
  'http://127.0.0.1:8081/v1/funciones?fecha=2026-08-07'

# Sin token: debe dar 401
curl -s -o /dev/null -w '%{http_code}\n' 'http://127.0.0.1:8081/v1/funciones?fecha=2026-08-07'

# Desde tu máquina: debe fallar la conexión, no responder
curl -s --max-time 5 'http://muci.org:8081/v1/funciones?fecha=2026-08-07' ; echo "salida: $?"
```

Expected: `200`, `401`, y fallo de conexión desde afuera. Si el tercero responde algo, **parar y arreglar el `listen`** antes de seguir.

- [ ] **Step 4: Contrastar contra los números conocidos**

```bash
curl -s -H "Authorization: Bearer $TOKEN" \
  'http://127.0.0.1:8081/v1/funciones?fecha=2026-08-07' | /usr/bin/php8.4 -r '
$r = json_decode(stream_get_contents(STDIN), true);
printf("funciones: %d | entradas: %d | avisos: %d\n",
    count($r["funciones"]),
    array_sum(array_column($r["funciones"], "entradas_vendidas")),
    count($r["avisos"]));'
```

Expected: **11 funciones**, **26 entradas**, **0 avisos**. Los 11 vienen de la programación (incluidas las 5 sin ventas) y los 26 de las 6 funciones con venta.

Si dan 7 funciones, el parser está ignorando la forma B. Si dan 6, la programación no se está cruzando y solo se ven las funciones con venta.

- [ ] **Step 5: Escribir el README con el procedimiento**

Documentar en `README.md`: qué hace el servicio, la verificación del Step 4 con la advertencia de que **los números se mueven** —contrastar contra la base en el momento, no contra la tabla—, y el recordatorio de `/usr/bin/php8.4` explícito.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "docs: despliegue en 127.0.0.1:8081 y verificación contra producción"
```

---

## Lo que queda para el segundo plan

El lado del CRM: cliente HTTP con reintento, comando `ticket-sales:sync`, tabla `muci_ticket_sales_snapshot` con escritura atómica, los cinco estados de la vista, y el retiro de la conexión `woocommerce` y las variables `WC_DB_*`. Se escribe cuando este servicio esté vivo y verificado, porque recién ahí el fixture `respuesta-ejemplo.json` es real y no una suposición.
