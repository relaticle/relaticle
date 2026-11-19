---
title: Tout trouver avec la recherche et les filtres
description: Cherchez dans les enregistrements Relaticle et filtrez les listes d'entreprises, de personnes, d'opportunités, de tâches et de notes.
order: 7
updated: "2026-11-13"
related: [help/getting-started/use-custom-fields, help/getting-started/import-your-existing-data]
---

Appuyez sur **⌘K**, ou cliquez sur la zone de recherche de la barre latérale,
pour chercher dans tous les enregistrements à la fois. Utilisez la rangée de
filtres de n'importe quelle liste pour affiner ce que vous avez déjà sous les
yeux.

1. Cliquez sur la zone de recherche en haut de la barre latérale, ou appuyez
   sur **⌘K**.
2. Saisissez n'importe quelle partie d'un nom. Les résultats apparaissent
   groupés par type d'enregistrement : **Entreprises**, **Opportunités**, etc.
3. Cliquez sur un résultat pour l'ouvrir.

![Le menu de recherche globale affichant les résultats pour « Acme » groupés sous Entreprises et Opportunités](/help-assets/getting-started/find-anything-with-search-and-filters-1.png)

## Trouver un enregistrement dans une liste

Les listes (Entreprises, Personnes, Opportunités, Tâches, Notes) n'ont pas de
zone de recherche. Pour trouver un enregistrement par son nom dans une liste,
ajoutez un filtre sur son champ de nom ou de titre avec la condition
**contient**. La rangée de filtres au-dessus du tableau l'affiche, et vous
pouvez l'y retirer.

## Filtrer une liste

1. Cliquez sur **Filtrer** dans la rangée au-dessus du tableau. Dès qu'un
   filtre est défini, le bouton devient **+**.
2. Choisissez un champ. Saisissez du texte pour réduire la liste des champs.
3. Choisissez une condition, par exemple **contient**, **est l'un de** ou
   **il y a moins de**.
4. Saisissez ou choisissez une valeur. La liste se met à jour dès que le filtre
   est complet.

![La rangée de filtres au-dessus de la liste Opportunités avec trois pastilles : Étape est l'un de Négociation/Révision, Date de clôture dans moins de 30 jours, et Montant supérieur à 10 000,00 $](/help-assets/getting-started/find-anything-with-search-and-filters-2.png)

Chaque filtre apparaît sous forme de pastille dans la rangée. Cliquez sur son
champ, sa condition ou sa valeur pour le modifier. Choisir un autre champ
conserve la pastille et efface sa condition et sa valeur. Pour la retirer,
cliquez sur **⋮** sur la pastille, puis sur **Retirer le filtre**. Un
enregistrement doit correspondre à chaque pastille pour rester dans la liste.
Quand les filtres masquent tous les enregistrements, cliquez sur **Effacer les
filtres**.

La rangée reste sur une seule ligne. Faites-la défiler latéralement quand elle
contient plus de pastilles qu'il n'en tient.

## Utiliser des dates relatives

Choisissez **il y a moins de**, **dans moins de** ou **tombe** pour filtrer par
période, par exemple les 30 derniers jours ou ce mois-ci. Une vue enregistrée
garde la période relative : elle suit le calendrier. **Aujourd'hui** fonctionne
de la même façon.

Un champ qui contient des membres vous place en premier, avec votre nom suivi
de **(moi)**.

## Afficher les enregistrements supprimés

**Enregistrements supprimés** se trouve en bas de la liste des champs. Sa
pastille affiche les enregistrements supprimés avec les autres (**inclus**) ou
seuls (**uniquement**). Cliquez sur la deuxième partie de la pastille pour
basculer. Vous ne la voyez que si vous avez le droit de voir les
enregistrements supprimés.

## Trier et grouper une liste

Cliquez sur **Trier** et cherchez la colonne selon laquelle ordonner la liste.
Cliquez sur **Ajouter un tri** pour départager les égalités avec une autre
colonne, et faites glisser une ligne par sa poignée pour changer le tri
prioritaire. Chaque ligne bascule entre croissant et décroissant, et sa croix
la retire. Le bouton affiche **Trié par** et la première colonne, plus un
nombre quand d'autres suivent.

Dans Tâches, **Grouper** groupe la liste par statut ou par priorité.

Une vue enregistrée est à vous seul, et elle s'enregistre toute seule.
Modifiez un filtre, un tri, un groupement, une colonne ou le nom d'une colonne
pendant qu'une vue est ouverte, et la vue conserve la modification. Pour
démarrer une autre vue à partir de ce qui est à l'écran, ouvrez le sélecteur de
vues et choisissez **Créer une vue**.

Le sélecteur liste vos vues dans un seul ordre. Le tableau intégré est l'une
des lignes, par exemple **Toutes les entreprises**. Opportunités ajoute
**Pipeline**, Tâches ajoute **Board**, et Notes ajoute **Cartes**. La recherche
en haut réduit la liste par nom.

Faites glisser une ligne pour la déplacer. Un appui long fait la même chose sur
un écran tactile. Le menu de chaque ligne propose aussi **Monter** et
**Descendre**, qui fonctionnent au clavier. La recherche désactive le glisser
et le déplacement pendant que vous tapez.

La vue en tête de liste est celle sur laquelle une page s'ouvre quand vous la
visitez sans lien, par exemple depuis la barre latérale. Un board ou la vue
Cartes en tête ouvre cette page. Un lien qui désigne quelque chose, comme un
filtre, un enregistrement ou **Toutes les entreprises**, ouvre ce qu'il
désigne.

## Si un champ manque dans la liste des filtres

Tout attribut personnalisé peut être filtré, sauf les attributs Zone de texte,
Éditeur de texte enrichi et Sélecteur de couleur. Les attributs chiffrés ne
peuvent pas être filtrés non plus, ni un attribut désactivé. Un attribut n'a
pas besoin de **Visible dans la liste** pour être filtré. Consultez
[Utiliser des attributs personnalisés](/fr/help/getting-started/use-custom-fields)
pour tous les réglages d'un attribut.

## Filtrer via l'API, MCP ou Rela

La rangée de filtres est une façon de réduire une liste. L'API REST, le MCP server et Rela, l'assistant IA intégré, utilisent plutôt un objet `filter`. Il filtre sur les champs natifs et les attributs personnalisés, combine les conditions avec AND, OR et NOT, et suit les liens entre enregistrements. Lisez [le guide MCP](/fr/developers/mcp) pour la grammaire complète.
