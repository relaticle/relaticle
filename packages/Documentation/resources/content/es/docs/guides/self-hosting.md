---
title: Guía de autoalojamiento
description: Obtenga los pasos de despliegue de Relaticle con Docker Compose, Coolify, Dokploy y Laravel Cloud, con la configuración de PostgreSQL, Redis y Ollama.
order: 1
updated: "2026-10-31"
---

Despliegue Relaticle en su propia infraestructura, con Docker o de forma manual.

---

## Inicio rápido

Ponga Relaticle en marcha en 5 pasos:

1. Descargue el archivo compose:

```bash
curl -o compose.yml https://raw.githubusercontent.com/Relaticle/relaticle/main/compose.yml
```

2. Genere una clave de aplicación:

```bash
echo "APP_KEY=base64:$(openssl rand -base64 32)"
```

3. Cree un archivo `.env` con su configuración:

```bash
APP_KEY=base64:your-generated-key-here
DB_PASSWORD=your-secure-database-password
APP_URL=https://crm.example.com
```

4. Inicie los contenedores:

```bash
docker compose up -d
```

5. Cree su cuenta de administrador:

```bash
docker compose exec app php artisan make:filament-user
```

Su CRM ya está disponible en `{APP_URL}/app`.

---

## Requisitos

| Recurso | Mínimo | Recomendado |
|----------|---------|-------------|
| RAM | 2 GB | 4 GB |
| CPU | 1 núcleo | 2 núcleos |
| Disco | 10 GB | 20 GB+ |
| Docker | 20.10+ | La última versión |
| Docker Compose | v2.0+ | La última versión |

---

## Variables de entorno

### Obligatorias

Deben estar definidas o los contenedores se negarán a iniciar.

| Variable | Descripción |
|----------|-------------|
| `APP_KEY` | Clave de cifrado. Genérela con `openssl rand -base64 32` y anteponga `base64:`. |
| `DB_PASSWORD` | Contraseña de PostgreSQL. Use un valor aleatorio seguro. |

### Aplicación

| Variable | Predeterminado | Descripción |
|----------|---------|-------------|
| `APP_NAME` | `Relaticle` | Se muestra en la pestaña del navegador y en los correos. |
| `APP_ENV` | `production` | Defínala como `production` para el autoalojamiento. |
| `APP_DEBUG` | `false` | Defínala como `true` solo para depurar. Nunca en producción. |
| `APP_TIMEZONE` | `UTC` | Zona horaria de la aplicación. |
| `APP_URL` | `http://localhost` | URL completa en la que se accede a Relaticle. Incluya el esquema. |
| `TRUSTED_HOSTS` | `localhost` | Nombres de host adicionales que acepta la aplicación, separados por comas. El host de `APP_URL` y los hosts de cualquier `*_DOMAIN` siempre se aceptan. Mantenga `localhost` en la lista, porque la comprobación de estado del contenedor lo usa. |
| `TRUSTED_PROXIES` | (vacío) | Direcciones de proxy cuyos encabezados `X-Forwarded-For`, `-Proto` y `-Port` cree la aplicación, separadas por comas, como IP o rangos CIDR. Vacío confía en todos los rangos privados (10/8, 172.16/12, 192.168/16, loopback), lo que necesitan Traefik, Coolify y Dokploy. Redúzcalo a su proxy, por ejemplo la puerta de enlace o la subred de su red Docker, para que ningún otro contenedor o par del host pueda falsificar una IP de cliente. `*` confía en cualquier remitente y no es seguro. Nunca publique el puerto de la aplicación directamente en una red no confiable. |
| `SESSION_SECURE_COOKIE` | (vacío) | Envía la cookie de sesión solo por HTTPS. Vacío significa segura cuando `APP_URL` empieza con `https://`, y no segura en caso contrario. Defina `true` o `false` para anularlo. Sirva las instalaciones de producción por HTTPS. El navegador descarta una cookie Secure enviada por HTTP simple, así que una instalación con HTTP simple debe dejarla vacía o en `false`, o nadie podrá iniciar sesión. Una instalación `https://` que usted también abre por HTTP simple, por ejemplo por IP de la red local, necesita `false` para que ese acceso funcione. |
| `HSTS_MAX_AGE` | `15552000` | Segundos durante los cuales los navegadores siguen forzando HTTPS para su host. El encabezado `Strict-Transport-Security` se envía solo cuando `APP_ENV=production` y la solicitud es HTTPS. Defina `0` para que los navegadores lo olviden. |
| `HSTS_INCLUDE_SUBDOMAINS` | `false` | Defina `true` para extender HSTS a todos los subdominios del host. Déjelo desactivado si algún subdominio hermano, como un host de intranet, se sirve por HTTP simple. |
| `CSP_MODE` | `enforce` | Content-Security-Policy para las páginas HTML. `report-only` envía la política completa como un encabezado de solo informe, y `off` la omite. `frame-ancestors`, `object-src` y `base-uri` se siguen aplicando en todos los modos. |
| `CSP_SCRIPT_SRC`, `CSP_STYLE_SRC`, `CSP_IMG_SRC`, `CSP_FONT_SRC`, `CSP_CONNECT_SRC`, `CSP_FRAME_SRC` | (vacío) | Orígenes adicionales para esa directiva, separados por comas. Defina `CSP_IMG_SRC` cuando los archivos multimedia o las fotos de perfil se carguen desde la URL de un bucket en otro host. El origen del websocket se agrega automáticamente. |
| `APP_PORT` | `80` | Puerto del host al que se enlaza el contenedor de la aplicación. |
| `APP_PANEL_DOMAIN` | (vacío) | Defínala para el enrutamiento por subdominio (por ejemplo, `app.example.com`). Déjela vacía para el modo por ruta (`/app`). |
| `PASSKEYS_USER_HANDLE_SECRET` | `APP_KEY` | Deriva el identificador de usuario opaco de WebAuthn que se guarda en cada autenticador. Asígnele su propio valor aleatorio (`openssl rand -base64 32`) antes de que alguien registre una llave de acceso, para que rotar `APP_KEY` más adelante no cambie el identificador de todos los usuarios. |
| `REQUIRE_EMAIL_VERIFICATION` | `true` | Cuando es `false`, los usuarios inician sesión sin verificar su correo. Es útil para quienes autoalojan Relaticle y aún no han configurado SMTP. El administrador que cree con `make:filament-user` se verifica automáticamente en cualquier caso, así que el valor predeterminado `true` es seguro para instalaciones nuevas con Docker. Defínala como `false` solo si su panel está en una red privada: con la verificación desactivada, cualquiera que llegue a la página de inicio de sesión puede crear una cuenta que funciona. |
| `LOG_CHANNEL` | `stderr` | Destino de los registros. Se recomienda `stderr` para Docker. |
| `LOG_LEVEL` | `warning` | Nivel mínimo de registro. Use `debug` para solucionar problemas. |

