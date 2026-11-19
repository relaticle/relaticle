---
title: Guide d'auto-hébergement
description: Déployez Relaticle avec Docker Compose, Coolify, Dokploy ou Laravel Cloud, avec la configuration de PostgreSQL, de Redis et d'Ollama.
order: 1
updated: "2026-10-31"
---

Déployez Relaticle sur votre propre infrastructure, avec Docker ou manuellement.

---

## Démarrage rapide

Lancez Relaticle en 5 étapes :

1. Téléchargez le fichier compose :

```bash
curl -o compose.yml https://raw.githubusercontent.com/Relaticle/relaticle/main/compose.yml
```

2. Générez une clé d'application :

```bash
echo "APP_KEY=base64:$(openssl rand -base64 32)"
```

3. Créez un fichier `.env` avec vos paramètres :

```bash
APP_KEY=base64:your-generated-key-here
DB_PASSWORD=your-secure-database-password
APP_URL=https://crm.example.com
```

4. Démarrez les conteneurs :

```bash
docker compose up -d
```

5. Créez votre compte administrateur :

```bash
docker compose exec app php artisan make:filament-user
```

Votre CRM est maintenant disponible à l'adresse `{APP_URL}/app`.

---

## Prérequis

| Ressource | Minimum | Recommandé |
|----------|---------|-------------|
| RAM | 2 Go | 4 Go |
| CPU | 1 cœur | 2 cœurs |
| Disque | 10 Go | 20 Go+ |
| Docker | 20.10+ | Dernière version |
| Docker Compose | v2.0+ | Dernière version |

---

## Variables d'environnement

### Obligatoires

Ces variables doivent être définies, sinon les conteneurs refusent de démarrer.

| Variable | Description |
|----------|-------------|
| `APP_KEY` | Clé de chiffrement. Générez-la avec `openssl rand -base64 32`, puis préfixez-la par `base64:`. |
| `DB_PASSWORD` | Mot de passe PostgreSQL. Utilisez une valeur aléatoire robuste. |

### Application

| Variable | Valeur par défaut | Description |
|----------|---------|-------------|
| `APP_NAME` | `Relaticle` | Affiché dans l'onglet du navigateur et dans les e-mails. |
| `APP_ENV` | `production` | À définir sur `production` pour l'auto-hébergement. |
| `APP_DEBUG` | `false` | À définir sur `true` uniquement pour le débogage. Jamais en production. |
| `APP_TIMEZONE` | `UTC` | Fuseau horaire de l'application. |
| `APP_URL` | `http://localhost` | URL complète à laquelle Relaticle est accessible. Incluez le schéma. |
| `TRUSTED_HOSTS` | `localhost` | Noms d'hôte supplémentaires que l'application accepte, séparés par des virgules. L'hôte de `APP_URL` et tous les hôtes `*_DOMAIN` sont toujours acceptés. Gardez `localhost` dans la liste, car la vérification d'état du conteneur l'appelle. |
| `TRUSTED_PROXIES` | (vide) | Adresses des proxys dont l'application croit les en-têtes `X-Forwarded-For`, `-Proto` et `-Port`, séparées par des virgules, sous forme d'adresses IP ou de plages CIDR. Vide, l'application fait confiance à toutes les plages privées (10/8, 172.16/12, 192.168/16, loopback), ce dont Traefik, Coolify et Dokploy ont besoin. Restreignez-les à votre proxy, par exemple à la passerelle ou au sous-réseau de son réseau Docker, pour qu'aucun autre conteneur ou pair de l'hôte ne puisse falsifier une adresse IP client. `*` fait confiance à tous les expéditeurs et n'est pas sûr. N'exposez jamais directement le port de l'application à un réseau non fiable. |
| `SESSION_SECURE_COOKIE` | (vide) | Envoie le cookie de session uniquement en HTTPS. Vide, il est sécurisé quand `APP_URL` commence par `https://`, et non sécurisé sinon. Définissez `true` ou `false` pour forcer le comportement. Servez les installations de production en HTTPS. Un cookie Secure envoyé en HTTP simple est rejeté par le navigateur : une installation en HTTP simple doit donc laisser cette variable vide ou à `false`, sinon personne ne peut se connecter. Une installation en `https://` que vous ouvrez aussi en HTTP simple, par exemple par l'adresse IP du réseau local, exige `false` pour que cet accès fonctionne. |
| `HSTS_MAX_AGE` | `15552000` | Nombre de secondes pendant lesquelles les navigateurs forcent HTTPS pour votre hôte. L'en-tête `Strict-Transport-Security` n'est envoyé que lorsque `APP_ENV=production` et que la requête est en HTTPS. Définissez `0` pour que les navigateurs l'oublient. |
| `HSTS_INCLUDE_SUBDOMAINS` | `false` | Définissez `true` pour étendre HSTS à tous les sous-domaines de l'hôte. Laissez-le désactivé si un sous-domaine voisin, comme un hôte d'intranet, est servi en HTTP simple. |
| `CSP_MODE` | `enforce` | Content-Security-Policy pour les pages HTML. `report-only` envoie la politique complète dans un en-tête en mode rapport seul, et `off` la supprime. `frame-ancestors`, `object-src` et `base-uri` restent appliqués dans tous les modes. |
| `CSP_SCRIPT_SRC`, `CSP_STYLE_SRC`, `CSP_IMG_SRC`, `CSP_FONT_SRC`, `CSP_CONNECT_SRC`, `CSP_FRAME_SRC` | (vide) | Origines supplémentaires pour cette directive, séparées par des virgules. Définissez `CSP_IMG_SRC` quand les médias ou les photos de profil se chargent depuis l'URL d'un bucket sur un autre hôte. L'origine du websocket est ajoutée pour vous. |
| `APP_PORT` | `80` | Port de l'hôte auquel le conteneur de l'application se lie. |
| `APP_PANEL_DOMAIN` | (vide) | À définir pour le routage par sous-domaine (par exemple `app.example.com`). Laissez vide pour le mode par chemin (`/app`). |
| `PASSKEYS_USER_HANDLE_SECRET` | `APP_KEY` | Sert à dériver l'identifiant utilisateur WebAuthn opaque stocké sur chaque authentificateur. Donnez-lui sa propre valeur aléatoire (`openssl rand -base64 32`) avant que quiconque enregistre une clé d'accès, afin qu'une rotation ultérieure de `APP_KEY` ne change pas l'identifiant de chaque membre. |
| `REQUIRE_EMAIL_VERIFICATION` | `true` | Quand la valeur est `false`, les membres se connectent sans vérifier leur adresse e-mail. C'est utile pour les auto-hébergeurs qui n'ont pas encore configuré SMTP. L'administrateur que vous créez avec `make:filament-user` est vérifié automatiquement dans tous les cas, donc la valeur par défaut `true` est sans risque pour les nouvelles installations Docker. Ne la définissez sur `false` que si votre panneau est sur un réseau privé : sans vérification, toute personne qui peut atteindre la page de connexion peut créer un compte fonctionnel. |
| `LOG_CHANNEL` | `stderr` | Destination des journaux. `stderr` est recommandé pour Docker. |
| `LOG_LEVEL` | `warning` | Niveau de journalisation minimal. Utilisez `debug` pour le dépannage. |

