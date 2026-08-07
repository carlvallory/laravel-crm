# Diseño — Dashboard de entradas vendidas del día (KrayinTicketSales)

- **Fecha:** 2026-08-07
- **Autor:** Carlos Vallory (con asistencia de Claude Code)
- **Estado:** Aprobado — pendiente de plan de implementación
- **Repo destino del código:** nuevo paquete `packages/CarlVallory/KrayinTicketSales` dentro de `laravel-crm`

## 1. Objetivo

Un tablero que responda, de un vistazo y sin intervención manual: **¿qué funciones hay hoy y cuántas entradas se vendieron para cada una?**

- Alcance de la métrica: **funciones de hoy, acumulado histórico**. Una función de hoy con entradas vendidas hace dos semanas cuenta esas entradas. *No* mide "entradas vendidas hoy" (ritmo comercial) — decisión cerrada, ver §9.
- Los datos se refrescan **cada 5 minutos** desde la base de WooCommerce + FooEvents.
- Visible **solo para cuentas @muci.org**.

## 2. Hallazgo de infraestructura que define la arquitectura

Verificado por SSH el 2026-08-07: **el CRM de producción y WordPress/WooCommerce corren en la misma máquina y la misma instancia MySQL.**

| Hecho | Valor verificado |
|---|---|
| Host | `ubuntu-c-4-nyc1-01` · `159.89.228.18` (DigitalOcean NYC1) |
| Dominios | `muci.org` y `crm.muci.org` → misma IP |
| CRM | `/var/www/crm`, nginx + PHP 8.2-FPM, HTTPS |
| Bases en la misma instancia | `krayin` y `muci` (entre otras) |
| MySQL bind | `127.0.0.1:3306` únicamente — puerto filtrado desde afuera |
| Usuario de lectura | `anthropic_readonly@%`, grant = `SELECT, SHOW DATABASES ON *.*` |
| Scheduler | cron `schedule:run` activo y verificado (precedente: `inbound-emails:process` cada 5 min) |
| Login Google | `KrayinGoogleAuth` desplegado, `allowed_domains = ['muci.org']`, verificado end-to-end |

**Consecuencia:** el pedido original contemplaba un servidor interno, un túnel SSH y un login nuevo. Los tres son innecesarios. El dashboard se construye como paquete del CRM y hereda infraestructura ya probada en producción.

**Contrapartida asumida:** el dashboard vive en `crm.muci.org`, que es público (protegido por login Google), no en la LAN interna. Y si el CRM cae, cae el dashboard. Aceptado explícitamente.

## 3. Restricción de arquitectura (innegociable)

Idéntica a la de `KrayinGoogleAuth`:

- **No se toca el core** de Krayin (`packages/Webkul/*`, `vendor/*`).
- Toda la funcionalidad vive en `CarlVallory/KrayinTicketSales`.
- **Reversible por desinstalación:** migraciones con `down()` completo.
- Huella fuera del paquete: credenciales de la conexión `woocommerce` en `.env`, y el registro del comando en el scheduler.

## 4. Acceso a datos

Segunda conexión Laravel, declarada por el paquete en `register()` (no se edita `config/database.php` del core):

```
database.connections.woocommerce → 127.0.0.1:3306, base `muci`, usuario `anthropic_readonly`
```

**La solo-lectura la garantiza MySQL, no el código.** El grant del usuario es `SELECT` puro: aunque el paquete tuviera un bug que intentara escribir, la base lo rechaza. Toda consulta usa esta conexión; ningún `Model::save()` la toca.

Prefijo de tablas: `wpzv_`. HPOS activo (órdenes en `wpzv_wc_orders`, no en `wpzv_posts`).

## 5. Las dos fuentes de verdad

### 5.1 Programación — qué funciones hay hoy

Origen: meta `fooevents_bookings_options_serialized` de cada producto publicado (método `WooCommerceEventsBookingsMethod = dateslot`).

Se usa esta fuente y **no** los tickets, porque una función con cero ventas no genera tickets y sería invisible. Caso real del 2026-08-07: "Entrada Bioestanque" tenía 3 funciones programadas y arrancó el día sin ventas.

