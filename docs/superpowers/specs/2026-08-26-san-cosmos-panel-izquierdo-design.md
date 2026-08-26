# Diseño — San Cosmos englobado en el panel izquierdo de la pantalla

Fecha: 2026-08-26
Estado: aprobado, listo para plan de implementación
Repos que toca: `laravel-crm` (paquete `KrayinTicketSales` + tests), `~/code/servicio-fooevents`

## 1. Qué se pidió y por qué

En la vista de pantalla (la TV del hall) el panel izquierdo tiene que mostrar
**todas** las actividades que se proyectan en el domo/planetario —hoy
«Experiencia adaptada», «El sistema solar expandido», «Historias extelares»,
«Marte», «Misterios de tu cerebro», y las que vengan— **englobadas como San
Cosmos, sin mostrar el nombre de cada una**. Lo que sí se sigue mostrando de
ellas son los **horarios y las ventas**.

El resto —las actividades especiales— va al panel derecho, **con su nombre**,
como hasta ahora.

Esas actividades del domo comparten la categoría de WooCommerce **«Ticketera
2.0»**, que antes se llamaba **«Entrada San Cosmos»**. Que la hayan renombrado
una vez es un dato de diseño, no una anécdota: cualquier criterio que dependa del
nombre exacto se rompe la próxima vez.

### Qué cambia respecto de lo que hay

Hoy el panel izquierdo lo gana **el show que más vendió** (`ProgramacionDePantalla::armar()`,
criterio en cascada: más entradas → más funciones → nombre). Ese criterio deja de
elegir el panel y pasa a **ordenar solo la derecha**. El panel izquierdo queda
reservado para una categoría, fijo.

## 2. El problema de fondo: el CRM no sabe qué producto es San Cosmos

Verificado el 2026-08-26:

- El contrato de `/v1/funciones` trae `producto_id`, `show`, `slot`, `hora`,
  ventas y recaudación. **Nada de categorías.**
- `muci_ticket_sales_snapshot` no tiene columna de categoría.
- El servicio **hoy no consulta** `product_cat` (`grep` sobre `app/` y `config/`
  de `~/code/servicio-fooevents`: cero coincidencias).
- El CRM **no tiene ni va a tener** la credencial de la base de WordPress, así
  que no puede averiguarlo por su cuenta.

Descartado por frágil: deducir la categoría del nombre del show. «Marte» y
«Misterios de tu cerebro» no comparten ninguna subcadena con «San Cosmos» ni
entre sí.

Descartado por costoso de mantener: una lista de `producto_id` del domo. Cada
show nuevo aparecería **a la derecha con su nombre** —exactamente lo que se pidió
evitar— hasta que alguien edite y despliegue.

**Decisión: el servicio manda las categorías crudas y el CRM decide.** El
servicio sigue siendo lo que dice ser, un agregador de WooCommerce sin reglas de
MuCi; y el día que renombren la categoría otra vez, se cambia en el CRM sin
tocar el otro repo ni re-sincronizar.

Descartado también: que el servicio mande el grupo ya resuelto
(`grupo: "san_cosmos"|"especial"`). Mete una regla de presentación de MuCi dentro
del servicio, que hoy no tiene ninguna, y convierte cualquier ajuste del criterio
en un deploy del otro repo.

Descartado: derivar un booleano `es_san_cosmos` en el sync. Congela la decisión en
el snapshot; si el criterio queda mal, los días anteriores siguen mal hasta
re-sincronizar, y el selector de fecha mira una semana para atrás.

## 3. El criterio se edita desde el CRM, no desde el código

**Nada de listas de categorías en el código.** El único lugar donde vive el
criterio en runtime es `core_config`, editable desde la UI del CRM.

### 3.1 Página de configuración

Nueva página `admin/ticket-sales/configure` (GET + POST), con **el mismo
middleware y la misma ACL que el tablero** — lleva números de venta, no es
pública. Sigue el patrón que ya usa `KrayinFinancialReports`:
`CoreConfig::updateOrCreate` con `json_encode` para escribir,
`core()->getConfigData()` para leer.

