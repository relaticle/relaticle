---
title: Guide de contribution
description: Installez l'environnement de développement de Relaticle et découvrez son code PHP 8.5, Laravel 13 et Filament 5 avant d'ouvrir votre première PR.
order: 4
updated: "2026-10-29"
---

Documentation technique pour les développeurs et les contributeurs.

---

## Démarrage rapide

```
git clone https://github.com/Relaticle/relaticle.git
cd relaticle && composer app-install
composer run dev
```

Ouvrez `http://localhost:8000` pour accéder à l'application.

---

## Stack technique

| Composant | Technologie                           |
|-----------|---------------------------------------|
| Backend | PHP 8.5, Laravel 13                   |
| Interface d'administration | Filament 5                            |
| Frontend | Livewire 4, Alpine.js, Tailwind CSS 4 |
| Base de données | PostgreSQL                             |
| File d'attente | Laravel Horizon                       |
| Tests | Pest v5                               |
| Analyse statique | PHPStan (niveau 7)                     |
| Style de code | Laravel Pint, Rector                  |
| Authentification | Laravel Jetstream                     |

---

## Architecture

### Modèles principaux

```
Workspace ─┬─ User (via Membership)
           ├─ Company ─┬─ People
           │           └─ Opportunity ─── People
           ├─ Task (many-to-many with Company, People, Opportunity)
           └─ Note (many-to-many with Company, People, Opportunity)
```

### Architecture multi-tenant

Les données de chaque espace de travail sont isolées grâce au trait `HasWorkspace`. Chaque requête est automatiquement limitée à l'espace de travail courant.

### Traits principaux

| Trait | Rôle |
|-------|---------|
| `HasWorkspace` | Isolation par espace de travail |
| `HasCreator` | Enregistre l'auteur de la création d'un enregistrement |
| `HasNotes` | Relation polymorphe avec les notes |

---

## Installation pour le développement

### Prérequis

- **PHP 8.5+** avec les extensions : pdo_pgsql, gd, bcmath, mbstring, xml
- **PostgreSQL 17+**
- **Node.js 22+** avec pnpm
- **Composer 2+**

### Installation manuelle

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

### Configuration de l'environnement

Pour PostgreSQL :
```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=relaticle
DB_USERNAME=postgres
DB_PASSWORD=your_password
```

---

## Outils de qualité

```
composer lint          # Format with Pint + Rector
composer test:lint     # Check formatting
composer test:types    # PHPStan analysis
composer test:pest     # Run tests
composer test          # All checks (required before PR)
```

### Hooks Git

Activez les vérifications avant commit :
```
git config core.hooksPath .githooks
```

---

## Tests

Toute contribution exige :
- Des tests de fonctionnalité passant par les vrais points d'entrée pour tout nouveau comportement
- Des tests de fonctionnalité pour les interactions utilisateur
- 100 % de couverture des types

Lancez des tests précis :
```
php artisan test tests/Feature/ExampleTest.php
php artisan test --filter="test_method_name"
```

---

## Attributs personnalisés

Relaticle inclut un système d'attributs personnalisés qui permet d'étendre les entités sans migration.

- **Licence** : AGPL-3.0 (gratuite pour l'open source) ou commerciale
- **Documentation** : [Documentation des attributs personnalisés](https://relaticle.github.io/custom-fields/)

---

## Déploiement

Pour le déploiement en production, y compris la configuration Docker, l'environnement, le reverse proxy et les guides propres à chaque plateforme (Dokploy, Coolify), consultez le **guide d'auto-hébergement**.

---

## Dépannage

| Problème | Solution |
|-------|----------|
| La file d'attente ne traite rien | `php artisan queue:restart` |
| Erreurs d'envoi de fichiers | `chmod -R 775 storage bootstrap/cache` |
| Problèmes de cache des vues | `php artisan view:clear && pnpm run build` |

---

## Contribuer

1. Faites un fork du dépôt
2. Créez une branche de fonctionnalité : `git checkout -b feat/your-feature`
3. Apportez vos modifications en respectant les normes de code
4. Lancez les tests : `composer test`
5. Faites un commit avec des messages conventionnels
6. Ouvrez une Pull Request
7. Lors de votre première PR, signez le [Contributor License Agreement](https://github.com/relaticle/relaticle/blob/main/.github/CLA.md) en répondant par la phrase que publie le bot CLA

Les PR doivent réussir toutes les vérifications et avoir un CLA signé avant la fusion.

---

## Ressources

- [Documentation Laravel](https://laravel.com/docs/13.x)
- [Documentation Filament](https://filamentphp.com/docs)
- [Documentation Livewire](https://livewire.laravel.com/)
- [Documentation Pest](https://pestphp.com/)
