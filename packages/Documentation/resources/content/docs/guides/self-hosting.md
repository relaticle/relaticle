---
title: Self-Hosting Guide
description: Get Docker Compose, Coolify, Dokploy and Laravel Cloud deployment steps for Relaticle, with PostgreSQL, Redis and Ollama setup.
order: 1
updated: "2026-10-13"
---

Deploy Relaticle on your own infrastructure with Docker or manually.

---

## Quick Start

Get Relaticle running in 5 steps:

1. Download the compose file:

```bash
curl -o compose.yml https://raw.githubusercontent.com/Relaticle/relaticle/main/compose.yml
```

2. Generate an application key:

```bash
echo "APP_KEY=base64:$(openssl rand -base64 32)"
```

3. Create a `.env` file with your settings:

```bash
APP_KEY=base64:your-generated-key-here
DB_PASSWORD=your-secure-database-password
APP_URL=https://crm.example.com
```

4. Start the containers:

```bash
docker compose up -d
```

5. Create your admin account:

```bash
docker compose exec app php artisan make:filament-user
```

Your CRM is now available at `{APP_URL}/app`.

---

## Requirements

| Resource | Minimum | Recommended |
|----------|---------|-------------|
| RAM | 2 GB | 4 GB |
| CPU | 1 core | 2 cores |
| Disk | 10 GB | 20 GB+ |
| Docker | 20.10+ | Latest |
| Docker Compose | v2.0+ | Latest |

---

## Environment Variables

### Required

These must be set or the containers will refuse to start.

| Variable | Description |
|----------|-------------|
| `APP_KEY` | Encryption key. Generate with `openssl rand -base64 32`, then prefix with `base64:`. |
| `DB_PASSWORD` | PostgreSQL password. Use a strong random value. |

### Application

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_NAME` | `Relaticle` | Displayed in the browser tab and emails. |
| `APP_ENV` | `production` | Set to `production` for self-hosting. |
| `APP_DEBUG` | `false` | Set to `true` only for debugging. Never in production. |
| `APP_TIMEZONE` | `UTC` | Application timezone. |
| `APP_URL` | `http://localhost` | Full URL where Relaticle is accessible. Include the scheme. |
| `TRUSTED_HOSTS` | `localhost` | Extra hostnames the app accepts, comma separated. The `APP_URL` host and any `*_DOMAIN` hosts are always accepted. Keep `localhost` in the list, because the container health check calls it. |
| `TRUSTED_PROXIES` | (empty) | Proxy addresses whose `X-Forwarded-For`, `-Proto` and `-Port` headers the app believes, comma separated, as IPs or CIDR ranges. Empty trusts every private range (10/8, 172.16/12, 192.168/16, loopback), which Traefik, Coolify and Dokploy need. Narrow it to your proxy, for example its Docker network gateway or subnet, so no other container or host peer can forge a client IP. `*` trusts every sender and is unsafe. Never publish the app port directly to an untrusted network. |
| `SESSION_SECURE_COOKIE` | (empty) | Sends the session cookie over HTTPS only. Empty means secure when `APP_URL` starts with `https://`, and not secure otherwise. Set `true` or `false` to override. Serve production installs over HTTPS. A Secure cookie over plain HTTP is dropped by the browser, so a plain-HTTP install must leave this empty or `false`, or nobody can sign in. An `https://` install that you also open over plain HTTP, such as by LAN IP, needs `false` for that access to work. |
| `HSTS_MAX_AGE` | `15552000` | Seconds browsers keep forcing HTTPS for your host. The `Strict-Transport-Security` header is sent only when `APP_ENV=production` and the request is HTTPS. Set `0` to make browsers forget it. |
| `HSTS_INCLUDE_SUBDOMAINS` | `false` | Set `true` to extend HSTS to every subdomain of the host. Leave it off if any sibling subdomain, such as an intranet host, is served over plain HTTP. |
| `CSP_MODE` | `enforce` | Content-Security-Policy for HTML pages. `report-only` sends the full policy as a report-only header, and `off` drops it. `frame-ancestors`, `object-src` and `base-uri` stay enforced in every mode. |
| `CSP_SCRIPT_SRC`, `CSP_STYLE_SRC`, `CSP_IMG_SRC`, `CSP_FONT_SRC`, `CSP_CONNECT_SRC`, `CSP_FRAME_SRC` | (empty) | Extra origins for that directive, comma separated. Set `CSP_IMG_SRC` when media or profile photos load from a bucket URL on another host. The websocket origin is added for you. |
| `APP_PORT` | `80` | Host port the app container binds to. |
| `APP_PANEL_DOMAIN` | (empty) | Set for subdomain routing (e.g., `app.example.com`). Leave empty for path mode (`/app`). |
| `PASSKEYS_USER_HANDLE_SECRET` | `APP_KEY` | Derives the opaque WebAuthn user handle stored on each authenticator. Set it to its own random value (`openssl rand -base64 32`) before anyone registers a passkey, so rotating `APP_KEY` later does not change every user's handle. |
| `REQUIRE_EMAIL_VERIFICATION` | `true` | When `false`, users sign in without verifying their email. That is useful for self-hosters who haven't configured SMTP yet. The admin you create via `make:filament-user` is auto-verified regardless, so the default of `true` is safe for fresh Docker installs. Only set to `false` if your panel is on a private network: with verification disabled, anyone who can reach the sign-in page can create a working account. |
| `LOG_CHANNEL` | `stderr` | Where logs go. `stderr` is recommended for Docker. |
| `LOG_LEVEL` | `warning` | Minimum log level. Use `debug` for troubleshooting. |

