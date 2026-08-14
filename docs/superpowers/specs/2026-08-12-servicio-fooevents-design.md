# Diseño — Servicio intermedio FooEvents

- **Fecha:** 2026-08-12
- **Autor:** Carlos Vallory (con asistencia de Claude Code)
- **Estado:** Aprobado — pendiente de plan de implementación
- **Relación con el spec anterior:** reemplaza las secciones 4, 5 y 11 de
  `2026-08-07-dashboard-entradas-vendidas-design.md`. El resto de aquel spec
  (objetivo, interfaz, fuera de alcance, trampas del esquema) sigue vigente.

## 1. Por qué existe este servicio

El spec del 2026-08-07 resolvía el acceso a datos con una segunda conexión
Laravel desde el CRM a la base `muci`. El 2026-08-10 esa decisión se revirtió.

El motivo **no** es seguridad de acceso: la conexión directa era técnicamente
segura, misma máquina y grant `SELECT` puro impuesto por MySQL. El motivo es
acoplamiento. El CRM no debería depender del esquema `wpzv_*` de otro sistema.
El beneficio concreto y medible es que el `.env` del CRM pasa a tener un token
en vez de usuario y contraseña de una base ajena.

Un plugin de WordPress tampoco es la respuesta: ese WP tiene 84 plugins activos
y un deployable más ahí puede tumbar el sitio público del museo.

## 2. Arquitectura y límites

```
CRM (crm.muci.org)                      servicio-fooevents
├─ scheduler cada 5 min                 ├─ Laravel lite, stateless
├─ ticket-sales:sync ──GET──────────►   ├─ único con credencial de muci
├─ tabla muci_ticket_sales_snapshot     ├─ nginx vhost + php-fpm 8.2
└─ vista lee SOLO el snapshot           └─ listen 127.0.0.1:8081
                                               │ SELECT
                                               ▼
                                          base muci (wpzv_*)
```

**El límite que define todo:** el servicio es el único que conoce `wpzv_*`. El
CRM recibe filas ya agregadas y no sabe nada de FooEvents. La consecuencia
observable es que el CRM se queda sin un solo fixture de FooEvents.

**El paquete del CRM habla únicamente con el servicio.** No queda ninguna ruta
del CRM a `muci`: la conexión `woocommerce` que abrió el Task 1 se retira, y esa
retirada es la garantía de que no puede aparecer una consulta directa "temporal"
que después nadie saque.

**El snapshot se queda.** No es cacheo del servicio: es lo que permite servir el
último dato bueno con aviso de antigüedad en vez de un 500 cuando el servicio no
responde.

El servicio vive en un **repo nuevo y propio**, igual que los nueve paquetes
`carlvallory/*`. El nombre del repo y la ruta de despliegue se fijan en el plan;
este diseño no depende de cuáles sean.

### Reparto de los parsers ya escritos

| Componente | Dónde va | Por qué |
|---|---|---|
| `BookingsOptionsParser` | Servicio | Puro esquema FooEvents (formas A y B) |
| `SpanishDateParser` | Servicio | Existe solo por el `"agosto 11, 2026"` del JSON |
| `BusinessDay` | **CRM** | Calcular "hoy" en `America/Asuncion` es del consumidor; el CRM lo necesita para armar el request. El guard de tzdata que falla con −4 se va con él |

## 3. Contrato

### 3.1 Pedido

```
GET /v1/funciones?fecha=2026-08-12
Authorization: Bearer <token>
```

`fecha` es **obligatoria, sin default, formato `YYYY-MM-DD` estricto**. Sin ella
el servicio responde 422, nunca "hoy". La prohibición de `CURDATE()` y `NOW()`
la impone así la forma del contrato y no la disciplina de quien escribe el SQL.

El prefijo `/v1/` es seguro barato: si algún día cambia la forma de la
respuesta, no hay que coordinar dos deploys.

### 3.2 Respuesta 200

