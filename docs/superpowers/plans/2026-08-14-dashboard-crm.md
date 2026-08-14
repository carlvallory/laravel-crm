# Dashboard de entradas vendidas — lado del CRM Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que el paquete `KrayinTicketSales` deje de hablar con la base `muci` y pase a consumir el servicio intermedio, guarde el resultado en un snapshot local y lo muestre en un tablero del CRM con los cinco estados de antigüedad.

**Architecture:** El paquete pierde su conexión a `muci` y gana un cliente HTTP contra `http://127.0.0.1:8081/v1/funciones`, que ya corre en la misma máquina. Un comando cada 5 minutos pide el día, valida la forma de la respuesta y reemplaza el snapshot dentro de una transacción; ninguna falla toca el snapshot anterior. La vista lee únicamente las tablas locales y decide qué mostrar con una clase pura de cinco estados.

**Tech Stack:** PHP 8.2 (prod del CRM) / 8.3 (dev), Laravel 10, Krayin CRM, Pest, MySQL/MariaDB.

**Spec:**
- `docs/superpowers/specs/2026-08-12-servicio-fooevents-design.md` — el contrato, los errores y los cinco estados. Es el documento que manda.
- `docs/superpowers/specs/2026-08-07-dashboard-entradas-vendidas-design.md` — el objetivo, la zona horaria, el fuera de alcance y la verificación de referencia. Sus §4, §5.2 y §11 están **superadas** por el documento anterior.

**Plan hermano ya ejecutado:** `docs/superpowers/plans/2026-08-12-servicio-fooevents.md`. Sus siete secciones `Desviaciones encontradas al ejecutar` son el registro de dónde el plan y la realidad no coincidieron. Leerlas antes de empezar.

## Global Constraints

- **No modificar el core:** nada en `packages/Webkul/*`, `vendor/*`, ni `app/Console/Kernel.php`. Todo vive en `packages/CarlVallory/KrayinTicketSales`. El scheduler se registra **desde el paquete** vía `$this->app->booted()`.
- **Reversible por desinstalación:** toda migración con `down()` completo.
- **El CRM no abre ninguna conexión a `muci`.** Esa credencial la tiene solo el servicio. Después de la Task 1 no debe quedar en el paquete ni una referencia a `WC_DB_*`, `anthropic_readonly`, `wpzv_` ni `database.connections.woocommerce`.
- **`CURDATE()` y `NOW()` siguen prohibidos.** "Hoy" se calcula con `BusinessDay` en `America/Asuncion` y viaja como parámetro `fecha` al servicio. El servidor corre en UTC.
- **El CRM no debe fallar ante un `tipo` de aviso que no conozca.** Los códigos son cinco (`json_ilegible`, `fecha_no_parseable`, `estado_desconocido`, `prorrateo_ambiguo`, `linea_faltante`), agregar más es aditivo y **ya pasó una vez**. Se validan la forma (`tipo` y `detalle` presentes) y nunca el vocabulario.
- **Ninguna falla toca el snapshot anterior** (§4.2 del spec). Ni un 503, ni un 401, ni un timeout, ni una respuesta con forma inesperada.
- **Sin datos personales:** el snapshot no almacena ni la vista muestra nombres, cédulas, emails ni teléfonos.
- **Mutaciones antes de cerrar una task:** disciplina de la casa. Antes de commitear se muta cada pieza nueva y se verifica que los tests que deberían morir, mueran. Cada task tiene su lista concreta. Cuando una mutación sobrevive, casi nunca es equivalente: es un test que pasa por casualidad, y el test que lo cubre se anota en `Desviaciones encontradas al ejecutar`.
- **`git add` explícito:** nunca `git add -A` en `laravel-crm` (se cuela un gitlink de `packages/Vallory/KrayinFormatter`).
- **Estándar visual MuCi:** paleta `#F17DB1 #00B26B #000000 #6950A1 #F37043` y **Poppins Bold** en títulos. El layout admin de Krayin carga **Inter**, no Poppins: la vista tiene que traer la fuente ella misma.
- **Binarios:** en desarrollo, `php` (8.3) y `composer` a secas — el `/usr/bin/php8.4` del servicio **no aplica acá**. En el servidor el CRM sigue en **`/usr/bin/php8.2`**, que es su pool; el upgrade a 8.4 todavía no pasó.
- **Naming:** namespace `CarlVallory\KrayinTicketSales\`; paquete composer `carlvallory/krayin-ticket-sales`, repo privado `carlvallory/krayin-ticket-sales`.

## Decisiones de este plan que el spec no fijaba

Tres, tomadas acá y justificadas, porque el spec dejaba la forma abierta:

1. **Dos tablas, no una.** Una cabecera `muci_ticket_sales_sync` (una fila por fecha, con `generado_en`, `avisos` y `synced_at`) además de las filas de funciones. Lo fuerza el §4.3: con una sola tabla de funciones, "hoy no hay funciones" (estado 5) y "nunca corrió el sync" (estado 4) son ambos *cero filas* y no se pueden distinguir, y el spec exige explícitamente que se vean distinto. La cabecera es además dónde viven los `avisos`, que son del día y no de una función.
2. **Columnas en español, 1:1 con el contrato,** salvo `show` → `show_nombre`, porque `SHOW` es palabra reservada de MySQL. La correspondencia literal es defensa barata: el único bug real del plan del servicio fue un nombre de campo que no coincidía (`recaudacion_neto` contra `recaudacion_neta`), y no falló, calló.
3. **La recaudación se guarda en enteros.** El contrato dice "entera, en guaraníes"; un `decimal(14,2)` invitaría a un redondeo que el prorrateo por resto mayor ya resolvió aguas arriba.

## File Structure

| Archivo | Responsabilidad |
|---|---|
| `src/Config/ticket-sales.php` | Zona horaria, umbral de dato viejo, URL/token/timeouts del servicio, retención |
| `src/Support/BusinessDay.php` | "Hoy" en `America/Asuncion`. Pura. **Ya existe, no se toca** |
| `src/Support/EstadoDelTablero.php` | Los cinco estados del §4.3. Pura |
| `src/Services/FooEventsServiceClient.php` | Un `GET` al servicio, con reintento y validación de forma |
| `src/Services/ErrorDelServicio.php` | La falla del servicio, con el nivel de log que le corresponde |
| `src/Models/TicketSalesSnapshot.php` | Filas de funciones |
| `src/Models/TicketSalesSync.php` | Cabecera del sync: fecha, avisos, antigüedad |
| `src/Database/Migrations/2026_08_14_120000_create_muci_ticket_sales_tables.php` | Las dos tablas |
| `src/Console/SyncTicketSalesCommand.php` | Orquesta: pide, valida, escribe atómico, purga |
| `src/Http/Controllers/TicketSalesController.php` | Lee snapshot, arma totales y estado |
| `src/Http/routes.php` | Ruta admin |
| `src/Config/menu.php` · `src/Config/acl.php` | Menú y permiso |
| `src/Resources/views/index.blade.php` | La vista |
| `src/Providers/KrayinTicketSalesServiceProvider.php` | Registro de todo lo anterior |

Se **borran**: `src/Support/BookingsOptionsParser.php`, `src/Support/SpanishDateParser.php`, `registerWoocommerceConnection()`, `tests/Unit/TicketSales/BookingsOptionsParserTest.php`, `tests/Unit/TicketSales/SpanishDateParserTest.php`, `tests/Feature/TicketSales/WoocommerceConnectionTest.php` y `tests/Fixtures/fooevents/`.

---

## Desviaciones encontradas al ejecutar (Task 1, 2026-08-14)

- **El `git add` del Step 10 no puede ser uno solo: son dos repos.**
  `/packages/CarlVallory` está gitignoreado en el CRM (`.gitignore:39`), así que
  `git add packages/CarlVallory/...` desde la raíz del CRM no registra nada. Lo
  del paquete (`src/`, `composer.json`, `CLAUDE.md`, `README.md`) se commitea en
  el repo propio del paquete; lo de `tests/`, `.env.example` y `docs/` en el del
  CRM. **Aplica al Step de commit de las siete tasks**, no solo a esta.
- **El bloque del `.env` traía cuatro líneas de comentario además de las seis
  variables.** El Step 5 manda borrar solo las seis; borrar solo eso deja un
  comentario huérfano que describe la conexión a `muci` —justo la referencia que
  la constraint prohíbe—. Se borran las líneas 24–34 completas en ambos archivos.
  Ojo: el comentario **no es el mismo texto** en `.env` y en `.env.example`, pero
  cae en las mismas líneas.
- **La Mutación 3 sobrevivió, como el plan anticipó.** Borrar el
  `singleton(BusinessDay::class, ...)` deja la suite en verde porque los 6 tests
  hacen `new BusinessDay()` directo. No se escribe test acá: lo cubre la Task 6,
  que resuelve por el contenedor. Queda anotado para que no se lea como agujero
  nuevo.
- **Los residuos de `anthropic_readonly` y `wpzv_` en `CLAUDE.md` y `README.md`
  del paquete sobreviven a esta task por diseño.** Los borra la Task 7 (Steps 1 y
  2). La constraint "ni una referencia" se cumple en el código desde acá y en la
  documentación desde la Task 7.
- **El Step 8 no tiene con qué comparar si no se corrió antes.** Pide "sin fallas
  nuevas respecto de antes de esta task" pero ningún step anterior guarda ese
  número. Quedó en **58 passed, 0 failed, 0 skipped**, que hace la comparación
  innecesaria; si alguna vez diera rojo, hay que sacar el baseline con `git
  stash`.

---

## Desviaciones encontradas al ejecutar (Task 2, 2026-08-14)

- **La Mutación 8 sobrevivió, y encontró un test que pasaba por casualidad.** El
  plan dice que cambiar el guard `! $respuesta->successful()` por
  `status() !== 404` debe matar `500 se reintenta igual que el 503`. No lo mata:
  mata `503 se reintenta una vez y después se rinde`. La razón es que con el guard
  mutado el 500 no se clasifica —cae hasta `validar()`, que se ahoga con el cuerpo
  del error y lanza `respuestaInvalida`—, **y `respuestaInvalida` también es un
  `ErrorDelServicio`**, así que el `toThrow(ErrorDelServicio::class)` del test del
  500 seguía verde. El del 503 murió solo porque además asserta `nivel()`.
  Importa: el nivel es el del log y el plan lo declara no cosmético. Un 500 que se
  loguea `error` en vez de `warning` es justo el ruido que la decisión de niveles
  quiso evitar, y el mensaje "falta la clave `fecha`" manda a buscar un bug de
  contrato cuando lo que pasa es que el servicio está caído.
  **Test reforzado** (no lo pedía el plan): el del 500 ahora fija la
  clasificación, no solo que algo lanzó — `nivel()` en `'warning'` y el mensaje
  conteniendo `'500'`. Con eso, la Mutación 8 mata **dos** tests.
  Moraleja para las tasks 4 a 6: **`toThrow(ClaseDeExcepción::class)` a secas no
  vale cuando la clase tiene varias fábricas.** Hay que assertar cuál.
- **Las otras siete mutaciones murieron**, cada una incluyendo el test que el plan
  predecía. Tres mataron más de uno: la de `$intentos >= 1` mata cuatro y la de
  `$intentos >= 3` mata tres, porque el conteo de requests lo assertan varios.
- **Los 16 tests del Step 6 fallan por el motivo que el plan dice** (`Target class
  [...FooEventsServiceClient] does not exist`), pero el error sale de la línea 17
  del test —el `app(...)` del `beforeEach`—, no de la llamada a `funcionesDe()`.
  No cambia nada; conviene saberlo para no perseguir el `beforeEach`.
- **El fixture del servicio coincide con lo que los tests esperan** (producto
  192637, 2 entradas, 70000 bruto). Al 2026-08-14 los dos repos están en sincronía
  y la copia a mano del Step 1 no hizo falta ajustarla.

---

## Desviaciones encontradas al ejecutar (Task 3, 2026-08-14)

- **El `migrate:fresh` del Step 7 no se usó: borra la base de desarrollo entera.**
  Las mutaciones 1 y 3 son sobre el esquema y necesitan recrearlo, pero
  `migrate:fresh` tira **todas** las tablas de `krayin` (usuarios, leads, todo) y
  re-corre todas las migraciones. `php artisan migrate:rollback --step=1 &&
  php artisan migrate` da el mismo esquema mutado tocando **solo estas dos
  tablas**. Usar eso, acá y en cualquier task que mute una migración.
- **La Mutación 4 sobrevivió, y encontró otro test que pasaba por casualidad.** El
  plan dice que quitar `'recaudacion_neta' => 'integer'` de `$casts` muere porque
  "el `toBe(63636)` es estricto y una cadena no pasa". No muere: **PDO ya devuelve
  entero nativo para una columna INT, con cast o sin él.** Medido con la sonda:
  leído de MySQL da `int` en los dos casos; el cast solo se nota cuando el valor
  llega **como cadena desde PHP** (`int` con cast, `string` sin él). El test del
  plan persiste y vuelve a leer, así que hace coincidir "el modelo garantiza
  entero" con "el driver casualmente devuelve entero" — el proxy más simple.
  **Test agregado** (no lo pedía el plan): `los montos y los conteos son enteros
  porque el modelo lo garantiza, no porque el driver los devuelva así`, que
  construye el modelo con todos los numéricos como cadena y **sin ida a la base**.
  Mata la Mutación 4 y también la de cualquier otro cast entero que falte.
  Importa por la decisión 3 del plan (recaudación entera) y porque la Task 6 suma
  estas columnas: una cadena colándose ahí es la clase de bug que no falla, calla.
- **Por eso la Task 3 cierra con 6 tests, no 5,** y el total del plan pasa de 62 a
  **63**.
- **La Mutación 5 deja el esquema a medias, y hay que repararlo a mano.** Es lo que
  demuestra, pero conviene saberlo: el `down()` mutilado borra solo `snapshot`, el
  rollback igual borra la fila de `migrations`, y el `migrate` siguiente muere con
  `1050 Table 'muci_ticket_sales_sync' already exists`. Queda `sync` huérfana y la
  migración sin registrar. Se repara con `Schema::dropIfExists('muci_ticket_sales_sync')`
  y `php artisan migrate`. Verificar que la tabla está vacía antes de borrarla.
- **La Mutación 2 mata tres tests, no uno.** Sin el cast `array`, `avisos` sale como
  cadena JSON y se caen también `la cabecera admite avisos vacíos` y `no puede
  haber dos cabeceras para la misma fecha`.
- **Los tests corren contra la base `krayin` de desarrollo, no sobre sqlite.** El
  `phpunit.xml` del CRM no fija `DB_CONNECTION`, así que usa el `.env`; por eso el
  Step 5 tiene que correr `php artisan migrate` antes de los tests, y por eso los
  tests usan `DatabaseTransactions`. No confundir con el servicio, que sí corre
  sobre sqlite `:memory:`.

---

## Desviaciones encontradas al ejecutar (Task 4, 2026-08-14)

- **Dos tests del plan no compilaban con la realidad de `Http::fake`, y fallaban
  en el Step 5.** `un 503 falla el comando...` y `una respuesta malformada...`
  llaman `Http::fake()` **dos veces** en el mismo test, esperando que la segunda
  reemplace a la primera. **No la reemplaza: las acumula y gana el primer stub que
  matchea** (medido con una sonda). O sea que el segundo `artisan` recibía otra vez
  el 200 bueno y salía exitoso. Se reescribieron con **una sola `Http::fake` con
  `Http::sequence()`**, como ya hacen los tests de la Task 2. Ojo con el conteo de
  la secuencia: el del 503 necesita **tres** respuestas (200, 503, 503) porque el
  cliente reintenta; el de la malformada solo dos, porque un 200 no se reintenta.
  Vale para cualquier test futuro que haga dos syncs seguidos.
- **Siete de los nueve mapeos de `escribir()` se podían intercambiar con la suite
  en verde.** El barrido campo por campo que el Step 7 recomienda —y que vale cada
  minuto— dio: solo `producto_id` y `cupos_habilitados` estaban cubiertos.
  Sobrevivían `show_nombre <- slot`, `slot <- show`, `hora <- null`,
  `entradas_vendidas <- entradas_reagendadas` y su inverso, y **`recaudacion_neta
  <- recaudacion_bruta` y su inverso** — el tablero habría mostrado plata
  equivocada sin que nada se quejara. El plan solo anticipaba el de `show_nombre`.
  **Test agregado** (no lo pedía el plan): `cada campo del contrato aterriza en su
  columna y no en la de al lado`, con los nueve valores distintos entre sí —
  `entradas_reagendadas` en **5**, no en 0, que es lo que separa los dos conteos.
  Mata los nueve. Es el test que le faltaba al plan del servicio.
- **Por eso la Task 4 cierra con 12 tests, no 11,** y el total del plan queda en
  **64**.
- **La Mutación 5 (sin `DB::transaction`) sobrevivió, como el plan anticipó.** Se
  deja: matarla pide simular una falla a mitad de escritura. La atomicidad la
  sostiene la revisión de código.
- **La Mutación 7 (`'<'` por `'<='`) también sobrevivió, como el plan anticipó.** No
  se persigue: es un borde de un día en una purga de retención.
- **La Mutación 3 (SUCCESS en el `catch`) mata tres tests, no dos:** se lleva
  también `un 401 se loguea como error, no como warning`, porque ese test asserta
  `assertFailed()`.
- **Al mutar, cuidado con los `perl -0pi -e` que anclan en texto acentuado**
  ("Falló la sincronización"): no matchean y el comando sale en silencio sin
  aplicar la mutación, que se lee igual que "sobrevivió". Anclar en texto sin
  acentos y **verificar con un `grep` que la mutación entró** antes de creerle al
  resultado.

---

## Desviaciones encontradas al ejecutar (Task 5, 2026-08-14)

- **La Mutación 6 sobrevivió: el test del reloj corrido usaba un desfase
  demasiado chico.** Sacar el `false` de `diffInMinutes($ahora, false)` no mataba
  `un synced_at en el futuro no se toma como viejo`, porque el test usa **+5
  minutos** y en Carbon 2.73 `diffInMinutes()` sin el `false` devuelve el valor
  **absoluto**: da 5, que igual queda por debajo del umbral de 15. Los dos
  criterios coinciden ahí y se separan solo con un desfase **mayor que el umbral**
  — con signo, +40 minutos da -40 (no es viejo); sin signo, 40 (viejo), y el
  tablero pondría la banda de dato viejo sobre un dato recién traído. Verificado
  con una sonda: `nesbot/carbon 2.73.0`, `diffInMinutes($a)` = 5 y
  `diffInMinutes($a, false)` = -5.
  **Test reforzado** (mismo test, no uno nuevo): ahora asserta primero el desfase
  de **40** minutos y después el de 5. Mata la Mutación 6.
  Ojo para cuando el CRM suba de Carbon: **en Carbon 3 el default se invierte** y
  `diffInMinutes()` pasa a devolver un float con signo. El `false` explícito sigue
  siendo correcto en las dos versiones; el que hay que releer ese día es el
  comentario del código, no la condición.
- **Las otras siete murieron**, cada una incluyendo el test que el plan predecía.
  Tres se llevan más de uno: M1 y M3 matan tres cada una, porque los tres tests de
  precedencia de `OTRO_DIA` se apoyan en el mismo guard.
- **La Task 5 cierra con los 12 tests del plan.** No se agregó ninguno: el arreglo
  fue fortalecer uno. El total del plan sigue en **64**.

---

## Desviaciones encontradas al ejecutar (Task 6, 2026-08-14)

- **El punto de parada no se activó:** la base `krayin` local tiene el usuario id 1
  (`Vallory <carlos@muci.org>`), así que `getDefaultAdmin()` devuelve admin y
  ninguno de los tests salió `skipped`.
- **El plan dice 12 tests del tablero pero su propio código trae 11.** Error de
  conteo, no un test faltante. Con los tres agregados abajo, el archivo cierra en
  **14**.
- **Tres mutaciones sobrevivieron. Las tres eran tests que pasaban por
  casualidad:**
  1. **El filtro del controlador no estaba fijado por nada.** La Mutación 1
     (`where('fecha', $hoy)` -> `where('fecha', $fechaDelSync)`) sobrevive en su
     versión fiel, porque lo que salva al `estado 3` es **solo la rama `OTRO_DIA`
     de la vista**: aunque el controlador cargue las funciones de ayer, la vista no
     dibuja la tabla. El plan lista M1 y M3 como **dos** guardas y esperaba que
     cada una muriera por su cuenta; solo una estaba cubierta, y el día que alguien
     reordene la vista las filas del otro día salen. Ojo: la mutación **tal como el
     plan la escribe** muere, pero por otra razón — `$fechaDelSync` todavía no está
     en scope en ese punto, así que el filtro queda en `null`. Hay que mover el
     cálculo arriba para mutar de verdad.
     **Arreglado** con `expect($respuesta->viewData('funciones'))->toHaveCount(0)`
     en el test del `estado 3`: fija que las filas del otro día **no llegan** a la
     vista, independiente de lo que la vista haga con ellas.
  2. **El test de orden pasaba por el nombre, no por la hora.** Sacar el
     `orderByRaw('hora IS NULL, hora')` dejaba el test verde porque los shows del
     plan se llamaban «Mañana» y «Tarde», y su orden **alfabético** coincide con el
     de las horas — el `orderBy('show_nombre')` que queda daba el mismo resultado.
     **Arreglado** invirtiendo los nombres («Zeta» a las 08:30, «Alfa» a las
     19:00) y **agregando dos tests**, uno por cada parte restante del criterio: el
     desempate por nombre a igual hora, y que las funciones **sin hora van al
     final** (sin el `hora IS NULL`, MySQL pone los NULL primero y una función sin
     horario encabezaría el tablero). Ahora mueren las tres variantes por separado.
  3. **`assertSee('—')` pasaba siempre.** La Mutación 7 (`?? 0` en vez de la raya)
     sobrevivía porque la raya larga **también está en el párrafo del pie** de la
     vista. Es exactamente la trampa que el plan ya había previsto para
     `muci-banda` —y por la que inventó el `data-viejo`—, sin aplicarla acá.
     **Arreglado** con la misma solución: la celda vacía ahora es
     `<span data-cupos-vacio="1">—</span>` y el test assertea el atributo. Y se
     **agregó** el test complementario: **cupos en 0 se ve como 0, no como raya**,
     que es la distinción del §11 (0 es venta online cerrada, `null` es función no
     programada) y que ninguna versión anterior fijaba.
- **La Mutación 9 (borrar el singleton de `BusinessDay`) sobrevivió, como el plan
  anticipó.** No se persigue. Cierra el pendiente anotado en la Task 1: el binding
  no lo cubre ningún test y no hace falta que lo cubra — Laravel resuelve la clase
  igual con su default `America/Asuncion`; el singleton solo existe para que la
  zona salga de la config.
- **El Step 7 (verificación en el navegador) quedó a medias, y a propósito:** se
  verificó todo el **marcado** desde el harness —entrada en `menu.admin` y en
  `acl`, el `<link>` de Poppins, las cinco tintas de la paleta en el HTML, el
  `meta refresh` de 300s, la nota de reagendadas— y que
  `fonts.googleapis.com` responde 200 desde la máquina de desarrollo. Lo que
  **no** se hizo es mirarlo en pantalla: eso pide un navegador con sesión de admin
  y ojo humano. **Queda para la Task 7.**
- **Dos cosas para mirar en el deploy, ninguna es un bug de esta task:**
  1. **Poppins viene de Google Fonts, o sea de la red.** Si el servidor no tiene
     salida HTTPS, la fuente cae en silencio al `sans-serif` del sistema y el
     tablero se ve en Inter sin que nada avise. Verificar el 200 desde el servidor
     en la Task 7; si no lo hay, la fuente hay que servirla local.
  2. **`core()->formatBasePrice(70000)` devuelve `"PYG 70,000"`, no `"₲ 70.000"`.**
     Es la configuración de moneda base de Krayin, no de este paquete, y no se
     tocó. Si Carlos la quiere en guaraníes con el símbolo, es su propia tarea.
- **El total del paquete cierra en 66 tests, no 62:** 6 de `BusinessDay`, 12 de
  `EstadoDelTablero`, 16 del cliente, 6 del snapshot, 12 del comando y 14 del
  tablero. Sin fallas y **sin un solo skip**. La suite entera del CRM: 118.

---

### Task 1: Retirar el acceso directo a `muci`

El paquete deja de tener la credencial y deja de tener los parsers, que ahora viven en el servicio. Es una task de borrado: la única forma de verificar que no rompió nada es que lo que queda siga verde.

**Files:**
- Modify: `packages/CarlVallory/KrayinTicketSales/src/Providers/KrayinTicketSalesServiceProvider.php`
- Modify: `packages/CarlVallory/KrayinTicketSales/composer.json`
- Modify: `.env` y `.env.example` (raíz del CRM)
- Delete: `packages/CarlVallory/KrayinTicketSales/src/Support/BookingsOptionsParser.php`
- Delete: `packages/CarlVallory/KrayinTicketSales/src/Support/SpanishDateParser.php`
- Delete: `tests/Unit/TicketSales/BookingsOptionsParserTest.php`
- Delete: `tests/Unit/TicketSales/SpanishDateParserTest.php`
- Delete: `tests/Feature/TicketSales/WoocommerceConnectionTest.php`
- Delete: `tests/Fixtures/fooevents/` (los 18 `bookings_*.json` y `products.json`)

**Interfaces:**
- Produces: un `KrayinTicketSalesServiceProvider` sin `registerWoocommerceConnection()`. `BusinessDay` sigue existiendo, con el mismo constructor `__construct(private string $timezone = 'America/Asuncion')` y los mismos métodos `today(?CarbonImmutable $now = null): CarbonImmutable` y `todayString(?CarbonImmutable $now = null): string`, y sigue registrado como singleton.

- [ ] **Step 1: Confirmar el punto de partida**

Run: `php artisan test --filter=TicketSales`
Expected: `2 skipped, 21 passed`. Los 2 skipped son los de `WoocommerceConnectionTest` que necesitan la base `muci`, que no está en desarrollo.

- [ ] **Step 2: Confirmar que nadie más usa la conexión ni los parsers**

Run:
```bash
grep -rn "connection('woocommerce')\|connections.woocommerce\|WC_DB_" packages/ app/ config/ --include=*.php | grep -v KrayinTicketSales
grep -rn "BookingsOptionsParser\|SpanishDateParser" --include=*.php packages/ tests/ app/ | grep -v '^packages/CarlVallory/KrayinTicketSales/src/Support/'
grep -rn "Fixtures/fooevents" tests/ --include=*.php
```
Expected: la primera no devuelve nada. La segunda devuelve **solo** líneas de `tests/Unit/TicketSales/BookingsOptionsParserTest.php` y `tests/Unit/TicketSales/SpanishDateParserTest.php`, que son los archivos que se borran. La tercera, solo esos dos archivos.

> Si alguna devuelve algo fuera de esa lista, **parar**: hay un consumidor que este plan no contempla y borrar le rompe la suite.

- [ ] **Step 3: Borrar los archivos**

```bash
rm packages/CarlVallory/KrayinTicketSales/src/Support/BookingsOptionsParser.php \
   packages/CarlVallory/KrayinTicketSales/src/Support/SpanishDateParser.php \
   tests/Unit/TicketSales/BookingsOptionsParserTest.php \
   tests/Unit/TicketSales/SpanishDateParserTest.php \
   tests/Feature/TicketSales/WoocommerceConnectionTest.php