Clave: `krayin_ticket_sales.settings.san_cosmos`, con la forma

```json
{ "titulo": "San Cosmos", "categorias": ["ticketera-2-0", "entrada-san-cosmos"] }
```

Se editan dos cosas:

1. **Qué categorías cuentan como San Cosmos.** Con checkboxes de las categorías
   que el servicio **realmente reportó** en la ventana de retención (`DISTINCT`
   sobre la columna nueva del snapshot), más un campo libre para agregar una que
   todavía no apareció. Ese «distinct» se arma **en PHP**, leyendo las filas de
   la ventana y aplanando las listas: la columna es JSON y un `DISTINCT` de SQL
   devolvería documentos enteros, no categorías. Así nadie tipea un slug a ciegas ni adivina cómo se
   escribe `ticketera-2-0`.
2. **El rótulo del panel izquierdo** (hoy «San Cosmos»). Si la categoría se
   renombró una vez, el rótulo también puede.

### 3.2 Valor inicial

La **migración siembra la fila** de `core_config` con
`{"titulo": "San Cosmos", "categorias": ["ticketera-2-0", "entrada-san-cosmos"]}`,
y su `down()` la borra.

Se siembra en la base en vez de dejar un default en `ticket-sales.php` porque así
el criterio existe en un solo lugar y ese lugar es editable desde la UI. Un
default en el archivo sería una segunda fuente de verdad para lo mismo, y la
pregunta «¿cuál gana?» aparecería justo el día que algo se vea raro.

Se siembra —en vez de arrancar vacío— por el día del deploy: sin fila inicial, el
panel izquierdo arrancaría diciendo «Hoy no hay funciones de San Cosmos» sobre un
día que sí las tenía, y nadie que mire la TV tiene forma de saber que lo que falta
es la configuración.

### 3.3 Quién lee el config

Un `Support/CriterioDeSanCosmos`, con `categorias(): array` y `titulo(): string`,
que encapsula el `json_decode`-si-viene-string. Existe porque en
`FinancialReports` ese baile está repetido tres veces —en `configure()`, en
`storeConfiguration()` y en el index— y acá lo necesitan la página de config y la
pantalla. Copiar la repetición sería copiar el defecto.

Lo lee **solo `pantalla()`**, no `datosDelDia()`: el tablero de admin no reparte
paneles y no le hace falta. Esto no contradice la regla de no duplicar
`datosDelDia()`: el reparto en paneles es de la pantalla y de nadie más.

Si el valor guardado no tiene la forma esperada, `CriterioDeSanCosmos` devuelve
lista vacía y el rótulo por defecto, sin lanzar. La consecuencia es acotada y
visible: nada coincide, **todo** cae a la derecha con su nombre, y la página de
configuración muestra la lista vacía. Es una falla distinta de la del §4.2 y no
apaga nada — por eso el que valida de verdad es el POST de la página, que es
donde una persona puede ver el error y corregirlo.

La comparación de categorías va **normalizada en los dos lados** (`trim` +
minúsculas). Los slugs de WordPress son minúsculas por convención, pero un slug
escrito a mano en el campo libre de la UI no tiene por qué serlo.

## 4. El contrato del servicio

`/v1/funciones` agrega `categorias` a cada función: la lista de **slugs** de
`product_cat` del producto.

```json
{
  "producto_id": 192637,
  "show": "Entrada Bioestanque",
  "slot": "BioEstanque (16:00) (17:00)",
  "hora": "17:00",
  "categorias": ["entrada-bioestanque", "experiencias"],
  "entradas_vendidas": 2,
  "entradas_reagendadas": 0,
  "cupos_habilitados": 18,
  "recaudacion_neta": 63636,
  "recaudacion_bruta": 70000
}
```

Slugs y no nombres porque en WordPress renombrar un término **no le cambia el
slug**. Slugs y no `term_id` porque un config con `[128]` no se puede leer, y la
página de configuración es para que la use una persona.

### 4.1 En el CRM el campo es opcional

