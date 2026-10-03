---
title: REST API
description: Connect to the Relaticle REST API with a personal access token, scoped permissions, rate limits, upserts and the full endpoint reference.
order: 3
updated: "2026-10-02"
---

Relaticle has a REST API for companies, people, opportunities, tasks, notes and custom fields. Use it to sync records with another system or to build your own integration. The [API reference](/developers/api) lists every endpoint, parameter and response, and the OpenAPI spec is at [/openapi.json](/openapi.json).

## Base URL

Relaticle Cloud serves the API at:

```
https://api.relaticle.com/v1
```

A self-hosted install serves it at `{APP_URL}/api/v1`, or on its own domain when `API_DOMAIN` is set.

## Authentication

Every request sends a personal access token as a Bearer token:

```bash
curl https://api.relaticle.com/v1/companies \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

Create a token from **Settings > Access Tokens** in the app. Choose the workspace it can reach, when it expires, and its permissions. The same token also works for the [MCP server](/developers/mcp).

## Permissions

A token carries one or more of four permissions. Each request needs the permission that matches its HTTP method.

| Permission | Allows |
|---|---|
| `read` | `GET` requests |
| `create` | `POST` requests |
| `update` | `PUT` and `PATCH` requests |
| `delete` | `DELETE` requests |

## Resources

| Resource | Path |
|---|---|
| Companies | `/v1/companies` |
| People | `/v1/people` |
| Opportunities | `/v1/opportunities` |
| Tasks | `/v1/tasks` |
| Notes | `/v1/notes` |
| Custom fields | `/v1/custom-fields` (read only) |

Each record resource supports list, create, read, update and delete. Responses follow [JSON:API](https://jsonapi.org/), and each record carries its custom field values under `custom_fields`. List endpoints take filtering, sorting and pagination parameters, which the [API reference](/developers/api) documents per endpoint.

## Upsert: find or create a record

`POST /v1/people/upsert` and `POST /v1/companies/upsert` update the record that already holds a value, or create one when none does. Name a custom field marked unique and the value to look for:

```json
{
  "match": {"field": "emails", "value": "grace@navy.mil"},
  "name": "Grace Hopper"
}
```

The value matches case-insensitively, including inside multi-value fields. The API returns `201` when it creates a record and `200` when it updates one. When more than one record holds the value, it writes nothing and returns `409` with the matching IDs. An upsert needs both the `create` and `update` permissions.

## API rate limits

Each workspace can make 600 requests a minute. Each token can make 300 reads and 60 writes a minute. Every authenticated response carries `X-RateLimit-Limit` and `X-RateLimit-Remaining`. Over a limit, the API returns `429` with a `Retry-After` header giving the seconds to wait.

## Errors

Errors return JSON with a `message`. Validation failures return `422` and add an `errors` object keyed by field name.

## Plans and credits

On Relaticle Cloud the REST API is part of Cloud Pro and the trial. API requests never spend AI credits: only messages to the built-in assistant do. Self-hosted installs include the API at no cost.