rm -r tests/Fixtures/fooevents
```

> `tests/Fixtures/bcp_anual_2026.html` es de otro paquete y **se queda**. Por eso se borra el subdirectorio `fooevents/` y no `tests/Fixtures/`.

- [ ] **Step 4: Sacar la conexión del ServiceProvider**

Dejar `packages/CarlVallory/KrayinTicketSales/src/Providers/KrayinTicketSalesServiceProvider.php` así:

```php
<?php

namespace CarlVallory\KrayinTicketSales\Providers;

use CarlVallory\KrayinTicketSales\Support\BusinessDay;
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

        $this->app->singleton(BusinessDay::class, function () {
            return new BusinessDay(config('ticket-sales.timezone', 'America/Asuncion'));
        });
    }
}
```

Desaparecen el método `registerWoocommerceConnection()` entero y su llamada.

> `loadViewsFrom` apunta a un directorio que todavía no existe; Laravel no se queja y la Task 6 lo crea. Estaba así desde antes.

- [ ] **Step 5: Sacar las variables del `.env` y del `.env.example`**

Borrar de **ambos** archivos, en la raíz del CRM, las seis líneas:

```
WC_DB_HOST=127.0.0.1
WC_DB_PORT=3306
WC_DB_DATABASE=muci
WC_DB_USERNAME=anthropic_readonly
WC_DB_PASSWORD=...
WC_DB_PREFIX=wpzv_
```

- [ ] **Step 6: Declarar las dependencias del paquete**

`packages/CarlVallory/KrayinTicketSales/composer.json` tiene `"require": {}`, que es mentira: el paquete usa Illuminate y sintaxis de PHP 8. Reemplazar por:

```json
    "require": {
        "php": "^8.1",
        "illuminate/support": "^10.0",
        "illuminate/console": "^10.0",
        "illuminate/database": "^10.0",
        "illuminate/http": "^10.0"
    },
```

> `^8.1` y `^10.0` copian lo que declara el `composer.json` de la raíz del CRM. No subirlos: prod corre PHP 8.2.

- [ ] **Step 7: Correr la suite**

Run: `php artisan test --filter=TicketSales`
Expected: **PASS, 6 tests, 0 skipped.** Los 6 son los de `BusinessDayTest`, lo único que sobrevive.

- [ ] **Step 8: Correr la suite entera del CRM**

Run: `php artisan test`
Expected: sin fallas nuevas respecto de antes de esta task. Un borrado que rompe otro paquete se ve acá y en ningún otro lado.

- [ ] **Step 9: Mutaciones antes de cerrar**

Esta task borra, así que las mutaciones van sobre lo que **quedó**:

| Mutación | Debe morir |
|---|---|
| En `BusinessDay::today()`, cambiar `setTimezone($this->timezone)` por `setTimezone('UTC')` | `a las 22:00 de Asunción sigue siendo hoy...` |
| En `BusinessDay::today()`, borrar `->startOfDay()` | `today devuelve medianoche en la zona del museo` |
| En el provider, borrar el `singleton(BusinessDay::class, ...)` | Ninguna de las de arriba —son `new BusinessDay()` directo—, pero la Task 6 depende de ese binding. Anotarlo y seguir: lo cubre el test de la Task 6 |

Restaurar cada mutación desde una copia en el scratchpad antes de la siguiente. Si el patrón es dudoso, `php -l <archivo>` antes de correr: un `sed` que rompe la sintaxis da `ParseError` en toda la suite y eso **no** es una mutación que muere.

- [ ] **Step 10: Commit**

```bash
git add packages/CarlVallory/KrayinTicketSales/src/Providers/KrayinTicketSalesServiceProvider.php \
        packages/CarlVallory/KrayinTicketSales/composer.json \
        .env.example \
        tests/Unit/TicketSales/ tests/Feature/TicketSales/ tests/Fixtures/