`categorias` **no entra en `FooEventsServiceClient::CAMPOS`**. Si fuera
obligatorio, un servicio viejo —o el orden de deploy al revés— haría que el
cliente descarte la respuesta entera, y el tablero quedaría clavado con el dato
de ayer disparando `OTRO_DIA`: el estado más caro del paquete, por un campo
decorativo. Es la misma lógica aditiva que ya se aplicó a los `avisos`, donde
agregar un `tipo` nuevo ya rompió una vez.

### 4.2 Un campo malformado no descarta el día

Malformado significa **la forma del campo en la respuesta del servicio**, y el
único que puede producirlo es el servicio: WooCommerce solo tiene filas en
`wp_term_relationships` y `wp_term_taxonomy`, y quién decide la forma del JSON es
el servicio al serializar.

El caso realista es una **evolución del servicio que el CRM no esperaba** —ya
pasó una vez en este paquete, con un `tipo` nuevo en los `avisos`—:

```json
"categorias": [{"slug": "ticketera-2-0", "name": "Ticketera 2.0"}]
```

Alguien enriquece el campo del lado del servicio para llevar también el nombre
legible, que es un cambio aditivo y razonable allá, y el CRM se encuentra objetos
donde esperaba strings. Las otras dos variantes plausibles son
`"categorias": "ticketera-2-0"` —un `implode` que se cuela, o alguien que
«simplifica» el caso de una sola categoría— y `[128, 129]`, term_ids en vez de
slugs. Los tres son los casos de test del §8.

`null` y `[]` **no** son este caso: son «no sé» y «sin categorías», y caen a la
derecha por el §6.3.

Si `categorias` viene pero no es una lista de strings, se descarta ese campo
—queda `null`— se registra en el log, y **las ventas se guardan igual**.

Esto se aparta a propósito de la regla «el cliente devuelve datos validados o
lanza, nunca algo a medias». El motivo es que el costo es asimétrico: rechazar
apaga la TV entera por un detalle de categorización, y aceptar solo hace que un
producto salga a la derecha con su nombre. La regla existe para proteger las
cifras de venta; acá el campo en cuestión no es una cifra de venta.

## 5. El snapshot

Migración **aditiva** sobre `muci_ticket_sales_snapshot`: columna `categorias`,
JSON, nullable. `down()` completo, que la borra — el paquete tiene que seguir
siendo reversible por desinstalación.

Van **dos migraciones separadas**, no una: esta, que cambia el esquema, y la del
§3.2, que siembra un dato. Separarlas es lo que deja revertir la siembra del
criterio sin tirar la columna con las categorías ya sincronizadas.

El modelo la castea a `array` y la agrega a `$fillable`. **El sync escribe lo que
vino y no deriva nada** (ver §2, por qué no un booleano).

`null` significa «no sé», y es lo que va a haber en todas las filas entre el
deploy del CRM y el del servicio.

## 6. El reparto de la pantalla

`ProgramacionDePantalla::armar()` recibe el criterio como argumento y cambia de
forma:

```php
ProgramacionDePantalla::armar($funciones, $categoriasSanCosmos)
// =>
[
  'sanCosmos'  => ['funciones' => [['hora' => '15:30', 'entradas' => 25], …]],
  'especiales' => [['producto_id' => …, 'show' => 'Entrada Bioestanque', 'entradas' => 12, 'funciones' => […]], …],
]
```

`sanCosmos` no lleva total propio: el §10 deja el total agregado fuera de
alcance, y un campo que ninguna vista renderiza es un campo que ningún test fija.
`especiales` sí lleva `entradas`, porque de eso vive el orden del §6.2.

Sigue siendo **pura**: no toca base ni reloj. El criterio entra por parámetro
justamente para que siga siéndolo, que es lo que la hace testeable.

Las claves se **renombran**: `destacado` ya no significa «el que más vendió», y
dejarle el nombre viejo es una trampa para el próximo que lea el Blade. El
rótulo del panel no viaja acá — va del controlador a la vista, porque es
presentación y no reparto.

### 6.1 La fusión por hora ahora cruza productos

En el panel izquierdo se fusiona por hora **entre todos los productos de San
Cosmos**, no solo dentro de cada uno.

