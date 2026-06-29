# Diseño — Login con Google OAuth para el CRM (KrayinGoogleAuth)

- **Fecha:** 2026-06-29
- **Autor:** Carlos Vallory (con asistencia de Claude Code)
- **Estado:** Aprobado — pendiente de plan de implementación
- **Repo destino del código:** nuevo paquete `packages/CarlVallory/KrayinGoogleAuth` dentro de `laravel-crm`

## 1. Objetivo

Permitir el ingreso al CRM (Krayin admin) **mediante Google OAuth**, restringido a:

- correos del dominio **@muci.org** (auto-aprobados), o
- perfiles **aprobados manualmente** por un administrador.

En el **frontend** el login pasa a ofrecer **solo Google** (reemplazo total visual). En el **backend** se mantiene el login por contraseña nativo de Krayin como **respaldo de emergencia** (convivencia).

## 2. Restricción de arquitectura (innegociable)

- **No se reescribe ni modifica el core** de Krayin (`packages/Webkul/*`, `vendor/*`, `config/auth.php`, vista de login, modelo `User`).
- Toda la funcionalidad vive en un **paquete propio** `CarlVallory/KrayinGoogleAuth`.
- **Reversible por desinstalación:** desactivar el paquete revierte la funcionalidad; el `down()` de las migraciones revierte el esquema. Volver atrás = desinstalar el paquete.
- Única huella fuera del paquete: dependencia `laravel/socialite` en el `composer.json` raíz y credenciales de Google en `.env` (ambas reversibles a mano).

## 3. Decisiones de política (cerradas)

| Tema | Decisión |
|------|----------|
| Backend | **Convivencia** — login por contraseña se mantiene como respaldo. |
| Frontend | **Reemplazo total** — la UI solo ofrece Google. |
| @muci.org (nuevo) | **Auto-aprobado**: entra activo (`status=1`) con rol Básico. |
| Otro dominio (nuevo) | **Pendiente** (`status=0`): no inicia sesión hasta aprobación de admin. |
| Admin pre-carga usuario | Ya entra **aprobado** (admin lo crea con `status=1`). |
| Rol por defecto | **Nuevo rol "Básico"** (solo lectura), sembrado por la feature; el admin lo cambia luego. |
| Validación de dominio | Contra el claim **`hd`** de Google Workspace, NO contra el texto del email. |

## 4. Estructura del paquete

```
packages/CarlVallory/KrayinGoogleAuth/
├── src/
│   ├── Providers/KrayinGoogleAuthServiceProvider.php   # registra rutas, vistas, listener, config, migraciones, merge de services.google
│   ├── Http/Controllers/GoogleAuthController.php        # redirect() + callback()
│   ├── Services/GoogleUserResolver.php                  # lógica dominio/pendiente/aprobado + rol default
│   ├── Listeners/InjectGoogleButton.php                 # responde al hook del login
│   ├── Console/UninstallCommand.php                     # google-auth:uninstall (revierte esquema + reasigna roles)
│   ├── Config/google-auth.php
│   ├── Database/Migrations/                             # columnas en users + seed rol Básico (down() completo)
│   └── Resources/views/google-button.blade.php
└── composer.json
```

Registrado vía Concord, igual que los demás paquetes `CarlVallory/*`.

## 5. Modelo de datos

Todo cuelga de la tabla `users` existente. **No se crea tabla nueva de usuarios.**

**Columnas nuevas en `users`** (migración con `down()` que las elimina):

- `google_id` — `string`, nullable, **único**. Vincula la cuenta de Google de forma estable.
- `auth_provider` — `string`, nullable. `'google'` para usuarios creados por este flujo; `null` = usuario nativo. Usado para filtrar pendientes y distinguir cuentas.

**Reutilización de columnas existentes:**

- `password` — ya es nullable → usuarios solo-Google quedan con `password = null`.
- `status` — `0` = pendiente/sin acceso, `1` = aprobado/activo (el `SessionController` nativo ya respeta `status==0` denegando acceso).
- `role_id` — apunta al rol Básico por defecto.

**Rol "Básico"** (sembrado por migración; `down()` lo borra):

- `name = "Básico"`, `permission_type = 'custom'`, `permissions = [...]` con un set **solo-lectura**. Las claves exactas de la ACL se fijan al implementar leyendo el árbol de permisos de Krayin.
- El paquete lo resuelve **por nombre** (`config('google-auth.default_role_name')`), no por id hardcodeado.

**Caso borde de uninstall:** si quedan usuarios con rol "Básico", el `down()`/comando de uninstall los reasigna al rol de `config('google-auth.uninstall_fallback_role')` antes de borrar el rol (o aborta avisando si no está configurado), para no dejar `role_id` huérfano.

## 6. Flujo de autenticación

**Rutas nuevas** (paquete, middleware `web`, públicas — no detrás de `auth`):

- `GET /login/google` → `GoogleAuthController@redirect` → Socialite redirige a Google. (nombre: `google-auth.redirect`)
- `GET /login/google/callback` → `GoogleAuthController@callback`.

