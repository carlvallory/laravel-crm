# San Cosmos en el panel izquierdo — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que el panel izquierdo de la vista de pantalla quede reservado a las actividades del domo, englobadas bajo un solo rótulo y sin mostrar sus nombres, con las especiales a la derecha con nombre.

**Architecture:** El servicio agrega a cada función la lista de slugs de `product_cat` del producto. El CRM la guarda cruda en una columna nueva del snapshot y decide qué cuenta como San Cosmos con una lista que vive en `core_config`, editable desde una página nueva del CRM. `ProgramacionDePantalla` deja de elegir el panel por ventas y reparte por categoría; el criterio en cascada que ya existe pasa a ordenar solo la derecha.

**Tech Stack:** PHP 8.2 (CRM) / 8.4 (servicio), Laravel 10, Krayin CRM 2.1, Pest, MySQL, Blade.

**Spec:** `docs/superpowers/specs/2026-08-26-san-cosmos-panel-izquierdo-design.md`

## Global Constraints

- **No tocar el core:** nada en `packages/Webkul/*`, `vendor/*`, ni `app/Console/Kernel.php`.
- **Toda migración con `down()` completo.** El paquete tiene que ser reversible por desinstalación.
- **Los tests del CRM van en `laravel-crm/tests/`**, no dentro del paquete. Es la convención de los 8 paquetes hermanos.
- **Los tests del CRM no usan ninguna base ajena.** Lo del servicio va por `Http::fake()` contra `tests/Fixtures/fooevents/respuesta-ejemplo.json`.
- **No hay `.env.testing`:** los Feature del CRM corren contra la base `krayin` de desarrollo. Todo test que escriba usa `DatabaseTransactions`.
- **Correr los tests del CRM:** `php artisan test tests/Unit/TicketSales tests/Feature/TicketSales` desde `/home/vallory/code/crm/ticket-sales`. Si aparece «Class ... ServiceProvider not found», el manifest quedó viejo: `rm -f bootstrap/cache/packages.php bootstrap/cache/services.php && php artisan package:discover`.
- **Correr los tests del servicio:** `php artisan test` desde `~/code/servicio-fooevents`. Los del repositorio se auto-saltean sin la base `muci`; eso es normal fuera del servidor.
- **Paleta MuCi:** `#F17DB1`, `#00B26B`, `#000000`, `#6950A1`, `#F37043`. Tipografía Poppins Bold y variantes. El layout admin de Krayin carga Inter, no Poppins: la fuente la trae la vista.
- **En prod, todo artisan/composer del CRM con `/usr/bin/php8.2` explícito.** El servicio va con `/usr/bin/php8.4`. En prod no se toca git del CRM: los paquetes entran por composer desde GitHub.
- **La fixture canónica está duplicada a propósito** en `~/code/servicio-fooevents/tests/Fixtures/respuesta-ejemplo.json` y en `laravel-crm/tests/Fixtures/fooevents/respuesta-ejemplo.json`, y hoy son **byte-idénticas**. Cualquier cambio va a las dos en el mismo paso.
- **Prueba de aceptación, no se toca:** `/usr/bin/php8.2 artisan ticket-sales:sync --fecha=2026-08-07` tiene que informar `11 funciones | 26 entradas | 0 avisos`. Los números de venta se mueven: contrastar contra el servicio en el momento, no contra tablas viejas.
- **Disciplina de la casa — mutar antes de cerrar cada task:** mutar de a una las piezas nuevas, correr, verificar que la mutación mate el test que debería, y restaurar desde una copia en el scratchpad. Cuando una mutación sobrevive casi nunca es equivalente: es un test que pasa por casualidad, y hay que construir el caso donde el criterio correcto y el proxy más simple se separan. Los tests que salgan de esto se anotan en `Desviaciones encontradas al ejecutar`, al final de este plan.

---

## Mapa de archivos

### Repo `~/code/servicio-fooevents` (rama nueva `feat/categorias-de-producto`)

| Archivo | Responsabilidad |
|---|---|
| `app/Repositories/FooEventsRepository.php` | Suma una cuarta consulta: los slugs de `product_cat` por producto |
| `app/Services/FuncionesDelDia.php` | Estampa `categorias` en cada función, programada o huérfana |
| `app/Http/Controllers/FuncionesController.php` | Junta los IDs de los dos orígenes y pasa el mapa al agregador |
| `tests/Fixtures/respuesta-ejemplo.json` | La respuesta canónica, ahora con `categorias` |
| `tests/Unit/FuncionesDelDiaTest.php` | Que la estampa llegue a los dos tipos de función |
| `tests/Feature/FooEventsRepositoryTest.php` | Que la consulta nueva devuelva slugs (se saltea sin base) |
| `tests/Feature/FuncionesEndpointTest.php` | Que el campo salga en la respuesta. **9 bloques de mock a actualizar** |
| `tests/Feature/RespuestaCanonicaTest.php` | Comparación contra la fixture |

### Repo `laravel-crm`, worktree `/home/vallory/code/crm/ticket-sales` (rama `feat/ticket-sales-dashboard`)

| Archivo | Responsabilidad |
|---|---|
| `.../Database/Migrations/2026_08_26_120000_add_categorias_to_muci_ticket_sales_snapshot.php` | La columna JSON nullable |
| `.../Database/Migrations/2026_08_26_120100_seed_ticket_sales_san_cosmos_config.php` | Siembra la fila de `core_config` |
| `.../Models/TicketSalesSnapshot.php` | `categorias` en `$fillable` y casteada a `array` |
| `.../Support/CriterioDeSanCosmos.php` | **Nuevo.** Lee `core_config`, normaliza, tolera basura |
| `.../Support/ProgramacionDePantalla.php` | Reparte por categoría; la cascada ordena solo la derecha |
| `.../Services/FooEventsServiceClient.php` | `categorias` opcional, y malformada no descarta el día |
| `.../Console/SyncTicketSalesCommand.php` | Escribe la columna nueva |
| `.../Http/Controllers/TicketSalesController.php` | `pantalla()` lee el criterio; suma `configure`/`storeConfiguration` |
| `.../Http/routes.php` | Las dos rutas de configuración |
| `.../Resources/views/pantalla.blade.php` | Los dos paneles, sin nombres a la izquierda |
| `.../Resources/views/configure.blade.php` | **Nuevo.** La página de configuración |
| `.../Resources/views/index.blade.php` | Un enlace a la configuración. **La tabla no cambia** |
| `tests/Fixtures/fooevents/respuesta-ejemplo.json` | Igual que la del servicio |
| `tests/Unit/TicketSales/ProgramacionDePantallaTest.php` | Reescritura parcial: ver Task 9 |
| `tests/Feature/TicketSales/CriterioDeSanCosmosTest.php` | **Nuevo** |
| `tests/Feature/TicketSales/TicketSalesConfigureTest.php` | **Nuevo** |
| `tests/Feature/TicketSales/{FooEventsServiceClient,SyncTicketSalesCommand,TicketSalesPantalla,TicketSalesSnapshot}Test.php` | Casos nuevos |

### Nombres que cruzan tasks

Definidos una vez, usados tal cual en todas:

```php
// Servicio
FooEventsRepository::categoriasPorProducto(array $productoIds): array   // [int => string[]]
FuncionesDelDia::armar(array $programacion, array $tickets, array $lineas, array $categoriasPorProducto = []): array

// CRM
CriterioDeSanCosmos::__construct(array $categorias, string $titulo)
CriterioDeSanCosmos::desdeConfig(): self
CriterioDeSanCosmos::categorias(): array   // slugs YA normalizados (trim + minúsculas)
CriterioDeSanCosmos::titulo(): string
CriterioDeSanCosmos::CLAVE = 'krayin_ticket_sales.settings.san_cosmos'
CriterioDeSanCosmos::TITULO_POR_DEFECTO = 'San Cosmos'
ProgramacionDePantalla::armar(iterable $funciones, array $categoriasSanCosmos): array
// => ['sanCosmos' => ['funciones' => [['hora' => ?string, 'entradas' => int], …]],
//     'especiales' => [['producto_id' => int, 'show' => string, 'entradas' => int, 'funciones' => [...]], …]]
```

Variables que el controlador manda a `pantalla.blade.php`: `$sanCosmos`, `$especiales`, `$rotuloSanCosmos`.

---
## Parte A — El servicio (`~/code/servicio-fooevents`)

Antes de la Task 1:

```bash
cd ~/code/servicio-fooevents && git checkout -b feat/categorias-de-producto
```

---

### Task 1: El agregador estampa `categorias`

**Files:**
- Modify: `app/Services/FuncionesDelDia.php:21` (firma) y los dos armados de función (`:27-37` programada, `:48-59` huérfana)
- Test: `tests/Unit/FuncionesDelDiaTest.php`

**Interfaces:**
- Consumes: nada de tasks anteriores.
- Produces: `FuncionesDelDia::armar(array $programacion, array $tickets, array $lineas, array $categoriasPorProducto = []): array`. El cuarto parámetro es `[producto_id => string[]]`. Cada función de la salida gana la clave `categorias` (`string[]`), **inmediatamente después de `hora`**.

**El cuarto parámetro tiene default `[]` a propósito:** hay 12 llamadas a `armar()` en el test unitario y ninguna tiene por qué cambiar. Sin el default, esta task arrastraría 12 ediciones mecánicas que no prueban nada.

**La posición de la clave no es cosmética.** `RespuestaCanonicaTest` compara con `expect($actual)->toBe($esperado)`, que en PHP es `===`, y `===` entre arrays **exige el mismo orden de claves**. Si `categorias` sale en otro lugar que en la fixture, ese test falla con un diff ilegible.

- [ ] **Step 1: Escribir los tests que fallan**

En `tests/Unit/FuncionesDelDiaTest.php`, al final del archivo:

```php
test('una función programada lleva las categorías de su producto', function () {
    $r = FuncionesDelDia::armar([prog(1, 'A', 20)], [], [], [1 => ['ticketera-2-0', 'experiencias']]);

    expect($r['funciones'][0]['categorias'])->toBe(['ticketera-2-0', 'experiencias']);
});

test('una función huérfana también lleva las categorías de su producto', function () {
    // El caso del slot renombrado en WordPress: la venta existe y la
    // programación ya no. Es justo el que más importa: sin categorías, una
    // función huérfana de San Cosmos caería al panel derecho, y como las
    // huérfanas vienen con `show` vacío, en la TV eso es una tarjeta en blanco.
    $r = FuncionesDelDia::armar(
        [],
        [tick(1, 'A', 10)],
        ['10:1' => ['neto' => 5000, 'bruto' => 5500]],
        [1 => ['ticketera-2-0']]
    );

    expect($r['funciones'][0]['show'])->toBe('');
    expect($r['funciones'][0]['categorias'])->toBe(['ticketera-2-0']);
});

test('un producto que no está en el mapa queda con lista vacía, no sin la clave', function () {
    // Lista vacía y clave ausente se ven igual desde `?? []`, pero el CRM
    // valida la forma de lo que llega: la clave tiene que estar siempre.
    $r = FuncionesDelDia::armar([prog(9, 'A', 20)], [], [], [1 => ['ticketera-2-0']]);

    expect($r['funciones'][0])->toHaveKey('categorias');
    expect($r['funciones'][0]['categorias'])->toBe([]);
});

test('sin el cuarto parámetro todas las funciones quedan con lista vacía', function () {
    $r = FuncionesDelDia::armar([prog(1, 'A', 20)], [], []);

    expect($r['funciones'][0]['categorias'])->toBe([]);
});
```

- [ ] **Step 2: Correr los tests y verificar que fallan**

Run: `cd ~/code/servicio-fooevents && php artisan test tests/Unit/FuncionesDelDiaTest.php`
Expected: 4 FAIL con «Undefined array key "categorias"». Los 12 tests viejos del archivo siguen en verde.

- [ ] **Step 3: Cambiar la firma y estampar el campo**

En `app/Services/FuncionesDelDia.php`, la firma:

```php
    public static function armar(
        array $programacion,
        array $tickets,
        array $lineas,
        array $categoriasPorProducto = []
    ): array {
```

En el armado de la función **programada**, entre `'hora'` y `'entradas_vendidas'`:

```php
                'hora'                 => $p['hora'],
                'categorias'           => $categoriasPorProducto[$p['producto_id']] ?? [],
                'entradas_vendidas'    => 0,
```

En el armado de la función **huérfana**, en el mismo lugar:

```php
                    'hora'                 => $t['hora'],
                    'categorias'           => $categoriasPorProducto[$t['producto_id']] ?? [],
                    'entradas_vendidas'    => 0,
```

- [ ] **Step 4: Correr los tests y verificar que pasan**

Run: `cd ~/code/servicio-fooevents && php artisan test tests/Unit/FuncionesDelDiaTest.php`
Expected: PASS, 16 tests.

- [ ] **Step 5: Mutar y verificar**

Copiar el archivo al scratchpad. Mutar de a una y confirmar que cada mutación mata el test que le corresponde:

| Mutación | Test que tiene que morir |
|---|---|
| Estampar solo en la función programada (borrar la línea de la huérfana) | «una función huérfana también lleva las categorías» |
| `?? []` → `?? null` | «un producto que no está en el mapa queda con lista vacía» |
| Mover `categorias` al final del array | ninguno de esta task: **es la señal de que falta cubrir el orden**, y lo cubre `RespuestaCanonicaTest` en la Task 3. Anotarlo y seguir |

Restaurar desde la copia.

- [ ] **Step 6: Commit**

```bash
cd ~/code/servicio-fooevents
git add app/Services/FuncionesDelDia.php tests/Unit/FuncionesDelDiaTest.php
git commit -m "feat: categorias por funcion en el agregador

Las huerfanas tambien las llevan: son las del slot renombrado, y sin
categorias un show del domo caeria al panel derecho con el nombre vacio."
```

---

### Task 2: La consulta de categorías, y el slug real

**Files:**
- Modify: `app/Repositories/FooEventsRepository.php` (método nuevo al final, antes del `}` de la clase)
- Test: `tests/Feature/FooEventsRepositoryTest.php`

**Interfaces:**
- Consumes: nada.
- Produces: `FooEventsRepository::categoriasPorProducto(array $productoIds): array`, que devuelve `[producto_id => string[]]`. Los productos sin categorías **no aparecen** en el mapa; quien consume usa `?? []` (Task 1).

**Los tests de este archivo se auto-saltean sin la base `muci`.** Es la convención del repo y está bien: la credencial vive solo en el servidor.

- [ ] **Step 1: Averiguar el slug real de la categoría**

**Este paso no es opcional y bloquea la Task 5.** El spec asume que el slug es `ticketera-2-0`, pero eso es una conjetura: en WordPress el slug lo genera el nombre al crear el término y **no cambia** cuando lo renombran, así que la categoría que hoy se llama «Ticketera 2.0» puede tener el slug de «Entrada San Cosmos».

**Pedir permiso antes de conectarse al servidor.** Usuario `anthropic_readonly` por SSH y MySQL de solo lectura; nunca `root`.

```sql
SELECT t.term_id, t.name, t.slug, tt.count
FROM wpzv_terms t
JOIN wpzv_term_taxonomy tt ON tt.term_id = t.term_id AND tt.taxonomy = 'product_cat'
WHERE t.name LIKE '%icketera%' OR t.name LIKE '%osmos%' OR t.slug LIKE '%osmos%';
```

Anotar los slugs que devuelva en `Desviaciones encontradas al ejecutar`. **Los valores de la Task 5 salen de acá, no del spec.**

- [ ] **Step 2: Escribir los tests que fallan**

Al final de `tests/Feature/FooEventsRepositoryTest.php`:

```php
test('trae los slugs de product_cat de los productos pedidos', function () {
    $ids = array_slice(array_column($this->repo->productosConBookings(), 'producto_id'), 0, 5);

    $categorias = $this->repo->categoriasPorProducto($ids);

    expect($categorias)->not->toBeEmpty();

    foreach ($categorias as $productoId => $slugs) {
        expect($ids)->toContain($productoId);
        expect($slugs)->not->toBeEmpty();

        foreach ($slugs as $slug) {
            expect($slug)->toBeString()->not->toBe('');
        }
    }
});

test('sin ids no consulta y devuelve vacío', function () {
    // Sin este corte, el IN () queda sin marcadores y el SQL es inválido.
    expect($this->repo->categoriasPorProducto([]))->toBe([]);
});

test('no devuelve productos que no se pidieron', function () {
    $todos = array_column($this->repo->productosConBookings(), 'producto_id');
    $uno   = [$todos[0]];

    expect(array_keys($this->repo->categoriasPorProducto($uno)))->toBe($uno)
        ->or->toBe([]);
});
```

