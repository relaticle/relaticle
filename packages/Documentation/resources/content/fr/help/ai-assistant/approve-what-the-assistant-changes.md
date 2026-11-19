---
title: Approuver les changements de l'assistant
description: Chaque création, modification ou suppression proposée par l'IA attend votre relecture. Rien n'est enregistré tant que vous ne l'approuvez pas.
order: 2
updated: "2026-11-06"
related: [help/ai-assistant/ask-questions-about-your-data, help/getting-started/use-custom-fields, help/email-and-calendar/use-your-email-with-an-ai-assistant]
---

Quand vous demandez à l'assistant de changer quelque chose (« crée une tâche de
relance pour Acme », « passe cette opportunité à Négociation », « supprime ces
trois notes »), il n'écrit jamais directement dans votre CRM. Il rédige le
changement sous forme de carte de proposition à la place de la zone de message,
et attend. Approuvez-la ou abandonnez-la pour continuer à discuter.

![Une carte de proposition « Create task Send updated proposal to Figma », listant son titre, l'entreprise et l'opportunité liées, le statut, l'échéance et la priorité, avec les boutons Abandonner et Approuver](/help-assets/ai-assistant/approve-what-the-assistant-changes-1.png)

## La carte de proposition

La carte liste chaque changement sur une ligne, avec toutes les valeurs qu'il
définit. Une modification affiche l'ancienne valeur barrée à côté de la
nouvelle. À partir de là, vous pouvez :

- **Approuver**. Le bouton compte ce qu'il écrit : **Approuver**, ou
  **Approuver 3 modifications** pour plusieurs. Une suppression s'affiche en
  rouge, par exemple **Supprimer 2 tâches**. `⌘↵` approuve au clavier, sauf
  pour les suppressions, les e-mails, les invitations et les écrasements, qui
  demandent toujours un clic.
- **Modifier d'abord un champ**. Cliquez sur le crayon à côté d'une valeur,
  corrigez-la, puis **Enregistrer**. Approuvez quand la carte vous convient.
- **Exclure un élément**. Survolez un champ, une ligne ou un enregistrement et
  cliquez sur son **×**. Il reste sur la carte, barré, avec **Conserver** pour
  le rétablir. Rien n'est décidé tant que vous n'appuyez pas sur
  **Approuver**.
- **Abandonner**. Rien n'est écrit.

L'assistant peut renseigner n'importe quel champ que vous pourriez renseigner
dans le formulaire de l'enregistrement, y compris les attributs personnalisés de
votre espace de travail, et chacun apparaît sur la carte pour relecture.

## Quand quelqu'un a modifié l'enregistrement entre-temps

Si quelqu'un modifie un champ après que l'assistant a proposé de le changer, la
carte indique qui l'a modifié, en quoi et quand. L'approbation demande alors un
clic, jamais le raccourci clavier. Sur une modification unique, le bouton
affiche **Écraser**. Excluez ce champ pour garder sa valeur (dans un lot ou un
plan, excluez cet enregistrement), ou abandonnez la proposition. Une
modification d'un champ que la proposition ne touche pas ne change rien.

Si la modification arrive pendant que la carte est ouverte, votre premier clic
affiche le changement au lieu d'approuver. Cliquez de nouveau une fois que vous
l'avez lu.

## Lots et plans

Une demande peut proposer jusqu'à 25 enregistrements. « Ajoute ces cinq
personnes issues de mes notes de réunion » arrive sous forme d'une seule carte
qui liste les cinq. Excluez ceux dont vous ne voulez pas et approuvez le reste.
Les suppressions fonctionnent de la même façon.

Une demande qui exige plusieurs changements liés, comme une nouvelle entreprise
avec une personne et une tâche rattachées, arrive sous forme d'un plan de
5 étapes liées au maximum. Un enregistrement que le plan crée et auquel un autre
changement renvoie est marqué **nouveau**. Si vous excluez cet enregistrement,
les changements qui y renvoient partent avec lui. La carte vous indique
lesquels, avec **Conserver** pour les rétablir. Vous approuvez le plan une seule
fois.

## Après votre décision

La carte disparaît et la conversation garde un reçu : **3 modifications
appliquées** avec une ligne par enregistrement, chacune renvoyant vers celui-ci.
Tout ce que vous avez exclu s'affiche barré. Une proposition abandonnée laisse
une ligne grise. La plus récente propose **Proposer à nouveau**.

## E-mail

Un e-mail s'approuve un par un. Sa carte affiche tous les destinataires et le
message complet, et son bouton indique **Envoyer l'e-mail**. Un plan liste
l'e-mail sous la forme « Ensuite, envoyer l'e-mail … » et ne l'envoie jamais
avec le reste : une fois le plan approuvé, l'e-mail a la carte pour lui seul et
attend son propre **Envoyer l'e-mail**. Voir
[Utiliser votre e-mail avec un assistant IA](/fr/help/email-and-calendar/use-your-email-with-an-ai-assistant).

## Invitations

Inviter quelqu'un dans votre espace de travail lui envoie un e-mail, donc une
invitation a aussi son propre bouton : **Envoyer l'invitation**. Approuver un
plan ne l'envoie jamais.

## Les propositions n'attendent pas indéfiniment

- Tant qu'une proposition attend, la carte prend la place de la zone de
  message. Approuvez-la ou abandonnez-la pour continuer à discuter.
- Une proposition laissée de côté expire après 24 heures et sa ligne indique
  **expiré**. Si c'est la plus récente, **Proposer à nouveau** donne une carte
  neuve.
- Vous ne pouvez pas régénérer une réponse tant que sa proposition est en
  attente. Traitez d'abord la carte.

## Qui peut proposer quoi

L'assistant agit avec vos autorisations. Créer et modifier des définitions
d'attributs personnalisés depuis le chat est réservé au propriétaire et aux
administrateurs, exactement comme la page **Attributs personnalisés** elle-même.
Pour tous les autres, l'assistant explique et renvoie vers cette page.
