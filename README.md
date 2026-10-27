# Arkaic Sync ACF

Plugin WordPress maison pour synchroniser les articles et leurs champs ACF Pro entre le site source (`admin.arkaic-concept.fr`) et le site cible (`arkaic-skateboard.fr`).

## Fonctionnalités

- **Synchronisation complète des champs ACF** :
  - Clones (Introduction, Bouton...)
  - Contenu flexible (`blocks` : texte, image, image et texte avec orientation, galerie, slider, vidéo, bouton)
  - Taxonomies (catégories, étiquettes) avec création/correspondance automatique par slug
  - Médias (images, galeries, fichiers) avec remapping automatique des IDs sur la cible et transfert via URL sécurisée (compatible pare-feu Wordfence).
- **Image de tête & Image mise en avant** :
  - L'image d'en-tête de l'article (`header_image`) est automatiquement assignée en **Image mise en avant** native WordPress (`_thumbnail_id`) sur la cible.
  - Pas de duplication inutile dans le corps du texte : le thème de la cible (Divi) affiche proprement l'image en haut de l'article et dans les cartes de blog.
- **Gestion depuis la liste des articles (`edit.php`)** :
  - Colonne visuelle « Synchronisation » avec statut (**✔ Synchronisé**, **⚠ Échec**, **Non synchronisé**) et date de dernière synchro.
  - Boutons d'action AJAX directe (**Synchroniser**, **Resynchroniser**, **Réessayer**) sans rechargement de page.
  - Action rapide au survol sous le titre de chaque article.
  - Action groupée (Bulk action) : sélection multiple d'articles pour synchronisation par lot.
- **Mises à jour automatiques intégrées dans WordPress** :
  - Le plugin se connecte directement aux releases GitHub de ce dépôt.
  - Les notifications de mise à jour s'affichent nativement dans le tableau de bord WordPress (*Tableau de bord > Mises à jour* et *Extensions*).
  - Mise à jour en 1 clic depuis l'administration WordPress.

## Installation

Le plugin doit être installé sur les deux WordPress :

1. Téléchargez la dernière version `arkaic-sync-acf.zip` depuis les [Releases GitHub](https://github.com/arkaicconcept/arkaic-sync-acf/releases).
2. Dans WordPress (*Extensions > Ajouter une extension > Téléverser une extension*), installez et activez le plugin.

## Configuration

Rendez-vous dans **Réglages > Arkaic Sync** :

### 1. Sur le site cible (qui reçoit les articles)
- Allez dans la section **Recevoir (ce site est la cible)**.
- Choisissez l'utilisateur WordPress qui sera l'auteur des articles importés.
- Copiez le **Jeton de ce site**.

### 2. Sur le site source (qui envoie les articles)
- Allez dans la section **Envoyer (ce site est la source)**.
- Renseignez l'URL du **Site cible** (ex. `https://arkaic-skateboard.fr`).
- Collez le **Jeton de la cible**.
- Cliquez sur **Enregistrer et tester la connexion**.

*(Alternative : vous pouvez définir les constantes `ARKAIC_SYNC_TARGET`, `ARKAIC_SYNC_SEND_TOKEN`, `ARKAIC_SYNC_RECEIVE_TOKEN` et `ARKAIC_SYNC_RECEIVE_USER` dans `wp-config.php`).*

## Mises à jour

Le plugin vérifie automatiquement les nouvelles versions publiées sur GitHub via l'API GitHub Releases.
- Si le dépôt est public, aucune configuration n'est requise.
- Pour forcer une vérification immédiate, cliquez sur **Vérifier les mises à jour maintenant** dans *Réglages > Arkaic Sync*, ou cliquez sur *Vérifier à nouveau* dans *Tableau de bord > Mises à jour*.
