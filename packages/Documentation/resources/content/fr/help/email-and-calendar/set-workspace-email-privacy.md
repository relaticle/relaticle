---
title: Définir la confidentialité des e-mails
description: Les propriétaires et administrateurs masquent les e-mails d'adresses protégées ou bloquées et décident quels enregistrements la synchronisation crée.
order: 6
updated: "2026-11-08"
related: [help/email-and-calendar/choose-who-sees-your-email, help/workspace/manage-members-and-roles, help/email-and-calendar/connect-your-google-account]
---

Les propriétaires et les administrateurs définissent les règles e-mail de tout
l'espace de travail : les adresses dont les e-mails restent masqués, et les
enregistrements que la synchronisation crée. Ouvrez **Paramètres de l'espace de
travail**, puis **E-mail et agenda**. Les deux onglets ci-dessous n'apparaissent que
pour ces deux rôles. La part de chaque boîte mail que l'espace de travail voit se
règle sur la boîte mail elle-même, comme l'explique
[Choisir qui voit vos e-mails](/fr/help/email-and-calendar/choose-who-sees-your-email).

## Masquer les e-mails liés à certaines adresses

L'onglet **Visibilité des e-mails** masque, partout dans Relaticle, les e-mails et
les événements d'agenda qui impliquent les adresses et les domaines que vous listez.

1. Cliquez sur **Ajouter des adresses**.
2. Saisissez des **Adresses e-mail** ou des **Domaines**, en appuyant sur Entrée
   après chacun.
3. Cochez **Inclure les sous-domaines** pour couvrir des adresses comme
   `user@mail.example.com` quand vous ajoutez `example.com`.
4. Cliquez sur **Valider**.

Chaque entrée a un niveau d'application, que **Modifier le niveau d'application**
change.

- **Protégé** : masqué seulement quand toutes les adresses de l'e-mail ou de
  l'événement sont protégées ou bloquées. Les conversations internes restent
  privées, et une conversation avec un client reste visible.
- **Bloqué** : toujours masqué quand cette adresse figure dans l'e-mail ou
  l'événement.

Les adresses des membres de l'espace de travail sont protégées par défaut, ainsi que
leurs domaines professionnels. Ces entrées affichent **Valeur système** et
s'appliquent toujours.

![L'onglet Visibilité des e-mails, avec les adresses et domaines protégés, leur niveau d'application et la personne qui les a ajoutés](/help-assets/email-and-calendar/set-workspace-email-privacy-1.png)

## Décider quels enregistrements la synchronisation crée

L'onglet **Création d'enregistrements** définit ce qui se passe quand un e-mail ou
un événement synchronisé implique quelqu'un qui n'est pas encore dans votre CRM.

| Mode de création de personnes | Ce qui est créé |
|---|---|
| **Toutes les personnes** | Un enregistrement pour chaque personne présente dans les e-mails et les événements d'agenda des membres. |
| **Création sélective de personnes** | Un enregistrement uniquement pour une personne à qui un membre a écrit, ou qui figure dans les événements d'agenda d'un membre. |
| **Aucune** | Rien. Les e-mails et les événements restent liés aux enregistrements que vous créez vous-même. |

**Création sélective de personnes** est le mode recommandé, et celui sur lequel
démarre un nouvel espace de travail. Il évite que les newsletters et les e-mails
entrants non sollicités n'envahissent votre CRM.

**Créer automatiquement les enregistrements d'entreprise** ajoute une entreprise à
partir du domaine e-mail d'une nouvelle personne. Les domaines de messagerie
gratuite comme gmail.com ne créent jamais d'entreprise, et les expéditeurs
automatiques comme les adresses no-reply ne créent jamais d'enregistrement.

Une modification ici n'affecte que les e-mails et les événements synchronisés
ensuite.