**Riesgo crítico — conviven dos formatos de JSON incompatibles:**

```jsonc
// Forma A, anidada (productos 192862, 193817, 192637)
{"slotKey": {"label": "Entrada general", "formatted_time": "(10:30)",
             "add_date": {"dateKey": {"date": "agosto 4, 2026", "stock": "27"}}}}

// Forma B, plana (producto 194099)
{"slotKey": {"label": "Entrada general", "hour": "19", "minute": "00",
             "dateKey_add_date": "agosto 1, 2026", "dateKey_stock": "3"}}
```

Un parser que solo entienda la forma A **pierde 4 de los 7 shows del día**. Medido sobre la base real: 47 slots están en forma A y **17 en forma B**. El 2026-08-07, ignorar la forma B ocultaba los productos 194055, 194099, 194154 y 194339 — incluido el de mayor venta del día.

El parser soporta ambas y elige por presencia de la clave `add_date`. Esto no es una precaución teórica: es la diferencia entre mostrar 4 shows y mostrar 7.

Notas adicionales del formato:
- En la forma B no hay `formatted_time`; se compone desde `hour`/`minute` (+ `period` si estuviera).
- 10 de 28 productos tienen la meta vacía, `null` o `[]`. El parser los saltea sin romper.
- Hay títulos con **espacio duro** (`\xa0`), p. ej. `"De estrellas\xa0a supernovas"`. Se normaliza para mostrar.

### 5.2 Ventas — cuántas entradas

Origen: posts `post_type = 'event_magic_tickets'`.

| Meta | Uso |
|---|---|
| `WooCommerceEventsProductID` | Enlace al producto |
| `WooCommerceEventsBookingDateMySQLFormat` | Fecha y hora de la función, en **hora local** |
| `WooCommerceEventsBookingSlot` | Slot, para desglosar funciones del mismo día |
| `WooCommerceEventsOrderID` | Enlace a `wpzv_wc_orders` (recaudación y estado) |

**Advertencia heredada de la sesión del 2026-08-06:** la meta `_wooevents_ticket_product_id` **no existe** en esta base; cualquier JOIN sobre ella devuelve cero filas sin fallar. Y filtrar por `order_items.order_item_name` es frágil ante renombres. El enlace correcto es `WooCommerceEventsProductID`.

### 5.3 Llave de unión entre ambas fuentes

`producto_id` + `fecha` + `slot`, donde:

```
slot = trim(label) + " " + formatted_time
```

Verificado contra datos reales: `label = "BioEstanque (16:00)"` y `formatted_time = "(17:00)"` producen `"BioEstanque (16:00) (17:00)"`, exactamente lo que guarda el ticket. El label ya puede contener un horario propio, distinto del horario del slot — no se intenta "limpiarlo".

### 5.4 Fechas en español

El JSON guarda las fechas como texto localizado: `"agosto 7, 2026"`. Se parsean con una tabla de meses en español que contempla **`septiembre` y `setiembre`**. Una fecha ilegible se descarta y se registra en log; no rompe el sync.

## 6. Zona horaria — bug evitado por diseño

| Capa | Zona |
|---|---|
| Sistema operativo y MySQL | **UTC** (`@@time_zone = SYSTEM`, `date` = UTC) |
| WordPress | `gmt_offset = -3`, `timezone_string` vacío |
| Tickets | `post_date` vs `post_date_gmt` = **−3 exacto** (verificado) |

`CURDATE()` de MySQL devuelve la fecha **UTC**. Desde las 21:00 de Asunción, en UTC ya es el día siguiente: el dashboard mostraría las funciones de mañana justo cuando el personal cierra la caja del día.

**Regla:** "hoy" se calcula en PHP con `America/Asuncion` y se pasa como **parámetro explícito** al SQL. `CURDATE()` y `NOW()` quedan prohibidos en las consultas del paquete.

Las fechas de `WooCommerceEventsBookingDateMySQLFormat` ya están en hora local, así que se comparan directo contra esa fecha local.

## 7. Refresco cada 5 minutos

Comando `ticket-sales:sync` registrado en el scheduler con `->everyFiveMinutes()` — mismo mecanismo que el `inbound-emails:process` ya en producción.

