# Documentation package

The content and the engine behind Relaticle's help centre and developer guides. This package renders no pages. The website that does lives in `packages/Cloud`, which self-hosted builds do not contain.

## What it holds

| Piece | Role |
|---|---|
| `resources/content/{docs,help}` | The Markdown articles, one file per page |
| `Support\DocsRepository` | Reads the content into `DocPage` and `DocCategory` objects and caches the manifest |
| `Support\BuildSearchIndex` | One search record per `##` heading. Rela, the built-in AI assistant, reads it through `SearchDocsTool`, and the website search reads it too |
| `Support\DocUrl` | The one place that maps a content path onto a public URL |
| `Support\HeadingAnchors` | The heading ids the renderer and the search index share |
| `routes/web.php` | `/openapi.json` and `/openapi.yaml`, served from the spec Scribe generates |

## Content

Every article is `{area}/{category}/{slug}.md`, where the area is `docs` or `help`. A category describes itself in its own `_index.md`.

A page needs `title`, `description` and `order` in its front matter. It may add `updated` and `related`. A category's `_index.md` may add `feature`, the name of a class in `App\Features`, to hide the category when that flag is off.

Never put a bare `: ` inside an unquoted front matter value. It throws a parse error that breaks every page.

## URLs

`DocUrl` builds absolute links from `documentation.public_url`. The default is `https://relaticle.com`, because a self-hosted install has no docs website of its own. Rela, the panel's help links and the access tokens page all link through it.

## Configuration

`config/documentation.php` sets four keys. The provider registers the `documentation-config` and `documentation-content` publish tags only while `RELATICLE_FEATURE_DOCUMENTATION` is on. Published content lands in `resources/content/documentation`, which `content_path` never reads, so point `content_path` there as well.

| Key | Env | Role |
|---|---|---|
| `content_path` | | Root of the Markdown tree |
| `public_url` | `DOCUMENTATION_PUBLIC_URL` | Site that serves the pages |
| `cache.enabled` | `DOCUMENTATION_CACHE_ENABLED` | Cache the manifest and the search index. `app.debug` always bypasses it |
| `api_reference` | | The REST API reference link, which Scribe generates |

`RELATICLE_FEATURE_DOCUMENTATION` gates the OpenAPI routes.

## Tests

`php artisan test --compact tests/Feature/Documentation` covers the repository, the content integrity and the OpenAPI routes. The rendered pages are tested in `packages/Cloud/tests/Feature/Docs`.

## License

Covered by the repository's [AGPL-3.0 license](../../LICENSE).
