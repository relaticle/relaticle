---
title: Capturer des prospects depuis un formulaire
description: Connectez un formulaire Maxforms à Relaticle Cloud pour que chaque envoi crée des personnes, entreprises, opportunités, notes et tâches liées.
order: 5
updated: "2026-10-04"
related: [help/import/update-existing-records, help/ai-assistant/connect-claude-or-chatgpt, docs/guides/rest-api]
---

Relaticle n'a pas de générateur de formulaires. [Maxforms](https://maxforms.com/integrations/relaticle),
un générateur de formulaires, se connecte à Relaticle Cloud et transforme chaque
envoi en enregistrements dans votre espace de travail. Vous configurez la
connexion dans Maxforms, et Relaticle vous demande d'approuver l'accès une seule
fois.

## Ce que crée un envoi

Un envoi peut créer jusqu'à cinq enregistrements, chacun lié aux autres :

| Enregistrement | Ce qu'il contient en général |
|--------|----------------------|
| **Entreprise** | L'organisation de la personne qui remplit le formulaire |
| **Personne** | La personne qui remplit le formulaire, liée à l'entreprise |
| **Opportunité** | Ce que le formulaire ouvre, liée aux deux |
| **Note** | Les réponses, rattachées aux enregistrements ci-dessus |
| **Tâche** | Une relance, rattachée de la même façon |

Vous activez ou désactivez chaque enregistrement par formulaire. Un formulaire
qui n'a besoin que d'une personne et d'une note ne crée que ces deux-là.

## Connecter un formulaire

1. Dans Maxforms, ouvrez l'onglet **Integrate** du formulaire, trouvez la carte
   **Relaticle** et sélectionnez **Connect**.
2. Sélectionnez **Connect New Account** et connectez-vous à Relaticle si on vous
   le demande.
3. Approuvez l'accès et **choisissez l'espace de travail** dans lequel le
   formulaire peut écrire.
4. De retour dans Maxforms, activez les enregistrements voulus et associez chaque
   champ de Relaticle à une réponse du formulaire. Vos attributs personnalisés
   sont listés à côté des attributs intégrés.
5. Enregistrez, faites un envoi de test et ouvrez les nouveaux enregistrements
   dans Relaticle.

Maxforms demande deux autorisations : lire vos enregistrements, et les créer et
les mettre à jour. Il ne peut rien supprimer.

## Éviter les doublons

Maxforms peut faire correspondre un envoi à un enregistrement que vous avez déjà
au lieu d'en créer un second. Les personnes correspondent sur **E-mails** et les
entreprises sur **Domaines**, les deux champs que Relaticle garde uniques.

Dans la carte Relaticle de Maxforms, associez la réponse e-mail du formulaire à
E-mails, puis choisissez E-mails sous **Match existing people by**. Faites de
même avec Domaines pour les entreprises.

Si une même personne renvoie le formulaire, sa personne et son entreprise
existantes sont mises à jour. L'opportunité, la note et la tâche sont de nouveau
créées à chaque fois.

## Voir et révoquer la connexion

Ouvrez **Paramètres → Jetons d'accès** depuis le menu de votre avatar. Maxforms
apparaît dans la liste **Connecteurs IA**, à côté des assistants que vous avez
connectés, avec l'espace de travail dans lequel il écrit. **Révoquer** coupe son
accès immédiatement, pour tous les formulaires que vous avez connectés. Les
nouveaux envois échouent alors jusqu'à ce que vous reconnectiez Maxforms.

## Bon à savoir

- La connexion fonctionne avec Relaticle Cloud. Maxforms ne peut pas se
  connecter à une installation auto-hébergée. Une installation auto-hébergée peut
  recevoir les données d'un formulaire via les endpoints d'upsert du
  [guide de l'API REST](/fr/developers/rest-api).
- Maxforms agit au nom de la personne qui l'a connecté, dans l'unique espace de
  travail choisi sur l'écran de consentement.
- Un espace de travail en pause ne peut pas être choisi sur l'écran de
  consentement. Les livraisons vers un espace de travail qui se met en pause plus
  tard échouent jusqu'à ce que son abonnement soit réactivé.