### E-mail

| Variable | Valeur par défaut | Description |
|----------|---------|-------------|
| `MAIL_MAILER` | `log` | Pilote d'e-mail : `smtp`, `ses`, `mailgun`, `postmark` ou `log`. |
| `MAIL_HOST` | (vide) | Hôte SMTP (par exemple `smtp.mailgun.org`). |
| `MAIL_PORT` | `587` | Port SMTP. |
| `MAIL_USERNAME` | (vide) | Nom d'utilisateur SMTP. |
| `MAIL_PASSWORD` | (vide) | Mot de passe SMTP. |
| `MAIL_ENCRYPTION` | `tls` | `tls` ou `ssl`. |
| `MAIL_FROM_ADDRESS` | `hello@example.com` | Adresse e-mail de l'expéditeur. |
| `MAIL_FROM_NAME` | `Relaticle` | Nom affiché de l'expéditeur. |

### Base de données et Redis

Ces variables sont préconfigurées dans `compose.yml` et n'ont généralement pas besoin d'être modifiées.

| Variable | Valeur par défaut | Description |
|----------|---------|-------------|
| `DB_DATABASE` | `relaticle` | Nom de la base de données PostgreSQL. |
| `DB_USERNAME` | `relaticle` | Nom d'utilisateur PostgreSQL. |
| `REDIS_PASSWORD` | `null` | Mot de passe Redis. Laissez `null` pour aucune authentification. |

### Mises à jour en direct

Rela, l'assistant IA intégré, diffuse ses réponses dans le chat par un websocket. Le conteneur `reverb` le sert, et le conteneur de l'application le relaie sous `/reverb` à l'adresse que vous utilisez déjà. Aucun port, domaine ou certificat supplémentaire n'est nécessaire.

| Variable | Valeur par défaut | Description |
|----------|---------|-------------|
| `REVERB_APP_SECRET` | `APP_KEY` | Signe les messages entre l'application et le conteneur `reverb`. Définissez-la pour la renouveler indépendamment de `APP_KEY`. |
| `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` | (vide) | Ne les définissez que si le websocket a sa propre adresse publique. Laissées vides, le navigateur se connecte à l'origine `APP_URL` sous `/reverb`. |

Quand le websocket est injoignable, le chat fonctionne toujours. Chaque réponse apparaît quelques secondes après son enregistrement, sans s'afficher en direct. Les prochaines étapes suggérées sous une réponse s'affichent après un rechargement de la page.

### Facultatives

| Variable | Description |
|----------|-------------|
| `GOOGLE_CLIENT_ID` | ID client OAuth Google pour la connexion sociale. Le bouton Google n'apparaît que si cette variable est définie. |
| `GOOGLE_CLIENT_SECRET` | Secret client OAuth Google. |
| `GOOGLE_REDIRECT_URI` | URL de rappel absolue (`https://your-host/auth/callback/google`), ou chemin relatif (`/auth/callback/google`) pour déduire l'hôte de la requête. |
| `MICROSOFT_CLIENT_ID` | ID client Microsoft Entra ID. Le bouton Microsoft n'apparaît que si cette variable est définie. Enregistrez `https://your-host/auth/callback/microsoft` comme URI de redirection. |
| `MICROSOFT_CLIENT_SECRET` | Secret client Microsoft Entra ID. |
| `SENTRY_LARAVEL_DSN` | DSN Sentry pour le suivi des erreurs. |
| `FATHOM_ANALYTICS_SITE_ID` | ID de site Fathom Analytics. |

### Liens juridiques et d'aide

Une installation auto-hébergée n'inclut aucune page juridique, et les politiques de relaticle.com ne couvrent pas votre installation. Faites pointer les trois premiers paramètres vers vos propres pages. Un paramètre non défini n'affiche aucun lien. Seule `DOCUMENTATION_PUBLIC_URL` pointe par défaut vers relaticle.com.

| Variable | Valeur par défaut | Description |
|----------|---------|-------------|
| `RELATICLE_PRIVACY_URL` | (vide) | Votre politique de confidentialité. Liée depuis le pied de page des pages de connexion, des écrans d'intégration, de la page de suppression programmée et de la page des boîtes mail. Non définie, aucun lien n'apparaît. |
| `RELATICLE_TERMS_URL` | (vide) | Vos conditions d'utilisation. Liées depuis le pied de page de connexion, les écrans d'intégration et la page de suppression programmée. Non définies, aucun lien n'apparaît. |
| `RELATICLE_CONTACT_URL` | (vide) | Votre page de contact ou d'assistance. Liée depuis le pied de page de connexion. Non définie, aucun lien n'apparaît. |
| `DOCUMENTATION_PUBLIC_URL` | `https://relaticle.com` | Le site qui sert le centre d'aide et les guides pour les développeurs. Rela et les liens d'aide du panneau pointent vers lui. Définissez votre propre miroir si vous en hébergez un. |