**Flujo:** el comando lee `muci` (programación + ventas), arma el snapshot del día y lo escribe en una tabla propia de `krayin`. La vista lee **únicamente esa tabla local**.

Motivos:
- `wpzv_postmeta` tiene **4.6M de filas** y `event_magic_tickets` 64k. Consultar en vivo por request es inaceptable.
- Si el sync falla, el dashboard muestra el último dato bueno con un aviso de antigüedad, en lugar de un 500.

**Escritura atómica:** el snapshot se reemplaza dentro de una transacción, para que la vista nunca lea un estado a medio escribir.

Tabla `muci_ticket_sales_snapshot` (base `krayin`): fecha de la función, producto id, nombre, slot, horario, entradas vendidas, cupos habilitados, recaudación, y `synced_at`.

La vista se auto-refresca cada 5 minutos y muestra "Actualizado hace X". Si `synced_at` supera los 15 minutos, el aviso pasa a ser visualmente prominente.

## 8. Interfaz

Vista Krayin estándar (`<x-admin::layouts>`), entrada en el menú admin, permiso ACL propio.

- **Tarjetas:** funciones de hoy · entradas vendidas · recaudación del día.
- **Tabla:** show · horario · entradas vendidas · cupos habilitados · recaudación.
- Funciones **sin ventas se muestran igual**, en cero — son justamente las que hay que mirar.
- Si hay entradas en órdenes no completadas, se indica aparte. No se ocultan ni se suman en silencio.
- Estándar visual MuCi: Poppins y paleta de marca (#F17DB1, #00B26B, #000000, #6950A1, #F37043). El `#6950A1` ya es el color de ticket configurado en FooEvents.

## 9. Fuera de alcance, y por qué

**Porcentaje de ocupación — excluido deliberadamente.** El `stock` del JSON es el **remanente**, y `stock = 0` **no significa agotado**.

Evidencia: el 2026-08-07 el producto 193817 tenía `stock = 0` con 6 entradas vendidas, sobre un cupo que en otras fechas del mismo slot es 30. En el total, 98 de 203 fechas-slot están en 0. Un cálculo `vendidas / (vendidas + stock)` daría **100% de ocupación en una sala casi vacía**.

Se muestra el remanente con el nombre honesto **"cupos habilitados"**, sin derivar porcentajes. Si en el futuro se quiere ocupación real, hace falta una fuente de aforo que hoy no existe en la base.

**Otros excluidos:** métrica de "vendidas hoy" (ritmo comercial), datos personales de compradores, histórico y tendencias, exportación. Ninguno hace falta para la pregunta que el dashboard responde.

**Sobre datos personales:** el dashboard es **agregado**. No muestra nombres, cédulas, emails ni teléfonos de compradores, y el snapshot **no los almacena**.

## 10. Unidades y sus límites

| Unidad | Responsabilidad | Depende de |
|---|---|---|
| `BookingsOptionsParser` | JSON de FooEvents (formas A y B) → lista de funciones | nada — clase pura |
| `SpanishDateParser` | `"agosto 7, 2026"` → `Carbon` | nada — clase pura |
| `BusinessDay` | Fecha "hoy" en `America/Asuncion` | nada — clase pura |
| `DailySalesAggregator` | Programación + tickets + precios → filas del snapshot | nada — clase pura |
| `TicketSalesRepository` | Consultas a `muci`, sin agregar | conexión `woocommerce` |
| `SyncTicketSalesCommand` | Orquesta y escribe el snapshot | los cinco anteriores |
| `TicketSalesController` + vista | Lee snapshot y renderiza | tabla local |

Las **cuatro primeras** concentran todo el riesgo de parseo y de cruce, y son **testeables sin base de datos**. El repositorio devuelve filas crudas a propósito — una fila por ticket, decenas por día — para que la agregación quede en código puro en vez de en SQL.

**Regla del cruce, en ambas direcciones:** una función programada sin ventas aparece en cero, y un ticket cuya función ya no figura en la programación aparece igual (con cupos `null`). Perder una venta es peor que mostrar una función de más.

## 11. Errores

| Situación | Comportamiento |
|---|---|
| Base `muci` inalcanzable | Sync falla, log; la vista sirve el último snapshot con aviso de antigüedad |
| JSON de un producto ilegible | Se saltea ese producto y el resto sigue. Los parsers son puros y no loguean: el **comando** informa los `product_id` afectados, para no perder la señal de que apareció un tercer formato de JSON. Los 10 productos con la meta vacía son normales y no generan aviso |
| Fecha en español no parseable | Se descarta esa fecha; el resto del slot se conserva |
| Sin funciones hoy | Estado vacío explícito: "No hay funciones programadas para hoy" |
| Nunca se corrió el sync | Aviso de que no hay datos aún, con instrucción de correr el comando |

## 12. Tests

- `BookingsOptionsParser`: forma A, forma B, meta vacía/`null`/`[]`, JSON corrupto, composición del slot con label que ya trae horario, espacio duro en el título.
- `SpanishDateParser`: meses válidos, `septiembre` y `setiembre`, texto basura.
- `BusinessDay`: que a las 22:00 de Asunción "hoy" siga siendo hoy y no el día siguiente en UTC.
- `TicketSalesRepository`: contra fixtures, verificando que se usa `WooCommerceEventsProductID` y no el nombre del item.
- Comando: reemplazo atómico del snapshot y que una función con cero ventas aparezca en el resultado.
- Vista: acceso denegado sin login; ACL respetada.

Con `DatabaseTransactions`, igual que `KrayinGoogleAuth`.

## 13. Verificación de referencia

Datos reales del 2026-08-07 (hora local ~17:30), para contrastar la primera corrida:

| Producto | Slot | Entradas |
|---|---|---|
| 192637 Entrada Bioestanque | BioEstanque (16:00) (17:00) | 2 |
| 192637 Entrada Bioestanque | BioEstanque (18:00) (19:00) | 2 |
| 192862 El Sistema Solar Expandido | Entrada general (10:30) | 4 |
| 192862 El Sistema Solar Expandido | Entradas 2x1 (18:00) | 5 |
| 193817 Historias Estelares | Entrada 2x1 (16:00) | 6 |
| 194099 Mundos en órbita | Entrada 2x1 (19:00) | 7 |
| **Total** | **6 funciones con venta** | **26** |

La programación completa del día, parseando **ambas formas** de JSON, es de **11 funciones sobre 7 shows**:

| Producto | Slot | Cupos habilitados |
|---|---|---|
| 192637 Entrada Bioestanque | BioEstanque (16:00) (17:00) | 18 |
| 192637 Entrada Bioestanque | BioEstanque (17:00) (18:00) | 20 |
| 192637 Entrada Bioestanque | BioEstanque (18:00) (19:00) | 18 |
| 192862 El Sistema Solar Expandido | Entrada general (10:30) | 0 |
| 192862 El Sistema Solar Expandido | Entradas 2x1 (18:00) | 0 |
| 193817 Historias Estelares | Entrada general (08:30) | 0 |
| 193817 Historias Estelares | Entrada 2x1 (16:00) | 0 |
| 194055 Marte: La travesía definitiva | Entrada general (11:30) | 0 |
| 194099 Mundos en órbita | Entrada 2x1 (19:00) | 0 |
| 194154 Misterios de tu Cerebro | Entrada general (09:30) | 0 |
| 194339 Las Constelaciones y el Zodíaco | Entrada 2x1 (17:00) | 0 |

**Prueba de aceptación del parser dual:** si la primera corrida devuelve 7 funciones sobre 4 shows en vez de 11 sobre 7, el parser está ignorando la forma B (§5.1).

Las 5 funciones sin ventas (Bioestanque 17:00, y los slots de 193817, 194055, 194154, 194339 sin movimiento) **deben aparecer en cero**, no desaparecer.

Nótese también que 194055, 194154 y 194339 tienen `cupos = 0` **y** cero ventas: confirmación adicional de que `stock = 0` significa "venta online cerrada", no "agotado" (§9).

Advertencia para quien verifique: **estos números se mueven**. Durante la sesión de diseño pasaron de 22 a 26 entradas por ventas reales. Contrastar contra la base en el momento, no contra esta tabla.
