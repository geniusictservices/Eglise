<p align="center"><img src="branding/logo/png/waumini-horizontal.png" alt="Waumini" width="360"></p>

# Waumini

**Waumini** (« les fidèles » en swahili) est la plateforme de gestion des communautés de foi en RDC, développée par **Genius ICT** (Goma, Nord-Kivu). Elle s'adresse aux églises indépendantes comme aux dénominations avec siège, régions, secteurs et paroisses, et elle est ouverte aux autres communautés de foi grâce à des libellés renommables.

- **Feuille de route** : [docs/feuille-de-route.html](docs/feuille-de-route.html), aussi publiée en ligne
- **Identité visuelle** : [branding/](branding/README.md)
- **Manuel d'utilisation** : [docs/manuel/](docs/manuel/README.md)
- **Déploiement sur LWS** : [docs/deploiement-lws.md](docs/deploiement-lws.md)
- **Traductions** : [lang/a-traduire/](lang/a-traduire/LISEZMOI.md)

## Ce qui est disponible

**Site public** : page de présentation, inscription d’une communauté avec essai gratuit de 30 jours, demande de démonstration, page Abonnement (prix visibles seulement dans l’application), conditions d’utilisation et confidentialité.

**Fondations** :

- Hiérarchie multi-niveaux (siège, région, secteur, paroisse, annexe…), rattachement d'une paroisse inscrite seule à son siège.
- Connexion par numéro de téléphone et mot de passe, ou par empreinte, visage et Windows Hello (passkeys) ; mot de passe provisoire à changer à la première connexion.
- Centre d’aide intégré : le manuel d’utilisation sous `/aide`, avec captures ordinateur et téléphone.
- Rôles personnalisables par cases à cocher, attribués à un niveau précis, avec ou sans les niveaux inférieurs.
- Cloisonnement strict des données entre communautés.
- Journal d'audit inaltérable (chaîne d'empreintes SHA-256) avec vérification d'intégrité.
- Dollar comme devise de base, autres devises et taux du jour par communauté (hérité du niveau supérieur).
- Libellés renommables (« Pasteur » → « Imam »…), interface en français, traduction prévue en kiswahili, lingála, kikongo et tshiluba.
- Application installable (PWA) sur Android, iPhone et Windows.

## Technique

| | |
|---|---|
| Application | Laravel 13, Livewire 4, Alpine.js, Tailwind CSS 4 |
| Base de données | MySQL ou MariaDB |
| Hébergement | Mutualisé (LWS) : tâches de fond par cron, cache et sessions en base |
| Tests | PHPUnit sur MariaDB ; Playwright pour les captures du manuel |

## Démarrer en local

Prérequis : PHP 8.3, Composer, Node 22, MariaDB ou MySQL.

```bash
composer install
npm install && npm run build
cp .env.example .env && php artisan key:generate
# Renseigner DB_DATABASE, DB_USERNAME, DB_PASSWORD dans .env
php artisan migrate --seed      # crée la communauté de démonstration
php artisan serve
```

Ouvrez http://localhost:8000 et connectez-vous avec la communauté de démonstration (fictive) :

| Rôle | Téléphone | Mot de passe |
|---|---|---|
| Administrateur (siège) | 0990 000 001 | Waumini2026 |
| Pasteur (siège et niveaux inférieurs) | 0990 000 002 | Waumini2026 |
| Trésorier (paroisse de Himbi) | 0990 000 007 | Waumini2026 |

## Tests

```bash
php artisan test        # base waumini_test (voir phpunit.xml)
vendor/bin/pint         # style du code
```

## Organisation du code

| Dossier | Contenu |
|---|---|
| `app/Models` | Organisations (arbre à chemin matérialisé), utilisateurs, rôles, journal, devises |
| `app/Models/Concerns` | `BelongsToOrganization` (cloisonnement), `Auditable` (journal automatique) |
| `app/Services` | Création des communautés, journal d'audit, taux de change |
| `app/Livewire` | Écrans de l'application |
| `config/waumini.php` | Catalogue des permissions, rôles modèles, devises, langues |
| `resources/views` | Vues Blade ; `layouts/app` (application), `layouts/guest` (connexion) |
| `public/sw.js`, `public/manifest.webmanifest` | Application installable |
| `docs/` | Feuille de route, manuel, guides |
