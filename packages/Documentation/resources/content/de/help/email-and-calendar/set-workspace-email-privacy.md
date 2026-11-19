---
title: E-Mail-Datenschutz im Arbeitsbereich festlegen
description: Inhaber und Administratoren blenden E-Mails geschützter oder gesperrter Adressen aus und legen fest, welche Einträge die Synchronisierung erstellt.
order: 6
updated: "2026-11-08"
related: [help/email-and-calendar/choose-who-sees-your-email, help/workspace/manage-members-and-roles, help/email-and-calendar/connect-your-google-account]
---

Inhaber und Administratoren legen die E-Mail-Regeln für den ganzen Arbeitsbereich
fest: die Adressen, deren E-Mails ausgeblendet bleiben, und die Einträge, die die
Synchronisierung erstellt. Öffnen Sie **Einstellungen des Arbeitsbereichs** und dann
**E-Mail und Kalender**. Die beiden folgenden Tabs erscheinen nur für diese beiden
Rollen. Wie viel von jedem Postfach der Arbeitsbereich sieht, legen Sie am Postfach
selbst fest, wie
[Festlegen, wer Ihre E-Mails sieht](/de/help/email-and-calendar/choose-who-sees-your-email)
erklärt.

## E-Mails zu bestimmten Adressen ausblenden

Der Tab **E-Mail-Sichtbarkeit** blendet E-Mails und Kalendertermine, an denen die von
Ihnen aufgelisteten Adressen und Domains beteiligt sind, überall in Relaticle aus.

1. Klicken Sie auf **Adressen hinzufügen**.
2. Geben Sie **E-Mail-Adressen** oder **Domains** ein und drücken Sie nach jeder
   Eingabe die Eingabetaste.
3. Aktivieren Sie **Subdomains einschließen**, um Adressen wie
   `user@mail.example.com` abzudecken, wenn Sie `example.com` hinzufügen.
4. Klicken Sie auf **Absenden**.

Jeder Eintrag hat eine Durchsetzungsstufe, die **Durchsetzungsstufe ändern**
umschaltet.

- **Geschützt**: nur dann ausgeblendet, wenn jede Adresse der E-Mail oder des Termins
  geschützt oder gesperrt ist. Interne Unterhaltungen bleiben privat, und eine
  Unterhaltung mit einem Kunden wird weiterhin angezeigt.
- **Gesperrt**: immer ausgeblendet, sobald diese Adresse an der E-Mail oder dem
  Termin beteiligt ist.

Die eigenen Adressen der Mitglieder des Arbeitsbereichs sind standardmäßig
geschützt, ebenso ihre Arbeits-Domains. Diese Einträge heißen **Systemstandard** und
gelten immer.

![Der Tab E-Mail-Sichtbarkeit mit geschützten Adressen und Domains, ihrer Durchsetzungsstufe und der Angabe, wer sie hinzugefügt hat](/help-assets/email-and-calendar/set-workspace-email-privacy-1.png)

## Festlegen, welche Einträge die Synchronisierung erstellt

Der Tab **Erstellung von Einträgen** legt fest, was passiert, wenn eine synchronisierte
E-Mail oder ein Termin jemanden betrifft, der noch nicht in Ihrem CRM ist.

| Modus für die Erstellung von Personen | Was erstellt wird |
|---|---|
| **Alle Personen** | Ein Eintrag für jede Person, die in den E-Mails und Kalenderterminen der Mitglieder vorkommt. |
| **Gezielte Erstellung von Personen** | Ein Eintrag nur für Personen, denen ein Mitglied eine E-Mail geschrieben hat oder die in den Kalenderterminen eines Mitglieds vorkommen. |
| **Keine** | Nichts. E-Mails und Termine werden weiterhin mit Einträgen verknüpft, die Sie selbst erstellen. |

**Gezielte Erstellung von Personen** ist der empfohlene Modus und der, mit dem ein
neuer Arbeitsbereich beginnt. Er verhindert, dass Newsletter und unaufgeforderte
Anfragen Ihr CRM füllen.

**Unternehmenseinträge automatisch erstellen** legt aus der E-Mail-Domain einer neuen
Person ein Unternehmen an. Freie E-Mail-Domains wie gmail.com erstellen nie ein
Unternehmen, und automatische Absender wie no-reply-Adressen erstellen nie einen
Eintrag.

Eine Änderung hier betrifft nur E-Mails und Termine, die danach synchronisiert
werden.
