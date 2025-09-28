# UP Library Generator

Plugin générique pour enrichir un ou plusieurs CPT via des configurations dédiées (par CPT) avec:

- Metabox basée sur un **schéma dynamique** de metas (types: code, texte, case à cocher).
- Champs **code** (lang: PHP / JS / SCSS / CSS) éditables avec CodeMirror sur les CPT cibles.
- Option par champ code: **Générer le fichier** à l’enregistrement (+ chemins personnalisés par champ si activé).
- **Compilation SCSS→CSS** si `scssphp` est disponible.
- **Réglages par CPT**: emplacement de sortie (thème / mu-plugins / ce plugin / personnalisé), sous-dossier relatif, sous-dossier par élément.
- **Import/Export** XML par CPT configuré, et **import par défaut** depuis `defaults/<cpt>.xml`.

## Installation

1. Copier `up-library-generator/` dans `wp-content/plugins/`.
2. Activer le plugin.
3. Ouvrir le menu `Library` > `CPT config` et créer une configuration par CPT cible (voir ci-dessous).
4. Dans les listes des CPT, un bouton engrenage permet d’ouvrir les réglages du CPT correspondant.

## CPT `CPT config`

Créez un élément dans `CPT config` pour définir une configuration par CPT cible:

- `CPT cible` — le post type à enrichir.
- `Emplacement des fichiers générés` — base (thème / mu-plugins / plugin / personnalisé), sous-dossier relatif, sous-dossier par élément.
- `Chemin personnalisé` — si la base = personnalisé.
- `Schéma des metas` — ajoutez des lignes avec:
  - Label, slug (clé), type (`code` / `texte` / `checkbox`), colonne (1/2)
  - Pour `code`: langue (php/js/scss/css), option "Générer le fichier" et possibilité d’activer un chemin personnalisé par méta

Chaque configuration active automatiquement la metabox et l’enqueue de CodeMirror sur le CPT ciblé.

## Réglages

- `CPT cible` — type de contenu à enrichir.
- `Emplacement des fichiers générés` — base (thème / mu-plugins / plugin / personnalisé) + `Sous-dossier relatif` + option "Créer un sous-dossier par élément".
- `Chemin personnalisé` — utilisé uniquement si la base = personnalisé.
- `Schéma des metas` — définit tous les champs affichés dans la metabox du CPT cible.

## Metabox sur le CPT cible

Champs disponibles (selon schéma):
- `Nom de fichier` — base (sans extension) utilisée pour nommer les fichiers.
- Champs `texte` et `checkbox`.
- Champs `code` (lang: php/js/scss/css) avec CodeMirror, éventuellement avec case `Générer le fichier` et, si activé, réglage du chemin personnalisé.

## Génération de fichiers

Structure (si "sous-dossier par élément" activé):
```
<base>/<relative_subdir>/<slug>/
├─ <slug>.php         (si une méta code PHP demande un fichier)
├─ <slug>.js          (si une méta code JS demande un fichier)
├─ <slug>.scss        (si une méta code SCSS demande un fichier)
└─ <slug>.css         (si une méta code CSS demande un fichier ou si SCSS compilé)
```

## Import/Export

- `CPT > Import/Export` (sous-menu ajouté sur chaque CPT) —
  - Export XML (via `admin-post.php`).
  - Import depuis un fichier XML.
  - Import par défaut (plugin) lit `defaults/<cpt>.xml`.

Exemple minimal d’item XML (schéma dynamique):
```xml
<uplg_items>
  <item>
    <post>
      <title>Exemple</title>
      <slug>exemple</slug>
      <status>publish</status>
      <content><![CDATA[Contenu de l’élément]]></content>
    </post>
    <meta>
      <meta_key name="_uplg_file_name"><![CDATA[exemple]]></meta_key>
      <meta_key name="_uplg_meta_header"><![CDATA[<?php echo 'ok'; ?>]]></meta_key>
      <meta_key name="_uplg_generate_meta_header">1</meta_key>
    </meta>
  </item>
</uplg_items>
```

## Dépendances

- WordPress 5.8+
- CodeMirror via `wp_enqueue_code_editor()` (inclus par WP)
- `scssphp` (optionnel) pour compiler le SCSS vers CSS

## Licence

Distribué dans le cadre du projet et adaptable selon vos besoins.
