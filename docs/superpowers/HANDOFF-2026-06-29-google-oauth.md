# HANDOFF — Login Google OAuth (KrayinGoogleAuth)

**Fecha:** 2026-06-29
**Para:** la próxima IA/sesión que continúe esta feature
**Estado:** 2 de 9 tareas implementadas (T1, T2). Pausado a pedido del usuario.

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
