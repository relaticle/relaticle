# CRM interno del equipo — fork de Relaticle

> Este archivo son las reglas **del equipo**. El `CLAUDE.md` y el `AGENTS.md` de
> la raíz son de upstream (generados por Laravel Boost): no los edites, así
> `git pull upstream main` no da conflictos. Este archivo está versionado aunque
> `.gitignore` ignore `.claude/*` (se añadió con `git add -f`).

## Qué es este proyecto
**Crabdev CRM**: CRM y pipeline de ventas **interno** del equipo, partiendo de
un fork de [Relaticle](https://github.com/Relaticle/relaticle) (CRM open-source
sobre Laravel + Filament). Objetivo: tener controlados todos los clientes y el
pipeline de ventas en un solo sitio.

- **Equipo (2-3 personas):** dirección/desarrollo, socio dev y un comercial.
- **Clientes:** pymes españolas (agencia CrabDev y demás líneas de negocio).
- **Idioma de la interfaz:** español (`APP_LOCALE=es`).
- **Acceso:** solo por invitación; sin web pública.

## Stack (heredado de Relaticle)
- Laravel 13, Filament 5, PHP 8.5, Livewire 4, Alpine.js, Tailwind
- **PostgreSQL 17+** (no MySQL; el devcontainer usa la 18)
- Laravel Jetstream (teams) + Sanctum y Passport (API / OAuth del MCP)
- Horizon para colas (Redis)
- Plugin de **Custom Fields** (`relaticle/custom-fields`, sin migraciones)
- Spatie Media Library
- Tests con Pest (en paralelo)
- Servidor **MCP** + **REST API** incluidos

Modelos principales: Empresas = `Company`, Contactos = `People`,
Oportunidades = `Opportunity`, además de `Task` y `Note`.

## Requisitos de entorno
PHP 8.5+ · PostgreSQL 17+ · Composer 2 · Node.js 22.12+ con **pnpm** (versión
fijada en `packageManager` de `package.json`) · Redis (Horizon y caché).

## Entorno de desarrollo recomendado: devcontainer
`.devcontainer/` trae PHP 8.5, Node 22 + pnpm, PostgreSQL 18 y Redis ya
configurados. Funciona igual en VS Code (*Reopen in Container*, requiere Docker)
y en GitHub Codespaces.

- Al crearse, `post-create.sh` instala dependencias, crea `.env` (marca Crabdev,
  español, sin login social, documentación ni datos de demo), migra y compila
  assets. Es idempotente: se puede relanzar.
- `APP_URL` debe coincidir con la URL del navegador (`RedirectToPrimaryHost`
  redirige cualquier otro host). `post-create.sh` lo fija solo al crear `.env`:
  `http://localhost:8000` en local o la URL pública en Codespaces.
- BD de desarrollo `relaticle` (postgres/postgres) y de tests
  `relaticle_testing` (usuario `root`), como esperan `.env.example` y
  `.env.testing`.

## Comandos
- Instalar fuera del devcontainer: `composer app-install` (interactivo; por
  defecto propone SQLite, elige **PostgreSQL**)
- Desarrollo (server + Horizon + logs + Vite): `composer dev`
- Tests: `php artisan test` o `composer test:pest` (paralelo)
- Todo lo que ejecuta el CI (Pint, Rector, type coverage, PHPStan, Pest):
  `composer test`
- Migraciones: `php artisan migrate`
- Primer usuario (el registro está cerrado): `php artisan make:filament-user --panel=app`
- Aplicar nuestro pipeline y campos a un workspace:
  `php artisan crm:configurar-pipeline {id-o-slug}` (idempotente)

## Reglas de oro para el agente
1. **Campos nuevos = Custom Fields, no migraciones.** Para añadir atributos a
   Empresas / Contactos / Oportunidades, usa el sistema de Custom Fields: desde
   el panel o, para que sea repetible, añadiéndolos a `config/crm.php` y a
   `crm:configurar-pipeline`. Crea migraciones solo para entidades realmente
   nuevas. Esto mantiene el fork limpio para poder actualizar desde upstream.
2. **No romper tests.** Ejecuta Pest antes y después de cada cambio; mantenlo en
   verde.
3. **Cambios pequeños y commits atómicos.** Explica y pide confirmación antes de
   cualquier operación destructiva (migraciones destructivas, reseteo de BD,
   borrado de datos).
4. **No tocar la lógica multi-tenant / teams** salvo que se pida: somos un solo
   equipo, un solo workspace.
5. **PSR-12** y las convenciones ya presentes en el repo (Pint + Rector). Pint
   también revisa `lang/`.
6. **UI en español:** usa la localización de Laravel/Filament, no textos
   hardcodeados. Todo texto nuevo va en `lang/en` **y** en `lang/es`. Nunca
   escribas «Relaticle» en español: es «Crabdev CRM».

## Español
- `lang/es/`: traducción completa de `lang/en/` (glosario: Workspace →
  espacio de trabajo, People → Contactos, Account Owner → Responsable de la
  cuenta; «Rela», el asistente de IA, conserva su nombre).
- `lang/es/{auth,pagination,passwords,validation}.php` y `lang/es.json` parten
  de Laravel Lang (`php artisan lang:add es`) con las claves propias de
  Relaticle añadidas.
- `lang/vendor/custom-fields/es/` y `lang/vendor/activity-log/es/`: paquetes
  de Relaticle que no traen español. Filament y Flowforge sí lo traen.
- `tests/Feature/Crm/SpanishTranslationTest.php` **falla si falta en español
  alguna clave** de `lang/en` o de esos paquetes (p. ej. tras un
  `git pull upstream main` o un `composer update`): tradúcela y actualiza el
  snapshot.
