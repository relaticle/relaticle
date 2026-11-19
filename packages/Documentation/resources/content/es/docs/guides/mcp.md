---
title: MCP server
description: Consulte la referencia del MCP server de Relaticle, con OAuth, tokens de acceso personal, acceso a atributos personalizados y escrituras directas.
order: 2
updated: "2026-11-09"
---

MCP (Model Context Protocol) permite que asistentes de IA como Claude trabajen directamente con los datos de su CRM en Relaticle. En lugar de copiar y pegar entre herramientas, su asistente de IA puede listar empresas, crear tareas, actualizar personas y más, todo desde una conversación natural.

---

## Qué puede hacer

Con el MCP server de Relaticle, su asistente de IA puede:

- **Listar y buscar** empresas, personas, oportunidades, tareas y notas
- **Obtener un registro** con todos sus detalles y relaciones
- **Crear registros nuevos** directamente desde una conversación
- **Actualizar registros existentes**: renombrar una empresa, reasignar una tarea
- **Eliminar registros** que ya no necesite
- **Asociar o desasociar** tareas y notas de empresas, personas y oportunidades
- **Leer los esquemas de las entidades** para entender sus atributos personalizados
- **Obtener una visión general del CRM** con conteos de registros y actividad reciente

Las escrituras hechas por MCP se aplican de inmediato. La tarjeta de aprobación pertenece al asistente integrado en Relaticle, y un cliente MCP depende de sus propias confirmaciones. Hay herramientas de correo para una conexión a la que se dio acceso al correo. Las reuniones no se exponen. Un correo enviado es la única escritura que no se aplica de inmediato: primero se retiene y la persona que usa el agente puede cancelarlo, en Relaticle o mediante el agente que lo envió.

---

## Conectarse a Relaticle

Los clientes con compatibilidad con OAuth solo necesitan el endpoint MCP. ChatGPT, Claude y Claude Code abren la pantalla de consentimiento de Relaticle y le permiten elegir un espacio de trabajo.

Los clientes sin compatibilidad con OAuth necesitan un token de acceso personal:

1. Inicie sesión en Relaticle
2. Haga clic en su avatar en la esquina superior derecha
3. Seleccione **Tokens de acceso**
4. Haga clic en **Crear** y póngale un nombre a su token
5. Copie el token: no se volverá a mostrar

El token limita su acceso al espacio de trabajo que seleccione al crearlo. Todas las operaciones MCP usan los datos de ese espacio de trabajo.

---

## Autenticación

El MCP server de Relaticle admite dos métodos de autenticación:

### OAuth 2.1 (recomendado para usuarios finales)

El endpoint MCP anuncia los metadatos de OAuth en:

- `https://mcp.relaticle.com/.well-known/oauth-authorization-server`
- `https://mcp.relaticle.com/.well-known/oauth-protected-resource`

Los clientes que admiten Dynamic Client Registration (RFC 7591), incluidos Claude.ai, Claude Desktop, Claude Code y los conectores personalizados de ChatGPT, se registran solos y lo guían por un flujo de consentimiento de un clic. PKCE es obligatorio (`S256`). Un cliente puede enviar un parámetro `resource` (RFC 8707). Debe nombrar este MCP server, o la solicitud se rechaza con `invalid_target`.

En el consentimiento elige **un espacio de trabajo** para el conector. Esa elección es permanente para ese conector: para dirigirlo a otro espacio de trabajo, revóquelo y conéctelo de nuevo. Los espacios de trabajo en pausa no se pueden seleccionar. Suscríbase primero, o el conector no tendría datos que leer.

La pantalla de consentimiento enumera lo que el conector podrá hacer, y la lista sigue su rol en el espacio de trabajo que elija. Donde el correo está activado, todos los roles obtienen lectura y redacción de borradores, y los roles de propietario, administrador y miembro obtienen el envío. El cliente no puede pedir más ni menos. Relaticle muestra esta pantalla cada vez que un conector se autoriza, así que el conector siempre tiene lo que vio la persona que lo autorizó. Cada llamada a una herramienta también comprueba su rol en ese momento, así que un cambio de rol se aplica a los conectores que agregó antes.

