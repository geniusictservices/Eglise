# Système de design « Volcan »

Direction choisie par le client parmi les quatre propositions (B · Volcan). Sombre et énergique, inspirée du basalte et de la lave du Nyiragongo (Goma) : le basalte pour les fonds, la lave pour l'action, la braise pour les prix, la glace pour l'information.

- Feuille commune : `public/assets/css/volcan.css` (jetons et composants), chargée par les trois gabarits avant la feuille propre à chaque espace (`vitrine.css`, `caisse.css`, `gestion.css`).
- Thème : `public/assets/js/theme.js`, chargé dans `<head>` (sans `defer`) pour appliquer le thème mémorisé avant l'affichage.
- Page vivante : `/gestion/design` (connexion requise). Elle utilise les vraies classes et lit les valeurs des jetons dans la feuille de style, avec le contraste calculé en direct.

## Principes

1. **Lisible dans la pénombre comme au soleil.** Deux thèmes complets, choisis selon le contexte, toujours modifiables par l'utilisateur.
2. **Une couleur, un rôle.** Lave = action principale (un seul bouton plein par écran si possible). Braise = prix et mises en avant. Glace = information, nouveauté. Les catégories de la caisse ont leur propre palette.
3. **Toucher plutôt que taper.** Cibles d'au moins 44 px, boutons de 48 px, bouton « Encaisser » de 56 à 60 px.
4. **Peu de texte, des repères visuels.** Couleur de catégorie sur les onglets de la caisse ; la sélection (tuile choisie, pastille de quantité) est à la couleur d'accent ; grandes images sur les tuiles.
5. **Pas de bandes de couleur sur un seul côté.** Cartes, chiffres, alertes, lignes du ticket et blocs ont un contour fin et égal sur tous les côtés (couleur `--ligne`, ou teinte de l'état pour une alerte) ; la couleur passe par le fond teinté, une pastille ou le texte, jamais par une bordure plus épaisse à gauche, en haut ou en bas.
6. **Les mêmes noms partout.** Les classes et variables historiques (`--accent --fond --surface --encre --doux --ligne --erreur`, `.bouton`, `.tableau`, `.champ`…) sont conservées : les vues existantes et celles des autres modules prennent le design sans changement.

## Typographie

| Usage | Police | Graisses |
|---|---|---|
| Titres, noms d'établissement, totaux de la caisse | Unbounded | 600, 800 |
| Texte, boutons, libellés, prix | Sora | 400, 600, 700 |

Chargées depuis Google Fonts avec `display=swap` (gabarit partiel `views/layouts/_polices.php`). Polices de secours : `"Arial Black", "Segoe UI", system-ui, sans-serif` pour les titres, `system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif` pour le texte (la caisse reste utilisable sans réseau).

Classes utilitaires : `.titre-1` (taille fluide 2 à 3,25 rem), `.titre-2`, `.titre-3`, `.surtitre` (capitales espacées, texte doux), `.prix-volcan`, `.montant` (chiffres tabulaires).

## Jetons

Les jetons se règlent avec l'attribut `data-theme` sur `<html>` ou sur n'importe quel bloc (la page `/gestion/design` affiche les deux thèmes côte à côte) : `jour`, `nuit`, ou `auto` (suit `prefers-color-scheme`). Sans attribut, c'est le thème jour.

### Couleurs

| Jeton | Rôle | Nuit | Jour |
|---|---|---|---|
| `--fond` | fond de page | `#0F1113` | `#F4F3F1` |
| `--surface` | cartes, barres, ticket | `#15181B` | `#FFFFFF` |
| `--surface-2` | puces, champs secondaires | `#1F2327` | `#ECEAE6` |
| `--tuile-fond` | tuiles de caisse | `#171A1D` | `#FFFFFF` |
| `--ligne` / `--ligne-forte` | séparateurs / contours de champs | `#2A2F34` / `#3A4046` | `#DEDCD8` / `#C4C1BB` |
| `--encre` | texte | `#F3F0EB` | `#111315` |
| `--doux` | texte secondaire | `#A2A8AE` | `#5B6168` |
| `--lave` | action principale | `#FF5A1F` | `#E04A12` |
| `--sur-lave` | texte posé sur la lave | `#111315` | `#111315` |
| `--lave-texte` | lave utilisée comme texte | `#FF5A1F` | `#B93A10` |
| `--braise` | prix, mises en avant | `#FFB547` | `#9A5B00` |
| `--glace` / `--glace-texte` | information, catégorie secondaire | `#6CC3D5` | `#1F7F93` / `#1A6E80` |
| `--erreur` | erreurs, suppression | `#FF7A66` | `#C0341F` |
| `--succes` | ouvert, enregistré | `#5CC98A` | `#1E7A46` |

Jetons dérivés (recalculés sur chaque bloc thématisé) : `--accent` (= lave, ou couleur de l'établissement), `--sur-accent`, `--accent-texte`, `--prix` (= braise), `--info`, `--focus`, et les fonds teintés `--teinte-accent`, `--teinte-braise`, `--teinte-glace`, `--teinte-erreur`, `--teinte-succes` (10 % de la couleur sur la surface).

### Catégories de la caisse

`--cat-1` à `--cat-8`, attribuées dans l'ordre des catégories, et `--sur-cat` pour le texte posé dessus (pastille de quantité, onglet choisi).

| | Lave | Braise | Glace | Améthyste | Menthe | Hibiscus | Cobalt | Cendre |
|---|---|---|---|---|---|---|---|---|
| Nuit (texte `#111315`) | `#FF5A1F` | `#FFB547` | `#6CC3D5` | `#B98CFF` | `#7BD88F` | `#FF8FB1` | `#5FA8FF` | `#E8E3D9` |
| Jour (texte blanc) | `#C2410C` | `#9A5B00` | `#1F7F93` | `#7C4DDB` | `#2E7D46` | `#C2416F` | `#2F6FD0` | `#6B6560` |

### Formes et tailles

`--rayon` 10 px (boutons, champs, tuiles), `--rayon-carte` 16 px, `--rayon-badge` 4 px, `--rayon-rond` (puces, pastilles). `--hauteur-bouton` 48 px, `--cible` 44 px. Espacements `--espace-1` à `--espace-6` (4, 8, 12, 16, 24, 32 px).

## Composants (volcan.css)

| Composant | Classes | Notes |
|---|---|---|
| Bouton | `.bouton`, `--secondaire`, `--fantome`, `--danger`, `--petit` (44 px), `--large` (pleine largeur, 56 px) | Fond lave, texte `--sur-accent`. `:disabled` à 45 % d'opacité. |
| Bouton icône | `.bouton-icone` | 44 × 44 px, libellé dans `.visuellement-cache`, état `aria-pressed`. |
| Lien discret | `.lien` | Bouton ou lien souligné, texte doux. |
| Badge | `.badge`, `--lave`, `--braise`/`--populaire`, `--glace`/`--nouveau`, `--erreur`/`--epice`, `--succes`, `--neutre`/`--epuise`, `--plein`, `--contour` | Capitales espacées (0,08 em), 11 px, gras. |
| Puce | `.puce`, `.puce--accent` ; état `aria-pressed`, `aria-current` ou `.puce--active` | Filtres, catégories, choix. 44 px. |
| Carte | `.carte`, `.carte--accent` | Surface, rayon 16 px. |
| Champ | `.champ` (libellé + saisie), `.saisie`, `.champ__aide`, `.champ__erreur`, `.champ--erreur`, `aria-invalid="true"`, `.coche` | 48 px de haut, contour 1,5 px, focus à la couleur d'accent. |
| Tableau | `.tableau`, `.defile` (conteneur qui défile), `.nombre` | En-têtes en capitales espacées. |
| Tuile de caisse | `.tuile`, `__tete`, `__nom`, `__qte`, `__bas`, `__format`, `__prix`, `--choisie`, `--epuise` ; variable `--cat` | Style « Volcan Pro » : fond `--tuile-fond`, contour fin, rayon 16 px, grande image (16/10, 2/1 en S, 4/3 en L) ; nom et pastille de quantité côte à côte (pastille sur l'image si la tuile est étroite) ; prix en police de titre ; tuile choisie : contour et fond teinté à la couleur d'accent. |

Composants propres à un espace : en-tête, barre collante, état d'ouverture et ligne de menu (`vitrine.css`) ; onglets, rangée des sous-catégories (`.sous-onglet`), ticket, choix de la taille et du format, résumé sur téléphone, indicateur du réseau (`.hl-etat` : point vert « En ligne », fond braise « Hors ligne », pastille du nombre de ventes en attente), reçu provisoire (`.recu--provisoire`, bandeau « En attente d'envoi » en pointillés) et opérations grisées sans réseau (`.hl-reseau`) (`caisse.css`) ; intertitre de sous-catégorie (`.sous-categorie__titre`) sur la vitrine ; barre de navigation, blocs, chiffres, alertes, codes QR et chevalet (`gestion.css`).

## Thème selon le contexte

| Écran | Thème par défaut | Changement |
|---|---|---|
| Vitrine d'un nightclub ou d'un lounge | nuit | bouton ◐ dans la barre, mémorisé sur l'appareil du visiteur (`localStorage` « vitrine.theme », valable pour toute la vitrine) |
| Vitrine d'un restaurant-bar | jour | idem |
| Page d'accueil de la vitrine | nuit, sauf si tous les établissements sont des restaurants-bars | idem |
| Caisse | comme la vitrine (nightclub et lounge de nuit ; le mode comptoir rapide du nightclub démarre donc en sombre) | bouton ◐ de la barre, mémorisé par appareil et par établissement (« caisse.theme.&lt;slug&gt; ») ; grille de 5 tuiles par ligne (3 sur téléphone), tailles S/M/L pour la hauteur et le texte |
| Espace de gestion | `auto` : préférence du système, jour sinon | bouton ◐ en haut, mémorisé (« gestion.theme ») |
| Pages d'erreur | nuit | — |
| Chevalet de table à imprimer | jour (toujours imprimé sur blanc) | — |

Mise en place : le gabarit écrit `data-theme` et `data-theme-cle` sur `<html>` ; `theme.js` remplace le thème par celui mémorisé (lecture et écriture de `localStorage` dans `try/catch` : sans stockage, le thème par défaut s'applique), relie les boutons `[data-bascule-theme]`, met à jour `aria-pressed` et la couleur `theme-color` du navigateur, et émet l'évènement `theme:change`.

### Couleur propre à un établissement

Si `etablissements.couleur` est renseignée (`#RRGGBB`), elle remplace la lave sur la vitrine de cet établissement (catégorie active, titres de catégories, liens). Le gabarit calcule avec les fonctions de `app/helpers.php` :

- `texteSurCouleur($couleur)` : `#111315` ou `#FFFFFF`, celui qui contraste le mieux (luminance WCAG) → `--sur-accent` ;
- `couleurLisible($couleur, $fond)` : la couleur assombrie (jour) ou éclaircie (nuit) jusqu'à 4,5:1 → `--accent-texte`.

Démonstration : Comptoir du Lac `#6CC3D5` (glace), Volcano Club `#FF5A1F` (lave).

### Crédits photo

Sur la vitrine seulement, `produits.photo_credit` s'affiche en petit sous la photo du produit et `etablissements.couverture_credit` en bas de la photo de couverture (« Photo : … »).

## Accessibilité

- **Contrastes** : texte normal 4,5:1 au moins, grands titres et éléments graphiques 3:1 au moins, dans les deux thèmes. Valeurs vérifiées : texte sur fond 16,2:1 (nuit) et 16,8:1 (jour) ; texte doux 7,0:1 sur surface (nuit), 5,7:1 sur fond (jour) ; texte sur lave 6,0:1 (nuit), 4,6:1 (jour) ; braise 9,4:1 sur surface (nuit), 5,4:1 (jour) ; badges teintés au moins 4,5:1. Les variantes `--lave-texte` et `--glace-texte` existent parce que la lave et la glace du thème jour ne suffisent pas en texte sur le fond clair.
- **Ne jamais coder une information par la couleur seule** : la catégorie a aussi son nom (onglet), « Épuisé » est écrit, l'état d'ouverture est en toutes lettres, une erreur de champ a un message.
- **Messages d'erreur** : dire quoi corriger (« Indiquez un prix supérieur à 0, par exemple 3,50 »), relier le message au champ avec `aria-describedby` et marquer le champ `aria-invalid="true"`.
- **Cibles tactiles** de 44 px au moins (48 px pour les boutons), espacées d'au moins 8 px.
- **Focus visible** : contour de 3 px à la couleur `--focus`.
- **Boutons à bascule** (thème, taille des tuiles, catégories) : `aria-pressed`. Icônes seules : libellé dans `.visuellement-cache` et `title`.
- **Mouvement réduit** : `prefers-reduced-motion` coupe les transitions et animations.
- **Téléphone** : formulaires sur une colonne sous 600 px, tableaux dans un conteneur qui défile (`.defile` ou `.bloc`), aucune barre de défilement horizontale sur la page.

## Ajouter un écran

1. Utiliser le gabarit de l'espace (`View::afficher(..., 'gestion')`) : polices, `volcan.css`, thème et bouton de bascule sont déjà là.
2. Réutiliser les composants ci-dessus avant d'en créer ; ne jamais écrire une couleur en dur, toujours un jeton.
3. Si un style propre est nécessaire, le mettre dans une feuille du module, qui ne s'appuie que sur les jetons.
4. Vérifier l'écran dans les deux thèmes (bouton ◐) et sur téléphone.

## Champ image

Partout où l'on ajoute une image (photo du produit, logo et couverture d'un établissement), on utilise le même composant : le partiel `views/gestion/_champ-image.php`, `public/assets/js/champ-image.js` et `public/assets/css/champ-image.css`. Il accepte le glisser-déposer, le choix d'un fichier, le collage (Ctrl+V) et le choix dans la médiathèque (`/gestion/mediatheque`), avec un repli sans JavaScript (champ fichier classique). Une image ajoutée entre dans la médiathèque ; la retirer d'une fiche ne supprime jamais le fichier.

