---
title: Crédits d'IA et limites de débit
description: Comment les messages à l'assistant consomment des crédits sur Cloud, ce qu'inclut chaque offre et ce qui se passe à zéro. Aucune limite en auto-hébergé.
order: 4
updated: "2026-11-04"
related: [help/ai-assistant/choose-an-ai-model, help/workspace/billing-and-plans]
---

Sur Relaticle Cloud, chaque message que vous envoyez à l'assistant consomme des
crédits sur l'allocation mensuelle de votre espace de travail. Une réponse simple
coûte un crédit, et chaque outil que l'assistant utilise pour répondre s'y
ajoute. Les crédits sont partagés par tout l'espace de travail, pas par
personne.

## Installations auto-hébergées

Une installation auto-hébergée n'a aucune limite de crédits. L'assistant
fonctionne avec votre propre clé de fournisseur ou un modèle local, et vous
payez directement ce fournisseur. Seule la limite d'envois par minute
s'applique, comme décrit plus bas dans Limites de débit.

## Ce que coûte un message

Sur Relaticle Cloud, le coût dépend du multiplicateur du modèle (voir
[Choisir un modèle d'IA](/fr/help/ai-assistant/choose-an-ai-model)), auquel
s'ajoute un peu pour chaque outil utilisé pendant la réponse. Chercher des
enregistrements, lire une entreprise et rédiger une proposition comptent chacun.
Une question simple sur le modèle par défaut coûte 1 crédit. Une demande en
plusieurs étapes sur un modèle 3x coûte davantage. Chaque recherche web ajoute
2 crédits (voir
[Rechercher une entreprise avec Rela](/fr/help/ai-assistant/research-a-company-with-rela)).
Le coût final est établi une fois la réponse terminée.

## Ce que chaque offre inclut

| Offre | Crédits par mois | Messages par minute |
|------|-------------------|---------------------|
| Cloud Pro | 2 000 | 30 |
| Essai Cloud Pro | 300 | 30 |
| Cloud Scale | 5 000 | 60 |
| Enterprise | Convenu avec votre équipe | Convenu avec votre équipe |
| Auto-hébergé | Non mesuré | 60, défini par `CHAT_RATE_LIMIT_PER_MINUTE` (0 pour aucune limite) |

Relaticle Cloud n'a pas d'offre gratuite. Le prix de chaque offre figure dans
[Facturation et offres](/fr/help/workspace/billing-and-plans).

Les allocations Cloud se réinitialisent avec votre période de facturation. Les
espaces de travail payants peuvent aussi acheter des packs de crédits. Les
crédits achetés ne sont utilisés qu'après l'allocation mensuelle et
**n'expirent jamais**. Votre solde restant pour la période s'affiche sur la
page **Facturation**, sous le nom de votre espace de travail.

## Quand les crédits sont épuisés

Sur Relaticle Cloud, le chat affiche **Vous avez utilisé tous vos crédits
d'IA** avec votre date de réinitialisation, et les nouveaux messages attendent
cette date. Rien d'autre dans Relaticle n'est affecté. Les espaces de travail
payants voient un bouton **Ajouter des crédits** sur le même avis. Le reste du
CRM ne consomme jamais de crédits, seuls les messages à l'assistant le font.

## Limites de débit

La limite de messages par minute est partagée dans tout l'espace de travail.
Si votre équipe envoie plus vite, le chat affiche **Envoi trop rapide** avec un
compte à rebours et envoie votre message automatiquement à l'ouverture de la
fenêtre, donc rien n'est perdu. Un message peut contenir jusqu'à 5 000
caractères.

Sur une installation auto-hébergée, la limite est de 60 messages par minute
par défaut. L'opérateur la définit avec `CHAT_RATE_LIMIT_PER_MINUTE`, et 0 la
supprime.
