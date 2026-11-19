---
title: API REST
description: Conéctese a la API REST de Relaticle con un token de acceso personal, permisos acotados, límites de uso, upserts y la referencia completa de endpoints.
order: 3
updated: "2026-10-06"
---

Relaticle tiene una API REST para empresas, personas, oportunidades, tareas, notas y atributos personalizados. Úsela para sincronizar registros con otro sistema o para crear su propia integración. La [referencia de la API](/developers/api) enumera cada endpoint, parámetro y respuesta, y la especificación OpenAPI está en [/openapi.json](/openapi.json).

## URL base

Relaticle Cloud sirve la API en:

```
https://api.relaticle.com/v1
```

Una instalación autoalojada la sirve en `{APP_URL}/api/v1`, o en su propio dominio cuando se define `API_DOMAIN`.

## Autenticación

Cada solicitud envía un token de acceso personal como token Bearer:

```bash
curl https://api.relaticle.com/v1/companies \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

Cree un token desde **Configuración > Tokens de acceso** en la aplicación. Elija el espacio de trabajo al que puede acceder, cuándo vence y sus permisos. El mismo token también funciona con el [MCP server](/es/developers/mcp).

## Permisos del token

Un token de acceso tiene uno o más permisos. Cuatro de ellos cubren la API REST, y cada solicitud necesita el permiso que corresponde a su método HTTP.

| Permiso | Permite |
|---|---|
| `read` | Solicitudes `GET` |
| `create` | Solicitudes `POST` |
| `update` | Solicitudes `PUT` y `PATCH` |
| `delete` | Solicitudes `DELETE` |

Una consulta con filtro, `POST /v1/{resource}/query`, es una lectura. Necesita el permiso `read`, no `create`.

Un token de acceso también puede tener tres permisos de correo: **Leer correo**, **Redactar borradores de correo** y **Enviar correo**. La API REST no tiene ningún endpoint de correo, así que estos permisos se aplican solo al [MCP server](/es/developers/mcp).

## Recursos

| Recurso | Ruta |
|---|---|
| Empresas | `/v1/companies` |
| Personas | `/v1/people` |
| Oportunidades | `/v1/opportunities` |
| Tareas | `/v1/tasks` |
| Notas | `/v1/notes` |
| Atributos personalizados (custom fields) | `/v1/custom-fields` (solo lectura) |

Cada recurso de registros admite listar, crear, leer, actualizar y eliminar. Las respuestas siguen [JSON:API](https://jsonapi.org/), y cada registro incluye los valores de sus atributos personalizados en `custom_fields`. Los endpoints de lista aceptan parámetros de filtrado, orden y paginación, que la [referencia de la API](/developers/api) documenta por endpoint.

## Filtrar una lista

Los endpoints de lista de empresas, personas, oportunidades, tareas y notas aceptan un objeto `filter`. Puede combinar campos nativos, atributos personalizados y registros vinculados con `$and`, `$or` y `$not`. La [guía de MCP](/es/developers/mcp) describe la gramática completa, y el MCP server y Rela, el asistente de IA integrado, leen el mismo filtro.

`GET /v1/companies?filter[name][$contains]=Acme` envía un filtro en la cadena de consulta, con corchetes anidados. La cadena de consulta transporta cada valor como texto, y Relaticle lo convierte al tipo del campo.

Un filtro largo puede superar el límite de longitud de la URL, así que envíelo en el cuerpo de la solicitud. Cada uno de los cinco recursos de registros tiene un endpoint `query`:

```
POST /v1/companies/query
POST /v1/people/query
POST /v1/opportunities/query
POST /v1/tasks/query
POST /v1/notes/query
```

El cuerpo es un objeto JSON. Acepta `filter`, `sort`, `include`, `per_page`, `page` y `cursor`. Todas las claves son opcionales, y un objeto vacío devuelve la primera página de registros, 15 de forma predeterminada. `include` es una cadena separada por comas o una lista de nombres. Un cuerpo de más de 256 KB devuelve `413`.

```bash
curl https://api.relaticle.com/v1/opportunities/query \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "filter": {
      "custom_fields": {"amount": {"$gte": 10000}},
      "company": {"custom_fields": {"icp": {"$eq": true}}}
    },
    "sort": "-created_at",
    "include": "company",
    "per_page": 25,
    "page": 1
  }'