- Sigue sin traducir (código de upstream con texto fijo): el aviso de
  «exportación terminada» de los exportadores.

## Personalización Crabdev (sin tocar el core)
Todo vive en archivos propios y se controla con `config/crm.php`:

- `app/Providers/CrmServiceProvider.php`: nombre, favicon, colores (dorado
  `#d9a441`), cabecera de correos, registro solo por invitación y middleware de
  la web pública. Registrado al final de `bootstrap/providers.php` (única línea
  tocada de upstream).
- `resources/views/crm/`: vistas que **sustituyen** a las de upstream con el
  mismo nombre (logo, pie del login, crear workspace, cabecera de correo). Si
  upstream cambia la vista original, revisar nuestra copia.
- `app/Http/Middleware/RedirectPublicPagesToApp.php`: portada, precios,
  documentación, legales… redirigen a la app (lista en
  `crm.access.public_paths`).
- `app/Support/Crm/SignupGate.php`: desde la web solo se crea cuenta con
  invitación pendiente **por correo** (el enlace de invitación no sirve para
  gente sin cuenta); por Artisan, siempre.
- Flags (por defecto activos salvo en los tests de upstream, `APP_ENV=testing`):
  `CRM_BRANDING`, `CRM_PUBLIC_PAGES`, `CRM_OPEN_SIGNUP`.

## Mantener el fork actualizable
- `origin` = nuestro fork, `https://github.com/Guillermoj9/relaticle`
  (ahora público; **pasarlo a privado**). `upstream` = `Relaticle/relaticle`.
- Personalizar vía config, Custom Fields y archivos propios siempre que se
  pueda, evitando editar el core, para poder hacer `git pull upstream main`.
- Tras cada `git pull upstream main`:
  1. Pasar los tests: `SpanishTranslationTest` avisa de textos nuevos.
  2. Ver qué textos de `lang/en` cambiaron (sin el ruido de `es.json`, que
     siempre sale como «orphaned» porque upstream no tiene `en.json`):
     `php artisan locale:diff es --format=json | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach(["missing","stale"] as $k) foreach($d[$k] as $i) echo "$k: {$i["key"]}\n";'`
  3. Traducir y fijar la nueva referencia:
     `php artisan locale:diff es --update-snapshot` (commitear
     `lang/.snapshots/es.json`).
  4. Revisar si cambiaron las vistas que sustituimos en `resources/views/crm/`
     o aparecieron páginas públicas nuevas.
- Contexto personal o no versionado → `CLAUDE.local.md` (no commitear).
- `.env` no se versiona: hay que recrearlo en cada máquina.

## Despliegue (VPS)
- El devcontainer es **solo para desarrollo**; no se usa en producción.
- El `compose.yml` de la raíz usa la imagen de upstream
  (`ghcr.io/relaticle/relaticle:latest`), **no nuestro código**. Para
  desplegar el fork hay que construir nuestra imagen con el `Dockerfile` de la
  raíz y apuntar `compose.yml` a ella.
- Variables: `APP_NAME="Crabdev CRM"`, `APP_LOCALE=es`,
  `APP_FAKER_LOCALE=es_ES`, `REQUIRE_EMAIL_VERIFICATION=true`, SMTP real
  (`MAIL_MAILER=smtp`), `RELATICLE_FEATURE_SOCIAL_AUTH=false`,
  `RELATICLE_FEATURE_DOCUMENTATION=false` y
  `RELATICLE_FEATURE_ONBOARD_SEED=false` (si no, la primera cuenta recibe datos
  de demo).
- Arranque: `php artisan make:filament-user --panel=app` (primer admin) →
  entrar y crear el workspace → `php artisan crm:configurar-pipeline
  {id-o-slug}` → invitar al equipo **por correo** desde el CRM.
- Ojo: `make:filament-user` deja el email **sin verificar** (visto en local), y
  con `REQUIRE_EMAIL_VERIFICATION=true` el admin quedaría bloqueado. Verifícalo a
  mano: `php artisan tinker --execute='App\Models\User::where("email", "tu@email")->firstOrFail()->forceFill(["email_verified_at" => now()])->save();'`

## Nuestro pipeline de ventas
Etapas (columnas del tablero): **Oportunidad → Contactado → En trámite →
Contactar más tarde → Cerrada ganada / Cerrada fallida**.

Todo se define en `config/crm.php` y lo aplica `crm:configurar-pipeline`:
- **Oportunidad:** valor (€), responsable (desplegable con los nombres del
  equipo), origen (web / referido / frío / RRSS), fecha de cierre estimada,
  probabilidad %.
- **Empresa:** CIF, sector (desplegable), web.

Las etapas de upstream que coinciden (p. ej. *Prospecting*, *Closed Won*) se
renombran y conservan sus oportunidades; el resto se borran solo si nadie las
usa.

## Roadmap
- **Fase 1:** instalar y arrancar ✅, localización en español ✅, configurar
  etapas del pipeline y campos ✅ (comando), crear usuario admin, invitar al
  equipo, cargar los clientes actuales (pendientes, en el VPS).
- **Fase 2:** dashboard (valor por etapa, tasa de conversión) y recordatorios de
  seguimiento.
- **Fase 3:** integraciones (email / calendario) y aprovechar el MCP/API.

## Licencia (importante)
Relaticle es **AGPL-3.0**. Uso interno self-hosted → sin problema. Si algún día
se expone como servicio a terceros, la AGPL obliga a publicar el código fuente,
incluidas vuestras modificaciones. No lo mezcléis con código propietario que
queráis mantener cerrado.