- [ ] **Step 3: Correr y verificar que fallan**

Run: `cd ~/code/servicio-fooevents && php artisan test tests/Feature/FooEventsRepositoryTest.php`
Expected, en el servidor: 3 FAIL con «Call to undefined method». **Fuera del servidor los 3 aparecen como SKIPPED, y eso no valida nada** — este paso hay que correrlo donde la base `muci` esté viva.

- [ ] **Step 4: Escribir la consulta**

En `app/Repositories/FooEventsRepository.php`, método nuevo:

```php
    /**
     * Los slugs de `product_cat` por producto.
     *
     * Slugs y no nombres porque renombrar un término en WordPress no le cambia
     * el slug, y este dato alimenta un criterio del CRM que tiene que sobrevivir
     * un renombre — que ya pasó una vez, de «Entrada San Cosmos» a «Ticketera 2.0».
     *
     * El `ORDER BY` no es adorno: la respuesta del endpoint se compara entera
     * contra una fixture canónica, y sin orden estable esa comparación falla
     * según lo que devuelva MySQL ese día.
     *
     * Un producto sin categorías no aparece en el mapa. Quien consume usa `?? []`.
     *
     * @param  array<int, int> $productoIds
     * @return array<int, array<int, string>>
     */
    public function categoriasPorProducto(array $productoIds): array
    {
        if ($productoIds === []) {
            return [];
        }

        $ids        = array_values(array_unique(array_map('intval', $productoIds)));
        $marcadores = implode(',', array_fill(0, count($ids), '?'));

        $filas = $this->db()->select(<<<SQL
            SELECT tr.object_id AS producto_id,
                   t.slug AS slug
            FROM wpzv_term_relationships tr
            JOIN wpzv_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 AND tt.taxonomy = 'product_cat'
            JOIN wpzv_terms t ON t.term_id = tt.term_id
            WHERE tr.object_id IN ({$marcadores})
            ORDER BY tr.object_id, t.slug
        SQL, $ids);

        $resultado = [];

        foreach ($filas as $f) {
            $resultado[(int) $f->producto_id][] = (string) $f->slug;
        }

        return $resultado;
    }
```

- [ ] **Step 5: Correr y verificar que pasan**

Run, **en el servidor**: `cd ~/code/servicio-fooevents && /usr/bin/php8.4 artisan test tests/Feature/FooEventsRepositoryTest.php`
Expected: PASS.

- [ ] **Step 6: Mutar y verificar**

| Mutación | Test que tiene que morir |
|---|---|
| Sacar el corte de `$productoIds === []` | «sin ids no consulta y devuelve vacío» (con un error de SQL) |
| `AND tt.taxonomy = 'product_cat'` → sin el filtro | ninguno de estos: **anotarlo**. Trae `product_tag` y `product_shipping_class` como si fueran categorías, y el test solo mira que sean strings. Construir el caso: pedir un producto conocido y afirmar que un slug de tag conocido **no** está |
| `t.slug` → `t.name` | ninguno automáticamente: los nombres también son strings. **Anotarlo y cubrirlo**: afirmar que ningún slug devuelto tiene espacios ni mayúsculas |

Las dos mutaciones que sobreviven son la clase de agujero que la disciplina existe para encontrar. Los tests que salgan van a `Desviaciones encontradas al ejecutar`.

- [ ] **Step 7: Commit**

```bash
cd ~/code/servicio-fooevents
git add app/Repositories/FooEventsRepository.php tests/Feature/FooEventsRepositoryTest.php
git commit -m "feat: consulta de product_cat por producto

Slugs y no nombres: renombrar un termino en WordPress no le cambia el
slug, y el criterio del CRM tiene que sobrevivir un renombre."
```

---

### Task 3: El endpoint devuelve `categorias`

**Files:**
- Modify: `app/Http/Controllers/FuncionesController.php:32-38`
- Modify: `tests/Fixtures/respuesta-ejemplo.json`
- Modify: `tests/Feature/FuncionesEndpointTest.php` (**9 bloques de mock**, ver Step 1)
- Modify: `tests/Feature/RespuestaCanonicaTest.php`

**Interfaces:**
- Consumes: `FuncionesDelDia::armar(...)` con cuarto parámetro (Task 1) y `FooEventsRepository::categoriasPorProducto()` (Task 2).
- Produces: el contrato público `/v1/funciones`, con `categorias` en cada función. Lo consume el CRM en la Parte B.

- [ ] **Step 1: Agregar el método nuevo a todos los mocks del repositorio**

Los mocks de Mockery son estrictos: llamar un método que el mock no declara **lanza**, así que sin esto todos los tests de endpoint se caen. Hay que agregar

```php
        $m->shouldReceive('categoriasPorProducto')->andReturn([]);
```

a los **9** bloques `mock(\App\Repositories\FooEventsRepository::class, ...)`, identificados por el test que los contiene:

En `tests/Feature/FuncionesEndpointTest.php`:
1. el helper `repoVacio()`
2. `'503 si la base muci no responde'`
3. `'un producto con JSON ilegible genera aviso y no rompe el día'`
4. `'una fecha ilegible genera aviso y conserva el resto del slot'`
5. `'la meta vacía no genera aviso'`
6. `'devuelve solo las funciones de la fecha pedida, no todas las del producto'`
7. `'un JSON válido sin ninguna función se saltea sin avisar'`
8. `'un producto cuya única fecha es ilegible avisa una sola vez, no dos'`

En `tests/Feature/RespuestaCanonicaTest.php`:
9. `'la respuesta canónica del fixture coincide con la que produce el servicio'`, pero **este devuelve datos de verdad**, no `[]`:

```php
        $m->shouldReceive('categoriasPorProducto')->andReturn([192637 => ['entrada-bioestanque']]);
```

- [ ] **Step 2: Actualizar la fixture canónica en los DOS repos**

`categorias` va **inmediatamente después de `hora`**, por lo del orden de claves (Task 1).

En `~/code/servicio-fooevents/tests/Fixtures/respuesta-ejemplo.json` y en
`/home/vallory/code/crm/ticket-sales/tests/Fixtures/fooevents/respuesta-ejemplo.json`,
dentro de la única función:

```json
      "hora": "17:00",
      "categorias": ["entrada-bioestanque"],
      "entradas_vendidas": 2,
```

Verificar que siguen siendo idénticas:

```bash
diff ~/code/servicio-fooevents/tests/Fixtures/respuesta-ejemplo.json \
     /home/vallory/code/crm/ticket-sales/tests/Fixtures/fooevents/respuesta-ejemplo.json \
  && echo IDENTICAS
```

- [ ] **Step 3: Escribir los tests de endpoint que fallan**

Al final de `tests/Feature/FuncionesEndpointTest.php`:

```php
test('cada función sale con sus categorías', function () {
    $this->mock(\App\Repositories\FooEventsRepository::class, function ($m) {
        $m->shouldReceive('productosConBookings')->andReturn([
            ['producto_id' => 777, 'show' => 'Marte', 'bookings_json' => json_encode([
                'k' => ['label' => 'Domo', 'formatted_time' => '(15:30)',
                        'add_date' => ['d' => ['date' => 'agosto 7, 2026', 'stock' => '40']]],
            ])],
        ]);
        $m->shouldReceive('ticketsDeLaFecha')->andReturn([]);
        $m->shouldReceive('lineasDe')->andReturn([]);
        $m->shouldReceive('categoriasPorProducto')->andReturn([777 => ['ticketera-2-0']]);
    });

    pedir()->assertOk()->assertJsonPath('funciones.0.categorias', ['ticketera-2-0']);
});

test('un producto sin categorías sale con lista vacía, no sin la clave', function () {
    $this->mock(\App\Repositories\FooEventsRepository::class, function ($m) {
        $m->shouldReceive('productosConBookings')->andReturn([
            ['producto_id' => 777, 'show' => 'Marte', 'bookings_json' => json_encode([
                'k' => ['label' => 'Domo', 'formatted_time' => '(15:30)',
                        'add_date' => ['d' => ['date' => 'agosto 7, 2026', 'stock' => '40']]],
            ])],
        ]);
        $m->shouldReceive('ticketsDeLaFecha')->andReturn([]);
        $m->shouldReceive('lineasDe')->andReturn([]);
        $m->shouldReceive('categoriasPorProducto')->andReturn([]);
    });

    pedir()->assertOk()->assertJsonPath('funciones.0.categorias', []);
});

test('pide las categorías de los productos con ventas, no solo de los programados', function () {
    // El producto 888 vendió y no está en la programación. Si el controlador
    // junta los IDs solo de la programación, esta función queda sin categorías
    // y un show del domo se va al panel equivocado.
    $this->mock(\App\Repositories\FooEventsRepository::class, function ($m) {
        $m->shouldReceive('productosConBookings')->andReturn([]);
        $m->shouldReceive('ticketsDeLaFecha')->andReturn([
            ['producto_id' => 888, 'pedido_id' => 5, 'slot' => 'viejo',
             'hora' => '16:30', 'estado' => 'wc-completed'],
        ]);
        $m->shouldReceive('lineasDe')->andReturn(['5:888' => ['neto' => 100, 'bruto' => 110]]);
        $m->shouldReceive('categoriasPorProducto')
            ->with([888])
            ->andReturn([888 => ['ticketera-2-0']]);
    });

    pedir()->assertOk()->assertJsonPath('funciones.0.categorias', ['ticketera-2-0']);
});
```

- [ ] **Step 4: Correr y verificar que fallan**

Run: `cd ~/code/servicio-fooevents && php artisan test`
Expected: los 3 nuevos FAIL, y `RespuestaCanonicaTest` también FAIL (la fixture ya tiene `categorias` y el servicio todavía no lo emite). El resto en verde.

- [ ] **Step 5: Juntar los IDs de los dos orígenes**

En `app/Http/Controllers/FuncionesController.php`, reemplazar el bloque `:32-38`:

```php
            $tickets = $repo->ticketsDeLaFecha($fecha);
            $pares   = array_values(array_unique(array_map(
                fn ($t) => $t['pedido_id'] . ':' . $t['producto_id'],
                array_filter($tickets, fn ($t) => $t['estado'] === 'wc-completed')
            )));

            // Los IDs salen de los DOS orígenes. Un producto puede tener ventas
            // sin figurar en la programación —el slot renombrado en WordPress—, y
            // esa función necesita categorías igual que las demás: es la que el
            // CRM tiene que poder mandar al panel del domo, porque viene sin
            // nombre y a la derecha se vería como una tarjeta en blanco.
            $productoIds = array_values(array_unique(array_merge(
                array_column($programacion, 'producto_id'),
                array_column($tickets, 'producto_id')
            )));

            $armado = FuncionesDelDia::armar(
                $programacion,
                $tickets,
                $repo->lineasDe($pares),
                $repo->categoriasPorProducto($productoIds)
            );
```

- [ ] **Step 6: Correr toda la suite y verificar que pasa**

Run: `cd ~/code/servicio-fooevents && php artisan test`
Expected: PASS. Los del repositorio, SKIPPED fuera del servidor.

- [ ] **Step 7: Mutar y verificar**

| Mutación | Test que tiene que morir |
|---|---|
| Juntar los IDs solo de `$programacion` | «pide las categorías de los productos con ventas, no solo de los programados» |
| Juntar los IDs solo de `$tickets` | «cada función sale con sus categorías» |
| Mover `categorias` al final del array en `FuncionesDelDia` | `RespuestaCanonicaTest` — es la deuda que quedó anotada en la Task 1 |

- [ ] **Step 8: Commit**

```bash
cd ~/code/servicio-fooevents
git add app/Http/Controllers/FuncionesController.php tests/
git commit -m "feat: categorias en el contrato de /v1/funciones

Los ids salen de la programacion y de los tickets: un producto con
ventas y sin programacion necesita categorias igual que los demas."
```

El commit de la fixture del CRM va con la Parte B, en el repo del CRM.

---
## Parte B — El CRM (`/home/vallory/code/crm/ticket-sales`, rama `feat/ticket-sales-dashboard`)

Dos cosas verificadas en el core de Krayin que condicionan las tasks que siguen:

- `core_config` es `id, code, value, timestamps`, y **`value` es un `string` (VARCHAR 255)**, no un `text`. El JSON del criterio entra holgado hoy (~75 caracteres con las dos categorías), pero **una selección de más de ~12 slugs lo desborda**. La página de configuración valida el largo del JSON codificado antes de guardar (Task 13); MySQL en modo no estricto truncaría en silencio, y un criterio truncado es un panel que reparte mal sin avisar.
- `core()->getConfigData($code)` devuelve **el `value` crudo, siempre string**, y `null` si la fila no existe. El `json_decode` no es opcional.

---

### Task 4: La columna `categorias` en el snapshot

**Files:**
- Create: `packages/CarlVallory/KrayinTicketSales/src/Database/Migrations/2026_08_26_120000_add_categorias_to_muci_ticket_sales_snapshot.php`
- Modify: `packages/CarlVallory/KrayinTicketSales/src/Models/TicketSalesSnapshot.php`
- Test: `tests/Feature/TicketSales/TicketSalesSnapshotTest.php`

**Interfaces:**
- Consumes: nada.
- Produces: `TicketSalesSnapshot->categorias`, de tipo `array|null`. `null` significa «no sé» (servicio viejo o campo malformado); `[]` significa «el producto no tiene categorías». Los dos caen al panel derecho, pero **no son lo mismo** y los tests los distinguen.

- [ ] **Step 1: Escribir los tests que fallan**

Al final de `tests/Feature/TicketSales/TicketSalesSnapshotTest.php` (confirmar que el archivo ya tiene `uses(DatabaseTransactions::class);` arriba; si no, agregarlo):

```php
test('categorias se guarda como lista y vuelve como array', function () {
    $fila = TicketSalesSnapshot::create([
        'fecha'       => '2026-08-07',
        'producto_id' => 1,
        'show_nombre' => 'Marte',
        'slot'        => 'Domo (15:30)',
        'hora'        => '15:30',
        'categorias'  => ['ticketera-2-0', 'experiencias'],
    ]);

    expect($fila->fresh()->categorias)->toBe(['ticketera-2-0', 'experiencias']);
});

test('categorias en null y en lista vacía no son lo mismo', function () {
    // `null` es "no sé" —servicio viejo o campo malformado— y `[]` es "este
    // producto no tiene categorías". Los dos van al panel derecho, pero
    // confundirlos borra la única señal de que el servicio no está mandando
    // el campo.
    $sinDato = TicketSalesSnapshot::create([
        'fecha' => '2026-08-07', 'producto_id' => 1, 'show_nombre' => 'A',
        'slot'  => 'X', 'hora' => '10:00', 'categorias' => null,
    ]);

    $sinCategorias = TicketSalesSnapshot::create([
        'fecha' => '2026-08-07', 'producto_id' => 2, 'show_nombre' => 'B',
        'slot'  => 'Y', 'hora' => '11:00', 'categorias' => [],
    ]);

    expect($sinDato->fresh()->categorias)->toBeNull();
    expect($sinCategorias->fresh()->categorias)->toBe([]);
});
```

- [ ] **Step 2: Correr y verificar que fallan**

Run: `php artisan test tests/Feature/TicketSales/TicketSalesSnapshotTest.php`
Expected: FAIL con «Unknown column 'categorias'».

- [ ] **Step 3: Escribir la migración**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * Aditiva y nullable: entre el deploy del CRM y el del servicio, todas las
     * filas quedan en null y la pantalla se ve como antes del cambio. Ese
     * degradado es parte del diseño, no un accidente.
     *
     * Se guarda lo que vino, crudo. Derivar acá un booleano `es_san_cosmos`
     * congelaría el criterio en el snapshot, y arreglar el criterio dejaría mal
     * los días anteriores hasta re-sincronizar — con un selector que mira una
     * semana para atrás.
     */
    public function up(): void
    {
        Schema::table('muci_ticket_sales_snapshot', function (Blueprint $table) {
            $table->json('categorias')->nullable()->after('hora');
        });
    }

    public function down(): void
    {
        Schema::table('muci_ticket_sales_snapshot', function (Blueprint $table) {
            $table->dropColumn('categorias');
        });
    }
};
```

- [ ] **Step 4: Agregar el campo al modelo**

En `TicketSalesSnapshot.php`, en `$fillable` después de `'hora'`:

```php
        'hora',
        'categorias',
```

Y en `$casts`:

```php
        'categorias'           => 'array',
