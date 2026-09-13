# CRM interno del equipo — fork de Relaticle

> Este archivo son las reglas **del equipo**. El `CLAUDE.md` y el `AGENTS.md` de
> la raíz son de upstream (generados por Laravel Boost): no los edites, así
> `git pull upstream main` no da conflictos. Este archivo está versionado aunque
> `.gitignore` ignore `.claude/*` (se añadió con `git add -f`).

## Qué es este proyecto
CRM y pipeline de ventas **interno** del equipo, partiendo de un fork de
[Relaticle](https://github.com/Relaticle/relaticle) (CRM open-source sobre
Laravel + Filament). Objetivo: tener controlados todos los clientes y el
pipeline de ventas en un solo sitio.

- **Equipo (2-3 personas):** dirección/desarrollo, socio dev y un comercial.
- **Clientes:** pymes españolas (agencia CrabDev y demás líneas de negocio).
- **Idioma de la interfaz:** español.

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

- Al crearse, `post-create.sh` instala dependencias, crea `.env`, migra y
  compila assets. Es idempotente: se puede relanzar.
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

## Reglas de oro para el agente
1. **Campos nuevos = Custom Fields, no migraciones.** Para añadir atributos a
   Empresas / Contactos / Oportunidades, usa el sistema de Custom Fields del
   panel. Crea migraciones solo para entidades realmente nuevas. Esto mantiene
   el fork limpio para poder actualizar desde upstream.
2. **No romper tests.** Ejecuta Pest antes y después de cada cambio; mantenlo en
   verde.
3. **Cambios pequeños y commits atómicos.** Explica y pide confirmación antes de
   cualquier operación destructiva (migraciones destructivas, reseteo de BD,
   borrado de datos).
4. **No tocar la lógica multi-tenant / teams** salvo que se pida: somos un solo
   equipo, un solo workspace.
5. **PSR-12** y las convenciones ya presentes en el repo (Pint + Rector).
6. **UI en español:** usa la localización de Laravel/Filament (`lang/es`), no
   textos hardcodeados. **Pendiente:** upstream solo trae `lang/en` y
   `lang/fr`; hay que crear `es` (`php artisan lang:add es`, de
   `laravel-lang/publisher`) y poner `APP_LOCALE=es`.

## Mantener el fork actualizable
- `origin` = nuestro fork, `https://github.com/Guillermoj9/relaticle`
  (ahora público; **pasarlo a privado**). `upstream` = `Relaticle/relaticle`.
- Personalizar vía config, Custom Fields y archivos propios siempre que se
  pueda, evitando editar el core, para poder hacer `git pull upstream main`.
- Contexto personal o no versionado → `CLAUDE.local.md` (no commitear).
- `.env` no se versiona: hay que recrearlo en cada máquina.

## Nuestro pipeline de ventas
Etapas: **Nuevo → Cualificado → Propuesta enviada → Negociación → Ganado /
Perdido**.

Campos que nos importan:
- **Oportunidad:** valor (€), responsable, origen (web / referido / frío /
  RRSS), fecha de cierre estimada, probabilidad %.
- **Empresa:** CIF, sector, web.

## Roadmap
- **Fase 1:** instalar y arrancar, crear usuario admin, invitar al equipo,
  localización en español, configurar etapas del pipeline y campos, cargar los
  clientes actuales.
- **Fase 2:** dashboard (valor por etapa, tasa de conversión) y recordatorios de
  seguimiento.
- **Fase 3:** integraciones (email / calendario) y aprovechar el MCP/API.

## Licencia (importante)
Relaticle es **AGPL-3.0**. Uso interno self-hosted → sin problema. Si algún día
se expone como servicio a terceros, la AGPL obliga a publicar el código fuente,
incluidas vuestras modificaciones. No lo mezcléis con código propietario que
queráis mantener cerrado.