git commit -m "refactor(ticket-sales): el CRM deja de leer muci y suelta los parsers al servicio"
```

> `.env` no se versiona; solo va `.env.example`. El `git add` de los directorios de tests registra los borrados.

---

### Task 2: Cliente HTTP contra el servicio

La única pieza del CRM que habla con el mundo. Concentra el reintento, los timeouts y —lo más importante— la validación de forma que impide que una respuesta rara vacíe el snapshot.

**Files:**
- Modify: `packages/CarlVallory/KrayinTicketSales/src/Config/ticket-sales.php`
- Create: `packages/CarlVallory/KrayinTicketSales/src/Services/ErrorDelServicio.php`
- Create: `packages/CarlVallory/KrayinTicketSales/src/Services/FooEventsServiceClient.php`
- Create: `tests/Fixtures/fooevents/respuesta-ejemplo.json`
- Modify: `.env` y `.env.example` (raíz del CRM)
- Test: `tests/Feature/TicketSales/FooEventsServiceClientTest.php`

**Interfaces:**
- Consumes: config `ticket-sales.service.*`.
- Produces:
  - `ErrorDelServicio extends \RuntimeException`, con las fábricas estáticas `noDisponible(string $detalle): self`, `tokenRechazado(): self`, `pedidoInvalido(string $detalle): self`, `respuestaInvalida(string $detalle): self`, y el método `nivel(): string` que devuelve `'warning'` o `'error'`.
  - `FooEventsServiceClient::funcionesDe(string $fecha): array` — devuelve `['fecha' => string, 'generado_en' => string, 'avisos' => array<array{tipo: string, detalle: string}>, 'funciones' => array<array{producto_id: int, show: string, slot: string, hora: ?string, entradas_vendidas: int, entradas_reagendadas: int, cupos_habilitados: ?int, recaudacion_neta: int, recaudacion_bruta: int}>]`, ya validado. Lanza `ErrorDelServicio` en cualquier otro caso; **nunca** devuelve datos parciales.

- [ ] **Step 1: Traer la respuesta canónica como fixture**

```bash
mkdir -p tests/Fixtures/fooevents
cp ~/code/servicio-fooevents/tests/Fixtures/respuesta-ejemplo.json tests/Fixtures/fooevents/respuesta-ejemplo.json
```

Verificar que quedó:

```bash
cat tests/Fixtures/fooevents/respuesta-ejemplo.json
```
Expected: un objeto con `fecha` `"2026-08-07"`, `generado_en`, `avisos: []` y **una** función, la del producto 192637.

> Son dos repos: esta copia es a mano y se pueden desincronizar (§5.4 del spec). No es un contract test y no conviene disfrazarlo de garantía. Lo que sostiene el sistema es la validación de forma del Step 4: si el servicio cambia la respuesta y nadie actualiza el fixture, el CRM lo ve como error de forma y conserva el snapshot viejo. Se rompe ruidoso, no callado.

- [ ] **Step 2: Ampliar la config**

Reemplazar `packages/CarlVallory/KrayinTicketSales/src/Config/ticket-sales.php` por:

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

    'service' => [
        /*
         * El servicio corre en la misma máquina y escucha SOLO en loopback.
         * Que no sea alcanzable desde afuera es parte del diseño.
         */
        'url'   => env('FOOEVENTS_SERVICE_URL', 'http://127.0.0.1:8081'),
        'token' => env('FOOEVENTS_SERVICE_TOKEN'),

        /*
         * El sync corre cada 5 minutos: si el servicio tarda más de 15s hay
         * algo roto, y es mejor conservar el dato viejo que colgar el comando.
         */
        'connect_timeout' => 3,
        'timeout'         => 15,

        /*
         * Un reintento y nada más. En config para que el test pueda ponerlo en
         * 0 y no pagar la espera de verdad.
         */
        'retry_delay_ms' => 2000,
    ],

    /*
     * El snapshot conserva una semana. La tabla no crece sin límite y queda
     * margen para mirar hacia atrás si un día sale raro.
     */
    'retention_days' => 7,
];
```

- [ ] **Step 3: Agregar las variables al `.env` y al `.env.example`**

En `.env` (raíz del CRM), donde estaban las `WC_DB_*`:

```
FOOEVENTS_SERVICE_URL=http://127.0.0.1:8081
FOOEVENTS_SERVICE_TOKEN=
```

En `.env.example`, las mismas dos líneas con el valor de `URL` y el token vacío.

> En desarrollo el token queda vacío a propósito: el servicio no corre acá y todos los tests usan `Http::fake()`. El token real se pone en el servidor, en la Task 7.

- [ ] **Step 4: Escribir la excepción**

Crear `packages/CarlVallory/KrayinTicketSales/src/Services/ErrorDelServicio.php`:

```php
<?php

namespace CarlVallory\KrayinTicketSales\Services;

use RuntimeException;

/**
 * Una falla al pedirle el día al servicio. El `nivel` es el del log, y no es
 * cosmético: un 503 es un hipo de red y un 401 es configuración rota. Mandar
 * los dos al mismo nivel haría que el segundo se pierda entre los primeros.
 *
 * Ninguna de estas fallas toca el snapshot anterior (§4.2 del spec).
 */
class ErrorDelServicio extends RuntimeException
{
    private function __construct(string $mensaje, private string $nivel)
    {
        parent::__construct($mensaje);
    }

    /** Base caída, 500 inesperado o timeout: se reintentó y no salió. */
    public static function noDisponible(string $detalle): self
    {
        return new self($detalle, 'warning');
    }

    /** Token ausente o distinto. Es configuración, no un hipo de red. */
    public static function tokenRechazado(): self
    {
        return new self('El servicio rechazó el token.', 'error');
    }

    /** 422: la fecha que mandó el CRM no le gustó. Es un bug del CRM. */
    public static function pedidoInvalido(string $detalle): self
    {
        return new self($detalle, 'error');
    }

    /** La respuesta llegó pero no tiene la forma del contrato. */
    public static function respuestaInvalida(string $detalle): self
    {
        return new self('Respuesta con forma inesperada: ' . $detalle, 'error');
    }

    public function nivel(): string
    {
        return $this->nivel;
    }
}
```

- [ ] **Step 5: Escribir los tests, antes que el cliente**

Crear `tests/Feature/TicketSales/FooEventsServiceClientTest.php`:

```php
<?php

use CarlVallory\KrayinTicketSales\Services\ErrorDelServicio;
use CarlVallory\KrayinTicketSales\Services\FooEventsServiceClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'ticket-sales.service.url'            => 'http://127.0.0.1:8081',
        'ticket-sales.service.token'          => 'un-token',
        // Sin esto cada test de reintento espera 2 segundos de verdad.
        'ticket-sales.service.retry_delay_ms' => 0,
    ]);

    $this->cliente = app(FooEventsServiceClient::class);
});

function respuestaCanonica(): array
{
    return json_decode(
        file_get_contents(base_path('tests/Fixtures/fooevents/respuesta-ejemplo.json')),
        true
    );
}

test('200 devuelve la respuesta canónica ya validada', function () {
    Http::fake(['*' => Http::response(respuestaCanonica(), 200)]);

    $datos = $this->cliente->funcionesDe('2026-08-07');

    expect($datos['fecha'])->toBe('2026-08-07');
    expect($datos['avisos'])->toBe([]);
    expect($datos['funciones'])->toHaveCount(1);
    expect($datos['funciones'][0]['producto_id'])->toBe(192637);
    expect($datos['funciones'][0]['entradas_vendidas'])->toBe(2);
    expect($datos['funciones'][0]['recaudacion_bruta'])->toBe(70000);
});

test('manda el token como Bearer y la fecha como query', function () {
    Http::fake(['*' => Http::response(respuestaCanonica(), 200)]);

    $this->cliente->funcionesDe('2026-08-07');

    Http::assertSent(function (Request $request) {
        return $request->url() === 'http://127.0.0.1:8081/v1/funciones?fecha=2026-08-07'
            && $request->hasHeader('Authorization', 'Bearer un-token');
    });
});

test('503 se reintenta una vez y después se rinde', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['error' => 'origen_no_disponible', 'mensaje' => 'x'], 503)
        ->push(['error' => 'origen_no_disponible', 'mensaje' => 'x'], 503),
    ]);

    $error = null;

    try {
        $this->cliente->funcionesDe('2026-08-07');
    } catch (ErrorDelServicio $e) {
        $error = $e;
    }

    expect($error)->not->toBeNull();
    expect($error->nivel())->toBe('warning');
    Http::assertSentCount(2);
});

test('un 503 seguido de un 200 devuelve los datos', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['error' => 'origen_no_disponible', 'mensaje' => 'x'], 503)
        ->push(respuestaCanonica(), 200),
    ]);

    $datos = $this->cliente->funcionesDe('2026-08-07');

    expect($datos['funciones'])->toHaveCount(1);
    Http::assertSentCount(2);
});

test('500 se reintenta igual que el 503', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['error' => 'x', 'mensaje' => 'x'], 500)
        ->push(['error' => 'x', 'mensaje' => 'x'], 500),
    ]);

    expect(fn () => $this->cliente->funcionesDe('2026-08-07'))
        ->toThrow(ErrorDelServicio::class);

    Http::assertSentCount(2);
});

test('un timeout se reintenta una vez', function () {
    // El contador va acá y no en `Http::assertSentCount`: cuando el stub lanza,
    // Laravel no llega a registrar el par request/response y el assert daría 0.
    $intentos = 0;

    Http::fake(function () use (&$intentos) {
        $intentos++;

        throw new ConnectionException('cURL error 28: Operation timed out');
    });

    $error = null;

    try {
        $this->cliente->funcionesDe('2026-08-07');
    } catch (ErrorDelServicio $e) {
        $error = $e;
    }

    expect($error)->not->toBeNull();
    expect($error->nivel())->toBe('warning');
    expect($intentos)->toBe(2);
});

test('401 no se reintenta y es error, no warning', function () {
    Http::fake(['*' => Http::response(['error' => 'no_autorizado', 'mensaje' => 'x'], 401)]);

    $error = null;

    try {
        $this->cliente->funcionesDe('2026-08-07');
    } catch (ErrorDelServicio $e) {
        $error = $e;
    }

    expect($error)->not->toBeNull();
    expect($error->nivel())->toBe('error');
    Http::assertSentCount(1);
});

test('422 no se reintenta y es error: es un bug del CRM', function () {
    Http::fake(['*' => Http::response(['error' => 'fecha_invalida', 'mensaje' => 'x'], 422)]);

    $error = null;

    try {
        $this->cliente->funcionesDe('2026-08-07');
    } catch (ErrorDelServicio $e) {
        $error = $e;
    }

    expect($error)->not->toBeNull();
    expect($error->nivel())->toBe('error');
    Http::assertSentCount(1);
});

test('una respuesta sin la clave funciones se rechaza', function () {
    Http::fake(['*' => Http::response([
        'fecha'       => '2026-08-07',
        'generado_en' => '2026-08-07T17:30:00-03:00',
        'avisos'      => [],
    ], 200)]);

    $error = null;

    try {
        $this->cliente->funcionesDe('2026-08-07');
    } catch (ErrorDelServicio $e) {
        $error = $e;
    }

    expect($error)->not->toBeNull();
    expect($error->nivel())->toBe('error');
});

test('una respuesta que no es JSON se rechaza', function () {
    Http::fake(['*' => Http::response('<html>502 Bad Gateway</html>', 200)]);

    expect(fn () => $this->cliente->funcionesDe('2026-08-07'))
        ->toThrow(ErrorDelServicio::class);
});

test('una respuesta de otra fecha se rechaza', function () {
    Http::fake(['*' => Http::response(respuestaCanonica(), 200)]);

    // El fixture es del 2026-08-07; se pide el 08.
    expect(fn () => $this->cliente->funcionesDe('2026-08-08'))
        ->toThrow(ErrorDelServicio::class);
});

test('una función a la que le falta un campo se rechaza', function () {
    $cuerpo = respuestaCanonica();
    unset($cuerpo['funciones'][0]['recaudacion_neta']);

    Http::fake(['*' => Http::response($cuerpo, 200)]);

    expect(fn () => $this->cliente->funcionesDe('2026-08-07'))
        ->toThrow(ErrorDelServicio::class);
});

test('cupos_habilitados y hora en null son válidos', function () {
    $cuerpo = respuestaCanonica();
    $cuerpo['funciones'][0]['cupos_habilitados'] = null;
    $cuerpo['funciones'][0]['hora']              = null;

    Http::fake(['*' => Http::response($cuerpo, 200)]);

    $datos = $this->cliente->funcionesDe('2026-08-07');

    expect($datos['funciones'][0]['cupos_habilitados'])->toBeNull();
    expect($datos['funciones'][0]['hora'])->toBeNull();
});

test('un aviso con un tipo desconocido no rompe nada', function () {
    $cuerpo           = respuestaCanonica();
    $cuerpo['avisos'] = [
        ['tipo' => 'linea_faltante', 'detalle' => 'conocido'],
        ['tipo' => 'un_codigo_del_futuro', 'detalle' => 'todavía no existe'],
    ];

    Http::fake(['*' => Http::response($cuerpo, 200)]);

    $datos = $this->cliente->funcionesDe('2026-08-07');

    expect($datos['avisos'])->toHaveCount(2);
    expect($datos['avisos'][1]['tipo'])->toBe('un_codigo_del_futuro');
});

test('un aviso sin la clave detalle sí se rechaza', function () {
    $cuerpo           = respuestaCanonica();
    $cuerpo['avisos'] = [['tipo' => 'json_ilegible']];

    Http::fake(['*' => Http::response($cuerpo, 200)]);

    expect(fn () => $this->cliente->funcionesDe('2026-08-07'))
        ->toThrow(ErrorDelServicio::class);
});

test('un día sin funciones es una respuesta válida, no un error', function () {
    Http::fake(['*' => Http::response([
        'fecha'       => '2026-08-07',
        'generado_en' => '2026-08-07T17:30:00-03:00',
        'avisos'      => [],
        'funciones'   => [],
    ], 200)]);

    $datos = $this->cliente->funcionesDe('2026-08-07');

    expect($datos['funciones'])->toBe([]);
});
```

> Los dos últimos son el par que define la regla del vocabulario: **la forma se valida, el vocabulario no.** Un `tipo` nuevo pasa; un aviso sin `detalle` no.

- [ ] **Step 6: Correr los tests para verlos fallar**

Run: `php artisan test tests/Feature/TicketSales/FooEventsServiceClientTest.php`
Expected: FAIL en los 16, con `Target class [CarlVallory\KrayinTicketSales\Services\FooEventsServiceClient] does not exist`.

- [ ] **Step 7: Escribir el cliente**

Crear `packages/CarlVallory/KrayinTicketSales/src/Services/FooEventsServiceClient.php`:

```php
<?php

namespace CarlVallory\KrayinTicketSales\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * El único punto del CRM que habla con el servicio.
 *
 * Devuelve datos ya validados o lanza. Nunca devuelve algo a medias: quien
 * llama escribe el snapshot sin volver a mirar, y una respuesta rara que
 * pasara por acá vaciaría el tablero en silencio.
 */
class FooEventsServiceClient
{
    /**
     * Los campos que cada función debe traer, con si aceptan null.
     */
    private const CAMPOS = [
        'producto_id'          => false,
        'show'                 => false,
        'slot'                 => false,
        'hora'                 => true,
        'entradas_vendidas'    => false,
        'entradas_reagendadas' => false,
        'cupos_habilitados'    => true,
        'recaudacion_neta'     => false,
        'recaudacion_bruta'    => false,
    ];

    /**
     * @return array{fecha: string, generado_en: string, avisos: array, funciones: array}
     *
     * @throws ErrorDelServicio
     */
    public function funcionesDe(string $fecha): array
    {
        $respuesta = $this->pedirConUnReintento($fecha);

        if ($respuesta->status() === 401) {
            throw ErrorDelServicio::tokenRechazado();
        }

        if ($respuesta->status() === 422) {
            throw ErrorDelServicio::pedidoInvalido(
                "El servicio rechazó la fecha {$fecha}: " . $respuesta->body()
            );
        }

        if (! $respuesta->successful()) {
            throw ErrorDelServicio::noDisponible(
                "El servicio respondió {$respuesta->status()} para {$fecha}."
            );
        }

        return $this->validar($respuesta->json(), $fecha);
    }

    /**
     * Un reintento y nada más. Los 401 y 422 no se reintentan: reintentar un
     * token mal configurado es gastar dos requests para el mismo resultado.
     */
    private function pedirConUnReintento(string $fecha): Response
    {
        $intentos = 0;

        while (true) {
            $intentos++;

            try {
                $respuesta = $this->pedir($fecha);

                if (in_array($respuesta->status(), [401, 422], true) || $respuesta->successful()) {
                    return $respuesta;
                }

                if ($intentos >= 2) {
                    return $respuesta;
                }
            } catch (ConnectionException $e) {
                if ($intentos >= 2) {
                    throw ErrorDelServicio::noDisponible(
                        "No se pudo conectar al servicio para {$fecha}: " . $e->getMessage()
                    );
                }
            }

            usleep((int) config('ticket-sales.service.retry_delay_ms', 2000) * 1000);
        }
    }

    private function pedir(string $fecha): Response
    {
        return Http::withToken((string) config('ticket-sales.service.token'))
            ->connectTimeout((int) config('ticket-sales.service.connect_timeout', 3))
            ->timeout((int) config('ticket-sales.service.timeout', 15))
            ->acceptJson()
            ->get(rtrim((string) config('ticket-sales.service.url'), '/') . '/v1/funciones', [
                'fecha' => $fecha,
            ]);
    }

    /**
     * Se valida la FORMA, nunca el vocabulario.
     *
     * Los códigos de aviso son cinco hoy y agregar uno es aditivo — ya pasó una
     * vez, con `linea_faltante`. Un CRM que rechace un `tipo` que no conoce se
     * rompería en el próximo deploy del servicio, y por una cadena que solo va
     * al log.
     *
     * @throws ErrorDelServicio
     */
    private function validar(mixed $cuerpo, string $fecha): array
    {
        if (! is_array($cuerpo)) {
            throw ErrorDelServicio::respuestaInvalida('el cuerpo no es JSON.');
        }

        foreach (['fecha', 'generado_en', 'avisos', 'funciones'] as $clave) {
            if (! array_key_exists($clave, $cuerpo)) {
                throw ErrorDelServicio::respuestaInvalida("falta la clave `{$clave}`.");
            }
        }

        // Esta comparación es la que impide que una respuesta de otro día se
        // escriba como si fuera de hoy. Sin ella, el estado 3 del §4.3 —el
        // error más caro que este tablero puede cometer— sería alcanzable.
        if ($cuerpo['fecha'] !== $fecha) {
            throw ErrorDelServicio::respuestaInvalida(
                "se pidió {$fecha} y respondió {$cuerpo['fecha']}."
            );
        }

        if (! is_array($cuerpo['avisos']) || ! is_array($cuerpo['funciones'])) {
            throw ErrorDelServicio::respuestaInvalida('`avisos` y `funciones` deben ser listas.');
        }

        foreach ($cuerpo['avisos'] as $i => $aviso) {
            if (! is_array($aviso) || ! isset($aviso['tipo'], $aviso['detalle'])) {
                throw ErrorDelServicio::respuestaInvalida("el aviso #{$i} no tiene `tipo` y `detalle`.");
            }
        }

        foreach ($cuerpo['funciones'] as $i => $funcion) {
            if (! is_array($funcion)) {
                throw ErrorDelServicio::respuestaInvalida("la función #{$i} no es un objeto.");
            }

            foreach (self::CAMPOS as $campo => $aceptaNull) {
                if (! array_key_exists($campo, $funcion)) {
                    throw ErrorDelServicio::respuestaInvalida("a la función #{$i} le falta `{$campo}`.");
                }

                if ($funcion[$campo] === null && ! $aceptaNull) {
                    throw ErrorDelServicio::respuestaInvalida("la función #{$i} trae `{$campo}` en null.");
                }
            }
        }

        return $cuerpo;
    }
}
```

- [ ] **Step 8: Correr los tests**

Run: `php artisan test tests/Feature/TicketSales/FooEventsServiceClientTest.php`
Expected: **PASS, 16 tests.**

- [ ] **Step 9: Mutaciones antes de cerrar**

| Mutación | Debe morir |
|---|---|
| En `validar()`, borrar el bloque `if ($cuerpo['fecha'] !== $fecha)` | `una respuesta de otra fecha se rechaza` |
| En `validar()`, hacer que el bucle de `self::CAMPOS` no lance nunca (`continue` en lugar del `throw`) | `una función a la que le falta un campo se rechaza` |
| En `validar()`, agregar una lista blanca de `tipo` y rechazar lo que no esté | `un aviso con un tipo desconocido no rompe nada` — es la mutación que prueba que la regla está viva, no solo comentada |
| En `pedirConUnReintento()`, sacar el `401` del `in_array` | `401 no se reintenta y es error, no warning` (por el `assertSentCount(1)`) |
| En `pedirConUnReintento()`, cambiar `$intentos >= 2` por `$intentos >= 1` | `un 503 seguido de un 200 devuelve los datos` |
| En `pedirConUnReintento()`, cambiar `$intentos >= 2` por `$intentos >= 3` | `503 se reintenta una vez y después se rinde` (por el `assertSentCount(2)`) |
| En `ErrorDelServicio::tokenRechazado()`, cambiar `'error'` por `'warning'` | `401 no se reintenta y es error, no warning` |
| En `funcionesDe()`, cambiar el guard `$respuesta->successful()` por `$respuesta->status() !== 404` | `500 se reintenta igual que el 503` |

Restaurar desde una copia en el scratchpad entre mutación y mutación. Si alguna sobrevive, escribir el test que la mata y anotarlo en `Desviaciones encontradas al ejecutar`.

- [ ] **Step 10: Commit**

```bash
git add packages/CarlVallory/KrayinTicketSales/src/Services/ \
        packages/CarlVallory/KrayinTicketSales/src/Config/ticket-sales.php \
        tests/Feature/TicketSales/FooEventsServiceClientTest.php \
        tests/Fixtures/fooevents/respuesta-ejemplo.json \
        .env.example
git commit -m "feat(ticket-sales): cliente HTTP del servicio con reintento y validación de forma"
```

---

### Task 3: Las dos tablas y sus modelos

**Files:**
- Create: `packages/CarlVallory/KrayinTicketSales/src/Database/Migrations/2026_08_14_120000_create_muci_ticket_sales_tables.php`
- Create: `packages/CarlVallory/KrayinTicketSales/src/Models/TicketSalesSnapshot.php`
- Create: `packages/CarlVallory/KrayinTicketSales/src/Models/TicketSalesSync.php`
- Modify: `packages/CarlVallory/KrayinTicketSales/src/Providers/KrayinTicketSalesServiceProvider.php`
- Test: `tests/Feature/TicketSales/TicketSalesSnapshotTest.php`

**Interfaces:**
- Produces:
  - Tabla `muci_ticket_sales_snapshot` en la conexión por defecto (`krayin`), y el modelo `TicketSalesSnapshot` con `$fillable = ['fecha','producto_id','show_nombre','slot','hora','entradas_vendidas','entradas_reagendadas','cupos_habilitados','recaudacion_neta','recaudacion_bruta']`.
  - Tabla `muci_ticket_sales_sync` y el modelo `TicketSalesSync` con `$fillable = ['fecha','generado_en','avisos','synced_at']`, con `avisos` casteado a `array` y `synced_at`/`generado_en` a `datetime`.

- [ ] **Step 1: Escribir la migración**

Crear `packages/CarlVallory/KrayinTicketSales/src/Database/Migrations/2026_08_14_120000_create_muci_ticket_sales_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Cabecera: una fila por fecha sincronizada.
         *
         * Existe porque sin ella "hoy no hay funciones" y "el sync nunca corrió"
         * son los dos cero filas, y el §4.3 del spec exige que se vean distinto.
         * Es además donde viven los avisos, que son del día y no de una función.
         */
        Schema::create('muci_ticket_sales_sync', function (Blueprint $table) {
            $table->id();
            $table->date('fecha')->unique();
            $table->timestamp('generado_en')->nullable();
            $table->json('avisos')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();
        });

        /*
         * Filas de funciones. Los nombres copian el contrato del servicio 1:1,
         * salvo `show` -> `show_nombre` porque SHOW es reservada en MySQL.
         */
        Schema::create('muci_ticket_sales_snapshot', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->unsignedBigInteger('producto_id');
            $table->string('show_nombre');
            $table->string('slot');
            $table->string('hora', 5)->nullable();
            $table->unsignedInteger('entradas_vendidas')->default(0);
            $table->unsignedInteger('entradas_reagendadas')->default(0);
            $table->integer('cupos_habilitados')->nullable();
            $table->unsignedBigInteger('recaudacion_neta')->default(0);
            $table->unsignedBigInteger('recaudacion_bruta')->default(0);
            $table->timestamps();

            $table->index(['fecha', 'hora']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('muci_ticket_sales_snapshot');
        Schema::dropIfExists('muci_ticket_sales_sync');
    }
};
```

> `cupos_habilitados` es nullable y **con signo** a propósito: es `null` cuando la función existe por tickets pero ya no figura en la programación, y FooEvents podría entregar un remanente negativo. La recaudación va en enteros porque el contrato la manda entera, en guaraníes.

- [ ] **Step 2: Escribir los modelos**

`packages/CarlVallory/KrayinTicketSales/src/Models/TicketSalesSnapshot.php`:

```php
<?php

namespace CarlVallory\KrayinTicketSales\Models;

use Illuminate\Database\Eloquent\Model;

class TicketSalesSnapshot extends Model
{
    protected $table = 'muci_ticket_sales_snapshot';

    protected $fillable = [
        'fecha',
        'producto_id',
        'show_nombre',
        'slot',
        'hora',
        'entradas_vendidas',
        'entradas_reagendadas',
        'cupos_habilitados',
        'recaudacion_neta',
        'recaudacion_bruta',
    ];

    protected $casts = [
        'fecha'                => 'date',
        'producto_id'          => 'integer',
        'entradas_vendidas'    => 'integer',
        'entradas_reagendadas' => 'integer',
        'cupos_habilitados'    => 'integer',
        'recaudacion_neta'     => 'integer',
        'recaudacion_bruta'    => 'integer',
    ];
}
```

`packages/CarlVallory/KrayinTicketSales/src/Models/TicketSalesSync.php`:

```php
<?php

namespace CarlVallory\KrayinTicketSales\Models;

use Illuminate\Database\Eloquent\Model;

class TicketSalesSync extends Model
{
    protected $table = 'muci_ticket_sales_sync';

    protected $fillable = [
        'fecha',
        'generado_en',
        'avisos',
        'synced_at',
    ];

    protected $casts = [
        'fecha'       => 'date',
        'generado_en' => 'datetime',
        'avisos'      => 'array',
        'synced_at'   => 'datetime',
    ];
}
```

- [ ] **Step 3: Cargar las migraciones desde el ServiceProvider**

En `boot()`, arriba del `loadViewsFrom`:

```php
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
```

- [ ] **Step 4: Escribir el test**

Crear `tests/Feature/TicketSales/TicketSalesSnapshotTest.php`:

```php
<?php

use CarlVallory\KrayinTicketSales\Models\TicketSalesSnapshot;
use CarlVallory\KrayinTicketSales\Models\TicketSalesSync;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

test('una función se persiste y se lee con los tipos del contrato', function () {
    TicketSalesSnapshot::create([
        'fecha'                => '2026-08-07',
        'producto_id'          => 192637,
        'show_nombre'          => 'Entrada Bioestanque',
        'slot'                 => 'BioEstanque (16:00) (17:00)',
        'hora'                 => '17:00',
        'entradas_vendidas'    => 2,
        'entradas_reagendadas' => 0,
        'cupos_habilitados'    => 18,
        'recaudacion_neta'     => 63636,
        'recaudacion_bruta'    => 70000,
    ]);

    $fila = TicketSalesSnapshot::where('producto_id', 192637)->first();

    expect($fila->entradas_vendidas)->toBe(2);
    expect($fila->slot)->toBe('BioEstanque (16:00) (17:00)');
    expect($fila->recaudacion_neta)->toBe(63636);
    expect($fila->recaudacion_bruta)->toBe(70000);
});

test('cupos_habilitados acepta null: la función existe por tickets pero no está programada', function () {
    $fila = TicketSalesSnapshot::create([
        'fecha'                => '2026-08-07',
        'producto_id'          => 1,
        'show_nombre'          => 'X',
        'slot'                 => 'Y',
        'hora'                 => null,
        'entradas_vendidas'    => 1,
        'entradas_reagendadas' => 0,
        'cupos_habilitados'    => null,
        'recaudacion_neta'     => 0,
        'recaudacion_bruta'    => 0,
    ]);

    expect($fila->fresh()->cupos_habilitados)->toBeNull();
    expect($fila->fresh()->hora)->toBeNull();
});

test('la cabecera guarda los avisos como estructura, no como texto', function () {
    TicketSalesSync::create([
        'fecha'       => '2026-08-07',
        'generado_en' => '2026-08-07 17:30:00',
        'avisos'      => [
            ['tipo' => 'linea_faltante', 'detalle' => 'Par 500:192637 sin línea.'],
        ],
        'synced_at'   => now(),
    ]);

    $sync = TicketSalesSync::where('fecha', '2026-08-07')->first();

    expect($sync->avisos)->toBeArray();
    expect($sync->avisos[0]['tipo'])->toBe('linea_faltante');
});

test('la cabecera admite avisos vacíos, que es el caso normal', function () {
    TicketSalesSync::create([
        'fecha'       => '2026-08-07',
        'generado_en' => '2026-08-07 17:30:00',
        'avisos'      => [],
        'synced_at'   => now(),
    ]);

    expect(TicketSalesSync::where('fecha', '2026-08-07')->first()->avisos)->toBe([]);
});

test('no puede haber dos cabeceras para la misma fecha', function () {
    TicketSalesSync::create([
        'fecha' => '2026-08-07', 'generado_en' => now(), 'avisos' => [], 'synced_at' => now(),
    ]);

    expect(fn () => TicketSalesSync::create([
        'fecha' => '2026-08-07', 'generado_en' => now(), 'avisos' => [], 'synced_at' => now(),
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});
```

- [ ] **Step 5: Correr la migración y los tests**

Run:
```bash
php artisan migrate
php artisan test tests/Feature/TicketSales/TicketSalesSnapshotTest.php
```
Expected: la migración informa `Ran`; **PASS, 5 tests.**

- [ ] **Step 6: Verificar que la desinstalación es limpia**

Run:
```bash
php artisan migrate:rollback --step=1
php artisan migrate
```
Expected: el rollback borra las dos tablas sin error y la migración las vuelve a crear. Una migración sin `down()` completo rompe la restricción de reversibilidad y esto es lo único que la comprueba.

