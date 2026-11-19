---
title: MCP-Server
description: Referenz zum MCP-Server von Relaticle mit Einrichtung per OAuth und persönlichem Zugriffstoken, Zugriff auf benutzerdefinierte Attribute und direktem Schreiben.
order: 2
updated: "2026-11-09"
---

MCP (Model Context Protocol) lässt KI-Assistenten wie Claude direkt mit Ihren Relaticle-CRM-Daten arbeiten. Statt zwischen Tools zu kopieren und einzufügen, kann Ihr KI-Assistent Unternehmen auflisten, Aufgaben erstellen, Personen aktualisieren und mehr, alles in einer natürlichen Unterhaltung.

---

## Was Sie tun können

Mit dem Relaticle-MCP-Server kann Ihr KI-Assistent:

- Unternehmen, Personen, Verkaufschancen, Aufgaben und Notizen **auflisten und durchsuchen**
- **Einen einzelnen Eintrag abrufen** mit allen Details und Beziehungen
- **Neue Einträge erstellen**, direkt aus einer Unterhaltung heraus
- **Bestehende Einträge aktualisieren**, etwa ein Unternehmen umbenennen oder eine Aufgabe neu zuweisen
- **Einträge löschen**, die Sie nicht mehr brauchen
- Aufgaben und Notizen mit Unternehmen, Personen und Verkaufschancen **verknüpfen oder die Verknüpfung lösen**
- **Entitätsschemata lesen**, um Ihre benutzerdefinierten Attribute zu verstehen
- **Einen CRM-Überblick abrufen** mit Anzahl der Einträge und jüngster Aktivität

Schreibvorgänge über MCP werden sofort angewendet. Die Genehmigungskarte gehört zum Assistenten, der in Relaticle integriert ist, und ein MCP-Client verlässt sich auf seine eigenen Rückfragen. E-Mail-Tools gibt es für eine Verbindung, die E-Mail-Zugriff erhalten hat. Meetings werden nicht bereitgestellt. Eine gesendete E-Mail ist der einzige Schreibvorgang, der nicht sofort angewendet wird: Sie wird zunächst zurückgehalten, und ihr Benutzer kann sie abbrechen, in Relaticle oder über den Agenten, der sie gesendet hat.

---

## Mit Relaticle verbinden

Clients mit OAuth-Unterstützung brauchen nur den MCP-Endpunkt. ChatGPT, Claude und Claude Code öffnen den Zustimmungsbildschirm von Relaticle und lassen Sie einen Arbeitsbereich wählen.

Clients ohne OAuth-Unterstützung brauchen ein persönliches Zugriffstoken:

1. Melden Sie sich bei Relaticle an
2. Klicken Sie oben rechts auf Ihren Avatar
3. Wählen Sie **Zugriffstoken**
4. Klicken Sie auf **Erstellen** und geben Sie Ihrem Token einen Namen
5. Kopieren Sie das Token. Es wird nicht noch einmal angezeigt

Das Token beschränkt Ihren Zugriff auf den Arbeitsbereich, den Sie beim Erstellen wählen. Alle MCP-Vorgänge nutzen die Daten dieses Arbeitsbereichs.

---

## Authentifizierung

Der MCP-Server von Relaticle unterstützt zwei Authentifizierungsmethoden:

### OAuth 2.1 (empfohlen für Endnutzer)

Der MCP-Endpunkt macht OAuth-Metadaten verfügbar unter:

- `https://mcp.relaticle.com/.well-known/oauth-authorization-server`
- `https://mcp.relaticle.com/.well-known/oauth-protected-resource`

Clients, die Dynamic Client Registration (RFC 7591) unterstützen, darunter Claude.ai, Claude Desktop, Claude Code und eigene ChatGPT-Konnektoren, registrieren sich automatisch und führen Sie durch eine Zustimmung mit einem Klick. PKCE ist erforderlich (`S256`). Ein Client darf einen Parameter `resource` senden (RFC 8707). Er muss diesen MCP-Server benennen, sonst wird die Anfrage mit `invalid_target` abgelehnt.

Bei der Zustimmung wählen Sie **einen Arbeitsbereich** für den Konnektor. Diese Wahl gilt dauerhaft für diesen Konnektor: Um ihn auf einen anderen Arbeitsbereich zu richten, widerrufen Sie ihn und verbinden ihn neu. Pausierte Arbeitsbereiche lassen sich nicht auswählen. Schließen Sie zuerst ein Abonnement ab, sonst hätte der Konnektor keine Daten zum Lesen.

