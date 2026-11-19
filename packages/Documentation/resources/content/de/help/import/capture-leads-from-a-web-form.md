---
title: Leads aus einem Webformular erfassen
description: Verbinden Sie ein Maxforms-Formular mit Relaticle Cloud, damit jede Einsendung verknüpfte Personen, Unternehmen, Verkaufschancen, Notizen und Aufgaben anlegt.
order: 5
updated: "2026-10-04"
related: [help/import/update-existing-records, help/ai-assistant/connect-claude-or-chatgpt, docs/guides/rest-api]
---

Relaticle hat keinen eigenen Formular-Builder.
[Maxforms](https://maxforms.com/integrations/relaticle), ein Formular-Builder,
verbindet sich mit Relaticle Cloud und macht aus jeder Einsendung Einträge in
Ihrem Arbeitsbereich. Sie richten die Verbindung in Maxforms ein, und Relaticle
bittet Sie einmal, den Zugriff zu genehmigen.

## Was eine Einsendung anlegt

Eine Einsendung kann bis zu fünf Einträge anlegen, die jeweils mit den anderen
verknüpft sind:

| Eintrag | Was er üblicherweise enthält |
|--------|----------------------|
| **Unternehmen** | Die Organisation der einsendenden Person |
| **Person** | Die einsendende Person, mit dem Unternehmen verknüpft |
| **Verkaufschance** | Was das Formular eröffnet, mit beiden verknüpft |
| **Notiz** | Die Antworten, an die obigen Einträge angehängt |
| **Aufgabe** | Eine Nachverfolgung, auf dieselbe Weise angehängt |

Sie schalten jeden Eintrag pro Formular ein oder aus. Ein Formular, das nur eine
Person und eine Notiz braucht, legt nur diese beiden an.

## Ein Formular verbinden

1. Öffnen Sie in Maxforms den Tab **Integrate** des Formulars, suchen Sie die
   Karte **Relaticle** und wählen Sie **Connect**.
2. Wählen Sie **Connect New Account** und melden Sie sich bei Relaticle an,
   falls Sie dazu aufgefordert werden.
3. Genehmigen Sie den Zugriff und **wählen Sie den Arbeitsbereich**, in den das
   Formular schreiben darf.
4. Schalten Sie zurück in Maxforms die gewünschten Einträge ein und ordnen Sie
   jedes Relaticle-Feld einer Formularantwort zu. Ihre benutzerdefinierten
   Attribute stehen neben den eingebauten.
5. Speichern Sie, senden Sie eine Testeinsendung und öffnen Sie die neuen
   Einträge in Relaticle.

Maxforms bittet um zwei Berechtigungen: Ihre Einträge lesen sowie sie erstellen
und aktualisieren. Löschen kann es nichts.

## Duplikate vermeiden

Maxforms kann eine Einsendung einem bereits vorhandenen Eintrag zuordnen, statt
einen zweiten anzulegen. Personen werden über **E-Mail-Adressen** abgeglichen,
Unternehmen über **Domains**, die beiden Felder, die Relaticle eindeutig hält.

Ordnen Sie in der Relaticle-Karte in Maxforms die E-Mail-Antwort des Formulars
E-Mail-Adressen zu und wählen Sie dann unter **Match existing people by** die
E-Mail-Adressen. Gehen Sie für Unternehmen mit Domains genauso vor.

Wer erneut einsendet, aktualisiert dann die bestehende Person und das
bestehende Unternehmen. Verkaufschance, Notiz und Aufgabe werden jedes Mal neu
angelegt.

## Die Verbindung ansehen und widerrufen

Öffnen Sie im Avatar-Menü **Einstellungen → Zugriffstoken**. Maxforms erscheint
in der Liste **KI-Konnektoren** neben allen Assistenten, die Sie verbunden haben,
zusammen mit dem Arbeitsbereich, in den es schreibt. **Widerrufen** unterbindet
seinen Zugriff sofort, für jedes Formular, das Sie verbunden haben. Neue
Einsendungen werden dann nicht mehr zugestellt, bis Sie erneut verbinden.

## Gut zu wissen

- Die Verbindung funktioniert mit Relaticle Cloud. Maxforms kann sich nicht mit
  einer selbst gehosteten Installation verbinden. Eine selbst gehostete
  Installation kann Formulardaten über die Upsert-Endpunkte im
  [Leitfaden zur REST-API](/de/developers/rest-api) entgegennehmen.
- Maxforms handelt als die Person, die es verbunden hat, innerhalb des einen
  Arbeitsbereichs, der auf dem Zustimmungsbildschirm gewählt wurde.
- Ein pausierter Arbeitsbereich lässt sich auf dem Zustimmungsbildschirm nicht
  wählen. Zustellungen an einen Arbeitsbereich, der später pausiert, schlagen
  fehl, bis er wieder abonniert ist.
