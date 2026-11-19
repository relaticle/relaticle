---
title: KI-Credits und Ratenlimits
description: Wie Assistentennachrichten in Relaticle Cloud Credits verbrauchen, was jeder Tarif enthält und was bei null passiert. Selbst gehostet gibt es kein Credit-Limit.
order: 4
updated: "2026-11-04"
related: [help/ai-assistant/choose-an-ai-model, help/workspace/billing-and-plans]
---

In Relaticle Cloud kostet jede Nachricht, die Sie an den Assistenten senden,
Credits aus dem Monatskontingent Ihres Arbeitsbereichs. Eine einfache Antwort
kostet einen Credit, und jedes Tool, das der Assistent für die Antwort nutzt,
erhöht den Preis. Die Credits teilt sich der ganze Arbeitsbereich, sie gelten
nicht pro Person.

## Selbst gehostete Installationen

Eine selbst gehostete Installation hat kein Credit-Limit. Der Assistent läuft
mit Ihrem eigenen Anbieterschlüssel oder einem lokalen Modell, und Sie bezahlen
diesen Anbieter direkt. Es gilt nur das Sendelimit pro Minute, wie unter
Ratenlimits weiter unten beschrieben.

## Was eine Nachricht kostet

In Relaticle Cloud richten sich die Kosten nach dem Multiplikator des Modells
(siehe [KI-Modell auswählen](/de/help/ai-assistant/choose-an-ai-model)) und
nach einem kleinen Aufschlag für jedes Tool, das der Assistent beim Antworten
nutzt. Das Suchen von Einträgen, das Lesen eines Unternehmens und das
Entwerfen eines Vorschlags zählen jeweils mit. Eine einfache Frage mit dem
Standardmodell kostet 1 Credit. Eine mehrstufige Anfrage mit einem 3x-Modell
kostet mehr. Jede Websuche kostet 2 Credits zusätzlich (siehe
[Mit Rela ein Unternehmen recherchieren](/de/help/ai-assistant/research-a-company-with-rela)).
Die endgültigen Kosten stehen fest, sobald die Antwort vollständig ist.

## Was jeder Tarif enthält

| Tarif | Credits pro Monat | Nachrichten pro Minute |
|-------|-------------------|------------------------|
| Cloud Pro | 2.000 | 30 |
| Cloud Pro Testphase | 300 | 30 |
| Cloud Scale | 5.000 | 60 |
| Enterprise | Mit Ihrem Team vereinbart | Mit Ihrem Team vereinbart |
| Self-Hosted | Unbegrenzt | 60, festgelegt über `CHAT_RATE_LIMIT_PER_MINUTE` (0 für kein Limit) |

Relaticle Cloud hat keinen kostenlosen Tarif. Was jeder Tarif kostet, steht
unter [Abrechnung und Tarife](/de/help/workspace/billing-and-plans).

Die Cloud-Kontingente werden mit Ihrem Abrechnungszeitraum zurückgesetzt.
Bezahlte Arbeitsbereiche können außerdem Credit-Pakete kaufen. Gekaufte Credits
werden erst nach dem Monatskontingent verbraucht und **verfallen nie**. Ihr
verbleibendes Guthaben für den Zeitraum sehen Sie auf der Seite
**Abrechnung**, unter dem Namen Ihres Arbeitsbereichs.

## Wenn die Credits aufgebraucht sind

In Relaticle Cloud zeigt der Chat **Sie haben alle Ihre KI-Credits
verbraucht** mit Ihrem Rücksetzdatum, und neue Nachrichten warten bis dahin.
Alles andere in Relaticle bleibt unberührt. Bezahlte Arbeitsbereiche erhalten
in diesem Hinweis eine Schaltfläche **Credits hinzufügen**. Das übrige CRM
verbraucht nie Credits, nur Assistentennachrichten tun das.

## Ratenlimits

Das Nachrichtenlimit pro Minute teilt sich der ganze Arbeitsbereich. Sendet
Ihr Team schneller, zeigt der Chat **Zu schnelles Senden** mit einem
Countdown und sendet Ihre Nachricht automatisch, sobald das Fenster wieder
offen ist. So geht nichts verloren. Eine einzelne Nachricht darf bis zu 5.000
Zeichen lang sein.

In einer selbst gehosteten Installation liegt das Limit standardmäßig bei 60
Nachrichten pro Minute. Der Betreiber legt es mit
`CHAT_RATE_LIMIT_PER_MINUTE` fest, und 0 hebt es auf.