Der Zustimmungsbildschirm listet auf, was der Konnektor künftig tun darf, und die Liste richtet sich nach Ihrer Rolle in dem Arbeitsbereich, den Sie wählen. Wo E-Mail aktiv ist, erhält jede Rolle Lesen und Entwerfen, und die Rollen Inhaber, Administrator und Mitglied erhalten Senden. Der Client kann nicht mehr oder weniger anfordern. Relaticle zeigt diesen Bildschirm bei jeder Autorisierung eines Konnektors, sodass der Konnektor immer das hält, was sein Benutzer gesehen hat. Jeder Tool-Aufruf prüft außerdem Ihre Rolle im jeweiligen Moment, sodass eine Rollenänderung auch für Konnektoren gilt, die Sie früher hinzugefügt haben.

Zugriffstoken gelten 30 Tage und Aktualisierungstoken 90 Tage. Unterstützte Clients aktualisieren sie im Hintergrund unbemerkt.

### Einen Konnektor widerrufen

**Einstellungen → Zugriffstoken → KI-Konnektoren** listet jeden Assistenten auf, den Sie verbunden haben, den Arbeitsbereich, an den er jeweils gebunden ist, und eine Schaltfläche **Widerrufen**. Das Widerrufen macht die Zugriffs- und Aktualisierungstoken des Konnektors sofort ungültig. E-Mails, die er eingeplant hat und die noch nicht versendet wurden, werden nicht gesendet. Sie wandern in den Postausgang unter **Fehlgeschlagen**, wo Sie sie mit **Wiederholen** selbst senden können.

### Persönliche Zugriffstoken (empfohlen für Entwickler-Tools)

Für Cursor, VS Code, den MCP Inspector oder jeden Client ohne OAuth-Unterstützung erstellen Sie in Ihren Kontoeinstellungen ein persönliches Zugriffstoken und übergeben es als `Authorization: Bearer YOUR_TOKEN`.

Ein Token erhält E-Mail-Zugriff über seine Berechtigungen: **E-Mails lesen**, **E-Mails entwerfen** und **E-Mails senden**. Ein neues Token hat keine davon, bis Sie eine ankreuzen.

---

## Einrichtung je Client

Der Endpunkt des MCP-Servers ist `https://mcp.relaticle.com`. ChatGPT, Claude und Claude Code nutzen OAuth. Die übrigen Beispiele nutzen ein persönliches Zugriffstoken.

### ChatGPT