### Mail

| Variable | Default | Description |
|----------|---------|-------------|
| `MAIL_MAILER` | `log` | Mail driver: `smtp`, `ses`, `mailgun`, `postmark`, or `log`. |
| `MAIL_HOST` | (empty) | SMTP host (e.g., `smtp.mailgun.org`). |
| `MAIL_PORT` | `587` | SMTP port. |
| `MAIL_USERNAME` | (empty) | SMTP username. |
| `MAIL_PASSWORD` | (empty) | SMTP password. |
| `MAIL_ENCRYPTION` | `tls` | `tls` or `ssl`. |
| `MAIL_FROM_ADDRESS` | `hello@example.com` | Sender email address. |
| `MAIL_FROM_NAME` | `Relaticle` | Sender display name. |

### Database and Redis

These are pre-configured in `compose.yml` and generally don't need changing.

| Variable | Default | Description |
|----------|---------|-------------|
| `DB_DATABASE` | `relaticle` | PostgreSQL database name. |
| `DB_USERNAME` | `relaticle` | PostgreSQL username. |
| `REDIS_PASSWORD` | `null` | Redis password. Leave as `null` for no auth. |

### Live updates

Rela, the built-in AI assistant, streams its replies into the chat over a websocket. The `reverb` container serves it, and the app container proxies it under `/reverb` on the address you already use. No extra port, domain or certificate is needed.

| Variable | Default | Description |
|----------|---------|-------------|
| `REVERB_APP_SECRET` | `APP_KEY` | Signs messages between the app and the `reverb` container. Set it to rotate it apart from `APP_KEY`. |
| `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` | (empty) | Set these only when the websocket has its own public address. Left empty, the browser connects to the `APP_URL` origin under `/reverb`. |

When the websocket is unreachable, the chat still works. Each reply appears a few seconds after it is saved, without typing live. The suggested next steps under a reply show after a page reload.

### Optional