### E-mail et agenda

La synchronisation des e-mails et de l'agenda est désactivée par défaut. Activez-la avec `RELATICLE_FEATURE_EMAIL_INTEGRATION=true`. Les membres peuvent alors connecter des boîtes mail Google, et des boîtes mail Microsoft une fois que vous avez configuré un client Microsoft.

Les boîtes mail Google ont besoin de leur propre client OAuth Google, distinct de celui de la connexion sociale, avec l'API Gmail et l'API Google Calendar activées.

| Variable | Valeur par défaut | Description |
|----------|---------|-------------|
| `GMAIL_CLIENT_ID` | | ID client OAuth du client Google utilisé pour connecter les boîtes mail. |
| `GMAIL_CLIENT_SECRET` | | Secret client OAuth de ce client. |
| `GMAIL_REDIRECT_URI` | | URL de rappel. Enregistrez `https://your-host/email-accounts/callback/gmail` comme URI de redirection dans Google Cloud. |

Le client demande trois scopes : `gmail.readonly`, `gmail.send` et `calendar.events`. Ajoutez-les à l'écran de consentement OAuth. Tant que l'écran de consentement est en mode test, seuls les utilisateurs de test que vous listez peuvent se connecter.

Les boîtes mail Outlook et Microsoft 365 ont besoin d'un enregistrement d'application dans Microsoft Entra ID. Le bouton **Connecter une boîte mail Microsoft** n'apparaît que si `AZURE_CLIENT_ID` est défini.

| Variable | Valeur par défaut | Description |
|----------|---------|-------------|
| `AZURE_CLIENT_ID` | | ID de l'application (client) de l'enregistrement d'application Entra utilisé pour connecter les boîtes mail. |
| `AZURE_CLIENT_SECRET` | | Secret client de cet enregistrement d'application. |
| `AZURE_REDIRECT_URI` | | URL de rappel. Enregistrez `https://your-host/email-accounts/callback/azure` comme URI de redirection de type Web dans Entra. |
| `AZURE_TENANT_ID` | `common` | Annuaire par lequel passe la connexion. Définissez l'ID de votre tenant pour n'accepter que les comptes d'une seule organisation. |

L'application demande cinq permissions Microsoft Graph déléguées : `Mail.Read`, `Mail.Send`, `Calendars.ReadWrite`, `User.Read` et `offline_access`. Ajoutez-les dans Autorisations de l'API. Les types de comptes pris en charge que vous choisissez sur l'enregistrement d'application déterminent quels comptes peuvent se connecter.

Un paramètre s'applique aux deux fournisseurs.

| Variable | Valeur par défaut | Description |
|----------|---------|-------------|
| `EMAIL_SYNC_INITIAL_DAYS` | | Limite le premier import aux N derniers jours. Laissez non défini pour importer toute la boîte mail. |

### Assistant IA

Rela fonctionne avec des fournisseurs cloud, un serveur auto-hébergé, ou les deux. Les modèles n'apparaissent dans le sélecteur de modèle du chat que si leur fournisseur est configuré. Sans aucun fournisseur configuré, l'assistant ne peut pas répondre.

Il existe deux façons d'exécuter un modèle auto-hébergé, et vous pouvez utiliser l'une, l'autre ou les deux :