- [ ] **Step 7: Mutaciones antes de cerrar**

| Mutación | Debe morir |
|---|---|
| En la migración, sacar `->unique()` de `fecha` en `muci_ticket_sales_sync` (con `migrate:fresh` de por medio) | `no puede haber dos cabeceras para la misma fecha` |
| En `TicketSalesSync`, sacar `'avisos' => 'array'` de `$casts` | `la cabecera guarda los avisos como estructura, no como texto` |
| En la migración, sacar `->nullable()` de `cupos_habilitados` | `cupos_habilitados acepta null...` |
| En `TicketSalesSnapshot`, sacar `'recaudacion_neta' => 'integer'` de `$casts` | `una función se persiste y se lee con los tipos del contrato` (el `toBe(63636)` es estricto y una cadena no pasa) |
| En la migración, cambiar el `down()` por un `dropIfExists` de una sola tabla | Step 6 |

- [ ] **Step 8: Commit**

```bash
git add packages/CarlVallory/KrayinTicketSales/src/Database/ \
        packages/CarlVallory/KrayinTicketSales/src/Models/ \
        packages/CarlVallory/KrayinTicketSales/src/Providers/KrayinTicketSalesServiceProvider.php \
        tests/Feature/TicketSales/TicketSalesSnapshotTest.php
git commit -m "feat(ticket-sales): tablas y modelos del snapshot y de la cabecera de sync"
```

---

### Task 4: Comando `ticket-sales:sync` y el scheduler

**Files:**
- Create: `packages/CarlVallory/KrayinTicketSales/src/Console/SyncTicketSalesCommand.php`
- Modify: `packages/CarlVallory/KrayinTicketSales/src/Providers/KrayinTicketSalesServiceProvider.php`
- Test: `tests/Feature/TicketSales/SyncTicketSalesCommandTest.php`

**Interfaces:**
- Consumes: `FooEventsServiceClient::funcionesDe(string $fecha): array`, `ErrorDelServicio::nivel(): string`, `BusinessDay::todayString(): string`, `TicketSalesSnapshot`, `TicketSalesSync`.
- Produces: el comando `ticket-sales:sync {--fecha=}`, registrado en el scheduler cada 5 minutos.

- [ ] **Step 1: Escribir los tests, antes que el comando**

Crear `tests/Feature/TicketSales/SyncTicketSalesCommandTest.php`:

```php
<?php

use CarlVallory\KrayinTicketSales\Models\TicketSalesSnapshot;
use CarlVallory\KrayinTicketSales\Models\TicketSalesSync;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(DatabaseTransactions::class);

beforeEach(function () {
    config([
        'ticket-sales.service.url'            => 'http://127.0.0.1:8081',
        'ticket-sales.service.token'          => 'un-token',
        'ticket-sales.service.retry_delay_ms' => 0,
        'ticket-sales.retention_days'         => 7,
    ]);
});

function cuerpoDelServicio(array $funciones = [], array $avisos = [], string $fecha = '2026-08-07'): array
{
    return [
        'fecha'       => $fecha,
        'generado_en' => $fecha . 'T17:30:00-03:00',
        'avisos'      => $avisos,
        'funciones'   => $funciones,
    ];
}

function unaFuncion(array $sobre = []): array
{
    return array_merge([
        'producto_id'          => 192637,
        'show'                 => 'Entrada Bioestanque',
        'slot'                 => 'BioEstanque (16:00) (17:00)',
        'hora'                 => '17:00',
        'entradas_vendidas'    => 2,
        'entradas_reagendadas' => 0,
        'cupos_habilitados'    => 18,
        'recaudacion_neta'     => 63636,
        'recaudacion_bruta'    => 70000,
    ], $sobre);
}

test('escribe las funciones y la cabecera del día', function () {
    Http::fake(['*' => Http::response(cuerpoDelServicio([
        unaFuncion(),
        unaFuncion(['slot' => 'BioEstanque (18:00) (19:00)', 'hora' => '19:00', 'entradas_vendidas' => 0, 'recaudacion_neta' => 0, 'recaudacion_bruta' => 0]),
    ]), 200)]);

    $this->artisan('ticket-sales:sync', ['--fecha' => '2026-08-07'])->assertSuccessful();

    expect(TicketSalesSnapshot::where('fecha', '2026-08-07')->count())->toBe(2);
    expect(TicketSalesSync::where('fecha', '2026-08-07')->first())->not->toBeNull();
});

test('la función sin ventas queda en cero, no desaparece', function () {
    Http::fake(['*' => Http::response(cuerpoDelServicio([
        unaFuncion(['entradas_vendidas' => 0, 'recaudacion_neta' => 0, 'recaudacion_bruta' => 0]),
    ]), 200)]);

    $this->artisan('ticket-sales:sync', ['--fecha' => '2026-08-07'])->assertSuccessful();

    $fila = TicketSalesSnapshot::where('producto_id', 192637)->first();

    expect($fila)->not->toBeNull();
    expect($fila->entradas_vendidas)->toBe(0);
    expect($fila->cupos_habilitados)->toBe(18);
});

test('sin --fecha usa el día de negocio en la zona del museo', function () {
    $hoy = app(\CarlVallory\KrayinTicketSales\Support\BusinessDay::class)->todayString();

    Http::fake(['*' => Http::response(cuerpoDelServicio([unaFuncion()], [], $hoy), 200)]);

    $this->artisan('ticket-sales:sync')->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request->url(), "fecha={$hoy}"));
    expect(TicketSalesSync::where('fecha', $hoy)->exists())->toBeTrue();
});

test('correr dos veces reemplaza en vez de duplicar', function () {
    Http::fake(['*' => Http::response(cuerpoDelServicio([unaFuncion()]), 200)]);

    $this->artisan('ticket-sales:sync', ['--fecha' => '2026-08-07'])->assertSuccessful();
    $this->artisan('ticket-sales:sync', ['--fecha' => '2026-08-07'])->assertSuccessful();

    expect(TicketSalesSnapshot::where('fecha', '2026-08-07')->count())->toBe(1);
    expect(TicketSalesSync::where('fecha', '2026-08-07')->count())->toBe(1);
});

test('un 503 falla el comando y deja el snapshot anterior intacto', function () {
    Http::fake(['*' => Http::response(cuerpoDelServicio([unaFuncion()]), 200)]);
    $this->artisan('ticket-sales:sync', ['--fecha' => '2026-08-07'])->assertSuccessful();

    Http::fake(['*' => Http::response(['error' => 'origen_no_disponible', 'mensaje' => 'x'], 503)]);
    $this->artisan('ticket-sales:sync', ['--fecha' => '2026-08-07'])->assertFailed();

    expect(TicketSalesSnapshot::where('fecha', '2026-08-07')->count())->toBe(1);
    expect(TicketSalesSync::where('fecha', '2026-08-07')->first()->avisos)->toBe([]);
});

test('una respuesta malformada deja el snapshot anterior intacto', function () {
    Http::fake(['*' => Http::response(cuerpoDelServicio([unaFuncion()]), 200)]);
    $this->artisan('ticket-sales:sync', ['--fecha' => '2026-08-07'])->assertSuccessful();

    // Sin la clave `funciones`: el cliente la rechaza y el comando no debe escribir.
    Http::fake(['*' => Http::response([
        'fecha' => '2026-08-07', 'generado_en' => 'x', 'avisos' => [],
    ], 200)]);
    $this->artisan('ticket-sales:sync', ['--fecha' => '2026-08-07'])->assertFailed();

    expect(TicketSalesSnapshot::where('fecha', '2026-08-07')->count())->toBe(1);
});

test('un día sin funciones escribe la cabecera igual', function () {
    Http::fake(['*' => Http::response(cuerpoDelServicio([]), 200)]);

    $this->artisan('ticket-sales:sync', ['--fecha' => '2026-08-07'])->assertSuccessful();

    expect(TicketSalesSnapshot::where('fecha', '2026-08-07')->count())->toBe(0);
    expect(TicketSalesSync::where('fecha', '2026-08-07')->exists())->toBeTrue();
});

test('los avisos se guardan y se loguean como warning', function () {
    Log::spy();

    Http::fake(['*' => Http::response(cuerpoDelServicio(
        [unaFuncion()],
        [['tipo' => 'estado_desconocido', 'detalle' => 'wc-nuevo']]
    ), 200)]);

    $this->artisan('ticket-sales:sync', ['--fecha' => '2026-08-07'])->assertSuccessful();

    expect(TicketSalesSync::where('fecha', '2026-08-07')->first()->avisos)->toHaveCount(1);
    Log::shouldHaveReceived('warning')->atLeast()->once();
});

test('un aviso con un tipo desconocido se guarda sin romper el sync', function () {
    Http::fake(['*' => Http::response(cuerpoDelServicio(
        [unaFuncion()],
        [['tipo' => 'un_codigo_del_futuro', 'detalle' => 'todavía no existe']]
    ), 200)]);

    $this->artisan('ticket-sales:sync', ['--fecha' => '2026-08-07'])->assertSuccessful();

    expect(TicketSalesSync::where('fecha', '2026-08-07')->first()->avisos[0]['tipo'])
        ->toBe('un_codigo_del_futuro');
});

test('un 401 se loguea como error, no como warning', function () {
    Log::spy();

    Http::fake(['*' => Http::response(['error' => 'no_autorizado', 'mensaje' => 'x'], 401)]);

    $this->artisan('ticket-sales:sync', ['--fecha' => '2026-08-07'])->assertFailed();

    Log::shouldHaveReceived('error')->atLeast()->once();
});

test('la purga borra lo más viejo que la retención y respeta el resto', function () {
    Http::fake(['*' => Http::response(cuerpoDelServicio([unaFuncion()]), 200)]);

    // Dos días viejos: uno adentro de la ventana de 7 días, otro afuera.
    foreach (['2026-08-05', '2026-07-20'] as $fechaVieja) {
        TicketSalesSnapshot::create([
            'fecha' => $fechaVieja, 'producto_id' => 1, 'show_nombre' => 'X', 'slot' => 'Y',
            'hora' => null, 'entradas_vendidas' => 0, 'entradas_reagendadas' => 0,
            'cupos_habilitados' => null, 'recaudacion_neta' => 0, 'recaudacion_bruta' => 0,
        ]);
        TicketSalesSync::create([
            'fecha' => $fechaVieja, 'generado_en' => now(), 'avisos' => [], 'synced_at' => now(),
        ]);
    }

    $this->artisan('ticket-sales:sync', ['--fecha' => '2026-08-07'])->assertSuccessful();

    expect(TicketSalesSnapshot::where('fecha', '2026-07-20')->exists())->toBeFalse();
    expect(TicketSalesSync::where('fecha', '2026-07-20')->exists())->toBeFalse();
    expect(TicketSalesSnapshot::where('fecha', '2026-08-05')->exists())->toBeTrue();
    expect(TicketSalesSync::where('fecha', '2026-08-05')->exists())->toBeTrue();
});
```

- [ ] **Step 2: Correr los tests para verlos fallar**

Run: `php artisan test tests/Feature/TicketSales/SyncTicketSalesCommandTest.php`
Expected: FAIL en los 11, con `The command "ticket-sales:sync" does not exist`.

- [ ] **Step 3: Escribir el comando**

Crear `packages/CarlVallory/KrayinTicketSales/src/Console/SyncTicketSalesCommand.php`:

```php
<?php

namespace CarlVallory\KrayinTicketSales\Console;

use CarlVallory\KrayinTicketSales\Models\TicketSalesSnapshot;
use CarlVallory\KrayinTicketSales\Models\TicketSalesSync;
use CarlVallory\KrayinTicketSales\Services\ErrorDelServicio;
use CarlVallory\KrayinTicketSales\Services\FooEventsServiceClient;
use CarlVallory\KrayinTicketSales\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncTicketSalesCommand extends Command
{
    protected $signature = 'ticket-sales:sync {--fecha= : Fecha YYYY-MM-DD; por defecto hoy en la zona del museo}';

    protected $description = 'Pide al servicio las funciones del día y reemplaza el snapshot local';

    public function handle(FooEventsServiceClient $cliente, BusinessDay $businessDay): int
    {
        $fecha = $this->option('fecha') ?: $businessDay->todayString();

        $this->info("Sincronizando entradas para {$fecha}...");

        try {
            $respuesta = $cliente->funcionesDe($fecha);
        } catch (ErrorDelServicio $e) {
            // El nivel lo decide la excepción: un 503 es ruido de red y un 401
            // es configuración rota. Mandarlos al mismo lugar pierde el segundo.
            Log::{$e->nivel()}('ticket-sales:sync no pudo actualizar el snapshot', [
                'fecha'   => $fecha,
                'detalle' => $e->getMessage(),
            ]);

            $this->error('Falló la sincronización: ' . $e->getMessage());
            $this->warn('El snapshot anterior queda intacto.');

            return self::FAILURE;
        }

        $this->escribir($fecha, $respuesta);
        $this->reportarAvisos($fecha, $respuesta['avisos']);

        $this->info(sprintf(
            '%d funciones | %d entradas | %d avisos',
            count($respuesta['funciones']),
            array_sum(array_column($respuesta['funciones'], 'entradas_vendidas')),
            count($respuesta['avisos']),
        ));

        return self::SUCCESS;
    }

    /**
     * Reemplazo atómico. La vista nunca lee un estado a medio escribir, y una
     * transacción que falla a la mitad deja el snapshot anterior entero.
     */
    private function escribir(string $fecha, array $respuesta): void
    {
        $ahora = now();

        DB::transaction(function () use ($fecha, $respuesta, $ahora) {
            TicketSalesSnapshot::where('fecha', $fecha)->delete();

            foreach ($respuesta['funciones'] as $funcion) {
                TicketSalesSnapshot::create([
                    'fecha'                => $fecha,
                    'producto_id'          => $funcion['producto_id'],
                    'show_nombre'          => $funcion['show'],
                    'slot'                 => $funcion['slot'],
                    'hora'                 => $funcion['hora'],
                    'entradas_vendidas'    => $funcion['entradas_vendidas'],
                    'entradas_reagendadas' => $funcion['entradas_reagendadas'],
                    'cupos_habilitados'    => $funcion['cupos_habilitados'],
                    'recaudacion_neta'     => $funcion['recaudacion_neta'],
                    'recaudacion_bruta'    => $funcion['recaudacion_bruta'],
                ]);
            }

            TicketSalesSync::updateOrCreate(
                ['fecha' => $fecha],
                [
                    'generado_en' => CarbonImmutable::parse($respuesta['generado_en']),
                    'avisos'      => $respuesta['avisos'],
                    'synced_at'   => $ahora,
                ]
            );

            $this->purgar($fecha);
        });
    }

    /**
     * La ventana se cuenta desde la fecha sincronizada y no desde "hoy", para
     * que rellenar un día viejo a mano no borre los recientes.
     */
    private function purgar(string $fecha): void
    {
        $corte = CarbonImmutable::parse($fecha)
            ->subDays((int) config('ticket-sales.retention_days', 7))
            ->format('Y-m-d');

        TicketSalesSnapshot::where('fecha', '<', $corte)->delete();
        TicketSalesSync::where('fecha', '<', $corte)->delete();
    }

    /**
     * Los avisos no se muestran en el tablero: su público es quien mantiene el
     * sistema, no boletería. Van al log para que un cambio en WordPress no pase
     * inadvertido durante meses, que es lo que ya pasó con las líneas de pedido.
     */
    private function reportarAvisos(string $fecha, array $avisos): void
    {
        if ($avisos === []) {
            return;
        }

        Log::warning('ticket-sales:sync recibió avisos del servicio', [
            'fecha'  => $fecha,
            'avisos' => $avisos,
        ]);

        foreach ($avisos as $aviso) {
            $this->warn("[{$aviso['tipo']}] {$aviso['detalle']}");
        }
    }
}
```