| Variable | Description |
|----------|-------------|
| `GOOGLE_CLIENT_ID` | Google OAuth client ID for social login. The Google button appears only when this is set. |
| `GOOGLE_CLIENT_SECRET` | Google OAuth client secret. |
| `GOOGLE_REDIRECT_URI` | Absolute callback URL (`https://your-host/auth/callback/google`), or a relative path (`/auth/callback/google`) to derive the host from the request. |
| `MICROSOFT_CLIENT_ID` | Microsoft Entra ID client id. The Microsoft button appears only when this is set; register `https://your-host/auth/callback/microsoft` as the redirect URI. |
| `MICROSOFT_CLIENT_SECRET` | Microsoft Entra ID client secret. |
| `SENTRY_LARAVEL_DSN` | Sentry DSN for error tracking. |
| `FATHOM_ANALYTICS_SITE_ID` | Fathom Analytics site ID. |

### Email and calendar

Email and calendar sync is off by default. Turn it on with `RELATICLE_FEATURE_EMAIL_INTEGRATION=true`. Members can then connect Google accounts, and Microsoft accounts once you configure a Microsoft client.

Google mailboxes need their own Google OAuth client, separate from the one used for social login, with the Gmail API and the Google Calendar API enabled.

| Variable | Default | Description |
|----------|---------|-------------|
| `GMAIL_CLIENT_ID` | | OAuth client ID of the Google client used to connect mailboxes. |
| `GMAIL_CLIENT_SECRET` | | OAuth client secret of that client. |
| `GMAIL_REDIRECT_URI` | | Callback URL. Register `https://your-host/email-accounts/callback/gmail` as the redirect URI in Google Cloud. |

The client requests three scopes: `gmail.readonly`, `gmail.send`, and `calendar.events`. Add them to the OAuth consent screen. While the consent screen is in testing mode, only the test users you list can connect.

Outlook and Microsoft 365 mailboxes need an app registration in Microsoft Entra ID. The **Connect Microsoft account** button appears only when `AZURE_CLIENT_ID` is set.

| Variable | Default | Description |
|----------|---------|-------------|
| `AZURE_CLIENT_ID` | | Application (client) ID of the Entra app registration used to connect mailboxes. |
| `AZURE_CLIENT_SECRET` | | Client secret of that app registration. |
| `AZURE_REDIRECT_URI` | | Callback URL. Register `https://your-host/email-accounts/callback/azure` as a Web redirect URI in Entra. |
| `AZURE_TENANT_ID` | `common` | Directory the sign-in goes through. Set your tenant ID to accept accounts from one organization only. |

The app requests five delegated Microsoft Graph permissions: `Mail.Read`, `Mail.Send`, `Calendars.ReadWrite`, `User.Read`, and `offline_access`. Add them under API permissions. The supported account types you choose on the app registration decide which accounts can connect.

One setting applies to both providers.

| Variable | Default | Description |
|----------|---------|-------------|
| `EMAIL_SYNC_INITIAL_DAYS` | | Limits the first import to the last N days. Leave unset to import the whole mailbox. |

### AI Assistant

Rela works with cloud providers, a self-hosted server, or both. Models appear in the chat model picker only when their provider is configured. With none configured, the assistant cannot answer.

There are two ways to run a self-hosted model, and you can use either or both:

- **[Ollama](https://ollama.com)** (`OLLAMA_MODEL`): the simplest path, with the best tool-calling support.
- **Any OpenAI-compatible endpoint** (`SELF_HOSTED_AI_*`): point at [vLLM](https://docs.vllm.ai) (best for many concurrent users), LM Studio, LocalAI, or a hosted gateway. Exposes multiple models at once.

| Variable | Default | Description |
|----------|---------|-------------|
| `ANTHROPIC_API_KEY` | (empty) | Enables the Claude models in the chat picker. |
| `OPENAI_API_KEY` | (empty) | Enables the GPT models in the chat picker. |
| `OLLAMA_BASE_URL` | `http://localhost:11434` | URL of your Ollama server. From inside a Docker container, `localhost` is the container itself. Use `http://host.docker.internal:11434` (or your host's LAN IP) to reach Ollama running on the host. |
| `OLLAMA_MODEL` | (empty) | Ollama model tag (e.g. `qwen3:14b`). Setting this adds the model to the chat picker. |
| `SELF_HOSTED_AI_URL` | (empty) | Base URL of an OpenAI-compatible endpoint (e.g. `http://localhost:8000/v1`). |
| `SELF_HOSTED_AI_KEY` | (empty) | API key for that endpoint, if it requires one (blank for most local servers). |
| `SELF_HOSTED_AI_MODELS` | (empty) | Comma-separated model tags to expose (e.g. `qwen3:14b,llama3.1:70b`). Each becomes a picker entry. |

**Choosing a model.** The assistant calls CRM tools (search, create, update, delete). Only models with strong tool-calling support work reliably. `qwen3` and `llama3.1:70b`-class models are good starting points. Small models tend to claim an action succeeded without actually invoking the tool. Verify a model before trusting it with `php artisan chat:models --probe=<id>`, which lists the registry and smoke-tests tool-calling against its endpoint (self-hosted models only; cloud models run through the vendor SDK and are declined).

**Known limitations with self-hosted models:**

- Cloud providers enforce one-write-proposal-at-a-time at the API level; self-hosted models have no equivalent switch, so this is enforced by prompt instructions only. Every write still requires your explicit approval before anything is saved.
- Each response must complete within 120 seconds. If a slow model exceeds it, the turn stops cleanly and nothing is silently lost. Your message stays in the conversation with a note that the reply hit the 120-second limit. Ask for a shorter answer, or for it in parts. To avoid the limit, use a smaller/faster model or a GPU; "thinking" models like `qwen3` reason before answering and reach it sooner, so a non-thinking model or a shorter prompt helps for interactive use.

### Feature Flags

Toggle features on or off. All are enabled by default unless noted. Useful for forks and custom deployments that want to disable specific functionality without modifying code.

| Variable | Default | Description |
|----------|---------|-------------|
| `RELATICLE_FEATURE_ONBOARD_SEED` | `true` | Seed demo data (sample companies, people, tasks) when a new workspace is created. Set to `false` to start with an empty workspace. |
| `RELATICLE_FEATURE_SOCIAL_AUTH` | `true` | Enable Google and Microsoft social login. Set to `false` to use only email, password, and passkey sign-in. |
| `RELATICLE_FEATURE_DOCUMENTATION` | `true` | Enable the `/developers` and `/help` documentation module. Set to `false` to remove documentation routes and navigation links. |
| `RELATICLE_FEATURE_EMAIL_INTEGRATION` | `false` | Enable email and calendar sync for Google and Microsoft accounts, the email composer, and the Email & calendar help pages. Requires `GMAIL_CLIENT_ID` and `GMAIL_CLIENT_SECRET`. Microsoft accounts also need `AZURE_CLIENT_ID` and `AZURE_CLIENT_SECRET`. |
| `RELATICLE_FEATURE_SIGNUP_CHALLENGE` | `false` | Ask new sign-ups to pass a Cloudflare Turnstile check. Stays hidden for normal browsers and shows a checkbox only when Cloudflare needs one. Requires `TURNSTILE_SITE_KEY` and `TURNSTILE_SECRET_KEY`. |

---

## Architecture

The Docker setup runs 6 containers:

| Container | Image | Purpose |
|-----------|-------|---------|
| **app** | `ghcr.io/relaticle/relaticle:latest` | Web server (nginx + PHP-FPM) on port 8080. Runs migrations automatically on startup. |
| **horizon** | `ghcr.io/relaticle/relaticle:latest` | Queue worker powered by Laravel Horizon. Processes background jobs. |
| **scheduler** | `ghcr.io/relaticle/relaticle:latest` | Runs `schedule:work` for recurring tasks (e.g., cleanup, notifications). |
| **reverb** | `ghcr.io/relaticle/relaticle:latest` | Websocket server (Laravel Reverb). Streams Rela's replies to the browser. |
| **postgres** | `postgres:17-alpine` | PostgreSQL 17 database. |
| **redis** | `redis:7-alpine` | Cache, sessions, and queue backend. Runs with append-only persistence. |

### Volumes

| Volume | Purpose |
|--------|---------|
| `postgres` | Database files. Back this up. |
| `redis` | Redis persistence data. |
| `storage` | Uploaded files and application storage. Back this up. |

### Networking

The app container listens on port 8080 internally and maps to `APP_PORT` (default 80) on the host. All containers communicate through Docker's internal network. Only the app container exposes a port to the host. The browser reaches the websocket through it, at `/reverb`.

---

## Creating Your Admin Account

After starting the containers, create your first admin user:

```bash
docker compose exec app php artisan make:filament-user
```

When prompted to pick a panel, choose `app`, then enter a name, email, and password. Once created, access the CRM panel at `{APP_URL}/app`. (To create the instance-wide system administrator instead, see the next section.)

**Note**: By default the CRM panel is available at the `/app` path. To use subdomain routing instead (e.g., `app.example.com`), set the `APP_PANEL_DOMAIN` environment variable.

### System Administrator Account

The `sysadmin` panel at `{APP_URL}/sysadmin` is a separate, instance-wide admin surface for managing every workspace and user on your installation. To create a system administrator, you can either pick `sysadmin` from the `make:filament-user` panel prompt, or use the dedicated command:

```bash
docker compose exec app php artisan sysadmin:create
```

---

## Reverse Proxy and SSL

The app container serves HTTP on port 8080 internally. Place a reverse proxy in front to handle SSL termination and route traffic to the container.

SSL is not optional if you want passkeys: browsers run WebAuthn over HTTPS only, and a passkey is bound to the host it was registered on. That host is `APP_PANEL_DOMAIN` when subdomain routing is on, and the `APP_URL` host otherwise, so serve the panel from one hostname and keep it stable.

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

Caddy handles SSL certificates automatically via Let's Encrypt, and it forwards websockets without extra configuration. So does Traefik.

### Traefik

Add these labels to the `app` service in your `compose.yml`:

```yaml
labels:
  - "traefik.enable=true"
  - "traefik.http.routers.relaticle.rule=Host(`crm.example.com`)"
  - "traefik.http.routers.relaticle.entrypoints=websecure"
  - "traefik.http.routers.relaticle.tls.certresolver=letsencrypt"
  - "traefik.http.services.relaticle.loadbalancer.server.port=8080"
```

The `Upgrade` and `Connection` headers in the Nginx block carry the chat websocket. A proxy that drops them still serves the app, and replies then appear a few seconds late.

**Note**: When using a reverse proxy, set `APP_URL` to your public HTTPS URL (e.g., `https://crm.example.com`). The app trusts `X-Forwarded-*` headers from RFC1918 private networks, loopback, and IPv6 ULA/link-local, which covers Coolify/Dokploy/Traefik on a Docker network and reverse proxies on the host. Headers from public IPs are rejected, preventing spoofing.

The app only accepts requests whose `Host` header matches `APP_URL`, `www.` plus that host, a configured `*_DOMAIN`, or an entry in `TRUSTED_HOSTS`. Any other host gets a 400. Your proxy must forward the real `Host` header (Nginx `proxy_set_header Host $host;`) and must not pass a client-supplied `X-Forwarded-Host` through. The app ignores `X-Forwarded-Host` and `X-Forwarded-Prefix`. Probes that call the container by IP or an internal name need that name in `TRUSTED_HOSTS`, or they should send the configured domain as the `Host` header.

---

## Deploying on Dokploy

[Dokploy](https://dokploy.com/) is an open-source deployment platform. Here's how to deploy Relaticle on it.

### 1. Create a Project

In the Dokploy dashboard, click **Create Project** and give it a name (e.g., "Relaticle").

### 2. Add a Compose Service

1. Inside your project, click **Create Service** and select **Compose**
2. Set the source to **Raw** and paste the contents of the [compose.yml](https://raw.githubusercontent.com/Relaticle/relaticle/main/compose.yml)
3. Click **Create**

### 3. Set Environment Variables

In the service's **Environment** tab, add the required variables:

```bash
APP_KEY=base64:your-generated-key-here
DB_PASSWORD=your-secure-database-password
APP_URL=https://crm.yourdomain.com
APP_PORT=8080
```

Generate your `APP_KEY` with:

```bash
echo "base64:$(openssl rand -base64 32)"
```

**Note**: Set `APP_PORT=8080` so the container maps port 8080:8080, avoiding conflicts with Dokploy's own port 80.

### 4. Deploy

Click **Deploy**. Dokploy will pull the images and start all 6 containers. Wait for health checks to pass.

### 5. Configure Domain

1. Go to the **Domains** tab of the `app` service
2. Add your domain (e.g., `crm.yourdomain.com`)
3. Set the container port to `8080`
4. Enable HTTPS (Dokploy handles Let's Encrypt automatically)

### 6. Create Admin User

Open the Dokploy terminal for the `app` container and run:

```bash
php artisan make:filament-user
```

Your Relaticle instance is now live at `https://crm.yourdomain.com/app`.

---

## Deploying on Coolify

[Coolify](https://coolify.io/) is an open-source, self-hostable platform for deploying applications.

### 1. Create a New Project

In the Coolify dashboard, click **New Project** and give it a name.

### 2. Add a Docker Compose Resource

1. Click **Add Resource** in your project
2. Select **Docker Compose**
3. Choose **Empty** as the source, then paste the contents of the [compose.yml](https://raw.githubusercontent.com/Relaticle/relaticle/main/compose.yml)

### 3. Configure Environment Variables

In the resource's **Environment Variables** section, add:

```bash
APP_KEY=base64:your-generated-key-here
DB_PASSWORD=your-secure-database-password
APP_URL=https://crm.yourdomain.com
```

### 4. Set Up Domain

1. Go to the app service's **Settings**
2. Set your domain (e.g., `crm.yourdomain.com`)
3. Coolify will automatically provision an SSL certificate

### 5. Deploy

Click **Deploy**. Coolify will pull the images, create containers, and start the services.

### 6. Create Admin User

Use Coolify's **Terminal** feature to run in the `app` container:

```bash
php artisan make:filament-user
```

Access your CRM at `https://crm.yourdomain.com/app`.

---

## Deploying on Laravel Cloud

Laravel Cloud runs Relaticle without Docker. Web replicas and queue workers each get their own short-lived disk, so every file Relaticle keeps goes to object storage.

### 1. Create the Application

Create an application from your fork of the Relaticle repository. Attach these resources to its environment:

| Resource | Setting |
|----------|---------|
| Database | Laravel Serverless Postgres |
| Cache | Laravel Valkey, with the eviction policy set to **No eviction** |
| WebSockets | Laravel Reverb |
| Bucket | A **public** bucket with the disk name `public` |
| Bucket | A **private** bucket with the disk name `s3` |

Cloud points the disks named `public` and `s3` at the buckets, so the names must match exactly. Leave "default disk" unchecked on both buckets: the default disk stays `local`, which holds build artifacts only.

Set each bucket's allowed origins to your application's URL, such as `https://your-app.laravel.cloud`. Browsers upload files straight to the private bucket, and they need that origin to pass the bucket's CORS check.

Queued jobs and Horizon live in Valkey next to the cache. An evicting policy can silently drop a queued job when memory fills, so keep No eviction.

### 2. Set Environment Variables

Cloud injects the database, cache, Reverb, and bucket credentials. Add these:

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

Generate the Passport keys once with `php artisan passport:keys --force` on your machine and paste the contents of `storage/oauth-private.key` and `storage/oauth-public.key`.

`FILAMENT_FILESYSTEM_DISK` must be `s3`. Left unset, CRM exports are written to one replica's disk and downloads from another replica fail. Never point it at the public bucket: exports hold customer data.

### 3. Build and Deploy Commands

Cloud does not install pnpm, so the build installs the version `package.json` pins. Caching belongs in the build: a deploy command's file changes are not kept.

Build command:

```bash
composer install --no-dev --optimize-autoloader
npm install -g "$(node -p 'require("./package.json").packageManager')"
pnpm install --frozen-lockfile --ignore-scripts
pnpm run build
php artisan scribe:generate
php artisan optimize
php artisan filament:optimize
```

Deploy command:

```bash
php artisan migrate --force
```

Cloud restarts Horizon on every deployment, so no `horizon:terminate` step is needed.

### 4. Queue Workers and Scheduler

Turn on the Scheduler toggle on the App cluster. Worker clusters need the Growth plan or higher. Add a worker cluster, keep it awake instead of letting it sleep with the App cluster, and give it one custom background process:

```bash
php artisan horizon
```

Every worker replica runs the full Horizon supervisor set. Tune the process counts with `HORIZON_DEFAULT_MIN`, `HORIZON_DEFAULT_MAX`, `HORIZON_IMPORTS_MIN`, and `HORIZON_IMPORTS_MAX`.

### 5. Create Admin User

Open the environment's Commands tab and run the command with every value passed as an option. The Commands tab cannot answer interactive prompts.

```bash
php artisan make:filament-user --name="Your Name" --email=you@example.com --password=your-secure-password
```

Access your CRM at `https://your-app.laravel.cloud/app`.

---

## Upgrading

### 1. Back Up Your Data

Always back up before upgrading. See the Backup and Restore section below.

### 2. Pull the Latest Images

```bash
docker compose pull
```

### 3. Restart the Containers

```bash
docker compose up -d
```

Database migrations run automatically on startup. The app container will apply any pending migrations before serving traffic.

### 4. Refresh the Compose File

A `compose.yml` downloaded before October 2026 has no `reverb` container. The chat works without it, and replies appear a few seconds after they are saved. To get live streaming, download the file again and start the stack:

```bash
curl -o compose.yml https://raw.githubusercontent.com/Relaticle/relaticle/main/compose.yml
docker compose up -d
```

Copy any edits you made to the old file first. The new file passes the AI provider variables from `.env` to the containers, so those lines no longer need adding by hand.

The image now caches in Redis by default, so the web, Horizon and scheduler containers share one cache. The old file gave only the web container Redis, and the chat's Stop button never reached the worker. A stack that sets `CACHE_STORE` itself keeps its own value.

**Note**: Check the [release notes](https://github.com/Relaticle/relaticle/releases) before upgrading. Breaking changes will be documented there.

---

## Backup and Restore

### Database Backup

```bash
docker compose exec postgres pg_dump -U relaticle relaticle > backup-$(date +%Y%m%d).sql
```

### Database Restore

```bash
docker compose exec -T postgres psql -U relaticle relaticle < backup-20260320.sql
```

### Storage Backup

Back up the storage volume which contains uploaded files:

```bash
docker compose cp app:/var/www/html/storage/app ./storage-backup
```

### Automated Backups

Add a cron job to back up daily:

```bash
0 3 * * * cd /path/to/relaticle && docker compose exec -T postgres pg_dump -U relaticle relaticle | gzip > /backups/relaticle-$(date +\%Y\%m\%d).sql.gz
```

---

## Manual Deployment

If you prefer not to use Docker, you can deploy Relaticle directly on a server.

### Requirements

- PHP 8.5+ with extensions: pdo_pgsql, gd, bcmath, mbstring, xml, redis
- PostgreSQL 17+
- Redis 7+
- Node.js 22+
- Composer 2+
- Nginx or Apache
- Supervisor (for queue workers)

### Installation

1. Clone the repository:

```bash
git clone https://github.com/Relaticle/relaticle.git /var/www/relaticle
cd /var/www/relaticle
```

2. Install dependencies:

```bash
composer install --no-dev --optimize-autoloader
pnpm install --frozen-lockfile && pnpm run build
```

3. Configure the environment:

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` with your database credentials, mail settings, and `APP_URL`. Set `APP_ENV=production` and `APP_DEBUG=false`. The example file ships `local` and `true`, and debug pages in production expose your configuration.

4. Set up the database:

```bash
php artisan migrate --force
php artisan storage:link
```

5. Set permissions:

```bash
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

6. Create your admin user:

```bash
php artisan make:filament-user
```

### Nginx Configuration

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

The `/reverb/app/` block carries the chat websocket to Reverb. In `.env`, set `BROADCAST_CONNECTION=reverb`, `BROADCAST_REVERB_HOST=127.0.0.1` and `BROADCAST_REVERB_PORT=8080`, and leave `REVERB_HOST` empty.

### Supervisor for Horizon

Create `/etc/supervisor/conf.d/relaticle-horizon.conf`:

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

Then start it:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start relaticle-horizon
```

### Supervisor for Reverb

Create `/etc/supervisor/conf.d/relaticle-reverb.conf`:

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

Start it the same way, then run `php artisan reverb:restart` after each upgrade.

### Scheduler Cron

Add to `www-data`'s crontab:

```bash
* * * * * cd /var/www/relaticle && php artisan schedule:run >> /dev/null 2>&1
```

---

## Troubleshooting

### "APP_KEY is required" error on startup

The `APP_KEY` environment variable is not set. Generate one:

```bash
echo "base64:$(openssl rand -base64 32)"
```

Add it to your `.env` file and restart:

```bash
docker compose up -d
```

### Container restart loops

Check the logs:

```bash
docker compose logs app --tail 50
```

Common causes:
- Missing required environment variables (`APP_KEY`, `DB_PASSWORD`)
- PostgreSQL not ready yet (the app waits for a health check, but custom configs may skip this)
- Insufficient memory (need at least 2 GB)

### 500 errors after deployment

1. Check `APP_DEBUG=true` temporarily to see the full error
2. View logs: `docker compose logs app --tail 100`
3. Ensure `APP_URL` matches your actual domain (including scheme)
4. Run `docker compose exec app php artisan optimize:clear` to clear all caches

### Email not sending

1. Verify `MAIL_MAILER` is set to `smtp` (not `log`)
2. Check your SMTP credentials are correct
3. Test with: `docker compose exec app php artisan tinker --execute "Mail::raw('Test', fn(\$m) => \$m->to('you@example.com')->subject('Test'))"`
4. Check logs: `docker compose logs app | grep -i mail`

### Chat stays on "Thinking..."

Since October 2026 a reply appears a few seconds after it is saved even with no websocket. If the chat still waits, the reply is not being produced:

1. Check the job ran: `docker compose logs horizon --tail 50` shows `ProcessChatMessage` as `DONE`.
2. Check the model answers: `docker compose exec horizon php artisan chat:models --probe=<id>`.
3. Check every container shares the cache: `docker compose exec horizon php artisan config:show cache.default` prints `redis`.

To see whether replies stream live, open the browser's Network tab, filter by WS, and look for a `/reverb/app/...` request with status 101. With no such request, the `reverb` container is missing or your reverse proxy drops the `Upgrade` header.

### Horizon not processing jobs

1. Check Horizon status: `docker compose exec app php artisan horizon:status`
2. Verify the horizon container is running: `docker compose ps`
3. Check horizon logs: `docker compose logs horizon --tail 50`
4. Restart Horizon: `docker compose restart horizon`

### Permission errors on storage

```bash
docker compose exec app chown -R www-data:www-data /var/www/html/storage
docker compose exec app chmod -R 775 /var/www/html/storage
```

### Database connection refused

1. Verify PostgreSQL is running: `docker compose ps postgres`
2. Check PostgreSQL logs: `docker compose logs postgres --tail 20`
3. Ensure `DB_PASSWORD` matches between the app and postgres containers (both read from the same `.env` variable by default)