```

La respuesta tiene la misma forma que la lista `GET` equivalente. Para obtener la página siguiente, envíe el mismo cuerpo con el siguiente `page`. Las URL de `links` de una respuesta no se pueden obtener con `GET`, así que para paginar una consulta vuelva a enviar el cuerpo.

Para un resultado largo, pagine con un cursor en lugar de `page`. Envíe `"cursor": true` para la primera página y luego envíe el mismo cuerpo con `cursor` igual a `meta.next_cursor` de la respuesta anterior. Una solicitud que envía `page` y `cursor` a la vez devuelve `422`, también en una lista `GET`. La última página tiene un `next_cursor` de `null`. La paginación con cursor ordena por `name` (`title` en tareas y notas), `created_at` o `updated_at`. Un orden por atributo personalizado necesita `page`, y pedirlo con un cursor devuelve `400`. Un cursor que no es `true` ni un valor de una página anterior devuelve `422`. Las listas `GET` aceptan los mismos valores como `?cursor=true`.

El operando de una fecha es `YYYY-MM-DD` o una fecha y hora ISO 8601, como `2026-10-01T09:30:00Z`. Cualquier otro formato devuelve `422`. Una fecha y hora con desfase, como `2026-10-01T13:00:00+05:00`, se interpreta como el mismo instante en UTC. Una fecha sin hora abarca todo ese día UTC.

Un cuerpo que no es un objeto JSON devuelve `422`. Eso incluye un JSON truncado, una cadena o un número sueltos, y un cuerpo enviado sin el tipo de contenido `application/json`. Un cuerpo vacío se toma como sin filtro. Lo mismo ocurre con `{}` y con una lista vacía `[]`, que es lo que PHP envía para un arreglo vacío. Una clave que el endpoint no acepta devuelve `422` y enumera las claves aceptadas.

Los espacios alrededor de un operando se ignoran. Un operando en blanco devuelve `422`. Use `$is_empty` para encontrar registros sin valor.

`sort` acepta un campo nativo o un atributo personalizado que contiene un solo valor. Los registros sin valor en un atributo personalizado quedan al final en ambos sentidos. Un campo que contiene una lista, como correo, teléfono, enlace o etiquetas, devuelve `400` como orden.

## Formatos de valor

Relaticle guarda algunos valores de atributos personalizados en una sola forma, sin importar cómo los escriba la solicitud. Una lectura devuelve la forma guardada.

| Campo | Se guarda como |
|---|---|
| Teléfono | E.164, como `+14155550100`. Una extensión se conserva como `;ext=12` |
| Un campo de dominio, como `domains` de una empresa | El host sin esquema, `www.` ni ruta, en minúsculas |
| Otros enlaces | La URL, con su host en minúsculas |
| Fecha y hora | El instante en UTC. `2026-10-01T13:00:00+05:00` se guarda como 08:00 UTC |

Un teléfono necesita un código de país. Un teléfono sin él devuelve `422`.

## Upsert: encontrar o crear un registro

`POST /v1/people/upsert` y `POST /v1/companies/upsert` actualizan el registro que ya tiene un valor, o crean uno cuando ninguno lo tiene. Indique un atributo personalizado marcado como único y el valor que se debe buscar:

```json
{
  "match": {"field": "emails", "value": "grace@navy.mil"},
  "name": "Grace Hopper"
}
```

El valor coincide sin distinguir mayúsculas de minúsculas, también dentro de campos de varios valores. Un dominio coincide con o sin esquema o `www.`. La API devuelve `201` cuando crea un registro y `200` cuando lo actualiza. Cuando más de un registro tiene el valor, no escribe nada y devuelve `409` con los ID coincidentes. Un upsert necesita los permisos `create` y `update`.

## Límites de uso de la API

Cada espacio de trabajo puede hacer 600 solicitudes por minuto. Cada token puede hacer 300 lecturas y 60 escrituras por minuto. La API cuenta una consulta con filtro como una lectura. Cada respuesta autenticada de la API incluye `X-RateLimit-Limit` y `X-RateLimit-Remaining`. Al superar un límite, la API devuelve `429` con un encabezado `Retry-After` que indica los segundos que debe esperar.

## Errores

Los errores devuelven JSON con un `message`. Los fallos de validación devuelven `422` y agregan un objeto `errors` con el nombre del campo como clave.

Un error de filtro es un `422` cuya clave es la ruta de la parte que debe corregir. Un nombre de filtro desconocido es uno de ellos. Este ejemplo viene de una lista de empresas, y el mensaje enumera los nombres que puede usar:

```json
{
  "message": "Unknown filter stage. Use one of: name, created_at, updated_at, creation_source, creator, accountOwner, people, opportunities, custom_fields, $and, $or, $not.",
  "errors": {
    "filter.stage": ["Unknown filter stage. Use one of: name, created_at, updated_at, creation_source, creator, accountOwner, people, opportunities, custom_fields, $and, $or, $not."]
  }
}
```

Los parámetros de filtro planos de la API anterior, como `company_id`, `created_after` y `assignee_ids`, devuelven un `422` que indica el reemplazo. Un `sort` o `include` desconocido sigue devolviendo `400`.

## Planes y créditos

En Relaticle Cloud la API REST forma parte de Cloud Pro y de la prueba. Las solicitudes a la API nunca consumen créditos de IA: solo lo hacen los mensajes a Rela, el asistente integrado. Las instalaciones autoalojadas incluyen la API sin costo.