- [ ] **Step 4: Registrar el comando y el scheduler**

En `boot()` del `KrayinTicketSalesServiceProvider`:

```php
        if ($this->app->runningInConsole()) {
            $this->commands([
                \CarlVallory\KrayinTicketSales\Console\SyncTicketSalesCommand::class,
            ]);
        }

        // El scheduler se registra desde el paquete para no tocar
        // app/Console/Kernel.php, que es core. Mismo mecanismo que el
        // inbound-emails:process que ya corre en producción.
        $this->app->booted(function () {
            $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);

            $schedule->command('ticket-sales:sync')
                ->everyFiveMinutes()
                ->withoutOverlapping()
                ->runInBackground();
        });
```

- [ ] **Step 5: Correr los tests**

Run: `php artisan test tests/Feature/TicketSales/SyncTicketSalesCommandTest.php`
Expected: **PASS, 11 tests.**

- [ ] **Step 6: Verificar que el scheduler quedó registrado**

Run: `php artisan schedule:list`
Expected: aparece `ticket-sales:sync` con cadencia `*/5 * * * *`.

- [ ] **Step 7: Mutaciones antes de cerrar**

| Mutación | Debe morir |
|---|---|
| En `escribir()`, borrar el `TicketSalesSnapshot::where(...)->delete()` | `correr dos veces reemplaza en vez de duplicar` |
| En `handle()`, mover el `escribir()` **antes** del `try`/`catch` (o sea, escribir aunque falle) | `un 503 falla el comando y deja el snapshot anterior intacto` |
| En `handle()`, devolver `self::SUCCESS` en el `catch` | `un 503 falla el comando...` y `una respuesta malformada...` |
| En `handle()`, reemplazar `Log::{$e->nivel()}` por `Log::warning` fijo | `un 401 se loguea como error, no como warning` |
| En `escribir()`, sacar el `DB::transaction` y dejar las operaciones sueltas | **Ninguno de los tests actuales.** Es una mutación que no se puede matar con Pest sin simular una falla a mitad de escritura. Anotarla en `Desviaciones` y dejarla: la atomicidad la sostiene la revisión de código, no la suite |
| En `purgar()`, cambiar `subDays(...)` por `subDays(0)` | `la purga borra lo más viejo que la retención y respeta el resto` (borraría también el 2026-08-05) |
| En `purgar()`, cambiar `'<'` por `'<='` | Nada hoy — el corte cae en 2026-07-31 y no hay fila ahí. Si sobrevive, **no perseguirla**: es un borde de un día en una purga de retención, no un resultado incorrecto |
| En `handle()`, cambiar `$this->option('fecha') ?: $businessDay->todayString()` por una fecha fija | `sin --fecha usa el día de negocio en la zona del museo` |
| En `escribir()`, mapear `'show_nombre' => $funcion['slot']` | `escribe las funciones y la cabecera del día` no lo ve; **lo ve** el test de la Task 6 que hace `assertSee('Entrada Bioestanque')`. Anotarlo: si molesta, agregar acá un `expect($fila->show_nombre)->toBe('Entrada Bioestanque')` |

> El mapeo campo a campo del `escribir()` es exactamente donde el plan del servicio se comió su único bug real (`recaudacion_neto` contra `recaudacion_neta`, que no falló: creó dos campos fantasma y dejó la recaudación en cero). Mutar **cada** uno de los nueve mapeos y ver cuáles sobreviven es tiempo bien gastado.

- [ ] **Step 8: Commit**

```bash
git add packages/CarlVallory/KrayinTicketSales/src/Console/ \
        packages/CarlVallory/KrayinTicketSales/src/Providers/KrayinTicketSalesServiceProvider.php \
        tests/Feature/TicketSales/SyncTicketSalesCommandTest.php
git commit -m "feat(ticket-sales): comando de sync cada 5 minutos con escritura atómica"
```

---

### Task 5: `EstadoDelTablero` — los cinco estados

Clase pura, sin base ni app. Es la pieza donde vive el error más caro que este tablero puede cometer: mostrar los números del jueves un viernes en que el sync se rompió.

**Files:**
- Create: `packages/CarlVallory/KrayinTicketSales/src/Support/EstadoDelTablero.php`
- Test: `tests/Unit/TicketSales/EstadoDelTableroTest.php`

**Interfaces:**
- Produces: `EstadoDelTablero`, con `__construct(private int $umbralMinutos = 15)`, las constantes `NORMAL`, `VIEJO`, `OTRO_DIA`, `NUNCA`, `SIN_FUNCIONES` (cadenas), y dos métodos:
  - `decidir(?string $fechaDelSync, ?CarbonImmutable $syncedAt, string $hoy, int $cantidadFunciones, CarbonImmutable $ahora): string`
  - `esViejo(?CarbonImmutable $syncedAt, CarbonImmutable $ahora): bool`

> **Por qué dos métodos y no uno.** El §4.3 enumera cinco estados excluyentes, pero deja un hueco: un día de hoy, sin funciones, con el dato de hace 40 minutos cae en el estado 5 y perdería la banda de antigüedad — o sea "no hay funciones hoy" dicho con una confianza que el dato no tiene. `esViejo()` es independiente del estado, así que la banda también aparece sobre el estado 5. La redundancia (`VIEJO` implica `esViejo`) es a propósito: la vista usa `decidir()` para elegir qué cuerpo mostrar y `esViejo()` para decidir la banda.

- [ ] **Step 1: Escribir los tests**

Crear `tests/Unit/TicketSales/EstadoDelTableroTest.php`:

```php
<?php

use CarlVallory\KrayinTicketSales\Support\EstadoDelTablero;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->estado = new EstadoDelTablero(15);
    $this->ahora  = CarbonImmutable::parse('2026-08-07 17:30:00', 'America/Asuncion');
});

test('snapshot de hoy y fresco es el tablero normal', function () {
    expect($this->estado->decidir(
        '2026-08-07',
        $this->ahora->subMinutes(3),
        '2026-08-07',
        11,
        $this->ahora
    ))->toBe(EstadoDelTablero::NORMAL);
});

test('snapshot de hoy con 15 minutos o más es viejo', function () {
    expect($this->estado->decidir(
        '2026-08-07',
        $this->ahora->subMinutes(15),
        '2026-08-07',
        11,
        $this->ahora
    ))->toBe(EstadoDelTablero::VIEJO);
});

test('a los 14 minutos todavía es normal: el umbral es 15, no "más de 15"', function () {
    expect($this->estado->decidir(
        '2026-08-07',
        $this->ahora->subMinutes(14),
        '2026-08-07',
        11,
        $this->ahora
    ))->toBe(EstadoDelTablero::NORMAL);
});

test('un snapshot de otro día no muestra sus funciones', function () {
    expect($this->estado->decidir(
        '2026-08-06',
        $this->ahora->subMinutes(2),
        '2026-08-07',
        11,
        $this->ahora
    ))->toBe(EstadoDelTablero::OTRO_DIA);
});

test('otro día gana sobre "sin funciones": nunca se muestra como día vacío', function () {
    // El sync viene fallando desde ayer y hoy no hay filas escritas todavía.
    expect($this->estado->decidir(
        '2026-08-06',
        $this->ahora->subDay(),
        '2026-08-07',
        0,
        $this->ahora
    ))->toBe(EstadoDelTablero::OTRO_DIA);
});

test('otro día gana sobre "viejo": el aviso correcto es que el sync falla', function () {
    expect($this->estado->decidir(
        '2026-08-06',
        $this->ahora->subDay(),
        '2026-08-07',
        11,
        $this->ahora
    ))->toBe(EstadoDelTablero::OTRO_DIA);
});

test('sin cabecera es "nunca corrió", no "sin funciones"', function () {
    expect($this->estado->decidir(null, null, '2026-08-07', 0, $this->ahora))
        ->toBe(EstadoDelTablero::NUNCA);
});

test('hoy sincronizado y sin funciones es el día vacío', function () {
    expect($this->estado->decidir(
        '2026-08-07',
        $this->ahora->subMinutes(2),
        '2026-08-07',
        0,
        $this->ahora
    ))->toBe(EstadoDelTablero::SIN_FUNCIONES);
});

test('esViejo es independiente del estado, así la banda también sale en el día vacío', function () {
    $syncedAt = $this->ahora->subMinutes(40);

    expect($this->estado->decidir('2026-08-07', $syncedAt, '2026-08-07', 0, $this->ahora))
        ->toBe(EstadoDelTablero::SIN_FUNCIONES);

    expect($this->estado->esViejo($syncedAt, $this->ahora))->toBeTrue();
});

test('sin cabecera, esViejo es verdadero', function () {
    expect($this->estado->esViejo(null, $this->ahora))->toBeTrue();
});

test('el umbral sale del constructor y no está cableado', function () {
    $laxo = new EstadoDelTablero(60);

    expect($laxo->decidir('2026-08-07', $this->ahora->subMinutes(30), '2026-08-07', 11, $this->ahora))
        ->toBe(EstadoDelTablero::NORMAL);

    expect($laxo->esViejo($this->ahora->subMinutes(30), $this->ahora))->toBeFalse();
});

test('un synced_at en el futuro no se toma como viejo', function () {
    // Un reloj corrido en el servidor no debe disparar la banda de antigüedad.
    expect($this->estado->esViejo($this->ahora->addMinutes(5), $this->ahora))->toBeFalse();
});
```

- [ ] **Step 2: Correr los tests para verlos fallar**

Run: `php artisan test tests/Unit/TicketSales/EstadoDelTableroTest.php`
Expected: FAIL en los 12, con `Class "CarlVallory\KrayinTicketSales\Support\EstadoDelTablero" not found`.

- [ ] **Step 3: Escribir la clase**

Crear `packages/CarlVallory/KrayinTicketSales/src/Support/EstadoDelTablero.php`:

```php
<?php

namespace CarlVallory\KrayinTicketSales\Support;

use Carbon\CarbonImmutable;

/**
 * Cuál de los cinco estados del §4.3 le toca al tablero. Pura: no toca base,
 * no toca reloj del sistema, el "ahora" viaja como argumento.
 *
 * El estado que justifica la clase entera es OTRO_DIA. Si el sync se rompe un
 * viernes, el snapshot del jueves tiene funciones con números plausibles;
 * mostrarlo sería el peor error posible, porque nadie lo notaría. Comparar la
 * fecha del snapshot contra el día de negocio lo hace imposible.
 */
class EstadoDelTablero
{
    public const NORMAL        = 'normal';
    public const VIEJO         = 'viejo';
    public const OTRO_DIA      = 'otro_dia';
    public const NUNCA         = 'nunca';
    public const SIN_FUNCIONES = 'sin_funciones';

    public function __construct(private int $umbralMinutos = 15)
    {
    }

    /**
     * El orden de los guards es el contrato:
     *
     * 1. Sin cabecera no se puede decir nada del día -> NUNCA.
     * 2. Cabecera de otro día -> OTRO_DIA, y gana sobre todo lo demás. Un
     *    snapshot de ayer no puede presentarse ni como día vacío ni como dato
     *    viejo de hoy: las dos lecturas serían falsas.
     * 3. Recién ahí importa si hay funciones, y por último la antigüedad.
     */
    public function decidir(
        ?string $fechaDelSync,
        ?CarbonImmutable $syncedAt,
        string $hoy,
        int $cantidadFunciones,
        CarbonImmutable $ahora
    ): string {
        if ($fechaDelSync === null) {
            return self::NUNCA;
        }

        if ($fechaDelSync !== $hoy) {
            return self::OTRO_DIA;
        }

        if ($cantidadFunciones === 0) {
            return self::SIN_FUNCIONES;
        }

        return $this->esViejo($syncedAt, $ahora) ? self::VIEJO : self::NORMAL;
    }

    /**
     * Independiente del estado, para que la banda de antigüedad también salga
     * sobre el día sin funciones: decir "hoy no hay funciones" con un dato de
     * hace 40 minutos es afirmar más de lo que se sabe.
     */
    public function esViejo(?CarbonImmutable $syncedAt, CarbonImmutable $ahora): bool
    {
        if ($syncedAt === null) {
            return true;
        }

        // Sin signo el `diffInMinutes` de Carbon devuelve el valor absoluto, y
        // un reloj corrido hacia adelante dispararía la banda sin motivo.
        return $syncedAt->diffInMinutes($ahora, false) >= $this->umbralMinutos;
    }
}
```

- [ ] **Step 4: Correr los tests**

Run: `php artisan test tests/Unit/TicketSales/EstadoDelTableroTest.php`
Expected: **PASS, 12 tests.**

- [ ] **Step 5: Mutaciones antes de cerrar**

| Mutación | Debe morir |
|---|---|
| Borrar el guard `$fechaDelSync !== $hoy` | `un snapshot de otro día no muestra sus funciones` |
| Mover el guard de `$cantidadFunciones === 0` **arriba** del de `$fechaDelSync` | `otro día gana sobre "sin funciones"...` |
| Mover el chequeo de antigüedad arriba del de `$fechaDelSync` | `otro día gana sobre "viejo"...` |
| En `esViejo()`, cambiar `>=` por `>` | `snapshot de hoy con 15 minutos o más es viejo` |
| En `esViejo()`, cambiar `$this->umbralMinutos` por `15` fijo | `el umbral sale del constructor y no está cableado` |
| En `esViejo()`, sacar el `false` del `diffInMinutes` | `un synced_at en el futuro no se toma como viejo` |
| En `esViejo()`, devolver `false` cuando `$syncedAt === null` | `sin cabecera, esViejo es verdadero` |
| En `decidir()`, devolver `SIN_FUNCIONES` en lugar de `NUNCA` cuando no hay cabecera | `sin cabecera es "nunca corrió", no "sin funciones"` |

- [ ] **Step 6: Commit**

```bash
git add packages/CarlVallory/KrayinTicketSales/src/Support/EstadoDelTablero.php \
        tests/Unit/TicketSales/EstadoDelTableroTest.php
git commit -m "feat(ticket-sales): los cinco estados del tablero como clase pura"
```

---

### Task 6: Controlador, ruta, menú, ACL y vista

**Files:**
- Create: `packages/CarlVallory/KrayinTicketSales/src/Http/Controllers/TicketSalesController.php`
- Create: `packages/CarlVallory/KrayinTicketSales/src/Http/routes.php`
- Create: `packages/CarlVallory/KrayinTicketSales/src/Config/menu.php`
- Create: `packages/CarlVallory/KrayinTicketSales/src/Config/acl.php`
- Create: `packages/CarlVallory/KrayinTicketSales/src/Resources/views/index.blade.php`
- Modify: `packages/CarlVallory/KrayinTicketSales/src/Providers/KrayinTicketSalesServiceProvider.php`
- Test: `tests/Feature/TicketSales/TicketSalesDashboardTest.php`

**Interfaces:**
- Consumes: `TicketSalesSnapshot`, `TicketSalesSync`, `BusinessDay::todayString()`, `EstadoDelTablero::decidir()` y `::esViejo()`, config `ticket-sales.stale_after_minutes`.
- Produces: la ruta con nombre `krayin.ticket-sales.index` en `admin/ticket-sales`.

- [ ] **Step 1: Crear el controlador**

Crear `packages/CarlVallory/KrayinTicketSales/src/Http/Controllers/TicketSalesController.php`:

