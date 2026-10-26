# Photos des produits et des établissements

Trois sortes d'images peuvent illustrer le menu et la vitrine :

| Sorte | Où | Crédit |
|---|---|---|
| **Vraies photos du client** (recommandé) | envoyées depuis l'espace de gestion, enregistrées dans `public/uploads/<sous-domaine>/` | à indiquer seulement si la photo n'est pas la sienne |
| **Photos libres de droits de démonstration** | importées de Wikimedia Commons par `bin/importer-photos.php`, dans `public/assets/img/demo/` | obligatoire, rempli automatiquement |
| **Illustrations SVG de démonstration** | dessins originaux du projet, `public/assets/img/demo/…/*.svg` (`database/demo-illustrations.sql`) | aucun |

## 1. Ajouter les vraies photos

Les photos prises sur place (ou fournies par le fournisseur avec son accord) valent mieux que n'importe quelle photo générique.

- **Produit** : Gestion → Catalogue → cliquer sur le produit → bloc « Photo » → « Choisir une photo » → « Enregistrer la photo ».
- **Couverture d'un établissement** : Gestion → Établissements → l'établissement → « Photo de couverture ».
- Formats acceptés : JPEG, PNG ou WebP, 8 Mo au plus. L'image est vérifiée, redressée (photo de téléphone), réduite et ré-encodée en JPEG ; le nom du fichier d'origine n'est pas gardé.
- Conseils : une photo bien éclairée, le produit au centre (le menu affiche une vignette carrée) ; pour la couverture, une photo en largeur (paysage).
- Si la photo n'est pas la vôtre, remplissez « Crédit de la photo » (exemple : `Jean K. · CC BY 4.0`). Il s'affiche sous la photo sur la vitrine.

Une photo envoyée depuis la gestion n'est jamais remplacée par l'import automatique (sauf avec `--forcer`, voir plus bas).

## 2. Importer les photos libres de droits (démonstration)

La liste des produits de démonstration et des recherches à faire est dans `database/photos-demo.json` : pour chaque produit (identifiant de `database/demo.sql`) et chaque couverture, une ou deux requêtes en anglais, génériques et sans nom de marque, ainsi que la liste des marques et des mots à écarter.

```sh
php bin/importer-photos.php --instance=demo --essai    # montre ce qui serait importé, n'écrit rien
php bin/importer-photos.php --instance=demo            # importe
php bin/importer-photos.php --instance=demo --forcer   # remplace aussi les photos déjà en place
php bin/importer-photos.php --sql                      # UPDATE des photos importées, pour une nouvelle démo
```

Pour chaque entrée, le script :

1. cherche dans Wikimedia Commons (espace de noms Fichier) avec chaque requête, dans l'ordre ;
2. garde la première image qui respecte les règles ci-dessous et qui n'est pas déjà utilisée pour une autre entrée ;
3. télécharge la vignette de 1 200 px, vérifie le type réel et les dimensions, la ré-encode en JPEG (1 000 px de côté au plus, qualité 82) dans `public/assets/img/demo/produits/<id>-<slug>.jpg` ou `public/assets/img/demo/etablissements/<slug>.jpg` ;
4. met à jour la base de l'instance : `produits.photo` et `photo_credit`, `etablissements.couverture` et `couverture_credit`, avec un crédit au format « Auteur · Licence · Wikimedia Commons » ;
5. réécrit `docs/credits-photos.md` (tableau des crédits) et `public/assets/img/demo/credits.json`.

Les illustrations SVG sont remplacées ; une photo déjà importée ou envoyée depuis la gestion est gardée. Par précaution, une entrée est ignorée si le produit de même identifiant porte un autre nom dans l'instance (ce n'est alors pas la démonstration). Le script attend une seconde entre deux requêtes et s'identifie par l'en-tête `User-Agent: restau-bar-pos/1.0 (import de photos de démonstration)`, sans donnée personnelle.

Codes de sortie : `0` tout est importé, `1` erreur d'utilisation ou de configuration, `2` aucune photo convenable pour certaines entrées (le script les nomme : ajouter ou changer les requêtes dans `photos-demo.json`), `3` accès réseau refusé.

Pour qu'une **nouvelle** instance de démonstration ait les photos dès sa création : après un import réussi, coller la sortie de `php bin/importer-photos.php --sql` à la fin de `database/demo-illustrations.sql` (ce fichier est chargé après `demo.sql` par `bin/creer-instance.php --demo` ; des UPDATE placés dans `demo.sql` seraient écrasés par les illustrations), et versionner les fichiers `.jpg` de `public/assets/img/demo/`.

## 3. Accès réseau à autoriser

Dans un environnement dont l'accès à Internet est filtré (par exemple un environnement Claude Code dans le cloud), l'import a besoin de deux domaines :

- `commons.wikimedia.org` (recherche, API) ;
- `upload.wikimedia.org` (téléchargement des images).

Réglages de l'environnement → **Network access** → domaines autorisés : ajouter ces deux domaines, puis relancer `php bin/importer-photos.php --instance=demo`. Tant qu'ils sont refusés, le script s'arrête dès la première requête, affiche les domaines à autoriser et se termine avec le code 3, sans rien modifier. S'il est interrompu en cours de route, les photos déjà importées sont gardées et la commande peut simplement être relancée.

Sur un serveur ordinaire, rien n'est à régler : PHP utilise l'extension curl (ou, à défaut, les flux HTTPS de PHP) et le proxy indiqué par `HTTPS_PROXY` s'il y en a un.

## 4. Règles de licence

Seules les images qu'on peut utiliser sur le menu d'un établissement commercial sont retenues :

| Licence | Retenue | Crédit |
|---|---|---|
| CC0, domaine public | oui | conseillé (le script l'indique toujours) |
| CC BY, toutes versions (2.0, 3.0, 4.0, versions nationales) | oui | **obligatoire** : auteur, licence, source |
| CC BY-SA, toutes versions | oui | **obligatoire** ; une version modifiée de la photo doit rester sous la même licence |
| CC BY-NC, CC BY-ND, CC BY-NC-SA… | non | pas d'usage commercial ou pas de modification (le redimensionnement en est une) |
| GFDL seule, « fair use », licence absente | non | |

Autres règles appliquées :

- **Pas de marques** : un titre ou une description qui contient un nom de la liste `marques_exclues` (bières, sodas, champagnes, spiritueux, chaînes de restauration…) est écarté, ainsi que les fichiers marqués « trademarked » sur Commons et les titres contenant un mot de `mots_exclus` (logo, étiquette, affiche, dessin…). Un produit de marque (Primus, Heineken…) reçoit donc une photo générique (une bouteille de bière, un verre de soda…).
- JPEG ou PNG uniquement, en paysage, au moins 800 px de large.
- Le crédit ne doit jamais être retiré tant que la photo est affichée. Si le client remplace la photo par la sienne, il peut effacer le crédit.
- Avant une utilisation au-delà de la démonstration (impression, publicité), vérifier la page source de chaque photo (lien dans `docs/credits-photos.md`) : une personne reconnaissable ou un logo visible sur l'image peuvent demander une autorisation en plus de la licence.