```

- [ ] **Step 5: Migrar y correr los tests**

```bash
php artisan migrate
php artisan test tests/Feature/TicketSales/TicketSalesSnapshotTest.php
```
Expected: PASS.

- [ ] **Step 6: Verificar que la migración es reversible**

```bash
php artisan migrate:rollback --step=1
php artisan migrate
```
Expected: las dos corren sin error. Es la regla de reversibilidad por desinstalación.

- [ ] **Step 7: Mutar y verificar**

| Mutación | Test que tiene que morir |
|---|---|
| Sacar `'categorias' => 'array'` de `$casts` | «categorias se guarda como lista y vuelve como array» (vuelve string JSON) |
| Sacar `'categorias'` de `$fillable` | los dos (queda null porque `create()` lo descarta) |
| `->nullable()` → sin nullable | «categorias en null y en lista vacía no son lo mismo» |

- [ ] **Step 8: Commit**

```bash
git add packages/CarlVallory/KrayinTicketSales/src/Database/Migrations packages/CarlVallory/KrayinTicketSales/src/Models tests/Feature/TicketSales/TicketSalesSnapshotTest.php
git commit -m "feat(ticket-sales): columna categorias en el snapshot

Nullable y cruda. null es \"no se\" y [] es \"sin categorias\": los dos van
al panel derecho, pero confundirlos borra la senal de que el servicio no
esta mandando el campo."
```

---

### Task 5: La siembra del criterio en `core_config`

**Files:**
- Create: `packages/CarlVallory/KrayinTicketSales/src/Database/Migrations/2026_08_26_120100_seed_ticket_sales_san_cosmos_config.php`
- Test: `tests/Feature/TicketSales/CriterioDeSanCosmosTest.php` (archivo nuevo; el resto de sus tests llegan en la Task 6)

**Interfaces:**
- Consumes: nada.
- Produces: la fila `core_config` con `code = 'krayin_ticket_sales.settings.san_cosmos'` y un `value` JSON `{"titulo": …, "categorias": [...]}`. La leen `CriterioDeSanCosmos` (Task 6) y la página de configuración (Task 13).

**Van dos migraciones separadas** —esta y la de la Task 4— y no una: la de esquema y la de dato se revierten por motivos distintos. Revertir la siembra del criterio no tiene por qué tirar la columna con las categorías ya sincronizadas.

**Los slugs salen de la Task 2, Step 1, no de este plan.** Si esa consulta devolvió otros, usar esos y anotarlo.

- [ ] **Step 1: Escribir el test que falla**

Archivo nuevo `tests/Feature/TicketSales/CriterioDeSanCosmosTest.php`:

```php
<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

test('la migración deja el criterio sembrado en core_config', function () {
    $fila = DB::table('core_config')
        ->where('code', 'krayin_ticket_sales.settings.san_cosmos')
        ->first();

    expect($fila)->not->toBeNull();

    $valor = json_decode($fila->value, true);

    expect($valor['titulo'])->toBe('San Cosmos');
    expect($valor['categorias'])->toContain('ticketera-2-0');
});
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `php artisan test tests/Feature/TicketSales/CriterioDeSanCosmosTest.php`
Expected: FAIL, «expect(null)->not->toBeNull()».