```php
<?php

namespace CarlVallory\KrayinTicketSales\Http\Controllers;

use CarlVallory\KrayinTicketSales\Models\TicketSalesSnapshot;
use CarlVallory\KrayinTicketSales\Models\TicketSalesSync;
use CarlVallory\KrayinTicketSales\Support\BusinessDay;
use CarlVallory\KrayinTicketSales\Support\EstadoDelTablero;
use Carbon\CarbonImmutable;
use Illuminate\Routing\Controller;

class TicketSalesController extends Controller
{
    public function index(BusinessDay $businessDay)
    {
        $hoy = $businessDay->todayString();

        $sync = TicketSalesSync::orderByDesc('fecha')->first();

        // El orden es el mismo criterio que usa el servicio —hora, y a igual
        // hora el show— para que el tablero no invente un orden propio. Las
        // funciones sin hora van al final.
        $funciones = TicketSalesSnapshot::where('fecha', $hoy)
            ->orderByRaw('hora IS NULL, hora')
            ->orderBy('show_nombre')
            ->get();

        $decisor = new EstadoDelTablero((int) config('ticket-sales.stale_after_minutes', 15));
        $ahora   = CarbonImmutable::now();

        $syncedAt      = $sync?->synced_at ? CarbonImmutable::instance($sync->synced_at) : null;
        $fechaDelSync  = $sync?->fecha?->format('Y-m-d');

        return view('krayin-ticket-sales::index', [
            'hoy'              => $hoy,
            'estado'           => $decisor->decidir($fechaDelSync, $syncedAt, $hoy, $funciones->count(), $ahora),
            'esViejo'          => $decisor->esViejo($syncedAt, $ahora),
            'funciones'        => $funciones,
            'syncedAt'         => $sync?->synced_at,
            'fechaDelSync'     => $fechaDelSync,
            'totalEntradas'    => (int) $funciones->sum('entradas_vendidas'),
            'totalReagendadas' => (int) $funciones->sum('entradas_reagendadas'),
            'totalNeto'        => (int) $funciones->sum('recaudacion_neta'),
            'totalBruto'       => (int) $funciones->sum('recaudacion_bruta'),
            'cantidadShows'    => $funciones->pluck('producto_id')->unique()->count(),
        ]);
    }
}
```

> Se lee la **última** cabecera por fecha, no la de hoy: si se leyera solo la de hoy, un sync que viene fallando desde ayer se vería igual que "nunca corrió", y el §4.3 los distingue.

- [ ] **Step 2: Crear ruta, menú y ACL**

`packages/CarlVallory/KrayinTicketSales/src/Http/routes.php`:

```php
<?php

use CarlVallory\KrayinTicketSales\Http\Controllers\TicketSalesController;
use Illuminate\Support\Facades\Route;

Route::group(['middleware' => ['web', 'admin_locale', 'user'], 'prefix' => 'admin/ticket-sales'], function () {
    Route::get('', [TicketSalesController::class, 'index'])->name('krayin.ticket-sales.index');
});
```

`packages/CarlVallory/KrayinTicketSales/src/Config/menu.php`:

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

`packages/CarlVallory/KrayinTicketSales/src/Config/acl.php`:

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

> El grupo de middleware `['web', 'admin_locale', 'user']` y el nombre `krayin.*` copian lo que ya usan `KrayinFinancialReports` y `KrayinOperations` en producción. El guard `user` es el que redirige al login, donde vive el botón de Google de `KrayinGoogleAuth` con `allowed_domains = ['muci.org']`.

- [ ] **Step 3: Registrar todo en el ServiceProvider**

En `boot()`, junto al `loadViewsFrom`:

```php
        $this->loadRoutesFrom(__DIR__ . '/../Http/routes.php');
```

En `register()`, después del `mergeConfigFrom` de `ticket-sales`:

```php
        $this->mergeConfigFrom(__DIR__ . '/../Config/menu.php', 'menu.admin');
        $this->mergeConfigFrom(__DIR__ . '/../Config/acl.php', 'acl');
```

- [ ] **Step 4: Crear la vista**

Crear `packages/CarlVallory/KrayinTicketSales/src/Resources/views/index.blade.php`:

```blade
<x-admin::layouts>
    <x-slot:title>
        Entradas del día
    </x-slot>

    {{-- El snapshot se refresca cada 5 minutos; la página se recarga a la par. --}}
    <meta http-equiv="refresh" content="300">

    {{-- El layout admin de Krayin carga Inter, no Poppins. La marca la trae la vista. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">

    <style>
        .muci-titulo   { font-family: 'Poppins', sans-serif; font-weight: 700; }
        .muci-cifra    { font-family: 'Poppins', sans-serif; font-weight: 700; }
        .muci-tarjeta          { border-left: 4px solid #6950A1; }
        .muci-tarjeta--ventas  { border-left-color: #00B26B; }
        .muci-tarjeta--plata   { border-left-color: #F37043; }
        .muci-banda    { background: #F17DB1; color: #000000; }
        .muci-alerta   { background: #F37043; color: #000000; }
    </style>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap mb-5">
        <div class="grid gap-1.5">
            <p class="muci-titulo text-2xl dark:text-white">Entradas del día</p>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Funciones del {{ \Carbon\Carbon::parse($hoy)->format('d/m/Y') }}
            </p>
        </div>

        @if ($syncedAt)
            {{-- El `data-viejo` no es decorativo: la clase `muci-banda` también
                 aparece en el <style> de arriba, así que un test que la busque
                 pasaría siempre. Este atributo solo existe cuando el dato es
                 viejo de verdad. --}}
            <div
                data-viejo="{{ $esViejo ? '1' : '0' }}"
                class="text-sm rounded-md px-3 py-1.5 {{ $esViejo ? 'muci-banda font-semibold' : 'text-gray-500 dark:text-gray-400' }}"
            >
                Actualizado {{ $syncedAt->diffForHumans() }}
            </div>
        @endif
    </div>

    @if ($estado === \CarlVallory\KrayinTicketSales\Support\EstadoDelTablero::OTRO_DIA)

        {{-- El estado más caro de todos: hay datos plausibles, pero son de otro
             día. No se muestran las funciones, a propósito. --}}
        <div class="muci-alerta rounded-lg p-6">
            <p class="muci-titulo text-lg">La sincronización viene fallando</p>
            <p class="mt-2 text-sm">
                El último dato disponible es del
                <strong>{{ \Carbon\Carbon::parse($fechaDelSync)->format('d/m/Y') }}</strong>,
                actualizado {{ $syncedAt?->diffForHumans() }}.
                No se muestran esas funciones porque no son las de hoy.
            </p>
            <p class="mt-2 text-sm">
                Revisar el log del CRM y que el servicio responda en
                <code>127.0.0.1:8081</code>.
            </p>
        </div>

    @elseif ($estado === \CarlVallory\KrayinTicketSales\Support\EstadoDelTablero::NUNCA)

        <div class="rounded-lg border border-gray-200 bg-white p-8 text-center dark:border-gray-800 dark:bg-gray-900">
            <p class="muci-titulo text-lg dark:text-white">Todavía no hay datos</p>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                La sincronización nunca corrió. Ejecutar
                <code>php artisan ticket-sales:sync</code> en el servidor.
            </p>
        </div>

    @else

        <div class="mt-3.5 flex gap-4 max-sm:flex-wrap">
            <div class="muci-tarjeta flex flex-1 flex-col gap-2 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-base font-semibold text-gray-600 dark:text-gray-300">Funciones</p>
                <p class="muci-cifra text-3xl text-gray-800 dark:text-white">{{ $funciones->count() }}</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $cantidadShows }} shows</p>
            </div>

            <div class="muci-tarjeta muci-tarjeta--ventas flex flex-1 flex-col gap-2 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-base font-semibold text-gray-600 dark:text-gray-300">Entradas vendidas</p>
                <p class="muci-cifra text-3xl text-gray-800 dark:text-white">{{ $totalEntradas }}</p>
                @if ($totalReagendadas > 0)
                    <p class="text-sm" style="color: #F37043;">{{ $totalReagendadas }} reagendadas, aparte</p>
                @endif
            </div>

            <div class="muci-tarjeta muci-tarjeta--plata flex flex-1 flex-col gap-2 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-base font-semibold text-gray-600 dark:text-gray-300">Recaudación</p>
                <p class="muci-cifra text-3xl text-gray-800 dark:text-white">{{ core()->formatBasePrice($totalBruto) }}</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Neto {{ core()->formatBasePrice($totalNeto) }}
                </p>
            </div>
        </div>

        <div class="mt-4 overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <table class="w-full text-left">
                <thead class="border-b border-gray-200 dark:border-gray-800">
                    <tr class="text-sm font-semibold text-gray-600 dark:text-gray-300">
                        <th class="p-4">Show</th>
                        <th class="p-4">Función</th>
                        <th class="p-4 text-right">Entradas</th>
                        <th class="p-4 text-right">Cupos habilitados</th>
                        <th class="p-4 text-right">Recaudación</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($funciones as $funcion)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="p-4 dark:text-white">{{ $funcion->show_nombre }}</td>
                            <td class="p-4 text-gray-600 dark:text-gray-400">{{ $funcion->slot }}</td>
                            <td class="p-4 text-right font-semibold dark:text-white">
                                {{ $funcion->entradas_vendidas }}
                                @if ($funcion->entradas_reagendadas > 0)
                                    <span class="text-xs font-normal" style="color: #F37043;">
                                        ({{ $funcion->entradas_reagendadas }} reag.)
                                    </span>
                                @endif
                            </td>
                            <td class="p-4 text-right text-gray-600 dark:text-gray-400">
                                {{ $funcion->cupos_habilitados === null ? '—' : $funcion->cupos_habilitados }}
                            </td>
                            <td class="p-4 text-right dark:text-white">
                                {{ core()->formatBasePrice($funcion->recaudacion_bruta) }}
                            </td>
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
            «Cupos habilitados» es el remanente de venta online que informa FooEvents. Un 0 significa
            venta online cerrada, no sala llena — por eso no se muestra un porcentaje de ocupación.
            Las entradas reagendadas se informan aparte y no se suman.
        </p>

    @endif
</x-admin::layouts>
```

- [ ] **Step 5: Escribir el test**

Crear `tests/Feature/TicketSales/TicketSalesDashboardTest.php`:

```php
<?php

use CarlVallory\KrayinTicketSales\Models\TicketSalesSnapshot;
use CarlVallory\KrayinTicketSales\Models\TicketSalesSync;
use CarlVallory\KrayinTicketSales\Support\BusinessDay;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->hoy = app(BusinessDay::class)->todayString();

    $this->admin = getDefaultAdmin();

    if (! $this->admin) {
        $this->markTestSkipped('No hay usuarios en la base local.');
    }
});

function sembrarFuncion(string $fecha, array $sobre = []): TicketSalesSnapshot
{
    return TicketSalesSnapshot::create(array_merge([
        'fecha'                => $fecha,
        'producto_id'          => 192637,
        'show_nombre'          => 'Entrada Bioestanque',
        'slot'                 => 'BioEstanque (16:00) (17:00)',
        'hora'                 => '17:00',
        'entradas_vendidas'    => 2,
        'entradas_reagendadas' => 0,
        'cupos_habilitados'    => 18,
        'recaudacion_neta'     => 63636,
        'recaudacion_bruta'    => 70000,
    ], $sobre));
}

function sembrarSync(string $fecha, ?\Carbon\CarbonInterface $syncedAt = null): TicketSalesSync
{
    return TicketSalesSync::create([
        'fecha'       => $fecha,
        'generado_en' => $syncedAt ?? now(),
        'avisos'      => [],
        'synced_at'   => $syncedAt ?? now(),
    ]);
}

test('un visitante sin sesión es redirigido al login', function () {
    $this->get(route('krayin.ticket-sales.index'))->assertRedirect();
});

test('estado 1: snapshot fresco muestra las funciones del día', function () {
    sembrarSync($this->hoy);
    sembrarFuncion($this->hoy);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertOk()
        ->assertSee('Entrada Bioestanque')
        ->assertSee('BioEstanque (16:00) (17:00)')
        ->assertDontSee('La sincronización viene fallando');
});

test('estado 2: snapshot viejo muestra las funciones y además la banda', function () {
    sembrarSync($this->hoy, now()->subMinutes(40));
    sembrarFuncion($this->hoy);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertOk()
        ->assertSee('Entrada Bioestanque')
        ->assertSee('data-viejo="1"', false);
});

test('estado 1: el snapshot fresco NO trae la banda de antigüedad', function () {
    sembrarSync($this->hoy, now()->subMinutes(3));
    sembrarFuncion($this->hoy);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertOk()
        ->assertSee('data-viejo="0"', false)
        ->assertDontSee('data-viejo="1"', false);
});

test('estado 3: un snapshot de ayer NO muestra sus funciones', function () {
    $ayer = \Carbon\Carbon::parse($this->hoy)->subDay()->format('Y-m-d');

    sembrarSync($ayer, now()->subDay());
    sembrarFuncion($ayer, ['show_nombre' => 'Show de ayer']);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertOk()
        ->assertSee('La sincronización viene fallando')
        ->assertDontSee('Show de ayer');
});

test('estado 4: sin cabecera dice que el sync nunca corrió', function () {
    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertOk()
        ->assertSee('Todavía no hay datos')
        ->assertSee('ticket-sales:sync');
});

test('estado 5: hoy sincronizado y sin funciones dice que no hay funciones', function () {
    sembrarSync($this->hoy);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertOk()
        ->assertSee('No hay funciones programadas para hoy')
        ->assertDontSee('Todavía no hay datos');
});

test('una función sin ventas se muestra en cero, no desaparece', function () {
    sembrarSync($this->hoy);
    sembrarFuncion($this->hoy);
    sembrarFuncion($this->hoy, [
        'show_nombre'       => 'Historias Estelares',
        'slot'              => 'Entrada general (08:30)',
        'hora'              => '08:30',
        'entradas_vendidas' => 0,
        'cupos_habilitados' => 0,
        'recaudacion_neta'  => 0,
        'recaudacion_bruta' => 0,
    ]);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertOk()
        ->assertSee('Historias Estelares');
});

test('las funciones salen ordenadas por hora', function () {
    sembrarSync($this->hoy);
    sembrarFuncion($this->hoy, ['show_nombre' => 'Tarde', 'slot' => 'S1', 'hora' => '19:00']);
    sembrarFuncion($this->hoy, ['show_nombre' => 'Mañana', 'slot' => 'S2', 'hora' => '08:30']);

    $html = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertOk()
        ->getContent();

    expect(strpos($html, 'Mañana'))->toBeLessThan(strpos($html, 'Tarde'));
});

test('cupos_habilitados en null se muestra como raya, no como cero', function () {
    sembrarSync($this->hoy);
    sembrarFuncion($this->hoy, ['cupos_habilitados' => null]);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertOk()
        ->assertSee('—');
});

test('la vista no muestra los avisos: su público es quien mantiene el sistema', function () {
    TicketSalesSync::create([
        'fecha'       => $this->hoy,
        'generado_en' => now(),
        'avisos'      => [['tipo' => 'estado_desconocido', 'detalle' => 'wc-inventado']],
        'synced_at'   => now(),
    ]);
    sembrarFuncion($this->hoy);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertOk()
        ->assertDontSee('wc-inventado')
        ->assertDontSee('estado_desconocido');
});
```

- [ ] **Step 6: Correr los tests**

Run: `php artisan test tests/Feature/TicketSales/TicketSalesDashboardTest.php`
Expected: **PASS, 12 tests.**