```json
{
  "fecha": "2026-08-12",
  "generado_en": "2026-08-12T14:05:33-03:00",
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

| Campo | Semántica |
|---|---|
| `producto_id` + `slot` | La llave de la función, junto con `fecha`. `slot = trim(label) + " " + formatted_time`, tal cual lo guarda el ticket |
| `show` | Título del producto, con el espacio duro U+00A0 ya normalizado — es artefacto de FooEvents, lo limpia el servicio |
| `hora` | Sale de la programación; si la función solo existe por tickets, del `WooCommerceEventsBookingDateMySQLFormat`. Es la clave de orden |
| `entradas_vendidas` | Registros de ticket (`event_magic_tickets`) en pedidos `wc-completed`. **No** se cuenta por líneas de pedido |
| `entradas_reagendadas` | Tickets en pedidos `wc-reagendado`, aparte y sin sumarse. Ver §3.5 |
| `cupos_habilitados` | Remanente del JSON. **`null`** si la función existe por tickets pero ya no figura en la programación. Nunca se deriva porcentaje de ocupación |
| `recaudacion_neta` | Suma de `_line_total`, entera, en guaraníes |
| `recaudacion_bruta` | Suma de `_line_total + _line_tax`, entera. El IVA es la diferencia entre ambas |

Las funciones vienen ordenadas por `hora` y, a igual hora, por `show`. El orden
es parte del contrato: así el CRM no tiene que reordenar ni inventar criterio.

Cada elemento de `avisos` tiene la forma `{"tipo": "...", "detalle": "..."}`,
donde `tipo` es un código estable y `detalle` el texto para el log. Los códigos
son cinco: `json_ilegible`, `fecha_no_parseable`, `estado_desconocido`,
`prorrateo_ambiguo` y `linea_faltante`.

`linea_faltante` se agregó el 2026-08-14, al ejecutar la Task 5. Cubre un par
pedido+producto que tiene entradas pero **no** tiene línea de plata: antes eso
daba recaudación cero sin decir nada, y un total corto sin explicación es peor que
un aviso. Hoy es inalcanzable —los 5516 pares tienen línea— pero es la misma
categoría de riesgo futuro que `prorrateo_ambiguo`.

**El CRM no debe fallar ante un `tipo` que no conozca.** Agregar códigos es
aditivo y va a volver a pasar; el consumidor los loguea y sigue.

### 3.3 Prorrateo de la recaudación

Las líneas de pedido **no sirven** para saber a qué función corresponde una
entrada: 206 de 375 líneas de los shows de agosto 2026 no traen ningún meta de
evento, y 148 de esas están en pedidos `wc-completed`. Toda la venta a colegios
cae en ese agujero.

Por eso las entradas se cuentan por ticket y la plata se prorratea:

```
precio_unitario  = SUM(_line_total) / SUM(_qty)   del par pedido+producto
recaudacion(f)   = precio_unitario × entradas_vendidas(f)
```

El reparto entre funciones usa **resto mayor**, de modo que la suma de las
funciones dé exactamente el total del par y nadie encuentre un guaraní perdido.

Condición que hace válido el prorrateo, verificada el 2026-08-12 (§6): la
cantidad de la línea coincide con la cantidad de tickets en **5516 de 5516**
pares.

### 3.4 Día sin funciones

`200` con `"funciones": []`.

No `404`: un día vacío es una respuesta válida, y un 404 haría que el CRM no
distinga "no hay funciones" de "el endpoint no existe".

### 3.5 Qué cuenta como entrada vendida

**Solo `wc-completed`.** Los `wc-reagendado` se reportan aparte, en su propio
campo, sin sumarse.

El filtro **enumera los estados que sí cuentan**, no excluye una lista de malos.
Un estado nuevo configurado en WooCommerce no debe colarse en la métrica
central; aparece como aviso (§4.1) y alguien decide qué hacer con él.

Fundamento del trato a `wc-reagendado`, verificado el 2026-08-12 (§6): el flujo
de reagendamiento deja el pedido viejo en `wc-reagendado` **quedándose con el
ticket**, y crea un pedido nuevo con cupón `REAGENDA-…` que **no tiene ningún
ticket**. El ticket nunca se edita: en los 4 casos su `post_modified` es
anterior al reagendamiento, así que sigue apuntando a la función original.

Las dos opciones son malas de maneras distintas. Contarlo mete la entrada en la
función a la que esa persona no fue — un número equivocado en la métrica
central. No contarlo la hace desaparecer, porque no existe en ningún otro lado.
Se elige reportarlo aparte: no arregla el agujero, pero lo hace visible en vez
de silencioso.

### 3.6 Errores

| Código | Cuándo | Qué hace el CRM |
|---|---|---|
| `401` | Token ausente o distinto | No reintenta. Loguea y conserva el snapshot |
| `422` | `fecha` ausente o mal formada | No reintenta — es un bug del CRM, no del servicio |
| `503` | Base `muci` inalcanzable | Reintenta una vez a los 2s, después se rinde |
| `500` | Cualquier otra falla | Igual que 503, pero se loguea como inesperado |

Cuerpo uniforme: `{"error": "fecha_invalida", "mensaje": "..."}`. El código es
para la máquina, el mensaje para el log.

**Timeouts del lado del CRM:** 3s de conexión, 15s total. El sync corre cada 5
minutos; si el servicio tarda más de 15s hay algo roto y es mejor conservar el
dato viejo que colgar el comando.

### 3.7 Fuera del contrato, a propósito

Ninguna PII —ni nombres, ni teléfonos, ni correos— y ningún porcentaje de
ocupación. Los pedidos ad-hoc de listas de compradores se resuelven aparte.

## 4. Errores y antigüedad

### 4.1 Del lado del servicio

Los parsers siguen siendo **puros y mudos**: no loguean, no lanzan. Quien decide
qué es anomalía es el servicio, que las junta en `avisos` y responde 200 igual.
Un producto ilegible no puede tumbar el día entero.

| Situación | Comportamiento |
|---|---|
| Base `muci` inalcanzable | `503`. No devuelve datos parciales ni cachea |
| JSON de un producto ilegible | Se saltea ese producto, aviso con el `product_id`, el resto sigue |
| Meta de bookings vacía, `null` o `[]` | **Normal, sin aviso.** Son 2 de los 18 productos y siempre fueron así |
| JSON válido que no da ninguna función | **Normal, sin aviso.** Se saltea el producto y nada más. Decidido el 2026-08-14: `json_ilegible` se resuelve por si el JSON **parsea**, no por contar funciones. Contarlas confunde "no pude leer" con "leí bien y está vacío", diagnostica mal y en un caso emite dos avisos por lo mismo |
| Par pedido+producto con entradas y sin línea de plata | Recaudación en cero para ese par y aviso `linea_faltante`. El resto del día sigue |
| Fecha en español no parseable | Se descarta esa fecha, el resto del slot se conserva, aviso |
| Estado de pedido desconocido | No se cuenta, aviso con el estado |
| Prorrateo ambiguo (par multifunción con precios distintos) | Reparte con el promedio y avisa. A nivel producto y a nivel día el número sigue siendo exacto |

El array `avisos` viaja en la respuesta, el CRM lo guarda en el snapshot y lo
loguea como `warning`. **No se muestra en el tablero:** su público es quien
mantiene el sistema, no boletería. Lo que evita es que un cambio en WordPress
—un tercer formato de JSON, un estado nuevo— pase inadvertido durante meses,
que es exactamente lo que pasó con las líneas de pedido.

### 4.2 Del lado del CRM

**Ninguna falla toca el snapshot anterior.**

| Situación | Snapshot | Log |
|---|---|---|
| `503` / `500` / timeout | Intacto | `warning` |
| `401` | Intacto | `error` — es configuración, no un hipo de red |
| `422` | Intacto | `error` — es un bug del CRM |
| Respuesta con forma inesperada | Intacto | `error` |

La última fila importa más de lo que parece: una respuesta malformada no puede
vaciar el snapshot. Se valida la forma antes de escribir y, si no cuadra, se
descarta entera.

**Escritura atómica:** dentro de una transacción se reemplazan las filas de esa
`fecha` y se borran las de más de 7 días. La vista nunca lee un estado a medio
escribir, y la tabla no crece sin límite.

### 4.3 Los cinco estados de la vista

1. **Snapshot de hoy, menos de 15 minutos** → tablero normal. La página se
   auto-refresca cada 5 minutos.
2. **Snapshot de hoy, 15 minutos o más** → tablero **más** banda de aviso con la
   hora del dato. Se sigue viendo el número; lo que se agrega es la duda.
3. **El snapshot es de otro día** → **no se muestran esas funciones.** Aviso de
   que el sync viene fallando y desde cuándo.
4. **Nunca se corrió el sync** → estado vacío con la instrucción de correr el
   comando.
5. **Hoy sin funciones** → "No hay funciones programadas para hoy".

El estado 3 justifica la sección entera. Si el sync se rompe un viernes, el
snapshot del jueves tiene funciones con números plausibles; mostrarlo sería el
error más caro que este tablero puede cometer, porque nadie lo nota. La
comparación de `fecha` contra el día de negocio lo hace imposible.

El estado 5 nunca se puede confundir con el 3 ni con el 4: "no hay funciones" es
una respuesta del servicio, "no pude preguntar" es otra cosa. Se ven distinto.

**Borde de medianoche:** si una corrida arranca 23:58 y termina 00:01, el
snapshot queda con la fecha de ayer y la vista cae en el estado 3 por dos
minutos, hasta que la corrida de las 00:03 lo arregla. Se resuelve solo y no
requiere código.

## 5. Testing

Cada lado testea lo que sabe. El servicio es el único con fixtures de FooEvents.

### 5.1 Reparto de los 23 tests que ya existen

| Tests de | Van a | Nota |
|---|---|---|
| `SpanishDateParser` | Servicio | Con sus fixtures |
| `BookingsOptionsParser` | Servicio | Incluida la prueba de aceptación 11-sobre-7 |
| `BusinessDay` + guard de tzdata | **Se quedan en el CRM** | Junto con la clase |

El volcado de los 18 productos `dateslot` se muda de
`laravel-crm/tests/Fixtures/fooevents/` al repo del servicio. **La prueba de
aceptación tiene que seguir en verde después de la mudanza** — es el único
guardián automatizado de que un cambio de formato de JSON no vuelva a costar
4 shows de 7.

### 5.2 Servicio

- **Unit, prorrateo:** par de una sola función; par multifunción con precio
  uniforme; par multifunción con precios distintos (reparte y avisa); resto
  mayor, verificando que la suma de las funciones dé exacto el total del par;
  con IVA y sin IVA.
- **Unit, cruce programación × ventas:** las dos direcciones. Función programada
  sin ventas → aparece en 0. Ticket cuya función ya no está en la programación →
  aparece con `cupos_habilitados: null`. Perder una venta es peor que mostrar
  una función de más.
- **Unit, avisos:** JSON ilegible, fecha en español basura, estado de pedido
  desconocido. Y que la meta vacía **no** genere aviso — si esa se vuelve
  ruidosa, los avisos dejan de leerse y el mecanismo muere.
- **Feature, endpoint:** `401` sin token y con token equivocado; `422` sin
  `fecha` y con `fecha` mal formada; `200` con la forma completa; `200` con
  `funciones: []`; `503` con la base caída.
- **Integración contra `muci`:** se saltea solo si la base no está. Localmente
  siempre se saltea, y eso no es un fallo.

### 5.3 CRM

- **Cliente HTTP, con `Http::fake()`:** 200 feliz; `503` → un reintento → se
  rinde; `401` → no reintenta; timeout; y **respuesta malformada → el snapshot
  no se toca**.
- **Comando de sync:** que ante cualquier falla el snapshot quede idéntico, y
  que la escritura sea atómica.
- **Vista, los cinco estados:** fresco, viejo con banda de aviso, snapshot de
  otro día, nunca corrió, hoy sin funciones. Con snapshots fabricados a mano.
- **ACL:** solo cuentas `@muci.org`, como `KrayinGoogleAuth`.

Los Feature del CRM corren contra la base `krayin` de desarrollo con
`DatabaseTransactions`, porque no hay `.env.testing`.

### 5.4 La costura entre los dos repos

Un archivo de respuesta canónica, `respuesta-ejemplo.json`: el servicio lo
produce con un test de golden file, el CRM lo usa como fixture de `Http::fake()`.

**Son dos repos, así que la copia es a mano y se pueden desincronizar.** No es
un contract test de verdad y no conviene disfrazarlo de garantía. Lo que
sostiene el sistema es la validación de forma en runtime de §4.2: si el servicio
cambia la respuesta y nadie actualiza el fixture, el CRM lo ve como error de
forma y conserva el snapshot viejo con aviso de antigüedad. Se rompe ruidoso, no
callado.

### 5.5 Lo que no se automatiza

La verificación contra datos reales de producción queda como procedimiento
manual documentado, no como test: los números se mueven solos. El de referencia
es **167 entradas sobre los 7 shows de agosto 2026**, contadas por asistente y
confirmadas por tres vías (posts de ticket, meta serializado
`WooCommerceEventsOrderTickets`, y `WooCommerceEventsTicketsPurchased`).

## 6. Verificaciones hechas el 2026-08-12

Todas contra la base de producción con el usuario de solo lectura.

### 6.1 Validez del prorrateo

Pares pedido+producto con tickets, desde 2026-01-01:

| Comprobación | Resultado |
|---|---|
| Pares | 5516 |
| Sin línea de pedido | 0 |
| `_qty` de la línea = cantidad de tickets | **5516** |
| No coincide | **0** |
| Pares cuyos tickets caen en más de una función | 40 |
| Pares con precio unitario distinto entre líneas | 20 |
| **Intersección de los dos anteriores** | **0** |

La última fila es la que habilita el prorrateo: los casos donde el promedio
podría repartir mal tienen todos sus tickets en una sola función, así que el
reparto da exacto en todos los datos existentes. El servicio igual contempla la
intersección (§4.1) porque puede aparecer mañana.

### 6.2 IVA

Líneas de pedidos `wc-completed` desde 2026-01-01:

| | Líneas | `_line_total` | `_line_tax` | Tasa |
|---|---|---|---|---|
| Con IVA | 2138 | 60.016.409 | 6.001.627 | **10,00%** |
| Sin IVA | 13134 | 497.607.399 | 0 | — |

Exactamente 10%, sin casos raros. El `total_amount` del pedido lo incluye: la
suma de líneas (557.623.809) más el IVA (6.001.627) da 563.625.436 contra
564.767.450 de `total_amount`; la diferencia de 1.142.014 es envío u otros
cargos, que no son entradas.

Por eso el contrato devuelve las dos cifras: la bruta es la que va a cuadrar
contra la caja del día, la neta la que sirve para contabilidad.

### 6.3 Reagendamiento

| Comprobación | Resultado |
|---|---|
| Pedidos `wc-reagendado` en toda la historia (desde 2023-10) | 5 |
| — nov–dic 2025, total ₲0, mismo cliente, todos TatakuaLab | 4 |
| — real: `#175614`, feb 2026, ₲35.000 | 1 |
| Pedidos creados con cupón `REAGENDA-` | 6 |
| De esos, cuántos tienen tickets | **0** |
| Tickets que hoy viven en un pedido `wc-reagendado` | 4 |
| Tickets cuyo `post_modified` es anterior al reagendamiento | **4 de 4** |