- **[Ollama](https://ollama.com)** (`OLLAMA_MODEL`) : la voie la plus simple, avec la meilleure prise en charge de l'appel d'outils.
- **Tout point d'accès compatible OpenAI** (`SELF_HOSTED_AI_*`) : pointez vers [vLLM](https://docs.vllm.ai) (idéal pour de nombreux utilisateurs simultanés), LM Studio, LocalAI ou une passerelle hébergée. Expose plusieurs modèles à la fois.

| Variable | Valeur par défaut | Description |
|----------|---------|-------------|
| `ANTHROPIC_API_KEY` | (vide) | Active les modèles Claude dans le sélecteur du chat. |
| `OPENAI_API_KEY` | (vide) | Active les modèles GPT dans le sélecteur du chat. |
| `OLLAMA_BASE_URL` | `http://localhost:11434` | URL de votre serveur Ollama. Depuis l'intérieur d'un conteneur Docker, `localhost` désigne le conteneur lui-même. Utilisez `http://host.docker.internal:11434` (ou l'adresse IP de votre hôte sur le réseau local) pour joindre un Ollama qui tourne sur l'hôte. |
| `OLLAMA_MODEL` | (vide) | Tag du modèle Ollama (par exemple `qwen3:14b`). Le définir ajoute le modèle au sélecteur du chat. |
| `SELF_HOSTED_AI_URL` | (vide) | URL de base d'un point d'accès compatible OpenAI (par exemple `http://localhost:8000/v1`). |
| `SELF_HOSTED_AI_KEY` | (vide) | Clé d'API de ce point d'accès, s'il en exige une (vide pour la plupart des serveurs locaux). |
| `SELF_HOSTED_AI_MODELS` | (vide) | Tags de modèles à exposer, séparés par des virgules (par exemple `qwen3:14b,llama3.1:70b`). Chacun devient une entrée du sélecteur. |
| `CHAT_RATE_LIMIT_PER_MINUTE` | `60` | Nombre de messages qu'un espace de travail peut envoyer à Rela par minute. Définissez `0` pour supprimer la limite. |

**Choisir un modèle.** L'assistant appelle des outils du CRM (recherche, création, mise à jour, suppression). Seuls les modèles qui gèrent très bien l'appel d'outils fonctionnent de façon fiable. `qwen3` et les modèles de la classe de `llama3.1:70b` sont de bons points de départ. Les petits modèles ont tendance à affirmer qu'une action a réussi sans avoir réellement appelé l'outil. Vérifiez un modèle avant de lui faire confiance avec `php artisan chat:models --probe=<id>`, qui liste le registre et teste l'appel d'outils sur son point d'accès. Cette vérification ne vaut que pour les modèles auto-hébergés : les modèles cloud passent par le SDK du fournisseur et sont refusés.

**Limites connues avec les modèles auto-hébergés :**

- Les fournisseurs cloud imposent une seule proposition d'écriture à la fois au niveau de l'API. Les modèles auto-hébergés n'ont pas d'équivalent, donc cette règle n'est appliquée que par les instructions du prompt. Chaque écriture exige toujours votre approbation explicite avant que quoi que ce soit soit enregistré.
- Chaque réponse doit se terminer en 120 secondes. Si un modèle lent dépasse ce délai, le tour s'arrête proprement et rien n'est perdu en silence. Votre message reste dans la conversation avec une note indiquant que la réponse a atteint la limite de 120 secondes. Demandez une réponse plus courte, ou en plusieurs parties. Pour éviter la limite, utilisez un modèle plus petit ou plus rapide, ou un GPU. Les modèles « à réflexion » comme `qwen3` raisonnent avant de répondre et atteignent la limite plus tôt : un modèle sans réflexion ou un prompt plus court aide pour un usage interactif.

### Feature flags

Activez ou désactivez des fonctionnalités. Toutes sont activées par défaut, sauf indication contraire. C'est utile pour les forks et les déploiements personnalisés qui veulent désactiver une fonctionnalité précise sans modifier le code.

| Variable | Valeur par défaut | Description |
|----------|---------|-------------|
| `RELATICLE_FEATURE_ONBOARD_SEED` | `true` | Crée des données de démonstration (entreprises, personnes et tâches d'exemple) à la création d'un nouvel espace de travail. Définissez `false` pour démarrer avec un espace de travail vide. |
| `RELATICLE_FEATURE_SOCIAL_AUTH` | `true` | Active la connexion sociale Google et Microsoft. Définissez `false` pour n'utiliser que la connexion par e-mail, mot de passe et clé d'accès. |
| `RELATICLE_FEATURE_DOCUMENTATION` | `true` | Sert la spécification OpenAPI à `/openapi.json` et `/openapi.yaml`, et affiche le lien du guide MCP sur la page des jetons d'accès. Définissez `false` pour retirer les deux. La spécification n'existe qu'après l'écriture par `php artisan scribe:generate`. L'image Docker et les étapes manuelles n'exécutent pas cette commande, contrairement au build de Laravel Cloud. La référence de l'API à `/developers/api` est une page de documentation de relaticle.com et charge la même spécification. |
| `RELATICLE_FEATURE_EMAIL_INTEGRATION` | `false` | Active la synchronisation des e-mails et de l'agenda pour les comptes Google et Microsoft, l'éditeur d'e-mails et les pages d'aide E-mail et agenda. Exige `GMAIL_CLIENT_ID` et `GMAIL_CLIENT_SECRET`. Les comptes Microsoft exigent aussi `AZURE_CLIENT_ID` et `AZURE_CLIENT_SECRET`. |
| `RELATICLE_FEATURE_SIGNUP_CHALLENGE` | `false` | Demande aux personnes qui s'inscrivent de réussir une vérification Cloudflare Turnstile. Elle reste invisible pour les navigateurs normaux et n'affiche une case à cocher que lorsque Cloudflare l'exige. Exige `TURNSTILE_SITE_KEY` et `TURNSTILE_SECRET_KEY`. Le flag refuse aussi les inscriptions depuis les relais de sortie Tor, ce qui n'exige aucune clé. Il consulte la liste des sorties du Tor Project, actualisée toutes les heures. La connexion via Tor fonctionne toujours. Tant que le flag est activé, l'inscription par e-mail crée au plus 3 comptes par jour et par adresse IP, et 5 par heure sur l'ensemble de l'installation. La connexion Google et Microsoft n'est jamais comptée. Modifiez ces plafonds avec `RELATICLE_SIGNUP_MAX_PER_IP_PER_DAY` et `RELATICLE_SIGNUP_MAX_PER_HOUR`, et définissez l'un d'eux à `0` pour le désactiver. Le flag enregistre aussi l'adresse IP et le navigateur de chaque nouvelle inscription pour les administrateurs système, et supprime cet enregistrement après 90 jours. |

`RELATICLE_FEATURE_BLOG` ne fait rien sur une installation auto-hébergée. Il active les pages du blog, qui se trouvent dans le package cloud. L'image Docker laisse ce package de côté, avec le site marketing et les pages du centre d'aide et des guides pour les développeurs.

Laissez `RELATICLE_FEATURE_BILLING` non défini, car rien dans une installation auto-hébergée ne peut démarrer un abonnement. Activé, chaque espace de travail créé après l'exécution de la migration de facturation de juillet 2026 sur votre installation passe à Gratuit ou à Essai terminé. Sur une nouvelle installation, cela concerne tous les espaces de travail. Les deux refusent l'accès : l'envoi d'e-mails, la synchronisation des boîtes mail et celle de l'agenda s'arrêtent pour ces espaces de travail. Les e-mails déjà en file d'attente pour eux sont marqués comme échoués et ne sont pas renvoyés quand vous désactivez le flag. La page des jetons d'accès gagne aussi un lien d'aide vers un formulaire web.

---

## Architecture

La configuration Docker exécute 6 conteneurs :

| Conteneur | Image | Rôle |
|-----------|-------|---------|
| **app** | `ghcr.io/relaticle/relaticle:latest` | Serveur web (nginx + PHP-FPM) sur le port 8080. Exécute automatiquement les migrations au démarrage. |
| **horizon** | `ghcr.io/relaticle/relaticle:latest` | Worker de file d'attente propulsé par Laravel Horizon. Traite les tâches en arrière-plan. |
| **scheduler** | `ghcr.io/relaticle/relaticle:latest` | Exécute `schedule:work` pour les tâches récurrentes (par exemple le nettoyage et les notifications). |
| **reverb** | `ghcr.io/relaticle/relaticle:latest` | Serveur websocket (Laravel Reverb). Diffuse les réponses de Rela vers le navigateur. |
| **postgres** | `postgres:17-alpine` | Base de données PostgreSQL 17. |
| **redis** | `redis:7-alpine` | Cache, sessions et backend de file d'attente. Fonctionne avec une persistance en ajout seul. |

### Volumes

| Volume | Rôle |
|--------|---------|
| `postgres` | Fichiers de la base de données. Sauvegardez-le. |
| `redis` | Données de persistance de Redis. |
| `storage` | Fichiers envoyés et stockage de l'application. Sauvegardez-le. |

### Réseau

Le conteneur de l'application écoute sur le port 8080 en interne et correspond à `APP_PORT` (80 par défaut) sur l'hôte. Tous les conteneurs communiquent par le réseau interne de Docker. Seul le conteneur de l'application expose un port à l'hôte. Le navigateur atteint le websocket par son intermédiaire, à `/reverb`.

---

## Créer votre compte administrateur

Après avoir démarré les conteneurs, créez votre premier utilisateur administrateur :

```bash
docker compose exec app php artisan make:filament-user
```

Quand on vous demande de choisir un panneau, choisissez `app`, puis saisissez un nom, une adresse e-mail et un mot de passe. Une fois le compte créé, accédez au panneau du CRM à `{APP_URL}/app`. (Pour créer à la place l'administrateur système de toute l'instance, consultez la section suivante.)

**Remarque** : par défaut, le panneau du CRM est disponible sous le chemin `/app`. Pour utiliser le routage par sous-domaine à la place (par exemple `app.example.com`), définissez la variable d'environnement `APP_PANEL_DOMAIN`.

### Compte administrateur système

Le panneau `sysadmin` à `{APP_URL}/sysadmin` est une surface d'administration distincte, valable pour toute l'instance, qui sert à gérer chaque espace de travail et chaque membre de votre installation. Pour créer un administrateur système, vous pouvez soit choisir `sysadmin` dans l'invite de panneau de `make:filament-user`, soit utiliser la commande dédiée :

```bash
docker compose exec app php artisan sysadmin:create
```

Le panneau s'ouvre sur le tableau de bord **Instance** à `{APP_URL}/sysadmin/instance`, et `{APP_URL}/sysadmin` redirige vers cette page. Il compte les membres, les espaces de travail, les entreprises, les personnes et les opportunités de tous les espaces de travail de votre installation. Les pages de revenus, d'abonnements et de crédits d'IA que montre le panneau du personnel de relaticle.com appartiennent au package cloud et n'existent pas ici.

---

## Reverse proxy et SSL

Le conteneur de l'application sert du HTTP sur le port 8080 en interne. Placez un reverse proxy devant lui pour gérer la terminaison SSL et acheminer le trafic vers le conteneur.

Le SSL n'est pas facultatif si vous voulez des clés d'accès : les navigateurs n'exécutent WebAuthn qu'en HTTPS, et une clé d'accès est liée à l'hôte sur lequel elle a été enregistrée. Cet hôte est `APP_PANEL_DOMAIN` quand le routage par sous-domaine est activé, et l'hôte de `APP_URL` sinon. Servez donc le panneau depuis un seul nom d'hôte et gardez-le stable.

### Nginx

```nginx
map $http_upgrade $connection_upgrade {
    default upgrade;
    '' close;
}

server {
    listen 443 ssl http2;
    server_name crm.example.com;

    ssl_certificate /etc/letsencrypt/live/crm.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/crm.example.com/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:80;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
    }
}

server {
    listen 80;
    server_name crm.example.com;
    return 301 https://$server_name$request_uri;
}
```

### Caddy

```
crm.example.com {
    reverse_proxy 127.0.0.1:80
}
```

Caddy gère automatiquement les certificats SSL via Let's Encrypt, et il transmet les websockets sans configuration supplémentaire. Traefik aussi.

### Traefik

Ajoutez ces labels au service `app` de votre `compose.yml` :

```yaml
labels:
  - "traefik.enable=true"
  - "traefik.http.routers.relaticle.rule=Host(`crm.example.com`)"
  - "traefik.http.routers.relaticle.entrypoints=websecure"
  - "traefik.http.routers.relaticle.tls.certresolver=letsencrypt"
  - "traefik.http.services.relaticle.loadbalancer.server.port=8080"
```

Les en-têtes `Upgrade` et `Connection` du bloc Nginx transportent le websocket du chat. Un proxy qui les supprime sert quand même l'application, mais les réponses apparaissent alors avec quelques secondes de retard.

**Remarque** : avec un reverse proxy, définissez `APP_URL` sur votre URL HTTPS publique (par exemple `https://crm.example.com`). L'application fait confiance aux en-têtes `X-Forwarded-*` provenant des réseaux privés RFC1918, de loopback et des adresses IPv6 ULA et link-local, ce qui couvre Coolify, Dokploy et Traefik sur un réseau Docker, ainsi que les reverse proxys sur l'hôte. Les en-têtes provenant d'adresses IP publiques sont rejetés, ce qui empêche l'usurpation.

L'application n'accepte que les requêtes dont l'en-tête `Host` correspond à `APP_URL`, à `www.` suivi de cet hôte, à un `*_DOMAIN` configuré ou à une entrée de `TRUSTED_HOSTS`. Tout autre hôte reçoit une erreur 400. Votre proxy doit transmettre le véritable en-tête `Host` (Nginx `proxy_set_header Host $host;`) et ne doit pas laisser passer un `X-Forwarded-Host` fourni par le client. L'application ignore `X-Forwarded-Host` et `X-Forwarded-Prefix`. Les sondes qui appellent le conteneur par son adresse IP ou un nom interne ont besoin de ce nom dans `TRUSTED_HOSTS`, ou doivent envoyer le domaine configuré comme en-tête `Host`.

---

## Déployer sur Dokploy

[Dokploy](https://dokploy.com/) est une plateforme de déploiement open source. Voici comment y déployer Relaticle.

### 1. Créer un projet

Dans le tableau de bord de Dokploy, cliquez sur **Create Project** et donnez-lui un nom (par exemple « Relaticle »).

### 2. Ajouter un service Compose

1. Dans votre projet, cliquez sur **Create Service** et sélectionnez **Compose**
2. Définissez la source sur **Raw** et collez le contenu du fichier [compose.yml](https://raw.githubusercontent.com/Relaticle/relaticle/main/compose.yml)
3. Cliquez sur **Create**

### 3. Définir les variables d'environnement

Dans l'onglet **Environment** du service, ajoutez les variables obligatoires :

```bash
APP_KEY=base64:your-generated-key-here
DB_PASSWORD=your-secure-database-password
APP_URL=https://crm.yourdomain.com
APP_PORT=8080
```

Générez votre `APP_KEY` avec :

```bash
echo "base64:$(openssl rand -base64 32)"
```

**Remarque** : définissez `APP_PORT=8080` pour que le conteneur associe le port 8080:8080, ce qui évite les conflits avec le port 80 de Dokploy.

### 4. Déployer

Cliquez sur **Deploy**. Dokploy récupère les images et démarre les 6 conteneurs. Attendez que les vérifications d'état réussissent.

### 5. Configurer le domaine

1. Allez dans l'onglet **Domains** du service `app`
2. Ajoutez votre domaine (par exemple `crm.yourdomain.com`)
3. Définissez le port du conteneur sur `8080`
4. Activez HTTPS (Dokploy gère Let's Encrypt automatiquement)

### 6. Créer l'utilisateur administrateur

Ouvrez le terminal Dokploy du conteneur `app` et exécutez :

```bash
php artisan make:filament-user
```

Votre instance Relaticle est maintenant en ligne à l'adresse `https://crm.yourdomain.com/app`.

---

## Déployer sur Coolify

[Coolify](https://coolify.io/) est une plateforme open source et auto-hébergeable pour déployer des applications.

### 1. Créer un nouveau projet

Dans le tableau de bord de Coolify, cliquez sur **New Project** et donnez-lui un nom.

### 2. Ajouter une ressource Docker Compose

1. Cliquez sur **Add Resource** dans votre projet
2. Sélectionnez **Docker Compose**
3. Choisissez **Empty** comme source, puis collez le contenu du fichier [compose.yml](https://raw.githubusercontent.com/Relaticle/relaticle/main/compose.yml)

### 3. Configurer les variables d'environnement

Dans la section **Environment Variables** de la ressource, ajoutez :

```bash
APP_KEY=base64:your-generated-key-here
DB_PASSWORD=your-secure-database-password
APP_URL=https://crm.yourdomain.com
```

### 4. Configurer le domaine

1. Allez dans les **Settings** du service app
2. Définissez votre domaine (par exemple `crm.yourdomain.com`)
3. Coolify provisionne automatiquement un certificat SSL

### 5. Déployer

Cliquez sur **Deploy**. Coolify récupère les images, crée les conteneurs et démarre les services.

### 6. Créer l'utilisateur administrateur

Utilisez la fonction **Terminal** de Coolify pour exécuter dans le conteneur `app` :

```bash
php artisan make:filament-user
```

Accédez à votre CRM à l'adresse `https://crm.yourdomain.com/app`.

---

## Déployer sur Laravel Cloud

Laravel Cloud exécute Relaticle sans Docker. Les réplicas web et les workers de file d'attente ont chacun leur propre disque éphémère : chaque fichier que Relaticle conserve va donc dans un stockage d'objets.

### 1. Créer l'application

Créez une application à partir de votre fork du dépôt Relaticle. Associez ces ressources à son environnement :

| Ressource | Paramètre |
|----------|---------|
| Base de données | Laravel Serverless Postgres |
| Cache | Laravel Valkey, avec la politique d'éviction définie sur **No eviction** |
| WebSockets | Laravel Reverb |
| Bucket | Un bucket **public** avec le nom de disque `public` |
| Bucket | Un bucket **privé** avec le nom de disque `s3` |

Cloud fait pointer les disques nommés `public` et `s3` vers les buckets, donc les noms doivent correspondre exactement. Laissez « default disk » décoché sur les deux buckets : le disque par défaut reste `local`, qui ne contient que les artefacts de build.

Définissez les origines autorisées de chaque bucket sur l'URL de votre application, par exemple `https://your-app.laravel.cloud`. Les navigateurs envoient les fichiers directement vers le bucket privé, et ils ont besoin de cette origine pour passer la vérification CORS du bucket.

Les tâches en file d'attente et Horizon vivent dans Valkey, à côté du cache. Une politique d'éviction peut supprimer en silence une tâche en file d'attente quand la mémoire est pleine : gardez donc No eviction.

### 2. Définir les variables d'environnement

Cloud injecte les identifiants de la base de données, du cache, de Reverb et des buckets. Ajoutez celles-ci :

```bash
APP_KEY=base64:your-generated-key-here
QUEUE_CONNECTION=redis
CACHE_STORE=redis
BROADCAST_CONNECTION=reverb
APP_MAINTENANCE_DRIVER=cache
MEDIA_DISK=s3
FILAMENT_FILESYSTEM_DISK=s3
LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=s3
IMPORT_STORE_DISK=s3
PASSPORT_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----..."
PASSPORT_PUBLIC_KEY="-----BEGIN PUBLIC KEY-----..."
```

Générez une seule fois les clés Passport avec `php artisan passport:keys --force` sur votre machine, puis collez le contenu de `storage/oauth-private.key` et de `storage/oauth-public.key`.

`FILAMENT_FILESYSTEM_DISK` doit valoir `s3`. Non défini, les exports du CRM sont écrits sur le disque d'un seul réplica et les téléchargements depuis un autre échouent. Ne le faites jamais pointer vers le bucket public : les exports contiennent des données clients.

### 3. Commandes de build et de déploiement

Cloud n'installe pas pnpm, donc le build installe la version que fixe `package.json`. La mise en cache relève du build : les modifications de fichiers d'une commande de déploiement ne sont pas conservées.

Commande de build :

```bash
composer install --no-dev --optimize-autoloader
npm install -g "$(node -p 'require("./package.json").packageManager')"
pnpm install --frozen-lockfile --ignore-scripts
pnpm run build
php artisan scribe:generate
php artisan optimize
php artisan filament:optimize
```

Commande de déploiement :

```bash
php artisan migrate --force
```

Un fork contient `packages/Cloud`, le code propre à relaticle.com. Il reste en dehors de l'application tant que `RELATICLE_CLOUD` n'est pas défini : laissez donc cette variable hors de l'environnement.

Cloud redémarre Horizon à chaque déploiement, donc aucune étape `horizon:terminate` n'est nécessaire.

### 4. Workers de file d'attente et planificateur

Activez l'option Scheduler sur le cluster App. Les clusters de workers exigent l'offre Growth ou supérieure. Ajoutez un cluster de workers, gardez-le éveillé au lieu de le laisser se mettre en veille avec le cluster App, et donnez-lui un processus d'arrière-plan personnalisé :

```bash
php artisan horizon
```

Chaque réplica de worker exécute l'ensemble complet des superviseurs Horizon. Ajustez le nombre de processus avec `HORIZON_DEFAULT_MIN`, `HORIZON_DEFAULT_MAX`, `HORIZON_IMPORTS_MIN` et `HORIZON_IMPORTS_MAX`.

### 5. Créer l'utilisateur administrateur

Ouvrez l'onglet Commands de l'environnement et exécutez la commande en passant chaque valeur sous forme d'option. L'onglet Commands ne peut pas répondre à des invites interactives.

```bash
php artisan make:filament-user --name="Your Name" --email=you@example.com --password=your-secure-password
```

Accédez à votre CRM à l'adresse `https://your-app.laravel.cloud/app`.

---

## Mettre à niveau

### 1. Sauvegarder vos données

Sauvegardez toujours avant de mettre à niveau. Consultez la section Sauvegarde et restauration ci-dessous.

### 2. Récupérer les dernières images

```bash
docker compose pull
```

### 3. Redémarrer les conteneurs

```bash
docker compose up -d
```

Les migrations de la base de données s'exécutent automatiquement au démarrage. Le conteneur de l'application applique toutes les migrations en attente avant de servir du trafic.

### 4. Actualiser le fichier Compose

Un `compose.yml` téléchargé avant octobre 2026 n'a pas de conteneur `reverb`. Le chat fonctionne sans lui, et les réponses apparaissent quelques secondes après leur enregistrement. Pour obtenir la diffusion en direct, téléchargez de nouveau le fichier et démarrez la stack :

```bash
curl -o compose.yml https://raw.githubusercontent.com/Relaticle/relaticle/main/compose.yml
docker compose up -d
```

Copiez d'abord les modifications que vous avez faites dans l'ancien fichier. Le nouveau fichier transmet aux conteneurs les variables des fournisseurs d'IA depuis `.env`, donc il n'est plus nécessaire d'ajouter ces lignes à la main.

L'image met désormais en cache dans Redis par défaut, si bien que les conteneurs web, Horizon et scheduler partagent un seul cache. L'ancien fichier ne donnait Redis qu'au conteneur web, et le bouton Stop du chat n'atteignait jamais le worker. Une stack qui définit elle-même `CACHE_STORE` garde sa propre valeur.

**Remarque** : consultez les [notes de version](https://github.com/Relaticle/relaticle/releases) avant de mettre à niveau. Les changements incompatibles y sont documentés.

---

## Sauvegarde et restauration

### Sauvegarde de la base de données

```bash
docker compose exec postgres pg_dump -U relaticle relaticle > backup-$(date +%Y%m%d).sql
```

### Restauration de la base de données

```bash
docker compose exec -T postgres psql -U relaticle relaticle < backup-20260320.sql
```

### Sauvegarde du stockage

Sauvegardez le volume de stockage, qui contient les fichiers envoyés :

```bash
docker compose cp app:/var/www/html/storage/app ./storage-backup
```

### Sauvegardes automatiques

Ajoutez une tâche cron pour sauvegarder chaque jour :

```bash
0 3 * * * cd /path/to/relaticle && docker compose exec -T postgres pg_dump -U relaticle relaticle | gzip > /backups/relaticle-$(date +\%Y\%m\%d).sql.gz
```

---

## Déploiement manuel

Si vous préférez ne pas utiliser Docker, vous pouvez déployer Relaticle directement sur un serveur.

### Prérequis

- PHP 8.5+ avec les extensions : pdo_pgsql, gd, bcmath, mbstring, xml, redis
- PostgreSQL 17+
- Redis 7+
- Node.js 22+
- Composer 2+
- Nginx ou Apache
- Supervisor (pour les workers de file d'attente)

### Installation

1. Clonez le dépôt :

```bash
git clone https://github.com/Relaticle/relaticle.git /var/www/relaticle
cd /var/www/relaticle
```

2. Installez les dépendances PHP :

```bash
composer install --no-dev --optimize-autoloader
```

3. Configurez l'environnement :

```bash
cp .env.example .env
php artisan key:generate
```

Modifiez `.env` avec les identifiants de votre base de données, vos paramètres d'e-mail et `APP_URL`. Définissez `APP_ENV=production` et `APP_DEBUG=false`. Le fichier d'exemple fournit `local` et `true`, et les pages de débogage en production exposent votre configuration.

Laissez `RELATICLE_CLOUD` non défini. `packages/Cloud` contient la facturation, le site marketing et les pages d'administration hébergées propres à relaticle.com. L'image Docker le laisse de côté, et un clone le contient. Avec l'interrupteur non défini, il reste en dehors de l'application et du build du frontend, donc votre installation ne sert jamais le site marketing de relaticle.com à `/`. Sa licence autorise l'usage pour le développement et les tests : le dossier reste donc sur le disque et `git pull` continue de fonctionner.

4. Compilez le frontend :

```bash
pnpm install --frozen-lockfile && pnpm run build
```

5. Préparez la base de données :

```bash
php artisan migrate --force
php artisan storage:link
```

6. Définissez les permissions :

```bash
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

7. Créez votre utilisateur administrateur :

```bash
php artisan make:filament-user
```

### Mettre à jour une installation manuelle

```bash
cd /var/www/relaticle
php artisan down
git pull
composer install --no-dev --optimize-autoloader
pnpm install --frozen-lockfile && pnpm run build
php artisan migrate --force
php artisan optimize
php artisan up
php artisan horizon:terminate
php artisan reverb:restart
```

`php artisan optimize` reconstruit les caches de configuration et de routes, qui nomment encore des routes que servait la version précédente. Si vous mettez en cache les composants avec `php artisan filament:optimize`, relancez cette commande après `php artisan up`. Si PHP-FPM tourne avec `opcache.validate_timestamps=0`, rechargez-le aussi.

Supervisor redémarre Horizon après `horizon:terminate`. Consultez les [notes de version](https://github.com/Relaticle/relaticle/releases) avant de mettre à jour.

### Configuration Nginx

```nginx
server {
    listen 80;
    server_name crm.example.com;
    root /var/www/relaticle/public;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location /reverb/app/ {
        rewrite ^/reverb(/.*)$ $1 break;
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Le bloc `/reverb/app/` transporte le websocket du chat vers Reverb. Dans `.env`, définissez `BROADCAST_CONNECTION=reverb`, `BROADCAST_REVERB_HOST=127.0.0.1` et `BROADCAST_REVERB_PORT=8080`, et laissez `REVERB_HOST` vide.

### Supervisor pour Horizon

Créez `/etc/supervisor/conf.d/relaticle-horizon.conf` :

```ini
[program:relaticle-horizon]
process_name=%(program_name)s
command=php /var/www/relaticle/artisan horizon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/relaticle/storage/logs/horizon.log
stopwaitsecs=3600
```

Puis démarrez-le :

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start relaticle-horizon
```

### Supervisor pour Reverb

Créez `/etc/supervisor/conf.d/relaticle-reverb.conf` :

```ini
[program:relaticle-reverb]
process_name=%(program_name)s
command=php /var/www/relaticle/artisan reverb:start --host=127.0.0.1 --port=8080
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/relaticle/storage/logs/reverb.log
```

Démarrez-le de la même façon, puis exécutez `php artisan reverb:restart` après chaque mise à niveau.

### Cron du planificateur

Ajoutez à la crontab de `www-data` :

```bash
* * * * * cd /var/www/relaticle && php artisan schedule:run >> /dev/null 2>&1
```

---

## Dépannage

### Erreur « APP_KEY is required » au démarrage

La variable d'environnement `APP_KEY` n'est pas définie. Générez-en une :

```bash
echo "base64:$(openssl rand -base64 32)"
```

Ajoutez-la à votre fichier `.env` et redémarrez :

```bash
docker compose up -d
```

### Conteneurs qui redémarrent en boucle

Consultez les journaux :

```bash
docker compose logs app --tail 50
```

Causes fréquentes :
- Variables d'environnement obligatoires manquantes (`APP_KEY`, `DB_PASSWORD`)
- PostgreSQL pas encore prêt (l'application attend une vérification d'état, mais des configurations personnalisées peuvent l'omettre)
- Mémoire insuffisante (au moins 2 Go sont nécessaires)

### Erreurs 500 après le déploiement

1. Activez temporairement `APP_DEBUG=true` pour voir l'erreur complète
2. Consultez les journaux : `docker compose logs app --tail 100`
3. Vérifiez que `APP_URL` correspond à votre domaine réel (schéma compris)
4. Exécutez `docker compose exec app php artisan optimize:clear` pour vider tous les caches

### Les e-mails ne partent pas

1. Vérifiez que `MAIL_MAILER` est défini sur `smtp` (et non `log`)
2. Vérifiez que vos identifiants SMTP sont corrects
3. Testez avec : `docker compose exec app php artisan tinker --execute "Mail::raw('Test', fn(\$m) => \$m->to('you@example.com')->subject('Test'))"`
4. Consultez les journaux : `docker compose logs app | grep -i mail`

### Le chat reste sur « Thinking... »

Depuis octobre 2026, une réponse apparaît quelques secondes après son enregistrement, même sans websocket. Si le chat attend toujours, la réponse n'est pas produite :

1. Vérifiez que la tâche s'est exécutée : `docker compose logs horizon --tail 50` affiche `ProcessChatMessage` avec le statut `DONE`.
2. Vérifiez que le modèle répond : `docker compose exec horizon php artisan chat:models --probe=<id>`.
3. Vérifiez que tous les conteneurs partagent le cache : `docker compose exec horizon php artisan config:show cache.default` affiche `redis`.

Pour voir si les réponses sont diffusées en direct, ouvrez l'onglet Réseau du navigateur, filtrez sur WS et cherchez une requête `/reverb/app/...` avec le statut 101. Sans cette requête, le conteneur `reverb` est absent ou votre reverse proxy supprime l'en-tête `Upgrade`.

### La page d'accueil affiche le site marketing de Relaticle

Le package cloud est chargé, car `RELATICLE_CLOUD` est défini dans `.env` ou dans l'environnement du serveur. Une installation auto-hébergée redirige `/` vers l'application. Supprimez la variable, exécutez `php artisan config:clear` (puis `php artisan optimize` de nouveau si vous mettez la configuration en cache), et recompilez le frontend avec `pnpm run build`.

### Horizon ne traite pas les tâches

1. Vérifiez l'état d'Horizon : `docker compose exec app php artisan horizon:status`
2. Vérifiez que le conteneur horizon tourne : `docker compose ps`
3. Consultez les journaux d'horizon : `docker compose logs horizon --tail 50`
4. Redémarrez Horizon : `docker compose restart horizon`

### Erreurs de permissions sur le stockage

```bash
docker compose exec app chown -R www-data:www-data /var/www/html/storage
docker compose exec app chmod -R 775 /var/www/html/storage
```

### Connexion à la base de données refusée

1. Vérifiez que PostgreSQL tourne : `docker compose ps postgres`
2. Consultez les journaux de PostgreSQL : `docker compose logs postgres --tail 20`
3. Vérifiez que `DB_PASSWORD` est identique dans les conteneurs de l'application et de postgres (les deux lisent par défaut la même variable de `.env`)
