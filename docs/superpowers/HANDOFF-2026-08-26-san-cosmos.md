# Handoff — Panel de San Cosmos en la pantalla de entradas

**Fecha:** 2026-08-26, cerrado 20:15 UTC
**Estado:** implementación **terminada, mergeada y desplegada como paquete**. No se
ve en la TV todavía, por dos cosas que no son código.

---

## Qué se pidió

Que en la vista de pantalla (la TV del hall) **todo lo que se proyecta en el domo
vaya a la izquierda, englobado como San Cosmos y sin el nombre de cada show** —
pero conservando horarios y ventas. Las actividades especiales, a la derecha con
su nombre.

## Qué se construyó

El panel izquierdo dejó de ser «el show que más vendió» y quedó reservado a una
**categoría**. El criterio **no vive en el código**: es una lista de categorías de
WooCommerce en `core_config`, editable desde `/admin/ticket-sales/configure`
junto con el rótulo del panel.

- **`servicio-fooevents`** agrega `categorias` (slugs de `product_cat`) a cada
  función del contrato `/v1/funciones`. Opcional para el CRM, y una forma
  inesperada no descarta el día.
- **`krayin-ticket-sales`** guarda la columna cruda en el snapshot, reparte los
  paneles por categoría, fusiona por hora **cruzando productos** a la izquierda
  (y **no** a la derecha), y trae la página de configuración.
- **`laravel-crm`** lleva los tests, la fixture canónica y estos documentos.

Diseño: `docs/superpowers/specs/2026-08-26-san-cosmos-panel-izquierdo-design.md`
Plan de 11 tasks: `docs/superpowers/plans/2026-08-26-san-cosmos-panel-izquierdo.md`
— con una sección **`Desviaciones encontradas al ejecutar`** que es la parte más
útil para releer.

## Dónde quedó todo

| Repo | Rama base | Commit | PR |
|---|---|---|---|
| `servicio-fooevents` | `main` | `99ad82a` | #1 merged |
| `krayin-ticket-sales` | `main` | `8ac34e7` (+ `a39a66f` de Carlos) | #1 merged |
| `laravel-crm` | `2.1` | `e9add96b` | #4 merged |

Tests: **194** en el CRM (`php artisan test tests/Unit/TicketSales tests/Feature/TicketSales`),
**63** en el servicio (**`php8.4 artisan test`** — el `php` del sistema es 8.3 y el
repo pide ≥8.4.1; 9 tests se saltean sin la base `muci`).

---

## Lo primero a hacer mañana

### 1. El sync está parado — es lo urgente y no es de esta feature

Dejó de escribir a las **16:50 UTC del 2026-08-26**; los días anteriores llegaba
hasta las 23:55. El servicio **está vivo** (401 en loopback, escuchando en 8081) y
**no hay ni una línea** de «no pudo actualizar el snapshot» en el log: no está
fallando, no está corriendo.

Hipótesis con buena evidencia: **el mutex de `->withoutOverlapping()` quedó
trabado.** A las 17:00 y 17:05 hay errores de `fopen` sobre
`storage/framework/cache/data/f1/3b/f13b7314...`, que es donde vive ese mutex.
Permisos descartados: los directorios son `www-data:www-data 775` y no hay
archivos root-owned nuevos.

```bash
cd /var/www/crm
/usr/bin/php8.2 artisan schedule:clear-cache
/usr/bin/php8.2 artisan ticket-sales:sync --fecha=2026-08-07
```

Si el mutex no era: mirar si el cron de `artisan schedule:run` está vivo, y si el
upgrade a Krayin 2.2.5 rompió el registro que el paquete hace vía
`$this->app->booted()`.

### 2. Categorizar 12 productos en WooCommerce

Es lo único que separa la feature de verse. Hoy están en `eventos`, y «Ticketera
SC 2.0» (slug **`san-cosmos`**) está vacía. Mientras tanto el panel izquierdo dice
«Hoy no hay funciones de San Cosmos», que es el degradado previsto.