Vale el mismo argumento que ya está escrito en `fusionarPorHora()`: el servicio
indexa por `producto + slot`, así que dos filas que caen a la misma hora son
conjuntos de entradas distintos, y sumarlas no dobla el conteo. Y sin nombres,
dos tarjetas «16:30» en la TV no significarían nada para quien las mira.

Las funciones **sin hora** siguen sin fusionarse entre sí —cada una lleva clave
propia— y van al final: dos funciones sin horario no tienen por qué ser la misma.

### 6.2 Qué queda del criterio en cascada

El criterio (más entradas → más funciones → nombre) **se conserva**, pero ahora
ordena solo la derecha. Los dos desempates siguen ganándose el lugar: cada
mañana, antes de la primera venta, todos los shows están en cero y el empate es
la regla; sin ellos el orden lo decidiría el orden de llegada de la consulta.

### 6.3 Lo desconocido cae a la derecha

**Alcanza con que una** de las categorías de la función esté en la lista para
que caiga a la izquierda. Los productos de WooCommerce suelen llevar varias
categorías, y exigir que todas coincidan dejaría afuera a cualquier show del domo
que además esté etiquetado como, digamos, «experiencias».

Una función con `categorias` en `null`, con la lista vacía, o con categorías que
ninguna está en la lista, va **a la derecha con su nombre**. Es el estado del día del deploy, y se
degrada exactamente a la pantalla de hoy.

### 6.4 Paneles vacíos

El reparto **60/40 es fijo**: la TV se ve igual todos los días, y quien pasa por
el hall sabe que la izquierda es el domo y la derecha lo demás. Un panel sin
funciones es información («hoy no hay domo»), no un hueco.

- Sin funciones de San Cosmos → panel izquierdo con su rótulo y «Hoy no hay
  funciones de {rótulo}».
- Sin actividades especiales → el cartel actual («Hoy solo hay funciones de X»)
  pasa a «Hoy solo hay funciones de {rótulo}».

Los dos carteles usan el **rótulo configurado**, no la cadena «San Cosmos»
cableada. Si alguien renombra el panel y los carteles siguen diciendo lo viejo,
la pantalla se contradice sola.

Descartado: que el panel sobreviviente tome el 100%. Aprovecharía más la TV, pero
desde el hall no se distinguiría «no hay domo» de «se rompió el panel izquierdo».

## 7. El tablero de admin no cambia

Sigue mostrando **una fila por función, con su nombre** y su «—» en cupos.

No es olvido. Es la única vista donde se ve que hay una función huérfana —el
problema del slot renombrado en WordPress que motivó la fusión por hora—, y
esconder el nombre ahí volvería el problema invisible. El razonamiento ya está
escrito en `fusionarPorHora()` y este cambio lo respeta.

El pedido se leyó como un pedido sobre la TV: «izquierda» y «derecha» son los
paneles de la pantalla; el tablero es una tabla plana.

## 8. Tests

Todos en `laravel-crm/tests/`, que es la convención de los 8 paquetes hermanos.
Ninguno necesita la base `muci`: lo del servicio va por `Http::fake()` contra la
fixture canónica.

**Unit, `ProgramacionDePantallaTest`:**

- Reparto por categoría: un producto del domo a la izquierda, uno especial a la
  derecha.
- Dos productos **distintos** de San Cosmos a la misma hora se fusionan en una
  tarjeta, con la suma.
- Funciones sin hora del domo no se fusionan entre sí y van al final.
- Izquierda vacía: `sanCosmos` con cero funciones y `especiales` poblado.
- Derecha vacía: `especiales` vacío y `sanCosmos` poblado.
- Categoría desconocida → derecha.
- `categorias` en `null` → derecha.
- El orden de `especiales` respeta la cascada, incluidos los dos desempates.

**Feature, pantalla:**

- El nombre de un show del domo **no** aparece en el HTML, y su horario y su
  cifra sí. El assert negativo es el que fija el pedido.
- El nombre de una actividad especial **sí** aparece.
- El rótulo del panel izquierdo sale del config, no del código.

