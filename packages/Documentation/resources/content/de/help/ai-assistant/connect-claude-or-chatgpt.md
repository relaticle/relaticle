---
title: Claude oder ChatGPT mit Relaticle verbinden
description: Richten Sie einen KI-Konnektor zu Relaticle per OAuth ein. Die Anleitung erklärt den Zugriff und warum MCP-Clients direkt in Einträge schreiben.
order: 5
updated: "2026-10-05"
related: [docs/guides/mcp, help/ai-assistant/ask-questions-about-your-data, help/ai-assistant/ai-credits-and-limits]
---

Die Assistenten, die Sie bereits nutzen, Claude und ChatGPT, können direkt
mit Ihren Relaticle-Daten arbeiten. Sie installieren das Relaticle-Plugin in
ChatGPT oder fügen Relaticle in Claude als **Konnektor** hinzu und genehmigen
den Zugriff einmal. Danach können Sie Ihren Assistenten in dessen eigenem Chat
nach Ihrer Pipeline fragen oder Einträge erstellen und aktualisieren lassen.

Kein Code, keine API-Schlüssel: Die ganze Einrichtung ist ein
Zustimmungsbildschirm.

## Verbinden

1. Fügen Sie Relaticle Ihrem Assistenten hinzu:
   - **ChatGPT:** Öffnen Sie das
     [Relaticle-Plugin](https://chatgpt.com/plugins/plugin_asdk_app_6a92c3af04a0819180ed6652ebe09961)
     im Plugin-Verzeichnis von ChatGPT und klicken Sie auf **Install plugin**.
   - **Claude:** Fügen Sie unter **Settings → Connectors** einen eigenen
     Konnektor mit der URL `https://mcp.relaticle.com` hinzu.
2. Der Assistent leitet Sie zu Relaticle weiter, damit Sie sich anmelden,
   falls Sie es noch nicht sind.
3. Genehmigen Sie den Zugriff und **wählen Sie den Arbeitsbereich**, den der
   Konnektor nutzen darf.
4. Das war es. Fragen Sie Ihren Assistenten etwas: „Was ist diesen Monat in
   meiner Pipeline?“ Beginnen Sie die Nachricht in ChatGPT mit
   **@Relaticle**.

Der Konnektor handelt als **Sie**, innerhalb **dieses einen Arbeitsbereichs**:
Er kann tun, was Sie dort tun können, und sonst nichts. Um einen Assistenten
auf einen anderen Arbeitsbereich zu richten, widerrufen Sie den Konnektor und
verbinden ihn neu.

## Was Ihr Assistent tun kann

Unternehmen, Personen, Verkaufschancen, Aufgaben und Notizen suchen und lesen,
erstellen, aktualisieren und löschen, Aufgaben und Notizen mit Einträgen
verknüpfen und die benutzerdefinierten Attribute Ihres Arbeitsbereichs lesen,
damit seine Antworten Ihr echtes Schema nutzen.

Eines sollten Sie wissen: Änderungen über einen Konnektor werden direkt
angewendet. Die Prüfkarte, die Sie im integrierten Chat von Relaticle
bekommen, gibt es hier nicht. Verlassen Sie sich daher auf die eigenen
Rückfragen Ihres Assistenten, bevor er handelt. Die Aktivität eines
Konnektors verbraucht nie die
[KI-Credits](/de/help/ai-assistant/ai-credits-and-limits) Ihres
Arbeitsbereichs, die nur für Rela, den integrierten Assistenten, gelten.

## Verbindungen ansehen und widerrufen

Öffnen Sie über Ihr Avatar-Menü **Einstellungen → Zugriffstoken**. Der
Abschnitt **KI-Konnektoren** listet jeden Assistenten auf, der mit Ihren
Arbeitsbereichen verbunden ist, an welchen Arbeitsbereich er jeweils gebunden
ist und wie viele aktive Token er besitzt. **Widerrufen** unterbindet den
Zugriff eines Assistenten sofort und stoppt jede E-Mail, die er eingeplant
hat und die noch nicht versendet wurde. Sie können ihn jederzeit wieder
verbinden.

![Die Liste der KI-Konnektoren mit Claude und ChatGPT, an einen Arbeitsbereich gebunden, mit Anzahl aktiver Token und einer Schaltfläche zum Widerrufen pro Zeile](/help-assets/ai-assistant/connect-claude-or-chatgpt-1.png)

## Gut zu wissen

- Funktioniert mit einem aktiven Relaticle-Cloud-Arbeitsbereich. Ein
  pausierter Arbeitsbereich lässt sich im Zustimmungsbildschirm nicht
  auswählen, bis er wieder abonniert ist.
- Claude und ChatGPT sind derzeit die Assistenten mit einem integrierten
  Konnektor-Ablauf. Der Server spricht aber den offenen MCP-Standard, sodass
  jedes andere MCP-fähige Tool dieselbe URL mit einem persönlichen
  Zugriffstoken nutzen kann, so wie Entwickler Cursor verbinden (siehe die
  [Anleitung zum MCP-Server](/de/developers/mcp)).
- Selbst gehostete Installationen enthalten denselben Konnektor-Server auf Ihrer
  eigenen Domain. Die [Anleitung zum MCP-Server](/de/developers/mcp)
  beschreibt die Einrichtung.
- Der Zugriff erneuert sich im Hintergrund selbst. Den Zustimmungsbildschirm
  sehen Sie nur wieder, wenn Sie widerrufen und neu verbinden.
