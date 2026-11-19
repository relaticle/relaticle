---
title: Self-Hosting-Leitfaden
description: Anleitung zur Bereitstellung von Relaticle mit Docker Compose, Coolify, Dokploy und Laravel Cloud, samt Einrichtung von PostgreSQL, Redis und Ollama.
order: 1
updated: "2026-10-31"
---

Stellen Sie Relaticle auf Ihrer eigenen Infrastruktur bereit, mit Docker oder manuell.

---

## Schnellstart

Relaticle läuft in 5 Schritten:

1. Laden Sie die Compose-Datei herunter:

```bash
curl -o compose.yml https://raw.githubusercontent.com/Relaticle/relaticle/main/compose.yml
```

2. Erzeugen Sie einen Anwendungsschlüssel:

```bash
echo "APP_KEY=base64:$(openssl rand -base64 32)"
```

3. Legen Sie eine `.env`-Datei mit Ihren Einstellungen an:

```bash
APP_KEY=base64:your-generated-key-here
DB_PASSWORD=your-secure-database-password
APP_URL=https://crm.example.com
```

4. Starten Sie die Container:

```bash
docker compose up -d
```

5. Erstellen Sie Ihr Administratorkonto:

```bash
docker compose exec app php artisan make:filament-user
```

Ihr CRM ist jetzt unter `{APP_URL}/app` erreichbar.

---

## Voraussetzungen

| Ressource | Minimum | Empfohlen |
|----------|---------|-------------|
| RAM | 2 GB | 4 GB |
| CPU | 1 Kern | 2 Kerne |
| Festplatte | 10 GB | 20 GB+ |
| Docker | 20.10+ | Neueste Version |
| Docker Compose | v2.0+ | Neueste Version |

---

## Umgebungsvariablen

### Erforderlich

Diese Variablen müssen gesetzt sein, sonst starten die Container nicht.

| Variable | Beschreibung |
|----------|-------------|
| `APP_KEY` | Verschlüsselungsschlüssel. Erzeugen Sie ihn mit `openssl rand -base64 32` und stellen Sie `base64:` voran. |
| `DB_PASSWORD` | PostgreSQL-Passwort. Verwenden Sie einen starken Zufallswert. |

### Anwendung

| Variable | Standard | Beschreibung |
|----------|---------|-------------|
| `APP_NAME` | `Relaticle` | Wird im Browser-Tab und in E-Mails angezeigt. |
| `APP_ENV` | `production` | Setzen Sie für Self-Hosting `production`. |
| `APP_DEBUG` | `false` | Setzen Sie `true` nur zur Fehlersuche. Niemals in der Produktion. |
| `APP_TIMEZONE` | `UTC` | Zeitzone der Anwendung. |
| `APP_URL` | `http://localhost` | Vollständige URL, unter der Relaticle erreichbar ist. Geben Sie das Schema an. |
| `TRUSTED_HOSTS` | `localhost` | Zusätzliche Hostnamen, die die App akzeptiert, durch Kommas getrennt. Der Host von `APP_URL` und alle `*_DOMAIN`-Hosts werden immer akzeptiert. Lassen Sie `localhost` in der Liste, weil der Health-Check des Containers ihn aufruft. |
| `TRUSTED_PROXIES` | (leer) | Proxy-Adressen, deren Header `X-Forwarded-For`, `-Proto` und `-Port` die App glaubt, durch Kommas getrennt, als IPs oder CIDR-Bereiche. Leer vertraut allen privaten Bereichen (10/8, 172.16/12, 192.168/16, Loopback), was Traefik, Coolify und Dokploy brauchen. Schränken Sie die Variable auf Ihren Proxy ein, zum Beispiel auf das Gateway oder Subnetz seines Docker-Netzwerks, damit kein anderer Container und kein anderer Host eine Client-IP fälschen kann. `*` vertraut jedem Absender und ist unsicher. Veröffentlichen Sie den App-Port nie direkt in einem nicht vertrauenswürdigen Netzwerk. |
| `SESSION_SECURE_COOKIE` | (leer) | Sendet das Sitzungs-Cookie nur über HTTPS. Leer bedeutet: sicher, wenn `APP_URL` mit `https://` beginnt, sonst nicht sicher. Setzen Sie `true` oder `false`, um das zu überschreiben. Betreiben Sie produktive Installationen über HTTPS. Ein Secure-Cookie über reines HTTP verwirft der Browser. Eine Installation über reines HTTP muss die Variable daher leer lassen oder auf `false` setzen, sonst kann sich niemand anmelden. Eine `https://`-Installation, die Sie auch über reines HTTP öffnen, etwa per LAN-IP, braucht für diesen Zugriff `false`. |
| `HSTS_MAX_AGE` | `15552000` | Sekunden, für die Browser HTTPS für Ihren Host erzwingen. Der Header `Strict-Transport-Security` wird nur gesendet, wenn `APP_ENV=production` gilt und die Anfrage HTTPS nutzt. Setzen Sie `0`, damit Browser ihn vergessen. |
| `HSTS_INCLUDE_SUBDOMAINS` | `false` | Setzen Sie `true`, um HSTS auf jede Subdomain des Hosts auszudehnen. Lassen Sie es aus, wenn eine benachbarte Subdomain, etwa ein Intranet-Host, über reines HTTP läuft. |
| `CSP_MODE` | `enforce` | Content-Security-Policy für HTML-Seiten. `report-only` sendet die vollständige Richtlinie als Report-only-Header, und `off` lässt sie weg. `frame-ancestors`, `object-src` und `base-uri` bleiben in jedem Modus erzwungen. |
| `CSP_SCRIPT_SRC`, `CSP_STYLE_SRC`, `CSP_IMG_SRC`, `CSP_FONT_SRC`, `CSP_CONNECT_SRC`, `CSP_FRAME_SRC` | (leer) | Zusätzliche Origins für die jeweilige Direktive, durch Kommas getrennt. Setzen Sie `CSP_IMG_SRC`, wenn Medien oder Profilfotos über eine Bucket-URL auf einem anderen Host geladen werden. Der Websocket-Origin wird für Sie ergänzt. |
| `APP_PORT` | `80` | Host-Port, an den der App-Container gebunden wird. |
| `APP_PANEL_DOMAIN` | (leer) | Für das Routing über Subdomains setzen (z. B. `app.example.com`). Für den Pfadmodus (`/app`) leer lassen. |
| `PASSKEYS_USER_HANDLE_SECRET` | `APP_KEY` | Leitet das undurchsichtige WebAuthn-User-Handle ab, das auf jedem Authenticator gespeichert ist. Setzen Sie einen eigenen Zufallswert (`openssl rand -base64 32`), bevor jemand einen Passkey registriert. So ändert eine spätere Rotation von `APP_KEY` nicht das Handle aller Nutzer. |
| `REQUIRE_EMAIL_VERIFICATION` | `true` | Bei `false` melden sich Nutzer an, ohne ihre E-Mail-Adresse zu bestätigen. Das hilft Betreibern, die SMTP noch nicht eingerichtet haben. Der Administrator, den Sie mit `make:filament-user` erstellen, ist unabhängig davon automatisch bestätigt. Der Standardwert `true` ist deshalb für frische Docker-Installationen sicher. Setzen Sie `false` nur, wenn Ihr Panel in einem privaten Netzwerk liegt: Bei deaktivierter Bestätigung kann jeder, der die Anmeldeseite erreicht, ein funktionierendes Konto erstellen. |
| `LOG_CHANNEL` | `stderr` | Wohin Logs geschrieben werden. `stderr` wird für Docker empfohlen. |
| `LOG_LEVEL` | `warning` | Minimaler Log-Level. Nutzen Sie `debug` zur Fehlersuche. |