**Feature, cliente del servicio:**

- Respuesta **sin** `categorias`: no lanza, y el snapshot queda con `null`.
- Respuesta con `categorias` malformada: no descarta el día, guarda las ventas,
  deja `null`. Un caso por cada forma del §4.2 — lista de objetos, string suelto,
  lista de enteros.
- `categorias` en `[]`: cae a la derecha, y **no** se confunde con malformado.

**Feature, página de configuración:**

- Guarda en `core_config` y lo lee de vuelta.
- La fila sembrada por la migración aplica sin que nadie configure nada.
- Los candidatos de checkbox salen de las categorías vistas en la ventana de
  retención.
- Config con forma inesperada: `CriterioDeSanCosmos` devuelve lista vacía y
  rótulo por defecto sin lanzar, y la pantalla manda todo a la derecha.

**Sync:** la columna se escribe con lo que vino.

Los que escriban usan `DatabaseTransactions` — no hay `.env.testing` y los
Feature corren contra la base `krayin` de desarrollo.

### 8.1 Disciplina de la casa

Antes de cerrar cada task se **mutan las piezas nuevas y se verifica que cada
mutación mate los tests que debería**. Mutar de a una, correr, restaurar desde
una copia en el scratchpad. Cuando una mutación sobrevive, casi nunca es
equivalente: es un test que pasa por casualidad, y hay que construir el caso
donde el criterio correcto y el proxy más simple se separan.

Los tests que salgan de esto se anotan en `Desviaciones encontradas al ejecutar`
del plan, porque son tests que el plan no pedía.

## 9. Orden de deploy

**Es indiferente, y eso es parte del diseño.** El cliente ignora campos extra que
no pide (`validar()` solo chequea que estén las claves que exige) y tolera el
campo ausente (§4.1).

- Si va primero el **CRM**: todas las filas quedan con `categorias` en `null`,
  todo cae a la derecha con su nombre, y la pantalla se ve como hoy. Cuando el
  servicio empieza a mandar el campo, la izquierda se puebla sola en el próximo
  sync (≤5 min).
- Si va primero el **servicio**: el CRM viejo ignora el campo y no se rompe.

La prueba de aceptación no se toca y tiene que seguir dando lo mismo:

```bash
/usr/bin/php8.2 artisan ticket-sales:sync --fecha=2026-08-07
# 11 funciones | 26 entradas | 0 avisos
```

En prod, todo artisan/composer del CRM con `/usr/bin/php8.2` explícito; el
servicio va con `/usr/bin/php8.4`. En prod no se toca git del CRM: los paquetes
entran por composer desde GitHub.

## 10. Alcance y no-alcance

**Dentro:**

- `servicio-fooevents`: `categorias` en la respuesta, con sus tests y su fixture
  canónica actualizada.
- `KrayinTicketSales`: migración (columna + siembra del config), modelo, cliente,
  sync, `CriterioDeSanCosmos`, `ProgramacionDePantalla`, vista `pantalla`, página
  y ruta de configuración, ACL.
- `laravel-crm/tests/`: lo del §8.

**Fuera:**

- El tablero de admin (§7).
- Un total de San Cosmos en el panel izquierdo. Se pidió horarios y ventas por
  función; una cifra agregada más es alcance que nadie pidió.
- Tocar el core (`packages/Webkul/*`, `vendor/*`, `app/Console/Kernel.php`).
- Reabrir cualquier acceso del CRM a la base de WordPress.

## 11. Decisiones cerradas

| Decisión | Resultado |
|---|---|
| Cómo sabe el CRM qué es San Cosmos | El servicio manda `categorias` crudas; el CRM decide |
| Dónde vive el criterio | `core_config`, sembrado por migración, editable en la UI |
| Identificador de categoría | Slug de `product_cat` |
| Campo obligatorio en el cliente | No: opcional, y malformado no descarta el día |
| Reparto cuando falta un panel | 60/40 fijo, con cartel en el panel vacío |
| Fusión por hora entre productos del domo | Sí |
| El tablero de admin | No cambia |

Sin decisiones abiertas.
