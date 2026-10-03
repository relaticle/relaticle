---
title: REST API
description: Connect to the Relaticle REST API with a personal access token, scoped permissions, rate limits, upserts and the full endpoint reference.
order: 3
updated: "2026-10-04"
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

A filter query, `POST /v1/{resource}/query`, is a read. It needs the `read` permission, not `create`.

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

## Filter a list

The list endpoints for companies, people, opportunities, tasks and notes take a `filter` object. It can combine native fields, custom fields and linked records with `$and`, `$or` and `$not`. The [MCP guide](/developers/mcp) describes the full grammar, and the MCP server and the in-app assistant read the same filter.

`GET /v1/companies?filter[name][$contains]=Acme` sends a filter in the query string, as nested brackets. The query string carries every value as text, and Relaticle converts it to the type of the field.

A long filter can outgrow the URL length limit, so send it in a request body instead. Each of the five record resources has a `query` endpoint:

```
POST /v1/companies/query
POST /v1/people/query
POST /v1/opportunities/query
POST /v1/tasks/query
POST /v1/notes/query
```

The body is a JSON object. It takes `filter`, `sort`, `include`, `per_page` and `page`. Every key is optional, and an empty object returns the first page of records, 15 by default.

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

The response has the same shape as the matching `GET` list. To fetch the next page, send the same body with the next `page`. The `links` URLs in a response cannot be fetched with `GET`, so page a query by sending the body again.

A body that is not a JSON object returns `422`. That includes truncated JSON, a bare string or number, and a body sent without the `application/json` content type. An empty body counts as no filter.

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

Each workspace can make 600 requests a minute. Each token can make 300 reads and 60 writes a minute. The API counts a filter query as a read. Every authenticated API response carries `X-RateLimit-Limit` and `X-RateLimit-Remaining`. Over a limit, the API returns `429` with a `Retry-After` header giving the seconds to wait.

## Errors

Errors return JSON with a `message`. Validation failures return `422` and add an `errors` object keyed by field name.

A filter error is a `422` keyed by the path of the part to fix. An unknown filter name is one of them. This example comes from a company list, and the message lists the names you can use:

```json
{
  "message": "Unknown filter stage. Use one of: name, created_at, updated_at, creation_source, creator, accountOwner, people, opportunities, custom_fields, $and, $or, $not.",
  "errors": {
    "filter.stage": ["Unknown filter stage. Use one of: name, created_at, updated_at, creation_source, creator, accountOwner, people, opportunities, custom_fields, $and, $or, $not."]
  }
}
```

The flat filter parameters of the earlier API, such as `company_id`, `created_after` and `assignee_ids`, return a `422` that names the replacement. An unknown `sort` or `include` is still a `400`.

## Plans and credits

On Relaticle Cloud the REST API is part of Cloud Pro and the trial. API requests never spend AI credits: only messages to the built-in assistant do. Self-hosted installs include the API at no cost.