### Correo

| Variable | Predeterminado | Descripción |
|----------|---------|-------------|
| `MAIL_MAILER` | `log` | Controlador de correo: `smtp`, `ses`, `mailgun`, `postmark` o `log`. |
| `MAIL_HOST` | (vacío) | Host SMTP (por ejemplo, `smtp.mailgun.org`). |
| `MAIL_PORT` | `587` | Puerto SMTP. |
| `MAIL_USERNAME` | (vacío) | Usuario SMTP. |
| `MAIL_PASSWORD` | (vacío) | Contraseña SMTP. |
| `MAIL_ENCRYPTION` | `tls` | `tls` o `ssl`. |
| `MAIL_FROM_ADDRESS` | `hello@example.com` | Dirección de correo del remitente. |
| `MAIL_FROM_NAME` | `Relaticle` | Nombre visible del remitente. |

### Base de datos y Redis

Están preconfiguradas en `compose.yml` y por lo general no necesitan cambios.

| Variable | Predeterminado | Descripción |
|----------|---------|-------------|
| `DB_DATABASE` | `relaticle` | Nombre de la base de datos de PostgreSQL. |
| `DB_USERNAME` | `relaticle` | Usuario de PostgreSQL. |
| `REDIS_PASSWORD` | `null` | Contraseña de Redis. Déjela en `null` para no usar autenticación. |

### Actualizaciones en vivo

Rela, el asistente de IA integrado, transmite sus respuestas al chat mediante un websocket. El contenedor `reverb` lo sirve, y el contenedor de la aplicación lo enruta bajo `/reverb` en la dirección que ya usa. No hace falta ningún puerto, dominio ni certificado adicional.

| Variable | Predeterminado | Descripción |
|----------|---------|-------------|
| `REVERB_APP_SECRET` | `APP_KEY` | Firma los mensajes entre la aplicación y el contenedor `reverb`. Defínala para rotarla por separado de `APP_KEY`. |
| `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` | (vacío) | Defínalas solo cuando el websocket tenga su propia dirección pública. Si se dejan vacías, el navegador se conecta al origen de `APP_URL` bajo `/reverb`. |

Cuando el websocket no está disponible, el chat sigue funcionando. Cada respuesta aparece unos segundos después de guardarse, sin escribirse en vivo. Los siguientes pasos sugeridos debajo de una respuesta aparecen después de recargar la página.

### Opcionales

| Variable | Descripción |
|----------|-------------|
| `GOOGLE_CLIENT_ID` | ID de cliente de Google OAuth para el inicio de sesión social. El botón de Google aparece solo cuando se define. |
| `GOOGLE_CLIENT_SECRET` | Secreto de cliente de Google OAuth. |
| `GOOGLE_REDIRECT_URI` | URL de retorno absoluta (`https://your-host/auth/callback/google`), o una ruta relativa (`/auth/callback/google`) para derivar el host de la solicitud. |
| `MICROSOFT_CLIENT_ID` | ID de cliente de Microsoft Entra ID. El botón de Microsoft aparece solo cuando se define; registre `https://your-host/auth/callback/microsoft` como URI de redirección. |
| `MICROSOFT_CLIENT_SECRET` | Secreto de cliente de Microsoft Entra ID. |
| `SENTRY_LARAVEL_DSN` | DSN de Sentry para el seguimiento de errores. |
| `FATHOM_ANALYTICS_SITE_ID` | ID de sitio de Fathom Analytics. |

### Enlaces legales y de ayuda

Una instalación autoalojada no incluye páginas legales, y las políticas de relaticle.com no cubren su instalación. Apunte los tres primeros ajustes a sus propias páginas. Un ajuste sin definir no muestra ningún enlace. Solo `DOCUMENTATION_PUBLIC_URL` apunta de forma predeterminada a relaticle.com.

| Variable | Predeterminado | Descripción |
|----------|---------|-------------|
| `RELATICLE_PRIVACY_URL` | (vacío) | Su política de privacidad. Se enlaza desde el pie de las páginas de inicio de sesión, las pantallas de incorporación, la página de eliminación programada y la página de buzones. Sin definir, no hay enlace. |
| `RELATICLE_TERMS_URL` | (vacío) | Sus términos de servicio. Se enlazan desde el pie del inicio de sesión, las pantallas de incorporación y la página de eliminación programada. Sin definir, no hay enlace. |
| `RELATICLE_CONTACT_URL` | (vacío) | Su página de contacto o soporte. Se enlaza desde el pie del inicio de sesión. Sin definir, no hay enlace. |
| `DOCUMENTATION_PUBLIC_URL` | `https://relaticle.com` | El sitio que sirve el centro de ayuda y las guías para desarrolladores. Rela y los enlaces de ayuda del panel apuntan aquí. Defínala con su propio espejo si aloja uno. |