Los tokens de acceso duran 30 días y los tokens de actualización 90 días. Los clientes compatibles renuevan los tokens en silencio, en segundo plano.

### Revocar un conector

**Configuración → Tokens de acceso → Conectores de IA** enumera cada asistente que ha conectado, el espacio de trabajo al que está vinculado cada uno y un botón **Revocar**. Revocar invalida de inmediato los tokens de acceso y de actualización del conector. Los correos que puso en cola y que aún no han salido no se envían. Pasan a la bandeja de salida, en **Fallidos**, donde usted mismo puede enviarlos con **Reintentar**.

### Tokens de acceso personal (recomendados para herramientas de desarrollo)

Para Cursor, VS Code, MCP Inspector o cualquier cliente sin compatibilidad con OAuth, cree un token de acceso personal desde la configuración de su cuenta y páselo como `Authorization: Bearer YOUR_TOKEN`.

Un token obtiene acceso al correo mediante sus permisos: **Leer correo**, **Redactar borradores de correo** y **Enviar correo**. Un token nuevo no tiene ninguno hasta que marque uno.

---

## Configuración por cliente

El endpoint del MCP server es `https://mcp.relaticle.com`. ChatGPT, Claude y Claude Code usan OAuth. Los demás ejemplos usan un token de acceso personal.

### ChatGPT