| ID | Producto |
|---|---|
| 192862 | El Sistema Solar Expandido |
| 194055 | Marte: La travesía definitiva |
| 194154 | Misterios de tu Cerebro |
| 193817 | Historias Estelares: De estrellas a supernovas |
| 198951 | San Cosmos: una experiencia adaptada |
| 193653 | Entrada a San Cosmos |
| 196315 | Entrada a San Cosmos - hospitalidad |
| 198093 | Exploradores de Exoplanetas |
| 194339 | Las Constelaciones y el Zodíaco |
| 194099 | Mundos en órbita: Las Lunas del Sistema Solar |
| 194228 | El Sistema Solar - La hora tranqui |
| 193902 | Dinosaurios - Una historia de supervivencia |

**No van**, aunque estén cerca: `197624` Eclipse Lunar (sede Costanera), `192637`
Entrada Bioestanque y `192681` Sábado porã (sede San Cosmos pero
`entradas-cielo-abierto`: predio sí, domo no).

### 3. Desplegar el servicio

`/var/www/servicio-fooevents` está en `8b60436`, un commit **anterior** a este
trabajo, con **cero** ocurrencias de `categorias`. Hasta que se actualice, el campo
llega `null` y todo cae al panel derecho. Va con `/usr/bin/php8.4`.

### 4. Corregir el tope de 255 caracteres

En `TicketSalesController::TOPE_DEL_VALOR` puse 255 porque la migración de Krayin
**2.1** declara `core_config.value` como `string`. **En prod la columna es `text`.**
El guard falla del lado seguro, pero el mensaje de error es falso allá. Conviene
derivarlo del tipo real en vez de una constante.

### 5. La prueba de aceptación, que nunca se corrió

```bash
/usr/bin/php8.2 artisan ticket-sales:sync --fecha=2026-08-07
```

Tiene que dar **`11 funciones | 26 entradas | 0 avisos`**. **7 funciones** sería el
servicio ignorando la forma B del JSON, **6** la programación sin cruzarse con las
ventas, y las **5 funciones sin ventas** tienen que aparecer, en cero. Los números
de venta se mueven: contrastar contra el servicio en el momento.

---

## Lo que hay que saber antes de tocar esto

- **Prod corre Krayin `2.2.5`; el worktree y la rama base dicen `2.1.6`.** No hay
  rama `2.2`: los PRs van a `2.1`. Los tests corren contra 2.1.6 y el código se
  ejecuta contra 2.2.5, así que un supuesto tomado del core 2.1 puede ser falso
  en prod — el tope de 255 es exactamente eso. El upgrade arrastró Laravel 11/12,
  y **los otros 9 paquetes hermanos pueden tener la misma restricción pendiente**.
- **El slug no se deduce del nombre de la categoría.** «Ticketera SC 2.0» →
  `san-cosmos`; «Entradas San Cosmos» → `entrada-sancosmos`. Renombrar un término
  en WordPress no le cambia el slug, así que `ticketera-2-0` **no existe**.
- **`WooCommerceEventsLocation` no sirve como criterio.** Dice «San Cosmos | Un
  Planetario MuCi» también para el Bioestanque y la observación de aves: es la
  **sede**, no la sala. Por eso hace falta una categoría curada y no hay atajo por
  los datos que ya existen.
- **El tablero de admin no cambió, a propósito.** Es la única vista donde se ve una
  función huérfana (la del slot renombrado en WordPress), y esconder el nombre ahí
  volvería el problema invisible.
- **`php` local es 8.3**: el servicio va con `php8.4`, y en prod todo artisan y
  composer del CRM con `/usr/bin/php8.2` explícito.

## Tres tests que pasaban por casualidad

Los encontró la disciplina de mutación, y valen como advertencia para el resto de
la suite:

1. Un mock de Mockery **sin `->with()`** devuelve su valor sin mirar el argumento:
   el test pasaba con cualquier lista de ids.
2. **`Http::fake()` no reemplaza el stub anterior.** Dentro de un `foreach`, las
   iteraciones 2 en adelante reciben la respuesta de la primera — 3 de 4 casos no
   se probaban. Se pasó a dataset de Pest.
3. Un `assertSee` del rótulo se satisfacía con el cartel **del otro panel**, no con
   el `<h1>`. El título estaba cableado y el test pasaba igual.

Y un defecto que **ningún test detecta**: el cartel del panel vacío quedaba en
1rem pegado arriba a la izquierda, ilegible desde el hall. Salió de mirar la
pantalla renderizada a 1920×1080 y 1080×1920. Ese paso del plan es fácil de
saltear y es el que lo encontró.
