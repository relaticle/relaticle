---
title: API REST
description: Connectez-vous à l'API REST de Relaticle avec un jeton d'accès personnel, des permissions, des limites de débit, des upserts et la référence des endpoints.
order: 3
updated: "2026-10-06"
---

Relaticle propose une API REST pour les entreprises, les personnes, les opportunités, les tâches, les notes et les attributs personnalisés. Utilisez-la pour synchroniser des enregistrements avec un autre système ou pour créer votre propre intégration. La [référence de l'API](/developers/api) liste chaque endpoint, chaque paramètre et chaque réponse, et la spécification OpenAPI se trouve à l'adresse [/openapi.json](/openapi.json).

## URL de base

Relaticle Cloud sert l'API à l'adresse suivante :

```
https://api.relaticle.com/v1
```

Une installation auto-hébergée la sert à `{APP_URL}/api/v1`, ou sur son propre domaine lorsque `API_DOMAIN` est défini.

## Authentification

Chaque requête envoie un jeton d'accès personnel comme jeton Bearer :

```bash
curl https://api.relaticle.com/v1/companies \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

Créez un jeton depuis **Paramètres > Jetons d'accès** dans l'application. Choisissez l'espace de travail auquel il donne accès, sa date d'expiration et ses permissions. Le même jeton fonctionne aussi pour le [MCP server](/fr/developers/mcp).

## Permissions des jetons

Un jeton d'accès porte une ou plusieurs permissions. Quatre d'entre elles couvrent l'API REST, et chaque requête exige la permission qui correspond à sa méthode HTTP.

| Permission | Autorise |
|---|---|
| `read` | Les requêtes `GET` |
| `create` | Les requêtes `POST` |
| `update` | Les requêtes `PUT` et `PATCH` |
| `delete` | Les requêtes `DELETE` |

Une requête de filtre, `POST /v1/{resource}/query`, est une lecture. Elle exige la permission `read`, pas `create`.

Un jeton d'accès peut aussi porter trois permissions d'e-mail : **Lire les e-mails**, **Rédiger des brouillons d'e-mail** et **Envoyer des e-mails**. L'API REST n'a aucun endpoint d'e-mail, donc ces permissions s'appliquent uniquement au [MCP server](/fr/developers/mcp).

## Ressources

| Ressource | Chemin |
|---|---|
| Entreprises | `/v1/companies` |
| Personnes | `/v1/people` |
| Opportunités | `/v1/opportunities` |
| Tâches | `/v1/tasks` |
| Notes | `/v1/notes` |
| Attributs personnalisés (custom fields) | `/v1/custom-fields` (lecture seule) |

Chaque ressource d'enregistrement prend en charge la liste, la création, la lecture, la mise à jour et la suppression. Les réponses suivent la norme [JSON:API](https://jsonapi.org/), et chaque enregistrement porte les valeurs de ses attributs personnalisés sous `custom_fields`. Les endpoints de liste acceptent des paramètres de filtrage, de tri et de pagination, que la [référence de l'API](/developers/api) documente pour chaque endpoint.

## Filtrer une liste

Les endpoints de liste des entreprises, des personnes, des opportunités, des tâches et des notes acceptent un objet `filter`. Il peut combiner des champs natifs, des attributs personnalisés et des enregistrements liés avec `$and`, `$or` et `$not`. Le [guide MCP](/fr/developers/mcp) décrit la grammaire complète, et le MCP server comme Rela, l'assistant IA intégré, lisent le même filtre.

`GET /v1/companies?filter[name][$contains]=Acme` envoie un filtre dans la chaîne de requête, sous forme de crochets imbriqués. La chaîne de requête transporte chaque valeur sous forme de texte, et Relaticle la convertit dans le type du champ.

Un long filtre peut dépasser la limite de longueur de l'URL. Envoyez-le alors dans le corps de la requête. Chacune des cinq ressources d'enregistrement a un endpoint `query` :

```
POST /v1/companies/query
POST /v1/people/query
POST /v1/opportunities/query
POST /v1/tasks/query
POST /v1/notes/query
```

Le corps est un objet JSON. Il accepte `filter`, `sort`, `include`, `per_page`, `page` et `cursor`. Chaque clé est facultative, et un objet vide renvoie la première page d'enregistrements, 15 par défaut. `include` est une chaîne séparée par des virgules ou une liste de noms. Un corps de plus de 256 Ko renvoie `413`.

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

La réponse a la même forme que la liste `GET` correspondante. Pour obtenir la page suivante, renvoyez le même corps avec la valeur suivante de `page`. Les URL `links` d'une réponse ne peuvent pas être appelées avec `GET`. Pour parcourir les pages d'une requête, renvoyez donc le corps.

Pour un long résultat, paginez avec un curseur à la place de `page`. Envoyez `"cursor": true` pour la première page, puis renvoyez le même corps avec `cursor` défini sur `meta.next_cursor` de la réponse précédente. Une requête qui envoie à la fois `page` et `cursor` renvoie `422`, y compris sur une liste `GET`. La dernière page a un `next_cursor` égal à `null`. La pagination par curseur trie par `name` (`title` pour les tâches et les notes), `created_at` ou `updated_at`. Un tri sur un attribut personnalisé exige `page`, et en demander un avec un curseur renvoie `400`. Un curseur qui n'est ni `true` ni une valeur issue d'une page précédente renvoie `422`. Les listes `GET` acceptent les mêmes valeurs avec `?cursor=true`.

Un opérande de date s'écrit `YYYY-MM-DD` ou sous forme de date et heure ISO 8601, comme `2026-10-01T09:30:00Z`. Tout autre format renvoie `422`. Une date et heure avec décalage, comme `2026-10-01T13:00:00+05:00`, est lue comme le même instant en UTC. Une date sans heure couvre toute cette journée UTC.

Un corps qui n'est pas un objet JSON renvoie `422`. C'est le cas d'un JSON tronqué, d'une chaîne ou d'un nombre seul, et d'un corps envoyé sans l'en-tête de type de contenu `application/json`. Un corps vide équivaut à l'absence de filtre. Il en va de même pour `{}` et pour une liste vide `[]`, que PHP envoie pour un tableau vide. Une clé que l'endpoint n'accepte pas renvoie `422` et liste les clés acceptées.

Les espaces autour d'un opérande sont ignorés. Un opérande vide renvoie `422`. Utilisez `$is_empty` pour trouver les enregistrements sans valeur.

`sort` accepte un champ natif ou un attribut personnalisé qui contient une seule valeur. Les enregistrements sans valeur pour un attribut personnalisé arrivent en dernier dans les deux sens. Un champ qui contient une liste, comme l'e-mail, le téléphone, le lien ou les tags, renvoie `400` quand on trie dessus.

## Formats des valeurs

Relaticle stocke certaines valeurs d'attributs personnalisés sous une seule forme, quelle que soit l'écriture envoyée par la requête. Une lecture renvoie la forme stockée.

| Champ | Stocké sous la forme |
|---|---|
| Téléphone | E.164, comme `+14155550100`. Un numéro de poste est conservé sous la forme `;ext=12` |
| Un champ de domaine, comme `domains` pour une entreprise | Le nom d'hôte seul en minuscules, sans schéma, sans `www.` et sans chemin |
| Autres liens | L'URL, avec son nom d'hôte en minuscules |
| Date et heure | L'instant en UTC. `2026-10-01T13:00:00+05:00` est stocké comme 08:00 UTC |

Un numéro de téléphone exige un indicatif de pays. Un numéro sans indicatif renvoie `422`.

## Upsert : trouver ou créer un enregistrement

`POST /v1/people/upsert` et `POST /v1/companies/upsert` mettent à jour l'enregistrement qui contient déjà une valeur, ou en créent un quand aucun ne la contient. Indiquez un attribut personnalisé marqué comme unique et la valeur à chercher :

```json
{
  "match": {"field": "emails", "value": "grace@navy.mil"},
  "name": "Grace Hopper"
}
```

La valeur est comparée sans tenir compte de la casse, y compris à l'intérieur des champs à valeurs multiples. Un domaine correspond avec ou sans schéma ou `www.`. L'API renvoie `201` quand elle crée un enregistrement et `200` quand elle en met un à jour. Quand plusieurs enregistrements contiennent la valeur, elle n'écrit rien et renvoie `409` avec les identifiants correspondants. Un upsert exige les permissions `create` et `update`.

## Limites de débit de l'API

Chaque espace de travail peut effectuer 600 requêtes par minute. Chaque jeton peut effectuer 300 lectures et 60 écritures par minute. L'API compte une requête de filtre comme une lecture. Chaque réponse authentifiée de l'API porte `X-RateLimit-Limit` et `X-RateLimit-Remaining`. Au-delà d'une limite, l'API renvoie `429` avec un en-tête `Retry-After` qui indique le nombre de secondes à attendre.

## Erreurs

Les erreurs renvoient du JSON avec un `message`. Les échecs de validation renvoient `422` et ajoutent un objet `errors` indexé par nom de champ.

Une erreur de filtre est un `422` indexé par le chemin de la partie à corriger. Un nom de filtre inconnu en fait partie. Cet exemple vient d'une liste d'entreprises, et le message liste les noms que vous pouvez utiliser :

```json
{
  "message": "Unknown filter stage. Use one of: name, created_at, updated_at, creation_source, creator, accountOwner, people, opportunities, custom_fields, $and, $or, $not.",
  "errors": {
    "filter.stage": ["Unknown filter stage. Use one of: name, created_at, updated_at, creation_source, creator, accountOwner, people, opportunities, custom_fields, $and, $or, $not."]
  }
}
```

Les paramètres de filtre à plat de l'ancienne API, comme `company_id`, `created_after` et `assignee_ids`, renvoient un `422` qui nomme leur remplaçant. Un `sort` ou un `include` inconnu reste un `400`.

## Offres et crédits

Sur Relaticle Cloud, l'API REST fait partie de Cloud Pro et de l'essai. Les requêtes API ne dépensent jamais de crédits d'IA : seuls les messages envoyés à Rela, l'assistant intégré, en dépensent. Les installations auto-hébergées incluent l'API sans frais.