- [ ] **Step 3: Escribir la migración de siembra**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CLAVE = 'krayin_ticket_sales.settings.san_cosmos';

    /*
     * El criterio vive acá y no en `src/Config/ticket-sales.php` para que tenga
     * una sola fuente de verdad, y para que esa fuente sea la que la página de
     * configuración edita. Un default en el archivo sería una segunda fuente
     * para lo mismo, y la pregunta "¿cuál gana?" aparecería justo el día que
     * algo se vea raro.
     *
     * Se siembra en vez de arrancar vacío por el día del deploy: sin fila, el
     * panel izquierdo diría "hoy no hay funciones de San Cosmos" sobre un día
     * que sí las tenía, y desde la TV eso no se distingue de un día sin domo.
     */
    public function up(): void
    {
        // Si la fila ya existe, alguien la editó desde la UI: no se pisa.
        if (DB::table('core_config')->where('code', self::CLAVE)->exists()) {
            return;
        }

        DB::table('core_config')->insert([
            'code'       => self::CLAVE,
            'value'      => json_encode([
                'titulo'     => 'San Cosmos',
                'categorias' => ['ticketera-2-0', 'entrada-san-cosmos'],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('core_config')->where('code', self::CLAVE)->delete();
    }
};
```

- [ ] **Step 4: Migrar y correr el test**

```bash
php artisan migrate
php artisan test tests/Feature/TicketSales/CriterioDeSanCosmosTest.php
```
Expected: PASS.

- [ ] **Step 5: Verificar que no pisa lo editado**

```bash
php artisan tinker --execute="DB::table('core_config')->where('code','krayin_ticket_sales.settings.san_cosmos')->update(['value'=>json_encode(['titulo'=>'Domo','categorias'=>['otra']])]);"
php artisan migrate:rollback --step=1 && php artisan migrate
php artisan tinker --execute="echo DB::table('core_config')->where('code','krayin_ticket_sales.settings.san_cosmos')->value('value');"
```

Expected: después del rollback la fila **no existe**, y al volver a migrar aparece con los valores sembrados. El guard de `exists()` protege una re-corrida de la migración, no un rollback: eso es lo que tiene que quedar claro de esta verificación.

- [ ] **Step 6: Mutar y verificar**

| Mutación | Test que tiene que morir |
|---|---|
| `insert` con `titulo` en `''` | «la migración deja el criterio sembrado» |
| Quitar `entrada-san-cosmos` de la lista | ninguno: el test solo exige `ticketera-2-0`. **Está bien así** — el slug secundario es un respaldo por el renombre, no un requisito. Anotarlo y no agregar test |
| `down()` vacío | ninguno automáticamente. **Anotarlo**: lo cubre el Step 5 a mano |

- [ ] **Step 7: Commit**

```bash
git add packages/CarlVallory/KrayinTicketSales/src/Database/Migrations tests/Feature/TicketSales/CriterioDeSanCosmosTest.php
git commit -m "feat(ticket-sales): siembra del criterio de San Cosmos en core_config

Va en la base y no en el archivo de config para que el criterio tenga una
sola fuente de verdad, y sea la que la pagina de configuracion edita."
```

---

### Task 6: `CriterioDeSanCosmos`

**Files:**
- Create: `packages/CarlVallory/KrayinTicketSales/src/Support/CriterioDeSanCosmos.php`
- Test: `tests/Feature/TicketSales/CriterioDeSanCosmosTest.php`

**Interfaces:**
- Consumes: la fila sembrada en la Task 5.
- Produces:
  - `CriterioDeSanCosmos::CLAVE` = `'krayin_ticket_sales.settings.san_cosmos'`
  - `CriterioDeSanCosmos::TITULO_POR_DEFECTO` = `'San Cosmos'`
  - `new CriterioDeSanCosmos(array $categorias, string $titulo)` — value object puro
  - `CriterioDeSanCosmos::desdeConfig(): self` — lee `core_config`, tolera basura
  - `->categorias(): array` — slugs **ya normalizados** (trim + minúsculas, sin vacíos, sin duplicados)
  - `->titulo(): string`

**La normalización se reparte, no se duplica:** este objeto normaliza el lado del **criterio**, y `ProgramacionDePantalla` (Task 9) normaliza el lado de la **función**. Cada lado en un lugar.

- [ ] **Step 1: Escribir los tests que fallan**

Agregar a `tests/Feature/TicketSales/CriterioDeSanCosmosTest.php`:

```php
use CarlVallory\KrayinTicketSales\Support\CriterioDeSanCosmos;

function guardarCriterio(mixed $valor): void
{
    DB::table('core_config')->updateOrInsert(
        ['code' => CriterioDeSanCosmos::CLAVE],
        ['value' => is_string($valor) ? $valor : json_encode($valor), 'updated_at' => now()]
    );
}

test('lee las categorías y el título de core_config', function () {
    guardarCriterio(['titulo' => 'Domo MuCi', 'categorias' => ['ticketera-2-0']]);

    $criterio = CriterioDeSanCosmos::desdeConfig();

    expect($criterio->titulo())->toBe('Domo MuCi');
    expect($criterio->categorias())->toBe(['ticketera-2-0']);
});

test('normaliza los slugs guardados: recorta, baja a minúsculas y descarta vacíos', function () {
    // El campo libre de la página de configuración lo llena una persona.
    guardarCriterio(['titulo' => 'San Cosmos', 'categorias' => ['  Ticketera-2-0 ', '', 'ENTRADA-SAN-COSMOS', '   ']]);

    expect(CriterioDeSanCosmos::desdeConfig()->categorias())
        ->toBe(['ticketera-2-0', 'entrada-san-cosmos']);
});

test('no repite un slug cargado dos veces', function () {
    guardarCriterio(['titulo' => 'San Cosmos', 'categorias' => ['ticketera-2-0', 'Ticketera-2-0']]);

    expect(CriterioDeSanCosmos::desdeConfig()->categorias())->toBe(['ticketera-2-0']);
});

test('sin fila en core_config devuelve lista vacía y el título por defecto', function () {
    DB::table('core_config')->where('code', CriterioDeSanCosmos::CLAVE)->delete();

    $criterio = CriterioDeSanCosmos::desdeConfig();

    expect($criterio->categorias())->toBe([]);
    expect($criterio->titulo())->toBe('San Cosmos');
});

test('un valor con forma inesperada no lanza: devuelve vacío y el título por defecto', function () {
    // La consecuencia es acotada y visible: nada coincide, todo cae al panel
    // derecho con su nombre, y la página de configuración muestra la lista
    // vacía. Quien valida de verdad es el POST de esa página.
    foreach (['no-es-json', '"un string"', '123', '{"categorias": "ticketera-2-0"}', '[]'] as $basura) {
        guardarCriterio($basura);

        $criterio = CriterioDeSanCosmos::desdeConfig();

        expect($criterio->categorias())->toBe([]);
        expect($criterio->titulo())->toBe('San Cosmos');
    }
});

test('un título vacío o en blanco cae al por defecto', function () {
    // Un panel sin rótulo en una TV es peor que un rótulo genérico.
    guardarCriterio(['titulo' => '   ', 'categorias' => ['ticketera-2-0']]);

    expect(CriterioDeSanCosmos::desdeConfig()->titulo())->toBe('San Cosmos');
});

test('los elementos que no son strings se descartan sin tirar el resto', function () {
    guardarCriterio(['titulo' => 'San Cosmos', 'categorias' => ['ticketera-2-0', 128, null, ['anidada']]]);

    expect(CriterioDeSanCosmos::desdeConfig()->categorias())->toBe(['ticketera-2-0']);
});
```

- [ ] **Step 2: Correr y verificar que fallan**

Run: `php artisan test tests/Feature/TicketSales/CriterioDeSanCosmosTest.php`
Expected: FAIL, «Class "CarlVallory\KrayinTicketSales\Support\CriterioDeSanCosmos" not found».

- [ ] **Step 3: Escribir la clase**

```php
<?php

namespace CarlVallory\KrayinTicketSales\Support;

/**
 * Qué categorías cuentan como San Cosmos, y con qué rótulo se muestran.
 *
 * El criterio vive en `core_config` y se edita desde la página de configuración
 * del paquete. Acá está el único lugar que lo lee, porque el `json_decode` de
 * un `value` que el core devuelve siempre como string aparece en tres lugares
 * —la página, su POST y la pantalla— y en `FinancialReports` ese baile está
 * repetido tres veces. Copiar la repetición sería copiar el defecto.
 *
 * Un valor con forma inesperada NO lanza: devuelve lista vacía. La consecuencia
 * es acotada y visible —nada coincide, todo cae al panel derecho con su nombre—
 * y quien valida de verdad es el POST de la página, que es donde una persona
 * puede ver el error y corregirlo. Lanzar acá apagaría la TV del hall.
 */
class CriterioDeSanCosmos
{
    public const CLAVE = 'krayin_ticket_sales.settings.san_cosmos';

    public const TITULO_POR_DEFECTO = 'San Cosmos';

    public function __construct(
        private array $categorias,
        private string $titulo,
    ) {}

    public static function desdeConfig(): self
    {
        $crudo = core()->getConfigData(self::CLAVE);

        // `getConfigData` devuelve el `value` tal cual, y la columna es un
        // string: acá siempre hay que decodificar.
        $datos = is_string($crudo) ? json_decode($crudo, true) : $crudo;

        if (! is_array($datos)) {
            return new self([], self::TITULO_POR_DEFECTO);
        }

        return new self(
            self::normalizarLista($datos['categorias'] ?? null),
            self::normalizarTitulo($datos['titulo'] ?? null),
        );
    }

    /** @return array<int, string> Slugs normalizados: sin espacios, en minúsculas, sin repetidos. */
    public function categorias(): array
    {
        return $this->categorias;
    }

    public function titulo(): string
    {
        return $this->titulo;
    }

    /**
     * Normaliza el lado del criterio. El lado de la función lo normaliza
     * `ProgramacionDePantalla`, que es quien la tiene a mano.
     *
     * @return array<int, string>
     */
    private static function normalizarLista(mixed $lista): array
    {
        if (! is_array($lista)) {
            return [];
        }

        $limpias = [];

        foreach ($lista as $slug) {
            if (! is_string($slug)) {
                continue;
            }

            $slug = mb_strtolower(trim($slug));

            if ($slug !== '' && ! in_array($slug, $limpias, true)) {
                $limpias[] = $slug;
            }
        }

        return $limpias;
    }

    private static function normalizarTitulo(mixed $titulo): string
    {
        if (! is_string($titulo) || trim($titulo) === '') {
            return self::TITULO_POR_DEFECTO;
        }

        return trim($titulo);
    }
}
```

- [ ] **Step 4: Correr y verificar que pasan**

Run: `php artisan test tests/Feature/TicketSales/CriterioDeSanCosmosTest.php`
Expected: PASS, 8 tests.

- [ ] **Step 5: Mutar y verificar**

| Mutación | Test que tiene que morir |
|---|---|
| Sacar `mb_strtolower` | «normaliza los slugs guardados» y «no repite un slug cargado dos veces» |
| Sacar `trim` | «normaliza los slugs guardados» |
| Sacar el `in_array` de duplicados | «no repite un slug cargado dos veces» |
| `if (! is_array($datos))` → `if ($datos === null)` | «un valor con forma inesperada no lanza» (el caso `'"un string"'`) |
| `normalizarTitulo` sin el chequeo de `trim($titulo) === ''` | «un título vacío o en blanco cae al por defecto» |
| `continue` de no-strings → castear con `(string)` | «los elementos que no son strings se descartan» (`128` entraría como `'128'`) |

- [ ] **Step 6: Commit**

```bash
git add packages/CarlVallory/KrayinTicketSales/src/Support/CriterioDeSanCosmos.php tests/Feature/TicketSales/CriterioDeSanCosmosTest.php
git commit -m "feat(ticket-sales): CriterioDeSanCosmos lee y normaliza el criterio

Un valor con forma inesperada devuelve lista vacia en vez de lanzar: la
consecuencia es todo al panel derecho, y lanzar apagaria la TV del hall."
```

---
### Task 7: El cliente tolera `categorias`

**Files:**
- Modify: `packages/CarlVallory/KrayinTicketSales/src/Services/FooEventsServiceClient.php` (el `foreach` de funciones en `validar()`, `:139-155`, y un método privado nuevo)
- Modify: `tests/Fixtures/fooevents/respuesta-ejemplo.json` (ya editada en la Task 3, Step 2 — acá se commitea)
- Test: `tests/Feature/TicketSales/FooEventsServiceClientTest.php`

**Interfaces:**
- Consumes: el contrato de la Parte A.
- Produces: cada función que devuelve `funcionesDe()` trae la clave `categorias`, de tipo `array|null`, **siempre presente**. `null` = ausente o malformada; `[]` = sin categorías; lista de strings = tal cual. Lo consume el sync (Task 8).

`categorias` **no va en `CAMPOS`**. Si fuera obligatorio, un servicio viejo haría que el cliente descarte la respuesta entera y el tablero quedaría clavado con el dato de ayer disparando `OTRO_DIA` —el estado más caro del paquete— por un campo decorativo.

- [ ] **Step 1: Escribir los tests que fallan**

Al final de `tests/Feature/TicketSales/FooEventsServiceClientTest.php`:

```php
test('una respuesta sin categorias no se rechaza y deja el campo en null', function () {
    // Es el estado real entre el deploy del CRM y el del servicio. Rechazar
    // acá dispararía OTRO_DIA por un campo que no es una cifra de venta.
    $cuerpo = respuestaCanonica();
    unset($cuerpo['funciones'][0]['categorias']);

    Http::fake(['*' => Http::response($cuerpo, 200)]);

    $datos = $this->cliente->funcionesDe('2026-08-07');

    expect($datos['funciones'][0])->toHaveKey('categorias');
    expect($datos['funciones'][0]['categorias'])->toBeNull();
});

test('una lista de slugs pasa tal cual', function () {
    $cuerpo = respuestaCanonica();
    $cuerpo['funciones'][0]['categorias'] = ['ticketera-2-0', 'experiencias'];

    Http::fake(['*' => Http::response($cuerpo, 200)]);

    expect($this->cliente->funcionesDe('2026-08-07')['funciones'][0]['categorias'])
        ->toBe(['ticketera-2-0', 'experiencias']);
});

test('una lista vacía queda en lista vacía, no en null', function () {
    // "sin categorías" y "no sé" van los dos al panel derecho, pero
    // confundirlos borra la única señal de que el servicio no manda el campo.
    $cuerpo = respuestaCanonica();
    $cuerpo['funciones'][0]['categorias'] = [];

    Http::fake(['*' => Http::response($cuerpo, 200)]);

    expect($this->cliente->funcionesDe('2026-08-07')['funciones'][0]['categorias'])->toBe([]);
});

test('una lista de objetos no descarta el día: queda null y se loguea', function () {
    // El caso realista: alguien enriquece el campo del lado del servicio para
    // llevar también el nombre legible. Es aditivo y razonable allá.
    Log::spy();

    $cuerpo = respuestaCanonica();
    $cuerpo['funciones'][0]['categorias'] = [['slug' => 'ticketera-2-0', 'name' => 'Ticketera 2.0']];

    Http::fake(['*' => Http::response($cuerpo, 200)]);

    $datos = $this->cliente->funcionesDe('2026-08-07');

    expect($datos['funciones'][0]['categorias'])->toBeNull();
    expect($datos['funciones'][0]['entradas_vendidas'])->toBe(2);

    Log::shouldHaveReceived('warning')->once();
});

test('las otras formas malformadas también quedan en null sin descartar el día', function () {
    foreach ([
        'ticketera-2-0',                      // string suelto: un implode que se cuela
        [128, 129],                           // term_ids en vez de slugs
        ['slug' => 'ticketera-2-0'],          // mapa, no lista
        ['ticketera-2-0', 128],               // mezcla
    ] as $forma) {
        $cuerpo = respuestaCanonica();
        $cuerpo['funciones'][0]['categorias'] = $forma;

        Http::fake(['*' => Http::response($cuerpo, 200)]);

        $datos = $this->cliente->funcionesDe('2026-08-07');

        expect($datos['funciones'][0]['categorias'])->toBeNull();
        expect($datos['funciones'][0]['recaudacion_bruta'])->toBe(70000);
    }
});
```

Agregar `use Illuminate\Support\Facades\Log;` arriba del archivo si todavía no está.

- [ ] **Step 2: Correr y verificar que fallan**

Run: `php artisan test tests/Feature/TicketSales/FooEventsServiceClientTest.php`
Expected: los 5 nuevos FAIL. **Y también falla «200 devuelve la respuesta canónica ya validada»** si la fixture del CRM no quedó actualizada en la Task 3 — verificar el `diff` de las dos fixtures antes de seguir.

- [ ] **Step 3: Normalizar el campo en `validar()`**

Al final del `foreach ($cuerpo['funciones'] as $i => $funcion)`, después del `foreach (self::CAMPOS ...)`:

```php
            // `categorias` es opcional a propósito: ver el método de abajo.
            $cuerpo['funciones'][$i]['categorias'] = $this->categoriasDe(
                $funcion['categorias'] ?? null,
                $i,
                $fecha
            );
```

Y el método privado nuevo, después de `validar()`:

```php
    /**
     * `categorias` NO está en CAMPOS, y eso es deliberado.
     *
     * Si fuera obligatorio, un servicio viejo —o el orden de deploy al revés—
     * haría que el cliente descarte la respuesta entera, y el tablero quedaría
     * clavado con el dato de ayer disparando OTRO_DIA: el estado más caro del
     * paquete, por un campo decorativo. Es la misma lógica aditiva de los
     * `avisos`, donde un `tipo` nuevo ya rompió una vez.
     *
     * Una forma inesperada tampoco descarta el día. Se aparta de la regla "o
     * datos validados o lanza" porque el costo es asimétrico: rechazar apaga la
     * TV entera por un detalle de categorización, y aceptar solo hace que un
     * producto salga a la derecha con su nombre. La regla existe para proteger
     * las cifras de venta, y este campo no es una cifra de venta.
     *
     * Devuelve `null` para "no sé" y `[]` para "sin categorías": los dos van al
     * panel derecho, pero confundirlos borra la señal de que el servicio no
     * está mandando el campo.
     */
    private function categoriasDe(mixed $valor, int $i, string $fecha): ?array
    {
        if ($valor === null) {
            return null;
        }

        if (is_array($valor) && array_is_list($valor)) {
            $todosStrings = true;

            foreach ($valor as $slug) {
                if (! is_string($slug)) {
                    $todosStrings = false;

                    break;
                }
            }

            if ($todosStrings) {
                return $valor;
            }
        }

        Log::warning('ticket-sales: el servicio mandó `categorias` con una forma inesperada', [
            'fecha'    => $fecha,
            'funcion'  => $i,
            'recibido' => $valor,
        ]);

        return null;
    }
```

Agregar `use Illuminate\Support\Facades\Log;` a los `use` del archivo.

- [ ] **Step 4: Correr y verificar que pasan**

Run: `php artisan test tests/Feature/TicketSales/FooEventsServiceClientTest.php`
Expected: PASS, 21 tests.

- [ ] **Step 5: Mutar y verificar**

| Mutación | Test que tiene que morir |
|---|---|
| Agregar `'categorias' => true` a `CAMPOS` | «una respuesta sin categorias no se rechaza» |
| `return null` del caso malformado → `throw ErrorDelServicio::respuestaInvalida(...)` | «una lista de objetos no descarta el día» y «las otras formas malformadas» |
| Sacar el `array_is_list` | «las otras formas malformadas» (el caso del mapa) |
| Sacar el chequeo de `is_string` por elemento | «las otras formas malformadas» (el caso `[128, 129]`) |
| `if ($valor === null)` → `if (empty($valor))` | «una lista vacía queda en lista vacía, no en null» |
| Sacar el `Log::warning` | «una lista de objetos no descarta el día» |

- [ ] **Step 6: Commit**

```bash
git add packages/CarlVallory/KrayinTicketSales/src/Services/FooEventsServiceClient.php tests/Feature/TicketSales/FooEventsServiceClientTest.php tests/Fixtures/fooevents/respuesta-ejemplo.json
git commit -m "feat(ticket-sales): categorias opcional en el cliente del servicio

Fuera de CAMPOS: obligatorio haria que un servicio viejo dispare
OTRO_DIA por un campo decorativo. Malformado deja null y guarda las
ventas: rechazar apagaria la TV por un detalle de categorizacion."
```

---

### Task 8: El sync escribe la columna

**Files:**
- Modify: `packages/CarlVallory/KrayinTicketSales/src/Console/SyncTicketSalesCommand.php:67-79`
- Test: `tests/Feature/TicketSales/SyncTicketSalesCommandTest.php`

**Interfaces:**
- Consumes: la salida de `FooEventsServiceClient::funcionesDe()` (Task 7), donde `categorias` **siempre está presente**.
- Produces: filas de `muci_ticket_sales_snapshot` con la columna poblada. La lee `pantalla()` (Task 11).

**El helper `unaFuncion()` del test NO lleva `categorias`, y así se queda.** Su default es «campo ausente», que es el estado real entre los dos deploys; los tests que necesitan categorías las pasan por `$sobre`. Agregarlas al helper haría que ningún test cubriera el caso realista.

- [ ] **Step 1: Escribir los tests que fallan**

Al final de `tests/Feature/TicketSales/SyncTicketSalesCommandTest.php`:

```php
test('las categorías de cada función aterrizan en su columna', function () {
    Http::fake(['*' => Http::response(cuerpoDelServicio([
        unaFuncion(['categorias' => ['ticketera-2-0', 'experiencias']]),
    ]), 200)]);

    $this->artisan('ticket-sales:sync --fecha=2026-08-07')->assertSuccessful();

    expect(TicketSalesSnapshot::where('fecha', '2026-08-07')->first()->categorias)
        ->toBe(['ticketera-2-0', 'experiencias']);
});

test('una función sin el campo queda con la columna en null', function () {
    // El servicio viejo. El sync no tiene que inventar una lista vacía: null
    // es "no sé" y es la verdad de ese momento.
    Http::fake(['*' => Http::response(cuerpoDelServicio([unaFuncion()]), 200)]);

    $this->artisan('ticket-sales:sync --fecha=2026-08-07')->assertSuccessful();

    expect(TicketSalesSnapshot::where('fecha', '2026-08-07')->first()->categorias)->toBeNull();
});

test('una función con lista vacía queda con lista vacía, no con null', function () {
    Http::fake(['*' => Http::response(cuerpoDelServicio([
        unaFuncion(['categorias' => []]),
    ]), 200)]);

    $this->artisan('ticket-sales:sync --fecha=2026-08-07')->assertSuccessful();

    expect(TicketSalesSnapshot::where('fecha', '2026-08-07')->first()->categorias)->toBe([]);
});

test('cada función guarda sus propias categorías, no las de la anterior', function () {
    Http::fake(['*' => Http::response(cuerpoDelServicio([
        unaFuncion(['producto_id' => 1, 'slot' => 'A', 'hora' => '10:00', 'categorias' => ['ticketera-2-0']]),
        unaFuncion(['producto_id' => 2, 'slot' => 'B', 'hora' => '11:00', 'categorias' => ['talleres']]),
    ]), 200)]);

    $this->artisan('ticket-sales:sync --fecha=2026-08-07')->assertSuccessful();

    $filas = TicketSalesSnapshot::where('fecha', '2026-08-07')->orderBy('producto_id')->get();

    expect($filas[0]->categorias)->toBe(['ticketera-2-0']);
    expect($filas[1]->categorias)->toBe(['talleres']);
});
```

- [ ] **Step 2: Correr y verificar que fallan**

Run: `php artisan test tests/Feature/TicketSales/SyncTicketSalesCommandTest.php`
Expected: los 4 nuevos FAIL con la columna en null (o con `Undefined array key` en el primero).

- [ ] **Step 3: Escribir la columna**

En `escribir()`, dentro del `TicketSalesSnapshot::create([...])`, entre `'hora'` y `'entradas_vendidas'`:

```php
                    'hora'                 => $funcion['hora'],
                    // El `?? null` no es defensivo de más: el cliente garantiza
                    // la clave, pero este comando también corre contra snapshots
                    // rellenados a mano en el futuro.
                    'categorias'           => $funcion['categorias'] ?? null,
                    'entradas_vendidas'    => $funcion['entradas_vendidas'],
```

- [ ] **Step 4: Correr toda la suite del paquete**

Run: `php artisan test tests/Unit/TicketSales tests/Feature/TicketSales`
Expected: PASS. Si aparece «Class ... ServiceProvider not found»: `rm -f bootstrap/cache/packages.php bootstrap/cache/services.php && php artisan package:discover`.

- [ ] **Step 5: Mutar y verificar**

| Mutación | Test que tiene que morir |
|---|---|
| Sacar la línea `'categorias'` del `create()` | «las categorías de cada función aterrizan en su columna» |
| `$funcion['categorias'] ?? null` → `?? []` | «una función sin el campo queda con la columna en null» |
| Poner la línea fuera del `foreach`, usando la primera función | «cada función guarda sus propias categorías» |

- [ ] **Step 6: Commit**

```bash
git add packages/CarlVallory/KrayinTicketSales/src/Console/SyncTicketSalesCommand.php tests/Feature/TicketSales/SyncTicketSalesCommandTest.php
git commit -m "feat(ticket-sales): el sync escribe las categorias

Sin derivar nada: se guarda crudo para poder cambiar el criterio sin
re-sincronizar la semana que el selector de fecha alcanza."
```

---
### Task 9: El reparto por categoría en `ProgramacionDePantalla`

**Files:**
- Modify: `packages/CarlVallory/KrayinTicketSales/src/Support/ProgramacionDePantalla.php`
- Rewrite parcial: `tests/Unit/TicketSales/ProgramacionDePantallaTest.php`

**Interfaces:**
- Consumes: filas del snapshot con `categorias` (Task 4/8) y la lista normalizada de `CriterioDeSanCosmos::categorias()` (Task 6).
- Produces:
  ```php
  ProgramacionDePantalla::armar(iterable $funciones, array $categoriasSanCosmos): array
  // ['sanCosmos'  => ['funciones' => [['hora' => ?string, 'entradas' => int], …]],
  //  'especiales' => [['producto_id' => int, 'show' => string, 'entradas' => int,
  //                    'funciones' => [['hora' => ?string, 'entradas' => int], …]], …]]
  ```
  `ProgramacionDePantalla::nombreCorto()` **no cambia**. Lo consume `pantalla()` (Task 10).

**La fusión cruzada sale gratis.** El panel izquierdo se arma pasando **todas** las filas de San Cosmos a la `fusionarPorHora()` que ya existe, en vez de una por producto. Cruzar productos no es código nuevo: es aplicar la misma función a un conjunto más grande. Y el argumento se sostiene igual —el servicio indexa por `producto + slot`, así que dos filas a la misma hora son conjuntos de entradas distintos y sumarlas no dobla— con un motivo extra: sin nombres, dos tarjetas «16:30» en la TV no significan nada.

**Qué se borra del test viejo.** Conservar **intacto** el bloque de `nombreCorto` (los 5 tests de `:127-176`). Borrar todo el resto de los tests y reemplazarlo por lo de abajo. Los que se van, por nombre:

| Test viejo | Por qué se va |
|---|---|
| «el show con más entradas vendidas va al panel destacado» | El criterio dejó de existir |
| «a igual cantidad de entradas gana el que tiene más funciones» | Se reescribe como orden de `especiales` |
| «con todos los shows en cero gana el de más funciones» | Idem |
| «a igual entradas y misma cantidad de funciones desempata el nombre» | Idem |
| «las funciones del destacado se ordenan por hora» | Se reescribe sobre `sanCosmos` |
| «el resto queda ordenado por entradas, de mayor a menor» | Se reescribe sobre `especiales` |
| «un solo show deja el resto vacío» | Se reescribe: los dos paneles se llenan por categoría, no por ranking |
| «sin funciones no hay destacado» | Ya no hay «destacado» |
| «agrupa por producto, no por nombre» | Se reescribe sobre `especiales` |
| «las funciones sin hora van al final» | Se reescribe para los dos paneles |
| Los 6 de fusión (`:179-254`) | Claves nuevas, y «la misma hora en productos distintos no se fusiona» **se invierte** para San Cosmos y **se conserva** para especiales |

- [ ] **Step 1: Reescribir el archivo de test**

Reemplazar el encabezado y los helpers por:

```php
<?php

use CarlVallory\KrayinTicketSales\Support\ProgramacionDePantalla;

/**
 * Las funciones entran como arrays y no como modelos: la clase es pura y los
 * tests Unit corren sin app, así que no hay resolver de Eloquent. Que también
 * funcione con los modelos de verdad lo fija el test Feature de la ruta.
 */
function funcionDePantalla(array $sobre = []): array
{
    return array_merge([
        'producto_id'       => 192637,
        'show_nombre'       => 'Entrada Bioestanque',
        'hora'              => '16:00',
        'entradas_vendidas' => 2,
        'categorias'        => ['talleres'],
    ], $sobre);
}

/** La misma función, pero categorizada como domo. */
function funcionDelDomo(array $sobre = []): array
{
    return funcionDePantalla(array_merge(['categorias' => ['ticketera-2-0']], $sobre));
}

/** El criterio, tal como lo entrega `CriterioDeSanCosmos::categorias()`. */
function criterioDomo(): array
{
    return ['ticketera-2-0', 'entrada-san-cosmos'];
}
```

Y estos tests, en lugar de los borrados:

```php
test('las funciones de una categoría de San Cosmos van al panel izquierdo', function () {
    $r = ProgramacionDePantalla::armar([
        funcionDelDomo(['hora' => '15:30', 'entradas_vendidas' => 25]),
    ], criterioDomo());

    expect($r['sanCosmos']['funciones'])->toBe([['hora' => '15:30', 'entradas' => 25]]);
    expect($r['especiales'])->toBe([]);
});

test('las que no están en la lista van a especiales con su nombre', function () {
    $r = ProgramacionDePantalla::armar([
        funcionDePantalla(['show_nombre' => 'Taller de robots', 'categorias' => ['talleres']]),
    ], criterioDomo());

    expect($r['sanCosmos']['funciones'])->toBe([]);
    expect($r['especiales'][0]['show'])->toBe('Taller de robots');
});

test('alcanza con que UNA de las categorías de la función esté en la lista', function () {
    // Los productos de WooCommerce suelen llevar varias categorías. Exigir que
    // todas coincidan dejaría afuera cualquier show del domo que además esté
    // etiquetado como "experiencias".
    $r = ProgramacionDePantalla::armar([
        funcionDePantalla(['categorias' => ['experiencias', 'ticketera-2-0', 'destacados']]),
    ], criterioDomo());

    expect($r['sanCosmos']['funciones'])->toHaveCount(1);
    expect($r['especiales'])->toBe([]);
});

test('categorias en null va a especiales', function () {
    // El estado del día del deploy: el servicio todavía no manda el campo.
    $r = ProgramacionDePantalla::armar([
        funcionDePantalla(['categorias' => null]),
    ], criterioDomo());

    expect($r['sanCosmos']['funciones'])->toBe([]);
    expect($r['especiales'])->toHaveCount(1);
});

test('categorias en lista vacía va a especiales', function () {
    $r = ProgramacionDePantalla::armar([
        funcionDePantalla(['categorias' => []]),
    ], criterioDomo());

    expect($r['sanCosmos']['funciones'])->toBe([]);
    expect($r['especiales'])->toHaveCount(1);
});

test('con el criterio vacío todo va a especiales y nada al panel izquierdo', function () {
    // Es lo que pasa si la fila de core_config quedó con basura. La pantalla se
    // degrada a nombres a la derecha, sin apagar nada.
    $r = ProgramacionDePantalla::armar([
        funcionDelDomo(),
        funcionDePantalla(['producto_id' => 2, 'show_nombre' => 'Taller']),
    ], []);

    expect($r['sanCosmos']['funciones'])->toBe([]);
    expect($r['especiales'])->toHaveCount(2);
});

test('la comparación de categorías ignora mayúsculas y espacios', function () {
    // El criterio llega normalizado desde CriterioDeSanCosmos; lo que puede
    // venir sucio es el lado de la función, que sale de WordPress.
    $r = ProgramacionDePantalla::armar([
        funcionDePantalla(['categorias' => ['  Ticketera-2-0 ']]),
    ], criterioDomo());

    expect($r['sanCosmos']['funciones'])->toHaveCount(1);
});

test('dos productos distintos del domo a la misma hora se fusionan en una tarjeta', function () {
    // El corazón del cambio. El domo es uno: dos funciones a las 16:30 son la
    // misma función vendida bajo dos etiquetas, y sin nombres dos tarjetas
    // "16:30" en la TV no significan nada. Sumar no dobla el conteo porque el
    // servicio indexa por producto + slot.
    $r = ProgramacionDePantalla::armar([
        funcionDelDomo(['producto_id' => 1, 'show_nombre' => 'Marte', 'hora' => '16:30', 'entradas_vendidas' => 2]),
        funcionDelDomo(['producto_id' => 2, 'show_nombre' => 'Historias', 'hora' => '16:30', 'entradas_vendidas' => 20]),
    ], criterioDomo());

    expect($r['sanCosmos']['funciones'])->toBe([['hora' => '16:30', 'entradas' => 22]]);
});

test('la misma hora en dos especiales distintos NO se fusiona', function () {
    // La contracara: a la derecha los nombres se ven, y dos actividades
    // especiales a la misma hora son dos cosas distintas que pasan a la vez.
    $r = ProgramacionDePantalla::armar([
        funcionDePantalla(['producto_id' => 1, 'show_nombre' => 'Aves', 'hora' => '16:30', 'entradas_vendidas' => 2]),
        funcionDePantalla(['producto_id' => 2, 'show_nombre' => 'Robots', 'hora' => '16:30', 'entradas_vendidas' => 20]),
    ], criterioDomo());

    expect($r['especiales'])->toHaveCount(2);
});

test('dos filas del mismo producto del domo a la misma hora se suman', function () {
    // El caso del slot renombrado en WordPress, que ya existía antes de este
    // cambio y sigue valiendo.
    $r = ProgramacionDePantalla::armar([
        funcionDelDomo(['hora' => '16:30', 'entradas_vendidas' => 2]),
        funcionDelDomo(['hora' => '16:30', 'entradas_vendidas' => 20]),
    ], criterioDomo());

    expect($r['sanCosmos']['funciones'])->toBe([['hora' => '16:30', 'entradas' => 22]]);
});

test('las funciones del panel izquierdo se ordenan por hora', function () {
    $r = ProgramacionDePantalla::armar([
        funcionDelDomo(['producto_id' => 1, 'hora' => '17:30', 'entradas_vendidas' => 1]),
        funcionDelDomo(['producto_id' => 2, 'hora' => '09:00', 'entradas_vendidas' => 2]),
        funcionDelDomo(['producto_id' => 3, 'hora' => '13:15', 'entradas_vendidas' => 3]),
    ], criterioDomo());

    expect(array_column($r['sanCosmos']['funciones'], 'hora'))->toBe(['09:00', '13:15', '17:30']);
});

test('las del domo sin hora no se fusionan entre sí y van al final', function () {
    // Dos funciones sin horario no tienen por qué ser la misma, y una sin
    // horario encabezando el panel es donde nadie la espera.
    $r = ProgramacionDePantalla::armar([
        funcionDelDomo(['producto_id' => 1, 'hora' => null, 'entradas_vendidas' => 3]),
        funcionDelDomo(['producto_id' => 2, 'hora' => null, 'entradas_vendidas' => 4]),
        funcionDelDomo(['producto_id' => 3, 'hora' => '10:00', 'entradas_vendidas' => 5]),
    ], criterioDomo());

    expect($r['sanCosmos']['funciones'])->toBe([
        ['hora' => '10:00', 'entradas' => 5],
        ['hora' => null, 'entradas' => 3],
        ['hora' => null, 'entradas' => 4],
    ]);
});

test('el panel izquierdo no expone el nombre de ningún show', function () {
    // Es el pedido, fijado en la estructura: si mañana alguien agrega la clave,
    // este test lo frena antes de que llegue al Blade.
    $r = ProgramacionDePantalla::armar([
        funcionDelDomo(['show_nombre' => 'Misterios de tu cerebro']),
    ], criterioDomo());

    foreach ($r['sanCosmos']['funciones'] as $funcion) {
        expect(array_keys($funcion))->toBe(['hora', 'entradas']);
    }

    expect(json_encode($r['sanCosmos']))->not->toContain('Misterios');
});

test('especiales queda ordenado por entradas, de mayor a menor', function () {
    $r = ProgramacionDePantalla::armar([
        funcionDePantalla(['producto_id' => 1, 'show_nombre' => 'Aves', 'entradas_vendidas' => 3]),
        funcionDePantalla(['producto_id' => 2, 'show_nombre' => 'Robots', 'entradas_vendidas' => 30]),
    ], criterioDomo());

    expect(array_column($r['especiales'], 'show'))->toBe(['Robots', 'Aves']);
});

test('a igual entradas, en especiales gana el que tiene más funciones', function () {
    // El de una sola función va primero en la entrada a propósito: con un orden
    // estable, no desempatar lo dejaría ganando.
    $r = ProgramacionDePantalla::armar([
        funcionDePantalla(['producto_id' => 1, 'show_nombre' => 'Aves', 'hora' => '10:00', 'entradas_vendidas' => 12]),
        funcionDePantalla(['producto_id' => 2, 'show_nombre' => 'Robots', 'hora' => '08:30', 'entradas_vendidas' => 5]),
        funcionDePantalla(['producto_id' => 2, 'show_nombre' => 'Robots', 'hora' => '09:30', 'entradas_vendidas' => 7]),
    ], criterioDomo());

    expect(array_column($r['especiales'], 'show'))->toBe(['Robots', 'Aves']);
});

test('con todos los especiales en cero gana el de más funciones', function () {
    // Cada mañana, antes de la primera venta, todos están en cero: el empate es
    // la regla, no el caso raro.
    $r = ProgramacionDePantalla::armar([
        funcionDePantalla(['producto_id' => 1, 'show_nombre' => 'Aves', 'hora' => '10:00', 'entradas_vendidas' => 0]),
        funcionDePantalla(['producto_id' => 2, 'show_nombre' => 'Robots', 'hora' => '08:30', 'entradas_vendidas' => 0]),
        funcionDePantalla(['producto_id' => 2, 'show_nombre' => 'Robots', 'hora' => '09:30', 'entradas_vendidas' => 0]),
    ], criterioDomo());

    expect(array_column($r['especiales'], 'show'))->toBe(['Robots', 'Aves']);
});

test('a igual entradas y funciones, en especiales desempata el nombre', function () {
    $r = ProgramacionDePantalla::armar([
        funcionDePantalla(['producto_id' => 1, 'show_nombre' => 'Zorros', 'entradas_vendidas' => 0]),
        funcionDePantalla(['producto_id' => 2, 'show_nombre' => 'Aves', 'entradas_vendidas' => 0]),
    ], criterioDomo());

    expect(array_column($r['especiales'], 'show'))->toBe(['Aves', 'Zorros']);
});

test('el desempate por funciones cuenta horarios distintos, no filas', function () {
    // Dos filas a la misma hora son UNA función en la tarjeta. Contarlas como
    // dos dejaría ganando a un show con una sola función y una fila duplicada.
    $r = ProgramacionDePantalla::armar([
        funcionDePantalla(['producto_id' => 1, 'show_nombre' => 'Aves', 'hora' => '10:00', 'entradas_vendidas' => 5]),
        funcionDePantalla(['producto_id' => 1, 'show_nombre' => 'Aves', 'hora' => '10:00', 'entradas_vendidas' => 0]),
        funcionDePantalla(['producto_id' => 2, 'show_nombre' => 'Robots', 'hora' => '11:00', 'entradas_vendidas' => 3]),
        funcionDePantalla(['producto_id' => 2, 'show_nombre' => 'Robots', 'hora' => '12:00', 'entradas_vendidas' => 2]),
    ], criterioDomo());

    expect(array_column($r['especiales'], 'show'))->toBe(['Robots', 'Aves']);
});

test('agrupa especiales por producto, no por nombre', function () {
    $r = ProgramacionDePantalla::armar([
        funcionDePantalla(['producto_id' => 1, 'show_nombre' => 'Igual', 'hora' => '10:00', 'entradas_vendidas' => 1]),
        funcionDePantalla(['producto_id' => 2, 'show_nombre' => 'Igual', 'hora' => '11:00', 'entradas_vendidas' => 1]),
    ], criterioDomo());

    expect($r['especiales'])->toHaveCount(2);
});

test('sin funciones los dos paneles quedan vacíos', function () {
    $r = ProgramacionDePantalla::armar([], criterioDomo());

    expect($r['sanCosmos']['funciones'])->toBe([]);
    expect($r['especiales'])->toBe([]);
});

test('un día de solo domo deja especiales vacío', function () {
    $r = ProgramacionDePantalla::armar([funcionDelDomo()], criterioDomo());

    expect($r['especiales'])->toBe([]);
    expect($r['sanCosmos']['funciones'])->toHaveCount(1);
});

test('un día sin domo deja el panel izquierdo sin funciones', function () {
    $r = ProgramacionDePantalla::armar([funcionDePantalla()], criterioDomo());

    expect($r['sanCosmos']['funciones'])->toBe([]);
    expect($r['especiales'])->toHaveCount(1);
});

test('fusionar no cambia el total de entradas del día', function () {
    // El invariante que protege contra doblar el conteo al cruzar productos.
    $funciones = [
        funcionDelDomo(['producto_id' => 1, 'hora' => '16:30', 'entradas_vendidas' => 2]),
        funcionDelDomo(['producto_id' => 2, 'hora' => '16:30', 'entradas_vendidas' => 20]),
        funcionDelDomo(['producto_id' => 2, 'hora' => '17:30', 'entradas_vendidas' => 5]),
        funcionDePantalla(['producto_id' => 3, 'hora' => '11:00', 'entradas_vendidas' => 7]),
    ];

    $r = ProgramacionDePantalla::armar($funciones, criterioDomo());

    $enPantalla = array_sum(array_column($r['sanCosmos']['funciones'], 'entradas'))
        + array_sum(array_column($r['especiales'], 'entradas'));

    expect($enPantalla)->toBe(34);
});
```

- [ ] **Step 2: Correr y verificar que fallan**

Run: `php artisan test tests/Unit/TicketSales/ProgramacionDePantallaTest.php`
Expected: todos los nuevos FAIL («Undefined array key "sanCosmos"» o `ArgumentCountError`). Los 5 de `nombreCorto`, en verde.

- [ ] **Step 3: Reescribir `armar()` y agregar el reparto**

Reemplazar `armar()` y `agrupar()`; `fusionarPorHora()`, `criterio()` y `nombreCorto()` **no se tocan**:

```php
    /**
     * @param  array<int, string> $categoriasSanCosmos  slugs YA normalizados,
     *                                                  tal como los entrega
     *                                                  CriterioDeSanCosmos
     * @return array{sanCosmos: array{funciones: array}, especiales: array}
     */
    public static function armar(iterable $funciones, array $categoriasSanCosmos): array
    {
        [$domo, $especiales] = collect($funciones)->partition(
            fn ($funcion) => self::esDeSanCosmos($funcion, $categoriasSanCosmos)
        );

        return [
            // Todas las del domo entran juntas a la MISMA fusión, y por eso se
            // cruzan productos: el domo es uno, dos funciones a la misma hora
            // son la misma función vendida bajo dos etiquetas, y sin nombres dos
            // tarjetas con la misma hora en la TV no significan nada. Sumar no
            // dobla el conteo porque el servicio indexa por producto + slot.
            //
            // El panel no lleva total propio: no hay vista que lo muestre, y un
            // campo que nadie renderiza es un campo que ningún test fija.
            'sanCosmos'  => ['funciones' => self::fusionarPorHora($domo)],
            'especiales' => self::agrupar($especiales),
        ];
    }

    /**
     * Alcanza con que UNA categoría de la función esté en la lista. Los
     * productos de WooCommerce suelen llevar varias, y exigir que todas
     * coincidan dejaría afuera cualquier show del domo etiquetado además como
     * "experiencias".
     *
     * Normaliza el lado de la FUNCIÓN, que es el que viene de WordPress. El del
     * criterio ya llega normalizado de CriterioDeSanCosmos: cada lado en un
     * lugar, sin duplicar la regla.
     */
    private static function esDeSanCosmos(mixed $funcion, array $categoriasSanCosmos): bool
    {
        if ($categoriasSanCosmos === []) {
            return false;
        }

        $suyas = data_get($funcion, 'categorias');

        // null es "no sé" —servicio viejo o campo malformado— y va a la derecha
        // con su nombre, que es exactamente la pantalla de antes de este cambio.
        if (! is_array($suyas)) {
            return false;
        }

        foreach ($suyas as $slug) {
            if (is_string($slug) && in_array(mb_strtolower(trim($slug)), $categoriasSanCosmos, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * El panel derecho: un grupo por producto, con nombre, ordenado por la
     * cascada de `criterio()`. Esa cascada ya no elige el panel —eso lo hace la
     * categoría— pero sigue ordenando acá, y sus dos desempates se siguen
     * ganando el lugar: cada mañana, antes de la primera venta, todos los shows
     * están en cero y el empate es la regla.
     *
     * @return array<int, array>
     */
    private static function agrupar(Collection $funciones): array
    {
        return $funciones
            ->groupBy('producto_id')
            ->map(fn (Collection $grupo) => [
                'producto_id' => (int) data_get($grupo->first(), 'producto_id'),
                'show'        => (string) data_get($grupo->first(), 'show_nombre'),
                'entradas'    => (int) $grupo->sum('entradas_vendidas'),
                'funciones'   => self::fusionarPorHora($grupo),
            ])
            ->sort(self::criterio())
            ->values()
            ->all();
    }
```

Actualizar el docblock de clase: el criterio del destacado ya no reparte, reparte la categoría.

- [ ] **Step 4: Correr y verificar que pasan**

Run: `php artisan test tests/Unit/TicketSales/ProgramacionDePantallaTest.php`
Expected: PASS. La suite Feature de pantalla **va a fallar** hasta la Task 11: es esperado y se arregla ahí.

- [ ] **Step 5: Mutar y verificar**

| Mutación | Test que tiene que morir |
|---|---|
| `esDeSanCosmos` exige que **todas** las categorías estén en la lista | «alcanza con que UNA de las categorías» |
| Sacar `mb_strtolower(trim(...))` del lado de la función | «la comparación de categorías ignora mayúsculas y espacios» |
| `if (! is_array($suyas))` → `if ($suyas === null)` | «categorias en lista vacía va a especiales» |
| Sacar el corte de `$categoriasSanCosmos === []` | «con el criterio vacío todo va a especiales» (con `in_array` sobre lista vacía nada coincide igual: si sobrevive, **anotarlo** — es una mutación equivalente y el corte es solo claridad) |
| Fusionar el domo por producto (`groupBy('producto_id')` antes de `fusionarPorHora`) | «dos productos distintos del domo a la misma hora se fusionan» |
| Fusionar los especiales cruzando productos | «la misma hora en dos especiales distintos NO se fusiona» |
| Agregar `'show' => …` a las funciones de `sanCosmos` | «el panel izquierdo no expone el nombre de ningún show» |
| Cambiar `partition` por «todo a especiales» | casi todos |

- [ ] **Step 6: Commit**

```bash
git add packages/CarlVallory/KrayinTicketSales/src/Support/ProgramacionDePantalla.php tests/Unit/TicketSales/ProgramacionDePantallaTest.php
git commit -m "feat(ticket-sales): el panel izquierdo se reparte por categoria

Deja de ser \"el show que mas vendio\". Todas las del domo entran a la
misma fusion por hora, y por eso se cruzan productos: sin nombres, dos
tarjetas con la misma hora no significan nada. La cascada de ventas
sobrevive ordenando el panel derecho."
```

---

### Task 10: El controlador y la vista de pantalla

**Files:**
- Modify: `packages/CarlVallory/KrayinTicketSales/src/Http/Controllers/TicketSalesController.php:32-40`
- Test: `tests/Feature/TicketSales/TicketSalesPantallaTest.php`

**Interfaces:**
- Consumes: `CriterioDeSanCosmos::desdeConfig()` (Task 6) y `ProgramacionDePantalla::armar()` (Task 9).
- Produces: la vista `pantalla` recibe `$sanCosmos`, `$especiales` y `$rotuloSanCosmos`, además de todo lo que ya recibía de `datosDelDia()`. Los consume el Blade (Task 11).

**El criterio se lee solo en `pantalla()`, no en `datosDelDia()`.** El tablero de admin no reparte paneles y no le hace falta. Esto no contradice la regla de no duplicar `datosDelDia()`: lo compartido es el filtro por fecha y el criterio de «viejo»; el reparto en paneles es de la pantalla y de nadie más.

- [ ] **Step 1: Escribir el test que falla**

El archivo ya trae lo que hace falta y hay que usarlo tal cual: `beforeEach` deja
`$this->hoy` y `$this->admin` (que sale de `getDefaultAdmin()` y saltea el test si
la base local no tiene usuarios), y los helpers son
`sembrarFuncionEnPantalla(string $fecha, array $sobre = [])` y
`sembrarSyncEnPantalla(string $fecha)`. El acceso va con
`$this->actingAs($this->admin, 'user')` — **con el guard `'user'`**, que es el de
Krayin.

**Al helper de siembra no hay que tocarlo:** `categorias` ya está en `$fillable`
(Task 4) y entra por `$sobre`. Su default sigue siendo «campo ausente», que es el
estado real entre los dos deploys.

Agregar este helper nuevo al archivo, junto a los otros dos:

```php
/**
 * El criterio, escrito directo en `core_config`. La página que lo edita se
 * prueba aparte: acá lo que importa es que la pantalla lo lea.
 */
function guardarCriterioEnConfig(array $valor): void
{
    \Illuminate\Support\Facades\DB::table('core_config')->updateOrInsert(
        ['code' => \CarlVallory\KrayinTicketSales\Support\CriterioDeSanCosmos::CLAVE],
        ['value' => json_encode($valor), 'updated_at' => now()]
    );
}
```

Y el test:

```php
test('la pantalla usa el criterio guardado en core_config', function () {
    guardarCriterioEnConfig(['titulo' => 'Domo MuCi', 'categorias' => ['solo-esta']]);

    sembrarSyncEnPantalla($this->hoy);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 1, 'show_nombre' => 'Marte',
        'hora' => '15:30', 'entradas_vendidas' => 9, 'categorias' => ['solo-esta']]);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 2, 'show_nombre' => 'Taller de robots',
        'hora' => '16:00', 'entradas_vendidas' => 4, 'categorias' => ['talleres']]);

    $respuesta = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk();

    expect($respuesta->viewData('rotuloSanCosmos'))->toBe('Domo MuCi');
    expect(array_column($respuesta->viewData('especiales'), 'show'))->toBe(['Taller de robots']);
    expect($respuesta->viewData('sanCosmos')['funciones'])->toBe([['hora' => '15:30', 'entradas' => 9]]);

    $respuesta->assertDontSee('Marte');
});
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `php artisan test tests/Feature/TicketSales/TicketSalesPantallaTest.php`
Expected: FAIL. Muchos otros tests del archivo también fallan, porque el Blade todavía lee `$destacado`/`$resto`. Se arreglan en la Task 11.

- [ ] **Step 3: Leer el criterio en `pantalla()`**

```php
    public function pantalla(BusinessDay $businessDay, Request $request)
    {
        $datos    = $this->datosDelDia($businessDay, $request);
        $criterio = CriterioDeSanCosmos::desdeConfig();

        return view('krayin-ticket-sales::pantalla', array_merge(
            $datos,
            ProgramacionDePantalla::armar($datos['funciones'], $criterio->categorias()),
            // El rótulo no viaja por `armar()`: es presentación, no reparto, y
            // meterlo ahí obligaría a la clase pura a saber de la config.
            ['rotuloSanCosmos' => $criterio->titulo()]
        ));
    }
```

Agregar `use CarlVallory\KrayinTicketSales\Support\CriterioDeSanCosmos;` a los `use`.

- [ ] **Step 4: Confirmar que ahora falla en el Blade, no en el controlador**

Run: `php artisan test tests/Feature/TicketSales/TicketSalesPantallaTest.php`
Expected: FAIL con «Undefined variable $destacado» desde `pantalla.blade.php`. Si el error sigue siendo del controlador, el `use` o el `array_merge` quedaron mal.

---
- [ ] **Step 5: Cambiar las columnas calculadas del `@php`**

En `pantalla.blade.php:16-17`, reemplazar:

```php
    $colsDestacado = $destacado ? $columnas(count($destacado['funciones']), 4) : 1;
    $colsResto     = count($resto) <= 4 ? min(2, max(1, count($resto))) : 3;
```

por:

```php
    $colsSanCosmos = $columnas(count($sanCosmos['funciones']), 4);
    $colsEspeciales = count($especiales) <= 4 ? min(2, max(1, count($especiales))) : 3;
```

El `$destacado ? … : 1` desaparece porque `$sanCosmos['funciones']` siempre existe —vacío o no— y `$columnas()` ya devuelve 1 para 0 o 1 elementos.

- [ ] **Step 6: Cambiar la condición del cartel de «no hay funciones»**

En `:281`, reemplazar `@elseif (! $destacado)` por:

```blade
        @elseif (count($sanCosmos['funciones']) === 0 && count($especiales) === 0)
```

Ese cartel a pantalla completa se queda: «no hay ninguna función hoy» es un estado distinto de «no hay domo hoy», y en una TV hay que poder distinguirlos.

- [ ] **Step 7: Reescribir el panel izquierdo**

Reemplazar `:292-295`:

```blade
            <section class="panel panel--destacado">
                <h1 class="panel__titulo">{{ ProgramacionDePantalla::nombreCorto($destacado['show']) }}</h1>

                <div class="grilla" style="--cols: {{ $colsDestacado }};">
                    @foreach ($destacado['funciones'] as $funcion)
```

por:

```blade
            <section class="panel panel--destacado">
                {{-- El rótulo es configurable y NO pasa por `nombreCorto()`: ese
                     corte existe para nombres de show que llegan de WordPress con
                     cualquier largo, y este lo escribe una persona en la página de
                     configuración. Recortárselo sería corregirle la mano. --}}
                <h1 class="panel__titulo">{{ $rotuloSanCosmos }}</h1>

                @if (count($sanCosmos['funciones']) === 0)
                    {{-- El 60/40 no se mueve: la TV se ve igual todos los días y
                         quien pasa por el hall sabe que la izquierda es el domo.
                         Un panel sin funciones es información, no un hueco. --}}
                    <p class="aviso__detalle" data-sin-domo="1">Hoy no hay funciones de {{ $rotuloSanCosmos }}</p>
                @else
                <div class="grilla" style="--cols: {{ $colsSanCosmos }};">
                    @foreach ($sanCosmos['funciones'] as $funcion)
```

Y cerrar el `@if` justo antes del `</section>`, después del `</div>` de la grilla:

```blade
                </div>
                @endif
            </section>
```

**El `data-sin-domo` no es decorativo.** El rótulo aparece dos veces en ese caso —en el `<h1>` y en el cartel—, así que un test que busque el texto del rótulo pasaría igual con el cartel roto. Es el mismo motivo del `data-viejo` y del `data-cupos-vacio` que ya están en el archivo.

- [ ] **Step 8: Reescribir el panel derecho**

Reemplazar `:312-318` y el `@foreach` que sigue, cambiando `$resto` por `$especiales`, `$colsResto` por `$colsEspeciales`, y el cartel de panel vacío:

```blade
            <aside class="panel panel--resto" style="--cols-resto: {{ $colsEspeciales }};">
                <h2 class="panel__titulo">Programación</h2>

                @if (count($especiales) === 0)
                    <p class="aviso__detalle" style="color: #000000;" data-solo-domo="1">Hoy solo hay funciones de {{ $rotuloSanCosmos }}.</p>
                @else
                    <div class="grilla" style="--cols: {{ $colsEspeciales }};">
                        @foreach ($especiales as $show)
```

El resto del cuerpo del `@foreach` —`nombreCorto($show['show'])`, el `data-multihorario`, la cifra a dos dígitos— **no cambia**: los especiales siguen mostrando su nombre, que es el pedido.

- [ ] **Step 9: Escribir los tests Feature de la pantalla**

En `tests/Feature/TicketSales/TicketSalesPantallaTest.php`, con los helpers del Step 1.

**Primero adaptar los que hablan de «destacado»**, que se rompieron con la Task 9:
«el destacado es el show con más entradas y muestra sus horarios», «las cifras del
destacado van a dos dígitos», «una cifra de tres dígitos no se recorta», «un nombre
largo se corta en el destacado y en las tarjetas», «un nombre que entra no se toca
en la pantalla» y «dos filas del mismo producto a la misma hora se ven como una
sola tarjeta». En todos, lo que antes era «el show con más ventas» ahora es una
función con `'categorias' => ['ticketera-2-0']`, y `viewData('destacado')` pasa a
`viewData('sanCosmos')`. Los de nombres largos **se mueven al panel derecho**: a la
izquierda ya no hay nombre que cortar, y ahí es donde `nombreCorto()` sigue vivo.

Los que **no** cambian: los del dato viejo, el histórico, el `OTRO_DIA`, el «sync
nunca corrió», el chrome del CRM, los enlaces entre vistas, Poppins, y todos los
del tablero de admin.

Y agregar estos:

```php
test('el panel izquierdo muestra los horarios y las ventas del domo, y ningún nombre', function () {
    sembrarSyncEnPantalla($this->hoy);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 1, 'show_nombre' => 'Experiencia adaptada',
        'hora' => '15:30', 'entradas_vendidas' => 25, 'categorias' => ['ticketera-2-0']]);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 2, 'show_nombre' => 'Misterios de tu cerebro',
        'hora' => '17:00', 'entradas_vendidas' => 8, 'categorias' => ['ticketera-2-0']]);

    $respuesta = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk();

    // Los horarios y las ventas, sí.
    $respuesta->assertSee('15:30')->assertSee('17:00');
    expect($respuesta->getContent())->toContain('data-cifra="25"')->toContain('data-cifra="08"');

    // Los nombres, no. Es el pedido.
    $respuesta->assertDontSee('Experiencia adaptada')->assertDontSee('Misterios de tu cerebro');
});

test('las actividades especiales sí muestran su nombre', function () {
    sembrarSyncEnPantalla($this->hoy);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 1, 'show_nombre' => 'Marte',
        'hora' => '15:30', 'entradas_vendidas' => 25, 'categorias' => ['ticketera-2-0']]);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 2, 'show_nombre' => 'Taller de robots',
        'hora' => '16:00', 'entradas_vendidas' => 4, 'categorias' => ['talleres']]);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk()
        ->assertSee('Taller de robots');
});

test('dos shows del domo a la misma hora se ven como una sola tarjeta', function () {
    sembrarSyncEnPantalla($this->hoy);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 1, 'show_nombre' => 'Marte',
        'slot' => 'Domo A', 'hora' => '16:30', 'entradas_vendidas' => 2, 'categorias' => ['ticketera-2-0']]);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 2, 'show_nombre' => 'Historias extelares',
        'slot' => 'Domo B', 'hora' => '16:30', 'entradas_vendidas' => 20, 'categorias' => ['ticketera-2-0']]);

    $html = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('data-cifra="22"');
    expect(substr_count($html, '16:30'))->toBe(1);
});

test('un día sin domo deja el panel izquierdo con su cartel y el 60/40 intacto', function () {
    sembrarSyncEnPantalla($this->hoy);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 2, 'show_nombre' => 'Taller de robots',
        'hora' => '16:00', 'entradas_vendidas' => 4, 'categorias' => ['talleres']]);

    $respuesta = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk();

    expect($respuesta->getContent())->toContain('data-sin-domo="1"');
    $respuesta->assertSee('Taller de robots');

    // El cartel de "no hay ninguna función" es otro estado y no tiene que salir.
    $respuesta->assertDontSee('No hay funciones programadas');
});

test('un día de solo domo dice que solo hay funciones del domo', function () {
    sembrarSyncEnPantalla($this->hoy);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 1, 'show_nombre' => 'Marte',
        'hora' => '15:30', 'entradas_vendidas' => 25, 'categorias' => ['ticketera-2-0']]);

    $html = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('data-solo-domo="1"');
    expect($html)->not->toContain('data-sin-domo="1"');
});

test('un día sin ninguna función muestra el cartel a pantalla completa', function () {
    sembrarSyncEnPantalla($this->hoy);

    $html = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('No hay funciones programadas');
    expect($html)->not->toContain('data-sin-domo="1"');
});

test('el rótulo del panel izquierdo sale del config, no del código', function () {
    guardarCriterioEnConfig(['titulo' => 'Domo MuCi', 'categorias' => ['ticketera-2-0']]);

    sembrarSyncEnPantalla($this->hoy);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 1, 'show_nombre' => 'Marte',
        'hora' => '15:30', 'entradas_vendidas' => 25, 'categorias' => ['ticketera-2-0']]);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk()
        ->assertSee('Domo MuCi');
});

test('con las categorías en null todo cae a la derecha, como antes del cambio', function () {
    // El estado real entre el deploy del CRM y el del servicio.
    sembrarSyncEnPantalla($this->hoy);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 1, 'show_nombre' => 'Marte',
        'hora' => '15:30', 'entradas_vendidas' => 25, 'categorias' => null]);

    $respuesta = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk();

    $respuesta->assertSee('Marte');
    expect($respuesta->getContent())->toContain('data-sin-domo="1"');
});

test('el tablero de admin sigue mostrando los nombres del domo', function () {
    // No es olvido: es la única vista donde se ve una función huérfana, y
    // esconder el nombre ahí volvería invisible el problema del slot renombrado.
    sembrarSyncEnPantalla($this->hoy);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 1, 'show_nombre' => 'Experiencia adaptada',
        'hora' => '15:30', 'entradas_vendidas' => 25, 'categorias' => ['ticketera-2-0']]);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertOk()
        ->assertSee('Experiencia adaptada');
});
```

- [ ] **Step 10: Correr toda la suite**

Run: `php artisan test tests/Unit/TicketSales tests/Feature/TicketSales`
Expected: PASS, todo.

- [ ] **Step 11: Mirarlo de verdad en el navegador**

Ningún test detecta un panel que desborda o una cifra ilegible a tres metros. Cargar `/admin/ticket-sales/pantalla` con datos del día y revisar:

- Que no aparezca barra de scroll (el `overflow: hidden` del `body` es red de seguridad, no solución: si algo desborda es un bug).
- Que las cifras del panel izquierdo se lean de lejos con 1, 4 y 8 funciones del domo.
- Que el cartel de «Hoy no hay funciones de …» quede centrado y no minúsculo.
- En vertical (`max-aspect-ratio: 1/1`), que los dos paneles sigan repartidos.

- [ ] **Step 12: Mutar y verificar**

| Mutación | Test que tiene que morir |
|---|---|
| `$rotuloSanCosmos` → `'San Cosmos'` literal en el `<h1>` | «el rótulo del panel izquierdo sale del config» |
| Poner `{{ $show['show'] }}` en el panel izquierdo | «el panel izquierdo muestra los horarios y las ventas del domo, y ningún nombre» |
| Sacar el `@if (count($sanCosmos['funciones']) === 0)` | «un día sin domo deja el panel izquierdo con su cartel» |
| Cambiar la condición del cartel completo a `count($especiales) === 0` | «un día de solo domo dice que solo hay funciones del domo» |
| Sacar `data-sin-domo="1"` | «un día sin domo…» y «con las categorías en null todo cae a la derecha» |

- [ ] **Step 13: Commit**

```bash
git add packages/CarlVallory/KrayinTicketSales/src/Http/Controllers/TicketSalesController.php packages/CarlVallory/KrayinTicketSales/src/Resources/views/pantalla.blade.php tests/Feature/TicketSales/TicketSalesPantallaTest.php
git commit -m "feat(ticket-sales): la pantalla reparte San Cosmos a la izquierda

Sin nombres a la izquierda, con nombres a la derecha, y el 60/40 fijo:
un panel sin funciones dice que no hay, y desde el hall eso no se
confunde con un panel roto. El rotulo sale de core_config."
```

---

### Task 11: La página de configuración

**Files:**
- Modify: `packages/CarlVallory/KrayinTicketSales/src/Http/routes.php`
- Modify: `packages/CarlVallory/KrayinTicketSales/src/Http/Controllers/TicketSalesController.php` (dos métodos nuevos)
- Create: `packages/CarlVallory/KrayinTicketSales/src/Resources/views/configure.blade.php`
- Modify: `packages/CarlVallory/KrayinTicketSales/src/Resources/views/index.blade.php` (un enlace)
- Test: `tests/Feature/TicketSales/TicketSalesConfigureTest.php` (nuevo)

**Interfaces:**
- Consumes: `CriterioDeSanCosmos` (Task 6) y la columna `categorias` (Task 4).
- Produces: las rutas `krayin.ticket-sales.configure` (GET) y `krayin.ticket-sales.configure.store` (POST).

**Sin entrada nueva en la ACL.** `acl.php` tiene una sola clave, `ticket_sales`, y las dos rutas nuevas van en el mismo grupo con el mismo middleware (`web`, `admin_locale`, `user`). Quien puede ver las ventas puede configurar el criterio; separar permisos acá sería inventar una distinción que nadie pidió.

**Los candidatos de checkbox se arman en PHP, no en SQL.** La columna es JSON y un `DISTINCT` de MySQL devolvería documentos enteros, no categorías.

**El límite de 255 caracteres de `core_config.value` se valida acá.** MySQL en modo no estricto truncaría en silencio, y un criterio truncado reparte mal sin avisar.

- [ ] **Step 1: Escribir los tests que fallan**

Archivo nuevo `tests/Feature/TicketSales/TicketSalesConfigureTest.php`:

```php
<?php

use CarlVallory\KrayinTicketSales\Models\TicketSalesSnapshot;
use CarlVallory\KrayinTicketSales\Support\CriterioDeSanCosmos;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

beforeEach(function () {
    // Mismo arranque que TicketSalesPantallaTest: `getDefaultAdmin()` es un
    // helper global de la suite del CRM, y sin usuarios en la base local no hay
    // forma de entrar a una ruta con middleware `user`.
    $this->admin = getDefaultAdmin();

    if (! $this->admin) {
        $this->markTestSkipped('No hay usuarios en la base local.');
    }
});

/** El mismo helper que usa el test de pantalla, con otro nombre para no chocar. */
function guardarCriterioDesdeConfigure(array $valor): void
{
    DB::table('core_config')->updateOrInsert(
        ['code' => CriterioDeSanCosmos::CLAVE],
        ['value' => json_encode($valor), 'updated_at' => now()]
    );
}

test('un visitante sin sesión no puede ver la configuración', function () {
    $this->get(route('krayin.ticket-sales.configure'))->assertRedirect();
});

test('guarda las categorías elegidas y el rótulo', function () {
    $this->actingAs($this->admin, 'user')
        ->post(route('krayin.ticket-sales.configure.store'), [
            'titulo'     => 'Domo MuCi',
            'categorias' => ['ticketera-2-0', 'entrada-san-cosmos'],
        ])
        ->assertRedirect();

    $criterio = CriterioDeSanCosmos::desdeConfig();

    expect($criterio->titulo())->toBe('Domo MuCi');
    expect($criterio->categorias())->toBe(['ticketera-2-0', 'entrada-san-cosmos']);
});

test('el campo libre agrega una categoría que todavía no apareció', function () {
    $this->actingAs($this->admin, 'user')
        ->post(route('krayin.ticket-sales.configure.store'), [
            'titulo'          => 'San Cosmos',
            'categorias'      => ['ticketera-2-0'],
            'categoria_nueva' => '  Domo-Nuevo ',
        ])
        ->assertRedirect();

    expect(CriterioDeSanCosmos::desdeConfig()->categorias())
        ->toBe(['ticketera-2-0', 'domo-nuevo']);
});

test('guardar sin ninguna categoría deja la lista vacía, no lanza', function () {
    // Es una elección válida: apaga el panel del domo. Lo que no puede hacer es
    // romper la página.
    $this->actingAs($this->admin, 'user')
        ->post(route('krayin.ticket-sales.configure.store'), ['titulo' => 'San Cosmos'])
        ->assertRedirect();

    expect(CriterioDeSanCosmos::desdeConfig()->categorias())->toBe([]);
});

test('un criterio que no entra en la columna se rechaza con mensaje, no se trunca', function () {
    // `core_config.value` es un VARCHAR(255). MySQL en modo no estricto
    // truncaría en silencio, y un criterio truncado reparte mal sin avisar.
    $muchas = array_map(fn ($i) => "categoria-larguisima-numero-{$i}", range(1, 30));

    $this->actingAs($this->admin, 'user')
        ->post(route('krayin.ticket-sales.configure.store'), [
            'titulo'     => 'San Cosmos',
            'categorias' => $muchas,
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(CriterioDeSanCosmos::desdeConfig()->categorias())->not->toBe($muchas);
});

test('la página ofrece como candidatas las categorías vistas en la ventana de retención', function () {
    TicketSalesSnapshot::create([
        'fecha' => now()->format('Y-m-d'), 'producto_id' => 1, 'show_nombre' => 'Marte',
        'slot'  => 'X', 'hora' => '15:30', 'categorias' => ['ticketera-2-0', 'experiencias'],
    ]);
    TicketSalesSnapshot::create([
        'fecha' => now()->format('Y-m-d'), 'producto_id' => 2, 'show_nombre' => 'Taller',
        'slot'  => 'Y', 'hora' => '16:00', 'categorias' => ['talleres'],
    ]);

    $html = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.configure'))
        ->assertOk()
        ->getContent();

    foreach (['ticketera-2-0', 'experiencias', 'talleres'] as $slug) {
        expect($html)->toContain($slug);
    }
});

test('las filas con categorias en null no aportan candidatas ni rompen la página', function () {
    TicketSalesSnapshot::create([
        'fecha' => now()->format('Y-m-d'), 'producto_id' => 1, 'show_nombre' => 'Marte',
        'slot'  => 'X', 'hora' => '15:30', 'categorias' => null,
    ]);

    $this->actingAs($this->admin, 'user')->get(route('krayin.ticket-sales.configure'))->assertOk();
});

test('las candidatas no se repiten aunque aparezcan en varias filas', function () {
    foreach ([1, 2, 3] as $id) {
        TicketSalesSnapshot::create([
            'fecha' => now()->format('Y-m-d'), 'producto_id' => $id, 'show_nombre' => "S{$id}",
            'slot'  => "X{$id}", 'hora' => '15:30', 'categorias' => ['ticketera-2-0'],
        ]);
    }

    $html = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.configure'))
        ->assertOk()
        ->getContent();

    expect(substr_count($html, 'value="ticketera-2-0"'))->toBe(1);
});

test('una categoría guardada que ya no tiene funciones recientes sigue apareciendo marcada', function () {
    // Sin esto, guardar dos veces le borra al usuario un criterio que sigue
    // siendo válido: el domo puede pasar semanas sin funciones de una categoría,
    // y entonces esa categoría no está entre las candidatas.
    guardarCriterioDesdeConfigure(['titulo' => 'San Cosmos', 'categorias' => ['vieja-pero-valida']]);

    $html = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.configure'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('value="vieja-pero-valida"');
    expect($html)->toContain('sin funciones recientes');
});

test('el tablero enlaza a la configuración', function () {
    $html = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain(route('krayin.ticket-sales.configure'));
});
```

Las filas se crean con `TicketSalesSnapshot::create()` directo y no con el helper del test de pantalla: los helpers de ese archivo son suyos a propósito —«si este archivo se corre solo, los de allá no existen»— y esa decisión ya está escrita ahí. Copiar el patrón, no importar el helper.

- [ ] **Step 2: Correr y verificar que fallan**

Run: `php artisan test tests/Feature/TicketSales/TicketSalesConfigureTest.php`
Expected: FAIL, «Route [krayin.ticket-sales.configure] not defined».

- [ ] **Step 3: Agregar las rutas**

En `src/Http/routes.php`, dentro del grupo existente:

```php
    // Misma ACL y mismo middleware que el tablero: quien puede ver las ventas
    // puede configurar el criterio. Separar permisos acá sería inventar una
    // distinción que nadie pidió.
    Route::get('configure', [TicketSalesController::class, 'configure'])
        ->name('krayin.ticket-sales.configure');

    Route::post('configure', [TicketSalesController::class, 'storeConfiguration'])
        ->name('krayin.ticket-sales.configure.store');
```

- [ ] **Step 4: Escribir los dos métodos del controlador**

En `TicketSalesController`:

```php
    /** El tope de `core_config.value`, que es un VARCHAR(255) del core. */
    private const TOPE_DEL_VALOR = 255;

    public function configure()
    {
        $criterio = CriterioDeSanCosmos::desdeConfig();

        return view('krayin-ticket-sales::configure', [
            'titulo'      => $criterio->titulo(),
            'elegidas'    => $criterio->categorias(),
            'candidatas'  => $this->categoriasVistas(),
        ]);
    }

    public function storeConfiguration(Request $request)
    {
        $datos = $request->validate([
            'titulo'          => 'nullable|string|max:60',
            'categorias'      => 'nullable|array',
            'categorias.*'    => 'nullable|string',
            'categoria_nueva' => 'nullable|string',
        ]);

        // Se normaliza acá igual que en CriterioDeSanCosmos, y a propósito: lo
        // que se guarda queda limpio, así que un valor viejo mal escrito se
        // arregla con solo volver a guardar.
        $categorias = [];

        foreach (array_merge($datos['categorias'] ?? [], [$datos['categoria_nueva'] ?? '']) as $slug) {
            $slug = mb_strtolower(trim((string) $slug));

            if ($slug !== '' && ! in_array($slug, $categorias, true)) {
                $categorias[] = $slug;
            }
        }

        $valor = json_encode([
            'titulo'     => trim((string) ($datos['titulo'] ?? '')) ?: CriterioDeSanCosmos::TITULO_POR_DEFECTO,
            'categorias' => $categorias,
        ]);

        // `core_config.value` es un VARCHAR(255). MySQL en modo no estricto
        // truncaría en silencio, y un criterio truncado reparte mal sin avisar:
        // mejor rechazar y decirlo.
        if (strlen($valor) > self::TOPE_DEL_VALOR) {
            return redirect()->back()->withInput()->with(
                'error',
                'Son demasiadas categorías para guardar juntas (el límite es ' .
                self::TOPE_DEL_VALOR . ' caracteres). Quitar algunas.'
            );
        }

        \Webkul\Core\Models\CoreConfig::updateOrCreate(
            ['code' => CriterioDeSanCosmos::CLAVE],
            ['value' => $valor]
        );

        session()->flash('success', 'Se guardó el criterio de San Cosmos.');

        return redirect()->route('krayin.ticket-sales.configure');
    }

    /**
     * Las categorías que el servicio realmente reportó en la ventana de
     * retención, para ofrecerlas como checkbox. Así nadie tipea un slug a
     * ciegas ni adivina cómo se escribe.
     *
     * El "distinct" se arma en PHP: la columna es JSON y un DISTINCT de SQL
     * devolvería documentos enteros, no categorías.
     *
     * @return array<int, string>
     */
    private function categoriasVistas(): array
    {
        $vistas = TicketSalesSnapshot::whereNotNull('categorias')
            ->pluck('categorias')
            ->flatten()
            ->filter(fn ($slug) => is_string($slug) && trim($slug) !== '')
            ->map(fn (string $slug) => mb_strtolower(trim($slug)))
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $vistas;
    }
```

- [ ] **Step 5: Escribir la vista**

`src/Resources/views/configure.blade.php`, siguiendo el layout admin de Krayin como `KrayinFinancialReports/configure.blade.php`:

```blade
<x-admin::layouts>
    <x-slot:title>
        Configurar Entradas del día
    </x-slot>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">

    <style>
        .muci-titulo { font-family: 'Poppins', sans-serif; font-weight: 700; }
    </style>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap mb-5">
        <p class="muci-titulo text-2xl dark:text-white">Configurar Entradas del día</p>

        <div class="flex items-center gap-x-2.5">
            <a href="{{ route('krayin.ticket-sales.index') }}" class="transparent-button hover:bg-gray-200 dark:hover:bg-gray-800 dark:text-white">
                Cancelar
            </a>

            <button type="submit" form="criterio-form" class="primary-button">
                Guardar
            </button>
        </div>
    </div>

    @if (session('error'))
        <div class="mb-4 rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-700">
            {{ session('error') }}
        </div>
    @endif

    @if (session('success'))
        <div class="mb-4 rounded-lg border border-green-300 bg-green-50 p-3 text-sm text-green-700">
            {{ session('success') }}
        </div>
    @endif

    <form id="criterio-form" action="{{ route('krayin.ticket-sales.configure.store') }}" method="POST" class="flex flex-col gap-4">
        @csrf

        <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <label class="mb-1.5 block text-xs font-semibold text-gray-800 dark:text-white">
                Rótulo del panel izquierdo
            </label>

            <input
                type="text"
                name="titulo"
                value="{{ old('titulo', $titulo) }}"
                maxlength="60"
                class="w-full rounded-md border border-gray-200 px-3 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
            >

            <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                Es el título que se ve en la TV sobre las funciones del domo. En blanco, queda
                «{{ \CarlVallory\KrayinTicketSales\Support\CriterioDeSanCosmos::TITULO_POR_DEFECTO }}».
            </p>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="mb-1.5 text-base font-semibold text-gray-800 dark:text-white">
                Categorías que van al panel izquierdo
            </p>

            <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">
                Las funciones de estas categorías se muestran juntas y sin nombre, solo con su
                horario y sus ventas. El resto va al panel derecho con su nombre. Alcanza con que
                el producto tenga una de estas categorías.
            </p>

            @forelse ($candidatas as $slug)
                <label class="mb-2 flex items-center gap-2 text-sm dark:text-white">
                    <input
                        type="checkbox"
                        name="categorias[]"
                        value="{{ $slug }}"
                        @checked(in_array($slug, old('categorias', $elegidas), true))
                    >
                    <span>{{ $slug }}</span>
                </label>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Todavía no se sincronizó ninguna categoría. Van a aparecer acá después de la
                    próxima corrida de <code>ticket-sales:sync</code> con un servicio que las mande.
                </p>
            @endforelse

            {{-- Las que están guardadas pero ya no aparecen en la ventana de
                 retención no se pueden perder por no estar en la lista de
                 candidatas: se muestran igual, marcadas. --}}
            @foreach (array_diff($elegidas, $candidatas) as $slug)
                <label class="mb-2 flex items-center gap-2 text-sm dark:text-white">
                    <input type="checkbox" name="categorias[]" value="{{ $slug }}" checked>
                    <span>{{ $slug }} <em class="text-xs text-gray-500">(guardada, sin funciones recientes)</em></span>
                </label>
            @endforeach

            <div class="mt-4">
                <label class="mb-1.5 block text-xs font-semibold text-gray-800 dark:text-white">
                    Agregar una categoría que todavía no apareció
                </label>

                <input
                    type="text"
                    name="categoria_nueva"
                    value="{{ old('categoria_nueva') }}"
                    placeholder="ticketera-2-0"
                    class="w-full rounded-md border border-gray-200 px-3 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                >

                <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                    Es el <em>slug</em> de la categoría de WooCommerce, no su nombre: renombrar la
                    categoría no le cambia el slug.
                </p>
            </div>
        </div>
    </form>
</x-admin::layouts>
```

- [ ] **Step 6: Enlazar desde el tablero**

En `index.blade.php`, junto al botón «Ver en pantalla»:

```blade
            <a
                href="{{ route('krayin.ticket-sales.configure') }}"
                class="muci-titulo rounded-md px-3 py-1.5 text-sm text-white"
                style="background: #F37043;"
            >
                Configurar
            </a>
```

- [ ] **Step 7: Correr y verificar que pasan**

Run: `php artisan test tests/Feature/TicketSales/TicketSalesConfigureTest.php`
Expected: PASS, 11 tests.

- [ ] **Step 8: Correr TODA la suite del paquete**

Run: `php artisan test tests/Unit/TicketSales tests/Feature/TicketSales`
Expected: PASS, todo.

- [ ] **Step 9: Usar la página de verdad**

Entrar a `/admin/ticket-sales/configure`, destildar todo, guardar, y confirmar que la pantalla manda todo a la derecha con nombres. Volver a tildar `ticketera-2-0`, guardar, y confirmar que la izquierda se puebla. Cambiar el rótulo y verlo en la TV. Es la prueba de que el criterio es editable de verdad, que es lo que se pidió.

- [ ] **Step 10: Mutar y verificar**

| Mutación | Test que tiene que morir |
|---|---|
| Sacar el chequeo de `TOPE_DEL_VALOR` | «un criterio que no entra en la columna se rechaza con mensaje» |
| Sacar el `unique()` de `categoriasVistas()` | «las candidatas no se repiten» |
| Sacar el `whereNotNull('categorias')` | «las filas con categorias en null no aportan candidatas» (si sobrevive, el `filter` lo está tapando: **anotarlo**, es una mutación equivalente) |
| No procesar `categoria_nueva` | «el campo libre agrega una categoría que todavía no apareció» |
| Sacar el `@foreach (array_diff(...))` de la vista | «una categoría guardada que ya no tiene funciones recientes sigue apareciendo marcada» |

- [ ] **Step 11: Commit**

```bash
git add packages/CarlVallory/KrayinTicketSales/src/Http packages/CarlVallory/KrayinTicketSales/src/Resources/views tests/Feature/TicketSales/TicketSalesConfigureTest.php
git commit -m "feat(ticket-sales): pagina para editar el criterio de San Cosmos

Los candidatos salen de las categorias que el servicio ya reporto, para
que nadie tipee un slug a ciegas. El tope de 255 de core_config.value se
valida acá: MySQL no estricto truncaria en silencio y el panel repartiria
mal sin avisar."
```

---

## Cierre

- [ ] **Correr la prueba de aceptación contra el servicio de verdad**

```bash
/usr/bin/php8.2 artisan ticket-sales:sync --fecha=2026-08-07
```

Tiene que seguir informando `11 funciones | 26 entradas | 0 avisos`. Los números de venta se mueven: contrastar contra el servicio en el momento, no contra este plan. **7 funciones** sería el servicio ignorando la forma B del JSON, **6** la programación sin cruzarse con las ventas, y las **5 funciones sin ventas** tienen que aparecer, en cero.

- [ ] **Verificar el degradado del orden de deploy**

Con el CRM nuevo y el servicio viejo (sin `categorias`), la pantalla tiene que verse **como antes del cambio**: todo a la derecha con nombres, y el cartel `data-sin-domo` a la izquierda. Se puede simular apuntando `FOOEVENTS_SERVICE_URL` a un stub que no mande el campo, o borrando la columna a mano en la base de desarrollo:

```sql
UPDATE muci_ticket_sales_snapshot SET categorias = NULL;
```

- [ ] **Actualizar la documentación del paquete**

En `packages/CarlVallory/KrayinTicketSales/CLAUDE.md` y su `README.md`: que el panel izquierdo se reparte por categoría y no por ventas, que el criterio vive en `core_config` y se edita en `/admin/ticket-sales/configure`, y que `categorias` es opcional en el contrato del servicio. En `~/code/servicio-fooevents`, documentar el campo nuevo en el contrato.

- [ ] **Anotar acá abajo lo que el plan no previó**

## Desviaciones encontradas al ejecutar

### Task 1

- **`php artisan test` no corre el servicio en local.** El `php` del sistema es 8.3.33 y el
  repo pide `>= 8.4.1`. Va con **`php8.4 artisan test`** (`/usr/bin/php8.4`, 8.4.24). La
  constante global del plan decía `php artisan test` y estaba mal.
- **La Task 1 dejaba la suite del servicio en rojo.** El agregador ya emite `categorias` y la
  fixture canónica todavía no lo tenía, así que `RespuestaCanonicaTest` fallaba en el commit
  intermedio. **Se movió la actualización de la fixture a la Task 1**, con `"categorias": []`
  —que es lo que el servicio produce en ese punto—, y la Task 3 la sube al valor real. Las dos
  copias de la fixture se editaron juntas y siguen byte-idénticas.
- Por lo anterior, la mutación «mover `categorias` al final del array» **sí muere** en la
  Task 1 (la mata `RespuestaCanonicaTest` por orden de claves). El plan la daba por descubierta
  recién en la Task 3.

### Task 2

- **El tercer test del plan usaba `->or->toBe([])`, que no existe en Pest.** Reescrito como un
  `foreach` que afirma que no vuelve ningún producto que no se pidió.
- **Las dos mutaciones que el plan marcaba como "van a sobrevivir" se cubrieron de entrada**, en
  vez de dejarlas anotadas: «todo lo que devuelve es de la taxonomía product_cat» (con un
  conjunto de referencia que sale de una consulta distinta a la del método, para que el test no
  se pruebe a sí mismo) y «devuelve slugs y no nombres» (sin espacios, sin mayúsculas).
- **La query quedó verificada contra la base `muci` de producción** (SSH `anthropic_readonly`,
  solo lectura, 2026-08-26): devuelve `producto_id → slugs`, ordenada, solo `product_cat`.
  Los 5 tests Pest siguen salteándose en local por diseño del repo del servicio.

#### Los slugs del spec no existen, y la categoría del domo está vacía

Lo que devolvió la consulta de `product_cat`:

| Nombre | Slug real | Productos |
|---|---|---|
| Ticketera SC 2.0 | **`san-cosmos`** | 0 |
| Entradas San Cosmos | **`entrada-sancosmos`** | 0 |
| Entradas Especiales | `entradas-especiales` | 0 |
| Eventos | `eventos` | 17 |

Ni `ticketera-2-0` ni `entrada-san-cosmos` —los dos que el spec asumía— existen en la base.

Y el problema de fondo: **los 13 productos `dateslot` del domo están todos en `eventos`**, que
además contiene al menos uno que no es del domo («Eclipse Lunar en la Costanera de Asunción»,
al aire libre). La premisa del §2 del spec —que el domo comparte una categoría propia— es
cierta como intención y falsa en los datos: la categoría existe y nadie la aplicó.

**Decisión de Carlos (2026-08-26): se categoriza en WooCommerce.** El código no cambia. La
Task 5 siembra `san-cosmos` y `entrada-sancosmos`, y el panel izquierdo se puebla solo en el
sync siguiente a que los productos queden categorizados. Hasta entonces muestra «hoy no hay
funciones», que es el degradado previsto en el §6.4.

**Productos a asignar a «Ticketera SC 2.0» en WooCommerce** (hoy en `eventos`):

| ID | Producto | ¿Domo? |
|---|---|---|
| 192862 | El Sistema Solar Expandido | sí |
| 194055 | Marte: La travesía definitiva | sí |
| 194154 | Misterios de tu Cerebro | sí |
| 193817 | Historias Estelares: De estrellas a supernovas | sí |
| 198951 | San Cosmos: una experiencia adaptada | sí |
| 193653 | Entrada a San Cosmos | sí |
| 196315 | Entrada a San Cosmos - hospitalidad | sí |
| 198093 | Exploradores de Exoplanetas | sí |
| 194339 | Las Constelaciones y el Zodíaco: El misterio de los signos | sí |
| 194099 | Mundos en órbita: Las Lunas del Sistema Solar | sí |
| 194228 | El Sistema Solar - La hora tranqui | sí |
| 193902 | Dinosaurios - Una historia de supervivencia | **confirmar** |
| 197624 | Eclipse Lunar en la Costanera de Asunción | **no** — es al aire libre |

Los dos últimos son los únicos que no salen de lo que se pidió: «Dinosaurios» tiene pinta de
proyección de domo pero no estaba en la lista original, y el «Eclipse Lunar» es en la costanera
y tiene que quedarse a la derecha con su nombre.

### Task 3

- **Eran 8 bloques de mock, no 9.** El del test «503 si la base muci no responde» hace que
  `productosConBookings` lance, así que nunca llega a `categoriasPorProducto` y no necesita
  declararlo. Los 8 se inyectaron por script anclando en la línea de `lineasDe`.
- **La fixture canónica quedó con los slugs REALES** de `Entrada Bioestanque`
  (`["entradas", "entradas-cielo-abierto"]`) en vez del `["entrada-bioestanque"]` inventado en
  el plan. Sale gratis y deja la fixture honesta.
- **Un test pasaba por casualidad, y la mutación lo encontró.** «ids solo de los tickets»
  sobrevivió: en «cada función sale con sus categorías» el mock devolvía el mapa **sin mirar el
  argumento**, así que el test pasaba con cualquier lista de IDs. Se le agregó `->with([777])`.
  Con eso la mutación muere. Es el caso exacto que la disciplina busca: no era una mutación
  equivalente, era un test que no fijaba lo que decía fijar.

### Tasks 4 y 5

- `packages/CarlVallory` está en el `.gitignore` del CRM: **el paquete es su propio repo**. Todo
  lo de `src/` va ahí y solo los tests van a `laravel-crm`. El paquete estaba en `main`, así que
  se abrió la rama **`feat/san-cosmos-panel-izquierdo`** (el trabajo anterior del tablero sí
  había ido directo a `main`). **Para deployar hay que mergearla**: `composer` con `@dev` tira de
  la rama por defecto.
- El `exists()` de la siembra se verificó **borrando el registro de `migrations` con la fila
  presente**, que es la única forma de hacer correr `up()` dos veces. Respetó el valor editado.
- La mutación «`down()` vacío» sobrevive a los tests, como el plan anticipaba, y se ve a mano:
  la fila queda después del rollback. **Deja la base de desarrollo sucia** —el guard después
  respeta el valor de la mutación anterior—, así que hay que borrar fila y registro de migración
  antes de seguir.

### Task 7

- **Otro test que pasaba por casualidad, encontrado por una mutación.** «las otras formas
  malformadas» era un `foreach` con `Http::fake()` adentro, y **`Http::fake()` no reemplaza el
  stub anterior**: las 4 iteraciones recibían la respuesta de la primera forma, así que 3 de las
  4 nunca se probaban. Se pasó a **dataset de Pest** (4 tests separados, cada uno con su fake).
  Con eso la mutación «sacar `array_is_list`» muere; antes sobrevivía.
- El resto de las 6 mutaciones de la task mueren como el plan decía.

### Task 9

- La reescritura conservó **textual** el bloque de `nombreCorto` (líneas 126-165 del archivo
  viejo). El resto del test se reescribió.
- **Dos comentarios del archivo quedaban mintiendo y se arreglaron:** el de `nombreCorto` hablaba
  del «desempate del destacado», y había un **docblock huérfano** sobre la cascada colgado arriba
  de `fusionarPorHora()` —que no es su método— y que además ya era falso («el panel grande lo
  decidiría el orden de llegada»). Se movió a `criterio()` y dice lo que la cascada hace ahora:
  ordenar el panel derecho, no elegirlo.
- **MUT «`$suyas === null` en vez de `! is_array`» sobrevivía** y no era equivalente en sentido
  estricto: con un `categorias` string el resultado es el mismo pero PHP tira un warning al
  hacer `foreach` sobre él, y `phpunit.xml` **no** tiene `failOnWarning`. Se agregó un test que
  instala un `set_error_handler` y convierte cualquier aviso de PHP en excepción. Con eso muere.
- **MUT «sacar el corte de `$categoriasSanCosmos === []`» sobrevive y ES equivalente**, como el
  plan anticipaba: `in_array($x, [], true)` es siempre `false`, así que el corte es claridad y no
  comportamiento. No se le agrega test.
- Se corrió una **mutación de control**: agregar una clave extra a los grupos de `especiales`.
  Sobrevive, y tiene que sobrevivir — el test que prohíbe claves extra es solo del panel
  izquierdo, que es donde está el pedido. Confirma que ese test no es un congelamiento ciego de
  la estructura.

### Task 10

- **Tercer test que pasaba por casualidad, encontrado por una mutación.** «el rótulo del panel
  izquierdo sale del config» hacía `assertSee('Domo MuCi')`, y como el test sembraba **solo** una
  función del domo, el panel derecho mostraba «Hoy solo hay funciones de Domo MuCi»: el rótulo
  aparecía por ahí y no por el `<h1>`. Con el título cableado el test pasaba igual. Se le agregó
  una actividad especial y el assert va contra el `<h1>` exacto.
- Los tests que hablaban de «destacado» se adaptaron; los de nombres largos **se mudaron al panel
  derecho**, que es donde `nombreCorto()` sigue vivo. Los de dato viejo, histórico, `OTRO_DIA`,
  chrome del CRM y tablero de admin no se tocaron.
- **Defecto visual que ningún test detecta, encontrado mirando la pantalla:** el cartel del panel
  vacío reusaba `.aviso__detalle` y quedaba en 1rem, peso 400, pegado arriba a la izquierda de un
  panel de 60% — ilegible desde el hall. Se le dio clase propia (`.panel__vacio`: centrada, peso
  700, `clamp(1.25rem, 2.6vw, 3rem)`). El Step 11 del plan pedía exactamente este chequeo.
- Verificado en navegador a 1920×1080 y 1080×1920: cero scroll en las dos, 8 funciones del domo
  entran 4×2 con la cifra en 107px, y el cartel centrado a 28px en vertical.

### Task 11

- **MUT «sin `whereNotNull`» sobrevive y es equivalente**, como el plan anticipaba: el `filter`
  de `is_string` ya descarta los `null` que deja el `flatten`.
- **MUT «sin normalizar al guardar» sobrevivía y NO era equivalente.** `CriterioDeSanCosmos`
  normaliza al leer, así que un valor sucio se ve bien de todos modos — pero el tope de 255 se
  mide sobre el JSON **guardado**, donde los espacios cuentan. Se agregó un test que mira el
  `value` crudo de `core_config`. Con eso muere.
- Ciclo completo ejercitado a mano: destildar todo manda las 2 funciones a la derecha con nombre
  y prende `data-sin-domo`; volver a tildar devuelve la del domo a la izquierda; el rótulo cambia
  a «Domo del MuCi»; y 30 categorías se rechazan con mensaje **sin perder el criterio anterior**.

## Estado al cerrar (2026-08-26)

**Las 11 tasks cerradas.** `194 passed` en el CRM, `63 passed / 9 skipped` en el servicio (los 9
son los del repositorio, que necesitan la base `muci`).

Ramas, sin pushear: `feat/categorias-de-producto` en el servicio,
`feat/san-cosmos-panel-izquierdo` en el paquete, `feat/ticket-sales-dashboard` en el CRM.

### Lo que falta y por qué

1. **La prueba de aceptación no se corrió.** Necesita el servicio escuchando en
   `127.0.0.1:8081`, que solo existe en el servidor porque es el único con la credencial de
   `muci`. Es un paso de deploy, no de desarrollo. **No darla por pasada.**
2. **Los 5 tests nuevos del repositorio del servicio nunca corrieron contra datos reales** —se
   saltean sin la base `muci`. La consulta sí se verificó a mano contra producción.
3. **Categorizar los 13 productos del domo en WooCommerce.** Hasta que eso pase, el panel
   izquierdo muestra «Hoy no hay funciones de San Cosmos» en producción. La lista está en la
   sección de la Task 2. Y hay dos por confirmar: «Dinosaurios» (¿es del domo?) y «Eclipse Lunar
   en la Costanera», que **no** lo es.
4. **Mergear la rama del paquete a `main`** antes de deployar: `composer` con `@dev` tira de la
   rama por defecto.