> **Sobre el ACL.** El §5.3 del spec pide "solo cuentas `@muci.org`, como
> `KrayinGoogleAuth`". Esa restricción de dominio vive en `KrayinGoogleAuth`
> (`allowed_domains = ['muci.org']`) y ya está verificada end-to-end en
> producción; este paquete solo hereda el guard `user`. Por eso el test de acá
> comprueba lo que **sí** es responsabilidad del paquete: que sin sesión la ruta
> redirige al login. Que ese login solo acepte `@muci.org` no se vuelve a testear.

> Si todos salen `skipped`, la base `krayin` de desarrollo no tiene el usuario id 1. No es un fallo del código, pero **sí bloquea la task**: sin esos tests los cinco estados no están verificados. Crear un admin local antes de seguir.

- [ ] **Step 7: Verificar la vista en el navegador**

Run: `php artisan serve` y abrir `http://127.0.0.1:8000/admin/ticket-sales`.

Verificar a ojo:
- El menú lateral muestra «Entradas del día».
- Los títulos y las cifras salen en Poppins, no en Inter. Si salen en Inter, el `<link>` de Google Fonts no cargó — revisar la consola del navegador.
- Los bordes de las tarjetas son `#6950A1`, `#00B26B` y `#F37043`.

- [ ] **Step 8: Mutaciones antes de cerrar**

| Mutación | Debe morir |
|---|---|
| En el controlador, cambiar `where('fecha', $hoy)` por `where('fecha', $fechaDelSync)` | `estado 3: un snapshot de ayer NO muestra sus funciones` |
| En el controlador, cambiar `TicketSalesSync::orderByDesc('fecha')->first()` por `::where('fecha', $hoy)->first()` | `estado 3: ...` (pasaría a verse como estado 4) |
| En la vista, borrar la rama `@if ($estado === ...OTRO_DIA)` | `estado 3: ...` |
| En la vista, unificar las ramas `NUNCA` y el `@empty` de la tabla en un solo texto | `estado 4: ...` o `estado 5: ...` — el §4.3 exige que no se confundan |
| En el controlador, cambiar `esViejo(...)` por `false` fijo | `estado 2: snapshot viejo muestra las funciones y además la banda` |
| En el controlador, sacar `orderByRaw('hora IS NULL, hora')` | `las funciones salen ordenadas por hora` |
| En la vista, cambiar `$funcion->cupos_habilitados === null ? '—' : ...` por `?? 0` | `cupos_habilitados en null se muestra como raya, no como cero` |
| En la vista, agregar un `@foreach` que imprima los avisos | `la vista no muestra los avisos...` |
| En el provider, borrar el `singleton(BusinessDay::class, ...)` | Ninguno de estos —Laravel resuelve la clase igual, con el default `America/Asuncion`—. Es la mutación anotada en la Task 1: **no perseguirla**, el binding solo existe para que la zona salga de la config |

- [ ] **Step 9: Correr toda la suite del paquete**

Run: `php artisan test --filter=TicketSales`
Expected: **PASS, 62 tests** — 6 de `BusinessDay`, 12 de `EstadoDelTablero`, 16 del cliente, 5 del snapshot, 11 del comando y 12 del tablero. Ni una falla ni un solo skip.

- [ ] **Step 10: Commit**

```bash
git add packages/CarlVallory/KrayinTicketSales/src/Http/ \
        packages/CarlVallory/KrayinTicketSales/src/Config/menu.php \
        packages/CarlVallory/KrayinTicketSales/src/Config/acl.php \
        packages/CarlVallory/KrayinTicketSales/src/Resources/ \
        packages/CarlVallory/KrayinTicketSales/src/Providers/KrayinTicketSalesServiceProvider.php \
        tests/Feature/TicketSales/TicketSalesDashboardTest.php
git commit -m "feat(ticket-sales): tablero con los cinco estados, ruta, menú y ACL"
```

---

### Task 7: Despliegue y verificación end-to-end

La primera vez que el CRM y el servicio se hablan de verdad. Todo lo anterior corrió contra `Http::fake()`.

**Files:**
- Modify: `packages/CarlVallory/KrayinTicketSales/README.md`
- Modify: `packages/CarlVallory/KrayinTicketSales/CLAUDE.md`
- Modify (en el servidor, no versionado): `.env` del CRM

- [ ] **Step 1: Actualizar el README del paquete**

Reemplazar el contenido de `packages/CarlVallory/KrayinTicketSales/README.md` por:

````markdown
# KrayinTicketSales

Tablero de entradas vendidas del día para el CRM: qué funciones hay hoy y
cuántas entradas se vendieron para cada una.

## Cómo funciona

El CRM **no lee la base de WordPress**. Le pregunta al servicio intermedio, que
es el único con esa credencial:

```
GET http://127.0.0.1:8081/v1/funciones?fecha=YYYY-MM-DD
Authorization: Bearer <token>
```

`ticket-sales:sync` corre cada 5 minutos, valida la forma de la respuesta y
reemplaza el snapshot local dentro de una transacción. La vista lee únicamente
las tablas locales, así que si el servicio se cae el tablero sigue mostrando el
último dato bueno con un aviso de antigüedad.

Repo del servicio: `carlvallory/servicio-fooevents` (privado).

## Ninguna falla toca el snapshot anterior

| Situación | Snapshot | Log |
|---|---|---|
| `503` / `500` / timeout | Intacto | `warning`, tras un reintento a los 2s |
| `401` | Intacto | `error` — es configuración, no red |
| `422` | Intacto | `error` — es un bug del CRM |
| Respuesta con forma inesperada | Intacto | `error` |

La última fila es la que más importa: una respuesta malformada no puede vaciar
el tablero. Se valida la forma antes de escribir y, si no cuadra, se descarta
entera.

## Los avisos no se muestran

El servicio manda `avisos` con `tipo` y `detalle`. Se guardan en
`muci_ticket_sales_sync` y se loguean como `warning`, pero **no se muestran en el
tablero**: su público es quien mantiene el sistema, no boletería.

Los códigos son cinco hoy —`json_ilegible`, `fecha_no_parseable`,
`estado_desconocido`, `prorrateo_ambiguo`, `linea_faltante`— y **el CRM no debe
fallar ante uno que no conozca**. Agregarlos es aditivo y ya pasó una vez.

## Cupos habilitados, no ocupación

`cupos_habilitados` es el remanente de venta online que informa FooEvents. Un 0
significa venta online cerrada, **no** sala llena: el 2026-08-07 había funciones
con remanente 0 y cero ventas. Por eso no se deriva ningún porcentaje de
ocupación — haría falta una fuente de aforo que en esta base no existe.

## Datos personales

El tablero es agregado. No muestra ni almacena nombres, cédulas, emails ni
teléfonos.

## Configuración

```
FOOEVENTS_SERVICE_URL=http://127.0.0.1:8081
FOOEVENTS_SERVICE_TOKEN=<el mismo token que FOOEVENTS_TOKEN del servicio>
```

## Despliegue

```bash
# En el servidor, en /var/www/crm. El CRM corre en PHP 8.2, no 8.4.
/usr/bin/php8.2 /usr/local/bin/composer update carlvallory/krayin-ticket-sales
/usr/bin/php8.2 artisan migrate
/usr/bin/php8.2 artisan config:cache

# Primera corrida manual
/usr/bin/php8.2 artisan ticket-sales:sync

# Verificar que el scheduler lo tomó
/usr/bin/php8.2 artisan schedule:list | grep ticket-sales
```

Los tests no corren en el servidor: el CRM se instala con `--no-dev` y ahí no hay
Pest. La verificación en producción es el chequeo de números del `sync`.
````

- [ ] **Step 2: Actualizar el `CLAUDE.md` del paquete**

El `CLAUDE.md` sigue describiendo un paquete que lee `muci` directo. Estas son
las secciones concretas a corregir (referenciadas por título, no por línea):

| Sección | Qué hacer |
|---|---|
| `## Estado al 2026-08-14 — leer esto antes de tocar nada` | Reescribir: ya no hay migración a medias. El paquete consume el servicio y punto |
| `## Las cuatro trampas de la base `muci`` | **Eliminar entera.** Ya lleva el aviso de que la Task 7 la borra. Esas trampas son responsabilidad del servicio y están documentadas en su repo; repetirlas acá invita a que alguien vuelva a consultar la base desde el CRM |
| En `## Restricciones que no se negocian`, la viñeta «Solo lectura sobre `muci`» | Reemplazar por: «El CRM **no tiene** credencial de `muci`. La única forma de llegar a esos datos es el servicio en `127.0.0.1:8081`». Las dos viñetas nuevas —la de los `tipo` desconocidos y la de que ninguna falla toca el snapshot— **se quedan** |
| En `## Entorno local`, «No existe la base `muci` localmente» y los fixtures de los 18 productos | Reemplazar por: los tests **no necesitan ninguna base ajena**, todo pasa por `Http::fake()` contra `tests/Fixtures/fooevents/respuesta-ejemplo.json` |
| En `## Entorno local`, el procedimiento de SSH + `MYSQL_PWD` | Eliminar. Quien necesite consultar la base va al repo del servicio |
| `## Prueba de aceptación` | Reemplazar: ya no la sostiene `BookingsOptionsParserTest` —la Task 1 borró ese archivo— sino el chequeo de números del `sync` contra producción, `11 funciones \| 26 entradas \| 0 avisos` |

En el `README.md` no queda nada por hacer: el Step 1 lo reemplazó entero.

Run: `grep -n 'woocommerce\|WC_DB\|anthropic_readonly\|wpzv_\|BookingsOptionsParser\|SpanishDateParser' packages/CarlVallory/KrayinTicketSales/CLAUDE.md packages/CarlVallory/KrayinTicketSales/README.md`
Expected: **sin resultados** al terminar el step.

> Las menciones a `muci.org` como dominio y a `@muci.org` como restricción de
> login **se quedan**: son el museo, no la base. El `grep` de arriba no las
> toca a propósito.

- [ ] **Step 3: Commit y publicar el paquete**

```bash
git add packages/CarlVallory/KrayinTicketSales/README.md \
        packages/CarlVallory/KrayinTicketSales/CLAUDE.md
git commit -m "docs(ticket-sales): el paquete consume el servicio, no la base"
```

Y en el repo propio del paquete, empujar a `carlvallory/krayin-ticket-sales`.

> Si el agente SSH de la máquina de desarrollo rechaza la firma (`agent refused
> operation`), empujar por HTTPS con el token de `gh` y dejar el credential helper
> **local al repo**, sin tocar la config global. Ya pasó al publicar el servicio.

- [ ] **Step 4: Configurar el `.env` de producción**

En el servidor, en `/var/www/crm/.env`:

```
FOOEVENTS_SERVICE_URL=http://127.0.0.1:8081
FOOEVENTS_SERVICE_TOKEN=<el mismo valor que FOOEVENTS_TOKEN del servicio>
```

Y **borrar** las seis `WC_DB_*` si siguen ahí.

El token se lee del `.env` del servicio:

```bash
sudo grep FOOEVENTS_TOKEN /var/www/servicio-fooevents/.env
```

> Ese `.env` está en `640 fooevents:fooevents` justamente para que el WordPress
> de al lado no lo pueda leer. Copiar el valor con `sudo`, y no dejarlo en el
> historial de shell.

- [ ] **Step 5: Desplegar**

```bash
cd /var/www/crm
/usr/bin/php8.2 /usr/local/bin/composer update carlvallory/krayin-ticket-sales
/usr/bin/php8.2 artisan migrate
/usr/bin/php8.2 artisan config:cache
```

Expected: la migración informa `Ran` para `create_muci_ticket_sales_tables`.

- [ ] **Step 6: PUNTO DE PARADA — verificar los números contra el servicio**

```bash
/usr/bin/php8.2 artisan ticket-sales:sync --fecha=2026-08-07
```

Expected: **`11 funciones | 26 entradas | 0 avisos`** — exactamente lo que devolvió
el servicio en su propia verificación final.

Los tres modos de falla que ese número descarta:
- **7 funciones** sería el servicio ignorando la forma B del JSON.
- **6 funciones** sería la programación sin cruzarse con las ventas.
- Las **5 funciones sin ventas** tienen que aparecer, en cero.

Contrastar contra el servicio directamente:

```bash
curl -s -H "Authorization: Bearer $TOKEN" \
  'http://127.0.0.1:8081/v1/funciones?fecha=2026-08-07' | head -c 400
```

> **Si los números no coinciden, parar.** Un desajuste entre lo que devuelve el
> servicio y lo que escribe el CRM es un bug de mapeo, que es exactamente la
> clase de falla silenciosa que este proyecto ya se comió una vez. No seguir con
> los steps de abajo hasta resolverlo.

- [ ] **Step 7: Verificar el día de hoy y el scheduler**

```bash
/usr/bin/php8.2 artisan ticket-sales:sync
/usr/bin/php8.2 artisan schedule:list | grep ticket-sales
```

Expected: el sync sin `--fecha` usa el día de Asunción y no el UTC (comparar con
`TZ=America/Asuncion date +%F`); `schedule:list` muestra `*/5 * * * *`.

- [ ] **Step 8: Verificar el tablero en el navegador**

Abrir `https://crm.muci.org/admin/ticket-sales` con una cuenta `@muci.org`.

Verificar:
- Se ven las funciones de hoy, con las de cero ventas incluidas.
- «Actualizado hace X» sin banda rosa (el sync acaba de correr).
- El menú lateral tiene «Entradas del día».
- Poppins en títulos y cifras.
- Cerrar sesión y entrar de nuevo a la URL: redirige al login.

- [ ] **Step 9: Verificar que ninguna falla toca el snapshot**

Con el tablero ya poblado:

```bash
sudo systemctl stop php8.4-fpm    # el pool del servicio, no el del CRM
/usr/bin/php8.2 artisan ticket-sales:sync
```

Expected: el comando **falla** con un mensaje de servicio no disponible, y el
tablero en el navegador **sigue mostrando las funciones**, ahora con la banda de
antigüedad. Después:

```bash
sudo systemctl start php8.4-fpm
/usr/bin/php8.2 artisan ticket-sales:sync
```

Expected: vuelve a la normalidad.

> Este step es la única prueba real de la promesa central del §4.2. Vale la pena
> el minuto de servicio caído: en producción esa promesa se va a cobrar sola
> tarde o temprano, y mejor saber ahora si se cumple.

- [ ] **Step 10: Esperar una corrida automática**

Esperar 5 minutos y verificar que el scheduler corrió solo:

```bash
/usr/bin/php8.2 artisan tinker --execute="echo \CarlVallory\KrayinTicketSales\Models\TicketSalesSync::orderByDesc('fecha')->first()->synced_at;"
```

Expected: un `synced_at` de hace menos de 5 minutos, sin que nadie haya corrido
el comando a mano.

- [ ] **Step 11: Anotar las desviaciones y cerrar**

Agregar a este archivo una sección `## Desviaciones encontradas al ejecutar
(Task 7, fecha)` con lo que el plan decía y lo que la realidad resultó ser.
Después:

```bash
git add docs/superpowers/plans/2026-08-14-dashboard-crm.md
git commit -m "docs(ticket-sales): Task 7 y cierre del plan del CRM"
```

---

## Puntos de parada

Tres, donde seguir sin resolver haría trabajo que después hay que deshacer:

1. **Task 1, Step 2.** Si algo fuera del paquete usa la conexión `woocommerce` o los parsers, este plan no lo contempla.
2. **Task 6, Step 6.** Si los tests del tablero salen todos `skipped` por falta de usuario admin local, los cinco estados quedan sin verificar y la Task 7 desplegaría a ciegas.
3. **Task 7, Step 6.** Si el CRM no reproduce el `11 funciones | 26 entradas | 0 avisos` del servicio, hay un bug de mapeo.
