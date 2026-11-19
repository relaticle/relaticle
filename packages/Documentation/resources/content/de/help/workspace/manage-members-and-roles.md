---
title: Mitglieder und Rollen verwalten
description: Was die Rollen Inhaber, Administrator, Mitglied und Betrachter jeweils dürfen, dazu Einladungen per E-Mail und das Entfernen von Mitgliedern.
order: 1
updated: "2026-11-19"
related: [help/getting-started/invite-your-team, help/workspace/rename-or-delete-your-workspace]
---

Mitglieder verwalten Sie unter **Einstellungen des Arbeitsbereichs**. Klicken Sie oben
in der Seitenleiste auf den Namen Ihres Arbeitsbereichs und dann auf den Tab
**Mitglieder**. Einladungen, Rollen und Entfernungen finden Sie alle dort.
**Mitglieder einladen** versendet die Einladungen. **Mitglieder** darunter listet
alle mit Zugriff auf, ob sie beigetreten sind oder nicht.

## Eine aktuelle Änderung bei den Rollen

Die Rolle Editor heißt jetzt Mitglied.
Administratoren können jetzt benutzerdefinierte Attribute verwalten, was früher nur
der Inhaber konnte. Prüfen Sie Ihre Liste **Mitglieder**, wenn Sie die Administratorrolle
früher vergeben haben. Betrachter können keine Daten mehr exportieren. Geben Sie daher
jedem, der weiterhin Exporte braucht, die Rolle Mitglied. Ein Betrachter kann
Einträge weiterhin über ein Zugriffstoken für die API oder den MCP-Server lesen.

## Die vier Rollen

| | Inhaber | Administrator | Mitglied | Betrachter |
|---|---|---|---|---|
| Einträge ansehen | Ja | Ja | Ja | Ja |
| Einträge erstellen | Ja | Ja | Ja | Nein |
| Einträge aktualisieren | Ja | Ja | Ja | Nein |
| Einträge löschen und wiederherstellen | Ja | Ja | Ja | Nein |
| Einträge endgültig löschen | Ja | Ja | Nein | Nein |
| Daten importieren | Ja | Ja | Ja | Nein |
| Daten exportieren | Ja | Ja | Ja | Nein |
| Mitglieder einladen, entfernen und ihre Rollen ändern | Ja | Ja | Nein | Nein |
| Jemanden zum Administrator befördern | Ja | Nein | Nein | Nein |
| Benutzerdefinierte Attribute verwalten | Ja | Ja | Nein | Nein |
| E-Mail-Einstellungen des Arbeitsbereichs verwalten | Ja | Ja | Nein | Nein |
| E-Mails über einen KI-Assistenten senden | Ja | Ja | Ja | Nein |
| Abrechnung verwalten | Ja | Nein | Nein | Nein |
| Arbeitsbereich umbenennen oder löschen | Ja | Nein | Nein | Nein |
| Aktivitätsprotokoll ansehen | Ja | Ja | Nein | Nein |

Administratoren verwalten neben der Arbeit mit Einträgen auch Mitglieder,
benutzerdefinierte Attribute und die E-Mail-Einstellungen des Arbeitsbereichs.
Einiges bleibt allein beim **Inhaber des Arbeitsbereichs**, unabhängig von der Rolle
anderer: jemanden *zum* Administrator befördern, den Arbeitsbereich umbenennen oder
löschen und die Abrechnung.

Der Inhaber ist keine Rolle, die Sie vergeben. Wer den Arbeitsbereich besitzt,
erscheint immer mit einem Badge **Inhaber**, hat keine Rolle, die sich ändern ließe,
und kann den Arbeitsbereich weder verlassen noch daraus entfernt werden.

Überall, wo Sie eine Rolle wählen, in **Mitglieder einladen** oder **Rolle ändern**,
steht **Rollen vergleichen** neben der Auswahl. Es öffnet diese
Tabelle in der App, und sie stimmt immer mit dem überein, was jede Rolle tatsächlich
darf.

## Einladungen

Klicken Sie auf **Mitglieder einladen**, um bis zu 10 Einladungen auf einmal zu
versenden. Fügen Sie die E-Mail-Adressen getrennt durch Komma, Leerzeichen oder
Zeilenumbruch ein, wählen Sie die Rolle, mit der alle beitreten, und senden Sie ab.

Eine eingeladene Person erscheint sofort in der Liste **Mitglieder**, über allen, die
angenommen haben, markiert mit **Einladung ausstehend**, mit der Rolle, die sie
erhält, und der verbleibenden Gültigkeit der Einladung. Das Aktionsmenü bietet:

- **Erneut senden** verschickt die E-Mail noch einmal.
- **Widerrufen** zieht die Einladung zurück.

Eine Einladung gilt 48 Stunden. Danach steht in der Zeile **Einladung abgelaufen**,
und **Erneut senden** stellt eine neue aus.

![Die Liste Mitglieder mit einer eingeladenen E-Mail-Adresse, markiert als Einladung ausstehend, über den Personen, die beigetreten sind](/help-assets/workspace/manage-members-and-roles-1.png)

## Mitglieder ändern und entfernen

Die Rolle jeder Person steht neben ihrem Namen, und das Aktionsmenü am Zeilenende
ändert sie. **Rolle ändern** wechselt zwischen Administrator, Mitglied und
Betrachter. **Entfernen** nimmt ein Mitglied aus dem Arbeitsbereich: Seine Einträge
bleiben, es verliert den Zugriff. Jedes Mitglied außer dem Inhaber kann den
Arbeitsbereich selbst **Verlassen**. Der Inhaber hat keine Aktion **Verlassen**.

Die Liste lässt sich nach Name oder E-Mail-Adresse durchsuchen, damit auch eine lange
Mitgliederliste handhabbar bleibt.
