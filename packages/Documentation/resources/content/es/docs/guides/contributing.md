---
title: Guía de contribución
description: Obtenga los pasos para preparar el entorno de desarrollo de Relaticle y una guía de su código base con PHP 8.5, Laravel 13 y Filament 5.
order: 4
updated: "2026-10-29"
---

Documentación técnica para desarrolladores y colaboradores.

---

## Inicio rápido

```
git clone https://github.com/Relaticle/relaticle.git
cd relaticle && composer app-install
composer run dev
```

Visite `http://localhost:8000` para acceder a la aplicación.

---

## Stack tecnológico

| Componente | Tecnología                            |
|-----------|---------------------------------------|
| Backend | PHP 8.5, Laravel 13                   |
| Interfaz de administración | Filament 5                            |
| Frontend | Livewire 4, Alpine.js, Tailwind CSS 4 |
| Base de datos | PostgreSQL                             |
| Cola | Laravel Horizon                       |
| Pruebas | Pest v5                               |
| Análisis estático | PHPStan (nivel 7)                     |
| Estilo de código | Laravel Pint, Rector                  |
| Autenticación | Laravel Jetstream                     |

---

## Arquitectura

### Modelos principales

```
Workspace ─┬─ User (via Membership)
           ├─ Company ─┬─ People
           │           └─ Opportunity ─── People
           ├─ Task (many-to-many with Company, People, Opportunity)
           └─ Note (many-to-many with Company, People, Opportunity)
```

### Multiinquilino

Los datos de cada espacio de trabajo se aíslan mediante el trait `HasWorkspace`. Cada consulta se limita automáticamente al espacio de trabajo actual.

### Traits principales

| Trait | Propósito |
|-------|---------|
| `HasWorkspace` | Aislamiento por espacio de trabajo |
| `HasCreator` | Registra quién creó el registro |
| `HasNotes` | Relación polimórfica de notas |

---

## Configuración del entorno de desarrollo

### Requisitos

- **PHP 8.5+** con las extensiones: pdo_pgsql, gd, bcmath, mbstring, xml
- **PostgreSQL 17+**
- **Node.js 22+** con pnpm
- **Composer 2+**

### Instalación manual

```
git clone https://github.com/Relaticle/relaticle.git
cd relaticle
composer install
pnpm install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan storage:link
pnpm run build
composer run dev
```

### Configuración del entorno

Para PostgreSQL:
```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=relaticle
DB_USERNAME=postgres
DB_PASSWORD=your_password
```

---

## Herramientas de calidad

```
composer lint          # Format with Pint + Rector
composer test:lint     # Check formatting
composer test:types    # PHPStan analysis
composer test:pest     # Run tests
composer test          # All checks (required before PR)
```

### Git hooks

Active las comprobaciones previas a cada commit:
```
git config core.hooksPath .githooks
```

---

## Pruebas

Toda contribución requiere:
- Pruebas de feature a través de los puntos de entrada reales para el comportamiento nuevo
- Pruebas de feature para las interacciones del usuario
- 100 % de cobertura de tipos

Ejecute pruebas específicas:
```
php artisan test tests/Feature/ExampleTest.php
php artisan test --filter="test_method_name"
```

---

## Atributos personalizados

Relaticle incluye un sistema de atributos personalizados para ampliar las entidades sin migraciones.

- **Licencia**: AGPL-3.0 (gratuita para código abierto) o comercial
- **Documentación**: [Documentación de Custom Fields](https://relaticle.github.io/custom-fields/)

---

## Despliegue

Para las instrucciones de despliegue en producción, incluidas la configuración de Docker, las variables de entorno, el proxy inverso y las guías por plataforma (Dokploy, Coolify), consulte la **Guía de autoalojamiento**.

---

## Solución de problemas

| Problema | Solución |
|-------|----------|
| La cola no procesa trabajos | `php artisan queue:restart` |
| Errores al subir archivos | `chmod -R 775 storage bootstrap/cache` |
| Problemas con la caché de vistas | `php artisan view:clear && pnpm run build` |

---

## Cómo contribuir

1. Haga un fork del repositorio
2. Cree una rama de feature: `git checkout -b feat/your-feature`
3. Haga los cambios siguiendo los estándares de código
4. Ejecute las pruebas: `composer test`
5. Haga el commit con mensajes convencionales
6. Abra un pull request
7. En su primer pull request, firme el [Acuerdo de Licencia de Colaborador](https://github.com/relaticle/relaticle/blob/main/.github/CLA.md) respondiendo con la frase que publica el bot del CLA

Los pull requests deben pasar todas las comprobaciones y tener un CLA firmado antes de la fusión.

---

## Recursos

- [Documentación de Laravel](https://laravel.com/docs/13.x)
- [Documentación de Filament](https://filamentphp.com/docs)
- [Documentación de Livewire](https://livewire.laravel.com/)
- [Documentación de Pest](https://pestphp.com/)