**Lógica del callback** (`GoogleUserResolver`):

1. Obtener de Google: `email`, `google_id` (sub), `name`, avatar, claim `hd`.
2. Buscar usuario por `google_id`; si no, por `email`.
3. **Usuario existe:**
   - Vincular `google_id` si faltaba.
   - `status = 1` → iniciar sesión (`auth()->guard('user')->login($user)`).
   - `status = 0` → rechazar con mensaje "acceso pendiente de aprobación".
4. **Usuario no existe:**
   - **`hd` == muci.org** (dominio en `allowed_domains`) → crear `status=1`, rol Básico, `auth_provider='google'`, `password=null` → iniciar sesión.
   - **Otro dominio / sin `hd`** → crear `status=0` (pendiente), rol Básico, `auth_provider='google'` → NO iniciar sesión; mensaje "acceso pendiente de aprobación de un administrador".

**Convivencia backend:** la ruta `POST /login` nativa queda intacta y funcional como respaldo de emergencia.

## 7. Frontend (botón + ocultar form nativo)

- `InjectGoogleButton` se engancha al hook `admin.sessions.login.form_controls.before` (que la vista de login de Krayin ya expone) y renderiza `google-button.blade.php`.
- El partial contiene:
  - Botón **"Entrar con Google"** → `route('google-auth.redirect')`.
  - `<style>` mínimo que **oculta** el form de email/password nativo (reemplazo total visual).
  - Mensaje de aviso/error leído de la sesión (p.ej. "acceso pendiente de aprobación").
- **Cero cambios al Blade del core.** Al desinstalar, el listener desaparece y el login vuelve al form nativo.
- **Estándar visual MuCi:** botón con tipografía Poppins y la paleta de marca.
- **Salida de emergencia:** `config('google-auth.show_password_login')` — si `true`, no se inyecta el CSS de ocultamiento → reaparece el form nativo sin desinstalar.

## 8. UX de aprobación de pendientes

Sin tocar el core, sobre el listado de usuarios existente:

- **Filtro/sección "Pendientes"**: usuarios `auth_provider='google'` y `status=0`.
- **Acción "Aprobar"**: pone `status=1` (opción de elegir rol en el mismo paso).
- **Gestión de rol**: se usa la pantalla de edición de usuario que Krayin ya tiene.

Se engancha por rutas/controlador del paquete + hooks de vista del módulo de usuarios. Si no hay hook conveniente en esa pantalla, alternativa: **mini-vista propia** ("Aprobaciones pendientes") bajo Configuración, también 100% del paquete. Se decide al implementar según los hooks disponibles.

## 9. Configuración

`config/google-auth.php` (publicable):

- `client_id` / `client_secret` / `redirect` → de `.env` (`GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`). El bloque `services.google` se agrega por merge del provider (sin editar `config/services.php` a mano).
- `allowed_domains` → `['muci.org']` (lista; quién es auto-aprobado).
- `default_role_name` → `'Básico'`.
- `show_password_login` → `false`.
- `uninstall_fallback_role` → rol al que se reasignan usuarios "Básico" al desinstalar.

## 10. Manejo de errores y casos borde

- **`hd` ausente o ≠ muci.org** → flujo "otro dominio" (pendiente). Nunca se asume @muci.org por el texto del correo.
- **Email ya existe como usuario nativo activo** (p.ej. `admin@example.com`) → se vincula `google_id` por match de email y entra (`status=1`). Si está inactivo, queda pendiente.
- **Usuario pendiente reintenta** → mismo mensaje "pendiente"; no se duplica (match por `google_id`/email).
- **Socialite falla / usuario cancela en Google** → `try/catch` → redirect al login con mensaje genérico. **No saturar Sentry** (regla de `CLAUDE.md`: solo fallos críticos).
- **Google no devuelve email** → rechazo con mensaje claro.
- **CSRF/state** → lo maneja Socialite; rutas con middleware `web`.

## 11. Testing

- **Unit** (`GoogleUserResolver`, con `SocialiteUser` mockeado):
  - @muci.org nuevo → crea `status=1`, rol Básico.
  - Otro dominio nuevo → crea `status=0`, no loguea.
  - Existente activo → vincula `google_id` y loguea.
  - Existente pendiente → rechaza.
  - `hd` ausente con correo que "parece" muci.org → tratado como otro dominio.
- **Feature** (rutas): redirect a Google; callback con usuario mock aprobado deja sesión iniciada; pendiente no.
- **Migraciones:** test que migra y revierte → `users` queda sin las columnas y sin el rol Básico.
- Tests con `DatabaseTransactions` (mismo patrón que la suite USD; no ensucian la BD local).

## 12. Fuera de alcance (YAGNI)

- Otros proveedores OAuth (GitHub, Microsoft, etc.).
- SSO/SAML a nivel Google Workspace.
- Gestión de roles/permisos más allá de sembrar "Básico" (se usa la UI existente de Krayin).
- 2FA.
