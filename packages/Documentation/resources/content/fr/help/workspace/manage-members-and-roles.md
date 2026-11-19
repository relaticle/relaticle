---
title: Gérer les membres et les rôles
description: Ce que peuvent faire les rôles Propriétaire, Administrateur, Membre et Lecteur, plus les invitations par e-mail et le retrait de membres.
order: 1
updated: "2026-11-19"
related: [help/getting-started/invite-your-team, help/workspace/rename-or-delete-your-workspace]
---

Les membres se gèrent dans **Paramètres de l'espace de travail**. Cliquez sur le nom
de votre espace de travail en haut de la barre latérale pour y accéder, puis sur
l'onglet **Membres**. Les invitations, les rôles et les retraits s'y trouvent tous.
**Inviter des membres** envoie les invitations. **Membres**, en dessous, liste toutes
les personnes ayant accès, qu'elles aient rejoint l'espace de travail ou non.

## Un changement récent des rôles

Le rôle Éditeur s'appelle désormais Membre. Les administrateurs peuvent désormais
gérer les attributs personnalisés, ce que seul le propriétaire pouvait faire
auparavant. Vérifiez votre liste **Membres** si vous avez accordé le rôle
Administrateur plus tôt. Les lecteurs ne peuvent plus exporter de données : donnez le
rôle Membre à toute personne qui a encore besoin d'exports. Un Lecteur peut toujours
lire les enregistrements avec un jeton d'accès pour l'API ou le MCP.

## Les quatre rôles

| | Propriétaire | Administrateur | Membre | Lecteur |
|---|---|---|---|---|
| Voir les enregistrements | Oui | Oui | Oui | Oui |
| Créer des enregistrements | Oui | Oui | Oui | Non |
| Modifier des enregistrements | Oui | Oui | Oui | Non |
| Supprimer et restaurer des enregistrements | Oui | Oui | Oui | Non |
| Supprimer définitivement des enregistrements | Oui | Oui | Non | Non |
| Importer des données | Oui | Oui | Oui | Non |
| Exporter des données | Oui | Oui | Oui | Non |
| Inviter, retirer et changer le rôle des membres | Oui | Oui | Non | Non |
| Promouvoir quelqu'un au rôle d'administrateur | Oui | Non | Non | Non |
| Gérer les attributs personnalisés | Oui | Oui | Non | Non |
| Gérer les paramètres e-mail de l'espace de travail | Oui | Oui | Non | Non |
| Envoyer des e-mails via un assistant IA | Oui | Oui | Oui | Non |
| Gérer la facturation | Oui | Non | Non | Non |
| Renommer ou supprimer l'espace de travail | Oui | Non | Non | Non |
| Consulter le journal d'activité | Oui | Oui | Non | Non |

Les administrateurs gèrent les membres, les attributs personnalisés et les paramètres
e-mail de l'espace de travail, en plus de travailler sur les enregistrements. Quelques
actions restent réservées au seul **propriétaire de l'espace de travail**, quel que
soit le rôle de chacun : promouvoir quelqu'un *au rôle* d'administrateur, renommer ou
supprimer l'espace de travail, et la facturation.

Propriétaire n'est pas un rôle que vous attribuez. La personne qui possède l'espace de
travail apparaît toujours avec un badge **Propriétaire**, n'a aucun rôle à changer, et
ne peut ni partir ni être retirée.

Partout où vous choisissez un rôle, dans **Inviter des membres de l'espace de
travail** ou **Changer le rôle**, **Comparer les rôles** se trouve à côté du choix. Il ouvre ce tableau dans l'application, et il correspond
toujours à ce que chaque rôle peut réellement faire.

## Invitations

Cliquez sur **Inviter des membres de l'espace de travail** pour envoyer jusqu'à 10
invitations à la fois. Collez les adresses e-mail séparées par une virgule, un espace
ou un retour à la ligne, choisissez le rôle avec lequel tous rejoindront l'espace de
travail, puis envoyez.

Une personne invitée rejoint tout de suite la liste **Membres**, au-dessus de celles
qui ont accepté, marquée **Invitation en attente** avec le rôle qu'elle recevra et le
temps qu'il reste à l'invitation. Son menu d'actions propose :

- **Renvoyer** l'e-mail.
- **Révoquer** l'invitation.

Une invitation dure 48 heures. Ensuite la ligne indique **Invitation expirée**, et
**Renvoyer** en émet une nouvelle.

![La liste des membres avec un e-mail invité marqué Invitation en attente au-dessus des personnes qui ont rejoint l'espace de travail](/help-assets/workspace/manage-members-and-roles-1.png)

## Changer et retirer des membres

Le rôle de chaque personne figure à côté de son nom, et le menu d'actions à la fin de
la ligne le modifie. **Changer le rôle** la fait passer entre Administrateur, Membre
et Lecteur. **Retirer** sort un membre de l'espace de travail : ses enregistrements
restent, il perd l'accès. Tout membre sauf le propriétaire peut **Quitter** lui-même
l'espace de travail. Le propriétaire n'a pas l'action **Quitter**.

Vous pouvez rechercher dans la liste par nom ou par adresse e-mail, ce qui garde une longue liste
facile à parcourir.
