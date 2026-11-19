---
title: REST-API
description: Verbinden Sie sich mit der Relaticle-REST-API über ein persönliches Zugriffstoken, mit Berechtigungen, Ratenlimits, Upserts und Endpunkt-Referenz.
order: 3
updated: "2026-10-06"
---

Relaticle hat eine REST-API für Unternehmen, Personen, Verkaufschancen, Aufgaben, Notizen und benutzerdefinierte Attribute. Nutzen Sie sie, um Einträge mit einem anderen System abzugleichen oder eine eigene Integration zu bauen. Die [API-Referenz](/developers/api) listet jeden Endpunkt, jeden Parameter und jede Antwort. Die OpenAPI-Spezifikation finden Sie unter [/openapi.json](/openapi.json).

## Basis-URL

Relaticle Cloud stellt die API hier bereit:

```
https://api.relaticle.com/v1
```

Eine selbst gehostete Installation stellt sie unter `{APP_URL}/api/v1` bereit, oder auf einer eigenen Domain, wenn `API_DOMAIN` gesetzt ist.

## Authentifizierung

Jede Anfrage sendet ein persönliches Zugriffstoken als Bearer-Token:

```bash
curl https://api.relaticle.com/v1/companies \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

Erstellen Sie ein Token unter **Einstellungen > Zugriffstoken** in der App. Legen Sie fest, auf welchen Arbeitsbereich es zugreifen darf, wann es abläuft und welche Berechtigungen es hat. Dasselbe Token funktioniert auch für den [MCP-Server](/de/developers/mcp).

## Berechtigungen des Tokens

Ein Zugriffstoken hat eine oder mehrere Berechtigungen. Vier davon betreffen die REST-API, und jede Anfrage braucht die Berechtigung, die zu ihrer HTTP-Methode passt.

| Berechtigung | Erlaubt |
|---|---|
| `read` | `GET`-Anfragen |
| `create` | `POST`-Anfragen |
| `update` | `PUT`- und `PATCH`-Anfragen |
| `delete` | `DELETE`-Anfragen |

Eine Filterabfrage, `POST /v1/{resource}/query`, ist ein Lesezugriff. Sie braucht die Berechtigung `read`, nicht `create`.

Ein Zugriffstoken kann außerdem drei E-Mail-Berechtigungen haben: **E-Mails lesen**, **E-Mails entwerfen** und **E-Mails senden**. Die REST-API hat keinen E-Mail-Endpunkt. Diese Berechtigungen gelten deshalb nur für den [MCP-Server](/de/developers/mcp).

## Ressourcen

| Ressource | Pfad |
|---|---|
| Unternehmen | `/v1/companies` |
| Personen | `/v1/people` |
| Verkaufschancen | `/v1/opportunities` |
| Aufgaben | `/v1/tasks` |
| Notizen | `/v1/notes` |
| Benutzerdefinierte Attribute (Custom Fields) | `/v1/custom-fields` (nur lesen) |

Jede Eintragsressource unterstützt Auflisten, Erstellen, Lesen, Aktualisieren und Löschen. Die Antworten folgen [JSON:API](https://jsonapi.org/). Jeder Eintrag enthält die Werte seiner benutzerdefinierten Attribute unter `custom_fields`. Listenendpunkte nehmen Parameter für Filtern, Sortieren und Paginierung an. Die [API-Referenz](/developers/api) dokumentiert sie pro Endpunkt.

## Eine Liste filtern

Die Listenendpunkte für Unternehmen, Personen, Verkaufschancen, Aufgaben und Notizen nehmen ein `filter`-Objekt an. Es kann native Felder, benutzerdefinierte Attribute und verknüpfte Einträge mit `$and`, `$or` und `$not` kombinieren. Die vollständige Grammatik beschreibt der [MCP-Leitfaden](/de/developers/mcp). Der MCP-Server und Rela, der integrierte KI-Assistent, lesen denselben Filter.

`GET /v1/companies?filter[name][$contains]=Acme` sendet einen Filter im Query-String, als verschachtelte eckige Klammern. Der Query-String übermittelt jeden Wert als Text, und Relaticle wandelt ihn in den Typ des Feldes um.

Ein langer Filter kann das Längenlimit der URL überschreiten. Senden Sie ihn dann im Request-Body. Jede der fünf Eintragsressourcen hat einen `query`-Endpunkt:

```
POST /v1/companies/query
POST /v1/people/query
POST /v1/opportunities/query
POST /v1/tasks/query
POST /v1/notes/query
```

Der Body ist ein JSON-Objekt. Es akzeptiert `filter`, `sort`, `include`, `per_page`, `page` und `cursor`. Jeder Schlüssel ist optional, und ein leeres Objekt liefert die erste Seite der Einträge, standardmäßig 15. `include` ist ein kommagetrennter String oder eine Liste von Namen. Ein Body über 256 KB liefert `413`.

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

Die Antwort hat dieselbe Form wie die passende `GET`-Liste. Für die nächste Seite senden Sie denselben Body mit der nächsten `page`. Die `links`-URLs einer Antwort lassen sich nicht mit `GET` abrufen. Blättern Sie daher in einer Abfrage, indem Sie den Body erneut senden.

Bei einem langen Ergebnis blättern Sie mit einem Cursor statt mit `page`. Senden Sie für die erste Seite `"cursor": true`. Senden Sie danach denselben Body mit `cursor` auf `meta.next_cursor` aus der vorherigen Antwort. Eine Anfrage, die `page` und `cursor` zusammen sendet, liefert `422`, auch bei einer `GET`-Liste. Die letzte Seite hat den `next_cursor` `null`. Das Blättern per Cursor sortiert nach `name` (bei Aufgaben und Notizen `title`), `created_at` oder `updated_at`. Eine Sortierung nach einem benutzerdefinierten Attribut braucht `page`. Wer sie mit einem Cursor anfordert, erhält `400`. Ein Cursor, der weder `true` noch ein Wert von einer vorherigen Seite ist, liefert `422`. `GET`-Listen nehmen dieselben Werte als `?cursor=true` an.

Ein Datumsoperand ist `YYYY-MM-DD` oder ein ISO-8601-Datum mit Uhrzeit wie `2026-10-01T09:30:00Z`. Jedes andere Format liefert `422`. Ein Datum mit Uhrzeit und Offset, etwa `2026-10-01T13:00:00+05:00`, wird als derselbe Zeitpunkt in UTC gelesen. Ein Datum ohne Uhrzeit umfasst den ganzen UTC-Tag.

Ein Body, der kein JSON-Objekt ist, liefert `422`. Das gilt für abgeschnittenes JSON, einen einzelnen String oder eine einzelne Zahl und für einen Body, der ohne den Content-Type `application/json` gesendet wird. Ein leerer Body zählt als kein Filter. Das gilt auch für `{}` und für die leere Liste `[]`, die PHP für ein leeres Array sendet. Ein Schlüssel, den der Endpunkt nicht kennt, liefert `422` und nennt die akzeptierten Schlüssel.

Leerzeichen um einen Operanden werden ignoriert. Ein leerer Operand liefert `422`. Mit `$is_empty` finden Sie Einträge ohne Wert.

`sort` nimmt ein natives Feld oder ein benutzerdefiniertes Attribut an, das genau einen Wert enthält. Einträge ohne Wert für ein benutzerdefiniertes Attribut stehen in beiden Richtungen am Ende. Ein Feld mit einer Liste von Werten, etwa E-Mail, Telefon, Link oder Tags, liefert als Sortierung `400`.

## Wertformate

Relaticle speichert einige Werte benutzerdefinierter Attribute in einer einzigen Form, unabhängig davon, wie eine Anfrage sie schreibt. Ein Lesezugriff liefert die gespeicherte Form.

| Feld | Gespeichert als |
|---|---|
| Telefon | E.164, etwa `+14155550100`. Eine Durchwahl bleibt als `;ext=12` erhalten |
| Ein Domainfeld, etwa die `domains` eines Unternehmens | Der reine Host in Kleinbuchstaben, ohne Schema, `www.` und Pfad |
| Andere Links | Die URL, mit dem Host in Kleinbuchstaben |
| Datum und Uhrzeit | Der Zeitpunkt in UTC. `2026-10-01T13:00:00+05:00` wird als 08:00 UTC gespeichert |

Ein Telefon braucht eine Landesvorwahl. Ein Telefon ohne Landesvorwahl liefert `422`.

## Upsert: einen Eintrag finden oder erstellen

`POST /v1/people/upsert` und `POST /v1/companies/upsert` aktualisieren den Eintrag, der einen Wert bereits enthält, oder erstellen einen, wenn es keinen gibt. Nennen Sie ein benutzerdefiniertes Attribut, das als eindeutig markiert ist, und den gesuchten Wert:

```json
{
  "match": {"field": "emails", "value": "grace@navy.mil"},
  "name": "Grace Hopper"
}
```

Der Wert wird ohne Beachtung der Groß- und Kleinschreibung abgeglichen, auch innerhalb von Feldern mit mehreren Werten. Eine Domain stimmt mit oder ohne Schema oder `www.` überein. Die API liefert `201`, wenn sie einen Eintrag erstellt, und `200`, wenn sie einen aktualisiert. Enthalten mehrere Einträge den Wert, schreibt sie nichts und liefert `409` mit den passenden IDs. Ein Upsert braucht die Berechtigungen `create` und `update`.

## Ratenlimits der API

Jeder Arbeitsbereich kann 600 Anfragen pro Minute senden. Jedes Token kann 300 Lesezugriffe und 60 Schreibzugriffe pro Minute ausführen. Die API zählt eine Filterabfrage als Lesezugriff. Jede authentifizierte API-Antwort enthält `X-RateLimit-Limit` und `X-RateLimit-Remaining`. Bei Überschreitung eines Limits liefert die API `429` mit einem `Retry-After`-Header, der die Wartezeit in Sekunden angibt.

## Fehler

Fehler liefern JSON mit einer `message`. Validierungsfehler liefern `422` und fügen ein `errors`-Objekt hinzu, dessen Schlüssel die Feldnamen sind.

Ein Filterfehler ist ein `422`, dessen Schlüssel der Pfad des zu korrigierenden Teils ist. Auch ein unbekannter Filtername gehört dazu. Dieses Beispiel stammt aus einer Unternehmensliste, und die Meldung nennt die Namen, die Sie verwenden können:

```json
{
  "message": "Unknown filter stage. Use one of: name, created_at, updated_at, creation_source, creator, accountOwner, people, opportunities, custom_fields, $and, $or, $not.",
  "errors": {
    "filter.stage": ["Unknown filter stage. Use one of: name, created_at, updated_at, creation_source, creator, accountOwner, people, opportunities, custom_fields, $and, $or, $not."]
  }
}
```

Die flachen Filterparameter der früheren API, etwa `company_id`, `created_after` und `assignee_ids`, liefern ein `422`, das den Ersatz nennt. Ein unbekanntes `sort` oder `include` liefert weiterhin `400`.

## Tarife und Credits

In Relaticle Cloud gehört die REST-API zu Cloud Pro und zur Testphase. API-Anfragen verbrauchen nie KI-Credits. Nur Nachrichten an Rela, den integrierten Assistenten, tun das. Selbst gehostete Installationen enthalten die API ohne Kosten.
