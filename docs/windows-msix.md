# Waumini sur Windows : le fichier d'installation MSIX

Waumini s'installe déjà sur Windows **depuis Edge ou Chrome** (bouton « Installer », voir le [manuel](manuel/02-installer.md)) : c'est la voie la plus simple, et elle suffit à la plupart des églises. Le fichier **MSIX** est l'installateur Windows classique, utile pour :

- publier Waumini dans le **Microsoft Store** (on le trouve en cherchant « Waumini ») ;
- l'installer sur les ordinateurs d'une communauté **sans passer par le navigateur**.

Le paquet MSIX contient l'application web de `https://waumini.com` (la même que sur le téléphone) : **Internet reste nécessaire**, et chaque mise à jour du site est aussitôt dans l'application, sans republier le paquet.

## Ce qui est déjà prêt dans Waumini

- Le manifeste `public/manifest.webmanifest` : nom, couleurs, `start_url`, `display: standalone`, `display_override: window-controls-overlay` (barre de titre intégrée sous Windows).
- Les icônes Windows dans `public/icons/windows/` : `Square44x44Logo`, `Square71x71Logo`, `Square150x150Logo`, `Square310x310Logo`, `Wide310x150Logo`, `SplashScreen`, aux tailles demandées par Windows.
- Le service worker `public/sw.js` : page hors ligne, notifications.
- HTTPS en production (obligatoire).

## 1. Le compte développeur Microsoft (pour le Store)

1. Créer un compte sur **Partner Center** (https://partner.microsoft.com), en compte **entreprise** au nom de Genius ICT (vérification de la société, quelques jours) ou **individuel** (plus rapide).
2. Dans *Applications et jeux*, **Nouveau produit › Application MSIX ou PWA**, et **réserver le nom** « Waumini ».
3. Dans *Gestion du produit › Identité du produit*, noter les trois valeurs : **Package/Identity/Name** (ex. `GeniusICT.Waumini`), **Package/Identity/Publisher** (`CN=…`), **Publisher display name** (`Genius ICT`).

## 2. Produire le paquet avec PWABuilder

PWABuilder est l'outil de Microsoft qui transforme une application web installable en paquet MSIX.

1. Ouvrir https://www.pwabuilder.com et saisir `https://waumini.com`. Le rapport doit être vert pour le manifeste et le service worker.
2. **Package for stores › Windows › Generate package**, puis *Options* :
   - **Package ID**, **Publisher display name**, **Publisher ID** : les trois valeurs de Partner Center ;
   - **App name** : Waumini ; **App version** : `1.0.0` (augmenter à chaque nouvelle publication du paquet, par exemple `1.0.1`) ;
   - **Language** : `fr` ;
   - laisser *Use Windows widgets* et *Enable Windows Notifications* décochés.
3. Télécharger l'archive : elle contient `Waumini.msixbundle` (pour le Store) et `Waumini.classic.appxbundle` (pour Windows 10 ancien), avec un fichier d'instructions.

## 3a. Publier dans le Microsoft Store

1. Dans Partner Center, **Démarrer la soumission** : prix « Gratuit » (l'abonnement se paie dans Waumini), marchés (au moins la RD Congo et les pays voisins), catégorie *Productivité*, classification d'âge.
2. **Paquets** : envoyer les deux fichiers produits par PWABuilder.
3. **Fiche du Store**, en français : la description (reprendre l'accroche du site), au moins 4 captures d'écran **ordinateur** (prendre `docs/manuel/captures/bureau/` : tableau de bord, membres, collecte du culte, budget), l'icône 300 × 300.
4. **Soumettre**. La vérification par Microsoft prend en général un à trois jours. Microsoft signe le paquet : rien à signer soi-même.

## 3b. Installer sans le Store

Un paquet MSIX installé hors du Store doit être **signé** par un certificat que l'ordinateur reconnaît.

- **Avec un certificat de signature de code** (acheté chez une autorité reconnue, environ 200 à 400 $ par an) : signer le paquet avec `signtool sign /fd SHA256 /a /f certificat.pfx /p MOTDEPASSE Waumini.msixbundle`. Il s'installe alors d'un double-clic sur n'importe quel Windows 10 ou 11 ; on peut le proposer au téléchargement sur `waumini.com/installer`.
- **Sans certificat** : réservé aux tests chez Genius ICT. Créer un certificat de test dans PowerShell (`New-SelfSignedCertificate -Type Custom -Subject "CN=…" -KeyUsage DigitalSignature -CertStoreLocation "Cert:\CurrentUser\My" -TextExtension @("2.5.29.37={text}1.3.6.1.5.5.7.3.3", "2.5.29.19={text}")`), signer le paquet, puis installer le certificat dans *Personnes autorisées* sur l'ordinateur de test. À ne pas faire chez les communautés.

Recommandation : **le Store**, qui signe gratuitement, met à jour tout seul et rassure les utilisateurs ; et, en attendant, l'installation depuis Edge.

## 4. Après la publication

- Le bouton **Installer** de Waumini continue de fonctionner : les deux voies coexistent.
- Mettre à jour le [manuel](manuel/02-installer.md) avec le lien du Store, et ajouter ce lien sur la page `waumini.com/installer`.
- Republier le paquet seulement si le nom, les icônes ou le manifeste changent ; les nouveautés de Waumini arrivent sans republier.