1. Abra el [plugin de Relaticle](https://chatgpt.com/plugins/plugin_asdk_app_6a92c3af04a0819180ed6652ebe09961) en el directorio de plugins de ChatGPT y haga clic en **Install plugin**.
2. Inicie sesión en Relaticle y elija un espacio de trabajo.
3. Empiece un mensaje con **@Relaticle**.

### Claude y Claude Desktop

Claude en la web y la aplicación Claude Desktop comparten los mismos conectores.

1. Abra **Customize → Connectors**.
2. Seleccione el botón de más y luego **Add custom connector**.
3. Ingrese `Relaticle` y `https://mcp.relaticle.com`.
4. Conecte, inicie sesión en Relaticle y elija un espacio de trabajo.

El archivo `claude_desktop_config.json` de Claude Desktop es para servidores locales. Agregue Relaticle como conector, como describe la guía de Anthropic para [agregar un conector por URL](https://claude.com/docs/connectors/custom/add-unlisted).

### Claude Code

Agregue el servidor y luego inicie sesión:

```bash
claude mcp add relaticle --transport http https://mcp.relaticle.com
claude mcp login relaticle
```

`claude mcp login` abre la pantalla de consentimiento en su navegador, donde elige un espacio de trabajo. También puede ejecutar `/mcp` dentro de una sesión. Claude Code guarda por sí mismo los tokens de OAuth, así que su configuración solo contiene la URL del servidor.

Para usar un token de acceso personal en su lugar, haga referencia a él mediante una variable de entorno:

```bash
claude mcp add relaticle \
  --transport http \
  https://mcp.relaticle.com \
  --header 'Authorization: Bearer ${RELATICLE_TOKEN}'
```

Un token escrito en el encabezado se guarda en texto plano en `~/.claude.json`. Con `--scope project` va a `.mcp.json` en la raíz de su repositorio, que Claude Code comparte mediante el control de versiones.

### Cursor con un token de acceso personal

Agregue esto a la configuración MCP de Cursor (`.cursor/mcp.json`):

```json
{
  "mcpServers": {
    "relaticle": {
      "type": "streamable-http",
      "url": "https://mcp.relaticle.com",
      "headers": {
        "Authorization": "Bearer YOUR_TOKEN"
      }
    }
  }
}
```

### VS Code con un token de acceso personal

Agregue esto a la configuración de VS Code (`.vscode/mcp.json`):

```json
{
  "servers": {
    "relaticle": {
      "type": "streamable-http",
      "url": "https://mcp.relaticle.com",
      "headers": {
        "Authorization": "Bearer YOUR_TOKEN"
      }
    }
  }
}
```

---

## Herramientas disponibles

El servidor ofrece 50 herramientas. Cubren el contexto de la cuenta, el descubrimiento entre entidades, el análisis del espacio de trabajo, CRUD completo en cinco entidades del CRM, la administración de relaciones, la subida de archivos, la lectura del correo sincronizado, la administración de borradores de correo y el envío y la programación de correo. Una conexión sin acceso al correo ve 39 herramientas.

### Descubrimiento entre entidades

| Herramienta | Descripción |
|------|-------------|
| `search` | Busca en empresas, personas, oportunidades, tareas y notas. Devuelve URL canónicas para citar. |
| `fetch` | Obtiene un registro por su URL canónica. Se combina con `search` para la integración Company Knowledge de ChatGPT. |

### Cuenta

| Herramienta | Descripción |
|------|-------------|
| `who-ami-tool` | Obtiene al usuario autenticado con su zona horaria, su espacio de trabajo actual, los miembros del espacio de trabajo y los permisos del token |

### Inteligencia del espacio de trabajo

| Herramienta | Descripción |
|------|-------------|
| `get-crm-schema-tool` | Obtiene el esquema activo, los atributos personalizados, los filtros y las relaciones de un tipo de entidad |
| `get-crm-summary-tool` | Obtiene los conteos de registros, los totales del pipeline por etapa y el estado de vencimiento de las tareas en su zona horaria |
| `aggregate-opportunities-tool` | Agrupa los conteos y montos de las oportunidades por etapa o empresa, con límites de fecha opcionales |
| `list-activity-tool` | Lista los cambios recientes del CRM con sus autores, enlaces a los registros y diferencias por campo. Una cuenta eliminada aparece como `Former member` |
| `list-custom-fields-tool` | Lista las definiciones de atributos personalizados (custom fields) activas e inactivas, incluidas las opciones de selección |

### Empresas

| Herramienta | Descripción |
|------|-------------|
| `list-companies-tool` | Lista empresas con filtro, orden y paginación opcionales |
| `get-company-tool` | Obtiene una empresa por ID con todos sus detalles y relaciones |
| `create-company-tool` | Crea una empresa nueva (requiere `name`) |
| `update-company-tool` | Actualiza una empresa por ID |
| `delete-company-tool` | Elimina una empresa por ID (eliminación lógica) |

### Personas

| Herramienta | Descripción |
|------|-------------|
| `list-people-tool` | Lista personas con filtro, orden y paginación opcionales |
| `get-people-tool` | Obtiene una persona por ID con todos sus detalles y relaciones |
| `create-people-tool` | Crea una persona nueva (requiere `name`, `company_id` opcional) |
| `update-people-tool` | Actualiza una persona por ID |
| `delete-people-tool` | Elimina una persona por ID (eliminación lógica) |

### Oportunidades

| Herramienta | Descripción |
|------|-------------|
| `list-opportunities-tool` | Lista oportunidades con filtro, orden y paginación opcionales |
| `get-opportunity-tool` | Obtiene una oportunidad por ID con todos sus detalles y relaciones |
| `create-opportunity-tool` | Crea una oportunidad nueva (requiere `name`, `company_id` y `contact_id` opcionales) |
| `update-opportunity-tool` | Actualiza una oportunidad por ID |
| `delete-opportunity-tool` | Elimina una oportunidad por ID (eliminación lógica) |

### Tareas

| Herramienta | Descripción |
|------|-------------|
| `list-tasks-tool` | Lista tareas con filtro, orden y paginación opcionales |
| `get-task-tool` | Obtiene una tarea por ID con todos sus detalles y relaciones |
| `create-task-tool` | Crea una tarea nueva (requiere `title`) |
| `update-task-tool` | Actualiza una tarea por ID |
| `delete-task-tool` | Elimina una tarea por ID (eliminación lógica) |
| `attach-task-to-entities-tool` | Vincula una tarea a empresas, personas u oportunidades, o asigna usuarios. Agrega sin quitar los vínculos existentes. |
| `detach-task-from-entities-tool` | Desvincula una tarea de empresas, personas u oportunidades, o quita la asignación de usuarios |

### Notas

| Herramienta | Descripción |
|------|-------------|
| `list-notes-tool` | Lista notas con filtro, orden y paginación opcionales |
| `get-note-tool` | Obtiene una nota por ID con todos sus detalles y relaciones |
| `create-note-tool` | Crea una nota nueva (requiere `title`) |
| `update-note-tool` | Actualiza una nota por ID |
| `delete-note-tool` | Elimina una nota por ID (eliminación lógica) |
| `attach-note-to-entities-tool` | Vincula una nota a empresas, personas u oportunidades. Agrega sin quitar los vínculos existentes. |
| `detach-note-from-entities-tool` | Desvincula una nota de empresas, personas u oportunidades |

### Correo electrónico

Estas herramientas aparecen solo para una conexión a la que se dio acceso al correo. Las herramientas de lectura muestran el correo sincronizado que su usuario puede ver, con el nivel de uso compartido que eligió el propietario del buzón, además de los borradores y la bandeja de salida del propio usuario. Las herramientas de buzón, borrador, envío y bandeja de salida trabajan sobre los buzones y correos del propio usuario.

Las dos herramientas de lectura necesitan acceso de lectura. La herramienta de buzón aparece para una conexión con permiso de redactar o enviar, y las tres herramientas de borrador para una con permiso de redactar. Las herramientas de envío, cancelación, reprogramación y reintento necesitan el permiso de envío y un rol que pueda enviar mediante un asistente. Un envío programado nunca sale antes de la retención. Un correo va a un máximo de 20 destinatarios en total entre Para, CC y CCO. Un usuario puede tener hasta 10 correos del asistente en espera a la vez.

Un correo en cola sigue ligado a la conexión que lo puso en cola. No se envía si esa conexión se revoca, se elimina o vence antes de la hora de envío. Lo mismo ocurre si el rol de su usuario ya no permite enviar mediante un asistente. Entonces espera en la bandeja de salida, en **Fallidos**, con el motivo.

| Herramienta | Descripción |
|------|-------------|
| `list-emails-tool` | Lista los correos del más reciente al más antiguo, con búsqueda y filtros opcionales por registro vinculado, dirección, conversación, estado y fecha. El correo de un compañero aparece una vez sincronizado o enviado, y también aparecen los borradores y la bandeja de salida del propio usuario. El asunto y el fragmento aparecen solo donde el nivel de uso compartido lo permite. |
| `get-email-tool` | Obtiene un correo por ID, con su estado y su hora de envío. El cuerpo y los nombres de los adjuntos aparecen solo con acceso completo, y un cuerpo largo se recorta. |
| `list-email-accounts-tool` | Lista los buzones que el usuario ha conectado en este espacio de trabajo, con el ID que se usa al redactar y si cada uno puede enviar. |
| `create-email-draft-tool` | Guarda un borrador en uno de los buzones del usuario, como texto plano o markdown, con la firma predeterminada salvo que la desactive. No se envía nada: el usuario revisa el borrador en Relaticle. |
| `update-email-draft-tool` | Reescribe un borrador guardado con los mismos argumentos que para crearlo. Los archivos adjuntos se conservan. |
| `delete-email-draft-tool` | Elimina un borrador guardado y sus archivos adjuntos. |
| `send-email-tool` | Envía un correo desde uno de los buzones del usuario, ahora o a la hora elegida. Cada correo espera unos minutos antes de salir, y el usuario puede cancelarlo desde las notificaciones o la Bandeja de salida. |
| `send-email-draft-tool` | Envía un borrador guardado tal como está, incluidas las ediciones del propio usuario, y lo quita de Borradores. Se retiene como cualquier otro envío. |
| `cancel-email-tool` | Cancela un correo en cola antes de que salga. |
| `reschedule-email-tool` | Cambia cuándo sale un correo en cola. El usuario recibe un aviso nuevo desde el que puede cancelar. |
| `retry-email-tool` | Vuelve a poner en cola un correo fallido, retenido como un envío nuevo. Queda entonces ligado a la conexión que lo reintentó. |

### Archivos

| Herramienta | Descripción |
|------|-------------|
| `upload-file` | Almacena un archivo en el espacio de trabajo desde un cuerpo base64 con `filename` o un `upload_id`. Devuelve `suggested_markdown` para incrustar el archivo en un campo de editor de texto enriquecido, como el cuerpo de una nota, además de `file_id` para el archivo almacenado. Tipos permitidos: pdf, doc, docx, xlsx, pptx, jpg, jpeg, png, gif, webp, de hasta 10 MB. 60 llamadas por hora por espacio de trabajo. |
| `create-upload-url` | Obtiene una URL firmada, válida cinco minutos, a la que enviar un cuerpo de archivo con `PUT`. Luego se pasa el `upload_id` devuelto a `upload-file` dentro de una hora. Un cuerpo no almacenado para entonces se elimina. 60 llamadas por hora por espacio de trabajo. |

Las subidas quedan pendientes durante 24 horas. Guardar un registro cuyo cuerpo de editor de texto enriquecido incrusta el archivo lo reclama. Los archivos no reclamados se purgan.

Las herramientas de lista de entidades aceptan `filter`, `sort`, `include`, `per_page` (15 por defecto, máximo 25) y `page`. `sort` es un objeto con `field` y `direction` (`asc` o `desc`). `include` es una lista de relaciones en singular o de conteos de relaciones. Una herramienta de lista rechaza cualquier otro argumento. Para encontrar registros por palabras en todas las entidades, use la herramienta `search`.

`sort` acepta un campo nativo o un atributo personalizado que guarde un solo valor. Un campo que guarda una lista, como correo, teléfono, enlace o etiquetas, no se puede ordenar.

Las respuestas de lista incluyen `page`, `per_page`, `total`, `has_more` y `next_page`. Las herramientas de creación y actualización aceptan `custom_fields` como pares clave-valor. Una escritura guarda un teléfono en formato E.164, un dominio como host simple y una fecha y hora como su instante UTC. Una fecha sin hora escrita en un atributo de fecha y hora se guarda como el inicio de ese día en la zona horaria del usuario del token. Una hora sin desfase se sigue leyendo como UTC.

### Filtrar registros

Pase `filter` a una herramienta de lista para acotar los registros que devuelve. Un filtro es un objeto JSON. La [API REST](/es/developers/rest-api) y Rela, el asistente de IA integrado, leen el mismo filtro.

Cada clave de un filtro es una de estas:

| Clave | Significado |
|---|---|
| `$and`, `$or`, `$not` | Lógica |
| `$eq`, `$in`, `$contains` y los demás operadores | Una prueba sobre el campo o la relación que los rodea |
| Un campo nativo como `name` o `created_at` | Una condición sobre una de las columnas propias del registro |
| Una relación como `company` o `assignees` | Una condición sobre registros vinculados |
| `custom_fields` | Condiciones sobre sus atributos personalizados, con el código del campo como clave |
| Un subcampo como `domain` | Una parte de un valor de correo o de enlace |

Una clave que empieza con `$` es una palabra clave. Toda otra clave es un nombre. No hay forma abreviada: `{"stage": "Won"}` es un error, y también lo es un operador sin su `$`.

Los campos nativos y las relaciones van en el nivel superior. Los atributos personalizados van bajo `custom_fields`, así que un atributo personalizado con el código `name` nunca choca con el `name` nativo.

#### Campos nativos y relaciones

| Entidad | Nombres de filtro |
|---|---|
| Empresas | Campos nativos `name`, `created_at`, `updated_at`, `creation_source`. Relaciones de registro `people`, `opportunities`. Relaciones de miembro `creator`, `accountOwner`. |
| Personas | Campos nativos `name`, `created_at`, `updated_at`, `creation_source`. Relación de registro `company`. Relación de miembro `creator`. |
| Oportunidades | Campos nativos `name`, `created_at`, `updated_at`, `creation_source`, `stale_days`. Relaciones de registro `company`, `contact`. Relación de miembro `creator`. |
| Tareas | Campos nativos `title`, `created_at`, `updated_at`, `creation_source`, `assigned_to_me`. Relaciones de registro `companies`, `people`, `opportunities`. Relaciones de miembro `creator`, `assignees`. |
| Notas | Campos nativos `title`, `created_at`, `updated_at`, `creation_source`. Relaciones de registro `companies`, `people`, `opportunities`. Relación de miembro `creator`. |

`name` y `title` aceptan los operadores de texto. `created_at` y `updated_at` aceptan los operadores de fecha y hora. Un operando de fecha es `YYYY-MM-DD` o una fecha y hora ISO 8601 como `2026-10-01T09:30:00Z`, y cualquier otro formato es un error. Una fecha y hora con desfase, como `2026-10-01T13:00:00+05:00`, se lee como el mismo instante en UTC. En estos campos y en un atributo personalizado de fecha y hora, una fecha sin hora cubre ese día completo en la zona horaria del usuario del token, que devuelve la herramienta de identidad. `creation_source` es una selección única con los valores `web`, `sample`, `import`, `api`, `mcp`, `chat`, `mailbox` y `system`. `stale_days` coincide con oportunidades por días completos sin actividad, como en `{"$gte": 30}`. `assigned_to_me` acepta `{"$eq": true}`.

#### Combinar condiciones

- Las claves de un mismo objeto se combinan con AND.
- `$or` recibe una lista de objetos de condición y devuelve los registros que cumplen cualquiera de ellos.
- `$and` también recibe una lista. Úselo cuando un objeto necesitaría dos claves `$or`.
- `$not` recibe un objeto de condición. Devuelve todos los registros que ese objeto no devuelve, incluidos los registros donde el campo está vacío.
- Una lista `$and` o `$or` vacía, un objeto `$not` vacío y un nodo de relación vacío son errores. Un filtro vacío significa que no hay filtro.

#### Filtrar mediante una relación

Un nodo de relación contiene la misma gramática de filtro, aplicada a los registros relacionados. Los nombres dentro de él pertenecen a la entidad relacionada.

- Las relaciones de registro aceptan `$in`, `$not_in` (IDs de registro) y `$is_empty`, o condiciones sobre el registro relacionado.
- Las relaciones de miembro aceptan `$in`, `$not_in` (IDs de miembro) y `$is_empty`. Los nombres y correos de los miembros no se pueden filtrar. Quien dejó el espacio de trabajo ya no cuenta como miembro, así que un registro que creó coincide con `{"creator": {"$is_empty": true}}`.
- Una relación de varios registros coincide cuando al menos un registro relacionado coincide. Todas las condiciones de un mismo nodo de relación se aplican al mismo registro relacionado. Para encontrar registros sin ninguno, envuelva el nodo en `$not`.
- Las relaciones se anidan como máximo 2 niveles, como `company` y luego `people`.

#### Operadores por tipo de campo

Los campos nativos aceptan los operadores del tipo de campo con el que coinciden. `$is_empty` acepta `true` o `false`.

| Tipo de campo | Operadores |
|---|---|
| Selección única (selección, opciones excluyentes, botones de alternancia), `creation_source` | `$eq`, `$in`, `$not_in`, `$is_empty` |
| Selección múltiple (selección múltiple, lista de casillas), etiquetas, registro | `$has_any`, `$has_none`, `$is_empty` |
| Correo electrónico, teléfono, enlace, dominio | `$has_any`, `$has_none`, `$contains`, `$starts_with`, `$ends_with`, `$is_empty` |
| Texto | `$eq`, `$contains`, `$starts_with`, `$ends_with`, `$is_empty` |
| Número, moneda, fecha, fecha y hora | `$eq`, `$gt`, `$gte`, `$lt`, `$lte`, `$is_empty` |
| Casilla, interruptor | `$eq`, `$is_empty` |

Los campos de correo y de enlace también aceptan un subcampo `domain` con `$in` y `$not_in`. Coincide con el dominio de cada valor, así que `{"domain": {"$in": ["canva.com"]}}` encuentra todas las direcciones de `canva.com`. Un dominio es un host simple. Una ruta, un usuario o un puerto es un error.

Los campos de área de texto, texto enriquecido y color no aceptan filtro. Tampoco un campo cifrado o inactivo.

#### Cómo coinciden los valores

- Los valores de selección aceptan la etiqueta de la opción o su ID. Una etiqueta desconocida devuelve un error con las etiquetas válidas. Una etiqueta ambigua pide el ID de la opción.
- Los valores de correo y de enlace coinciden sin distinguir mayúsculas de minúsculas. Un enlace también coincide con o sin su esquema, así que `acme.com/team` encuentra `https://acme.com/team`.
- Los valores de dominio coinciden como host simple o como URL completa, con o sin `www.`, así que `https://www.acme.com/about` encuentra `acme.com`.
- Los valores de teléfono coinciden en cualquier formato. Un operando de teléfono necesita un código de país, como `+1 415 555 0100`. Un operando sin él devuelve el error `phone_number needs a country code, for example +1 415 555 0100.`
- Las etiquetas coinciden con el valor almacenado exacto.
- Un campo de registro acepta los IDs de los registros a los que se vincula.
- `$contains`, `$starts_with` y `$ends_with` ignoran las mayúsculas y minúsculas. En un campo con varios valores, coinciden cuando cualquier valor coincide. Comparan el operando tal como se escribió con el valor tal como está almacenado. Un teléfono se almacena como `+14155550100`, con una extensión como `;ext=12` y sin espacios, así que `+1415` coincide y `+1 415` no. Un enlace conserva un esquema solo cuando se escribió uno, así que `https://acme` coincide con `https://acme.com/team` y `acme` no. Un dominio se almacena como host simple, sin esquema ni `www.`.
- `$not_in` y `$has_none` también coinciden con los registros donde el campo está vacío.
- El vínculo de un campo de registro con un registro eliminado equivale a no tener vínculo.

#### Límites

Un filtro contiene como máximo 20 condiciones, contadas como cada operador a cualquier profundidad. Anida `$and`, `$or` y `$not` como máximo 3 niveles y las relaciones como máximo 2 niveles. Una lista contiene como máximo 100 valores.

#### Ejemplos

Oportunidades de al menos 10,000 que están en Proposal/Price Quote o Negotiation/Review. La condición fuera de `$or` se combina con él mediante AND.

```json
{
  "custom_fields": {"amount": {"$gte": 10000}},
  "$or": [
    {"custom_fields": {"stage": {"$eq": "Proposal/Price Quote"}}},
    {"custom_fields": {"stage": {"$eq": "Negotiation/Review"}}}
  ]
}
```

Oportunidades de empresas con el interruptor `icp` activado. `company` es una relación, así que su nodo contiene condiciones sobre la empresa, incluidos sus atributos personalizados.

```json
{"company": {"custom_fields": {"icp": {"$eq": true}}}}
```

Personas con una dirección de correo en un dominio. Funciona igual con un campo de enlace.

```json
{"custom_fields": {"emails": {"domain": {"$in": ["canva.com"]}}}}
```

Las etiquetas de etapa y los códigos de campo pertenecen a su espacio de trabajo. Llame a `get-crm-schema-tool` para leer los suyos.

#### Leer el vocabulario de filtros

`get-crm-schema-tool` devuelve `filterable_fields` para un tipo de entidad. Tiene tres partes:

- Cada campo nativo y relación por nombre, con su `type`, sus `operators` y un `example`. Las relaciones agregan la `entity` relacionada. `creation_source` agrega sus `values`. Las relaciones, las relaciones de miembro, `stale_days` y `assigned_to_me` agregan un `operand` que indica lo que aceptan.
- `types` asigna cada tipo de atributo personalizado presente en su espacio de trabajo a sus `operators`, a su regla `matching`, a su regla `partial_matching` cuando la tiene y a cualquier `sub_fields`. Un tipo cuyos valores se escriben libremente también lleva un `example`, y un tipo de selección lo lleva en el campo.
- `custom_fields` enumera cada atributo personalizado filtrable por código, con su `name` y su `type`. Un campo de selección también lleva sus `options` y un `example` construido con ellas.

#### Errores de filtro

Un filtro no válido devuelve un error que nombra la parte que hay que corregir. Por ejemplo, un código de atributo personalizado desconocido devuelve `"stagee" is not a filterable custom attribute (custom field) on opportunity. Available: amount, close_date, stage.` Un operador sin `$` devuelve `Operators start with $. Use $eq.`

Los nombres planos del filtro anterior ya no existen. Dentro de `filter`, claves como `search`, `created_after`, `company_id` y `assignee_ids` devuelven un error que nombra el reemplazo, por ejemplo `company_id was replaced. Use company (or companies) with $in.` Los mismos nombres enviados junto a `filter` devuelven ese error también.

---

## Recursos de esquema

El servidor expone cinco recursos de esquema que describen los campos de cada entidad, incluidos los atributos personalizados que haya configurado su espacio de trabajo, además de un recurso de resumen con los conteos de registros:

| URI del recurso | Descripción |
|---|---|
| `relaticle://schema/company` | Campos y atributos personalizados de la empresa |
| `relaticle://schema/people` | Campos y atributos personalizados de la persona |
| `relaticle://schema/opportunity` | Campos y atributos personalizados de la oportunidad |
| `relaticle://schema/task` | Campos y atributos personalizados de la tarea |
| `relaticle://schema/note` | Campos y atributos personalizados de la nota |

La compatibilidad con recursos varía según el cliente MCP. Use `get-crm-schema-tool` antes de escribir un atributo personalizado (custom field) cuando su cliente no exponga los recursos automáticamente.

---

## Prompt CRM Overview

El servidor incluye un prompt integrado llamado **CRM Overview** que le da a su asistente de IA una instantánea de los datos de su CRM: los conteos de registros de cada entidad y las empresas y personas creadas recientemente. Es un gran punto de partida para cualquier conversación.

---

## Prompts de ejemplo

Una vez conectado, pruebe estos en su asistente de IA:

- "Liste todas mis empresas"
- "Cree una empresa nueva llamada Acme Corp"
- "Muéstreme las personas de la empresa X"
- "Cree una tarea para dar seguimiento a John la próxima semana"
- "Deme una visión general de mi CRM"
- "Cambie el nombre de la empresa X a Y"
- "Elimine la tarea con el ID abc-123"

---

## Solución de problemas

### Error "Unauthorized" o 401

Es posible que su token de acceso haya vencido o no sea válido. Cree uno nuevo desde **Configuración > Tokens de acceso**.

### No se devuelven datos

El MCP server limita todos los datos al espacio de trabajo asociado a su token. Asegúrese de que el token se creó para el espacio de trabajo correcto y de que el espacio de trabajo tiene datos.

### Conexión rechazada

Verifique que la URL de MCP sea correcta: `https://mcp.relaticle.com`.

### Los atributos personalizados no aparecen

Los atributos personalizados son específicos de cada espacio de trabajo. Si no los ve, confirme que están configurados para su espacio de trabajo en **Configuración > Atributos personalizados**. Luego llame a `get-crm-schema-tool` para el tipo de entidad.

### Límite de solicitudes

Las solicitudes de herramientas MCP están limitadas a 120 por minuto por usuario autenticado. Los endpoints de autorización de OAuth están limitados a 20 por minuto por dirección IP.