### 6.4 Llave de unión y cobertura de metas

La llave sigue siendo `producto_id + fecha + slot`, con
`slot = trim(label) + " " + formatted_time`. Verificada de nuevo hoy contra el
2026-08-07: 192637 da 2 entradas en `BioEstanque (16:00) (17:00)` y 2 en
`BioEstanque (18:00) (19:00)`, que es exactamente lo que registra §13 del diseño
del 2026-08-07.

Se evaluó y **descartó** usar `WooCommerceEventsBookingSlotID` +
`WooCommerceEventsBookingDateID` como llave, que serían inmunes a renombres de
label: el `slot_id` aparece en el JSON del producto en 5589 de 5600 tickets
(99,8%), pero el `date_id` solo en 4317 (77%), porque el JSON va perdiendo las
fechas pasadas y un ticket viejo apunta a una clave que ya no existe. Queda
anotado por si renombrar labels se vuelve un problema real.

**87 de 16.482 tickets no tienen `WooCommerceEventsBookingDateMySQLFormat` ni
`WooCommerceEventsBookingSlot`** (0,5%): son productos sin bookings. El servicio
los descarta — no son funciones.

### 6.5 Estados de pedido presentes

Histórico completo: `wc-completed` (38.556), `wc-cancelled` (5561),
`wc-refunded` (23), `wc-reagendado` (5), `wc-pending` (1).

