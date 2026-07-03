# HANDOFF — Login Google OAuth (KrayinGoogleAuth)

**Fecha:** 2026-06-29 · **Actualizado:** 2026-07-02
**Para:** la próxima IA/sesión que continúe esta feature
**Estado:** **FEATURE COMPLETA 9/9 + review whole-branch final (opus) APROBADA — READY TO MERGE.**

---

## ⏩ ESTADO FINAL AL 2026-07-02 (leer esto primero)

**La implementación TERMINÓ.** T8 (uninstall) y T9 (README + suite) completadas; review final whole-branch (opus) encontró 2 Critical + hallazgos de seguridad, TODOS corregidos con tests de regresión y re-verificados (detalle completo en `.superpowers/sdd/progress.md`, sección "REVIEW FINAL WHOLE-BRANCH"). Suites: 23 tests/51 asserts + uninstall 3/15, todo verde. Core Webkul intacto (net-cero verificado).

- **PR #2 abierto:** https://github.com/carlvallory/laravel-crm/pull/2 (`feat/google-oauth-login` @ `b14f6d93` → `2.1`). Merge = decisión de Carlos.
- **Paquete en repo propio pusheado:** https://github.com/carlvallory/krayin-google-auth (`main` @ `7645cf9`).
- **Único pendiente antes de producción:** verificación manual con credenciales Google reales (sección al final de este doc). El deploy de OAuth va APARTE del bloque USD/marketing — ver `DEPLOY-PRODUCCION.md` (raíz del workspace), Bloque 2.

**Fixes de seguridad de la review final (NO regresionar):**
1. `google_id`/`auth_provider` NO son fillable en el core → persistir SIEMPRE por asignación directa de propiedades (nunca `User::create([...])` con esos campos).
2. Rutas de aprobación gateadas por ACL de paquete (`src/Config/acl.php`, clave `settings.user.users.google_pending`, merge vía `mergeConfigFrom(...,'acl')`) + guard idéntico en `PendingUserController` — mantener la MISMA clave en ambos lados.
3. `approve()` scopeado: `where('status',0)->where('auth_provider','google')`.
4. `google-auth:uninstall` pide confirmación antes de reasignar usuarios Básico al rol fallback (`--force` para scripts).
5. Logo Google = SVG inline (sin requests externos en el login).

**Adjudicado (no "corregir"):** `config/l5-swagger.php` usa `env('APP_ENV')` a propósito — `env()` en config files se evalúa en `config:cache`; `app()->environment()` ahí rompía `package:discover`.

---

### Estado histórico (2026-07-01, T1–T7)

Implementadas y commiteadas T3–T7 (ejecución directa TDD por el controlador, dual-repo: código→repo del paquete, tests→laravel-crm/tests). Detalle por tarea con hashes en `.superpowers/sdd/progress.md`.

- **T3** rol Básico (pkg `084a43b`, crm `19b64bab`). Se quitó `contacts.organizations.view` del set de permisos (NO existe en `acl.php`).
- **T4** resolver + DTOs (pkg `ea4ceb8`, crm `a241ba37`) **+ HARDENING de seguridad** (pkg `dca087a`, crm `ddd77c4b`): agregado `GoogleAccount.emailVerified`; el resolver ya NO autovincula por email ni auto-aprueba con `email_verified=false`; lanza `RuntimeException` si falta el rol por defecto. Decisión de Carlos: "endurecer (recomendado)" tras un review de seguridad automático. Finding de paridad de dominio se dejó como diseño aprobado.
- **T5** controller + rutas redirect/callback (pkg `c9cc302`, crm `aa13a7bb`). El controller lee el claim `email_verified`.
- **T6** botón Google + ocultar form nativo (pkg `0abfa4f`, crm `b3465ae2`). Inyectado vía `Event::listen('admin.sessions.login.form_controls.before')`; el botón queda FUERA del `<form>`, así que el CSS oculta `form[action=admin.session.store]` sin esconderlo. Toggle `GOOGLE_AUTH_SHOW_PASSWORD_LOGIN`.
- **T7** aprobación de pendientes (pkg `81fa3b7`, crm `7ea47588`). Rutas `['web','user']` prefijo `admin/google-auth`.

~~RETOMAR EN T8~~ **SUPERADO 2026-07-02** — T8 y T9 completadas, review final hecha. ⚠️ Sigue vigente la mecánica del test de uninstall: hace `ALTER TABLE` (DDL NO transaccional en MariaDB) → correrlo ÚLTIMO y restaurar el esquema después: `DELETE FROM migrations WHERE migration IN ('2026_06_29_100000_add_google_columns_to_users_table','2026_06_29_100100_seed_basico_role');` + `php artisan migrate --force`, luego verificar columnas+rol. Backup durable: `/home/vallory/backups/krayin_backup_pre_t8_2026-07-02.sql` (el de /tmp se perdió en un reboot).

