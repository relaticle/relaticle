---
title: Contrôler où apparaît un attribut
description: Les paramètres d'un attribut décident de sa place dans les listes et les pages d'enregistrement, et de la façon dont il se filtre et se trie.
order: 3
updated: "2026-11-10"
related: [help/custom-fields/add-and-manage-fields, help/getting-started/find-anything-with-search-and-filters]
---

Chaque attribut a une section **Paramètres** qui contrôle où il apparaît et
comment il se comporte. Ouvrez l'attribut depuis la page **Attributs
personnalisés** pour les modifier. Les changements s'appliquent aussitôt à tout
l'espace de travail.

![La section Paramètres du formulaire de modification d'un attribut, avec les interrupteurs Visible dans la liste, Visible dans la vue détaillée, Masqué par défaut, affichable, Chiffré et Activer les couleurs des options](/help-assets/custom-fields/control-where-a-field-appears-1.png)

## Visibilité

| Paramètre | Ce qu'il fait |
|---------|--------------|
| Visible dans la liste | Ajoute l'attribut comme colonne sur la page de liste du type d'enregistrement |
| Visible dans la vue détaillée | Affiche l'attribut sur la page de détail de l'enregistrement |
| Masqué par défaut, affichable | Garde la colonne de liste disponible mais masquée jusqu'à ce que quelqu'un l'ajoute depuis **Paramètres de la vue**, sous **Ajouter un attribut à la vue** |

Un attribut désactivé aux deux endroits existe toujours dans le formulaire de
création et de modification. Utilisez cela pour des données que vous saisissez
mais que vous n'avez pas besoin de voir au quotidien.

## Filtres et tri

- **Les filtres** ne demandent aucun réglage. Vous pouvez filtrer une liste par
  la plupart des attributs depuis la rangée de filtres au-dessus. Les attributs
  Zone de texte, Éditeur de texte enrichi et Sélecteur de couleur n'ont pas de
  filtre, pas plus qu'un attribut chiffré. Voir
  [Tout trouver avec la recherche et les filtres](/fr/help/getting-started/find-anything-with-search-and-filters).
- **La recherche** ne demande aucun réglage. Rela et le MCP server comparent les
  valeurs des attributs Texte, Zone de texte, E-mail, Numéro de téléphone, Lien,
  Domaine et Sélecteur de couleur. Ils ignorent les attributs de choix,
  d'enregistrement, Éditeur de texte enrichi, nombre, date et devise. La
  recherche de la barre latérale compare les noms et les titres.
- **Le tri** fonctionne sur les colonnes de texte, de nombre, de devise et de
  date. Les colonnes à valeurs multiples et de type contact (e-mails,
  téléphones, liens) ne se trient pas.

## Forme des données

| Paramètre | Ce qu'il fait |
|---------|--------------|
| Autoriser plusieurs valeurs | Permet à un enregistrement de contenir plusieurs valeurs (avec un plafond que vous fixez, jusqu'à 20) |
| Unique par type d'enregistrement | Deux enregistrements de ce type ne peuvent pas partager une valeur. C'est ainsi que les domaines et les e-mails évitent les doublons |
| Chiffré | Stocke les valeurs chiffrées au repos. Les attributs chiffrés ne peuvent pas être triés |
| Activer les couleurs des options | Pour les attributs Liste déroulante et Saisie de tags. Donne une couleur à chaque option, affichée sous forme de badges dans les listes et sur le board |