### E-Mail

| Variable | Standard | Beschreibung |
|----------|---------|-------------|
| `MAIL_MAILER` | `log` | Mail-Treiber: `smtp`, `ses`, `mailgun`, `postmark` oder `log`. |
| `MAIL_HOST` | (leer) | SMTP-Host (z. B. `smtp.mailgun.org`). |
| `MAIL_PORT` | `587` | SMTP-Port. |
| `MAIL_USERNAME` | (leer) | SMTP-Benutzername. |
| `MAIL_PASSWORD` | (leer) | SMTP-Passwort. |
| `MAIL_ENCRYPTION` | `tls` | `tls` oder `ssl`. |
| `MAIL_FROM_ADDRESS` | `hello@example.com` | E-Mail-Adresse des Absenders. |
| `MAIL_FROM_NAME` | `Relaticle` | Angezeigter Name des Absenders. |

### Datenbank und Redis

Diese Werte sind in `compose.yml` vorkonfiguriert und müssen meist nicht geändert werden.

| Variable | Standard | Beschreibung |
|----------|---------|-------------|
| `DB_DATABASE` | `relaticle` | Name der PostgreSQL-Datenbank. |
| `DB_USERNAME` | `relaticle` | PostgreSQL-Benutzername. |
| `REDIS_PASSWORD` | `null` | Redis-Passwort. Lassen Sie `null` stehen, wenn keine Authentifizierung gilt. |

### Live-Aktualisierungen

Rela, der integrierte KI-Assistent, streamt seine Antworten über einen Websocket in den Chat. Der Container `reverb` stellt ihn bereit, und der App-Container leitet ihn unter `/reverb` über die Adresse weiter, die Sie bereits nutzen. Sie brauchen keinen zusätzlichen Port, keine zusätzliche Domain und kein zusätzliches Zertifikat.

| Variable | Standard | Beschreibung |
|----------|---------|-------------|
| `REVERB_APP_SECRET` | `APP_KEY` | Signiert Nachrichten zwischen der App und dem Container `reverb`. Setzen Sie ihn, um ihn unabhängig von `APP_KEY` zu rotieren. |
| `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` | (leer) | Setzen Sie diese Variablen nur, wenn der Websocket eine eigene öffentliche Adresse hat. Bleiben sie leer, verbindet sich der Browser mit dem Origin von `APP_URL` unter `/reverb`. |

Ist der Websocket nicht erreichbar, funktioniert der Chat trotzdem. Jede Antwort erscheint dann wenige Sekunden nach dem Speichern, ohne live zu tippen. Die vorgeschlagenen nächsten Schritte unter einer Antwort erscheinen nach einem Neuladen der Seite.

### Optional

| Variable | Beschreibung |
|----------|-------------|
| `GOOGLE_CLIENT_ID` | Google-OAuth-Client-ID für die Anmeldung über soziale Netzwerke. Die Google-Schaltfläche erscheint nur, wenn sie gesetzt ist. |
| `GOOGLE_CLIENT_SECRET` | Google-OAuth-Client-Secret. |
| `GOOGLE_REDIRECT_URI` | Absolute Callback-URL (`https://your-host/auth/callback/google`) oder ein relativer Pfad (`/auth/callback/google`), bei dem der Host aus der Anfrage abgeleitet wird. |
| `MICROSOFT_CLIENT_ID` | Client-ID von Microsoft Entra ID. Die Microsoft-Schaltfläche erscheint nur, wenn sie gesetzt ist. Registrieren Sie `https://your-host/auth/callback/microsoft` als Redirect-URI. |
| `MICROSOFT_CLIENT_SECRET` | Client-Secret von Microsoft Entra ID. |
| `SENTRY_LARAVEL_DSN` | Sentry-DSN für die Fehlerverfolgung. |
| `FATHOM_ANALYTICS_SITE_ID` | Site-ID von Fathom Analytics. |

### Rechtliche Hinweise und Hilfelinks

Eine selbst gehostete Installation liefert keine rechtlichen Seiten mit, und die Richtlinien von relaticle.com gelten nicht für Ihre Installation. Richten Sie die ersten drei Einstellungen auf Ihre eigenen Seiten. Eine nicht gesetzte Einstellung zeigt keinen Link. Nur `DOCUMENTATION_PUBLIC_URL` verweist standardmäßig auf relaticle.com.

| Variable | Standard | Beschreibung |
|----------|---------|-------------|
| `RELATICLE_PRIVACY_URL` | (leer) | Ihre Datenschutzerklärung. Sie ist aus der Fußzeile der Anmeldeseiten, den Onboarding-Bildschirmen, der Seite zur geplanten Löschung und der Seite Postfächer verlinkt. Nicht gesetzt bedeutet kein Link. |
| `RELATICLE_TERMS_URL` | (leer) | Ihre Nutzungsbedingungen. Sie sind aus der Fußzeile der Anmeldung, den Onboarding-Bildschirmen und der Seite zur geplanten Löschung verlinkt. Nicht gesetzt bedeutet kein Link. |
| `RELATICLE_CONTACT_URL` | (leer) | Ihre Kontakt- oder Support-Seite. Sie ist aus der Fußzeile der Anmeldung verlinkt. Nicht gesetzt bedeutet kein Link. |
| `DOCUMENTATION_PUBLIC_URL` | `https://relaticle.com` | Die Website, die das Hilfecenter und die Entwickler-Leitfäden bereitstellt. Rela und die Hilfelinks des Panels verweisen dorthin. Setzen Sie sie auf Ihren eigenen Mirror, wenn Sie einen betreiben. |