### Correo y calendario

La sincronización de correo y calendario está desactivada de forma predeterminada. Actívela con `RELATICLE_FEATURE_EMAIL_INTEGRATION=true`. Los miembros podrán entonces conectar cuentas de Google, y cuentas de Microsoft una vez que configure un cliente de Microsoft.

Los buzones de Google necesitan su propio cliente de Google OAuth, distinto del que se usa para el inicio de sesión social, con la API de Gmail y la API de Google Calendar habilitadas.

| Variable | Predeterminado | Descripción |
|----------|---------|-------------|
| `GMAIL_CLIENT_ID` | | ID de cliente OAuth del cliente de Google que se usa para conectar buzones. |
| `GMAIL_CLIENT_SECRET` | | Secreto de cliente OAuth de ese cliente. |
| `GMAIL_REDIRECT_URI` | | URL de retorno. Registre `https://your-host/email-accounts/callback/gmail` como URI de redirección en Google Cloud. |

El cliente solicita tres alcances: `gmail.readonly`, `gmail.send` y `calendar.events`. Agréguelos a la pantalla de consentimiento de OAuth. Mientras la pantalla de consentimiento está en modo de prueba, solo pueden conectarse los usuarios de prueba que usted enumere.

Los buzones de Outlook y Microsoft 365 necesitan un registro de aplicación en Microsoft Entra ID. El botón **Conectar cuenta de Microsoft** aparece solo cuando se define `AZURE_CLIENT_ID`.

| Variable | Predeterminado | Descripción |
|----------|---------|-------------|
| `AZURE_CLIENT_ID` | | ID de aplicación (cliente) del registro de aplicación de Entra que se usa para conectar buzones. |
| `AZURE_CLIENT_SECRET` | | Secreto de cliente de ese registro de aplicación. |
| `AZURE_REDIRECT_URI` | | URL de retorno. Registre `https://your-host/email-accounts/callback/azure` como URI de redirección Web en Entra. |
| `AZURE_TENANT_ID` | `common` | Directorio por el que pasa el inicio de sesión. Defina el ID de su inquilino para aceptar cuentas de una sola organización. |

La aplicación solicita cinco permisos delegados de Microsoft Graph: `Mail.Read`, `Mail.Send`, `Calendars.ReadWrite`, `User.Read` y `offline_access`. Agréguelos en Permisos de API. Los tipos de cuenta compatibles que elija en el registro de la aplicación deciden qué cuentas pueden conectarse.

Un ajuste se aplica a ambos proveedores.

| Variable | Predeterminado | Descripción |
|----------|---------|-------------|
| `EMAIL_SYNC_INITIAL_DAYS` | | Limita la primera importación a los últimos N días. Déjela sin definir para importar todo el buzón. |

### Asistente de IA

Rela funciona con proveedores en la nube, con un servidor autoalojado o con ambos. Los modelos aparecen en el selector de modelos del chat solo cuando su proveedor está configurado. Si no hay ninguno configurado, el asistente no puede responder.

Hay dos formas de ejecutar un modelo autoalojado, y puede usar una o ambas:

- **[Ollama](https://ollama.com)** (`OLLAMA_MODEL`): el camino más simple, con el mejor soporte de llamadas a herramientas.
- **Cualquier endpoint compatible con OpenAI** (`SELF_HOSTED_AI_*`): apunte a [vLLM](https://docs.vllm.ai) (el mejor para muchos usuarios simultáneos), LM Studio, LocalAI o un gateway alojado. Expone varios modelos a la vez.

| Variable | Predeterminado | Descripción |
|----------|---------|-------------|
| `ANTHROPIC_API_KEY` | (vacío) | Habilita los modelos de Claude en el selector del chat. |
| `OPENAI_API_KEY` | (vacío) | Habilita los modelos GPT en el selector del chat. |
| `OLLAMA_BASE_URL` | `http://localhost:11434` | URL de su servidor de Ollama. Dentro de un contenedor de Docker, `localhost` es el propio contenedor. Use `http://host.docker.internal:11434` (o la IP de red local de su host) para llegar a Ollama que se ejecuta en el host. |
| `OLLAMA_MODEL` | (vacío) | Etiqueta del modelo de Ollama (por ejemplo, `qwen3:14b`). Al definirla, el modelo se agrega al selector del chat. |
| `SELF_HOSTED_AI_URL` | (vacío) | URL base de un endpoint compatible con OpenAI (por ejemplo, `http://localhost:8000/v1`). |
| `SELF_HOSTED_AI_KEY` | (vacío) | Clave de API de ese endpoint, si la requiere (en blanco para la mayoría de los servidores locales). |
| `SELF_HOSTED_AI_MODELS` | (vacío) | Etiquetas de modelo separadas por comas que se expondrán (por ejemplo, `qwen3:14b,llama3.1:70b`). Cada una se convierte en una entrada del selector. |
| `CHAT_RATE_LIMIT_PER_MINUTE` | `60` | Mensajes que un espacio de trabajo puede enviar a Rela por minuto. Defina `0` para no tener límite. |

**Cómo elegir un modelo.** El asistente llama a herramientas del CRM (buscar, crear, actualizar, eliminar). Solo los modelos con buen soporte de llamadas a herramientas funcionan de forma fiable. Los modelos de la clase de `qwen3` y `llama3.1:70b` son buenos puntos de partida. Los modelos pequeños tienden a afirmar que una acción tuvo éxito sin invocar realmente la herramienta. Verifique un modelo antes de confiarle trabajo con `php artisan chat:models --probe=<id>`, que enumera el registro y prueba las llamadas a herramientas contra su endpoint (solo modelos autoalojados; los modelos en la nube se ejecutan mediante el SDK del proveedor y se rechazan).

**Limitaciones conocidas de los modelos autoalojados:**

- Los proveedores en la nube imponen una propuesta de escritura a la vez en el nivel de la API; los modelos autoalojados no tienen un interruptor equivalente, así que esto se impone solo mediante instrucciones en el prompt. Cada escritura sigue requiriendo su aprobación explícita antes de guardarse.
- Cada respuesta debe completarse en 120 segundos. Si un modelo lento lo supera, el turno se detiene limpiamente y no se pierde nada en silencio. Su mensaje permanece en la conversación con una nota de que la respuesta alcanzó el límite de 120 segundos. Pida una respuesta más corta, o por partes. Para evitar el límite, use un modelo más pequeño o más rápido, o una GPU; los modelos "de razonamiento" como `qwen3` razonan antes de responder y lo alcanzan antes, así que un modelo sin razonamiento o un prompt más corto ayudan en el uso interactivo.

### Indicadores de funciones

Active o desactive funciones. Todas están habilitadas de forma predeterminada, salvo que se indique lo contrario. Es útil para forks e implementaciones personalizadas que quieren desactivar funciones específicas sin modificar el código.

| Variable | Predeterminado | Descripción |
|----------|---------|-------------|
| `RELATICLE_FEATURE_ONBOARD_SEED` | `true` | Carga datos de ejemplo (empresas, personas y tareas de ejemplo) al crear un espacio de trabajo nuevo. Defina `false` para empezar con un espacio de trabajo vacío. |
| `RELATICLE_FEATURE_SOCIAL_AUTH` | `true` | Habilita el inicio de sesión social con Google y Microsoft. Defina `false` para usar solo el inicio de sesión con correo, contraseña y llave de acceso. |
| `RELATICLE_FEATURE_DOCUMENTATION` | `true` | Sirve la especificación OpenAPI en `/openapi.json` y `/openapi.yaml`, y muestra el enlace a la guía de MCP en la página de tokens de acceso. Defina `false` para quitar ambos. La especificación existe solo después de que `php artisan scribe:generate` la escribe. La imagen de Docker y los pasos manuales no ejecutan ese comando, y la compilación de Laravel Cloud sí. La referencia de la API en `/developers/api` es una página de documentación de relaticle.com y carga la misma especificación. |
| `RELATICLE_FEATURE_EMAIL_INTEGRATION` | `false` | Habilita la sincronización de correo y calendario para cuentas de Google y Microsoft, el redactor de correo y las páginas de ayuda de Correo y calendario. Requiere `GMAIL_CLIENT_ID` y `GMAIL_CLIENT_SECRET`. Las cuentas de Microsoft también necesitan `AZURE_CLIENT_ID` y `AZURE_CLIENT_SECRET`. |
| `RELATICLE_FEATURE_SIGNUP_CHALLENGE` | `false` | Pide a quienes se registran que pasen una verificación de Cloudflare Turnstile. Permanece oculta para los navegadores normales y muestra una casilla solo cuando Cloudflare la necesita. Requiere `TURNSTILE_SITE_KEY` y `TURNSTILE_SECRET_KEY`. El indicador también rechaza registros desde nodos de salida de Tor, lo que no necesita claves. Consulta la lista de nodos de salida del Tor Project, que se actualiza cada hora. El inicio de sesión por Tor sigue funcionando. Mientras el indicador está activo, el registro con correo crea como máximo 3 cuentas al día por dirección IP y 5 por hora en toda la instalación. El registro con Google y Microsoft nunca cuenta. Cambie los topes con `RELATICLE_SIGNUP_MAX_PER_IP_PER_DAY` y `RELATICLE_SIGNUP_MAX_PER_HOUR`, y defina uno en `0` para desactivarlo. El indicador también registra la dirección IP y el navegador de cada nuevo registro para los administradores del sistema, y elimina ese registro después de 90 días. |

`RELATICLE_FEATURE_BLOG` no hace nada en una instalación autoalojada. Activa las páginas del blog, que viven en el paquete cloud. La imagen de Docker deja fuera ese paquete, junto con el sitio de marketing y las páginas del centro de ayuda y de las guías para desarrolladores.

Deje `RELATICLE_FEATURE_BILLING` sin definir, porque nada en una instalación autoalojada puede iniciar una suscripción. Con ella activada, todo espacio de trabajo creado después de que su instalación ejecutó la migración de facturación de julio de 2026 pasa a Gratuito o Prueba finalizada. En una instalación nueva, eso incluye a todos los espacios de trabajo. Ambos estados deniegan el acceso, así que el envío de correo, la sincronización de buzones y la sincronización del calendario se detienen para esos espacios de trabajo. El correo que ya estaba en cola para ellos se marca como fallido y no se reenvía cuando desactiva el indicador. La página de tokens de acceso también gana un enlace de ayuda de formulario web.

---

## Arquitectura

La configuración de Docker ejecuta 6 contenedores:

| Contenedor | Imagen | Propósito |
|-----------|-------|---------|
| **app** | `ghcr.io/relaticle/relaticle:latest` | Servidor web (nginx + PHP-FPM) en el puerto 8080. Ejecuta las migraciones automáticamente al iniciar. |
| **horizon** | `ghcr.io/relaticle/relaticle:latest` | Worker de cola impulsado por Laravel Horizon. Procesa los trabajos en segundo plano. |
| **scheduler** | `ghcr.io/relaticle/relaticle:latest` | Ejecuta `schedule:work` para las tareas recurrentes (por ejemplo, limpieza y notificaciones). |
| **reverb** | `ghcr.io/relaticle/relaticle:latest` | Servidor de websockets (Laravel Reverb). Transmite las respuestas de Rela al navegador. |
| **postgres** | `postgres:17-alpine` | Base de datos PostgreSQL 17. |
| **redis** | `redis:7-alpine` | Caché, sesiones y backend de colas. Se ejecuta con persistencia de solo anexado. |

### Volúmenes

| Volumen | Propósito |
|--------|---------|
| `postgres` | Archivos de la base de datos. Haga una copia de seguridad. |
| `redis` | Datos de persistencia de Redis. |
| `storage` | Archivos subidos y almacenamiento de la aplicación. Haga una copia de seguridad. |

### Red

El contenedor de la aplicación escucha internamente en el puerto 8080 y lo asigna a `APP_PORT` (80 de forma predeterminada) en el host. Todos los contenedores se comunican por la red interna de Docker. Solo el contenedor de la aplicación expone un puerto al host. El navegador llega al websocket a través de él, en `/reverb`.

---

## Crear su cuenta de administrador

Después de iniciar los contenedores, cree su primer usuario administrador:

```bash
docker compose exec app php artisan make:filament-user
```

Cuando se le pida elegir un panel, elija `app` y luego ingrese un nombre, un correo y una contraseña. Una vez creado, acceda al panel del CRM en `{APP_URL}/app`. (Para crear en su lugar al administrador del sistema de toda la instancia, consulte la sección siguiente.)

**Nota**: De forma predeterminada, el panel del CRM está disponible en la ruta `/app`. Para usar enrutamiento por subdominio (por ejemplo, `app.example.com`), defina la variable de entorno `APP_PANEL_DOMAIN`.

### Cuenta de administrador del sistema

El panel `sysadmin` en `{APP_URL}/sysadmin` es una superficie de administración independiente, de toda la instancia, para administrar cada espacio de trabajo y usuario de su instalación. Para crear un administrador del sistema, puede elegir `sysadmin` en la pregunta de panel de `make:filament-user`, o usar el comando dedicado:

```bash
docker compose exec app php artisan sysadmin:create
```

El panel se abre en el tablero **Instance** en `{APP_URL}/sysadmin/instance`, y `{APP_URL}/sysadmin` redirige allí. Cuenta los usuarios, espacios de trabajo, empresas, personas y oportunidades de todos los espacios de trabajo de su instalación. Las páginas de ingresos, suscripciones y créditos de IA que muestra el panel del personal de relaticle.com pertenecen al paquete cloud y no existen aquí.

---

## Proxy inverso y SSL

El contenedor de la aplicación sirve HTTP internamente en el puerto 8080. Coloque un proxy inverso delante para encargarse de la terminación SSL y enrutar el tráfico al contenedor.

SSL no es opcional si quiere usar llaves de acceso: los navegadores ejecutan WebAuthn solo por HTTPS, y una llave de acceso queda ligada al host en el que se registró. Ese host es `APP_PANEL_DOMAIN` cuando el enrutamiento por subdominio está activo, y el host de `APP_URL` en caso contrario, así que sirva el panel desde un solo nombre de host y manténgalo estable.

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

Caddy se encarga de los certificados SSL automáticamente mediante Let's Encrypt, y reenvía los websockets sin configuración adicional. Traefik también.

### Traefik

Agregue estas etiquetas al servicio `app` en su `compose.yml`:

```yaml
labels:
  - "traefik.enable=true"
  - "traefik.http.routers.relaticle.rule=Host(`crm.example.com`)"
  - "traefik.http.routers.relaticle.entrypoints=websecure"
  - "traefik.http.routers.relaticle.tls.certresolver=letsencrypt"
  - "traefik.http.services.relaticle.loadbalancer.server.port=8080"
```

Los encabezados `Upgrade` y `Connection` del bloque de Nginx transportan el websocket del chat. Un proxy que los descarta sigue sirviendo la aplicación, pero las respuestas aparecen entonces con unos segundos de retraso.

**Nota**: Cuando use un proxy inverso, defina `APP_URL` con su URL pública HTTPS (por ejemplo, `https://crm.example.com`). La aplicación confía en los encabezados `X-Forwarded-*` de redes privadas RFC1918, loopback e IPv6 ULA/link-local, lo que cubre Coolify, Dokploy y Traefik en una red Docker, y los proxies inversos en el host. Los encabezados de IP públicas se rechazan, lo que evita la suplantación.

La aplicación solo acepta solicitudes cuyo encabezado `Host` coincida con `APP_URL`, con `www.` más ese host, con un `*_DOMAIN` configurado o con una entrada de `TRUSTED_HOSTS`. Cualquier otro host recibe un 400. Su proxy debe reenviar el encabezado `Host` real (en Nginx, `proxy_set_header Host $host;`) y no debe pasar un `X-Forwarded-Host` enviado por el cliente. La aplicación ignora `X-Forwarded-Host` y `X-Forwarded-Prefix`. Las sondas que llaman al contenedor por IP o por un nombre interno necesitan ese nombre en `TRUSTED_HOSTS`, o deben enviar el dominio configurado como encabezado `Host`.

---

## Desplegar en Dokploy

[Dokploy](https://dokploy.com/) es una plataforma de despliegue de código abierto. Así se despliega Relaticle en ella.

### 1. Crear un proyecto

En el panel de Dokploy, haga clic en **Create Project** y asígnele un nombre (por ejemplo, "Relaticle").

### 2. Agregar un servicio Compose

1. Dentro de su proyecto, haga clic en **Create Service** y seleccione **Compose**
2. Defina el origen como **Raw** y pegue el contenido de [compose.yml](https://raw.githubusercontent.com/Relaticle/relaticle/main/compose.yml)
3. Haga clic en **Create**

### 3. Definir las variables de entorno

En la pestaña **Environment** del servicio, agregue las variables obligatorias:

```bash
APP_KEY=base64:your-generated-key-here
DB_PASSWORD=your-secure-database-password
APP_URL=https://crm.yourdomain.com
APP_PORT=8080
```

Genere su `APP_KEY` con:

```bash
echo "base64:$(openssl rand -base64 32)"
```

**Nota**: Defina `APP_PORT=8080` para que el contenedor asigne el puerto 8080:8080 y evite conflictos con el propio puerto 80 de Dokploy.

### 4. Desplegar

Haga clic en **Deploy**. Dokploy descargará las imágenes e iniciará los 6 contenedores. Espere a que pasen las comprobaciones de estado.

### 5. Configurar el dominio

1. Vaya a la pestaña **Domains** del servicio `app`
2. Agregue su dominio (por ejemplo, `crm.yourdomain.com`)
3. Defina el puerto del contenedor como `8080`
4. Active HTTPS (Dokploy se encarga de Let's Encrypt automáticamente)

### 6. Crear el usuario administrador

Abra el terminal de Dokploy para el contenedor `app` y ejecute:

```bash
php artisan make:filament-user
```

Su instancia de Relaticle ya está activa en `https://crm.yourdomain.com/app`.

---

## Desplegar en Coolify

[Coolify](https://coolify.io/) es una plataforma de código abierto y autoalojable para desplegar aplicaciones.

### 1. Crear un proyecto nuevo

En el panel de Coolify, haga clic en **New Project** y asígnele un nombre.

### 2. Agregar un recurso Docker Compose

1. Haga clic en **Add Resource** en su proyecto
2. Seleccione **Docker Compose**
3. Elija **Empty** como origen y luego pegue el contenido de [compose.yml](https://raw.githubusercontent.com/Relaticle/relaticle/main/compose.yml)

### 3. Configurar las variables de entorno

En la sección **Environment Variables** del recurso, agregue:

```bash
APP_KEY=base64:your-generated-key-here
DB_PASSWORD=your-secure-database-password
APP_URL=https://crm.yourdomain.com
```

### 4. Configurar el dominio

1. Vaya a **Settings** del servicio de la aplicación
2. Defina su dominio (por ejemplo, `crm.yourdomain.com`)
3. Coolify aprovisionará automáticamente un certificado SSL

### 5. Desplegar

Haga clic en **Deploy**. Coolify descargará las imágenes, creará los contenedores e iniciará los servicios.

### 6. Crear el usuario administrador

Use la función **Terminal** de Coolify para ejecutar en el contenedor `app`:

```bash
php artisan make:filament-user
```

Acceda a su CRM en `https://crm.yourdomain.com/app`.

---

## Desplegar en Laravel Cloud

Laravel Cloud ejecuta Relaticle sin Docker. Las réplicas web y los workers de cola tienen cada uno su propio disco de corta duración, así que todo archivo que Relaticle conserva va al almacenamiento de objetos.

### 1. Crear la aplicación

Cree una aplicación a partir de su fork del repositorio de Relaticle. Asocie estos recursos a su entorno:

| Recurso | Ajuste |
|----------|---------|
| Base de datos | Laravel Serverless Postgres |
| Caché | Laravel Valkey, con la política de expulsión definida en **No eviction** |
| WebSockets | Laravel Reverb |
| Bucket | Un bucket **público** con el nombre de disco `public` |
| Bucket | Un bucket **privado** con el nombre de disco `s3` |

Cloud apunta los discos llamados `public` y `s3` a los buckets, así que los nombres deben coincidir exactamente. Deje sin marcar "default disk" en ambos buckets: el disco predeterminado sigue siendo `local`, que contiene solo artefactos de compilación.

Defina los orígenes permitidos de cada bucket con la URL de su aplicación, como `https://your-app.laravel.cloud`. Los navegadores suben archivos directamente al bucket privado y necesitan ese origen para pasar la comprobación CORS del bucket.

Los trabajos en cola y Horizon viven en Valkey junto a la caché. Una política con expulsión puede descartar en silencio un trabajo en cola cuando se llena la memoria, así que mantenga No eviction.

### 2. Definir las variables de entorno

Cloud inyecta las credenciales de la base de datos, la caché, Reverb y los buckets. Agregue estas:

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

Genere las claves de Passport una sola vez con `php artisan passport:keys --force` en su máquina local y pegue el contenido de `storage/oauth-private.key` y `storage/oauth-public.key`.

`FILAMENT_FILESYSTEM_DISK` debe ser `s3`. Si no se define, las exportaciones del CRM se escriben en el disco de una réplica y las descargas desde otra réplica fallan. Nunca la apunte al bucket público: las exportaciones contienen datos de clientes.

### 3. Comandos de compilación y de despliegue

Cloud no instala pnpm, así que la compilación instala la versión que fija `package.json`. El almacenamiento en caché pertenece a la compilación: los cambios de archivos de un comando de despliegue no se conservan.

Comando de compilación:

```bash
composer install --no-dev --optimize-autoloader
npm install -g "$(node -p 'require("./package.json").packageManager')"
pnpm install --frozen-lockfile --ignore-scripts
pnpm run build
php artisan scribe:generate
php artisan optimize
php artisan filament:optimize
```

Comando de despliegue:

```bash
php artisan migrate --force
```

Un fork contiene `packages/Cloud`, el código propio de relaticle.com. Permanece fuera de la aplicación mientras `RELATICLE_CLOUD` no esté definida, así que deje esa variable fuera del entorno.

Cloud reinicia Horizon en cada despliegue, así que no hace falta un paso `horizon:terminate`.

### 4. Workers de cola y programador

Active el interruptor Scheduler en el clúster App. Los clústeres de workers requieren el plan Growth o superior. Agregue un clúster de workers, manténgalo activo en lugar de dejarlo en suspensión junto con el clúster App, y asígnele un proceso personalizado en segundo plano:

```bash
php artisan horizon
```

Cada réplica de worker ejecuta el conjunto completo de supervisores de Horizon. Ajuste la cantidad de procesos con `HORIZON_DEFAULT_MIN`, `HORIZON_DEFAULT_MAX`, `HORIZON_IMPORTS_MIN` y `HORIZON_IMPORTS_MAX`.

### 5. Crear el usuario administrador

Abra la pestaña Commands del entorno y ejecute el comando con cada valor pasado como opción. La pestaña Commands no puede responder a preguntas interactivas.

```bash
php artisan make:filament-user --name="Your Name" --email=you@example.com --password=your-secure-password
```

Acceda a su CRM en `https://your-app.laravel.cloud/app`.

---

## Actualización

### 1. Haga una copia de seguridad de sus datos

Haga siempre una copia de seguridad antes de actualizar. Consulte la sección Copia de seguridad y restauración más abajo.

### 2. Descargue las imágenes más recientes

```bash
docker compose pull
```

### 3. Reinicie los contenedores

```bash
docker compose up -d
```

Las migraciones de la base de datos se ejecutan automáticamente al iniciar. El contenedor de la aplicación aplicará las migraciones pendientes antes de atender tráfico.

### 4. Actualice el archivo compose

Un `compose.yml` descargado antes de octubre de 2026 no tiene un contenedor `reverb`. El chat funciona sin él, y las respuestas aparecen unos segundos después de guardarse. Para obtener la transmisión en vivo, descargue el archivo de nuevo e inicie la pila:

```bash
curl -o compose.yml https://raw.githubusercontent.com/Relaticle/relaticle/main/compose.yml
docker compose up -d
```

Copie primero las ediciones que haya hecho al archivo anterior. El archivo nuevo pasa las variables de los proveedores de IA desde `.env` a los contenedores, así que ya no hace falta agregar esas líneas a mano.

La imagen ahora usa Redis como caché de forma predeterminada, de modo que los contenedores web, Horizon y programador comparten una sola caché. El archivo anterior daba Redis solo al contenedor web, y el botón Detener del chat nunca llegaba al worker. Una pila que define `CACHE_STORE` por su cuenta conserva su propio valor.

**Nota**: Revise las [notas de la versión](https://github.com/Relaticle/relaticle/releases) antes de actualizar. Los cambios incompatibles se documentarán allí.

---

## Copia de seguridad y restauración

### Copia de seguridad de la base de datos

```bash
docker compose exec postgres pg_dump -U relaticle relaticle > backup-$(date +%Y%m%d).sql
```

### Restauración de la base de datos

```bash
docker compose exec -T postgres psql -U relaticle relaticle < backup-20260320.sql
```

### Copia de seguridad del almacenamiento

Haga una copia de seguridad del volumen de almacenamiento, que contiene los archivos subidos:

```bash
docker compose cp app:/var/www/html/storage/app ./storage-backup
```

### Copias de seguridad automáticas

Agregue una tarea cron para hacer una copia diaria:

```bash
0 3 * * * cd /path/to/relaticle && docker compose exec -T postgres pg_dump -U relaticle relaticle | gzip > /backups/relaticle-$(date +\%Y\%m\%d).sql.gz
```

---

## Despliegue manual

Si prefiere no usar Docker, puede desplegar Relaticle directamente en un servidor.

### Requisitos

- PHP 8.5+ con las extensiones: pdo_pgsql, gd, bcmath, mbstring, xml, redis
- PostgreSQL 17+
- Redis 7+
- Node.js 22+
- Composer 2+
- Nginx o Apache
- Supervisor (para los workers de cola)

### Instalación

1. Clone el repositorio:

```bash
git clone https://github.com/Relaticle/relaticle.git /var/www/relaticle
cd /var/www/relaticle
```

2. Instale las dependencias de PHP:

```bash
composer install --no-dev --optimize-autoloader
```

3. Configure el entorno:

```bash
cp .env.example .env
php artisan key:generate
```

Edite `.env` con las credenciales de su base de datos, la configuración de correo y `APP_URL`. Defina `APP_ENV=production` y `APP_DEBUG=false`. El archivo de ejemplo trae `local` y `true`, y las páginas de depuración en producción exponen su configuración.

Deje `RELATICLE_CLOUD` sin definir. `packages/Cloud` es la facturación, el sitio de marketing y las páginas de administración alojadas propias de relaticle.com. La imagen de Docker lo deja fuera, y un clon lo contiene. Con el interruptor sin definir, permanece fuera de la aplicación y de la compilación del frontend, así que su instalación nunca sirve el sitio de marketing de relaticle.com en `/`. Su licencia permite el uso para desarrollo y pruebas, por lo que el directorio permanece en el disco y `git pull` sigue funcionando.

4. Compile el frontend:

```bash
pnpm install --frozen-lockfile && pnpm run build
```

5. Prepare la base de datos:

```bash
php artisan migrate --force
php artisan storage:link
```

6. Defina los permisos:

```bash
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

7. Cree su usuario administrador:

```bash
php artisan make:filament-user
```

### Actualizar una instalación manual

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

`php artisan optimize` reconstruye las cachés de configuración y de rutas, que todavía nombran rutas que servía la versión anterior. Si almacena componentes en caché con `php artisan filament:optimize`, ejecútelo de nuevo después de `php artisan up`. Si PHP-FPM se ejecuta con `opcache.validate_timestamps=0`, recárguelo también.

Supervisor reinicia Horizon después de `horizon:terminate`. Revise las [notas de la versión](https://github.com/Relaticle/relaticle/releases) antes de actualizar.

### Configuración de Nginx

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

El bloque `/reverb/app/` lleva el websocket del chat a Reverb. En `.env`, defina `BROADCAST_CONNECTION=reverb`, `BROADCAST_REVERB_HOST=127.0.0.1` y `BROADCAST_REVERB_PORT=8080`, y deje `REVERB_HOST` vacío.

### Supervisor para Horizon

Cree `/etc/supervisor/conf.d/relaticle-horizon.conf`:

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

Luego inícielo:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start relaticle-horizon
```

### Supervisor para Reverb

Cree `/etc/supervisor/conf.d/relaticle-reverb.conf`:

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

Inícielo de la misma manera y luego ejecute `php artisan reverb:restart` después de cada actualización.

### Cron del programador

Agregue esto al crontab de `www-data`:

```bash
* * * * * cd /var/www/relaticle && php artisan schedule:run >> /dev/null 2>&1
```

---

## Solución de problemas

### Error "APP_KEY is required" al iniciar

La variable de entorno `APP_KEY` no está definida. Genere una:

```bash
echo "base64:$(openssl rand -base64 32)"
```

Agréguela a su archivo `.env` y reinicie:

```bash
docker compose up -d
```

### Bucles de reinicio del contenedor

Revise los registros:

```bash
docker compose logs app --tail 50
```

Causas frecuentes:
- Faltan variables de entorno obligatorias (`APP_KEY`, `DB_PASSWORD`)
- PostgreSQL aún no está listo (la aplicación espera una comprobación de estado, pero las configuraciones personalizadas pueden omitirla)
- Memoria insuficiente (se necesitan al menos 2 GB)

### Errores 500 después del despliegue

1. Active `APP_DEBUG=true` temporalmente para ver el error completo
2. Consulte los registros: `docker compose logs app --tail 100`
3. Asegúrese de que `APP_URL` coincida con su dominio real (incluido el esquema)
4. Ejecute `docker compose exec app php artisan optimize:clear` para limpiar todas las cachés

### El correo no se envía

1. Verifique que `MAIL_MAILER` esté definido como `smtp` (no `log`)
2. Compruebe que sus credenciales SMTP sean correctas
3. Pruebe con: `docker compose exec app php artisan tinker --execute "Mail::raw('Test', fn(\$m) => \$m->to('you@example.com')->subject('Test'))"`
4. Revise los registros: `docker compose logs app | grep -i mail`

### El chat se queda en "Pensando…"

Desde octubre de 2026, una respuesta aparece unos segundos después de guardarse incluso sin websocket. Si el chat sigue esperando, la respuesta no se está produciendo:

1. Compruebe que el trabajo se ejecutó: `docker compose logs horizon --tail 50` muestra `ProcessChatMessage` como `DONE`.
2. Compruebe que el modelo responde: `docker compose exec horizon php artisan chat:models --probe=<id>`.
3. Compruebe que todos los contenedores comparten la caché: `docker compose exec horizon php artisan config:show cache.default` imprime `redis`.

Para ver si las respuestas se transmiten en vivo, abra la pestaña Network del navegador, filtre por WS y busque una solicitud `/reverb/app/...` con estado 101. Si no hay ninguna, falta el contenedor `reverb` o su proxy inverso descarta el encabezado `Upgrade`.

### La página de inicio muestra el sitio de marketing de Relaticle

El paquete cloud está cargado, porque `RELATICLE_CLOUD` está definida en `.env` o en el entorno del servidor. Una instalación autoalojada redirige `/` a la aplicación. Quite la variable, ejecute `php artisan config:clear` (y `php artisan optimize` de nuevo si almacena la configuración en caché) y luego recompile el frontend con `pnpm run build`.

### Horizon no procesa trabajos

1. Compruebe el estado de Horizon: `docker compose exec app php artisan horizon:status`
2. Verifique que el contenedor de horizon esté en ejecución: `docker compose ps`
3. Revise los registros de horizon: `docker compose logs horizon --tail 50`
4. Reinicie Horizon: `docker compose restart horizon`

### Errores de permisos en storage

```bash
docker compose exec app chown -R www-data:www-data /var/www/html/storage
docker compose exec app chmod -R 775 /var/www/html/storage
```

### Conexión a la base de datos rechazada

1. Verifique que PostgreSQL esté en ejecución: `docker compose ps postgres`
2. Revise los registros de PostgreSQL: `docker compose logs postgres --tail 20`
3. Asegúrese de que `DB_PASSWORD` coincida entre los contenedores de la aplicación y de postgres (ambos leen la misma variable de `.env` de forma predeterminada)