1. Öffnen Sie das [Relaticle-Plugin](https://chatgpt.com/plugins/plugin_asdk_app_6a92c3af04a0819180ed6652ebe09961) im Plugin-Verzeichnis von ChatGPT und klicken Sie auf **Install plugin**.
2. Melden Sie sich bei Relaticle an und wählen Sie einen Arbeitsbereich.
3. Beginnen Sie eine Nachricht mit **@Relaticle**.

### Claude und Claude Desktop

Claude im Web und die App Claude Desktop nutzen dieselben Konnektoren.

1. Öffnen Sie **Customize → Connectors**.
2. Wählen Sie die Plus-Schaltfläche, dann **Add custom connector**.
3. Geben Sie `Relaticle` und `https://mcp.relaticle.com` ein.
4. Verbinden Sie, melden Sie sich bei Relaticle an und wählen Sie einen Arbeitsbereich.

Die Datei `claude_desktop_config.json` von Claude Desktop ist für lokale Server gedacht. Fügen Sie Relaticle stattdessen als Konnektor hinzu, wie es die Anleitung von Anthropic zum [Hinzufügen eines Konnektors per URL](https://claude.com/docs/connectors/custom/add-unlisted) beschreibt.

### Claude Code

Fügen Sie den Server hinzu und melden Sie sich dann an:

```bash
claude mcp add relaticle --transport http https://mcp.relaticle.com
claude mcp login relaticle
```

`claude mcp login` öffnet den Zustimmungsbildschirm in Ihrem Browser, wo Sie einen Arbeitsbereich wählen. Sie können auch `/mcp` in einer Sitzung ausführen. Claude Code speichert die OAuth-Token selbst, seine Konfiguration enthält also nur die Server-URL.

Um stattdessen ein persönliches Zugriffstoken zu nutzen, verweisen Sie über eine Umgebungsvariable darauf:

```bash
claude mcp add relaticle \
  --transport http \
  https://mcp.relaticle.com \
  --header 'Authorization: Bearer ${RELATICLE_TOKEN}'
```

Ein Token, das Sie in den Header tippen, wird im Klartext in `~/.claude.json` gespeichert. Mit `--scope project` landet es in `.mcp.json` im Stammverzeichnis Ihres Repositorys, das Claude Code über die Versionskontrolle teilt.

### Cursor mit einem persönlichen Zugriffstoken

Fügen Sie dies Ihrer MCP-Konfiguration in Cursor hinzu (`.cursor/mcp.json`):

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

### VS Code mit einem persönlichen Zugriffstoken

Fügen Sie dies Ihren VS-Code-Einstellungen hinzu (`.vscode/mcp.json`):

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

## Verfügbare Tools

Der Server stellt 50 Tools bereit. Sie decken Kontokontext, entitätsübergreifende Suche, Analyse des Arbeitsbereichs, vollständiges CRUD für fünf CRM-Entitäten, Beziehungsverwaltung, Datei-Uploads, das Lesen synchronisierter E-Mails, die Verwaltung von E-Mail-Entwürfen sowie das Senden und Terminieren von E-Mails ab. Eine Verbindung ohne E-Mail-Zugriff sieht 39 Tools.

### Entitätsübergreifende Suche

| Tool | Beschreibung |
|------|--------------|
| `search` | Durchsucht Unternehmen, Personen, Verkaufschancen, Aufgaben und Notizen. Liefert kanonische URLs zum Zitieren. |
| `fetch` | Ruft einen einzelnen Eintrag über seine kanonische URL ab. Mit `search` kombinieren, für die ChatGPT-Integration Company Knowledge. |

### Konto

| Tool | Beschreibung |
|------|--------------|
| `who-ami-tool` | Liefert den authentifizierten Benutzer mit Zeitzone, aktuellem Arbeitsbereich, Mitgliedern des Arbeitsbereichs und Token-Berechtigungen |

### Analyse des Arbeitsbereichs

| Tool | Beschreibung |
|------|--------------|
| `get-crm-schema-tool` | Liefert das aktive Schema, die benutzerdefinierten Attribute, Filter und Beziehungen für einen Entitätstyp |
| `get-crm-summary-tool` | Liefert Anzahl der Einträge, Pipeline-Summen nach Phase und den Fälligkeitsstatus von Aufgaben in Ihrer Zeitzone |
| `aggregate-opportunities-tool` | Gruppiert Anzahl und Beträge von Verkaufschancen nach Phase oder Unternehmen, optional mit Datumsgrenzen |
| `list-activity-tool` | Listet jüngste CRM-Änderungen mit Akteuren, Links zu Einträgen und Unterschieden auf Attributebene. Ein gelöschtes Konto erscheint als `Former member` |
| `list-custom-fields-tool` | Listet aktive und inaktive Definitionen benutzerdefinierter Attribute (Custom Fields) auf, einschließlich der Auswahloptionen |

### Unternehmen

| Tool | Beschreibung |
|------|--------------|
| `list-companies-tool` | Listet Unternehmen mit optionalem Filter, Sortierung und Paginierung auf |
| `get-company-tool` | Ruft ein einzelnes Unternehmen per ID mit allen Details und Beziehungen ab |
| `create-company-tool` | Erstellt ein neues Unternehmen (erfordert `name`) |
| `update-company-tool` | Aktualisiert ein Unternehmen per ID |
| `delete-company-tool` | Löscht ein Unternehmen per ID mit Soft Delete |

### Personen

| Tool | Beschreibung |
|------|--------------|
| `list-people-tool` | Listet Personen mit optionalem Filter, Sortierung und Paginierung auf |
| `get-people-tool` | Ruft eine einzelne Person per ID mit allen Details und Beziehungen ab |
| `create-people-tool` | Erstellt eine neue Person (erfordert `name`, optional `company_id`) |
| `update-people-tool` | Aktualisiert eine Person per ID |
| `delete-people-tool` | Löscht eine Person per ID mit Soft Delete |

### Verkaufschancen

| Tool | Beschreibung |
|------|--------------|
| `list-opportunities-tool` | Listet Verkaufschancen mit optionalem Filter, Sortierung und Paginierung auf |
| `get-opportunity-tool` | Ruft eine einzelne Verkaufschance per ID mit allen Details und Beziehungen ab |
| `create-opportunity-tool` | Erstellt eine neue Verkaufschance (erfordert `name`, optional `company_id`, `contact_id`) |
| `update-opportunity-tool` | Aktualisiert eine Verkaufschance per ID |
| `delete-opportunity-tool` | Löscht eine Verkaufschance per ID mit Soft Delete |

### Aufgaben

| Tool | Beschreibung |
|------|--------------|
| `list-tasks-tool` | Listet Aufgaben mit optionalem Filter, Sortierung und Paginierung auf |
| `get-task-tool` | Ruft eine einzelne Aufgabe per ID mit allen Details und Beziehungen ab |
| `create-task-tool` | Erstellt eine neue Aufgabe (erfordert `title`) |
| `update-task-tool` | Aktualisiert eine Aufgabe per ID |
| `delete-task-tool` | Löscht eine Aufgabe per ID mit Soft Delete |
| `attach-task-to-entities-tool` | Verknüpft eine Aufgabe mit Unternehmen, Personen, Verkaufschancen oder weist Mitglieder zu. Fügt hinzu, ohne bestehende Verknüpfungen zu entfernen. |
| `detach-task-from-entities-tool` | Löst die Verknüpfung einer Aufgabe mit Unternehmen, Personen, Verkaufschancen oder hebt die Zuweisung von Mitgliedern auf |

### Notizen

| Tool | Beschreibung |
|------|--------------|
| `list-notes-tool` | Listet Notizen mit optionalem Filter, Sortierung und Paginierung auf |
| `get-note-tool` | Ruft eine einzelne Notiz per ID mit allen Details und Beziehungen ab |
| `create-note-tool` | Erstellt eine neue Notiz (erfordert `title`) |
| `update-note-tool` | Aktualisiert eine Notiz per ID |
| `delete-note-tool` | Löscht eine Notiz per ID mit Soft Delete |
| `attach-note-to-entities-tool` | Verknüpft eine Notiz mit Unternehmen, Personen oder Verkaufschancen. Fügt hinzu, ohne bestehende Verknüpfungen zu entfernen. |
| `detach-note-from-entities-tool` | Löst die Verknüpfung einer Notiz mit Unternehmen, Personen oder Verkaufschancen |

### E-Mail

Diese Tools erscheinen nur für eine Verbindung, die E-Mail-Zugriff erhalten hat. Die Lese-Tools zeigen die synchronisierten E-Mails, die ihr Benutzer sehen darf, in der Freigabestufe, die der Inhaber des Postfachs gewählt hat, dazu die eigenen Entwürfe und den Postausgang des Benutzers. Die Tools für Postfächer, Entwürfe, Senden und Postausgang arbeiten mit den eigenen Postfächern und E-Mails des Benutzers.

Die beiden Lese-Tools brauchen Lesezugriff. Das Postfach-Tool erscheint für eine Verbindung, die entwerfen oder senden darf, und die drei Entwurfs-Tools für eine, die entwerfen darf. Die Tools zum Senden, Abbrechen, Neu-Terminieren und Wiederholen brauchen die Berechtigung zum Senden und eine Rolle, die über einen Assistenten senden darf. Ein terminiertes Senden erfolgt nie früher als die Haltefrist. Eine E-Mail geht an höchstens 20 Empfänger insgesamt, über An, CC und BCC zusammen. Ein Benutzer kann bis zu 10 Assistenten-E-Mails gleichzeitig eingeplant haben.

Eine eingeplante E-Mail bleibt an die Verbindung gebunden, die sie eingeplant hat. Sie wird nicht gesendet, wenn diese Verbindung bis zur Sendezeit widerrufen, gelöscht oder abgelaufen ist. Dasselbe gilt, wenn die Rolle ihres Benutzers das Senden über einen Assistenten nicht mehr erlaubt. Sie wartet dann mit dem Grund im Postausgang unter **Fehlgeschlagen**.

| Tool | Beschreibung |
|------|--------------|
| `list-emails-tool` | Listet E-Mails mit den neuesten zuerst auf, mit optionaler Suche und Filtern nach verknüpftem Eintrag, Richtung, Thread, Status und Datum. Die E-Mail eines Mitglieds erscheint, sobald sie synchronisiert oder gesendet ist, und die eigenen Entwürfe und der Postausgang des Benutzers erscheinen ebenfalls. Betreff und Vorschautext erscheinen nur, wo die Freigabestufe es erlaubt. |
| `get-email-tool` | Ruft eine E-Mail per ID ab, mit Status und Sendezeit. Text und Namen der Anhänge erscheinen nur bei Vollzugriff, und ein langer Text wird gekürzt. |
| `list-email-accounts-tool` | Listet die Postfächer auf, die der Benutzer in diesem Arbeitsbereich verbunden hat, mit der ID für das Entwerfen und der Angabe, ob jedes senden kann. |
| `create-email-draft-tool` | Speichert einen Entwurf in einem Postfach des Benutzers, als reinen Text oder Markdown, mit der Standardsignatur, sofern Sie sie nicht abschalten. Es wird nichts gesendet: Der Benutzer prüft den Entwurf in Relaticle. |
| `update-email-draft-tool` | Überschreibt einen gespeicherten Entwurf mit denselben Argumenten wie beim Erstellen. Angehängte Dateien bleiben erhalten. |
| `delete-email-draft-tool` | Löscht einen gespeicherten Entwurf und seine angehängten Dateien. |
| `send-email-tool` | Sendet eine E-Mail aus einem Postfach des Benutzers, sofort oder zu einem gewählten Zeitpunkt. Jede E-Mail wartet einige Minuten, bevor sie rausgeht, und ihr Benutzer kann sie über Benachrichtigungen oder den Postausgang abbrechen. |
| `send-email-draft-tool` | Sendet einen gespeicherten Entwurf so, wie er ist, einschließlich der eigenen Änderungen des Benutzers, und entfernt ihn aus den Entwürfen. Er wird wie jedes andere Senden zurückgehalten. |
| `cancel-email-tool` | Bricht eine eingeplante E-Mail ab, bevor sie rausgeht. |
| `reschedule-email-tool` | Ändert, wann eine eingeplante E-Mail rausgeht. Der Benutzer erhält einen neuen Hinweis, über den er abbrechen kann. |
| `retry-email-tool` | Plant eine fehlgeschlagene E-Mail erneut ein, zurückgehalten wie ein neues Senden. Sie ist dann an die Verbindung gebunden, die den neuen Versuch gestartet hat. |

### Dateien

| Tool | Beschreibung |
|------|--------------|
| `upload-file` | Speichert eine Datei im Arbeitsbereich aus einem base64-Inhalt mit `filename` oder einer `upload_id`. Liefert `suggested_markdown`, um die Datei in ein Rich-Text-Attribut wie den Inhalt einer Notiz einzubetten, sowie `file_id` für die gespeicherte Datei. Erlaubte Typen: pdf, doc, docx, xlsx, pptx, jpg, jpeg, png, gif, webp, bis 10 MB. 60 Aufrufe pro Stunde und Arbeitsbereich. |
| `create-upload-url` | Liefert eine fünf Minuten gültige signierte URL, an die Sie einen Dateiinhalt per `PUT` senden. Übergeben Sie die zurückgegebene `upload_id` dann innerhalb einer Stunde an `upload-file`. Ein bis dahin nicht gespeicherter Inhalt wird gelöscht. 60 Aufrufe pro Stunde und Arbeitsbereich. |

Uploads bleiben 24 Stunden ausstehend. Speichern Sie einen Eintrag, dessen Rich-Text-Inhalt die Datei einbettet, wird die Datei zugeordnet. Nicht zugeordnete Dateien werden gelöscht.

Die Listen-Tools der Entitäten nehmen `filter`, `sort`, `include`, `per_page` (Standard 15, Maximum 25) und `page` entgegen. `sort` ist ein Objekt mit `field` und `direction` (`asc` oder `desc`). `include` ist eine Liste singulärer Beziehungen oder Beziehungszähler. Ein Listen-Tool lehnt jedes andere Argument ab. Um Einträge über alle Entitäten hinweg nach Wörtern zu finden, nutzen Sie das Tool `search`.

`sort` nimmt ein natives Feld oder ein benutzerdefiniertes Attribut, das einen Wert hält. Ein Feld, das eine Liste hält, etwa E-Mail, Telefon, Link oder Tags, lässt sich nicht sortieren.

Listenantworten enthalten `page`, `per_page`, `total`, `has_more` und `next_page`. Die Tools zum Erstellen und Aktualisieren akzeptieren `custom_fields` als Schlüssel-Wert-Paare. Ein Schreibvorgang speichert eine Telefonnummer in E.164-Form, eine Domain als reinen Host und einen Zeitpunkt mit Uhrzeit als seinen UTC-Zeitpunkt. Ein Datum ohne Uhrzeit, das in ein Attribut vom Typ Datum und Uhrzeit geschrieben wird, wird als Beginn dieses Tages in der Zeitzone des Token-Benutzers gespeichert. Eine Uhrzeit ohne Offset wird weiterhin als UTC gelesen.

### Einträge filtern

Übergeben Sie `filter` an ein Listen-Tool, um die zurückgegebenen Einträge einzugrenzen. Ein Filter ist ein JSON-Objekt. Die [REST-API](/de/developers/rest-api) und Rela, der integrierte KI-Assistent, lesen denselben Filter.

Jeder Schlüssel in einem Filter ist einer von diesen:

| Schlüssel | Bedeutung |
|---|---|
| `$and`, `$or`, `$not` | Logik |
| `$eq`, `$in`, `$contains` und die übrigen Operatoren | Eine Prüfung des Feldes oder der Beziehung, in der sie stehen |
| Ein natives Feld wie `name` oder `created_at` | Eine Bedingung für eine der eigenen Spalten des Eintrags |
| Eine Beziehung wie `company` oder `assignees` | Eine Bedingung für verknüpfte Einträge |
| `custom_fields` | Bedingungen für Ihre benutzerdefinierten Attribute, nach Attributcode geordnet |
| Ein Unterfeld wie `domain` | Ein Teil eines E-Mail- oder Link-Werts |

Ein Schlüssel, der mit `$` beginnt, ist ein Schlüsselwort. Jeder andere Schlüssel ist ein Name. Es gibt keine Kurzschreibweise: `{"stage": "Won"}` ist ein Fehler, ebenso ein Operator ohne sein `$`.

Native Felder und Beziehungen stehen auf der obersten Ebene. Benutzerdefinierte Attribute stehen unter `custom_fields`, sodass ein benutzerdefiniertes Attribut mit dem Code `name` nie mit dem nativen `name` kollidiert.

#### Native Felder und Beziehungen

| Entität | Filternamen |
|---|---|
| Unternehmen | Native Felder `name`, `created_at`, `updated_at`, `creation_source`. Eintragsbeziehungen `people`, `opportunities`. Mitgliedsbeziehungen `creator`, `accountOwner`. |
| Personen | Native Felder `name`, `created_at`, `updated_at`, `creation_source`. Eintragsbeziehung `company`. Mitgliedsbeziehung `creator`. |
| Verkaufschancen | Native Felder `name`, `created_at`, `updated_at`, `creation_source`, `stale_days`. Eintragsbeziehungen `company`, `contact`. Mitgliedsbeziehung `creator`. |
| Aufgaben | Native Felder `title`, `created_at`, `updated_at`, `creation_source`, `assigned_to_me`. Eintragsbeziehungen `companies`, `people`, `opportunities`. Mitgliedsbeziehungen `creator`, `assignees`. |
| Notizen | Native Felder `title`, `created_at`, `updated_at`, `creation_source`. Eintragsbeziehungen `companies`, `people`, `opportunities`. Mitgliedsbeziehung `creator`. |

`name` und `title` nehmen die Textoperatoren. `created_at` und `updated_at` nehmen die Operatoren für Datum und Uhrzeit. Ein Datumsoperand ist `YYYY-MM-DD` oder ein ISO-8601-Zeitpunkt wie `2026-10-01T09:30:00Z`, jedes andere Format ist ein Fehler. Ein Zeitpunkt mit Offset, etwa `2026-10-01T13:00:00+05:00`, wird als derselbe Zeitpunkt in UTC gelesen. Ein Datum ohne Uhrzeit deckt bei diesen Feldern und bei einem benutzerdefinierten Feld vom Typ Datum und Uhrzeit den ganzen Tag in der Zeitzone des Token-Benutzers ab, die das Identitäts-Tool zurückgibt. `creation_source` ist eine Einzelauswahl mit den Werten `web`, `sample`, `import`, `api`, `mcp`, `chat`, `mailbox` und `system`. `stale_days` findet Verkaufschancen nach ganzen Tagen ohne Aktivität, wie in `{"$gte": 30}`. `assigned_to_me` nimmt `{"$eq": true}`.

#### Bedingungen kombinieren

- Schlüssel in einem Objekt werden mit UND verknüpft.
- `$or` nimmt eine Liste von Bedingungsobjekten und liefert Einträge, die zu mindestens einem davon passen.
- `$and` nimmt ebenfalls eine Liste. Nutzen Sie es, wenn ein Objekt zwei `$or`-Schlüssel bräuchte.
- `$not` nimmt ein Bedingungsobjekt. Es liefert jeden Eintrag, den dieses Objekt nicht liefert, auch Einträge, bei denen das Feld leer ist.
- Eine leere `$and`- oder `$or`-Liste, ein leeres `$not`-Objekt und ein leerer Beziehungsknoten sind Fehler. Ein leerer Filter bedeutet: kein Filter.

#### Über eine Beziehung filtern

Ein Beziehungsknoten enthält dieselbe Filtergrammatik, angewendet auf die verknüpften Einträge. Die Namen darin gehören zur verknüpften Entität.

- Eintragsbeziehungen nehmen `$in`, `$not_in` (Eintrags-IDs) und `$is_empty` oder Bedingungen für den verknüpften Eintrag.
- Mitgliedsbeziehungen nehmen `$in`, `$not_in` (Mitglieds-IDs) und `$is_empty`. Namen und E-Mail-Adressen von Mitgliedern lassen sich nicht filtern. Wer den Arbeitsbereich verlassen hat, zählt als kein Mitglied, sodass ein Eintrag, den diese Person erstellt hat, zu `{"creator": {"$is_empty": true}}` passt.
- Eine To-many-Beziehung passt, wenn mindestens ein verknüpfter Eintrag passt. Jede Bedingung in einem Beziehungsknoten gilt für denselben verknüpften Eintrag. Um Einträge ohne Verknüpfung zu finden, fassen Sie den Knoten in `$not`.
- Beziehungen lassen sich höchstens 2 Ebenen tief verschachteln, etwa `company` und dann `people`.

#### Operatoren nach Feldtyp

Native Felder nehmen die Operatoren des Feldtyps, dem sie entsprechen. `$is_empty` nimmt `true` oder `false`.

| Feldtyp | Operatoren |
|---|---|
| Einzelauswahl (Auswahl, Optionsfeld, Umschaltflächen), `creation_source` | `$eq`, `$in`, `$not_in`, `$is_empty` |
| Mehrfachauswahl (Mehrfachauswahl, Checkbox-Liste), Tags, Eintrag | `$has_any`, `$has_none`, `$is_empty` |
| E-Mail, Telefon, Link, Domain | `$has_any`, `$has_none`, `$contains`, `$starts_with`, `$ends_with`, `$is_empty` |
| Text | `$eq`, `$contains`, `$starts_with`, `$ends_with`, `$is_empty` |
| Zahl, Währung, Datum, Datum und Uhrzeit | `$eq`, `$gt`, `$gte`, `$lt`, `$lte`, `$is_empty` |
| Checkbox, Schalter | `$eq`, `$is_empty` |

E-Mail- und Link-Felder nehmen außerdem ein Unterfeld `domain` mit `$in` und `$not_in`. Es gleicht die Domain jedes Werts ab, sodass `{"domain": {"$in": ["canva.com"]}}` jede Adresse bei `canva.com` findet. Eine Domain ist ein reiner Host. Ein Pfad, Benutzer oder Port ist ein Fehler.

Felder vom Typ Mehrzeiliger Text, Rich-Text und Farbe nehmen keinen Filter. Ein verschlüsseltes oder inaktives Feld ebenso wenig.

#### Wie Werte abgeglichen werden

- Auswahlwerte nehmen das Optionslabel oder seine ID. Ein unbekanntes Label liefert einen Fehler mit den gültigen Labels. Ein mehrdeutiges Label verlangt die Options-ID.
- E-Mail- und Link-Werte werden ohne Beachtung der Groß- und Kleinschreibung abgeglichen. Ein Link passt außerdem mit oder ohne Schema, sodass `acme.com/team` auch `https://acme.com/team` findet.
- Domain-Werte werden als reiner Host oder als vollständige URL abgeglichen, mit oder ohne `www.`, sodass `https://www.acme.com/about` `acme.com` findet.
- Telefonwerte werden in jedem Format abgeglichen. Ein Telefonoperand braucht eine Landesvorwahl, etwa `+1 415 555 0100`. Ein Operand ohne Vorwahl liefert den Fehler `phone_number needs a country code, for example +1 415 555 0100.`
- Tags passen auf den exakt gespeicherten Wert.
- Ein Eintragsfeld nimmt die IDs der Einträge, mit denen es verknüpft ist.
- `$contains`, `$starts_with` und `$ends_with` ignorieren die Groß- und Kleinschreibung. Bei einem Feld mit mehreren Werten passen sie, wenn irgendein Wert passt. Sie vergleichen den Operanden, wie getippt, mit dem Wert, wie gespeichert. Eine Telefonnummer wird als `+14155550100` gespeichert, eine Durchwahl als `;ext=12` und ohne Leerzeichen, sodass `+1415` passt und `+1 415` nicht. Ein Link behält ein Schema nur, wenn eines getippt wurde, sodass `https://acme` zu `https://acme.com/team` passt und `acme` nicht. Eine Domain wird als reiner Host gespeichert, ohne Schema oder `www.`.
- `$not_in` und `$has_none` passen auch auf Einträge, bei denen das Feld leer ist.
- Die Verknüpfung eines Eintragsfelds mit einem gelöschten Eintrag zählt als keine Verknüpfung.

#### Limits

Ein Filter enthält höchstens 20 Bedingungen, gezählt wird jeder Operator in jeder Tiefe. Er verschachtelt `$and`, `$or` und `$not` höchstens 3 Ebenen tief und Beziehungen höchstens 2 Ebenen tief. Eine Liste enthält höchstens 100 Werte.

#### Beispiele

Verkaufschancen im Wert von mindestens 10.000, die in Proposal/Price Quote oder Negotiation/Review stehen. Die Bedingung außerhalb von `$or` wird mit UND mit ihm verknüpft.

```json
{
  "custom_fields": {"amount": {"$gte": 10000}},
  "$or": [
    {"custom_fields": {"stage": {"$eq": "Proposal/Price Quote"}}},
    {"custom_fields": {"stage": {"$eq": "Negotiation/Review"}}}
  ]
}
```

Verkaufschancen bei Unternehmen, bei denen der Schalter `icp` an ist. `company` ist eine Beziehung, ihr Knoten enthält also Bedingungen für das Unternehmen, auch für dessen benutzerdefinierte Attribute.

```json
{"company": {"custom_fields": {"icp": {"$eq": true}}}}
```

Personen mit einer E-Mail-Adresse bei einer Domain. Bei einem Link-Feld funktioniert es genauso.

```json
{"custom_fields": {"emails": {"domain": {"$in": ["canva.com"]}}}}
```

Phasenlabels und Attributcodes gehören zu Ihrem Arbeitsbereich. Rufen Sie `get-crm-schema-tool` auf, um Ihre zu lesen.

#### Das Filtervokabular lesen

`get-crm-schema-tool` liefert `filterable_fields` für einen Entitätstyp. Es hat drei Teile:

- Jedes native Feld und jede Beziehung mit Namen, mit `type`, `operators` und einem `example`. Beziehungen ergänzen die verknüpfte `entity`. `creation_source` ergänzt seine `values`. Beziehungen, Mitgliedsbeziehungen, `stale_days` und `assigned_to_me` ergänzen einen `operand`, der angibt, was sie akzeptieren.
- `types` ordnet jedem in Ihrem Arbeitsbereich vorhandenen Typ benutzerdefinierter Attribute seine `operators`, seine Regel `matching`, seine Regel `partial_matching`, falls er eine hat, und seine `sub_fields` zu. Ein Typ, dessen Werte Sie frei tippen, trägt außerdem ein `example`, und ein Auswahltyp trägt es am Feld.
- `custom_fields` listet jedes filterbare benutzerdefinierte Attribut mit Code, `name` und `type` auf. Ein Auswahlfeld trägt außerdem seine `options` und ein daraus gebautes `example`.

#### Filterfehler

Ein ungültiger Filter liefert einen Fehler, der den zu korrigierenden Teil nennt. Ein unbekannter Code eines benutzerdefinierten Attributs liefert zum Beispiel `"stagee" is not a filterable custom attribute (custom field) on opportunity. Available: amount, close_date, stage.` Ein bloßer Operator liefert `Operators start with $. Use $eq.`

Die flachen Namen des früheren Filters gibt es nicht mehr. Innerhalb von `filter` liefern Schlüssel wie `search`, `created_after`, `company_id` und `assignee_ids` einen Fehler, der den Ersatz nennt, zum Beispiel `company_id was replaced. Use company (or companies) with $in.` Dieselben Namen neben `filter` gesendet liefern diesen Fehler ebenfalls.

---

## Schema-Ressourcen

Der Server stellt fünf Schema-Ressourcen bereit, die die Felder jeder Entität beschreiben, einschließlich der benutzerdefinierten Attribute, die Ihr Arbeitsbereich eingerichtet hat, dazu eine Zusammenfassungs-Ressource mit der Anzahl der Einträge:

| Ressourcen-URI | Beschreibung |
|---|---|
| `relaticle://schema/company` | Felder und benutzerdefinierte Attribute von Unternehmen |
| `relaticle://schema/people` | Felder und benutzerdefinierte Attribute von Personen |
| `relaticle://schema/opportunity` | Felder und benutzerdefinierte Attribute von Verkaufschancen |
| `relaticle://schema/task` | Felder und benutzerdefinierte Attribute von Aufgaben |
| `relaticle://schema/note` | Felder und benutzerdefinierte Attribute von Notizen |

Die Unterstützung für Ressourcen unterscheidet sich je nach MCP-Client. Nutzen Sie `get-crm-schema-tool` vor dem Schreiben eines benutzerdefinierten Attributs (Custom Field), wenn Ihr Client Ressourcen nicht automatisch bereitstellt.

---

## Prompt CRM-Überblick

Der Server enthält einen eingebauten Prompt namens **CRM Overview**, der Ihrem KI-Assistenten eine Momentaufnahme Ihrer CRM-Daten gibt: die Anzahl der Einträge je Entität sowie zuletzt erstellte Unternehmen und Personen. Das ist ein guter Ausgangspunkt für jede Unterhaltung.

---

## Beispiel-Prompts

Probieren Sie nach dem Verbinden diese in Ihrem KI-Assistenten aus:

- „Liste alle meine Unternehmen auf“
- „Erstelle ein neues Unternehmen namens Acme Corp“
- „Zeige mir die Personen bei Unternehmen X“
- „Erstelle eine Aufgabe, um nächste Woche bei John nachzufassen“
- „Gib mir einen Überblick über mein CRM“
- „Ändere den Namen von Unternehmen X in Y“
- „Lösche die Aufgabe mit der ID abc-123“

---

## Fehlerbehebung

### „Unauthorized“ oder Fehler 401

Ihr Zugriffstoken ist möglicherweise abgelaufen oder ungültig. Erstellen Sie unter **Einstellungen > Zugriffstoken** ein neues.

### Keine Daten zurückgegeben

Der MCP-Server beschränkt alle Daten auf den Arbeitsbereich, der zu Ihrem Token gehört. Stellen Sie sicher, dass das Token für den richtigen Arbeitsbereich erstellt wurde und dass der Arbeitsbereich Daten enthält.

### Verbindung verweigert

Prüfen Sie, ob die MCP-URL stimmt: `https://mcp.relaticle.com`.

### Benutzerdefinierte Attribute werden nicht angezeigt

Benutzerdefinierte Attribute gelten pro Arbeitsbereich. Wenn Sie sie nicht sehen, prüfen Sie, ob sie für Ihren Arbeitsbereich unter **Einstellungen > Benutzerdefinierte Attribute** eingerichtet sind. Rufen Sie dann `get-crm-schema-tool` für den Entitätstyp auf.

### Ratenbegrenzung

MCP-Tool-Anfragen sind auf 120 pro Minute und authentifiziertem Benutzer begrenzt. OAuth-Autorisierungsendpunkte sind auf 20 pro Minute und IP-Adresse begrenzt.
