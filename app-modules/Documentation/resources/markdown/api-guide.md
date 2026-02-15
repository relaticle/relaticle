# API Reference

> **Coming Soon** — The REST API is under active development.

---

## Overview

Relaticle will provide a comprehensive REST API for integrating with external applications and building custom workflows.

---

## Planned Features

| Feature | Description |
|---------|-------------|
| **Authentication** | API tokens via Laravel Sanctum |
| **CRUD Endpoints** | Companies, People, Opportunities, Tasks, Notes |
| **Filtering** | Query parameters for filtering and searching |
| **Pagination** | Cursor-based pagination for large datasets |
| **Rate Limiting** | Fair usage limits per token |

---

## Planned Endpoints

```
GET    /api/companies
POST   /api/companies
GET    /api/companies/{id}
PUT    /api/companies/{id}
DELETE /api/companies/{id}

GET    /api/people
POST   /api/people
GET    /api/people/{id}
PUT    /api/people/{id}
DELETE /api/people/{id}

GET    /api/opportunities
POST   /api/opportunities
GET    /api/opportunities/{id}
PUT    /api/opportunities/{id}
DELETE /api/opportunities/{id}

GET    /api/tasks
POST   /api/tasks
GET    /api/tasks/{id}
PUT    /api/tasks/{id}
DELETE /api/tasks/{id}

GET    /api/notes
POST   /api/notes
GET    /api/notes/{id}
PUT    /api/notes/{id}
DELETE /api/notes/{id}
```

---

## Stay Updated

Follow the repository for release announcements:

[github.com/Relaticle/relaticle](https://github.com/Relaticle/relaticle)

---

## Have Suggestions?

Open an issue on GitHub to share your API requirements and use cases.