### E-Mail und Kalender

Die Synchronisierung von E-Mails und Kalendern ist standardmäßig aus. Schalten Sie sie mit `RELATICLE_FEATURE_EMAIL_INTEGRATION=true` ein. Mitglieder können dann Google-Postfächer verbinden, und Microsoft-Postfächer, sobald Sie einen Microsoft-Client konfiguriert haben.

Google-Postfächer brauchen einen eigenen Google-OAuth-Client, getrennt von dem für die Anmeldung über soziale Netzwerke, mit aktivierter Gmail API und Google Calendar API.

| Variable | Standard | Beschreibung |
|----------|---------|-------------|
| `GMAIL_CLIENT_ID` | | OAuth-Client-ID des Google-Clients, mit dem Postfächer verbunden werden. |
| `GMAIL_CLIENT_SECRET` | | OAuth-Client-Secret dieses Clients. |
| `GMAIL_REDIRECT_URI` | | Callback-URL. Registrieren Sie `https://your-host/email-accounts/callback/gmail` in Google Cloud als Redirect-URI. |

Der Client fordert drei Scopes an: `gmail.readonly`, `gmail.send` und `calendar.events`. Fügen Sie sie dem OAuth-Zustimmungsbildschirm hinzu. Solange sich der Zustimmungsbildschirm im Testmodus befindet, können sich nur die von Ihnen aufgeführten Testnutzer verbinden.

Postfächer von Outlook und Microsoft 365 brauchen eine App-Registrierung in Microsoft Entra ID. Die Schaltfläche **Mit Microsoft verbinden** erscheint nur, wenn `AZURE_CLIENT_ID` gesetzt ist.

| Variable | Standard | Beschreibung |
|----------|---------|-------------|
| `AZURE_CLIENT_ID` | | Anwendungs-ID (Client-ID) der Entra-App-Registrierung, mit der Postfächer verbunden werden. |
| `AZURE_CLIENT_SECRET` | | Client-Secret dieser App-Registrierung. |
| `AZURE_REDIRECT_URI` | | Callback-URL. Registrieren Sie `https://your-host/email-accounts/callback/azure` in Entra als Web-Redirect-URI. |
| `AZURE_TENANT_ID` | `common` | Verzeichnis, über das die Anmeldung läuft. Setzen Sie Ihre Tenant-ID, um nur Konten einer Organisation zuzulassen. |

Die App fordert fünf delegierte Microsoft-Graph-Berechtigungen an: `Mail.Read`, `Mail.Send`, `Calendars.ReadWrite`, `User.Read` und `offline_access`. Fügen Sie sie unter API-Berechtigungen hinzu. Welche Konten sich verbinden können, bestimmen die unterstützten Kontotypen, die Sie in der App-Registrierung wählen.

Eine Einstellung gilt für beide Anbieter.

| Variable | Standard | Beschreibung |
|----------|---------|-------------|
| `EMAIL_SYNC_INITIAL_DAYS` | | Begrenzt den ersten Import auf die letzten N Tage. Lassen Sie sie ungesetzt, um das gesamte Postfach zu importieren. |

### KI-Assistent

Rela arbeitet mit Cloud-Anbietern, einem selbst gehosteten Server oder beidem. Modelle erscheinen in der Modellauswahl des Chats nur, wenn ihr Anbieter konfiguriert ist. Ist keiner konfiguriert, kann der Assistent nicht antworten.

Ein selbst gehostetes Modell betreiben Sie auf zwei Wegen. Sie können einen davon nutzen oder beide:

- **[Ollama](https://ollama.com)** (`OLLAMA_MODEL`): der einfachste Weg, mit der besten Unterstützung für Tool-Aufrufe.
- **Jeder OpenAI-kompatible Endpunkt** (`SELF_HOSTED_AI_*`): zeigen Sie auf [vLLM](https://docs.vllm.ai) (am besten für viele gleichzeitige Nutzer), LM Studio, LocalAI oder ein gehostetes Gateway. Stellt mehrere Modelle gleichzeitig bereit.

| Variable | Standard | Beschreibung |
|----------|---------|-------------|
| `ANTHROPIC_API_KEY` | (leer) | Aktiviert die Claude-Modelle in der Auswahl des Chats. |
| `OPENAI_API_KEY` | (leer) | Aktiviert die GPT-Modelle in der Auswahl des Chats. |
| `OLLAMA_BASE_URL` | `http://localhost:11434` | URL Ihres Ollama-Servers. Innerhalb eines Docker-Containers ist `localhost` der Container selbst. Nutzen Sie `http://host.docker.internal:11434` (oder die LAN-IP Ihres Hosts), um ein Ollama zu erreichen, das auf dem Host läuft. |
| `OLLAMA_MODEL` | (leer) | Ollama-Modell-Tag (z. B. `qwen3:14b`). Wenn Sie ihn setzen, wird das Modell der Auswahl des Chats hinzugefügt. |
| `SELF_HOSTED_AI_URL` | (leer) | Basis-URL eines OpenAI-kompatiblen Endpunkts (z. B. `http://localhost:8000/v1`). |
| `SELF_HOSTED_AI_KEY` | (leer) | API-Schlüssel für diesen Endpunkt, falls er einen verlangt (bei den meisten lokalen Servern leer). |
| `SELF_HOSTED_AI_MODELS` | (leer) | Kommagetrennte Modell-Tags, die bereitgestellt werden (z. B. `qwen3:14b,llama3.1:70b`). Jedes wird zu einem Eintrag in der Auswahl. |
| `CHAT_RATE_LIMIT_PER_MINUTE` | `60` | Nachrichten, die ein Arbeitsbereich pro Minute an Rela senden darf. Setzen Sie `0` für kein Limit. |

**Ein Modell wählen.** Der Assistent ruft CRM-Tools auf (suchen, erstellen, aktualisieren, löschen). Nur Modelle mit starker Unterstützung für Tool-Aufrufe arbeiten zuverlässig. Modelle der Klasse `qwen3` und `llama3.1:70b` sind gute Startpunkte. Kleine Modelle behaupten oft, eine Aktion sei gelungen, ohne das Tool wirklich aufzurufen. Prüfen Sie ein Modell, bevor Sie ihm vertrauen, mit `php artisan chat:models --probe=<id>`. Der Befehl listet die Registry auf und testet Tool-Aufrufe gegen den Endpunkt des Modells (nur selbst gehostete Modelle. Cloud-Modelle laufen über das SDK des Anbieters und werden abgelehnt).

**Bekannte Einschränkungen bei selbst gehosteten Modellen:**

- Cloud-Anbieter erzwingen auf API-Ebene, dass immer nur ein Schreibvorschlag offen ist. Selbst gehostete Modelle haben dafür keinen Schalter, deshalb wird es nur durch Anweisungen im Prompt durchgesetzt. Jeder Schreibzugriff braucht weiterhin Ihre ausdrückliche Freigabe, bevor etwas gespeichert wird.
- Jede Antwort muss innerhalb von 120 Sekunden fertig sein. Überschreitet ein langsames Modell dieses Limit, endet der Durchlauf sauber, und nichts geht unbemerkt verloren. Ihre Nachricht bleibt in der Unterhaltung, mit dem Hinweis, dass die Antwort das Limit von 120 Sekunden erreicht hat. Bitten Sie um eine kürzere Antwort oder um eine Antwort in Teilen. Um das Limit zu vermeiden, nutzen Sie ein kleineres oder schnelleres Modell oder eine GPU. „Denkende“ Modelle wie `qwen3` überlegen vor der Antwort und erreichen das Limit früher. Für die interaktive Nutzung helfen deshalb ein nicht denkendes Modell oder ein kürzerer Prompt.

### Feature-Flags

Schalten Sie Funktionen ein oder aus. Alle sind standardmäßig aktiviert, sofern nicht anders angegeben. Das ist nützlich für Forks und eigene Bereitstellungen, die einzelne Funktionen abschalten wollen, ohne Code zu ändern.

| Variable | Standard | Beschreibung |
|----------|---------|-------------|
| `RELATICLE_FEATURE_ONBOARD_SEED` | `true` | Legt Demodaten an (Beispielunternehmen, Personen, Aufgaben), wenn ein neuer Arbeitsbereich erstellt wird. Setzen Sie `false`, um mit einem leeren Arbeitsbereich zu starten. |
| `RELATICLE_FEATURE_SOCIAL_AUTH` | `true` | Aktiviert die Anmeldung mit Google und Microsoft. Setzen Sie `false`, um nur die Anmeldung mit E-Mail, Passwort und Passkey zu nutzen. |
| `RELATICLE_FEATURE_DOCUMENTATION` | `true` | Stellt die OpenAPI-Spezifikation unter `/openapi.json` und `/openapi.yaml` bereit und zeigt den Link zum MCP-Leitfaden auf der Seite der Zugriffstoken. Setzen Sie `false`, um beides zu entfernen. Die Spezifikation existiert erst, nachdem `php artisan scribe:generate` sie geschrieben hat. Das Docker-Image und die manuellen Schritte führen diesen Befehl nicht aus, der Build von Laravel Cloud schon. Die API-Referenz unter `/developers/api` ist eine Dokumentationsseite von relaticle.com und lädt dieselbe Spezifikation. |
| `RELATICLE_FEATURE_EMAIL_INTEGRATION` | `false` | Aktiviert die Synchronisierung von E-Mails und Kalendern für Google- und Microsoft-Postfächer, den E-Mail-Editor und die Hilfeseiten zu E-Mail und Kalender. Erfordert `GMAIL_CLIENT_ID` und `GMAIL_CLIENT_SECRET`. Microsoft-Postfächer brauchen außerdem `AZURE_CLIENT_ID` und `AZURE_CLIENT_SECRET`. |
| `RELATICLE_FEATURE_SIGNUP_CHALLENGE` | `false` | Verlangt von neuen Registrierungen eine Cloudflare-Turnstile-Prüfung. Sie bleibt für normale Browser unsichtbar und zeigt nur dann ein Kontrollkästchen, wenn Cloudflare eines braucht. Erfordert `TURNSTILE_SITE_KEY` und `TURNSTILE_SECRET_KEY`. Das Flag lehnt außerdem Registrierungen von Tor-Exit-Relays ab, wofür keine Schlüssel nötig sind. Es prüft die Exit-Liste des Tor Project, die stündlich aktualisiert wird. Die Anmeldung über Tor funktioniert weiterhin. Solange das Flag aktiv ist, erstellt die Registrierung per E-Mail höchstens 3 Konten pro Tag und IP-Adresse und 5 pro Stunde für die gesamte Installation. Die Anmeldung mit Google und Microsoft zählt nie mit. Ändern Sie die Obergrenzen mit `RELATICLE_SIGNUP_MAX_PER_IP_PER_DAY` und `RELATICLE_SIGNUP_MAX_PER_HOUR`, und setzen Sie eine auf `0`, um sie auszuschalten. Das Flag speichert außerdem die IP-Adresse und den Browser jeder neuen Registrierung für Systemadministratoren und löscht den Eintrag nach 90 Tagen. |

`RELATICLE_FEATURE_BLOG` bewirkt bei einer selbst gehosteten Installation nichts. Es schaltet die Blogseiten ein, die im Cloud-Paket liegen. Das Docker-Image lässt dieses Paket weg, ebenso die Marketing-Website und die Seiten der Hilfe und der Entwickler-Leitfäden.

Lassen Sie `RELATICLE_FEATURE_BILLING` ungesetzt, denn in einer selbst gehosteten Installation kann nichts ein Abonnement starten. Ist es aktiv, gilt für jeden Arbeitsbereich, der erstellt wurde, nachdem Ihre Installation die Abrechnungsmigration vom Juli 2026 ausgeführt hat, der Tarif „Kostenlos“ oder „Testphase beendet“. Bei einer neuen Installation ist das jeder Arbeitsbereich. Beide verweigern den Zugriff, deshalb stoppen für diese Arbeitsbereiche das Senden von E-Mails sowie die Synchronisierung von Postfächern und Kalendern. E-Mails, die für sie bereits eingeplant sind, werden als fehlgeschlagen markiert und nicht erneut gesendet, wenn Sie das Flag wieder ausschalten. Die Seite der Zugriffstoken erhält außerdem einen Hilfelink zum Webformular.

---

## Architektur

Das Docker-Setup führt 6 Container aus:

| Container | Image | Zweck |
|-----------|-------|---------|
| **app** | `ghcr.io/relaticle/relaticle:latest` | Webserver (nginx + PHP-FPM) auf Port 8080. Führt Migrationen beim Start automatisch aus. |
| **horizon** | `ghcr.io/relaticle/relaticle:latest` | Queue-Worker auf Basis von Laravel Horizon. Verarbeitet Hintergrund-Jobs. |
| **scheduler** | `ghcr.io/relaticle/relaticle:latest` | Führt `schedule:work` für wiederkehrende Aufgaben aus (z. B. Bereinigung, Benachrichtigungen). |
| **reverb** | `ghcr.io/relaticle/relaticle:latest` | Websocket-Server (Laravel Reverb). Streamt die Antworten von Rela in den Browser. |
| **postgres** | `postgres:17-alpine` | PostgreSQL-17-Datenbank. |
| **redis** | `redis:7-alpine` | Cache, Sitzungen und Queue-Backend. Läuft mit Append-only-Persistenz. |

### Volumes

| Volume | Zweck |
|--------|---------|
| `postgres` | Datenbankdateien. Sichern Sie es. |
| `redis` | Persistenzdaten von Redis. |
| `storage` | Hochgeladene Dateien und Anwendungsspeicher. Sichern Sie es. |

### Netzwerk

Der App-Container lauscht intern auf Port 8080 und wird auf `APP_PORT` (Standard 80) des Hosts abgebildet. Alle Container kommunizieren über das interne Netzwerk von Docker. Nur der App-Container stellt einen Port für den Host bereit. Der Browser erreicht den Websocket über ihn, unter `/reverb`.

---

## Administratorkonto erstellen

Erstellen Sie nach dem Start der Container Ihren ersten Administrator:

```bash
docker compose exec app php artisan make:filament-user
```

Wählen Sie bei der Frage nach dem Panel `app` und geben Sie dann Name, E-Mail-Adresse und Passwort ein. Danach erreichen Sie das CRM-Panel unter `{APP_URL}/app`. (Wie Sie stattdessen den instanzweiten Systemadministrator erstellen, lesen Sie im nächsten Abschnitt.)

**Hinweis**: Standardmäßig ist das CRM-Panel unter dem Pfad `/app` erreichbar. Für das Routing über Subdomains (z. B. `app.example.com`) setzen Sie stattdessen die Umgebungsvariable `APP_PANEL_DOMAIN`.

### Systemadministrator-Konto

Das Panel `sysadmin` unter `{APP_URL}/sysadmin` ist eine eigene, instanzweite Administrationsoberfläche für alle Arbeitsbereiche und Nutzer Ihrer Installation. Um einen Systemadministrator zu erstellen, wählen Sie entweder `sysadmin` bei der Panel-Abfrage von `make:filament-user`, oder Sie nutzen den eigenen Befehl:

```bash
docker compose exec app php artisan sysadmin:create
```

Das Panel öffnet sich auf dem Dashboard **Instance** unter `{APP_URL}/sysadmin/instance`, und `{APP_URL}/sysadmin` leitet dorthin weiter. Es zählt die Nutzer, Arbeitsbereiche, Unternehmen, Personen und Verkaufschancen über alle Arbeitsbereiche Ihrer Installation. Die Seiten zu Umsatz, Abonnements und KI-Credits, die das Mitarbeiter-Panel von relaticle.com zeigt, gehören zum Cloud-Paket und existieren hier nicht.

---

## Reverse Proxy und SSL

Der App-Container liefert HTTP intern auf Port 8080 aus. Setzen Sie einen Reverse Proxy davor, der die SSL-Terminierung übernimmt und den Verkehr an den Container leitet.

SSL ist nicht optional, wenn Sie Passkeys wollen: Browser führen WebAuthn nur über HTTPS aus, und ein Passkey ist an den Host gebunden, auf dem er registriert wurde. Das ist `APP_PANEL_DOMAIN`, wenn das Routing über Subdomains aktiv ist, sonst der Host von `APP_URL`. Stellen Sie das Panel daher unter einem Hostnamen bereit und halten Sie ihn stabil.

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

Caddy kümmert sich über Let's Encrypt automatisch um SSL-Zertifikate und leitet Websockets ohne zusätzliche Konfiguration weiter. Traefik tut das ebenfalls.

### Traefik

Fügen Sie diese Labels dem Service `app` in Ihrer `compose.yml` hinzu:

```yaml
labels:
  - "traefik.enable=true"
  - "traefik.http.routers.relaticle.rule=Host(`crm.example.com`)"
  - "traefik.http.routers.relaticle.entrypoints=websecure"
  - "traefik.http.routers.relaticle.tls.certresolver=letsencrypt"
  - "traefik.http.services.relaticle.loadbalancer.server.port=8080"
```

Die Header `Upgrade` und `Connection` im Nginx-Block tragen den Chat-Websocket. Ein Proxy, der sie verwirft, liefert die App trotzdem aus, die Antworten erscheinen dann aber einige Sekunden zu spät.

**Hinweis**: Wenn Sie einen Reverse Proxy nutzen, setzen Sie `APP_URL` auf Ihre öffentliche HTTPS-URL (z. B. `https://crm.example.com`). Die App vertraut `X-Forwarded-*`-Headern aus privaten RFC1918-Netzwerken, vom Loopback und von IPv6-ULA/Link-Local. Das deckt Coolify, Dokploy und Traefik in einem Docker-Netzwerk ab, ebenso Reverse Proxys auf dem Host. Header von öffentlichen IPs werden abgelehnt, damit niemand sie fälschen kann.

Die App akzeptiert nur Anfragen, deren `Host`-Header mit `APP_URL`, mit `www.` plus diesem Host, mit einer konfigurierten `*_DOMAIN` oder mit einem Eintrag in `TRUSTED_HOSTS` übereinstimmt. Jeder andere Host erhält ein 400. Ihr Proxy muss den echten `Host`-Header weiterleiten (bei Nginx `proxy_set_header Host $host;`) und darf keinen vom Client gesendeten `X-Forwarded-Host` durchreichen. Die App ignoriert `X-Forwarded-Host` und `X-Forwarded-Prefix`. Probes, die den Container über die IP oder einen internen Namen aufrufen, brauchen diesen Namen in `TRUSTED_HOSTS`, oder sie sollten die konfigurierte Domain als `Host`-Header senden.

---

## Bereitstellung auf Dokploy

[Dokploy](https://dokploy.com/) ist eine Open-Source-Plattform für die Bereitstellung. So stellen Sie Relaticle darauf bereit.

### 1. Projekt erstellen

Klicken Sie im Dokploy-Dashboard auf **Create Project** und geben Sie dem Projekt einen Namen (z. B. „Relaticle“).

### 2. Compose-Service hinzufügen

1. Klicken Sie in Ihrem Projekt auf **Create Service** und wählen Sie **Compose**.
2. Setzen Sie die Quelle auf **Raw** und fügen Sie den Inhalt der [compose.yml](https://raw.githubusercontent.com/Relaticle/relaticle/main/compose.yml) ein.
3. Klicken Sie auf **Create**.

### 3. Umgebungsvariablen setzen

Fügen Sie im Tab **Environment** des Services die erforderlichen Variablen hinzu:

```bash
APP_KEY=base64:your-generated-key-here
DB_PASSWORD=your-secure-database-password
APP_URL=https://crm.yourdomain.com
APP_PORT=8080
```

Erzeugen Sie Ihren `APP_KEY` mit:

```bash
echo "base64:$(openssl rand -base64 32)"
```

**Hinweis**: Setzen Sie `APP_PORT=8080`, damit der Container Port 8080:8080 abbildet und Konflikte mit Port 80 von Dokploy selbst vermieden werden.

### 4. Bereitstellen

Klicken Sie auf **Deploy**. Dokploy lädt die Images und startet alle 6 Container. Warten Sie, bis die Health-Checks bestanden sind.

### 5. Domain konfigurieren

1. Öffnen Sie den Tab **Domains** des Services `app`.
2. Fügen Sie Ihre Domain hinzu (z. B. `crm.yourdomain.com`).
3. Setzen Sie den Container-Port auf `8080`.
4. Aktivieren Sie HTTPS (Dokploy übernimmt Let's Encrypt automatisch).

### 6. Administrator erstellen

Öffnen Sie das Dokploy-Terminal für den Container `app` und führen Sie aus:

```bash
php artisan make:filament-user
```

Ihre Relaticle-Instanz ist jetzt unter `https://crm.yourdomain.com/app` live.

---

## Bereitstellung auf Coolify

[Coolify](https://coolify.io/) ist eine Open-Source-Plattform zum Bereitstellen von Anwendungen, die Sie selbst hosten können.

### 1. Neues Projekt erstellen

Klicken Sie im Coolify-Dashboard auf **New Project** und geben Sie dem Projekt einen Namen.

### 2. Docker-Compose-Ressource hinzufügen

1. Klicken Sie in Ihrem Projekt auf **Add Resource**.
2. Wählen Sie **Docker Compose**.
3. Wählen Sie **Empty** als Quelle und fügen Sie dann den Inhalt der [compose.yml](https://raw.githubusercontent.com/Relaticle/relaticle/main/compose.yml) ein.

### 3. Umgebungsvariablen konfigurieren

Fügen Sie im Bereich **Environment Variables** der Ressource hinzu:

```bash
APP_KEY=base64:your-generated-key-here
DB_PASSWORD=your-secure-database-password
APP_URL=https://crm.yourdomain.com
```

### 4. Domain einrichten

1. Öffnen Sie die **Settings** des App-Services.
2. Legen Sie Ihre Domain fest (z. B. `crm.yourdomain.com`).
3. Coolify richtet automatisch ein SSL-Zertifikat ein.

### 5. Bereitstellen

Klicken Sie auf **Deploy**. Coolify lädt die Images, erstellt die Container und startet die Services.

### 6. Administrator erstellen

Nutzen Sie die Funktion **Terminal** von Coolify, um im Container `app` auszuführen:

```bash
php artisan make:filament-user
```

Rufen Sie Ihr CRM unter `https://crm.yourdomain.com/app` auf.

---

## Bereitstellung auf Laravel Cloud

Laravel Cloud betreibt Relaticle ohne Docker. Web-Replikate und Queue-Worker erhalten jeweils eine eigene, kurzlebige Festplatte. Deshalb landet jede Datei, die Relaticle behält, im Object Storage.

### 1. Anwendung erstellen

Erstellen Sie eine Anwendung aus Ihrem Fork des Relaticle-Repositorys. Binden Sie diese Ressourcen an ihre Umgebung:

| Ressource | Einstellung |
|----------|---------|
| Datenbank | Laravel Serverless Postgres |
| Cache | Laravel Valkey, mit der Eviction-Policy **No eviction** |
| WebSockets | Laravel Reverb |
| Bucket | Ein **öffentlicher** Bucket mit dem Disk-Namen `public` |
| Bucket | Ein **privater** Bucket mit dem Disk-Namen `s3` |

Cloud verweist die Disks `public` und `s3` auf die Buckets, deshalb müssen die Namen genau stimmen. Lassen Sie „default disk“ bei beiden Buckets deaktiviert: Die Standard-Disk bleibt `local` und enthält nur Build-Artefakte.

Setzen Sie die erlaubten Origins jedes Buckets auf die URL Ihrer Anwendung, etwa `https://your-app.laravel.cloud`. Browser laden Dateien direkt in den privaten Bucket hoch und brauchen diesen Origin, um die CORS-Prüfung des Buckets zu bestehen.

Jobs in der Queue und Horizon liegen in Valkey neben dem Cache. Eine Policy mit Eviction kann unbemerkt einen Job aus der Queue verwerfen, wenn der Speicher voll läuft. Behalten Sie daher No eviction.

### 2. Umgebungsvariablen setzen

Cloud injiziert die Zugangsdaten für Datenbank, Cache, Reverb und Buckets. Fügen Sie diese hinzu:

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

Erzeugen Sie die Passport-Schlüssel einmalig mit `php artisan passport:keys --force` auf Ihrem Rechner und fügen Sie den Inhalt von `storage/oauth-private.key` und `storage/oauth-public.key` ein.

`FILAMENT_FILESYSTEM_DISK` muss `s3` sein. Ist sie nicht gesetzt, werden CRM-Exporte auf die Festplatte eines Replikats geschrieben, und Downloads von einem anderen Replikat schlagen fehl. Verweisen Sie sie nie auf den öffentlichen Bucket: Exporte enthalten Kundendaten.

### 3. Build- und Deploy-Befehle

Cloud installiert pnpm nicht, deshalb installiert der Build die Version, die `package.json` festlegt. Das Caching gehört in den Build: Dateiänderungen eines Deploy-Befehls bleiben nicht erhalten.

Build-Befehl:

```bash
composer install --no-dev --optimize-autoloader
npm install -g "$(node -p 'require("./package.json").packageManager')"
pnpm install --frozen-lockfile --ignore-scripts
pnpm run build
php artisan scribe:generate
php artisan optimize
php artisan filament:optimize
```

Deploy-Befehl:

```bash
php artisan migrate --force
```

Ein Fork enthält `packages/Cloud`, den eigenen Code von relaticle.com. Er bleibt aus der App heraus, solange `RELATICLE_CLOUD` nicht gesetzt ist. Lassen Sie diese Variable daher aus der Umgebung weg.

Cloud startet Horizon bei jedem Deployment neu, deshalb ist kein Schritt `horizon:terminate` nötig.

### 4. Queue-Worker und Scheduler

Schalten Sie den Scheduler-Schalter im App-Cluster ein. Worker-Cluster brauchen den Growth-Tarif oder höher. Fügen Sie einen Worker-Cluster hinzu, halten Sie ihn wach, statt ihn mit dem App-Cluster schlafen zu lassen, und geben Sie ihm einen benutzerdefinierten Hintergrundprozess:

```bash
php artisan horizon
```

Jedes Worker-Replikat führt den vollständigen Satz von Horizon-Supervisors aus. Passen Sie die Prozesszahlen mit `HORIZON_DEFAULT_MIN`, `HORIZON_DEFAULT_MAX`, `HORIZON_IMPORTS_MIN` und `HORIZON_IMPORTS_MAX` an.

### 5. Administrator erstellen

Öffnen Sie den Tab Commands der Umgebung und führen Sie den Befehl aus, wobei Sie jeden Wert als Option übergeben. Der Tab Commands kann interaktive Abfragen nicht beantworten.

```bash
php artisan make:filament-user --name="Your Name" --email=you@example.com --password=your-secure-password
```

Rufen Sie Ihr CRM unter `https://your-app.laravel.cloud/app` auf.

---

## Aktualisieren

### 1. Daten sichern

Sichern Sie vor jedem Update immer Ihre Daten. Siehe den Abschnitt Sicherung und Wiederherstellung weiter unten.

### 2. Neueste Images laden

```bash
docker compose pull
```

### 3. Container neu starten

```bash
docker compose up -d
```

Datenbankmigrationen laufen beim Start automatisch. Der App-Container wendet alle ausstehenden Migrationen an, bevor er Anfragen annimmt.

### 4. Compose-Datei aktualisieren

Eine `compose.yml`, die vor Oktober 2026 heruntergeladen wurde, hat keinen Container `reverb`. Der Chat funktioniert auch ohne ihn, und Antworten erscheinen einige Sekunden nach dem Speichern. Für Live-Streaming laden Sie die Datei erneut herunter und starten den Stack:

```bash
curl -o compose.yml https://raw.githubusercontent.com/Relaticle/relaticle/main/compose.yml
docker compose up -d
```

Kopieren Sie vorher alle Änderungen, die Sie an der alten Datei vorgenommen haben. Die neue Datei reicht die Variablen der KI-Anbieter aus `.env` an die Container weiter, diese Zeilen müssen Sie also nicht mehr von Hand ergänzen.

Das Image cached jetzt standardmäßig in Redis, sodass die Container für Web, Horizon und Scheduler einen gemeinsamen Cache nutzen. Die alte Datei gab nur dem Web-Container Redis, und die Stopp-Schaltfläche des Chats erreichte den Worker nie. Ein Stack, der `CACHE_STORE` selbst setzt, behält seinen eigenen Wert.

**Hinweis**: Lesen Sie vor dem Update die [Release Notes](https://github.com/Relaticle/relaticle/releases). Dort werden Breaking Changes dokumentiert.

---

## Sicherung und Wiederherstellung

### Datenbank sichern

```bash
docker compose exec postgres pg_dump -U relaticle relaticle > backup-$(date +%Y%m%d).sql
```

### Datenbank wiederherstellen

```bash
docker compose exec -T postgres psql -U relaticle relaticle < backup-20260320.sql
```

### Speicher sichern

Sichern Sie das Storage-Volume, das die hochgeladenen Dateien enthält:

```bash
docker compose cp app:/var/www/html/storage/app ./storage-backup
```

### Automatische Sicherungen

Richten Sie einen Cron-Job ein, der täglich sichert:

```bash
0 3 * * * cd /path/to/relaticle && docker compose exec -T postgres pg_dump -U relaticle relaticle | gzip > /backups/relaticle-$(date +\%Y\%m\%d).sql.gz
```

---

## Manuelle Bereitstellung

Wenn Sie Docker nicht nutzen möchten, stellen Sie Relaticle direkt auf einem Server bereit.

### Voraussetzungen

- PHP 8.5+ mit den Erweiterungen pdo_pgsql, gd, bcmath, mbstring, xml, redis
- PostgreSQL 17+
- Redis 7+
- Node.js 22+
- Composer 2+
- Nginx oder Apache
- Supervisor (für Queue-Worker)

### Installation

1. Klonen Sie das Repository:

```bash
git clone https://github.com/Relaticle/relaticle.git /var/www/relaticle
cd /var/www/relaticle
```

2. Installieren Sie die PHP-Abhängigkeiten:

```bash
composer install --no-dev --optimize-autoloader
```

3. Konfigurieren Sie die Umgebung:

```bash
cp .env.example .env
php artisan key:generate
```

Tragen Sie in `.env` Ihre Datenbank-Zugangsdaten, die Mail-Einstellungen und `APP_URL` ein. Setzen Sie `APP_ENV=production` und `APP_DEBUG=false`. Die Beispieldatei liefert `local` und `true`, und Debug-Seiten in der Produktion legen Ihre Konfiguration offen.

Lassen Sie `RELATICLE_CLOUD` ungesetzt. `packages/Cloud` enthält die Abrechnung, die Marketing-Website und die gehosteten Admin-Seiten von relaticle.com. Das Docker-Image lässt es weg, ein Klon enthält es. Bei ungesetztem Schalter bleibt es aus der App und aus dem Frontend-Build heraus, sodass Ihre Installation unter `/` nie die Marketing-Website von relaticle.com ausliefert. Seine Lizenz erlaubt die Nutzung für Entwicklung und Tests. Das Verzeichnis bleibt deshalb auf der Festplatte, und `git pull` funktioniert weiter.

4. Bauen Sie das Frontend:

```bash
pnpm install --frozen-lockfile && pnpm run build
```

5. Richten Sie die Datenbank ein:

```bash
php artisan migrate --force
php artisan storage:link
```

6. Setzen Sie die Berechtigungen:

```bash
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

7. Erstellen Sie Ihren Administrator:

```bash
php artisan make:filament-user
```

### Eine manuelle Installation aktualisieren

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

`php artisan optimize` baut die Config- und Route-Caches neu auf, die noch Routen benennen, die das vorherige Release bereitgestellt hat. Wenn Sie Komponenten mit `php artisan filament:optimize` cachen, führen Sie den Befehl nach `php artisan up` erneut aus. Läuft PHP-FPM mit `opcache.validate_timestamps=0`, laden Sie es ebenfalls neu.

Supervisor startet Horizon nach `horizon:terminate` neu. Lesen Sie vor dem Update die [Release Notes](https://github.com/Relaticle/relaticle/releases).

### Nginx-Konfiguration

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

Der Block `/reverb/app/` leitet den Chat-Websocket an Reverb weiter. Setzen Sie in `.env` `BROADCAST_CONNECTION=reverb`, `BROADCAST_REVERB_HOST=127.0.0.1` und `BROADCAST_REVERB_PORT=8080`, und lassen Sie `REVERB_HOST` leer.

### Supervisor für Horizon

Erstellen Sie `/etc/supervisor/conf.d/relaticle-horizon.conf`:

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

Starten Sie es dann:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start relaticle-horizon
```

### Supervisor für Reverb

Erstellen Sie `/etc/supervisor/conf.d/relaticle-reverb.conf`:

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

Starten Sie es auf dieselbe Weise und führen Sie nach jedem Update `php artisan reverb:restart` aus.

### Scheduler-Cron

Fügen Sie dies der Crontab von `www-data` hinzu:

```bash
* * * * * cd /var/www/relaticle && php artisan schedule:run >> /dev/null 2>&1
```

---

## Fehlerbehebung

### Fehler „APP_KEY is required“ beim Start

Die Umgebungsvariable `APP_KEY` ist nicht gesetzt. Erzeugen Sie einen Schlüssel:

```bash
echo "base64:$(openssl rand -base64 32)"
```

Tragen Sie ihn in Ihre `.env`-Datei ein und starten Sie neu:

```bash
docker compose up -d
```

### Container starten immer wieder neu

Prüfen Sie die Logs:

```bash
docker compose logs app --tail 50
```

Häufige Ursachen:
- Fehlende erforderliche Umgebungsvariablen (`APP_KEY`, `DB_PASSWORD`)
- PostgreSQL ist noch nicht bereit (die App wartet auf einen Health-Check, eigene Konfigurationen überspringen das aber eventuell)
- Zu wenig Arbeitsspeicher (mindestens 2 GB nötig)

### 500-Fehler nach der Bereitstellung

1. Setzen Sie vorübergehend `APP_DEBUG=true`, um den vollständigen Fehler zu sehen.
2. Sehen Sie sich die Logs an: `docker compose logs app --tail 100`
3. Stellen Sie sicher, dass `APP_URL` zu Ihrer tatsächlichen Domain passt (einschließlich Schema).
4. Führen Sie `docker compose exec app php artisan optimize:clear` aus, um alle Caches zu leeren.

### E-Mails werden nicht gesendet

1. Prüfen Sie, ob `MAIL_MAILER` auf `smtp` steht (nicht auf `log`).
2. Prüfen Sie, ob Ihre SMTP-Zugangsdaten stimmen.
3. Testen Sie mit: `docker compose exec app php artisan tinker --execute "Mail::raw('Test', fn(\$m) => \$m->to('you@example.com')->subject('Test'))"`
4. Prüfen Sie die Logs: `docker compose logs app | grep -i mail`

### Der Chat bleibt bei „Denkt nach…“

Seit Oktober 2026 erscheint eine Antwort wenige Sekunden nach dem Speichern, auch ohne Websocket. Wartet der Chat trotzdem, wird die Antwort nicht erzeugt:

1. Prüfen Sie, ob der Job lief: `docker compose logs horizon --tail 50` zeigt `ProcessChatMessage` als `DONE`.
2. Prüfen Sie, ob das Modell antwortet: `docker compose exec horizon php artisan chat:models --probe=<id>`.
3. Prüfen Sie, ob alle Container denselben Cache nutzen: `docker compose exec horizon php artisan config:show cache.default` gibt `redis` aus.

Ob Antworten live gestreamt werden, sehen Sie im Network-Tab des Browsers: Filtern Sie nach WS und suchen Sie eine Anfrage an `/reverb/app/...` mit dem Status 101. Gibt es keine solche Anfrage, fehlt der Container `reverb`, oder Ihr Reverse Proxy verwirft den Header `Upgrade`.

### Die Startseite zeigt die Relaticle-Marketing-Website

Das Cloud-Paket ist geladen, weil `RELATICLE_CLOUD` in `.env` oder in der Serverumgebung gesetzt ist. Eine selbst gehostete Installation leitet `/` zur App weiter. Entfernen Sie die Variable, führen Sie `php artisan config:clear` aus (und danach erneut `php artisan optimize`, wenn Sie die Konfiguration cachen) und bauen Sie dann das Frontend mit `pnpm run build` neu.

### Horizon verarbeitet keine Jobs

1. Prüfen Sie den Status von Horizon: `docker compose exec app php artisan horizon:status`
2. Prüfen Sie, ob der Container horizon läuft: `docker compose ps`
3. Prüfen Sie die Logs von Horizon: `docker compose logs horizon --tail 50`
4. Starten Sie Horizon neu: `docker compose restart horizon`

### Berechtigungsfehler beim Storage

```bash
docker compose exec app chown -R www-data:www-data /var/www/html/storage
docker compose exec app chmod -R 775 /var/www/html/storage
```

### Datenbankverbindung abgelehnt

1. Prüfen Sie, ob PostgreSQL läuft: `docker compose ps postgres`
2. Prüfen Sie die Logs von PostgreSQL: `docker compose logs postgres --tail 20`
3. Stellen Sie sicher, dass `DB_PASSWORD` in der App und im Container postgres übereinstimmt (beide lesen standardmäßig dieselbe `.env`-Variable).