~~🔴 PENDIENTE CRÍTICO: sin pushear~~ **RESUELTO 2026-07-02:** rama pusheada (PR #2) y paquete con repo remoto propio.

**Datos de contexto ya verificados esta sesión (no re-explorar):** hook login = `admin.sessions.login.form_controls.before` (login.blade.php:25); rutas admin bajo prefijo `config('app.admin_path')` + middleware `['web','admin_locale','user']`; alias `user`=`Bouncer` (guest→redirect a `admin.session.create`, no 500); nombres de ruta `admin.session.create/store/destroy`, `admin.dashboard.index`; tests en `laravel-crm/tests` con namespace `Tests\Feature\*` (NO `CarlVallory\...\Tests` como decía el plan). Backup DB pre-trabajo: `/tmp/krayin_backup_pre_oauth_1782929592.sql`.

---

### Estado histórico (2026-06-29, T1–T2)

---

## Qué es esta feature

Login al CRM (Krayin admin) vía **Google OAuth**, en un paquete propio **desinstalable** sin tocar el core.

- Spec: `docs/superpowers/specs/2026-06-29-google-oauth-login-design.md`
- Plan (9 tareas TDD, código completo): `docs/superpowers/plans/2026-06-29-google-oauth-login.md`
- Ledger de progreso: `.superpowers/sdd/progress.md` (sección "Login Google OAuth")
- Método de ejecución: **subagent-driven-development** (un implementer por tarea + review + ledger).

**Decisiones de política ya cerradas con el usuario** (no re-litigar):
- Backend convivencia (login por password queda de respaldo) + frontend reemplazo total (solo Google).
- @muci.org → auto-aprobado (validado por claim `hd` de Workspace, NO por texto del email) con rol "Básico".
- Otros dominios → pendientes (`status=0`) hasta aprobación de admin.
- Admin pre-carga usuario = ya aprobado.
- Rol default = NUEVO rol "Básico" solo-lectura, sembrado por la feature; el admin lo cambia luego.

---

## Rama y commits

- Rama: **`feat/google-oauth-login`** (creada desde `2.1`) en `laravel-crm`.
- `2557c439` — spec + plan (docs).
- `d50c3c69` — **T1**: scaffold del paquete + Socialite (root composer + l5-swagger fix; el paquete después se desvinculó, ver abajo).
- `efd23615` — **T2**: migración columnas `google_id`+`auth_provider`.
- `2cd24d37` — chore: laravel-crm deja de trackear el paquete (pasa a repo propio).
- Repo propio del paquete `packages/CarlVallory/KrayinGoogleAuth` (branch `main`): commit inicial `67e4360` con todo el código de T1+T2.

> **Nota de proceso:** los commits T1/T2 en laravel-crm aún contienen en su historia los archivos del paquete (se desvincularon en `2cd24d37`, no se reescribió historia). El estado de HEAD es correcto: laravel-crm no trackea el paquete; el código vive en el repo propio.

---

## Estado verificado

- **T1 (scaffold):** Spec ✅ / Calidad Aprobada (review por subagent sonnet). Boot-check en vivo: `config('google-auth.default_role_name')`=`Básico`; `package:discover` descubre `carlvallory/krayin-google-auth`.
- **T2 (migración):** test `GoogleColumnsMigrationTest` PASS (1 test/2 asserts). Reversibilidad VERIFICADA en vivo (rollback quita `google_id`+`auth_provider`, re-apply las restaura). Review **solo controller-level** (no se despachó reviewer aparte por presupuesto de contexto).
- BD local `krayin` (MariaDB nativa) tiene la migración aplicada actualmente.

---

## ✅ DECISIONES RESUELTAS (2026-06-29) — aplicar de aquí en adelante

### 1. RESUELTA — KrayinGoogleAuth tiene su PROPIO repo git (decisión del usuario)

El paquete `packages/CarlVallory/KrayinGoogleAuth` es ahora un **repo git independiente** (como los demás `carlvallory/*`), referenciado desde laravel-crm vía path repository + `@dev`.

Ya ejecutado:
- laravel-crm **dejó de trackear** el paquete (commit `2cd24d37`: `git rm --cached` de los 4 archivos; ahora caen bajo `/packages/CarlVallory` del `.gitignore`).
- Se inicializó el repo propio del paquete (branch `main`, commit inicial `67e4360`) con `composer.json`, `src/**` y un `.gitignore` propio (`/vendor`, `composer.lock`).
- **De aquí en adelante:** todo el CÓDIGO del paquete (providers, controllers, services, migraciones, vistas) se commitea en el repo del paquete (`cd packages/CarlVallory/KrayinGoogleAuth && git add <paths> && git commit`). NO usar `git add -f` en laravel-crm para el paquete.
- laravel-crm conserva: root `composer.json`/`composer.lock` (registro del paquete + socialite), el fix de `config/l5-swagger.php`, los docs (`docs/superpowers/*`) y los TESTS (`tests/**`).

### 2. RESUELTA — Tests del paquete viven en `laravel-crm/tests/`

Convención del ecosistema (igual que la feature USD): el código del paquete en su repo propio, pero los **tests en `laravel-crm/tests/Feature/...`** (que es donde `phpunit.xml` los escanea). El test de T2 ya está ahí (`tests/Feature/Migrations/GoogleColumnsMigrationTest.php`, trackeado en laravel-crm). El duplicado fantasma en el dir del paquete fue **borrado**. T4–T8 siguen este patrón: código→repo del paquete, test→laravel-crm/tests/.

---

## Cómo continuar (proceso subagent-driven)

1. **Resolver primero las 2 decisiones de arriba** con el usuario.
2. Releer la skill `superpowers:subagent-driven-development`.
3. Para cada tarea N (3→9):
   - `BASE=$(git rev-parse HEAD)`.
   - Generar brief: `<skill>/scripts/task-brief docs/superpowers/plans/2026-06-29-google-oauth-login.md N`.
   - Despachar implementer (modelo **haiku** — el plan trae el código completo) con: 1 línea de contexto + path del brief + interfaces de tareas previas + report-path. NO pegar historia acumulada.
   - Al volver DONE: `<skill>/scripts/review-package $BASE HEAD`, despachar task reviewer (**sonnet**).
   - Fixes para Critical/Important; Minors al ledger.
   - Marcar la tarea en el ledger (`.superpowers/sdd/progress.md`) y en la todo-list.
4. Tras T9: review whole-branch final con **opus** (usar `superpowers:requesting-code-review`), apuntándolo a la lista de Minors del ledger.
5. Cerrar con `superpowers:finishing-a-development-branch`.

### Tareas restantes (resumen — detalle y código en el plan)
- **T3:** migración seed rol "Básico" solo-lectura (`down()` reasigna usuarios al rol fallback). OJO: confirmar claves ACL reales en `packages/Webkul/Admin/src/Config/acl.php`.
- **T4:** `GoogleAccount`/`ResolutionResult` DTOs + `GoogleUserResolver` (lógica dominio/pendiente/aprobado). 5 tests unit. **El corazón de la feature.**
- **T5:** `GoogleAuthController` + rutas redirect/callback; login con `auth()->guard('user')->login()`. Feature tests con Socialite mock.
- **T6:** frontend — partial Blade con botón Google + CSS que oculta el form nativo + listener del hook `admin.sessions.login.form_controls.before`. Toggle de emergencia `GOOGLE_AUTH_SHOW_PASSWORD_LOGIN`.
- **T7:** UX aprobación de pendientes (PendingUserController + vista + rutas con middleware `user`).
- **T8:** comando `google-auth:uninstall` (revierte rol + columnas).
- **T9:** suite completa + README del paquete + review final.

---

## Datos técnicos útiles (ya descubiertos, no re-explorar)

- Guard admin de Krayin: **`user`** (session). `config/auth.php:38-43`. Provider `users` → `Webkul\User\Models\User`.
- `users.password` ya es **nullable** (los usuarios solo-Google quedan con `password=null`).
- `users.status`: `0`=pendiente/sin acceso, `1`=aprobado. El `SessionController` nativo ya deniega `status==0`.
- Modelo `User`: `packages/Webkul/User/src/Models/User.php`. Fillable incluye name, email, image, password, role_id, status, view_permission. T2 agregó google_id + auth_provider.
- Rol: `Webkul\User\Models\Role`, `permissions` = array JSON de claves dot-notation (`leads.view`, etc.). Único rol sembrado por Krayin = `Administrator` (`permission_type='all'`).
- Login: `auth()->guard('user')->login($user)`; redirect éxito `route('admin.dashboard.index')`. `SessionController.php`.
- Hook del login (inyección sin tocar Blade): `Event::listen('admin.sessions.login.form_controls.before', fn($mgr) => $mgr->addTemplate('google-auth::google-button'))`.
- Socialite NO estaba instalado; T1 lo agregó (`^5.0`).
- DB local: MariaDB nativa, base `krayin`, user `krayin_user`/`krayin_password`. root con password olvidada → no hay `krayin_test`; tests se aíslan con trait **`DatabaseTransactions`** (no RefreshDatabase). Ver memoria [[reference_db_local_admin]].
- **`git add` SIEMPRE explícito, NUNCA `-A`** (se cuela gitlink `packages/Vallory/KrayinFormatter`).
- Verificación manual final (necesita credenciales Google reales, no cubierta por tests): crear OAuth client en Google Cloud, poblar `.env` GOOGLE_CLIENT_ID/SECRET/REDIRECT_URI, probar @muci.org vs externo, probar toggle de emergencia.
