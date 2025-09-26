# UP Library Generator

Plugin générique pour enrichir un CPT choisi avec:

- Metabox de champs **code** (PHP / JS / SCSS / CSS) avec CodeMirror.
- Cases à cocher par champ pour **générer des fichiers** à l’enregistrement.
- **Compilation SCSS→CSS** si `scssphp` est disponible.
- **Réglages**: sélection du CPT cible, emplacement de sortie (thème / mu-plugins / ce plugin / personnalisé), sous-dossier relatif, sous-dossier par élément.
- **Import/Export** XML pour le CPT cible, et **import par défaut** depuis `defaults/<cpt>.xml`.

## Installation

1. Copier `up-library-generator/` dans `wp-content/plugins/`.
2. Activer le plugin.
3. Aller dans `Réglages > UP Library Generator` et choisir le **CPT cible**.

## Réglages

- `CPT cible` — type de contenu à enrichir.
- `Champs de code` — activer/désactiver chaque champ et sa case de génération.
- `Emplacement des fichiers générés` — base (thème / mu-plugins / plugin / personnalisé) + `Sous-dossier relatif` + option "Créer un sous-dossier par élément".
- `Chemin personnalisé` — utilisé uniquement si la base = personnalisé.

## Metabox sur le CPT cible

Champs disponibles (selon réglages):
- `PHP`, `JS`, `SCSS`, `CSS` — zones de code avec CodeMirror.
- `Nom de fichier` — base (sans extension) utilisée pour nommer les fichiers.
- Cases `Générer le fichier` — par type activé.

## Génération de fichiers

Structure (si "sous-dossier par élément" activé):
```
<base>/<relative_subdir>/<slug>/
├─ <slug>.php         (si coché)
└─ assets/
   ├─ js/<slug>.js    (si coché)
   ├─ scss/<slug>.scss (si coché)
   └─ css/<slug>.css  (si coché ou si SCSS compilé)
```

## Import/Export

- `CPT > Import/Export` —
  - Export XML (via `admin-post.php`).
  - Import depuis un fichier XML.
  - Import par défaut (plugin) lit `defaults/<cpt>.xml`.

Exemple minimal d’item XML:
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
      <meta_key name="_uplg_php_code"><![CDATA[<?php echo 'ok'; ?>]]></meta_key>
      <meta_key name="_uplg_generate_php_file">1</meta_key>
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
