# Changelog — UP Library Generator

## [0.2.0] — 2025-09-28
### Modifié
- Remplacement de la page de réglages globale par des réglages par CPT via un post `library-generator` dédié.
- Ajout d’un bouton engrenage sur les listes des CPT (publics et générés) pour accéder aux réglages du CPT.
- Correctifs de redirection précoce (early redirect) pour éviter « headers already sent » sur `edit.php?page=uplg-cpt-settings-*`.
- Assouplissement des permissions: accès basé sur les capacités du CPT (`edit_posts` du CPT) et rôles éditeurs/admin.
- Suppression des champs de code statiques (PHP/JS/SCSS/CSS) dans la config et l’édition des items.
- Nettoyage REST: retrait des metas statiques `_uplg_*_code` et `_uplg_generate_*_file`; seules les metas du schéma dynamique sont exposées.
- Export/Import XML mis à jour: plus d’export des champs statiques ni de `_uplg_conf_fields`; encodage JSON uniquement de `_uplg_conf_meta_schema`.
- Génération de fichiers basée uniquement sur les metas « code » du schéma dynamique (avec gestion des chemins personnalisés et flags).

## [0.1.0] — 2025-09-26
### Ajouté
- Première ébauche: sélection d’un CPT cible, metabox champs (PHP/JS/SCSS/CSS) avec CodeMirror.
- Cases de génération par champ; génération de fichiers à l’enregistrement.
- Réglages d’emplacement (thème / mu-plugins / plugin / personnalisé), sous-dossier relatif, sous-dossier par élément.
- Import/Export XML pour le CPT cible; import par défaut depuis `defaults/<cpt>.xml`.
- Compilation SCSS→CSS si `scssphp` est disponible.
