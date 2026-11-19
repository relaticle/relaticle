---
title: Leitfaden für Mitwirkende
description: Einrichtung der Entwicklungsumgebung für Relaticle und ein Überblick über die Codebasis mit PHP 8.5, Laravel 13 und Filament 5.
order: 4
updated: "2026-10-29"
---

Technische Dokumentation für Entwickler und Mitwirkende.

---

## Schnellstart

```
git clone https://github.com/Relaticle/relaticle.git
cd relaticle && composer app-install
composer run dev
```

Öffnen Sie `http://localhost:8000`, um die Anwendung aufzurufen.

---

## Technologie-Stack

| Komponente | Technologie                           |
|-----------|---------------------------------------|
| Backend | PHP 8.5, Laravel 13                   |
| Admin-Oberfläche | Filament 5                            |
| Frontend | Livewire 4, Alpine.js, Tailwind CSS 4 |
| Datenbank | PostgreSQL                             |
| Queue | Laravel Horizon                       |
| Tests | Pest v5                               |
| Statische Analyse | PHPStan (Level 7)                     |
| Code-Stil | Laravel Pint, Rector                  |
| Authentifizierung | Laravel Jetstream                     |

---

## Architektur

### Kernmodelle

```
Workspace ─┬─ User (via Membership)
           ├─ Company ─┬─ People
           │           └─ Opportunity ─── People
           ├─ Task (many-to-many with Company, People, Opportunity)
           └─ Note (many-to-many with Company, People, Opportunity)
```

### Mandantenfähigkeit

Alle Daten eines Arbeitsbereichs sind über den Trait `HasWorkspace` isoliert. Jede Abfrage wird automatisch auf den aktuellen Arbeitsbereich eingegrenzt.

### Wichtige Traits

| Trait | Zweck |
|-------|---------|
| `HasWorkspace` | Isolation der Arbeitsbereiche |
| `HasCreator` | Erfasst, wer einen Eintrag erstellt hat |
| `HasNotes` | Polymorphe Beziehung zu Notizen |

---

## Einrichtung der Entwicklungsumgebung

### Voraussetzungen

- **PHP 8.5+** mit den Erweiterungen pdo_pgsql, gd, bcmath, mbstring, xml
- **PostgreSQL 17+**
- **Node.js 22+** mit pnpm
- **Composer 2+**

### Manuelle Installation

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

### Umgebungskonfiguration

Für PostgreSQL:
```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=relaticle
DB_USERNAME=postgres
DB_PASSWORD=your_password
```

---

## Qualitätswerkzeuge

```
composer lint          # Format with Pint + Rector
composer test:lint     # Check formatting
composer test:types    # PHPStan analysis
composer test:pest     # Run tests
composer test          # All checks (required before PR)
```

### Git-Hooks

Aktivieren Sie die Prüfungen vor dem Commit:
```
git config core.hooksPath .githooks
```

---

## Tests

Jeder Beitrag braucht:
- Feature-Tests über die echten Einstiegspunkte für neues Verhalten
- Feature-Tests für Interaktionen der Nutzer
- 100 % Typabdeckung

Einzelne Tests ausführen:
```
php artisan test tests/Feature/ExampleTest.php
php artisan test --filter="test_method_name"
```

---

## Benutzerdefinierte Attribute

Relaticle enthält ein System für benutzerdefinierte Attribute. Damit erweitern Sie Entitäten ohne Migrationen.

- **Lizenz**: AGPL-3.0 (kostenlos für Open Source) oder kommerziell
- **Dokumentation**: [Dokumentation zu Custom Fields](https://relaticle.github.io/custom-fields/)

---

## Bereitstellung

Anleitungen für den Produktivbetrieb finden Sie im **Self-Hosting-Leitfaden**. Dort stehen die Docker-Einrichtung, die Umgebungskonfiguration, der Reverse Proxy und die Anleitungen für einzelne Plattformen (Dokploy, Coolify).

---

## Fehlerbehebung

| Problem | Lösung |
|-------|----------|
| Queue verarbeitet keine Jobs | `php artisan queue:restart` |
| Fehler beim Hochladen von Dateien | `chmod -R 775 storage bootstrap/cache` |
| Probleme mit dem View-Cache | `php artisan view:clear && pnpm run build` |

---

## Mitwirken

1. Forken Sie das Repository.
2. Legen Sie einen Feature-Branch an: `git checkout -b feat/your-feature`
3. Nehmen Sie Änderungen nach den Coding-Standards vor.
4. Führen Sie die Tests aus: `composer test`
5. Committen Sie mit Conventional Commits.
6. Öffnen Sie einen Pull Request.
7. Unterzeichnen Sie bei Ihrem ersten Pull Request die [Contributor License Agreement](https://github.com/relaticle/relaticle/blob/main/.github/CLA.md), indem Sie mit dem Satz antworten, den der CLA-Bot postet.

Pull Requests müssen alle Prüfungen bestehen und vor dem Merge eine unterzeichnete CLA haben.

---

## Ressourcen

- [Laravel-Dokumentation](https://laravel.com/docs/13.x)
- [Filament-Dokumentation](https://filamentphp.com/docs)
- [Livewire-Dokumentation](https://livewire.laravel.com/)
- [Pest-Dokumentation](https://pestphp.com/)