Corrige una nota anterior que afirmaba que `wc-pending` no existía: existe,
apareció el 2026-08-12. No cambia la decisión, la confirma — el filtro tiene que
enumerar lo que cuenta.

## 7. Decisiones cerradas

Estas cinco se cerraron el 2026-08-11 y **no se reabren**:

| Decisión | Resuelto |
|---|---|
| Dirección del canal | El CRM tira; el servicio expone `GET`, stateless, no conoce al CRM |
| Contrato | Agregado por función |
| Alcance v1 | Solo números, sin PII |
| Despliegue | `127.0.0.1:8081`, vhost nginx propio + php-fpm 8.2, misma máquina |
| Auth | Token estático compartido, `hash_equals` en middleware |

Descartados con motivo: systemd/Octane, otro servidor, Sanctum
(sobredimensionado para un consumidor único), y no poner auth (en esa máquina
también corre WordPress con 84 plugins activos).

Las tres agregadas el 2026-08-12: recaudación **bruta y neta**; solo
`wc-completed` cuenta, con `wc-reagendado` aparte; prorrateo por resto mayor.

## 8. Qué cambia en lo ya construido

Los Tasks 1–4 del plan del 2026-08-07 quedan como están, salvo un punto:

- **Se retira** la conexión `woocommerce` del Task 1, junto con las variables
  `WC_DB_*` del `.env` y del `.env.example` del CRM. En su lugar van la URL del
  servicio y el token.
- `BookingsOptionsParser` y `SpanishDateParser` cambian de repo, con sus tests y
  sus fixtures.
- `BusinessDay` no se toca.

## 9. Un hallazgo operativo, fuera de alcance

El flujo de reagendamiento **no emite ticket para la fecha nueva**. El pedido
`#161101` quedó `wc-completed` sin ningún ticket. Si eso sigue así en
producción, alguien que reagenda se queda sin entrada válida para el día que va
a ir.

No es del alcance de este proyecto y el tablero no puede arreglarlo. Queda
anotado porque se descubrió acá y porque explica por qué `entradas_reagendadas`
va en un campo aparte en vez de sumarse.
