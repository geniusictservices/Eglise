<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Acces;
use App\Core\Avatars;
use App\Core\CaissePartagee;
use App\Core\Database;
use App\Core\Journal;
use App\Core\Plateforme;
use App\Core\PinRefuse;
use App\Core\PinSuperieur;
use App\Core\Remises;
use App\Core\Reglement;
use App\Core\Session;
use App\Core\View;
use App\Repositories\CatalogueRepository;
use App\Repositories\DetteRepository;
use App\Repositories\DeviseRepository;
use App\Repositories\EtablissementRepository;
use App\Repositories\NoteRepository;
use App\Repositories\ServiceRepository;
use App\Repositories\SessionCaisseRepository;
use App\Repositories\StockRepository;
use App\Repositories\VenteRepository;

/**
 * Écran de caisse : produits en grille et ticket en cours.
 * L'écran s'adapte au type d'établissement (mode comptoir rapide et thème sombre pour un nightclub).
 * L'encaissement enregistre la vente (encaisser()) : prix recalculés, paiements dans la trésorerie.
 */
final class CaisseController
{
    /** Caisse de l'entrée (billetterie, contrôle des billets, invités) : un point de vente sans stock. */
    public function entree(): void
    {
        $this->index('entree');
    }

    /** Vestiaire : dépôt (frais facultatifs), retour, objets encore au vestiaire. */
    public function vestiaire(): void
    {
        $this->index('vestiaire');
    }

    /** Permission qui ouvre chaque écran de caisse (le bar, l'entrée, le vestiaire). */
    public const POSTES = [
        'caisse' => ['caisse.vendre'],
        'entree' => ['entrees.vendre', 'entrees.controler', 'invites.pointer'],
        'vestiaire' => ['vestiaire.gerer'],
    ];

    /** Écrans de caisse que la personne peut ouvrir dans cet établissement (dans l'ordre : bar, entrée, vestiaire). */
    public static function postesPermis(int $utilisateurId, int $etabId): array
    {
        $postes = [];
        foreach (self::POSTES as $poste => $droits) {
            if ($poste !== 'caisse' && (!Plateforme::moduleInclus('nightclub') || !\App\Repositories\BilletterieRepository::disponible())) {
                continue;
            }
            foreach ($droits as $droit) {
                if (Acces::a($utilisateurId, $droit, $etabId)) {
                    $postes[] = $poste;
                    break;
                }
            }
        }
        return $postes;
    }

    public function index(string $poste = 'caisse'): void
    {
        $etablissements = new EtablissementRepository();
        $appareil = CaissePartagee::appareil();
        $chemin = $poste === 'caisse' ? '/caisse' : '/caisse/' . $poste;

        if ($appareil !== null) {
            // Caisse partagée : l'établissement est celui de l'appareil ; chacun entre avec son PIN
            $etab = $etablissements->parSlug($appareil['slug']);
            if ($etab === null) {
                pageIntrouvable();
            }
            $caissier = CaissePartagee::caissier();
            if ($caissier !== null && !in_array($poste, self::postesPermis($caissier['id'], (int) $etab['id']), true)) {
                // Un agent d'entrée (ou du vestiaire) arrive sur son écran ; sinon, la caisse se verrouille
                $autres = self::postesPermis($caissier['id'], (int) $etab['id']);
                if ($poste === 'caisse' && $autres !== []) {
                    rediriger('/caisse/' . $autres[0]);
                }
                CaissePartagee::verrouiller();
                $caissier = null;
            }
            if ($caissier === null) {
                // ?personne=<id> : pavé du PIN de cette personne (sans JavaScript)
                $choisi = (int) ($_GET['personne'] ?? 0);
                $this->afficherQui($appareil, $etab, null, $choisi > 0 ? $choisi : null, 200, $poste);
                return;
            }
            $personne = $caissier;
            $liste = [$etab];
        } else {
            // Appareil personnel : il faut être connecté à la gestion avec le droit d'ouvrir cet écran
            $utilisateur = Session::exigerConnexion();
            $uid = (int) $utilisateur['id'];
            $liste = array_values(array_filter($etablissements->actifs(), static fn (array $e) => in_array($poste, self::postesPermis($uid, (int) $e['id']), true)));
            if ($liste === []) {
                // Pas de caisse du bar, mais l'entrée ou le vestiaire : son écran
                foreach ($etablissements->actifs() as $e) {
                    $autres = self::postesPermis($uid, (int) $e['id']);
                    if ($poste === 'caisse' && $autres !== []) {
                        rediriger('/caisse/' . $autres[0] . '?e=' . $e['slug']);
                    }
                }
                http_response_code(403);
                // Instance neuve : aucun établissement encore, ce n'est pas une question de rôle
                $texte = $etablissements->actifs() === [] ? t('caisse.sans_etablissement') : null;
                View::afficher('gestion/interdit', ['titre' => t('acces.interdit_titre'), 'utilisateur' => $utilisateur, 'texte' => $texte], 'gestion');
                return;
            }
            $etab = $liste[0];
            if (isset($_GET['e']) && is_string($_GET['e'])) {
                foreach ($liste as $candidat) {
                    if ($candidat['slug'] === $_GET['e']) {
                        $etab = $candidat;
                    }
                }
            }
            $personne = ['id' => $uid, 'nom' => $utilisateur['nom']];
        }
        $id = (int) $etab['id'];
        $nuit = $poste !== 'caisse';
        $zones = $nuit ? [] : $etablissements->zones($id);
        $contexte = $nuit ? $this->contexteNuit($etab, $poste) : $this->contexte($etab, is_string($_GET['zone'] ?? null) ? $_GET['zone'] : null);
        $zone = $contexte['zone'];
        $donnees = $contexte['donnees'];
        // Comptes de trésorerie où peuvent entrer les paiements de cet établissement, par type, avec leurs devises
        $donnees['comptes'] = self::comptesCaisse($id, $contexte['acceptees']);
        $donnees['encaisser'] = Acces::a($personne['id'], match ($poste) { 'entree' => 'entrees.vendre', 'vestiaire' => 'vestiaire.gerer', default => 'caisse.encaisser' }, $id);
        // Ardoise : mettre à crédit (caisse.credit), passer outre plafond et retard (dettes.gerer), rembourser (caisse.rembourser)
        $donnees['credit'] = !$nuit && $donnees['encaisser'] && Acces::a($personne['id'], 'caisse.credit', $id);
        $donnees['forcer'] = !$nuit && Acces::a($personne['id'], 'dettes.gerer', $id);
        $donnees['rembourser'] = !$nuit && Acces::a($personne['id'], 'caisse.rembourser', $id);
        $donnees['moi'] = $personne['id'];
        $donnees['personnel'] = $donnees['credit'] ? self::personnel($id) : [];
        $donnees['delai'] = (int) $etab['credit_delai_jours'];
        $donnees['aujourdhui'] = date('Y-m-d');
        $donnees['garanties'] = $nuit ? [] : CaisseDetteController::garantiesJson($personne['id']);
        // Sessions de caisse (au choix de l'établissement) : pas d'encaissement sans session ouverte
        $donnees['sessions'] = SessionCaisseRepository::obligatoires($etab) && ($donnees['encaisser'] || $donnees['rembourser']);
        $donnees['session'] = null;
        if ($donnees['sessions']) {
            $session = (new SessionCaisseRepository())->ouverte($id, $personne['id']);
            $donnees['session'] = $session ? ['id' => (int) $session['id'], 'depuis' => date('H:i', strtotime($session['ouverte_le']))] : null;
            $donnees['caissesACompter'] = (new SessionCaisseRepository())->caissesACompter($id, $contexte['acceptees']);
        }
        $donnees['etab'] = $etab['slug'];
        $donnees['zone'] = $zone['code'] ?? '';
        $donnees['poste'] = $poste;
        // Salle et cuisine : plan de salle, notes ouvertes, envoi en cuisine (null sans le module, ni à l'entrée)
        $donnees['grille'] = $contexte['grille'];
        $donnees['salle'] = $nuit ? null : CaisseNoteController::donneesCaisse($etab, $personne, $contexte, $zones);
        // Nuit : remises, formules, minimum, cashless, fidélité, entrée et vestiaire (caisse-nuit.js)
        $donnees['nuit'] = CaisseNuitController::donneesCaisse($etab, $personne, $poste, $contexte, $appareil);
        $personne['avatar'] = Database::un('SELECT avatar FROM utilisateurs WHERE id = ?', [$personne['id']])['avatar'] ?? null;
        Session::demarrer();
        $messagePhoto = $_SESSION['caisse_photo'] ?? null;
        // Copie demandée par le service worker (première visite) : le message reste pour la vraie page
        if (empty($_SERVER['HTTP_X_CAISSE_GARDE'])) {
            unset($_SESSION['caisse_photo']);
        }
        // Mode hors ligne (caisse-sw.js) : le service worker garde la caisse du bar de chaque personne (et de chaque zone)
        // pour la montrer sans réseau ; jamais l'entrée ni le vestiaire, qui demandent le réseau
        header('X-Caisse-Page: ' . ($poste === 'caisse' ? 'vente' : 'autre'));
        header('X-Caisse-Personne: ' . (int) $personne['id']);
        header('X-Caisse-Partagee: ' . ($appareil !== null ? '1' : '0'));
        View::afficher('caisse/index', [
            'titre' => $nuit ? t('nuit.poste_' . $poste) : t('caisse.titre'),
            'utilisateur' => $personne,
            'partagee' => $appareil !== null,
            'appareil' => $appareil,
            // « Revenir aux modules » seulement pour qui a des modules de gestion
            'aLaGestion' => Acces::aLaGestion($personne['id']),
            'peutAutoriser' => $appareil === null && Acces::a($personne['id'], 'caisse.appareils', $id),
            'photoPermise' => (bool) $etab['photo_a_la_caisse'],
            'scripts' => ['/assets/vendor/qrcode.js', '/assets/js/caisse.js', '/assets/js/caisse-ardoise.js', '/assets/js/caisse-session.js', '/assets/js/caisse-salle.js',
                '/assets/js/scanner-qr.js', '/assets/js/caisse-nuit.js', '/assets/js/photo-profil.js', '/assets/js/caisse-hors-ligne-base.js', '/assets/js/caisse-hors-ligne.js'],
            'horsLigne' => CaisseHorsLigneController::donnees($etab, $appareil, $personne, $zone, $poste === 'caisse' ? 'vente' : 'autre', Session::jetonCsrf())
                + ['cuisine' => $poste === 'caisse' ? CaisseHorsLigneController::categoriesCuisine($etab, $donnees, $zone, $appareil) : []],
            'service' => $contexte['service'], 'happy' => $contexte['happy'],
            'messagePhoto' => $messagePhoto,
            'etab' => $etab,
            'etablissements' => $liste,
            'zones' => $zones,
            'zone' => $zone,
            'poste' => $poste,
            'postes' => self::postesPermis($personne['id'], $id),
            'chemin' => $chemin,
            // Ticket gardé par personne : un serveur ne retrouve pas le ticket du précédent
            'regle' => $appareil !== null ? CaissePartagee::regle($etab) : null,
            // Verrouillage choisi par l'administrateur (caisses partagées seulement)
            'donnees' => $donnees + ['cleTicket' => $etab['slug'] . '.' . $personne['id'] . ($nuit ? '.' . $poste : ''), 'verrou' => [
                'minutes' => $appareil !== null ? (int) $etab['verrou_minutes'] : 0,
                'apresEncaissement' => $appareil !== null && (bool) $etab['verrou_apres_encaissement'],
            ]],
        ], 'caisse');
    }

    /**
     * Catalogue de l'entrée (les tarifs de la soirée) ou du vestiaire (le dépôt, avec ses frais), dans la même forme
     * que contexte() : la grille, le ticket et la fenêtre de paiement de la caisse servent tels quels.
     */
    public function contexteNuit(array $etab, string $poste): array
    {
        ['acceptees' => $acceptees, 'principale' => $principale] = self::devisesCaisse($etab);
        $produits = [];
        $categories = [];
        $soiree = null;
        $tarifs = [];
        if ($poste === 'entree') {
            $billetterie = new \App\Repositories\BilletterieRepository();
            $soiree = $billetterie->soiree($etab);
            $tarifs = $billetterie->tarifsEnVente($etab, $soiree['evenement'], $acceptees);
            $categories[] = ['id' => 1, 'nom' => t('nuit.entrees'), 'sous' => []];
            foreach ($tarifs as $t) {
                $produits[] = self::produitSimple($t['id'], 1, $t['nom'], t('nuit.categorie_' . $t['categorie']), $t['prix']) + ['entree' => $t['categorie']];
            }
            // Tarif réduit d'un invité de la soirée qui n'est pas en vente à tous (soirée à tarifs propres) : caché de la
            // grille, ajouté au ticket quand l'invité est pointé
            if ($soiree['evenement'] !== null) {
                $vendus = array_flip(array_column($tarifs, 'id'));
                foreach (Database::tous("SELECT DISTINCT tarif_id FROM invites WHERE evenement_id = ? AND entree = 'reduite' AND tarif_id IS NOT NULL", [(int) $soiree['evenement']['id']]) as $r) {
                    $t = isset($vendus[(int) $r['tarif_id']]) ? null : $billetterie->tarif((int) $r['tarif_id']);
                    if ($t !== null && (int) $t['etablissement_id'] === (int) $etab['id']) {
                        $produits[] = self::produitSimple((int) $t['id'], 1, champ($t, 'nom'), t('nuit.categorie_' . $t['categorie']), $billetterie->prix((int) $t['id'], $acceptees))
                            + ['entree' => $t['categorie'], 'cache' => true];
                    }
                }
            }
        } else {
            $frais = (new \App\Repositories\VestiaireRepository())->frais((int) $etab['id']);
            $categories[] = ['id' => 1, 'nom' => t('nuit.vestiaire'), 'sous' => []];
            $produits[] = self::produitSimple(1, 1, t('nuit.vestiaire_depot'), $frais === [] ? t('nuit.gratuit') : t('nuit.vestiaire_frais'),
                \App\Repositories\BilletterieRepository::prixDevises($frais, $acceptees));
        }
        $donnees = [
            'mode' => 'rapide',
            'langue' => \App\Core\I18n::langue(),
            'principale' => $principale['code'],
            'devises' => self::devisesJson($acceptees),
            'categories' => $categories,
            'produits' => $produits,
            'epuises' => [],
        ];
        return [
            'zone' => null, 'grille' => (int) $etab['grille_prix_id'], 'grilleDefaut' => (int) $etab['grille_prix_id'], 'acceptees' => $acceptees,
            'principale' => $principale, 'service' => null, 'happy' => null, 'source' => 'etablissement', 'epuises' => [],
            'donnees' => $donnees, 'soiree' => $soiree, 'tarifs' => $tarifs,
        ];
    }

    /** Une tuile d'un seul format (tarif d'entrée, dépôt au vestiaire). */
    private static function produitSimple(int $id, int $categorie, string $nom, string $format, array $prix): array
    {
        return [
            'id' => $id, 'categorie' => $categorie, 'sous' => 0, 'nom' => $nom, 'recherche' => mb_strtolower($nom . ' ' . $format),
            'disponible' => true, 'photo' => null, 'tailles' => 0,
            'formats' => [['id' => $id, 'nom' => $format, 'unite' => false, 'taille' => '', 'tailleId' => $id, 'freres' => 1, 'disponible' => true, 'prix' => $prix]],
        ];
    }

    /** Devises de la caisse pour caisse.js (ordre : locale, dollar, autres). */
    public static function devisesJson(array $acceptees): array
    {
        return array_values(array_map(static fn (array $d) => [
            'code' => $d['code'],
            'symbole' => $d['symbole'],
            'decimales' => (int) $d['decimales'],
            'symboleAvant' => (bool) $d['symbole_avant'],
            'arrondi' => (float) $d['arrondi'],
        ], $acceptees));
    }

    /**
     * Zone, grille de prix, devises acceptées, devise du ticket et catalogue de la caisse pour un établissement.
     * @return array{zone: ?array, grille: int, grilleDefaut: int, acceptees: array<int, array>, principale: array, donnees: array}
     */
    public function contexte(array $etab, ?string $codeZone, bool $filtrer = true, ?\DateTimeImmutable $moment = null): array
    {
        $etablissements = new EtablissementRepository();
        $id = (int) $etab['id'];
        $zone = $codeZone !== null && $codeZone !== '' ? $etablissements->zoneParCode($id, $codeZone) : null;
        $grilleDefaut = (int) $etab['grille_prix_id'];
        // Grille du moment : zone (Carré VIP), sinon happy hour, sinon service en cours, sinon établissement
        // ($moment : heure d'une vente faite sans réseau, pour la grille valable à cette heure-là)
        $choix = (new ServiceRepository())->grille($etab, $zone, $moment);
        $grille = $choix['grille'];
        $service = $choix['service'];
        // Catégories du service en cours (la cuisine fermée disparaît de la caisse) ; $filtrer = false pour payer une note
        $categories = $filtrer && $service !== null ? $service['categories'] : [];
        ['acceptees' => $acceptees, 'principale' => $principale] = self::devisesCaisse($etab);
        $donnees = $this->donnees($id, $grille, $grilleDefaut, $acceptees, $principale, $etab['type'], $categories, $service);
        // Stock en mode « bloquer » : les formats que le comptoir ne peut plus fournir sont « épuisés »
        $epuises = self::epuises($etab, $zone);
        $donnees['epuises'] = $epuises;
        if ($epuises !== []) {
            $hors = array_flip($epuises);
            foreach ($donnees['produits'] as &$produit) {
                foreach ($produit['formats'] as &$format) {
                    if (isset($hors[$format['id']])) {
                        $format['disponible'] = false;
                        $format['epuise'] = true;
                    }
                }
                unset($format);
                // Tous ses formats épuisés : la tuile est grisée comme un produit en rupture
                if ($produit['formats'] !== [] && array_filter($produit['formats'], static fn (array $f) => $f['disponible']) === []) {
                    $produit['disponible'] = false;
                }
            }
            unset($produit);
        }
        // Formules (bouteille avec softs) : groupes de composants à choisir, avec ce qui est épuisé
        $formules = new \App\Repositories\FormuleRepository();
        $ids = array_flip($formules->produits());
        if ($ids !== []) {
            $hors = array_flip($epuises);
            foreach ($donnees['produits'] as &$produit) {
                if (!isset($ids[$produit['id']]) || ($f = $formules->formule((int) $produit['id'])) === null) {
                    continue;
                }
                $produit['formule'] = array_map(static fn (array $g) => [
                    'nom' => $g['nom'], 'choix' => $g['choix'],
                    'options' => array_map(static fn (array $o) => ['id' => $o['id'], 'nom' => $o['libelle'], 'format' => $o['format'],
                        'epuise' => !$o['disponible'] || isset($hors[$o['id']])], $g['options']),
                ], $f['groupes']);
            }
            unset($produit);
        }
        return [
            'zone' => $zone, 'grille' => $grille, 'grilleDefaut' => $grilleDefaut, 'acceptees' => $acceptees, 'principale' => $principale,
            'service' => $service, 'happy' => $choix['happy'], 'source' => $choix['source'], 'epuises' => $epuises,
            'donnees' => $donnees,
        ];
    }

    /**
     * Formats épuisés au comptoir qui les sert (module stock, mode « bloquer » de l'établissement ; [] en mode
     * « vendre quand même » ou sans module stock). Le comptoir dépend de la catégorie, de l'appareil et de la zone.
     * @return list<int> identifiants de conditionnements
     */
    public static function epuises(array $etab, ?array $zone): array
    {
        if (!class_exists(\App\Repositories\StockRepository::class) || !method_exists(\App\Repositories\StockRepository::class, 'epuises')) {
            return [];
        }
        try {
            $appareil = CaissePartagee::appareil();
            $liste = array_map('intval', (new \App\Repositories\StockRepository())->epuises((int) $etab['id'], $appareil ? (int) $appareil['id'] : null, $zone ? (int) $zone['id'] : null));
            // Une formule n'a pas de stock à elle : elle est épuisée quand un de ses groupes ne peut plus être servi
            $formules = new \App\Repositories\FormuleRepository();
            $produits = $liste === [] ? [] : $formules->produits();
            if ($produits !== []) {
                $formats = Database::tous('SELECT id, produit_id FROM conditionnements WHERE produit_id IN (' . implode(',', $produits) . ')');
                $deFormule = array_flip(array_map('intval', array_column($formats, 'id')));
                $liste = array_values(array_filter($liste, static fn (int $id) => !isset($deFormule[$id])));
                foreach ($formats as $f) {
                    $formule = $formules->formule((int) $f['produit_id']);
                    if ($formule !== null && $formules->epuisee($formule, $liste)) {
                        $liste[] = (int) $f['id'];
                    }
                }
            }
            return $liste;
        } catch (\Throwable $e) {
            error_log('Stock épuisé non lu : ' . $e->getMessage());
            return [];
        }
    }

    /** @var array<string, array<int, array{format: array, produit: array}>> formats de chaque grille, par établissement */
    private array $formatsGrilles = [];

    /**
     * Formats vendus par l'établissement dans une grille, avec leurs prix dans chaque devise acceptée, sans filtre
     * de service (pour chiffrer les lignes d'une note commandées plus tôt, avec la grille du moment de la commande).
     * @return array<int, array{format: array, produit: array}>
     */
    public function formatsDeGrille(array $etab, int $grille): array
    {
        $cle = $etab['id'] . '-' . $grille;
        if (!isset($this->formatsGrilles[$cle])) {
            ['acceptees' => $acceptees, 'principale' => $principale] = self::devisesCaisse($etab);
            $formats = [];
            foreach ($this->donnees((int) $etab['id'], $grille, (int) $etab['grille_prix_id'], $acceptees, $principale, $etab['type'], [], null)['produits'] as $produit) {
                foreach ($produit['formats'] as $format) {
                    $formats[$format['id']] = ['format' => $format, 'produit' => $produit];
                }
            }
            $this->formatsGrilles[$cle] = $formats;
        }
        return $this->formatsGrilles[$cle];
    }

    /**
     * Prix unitaires actuels de lignes de note, dans chaque devise acceptée, avec la grille de leur commande
     * (happy hour, VIP) ; à défaut (format retiré depuis), les prix calculés au moment de l'envoi.
     * @return array<int, array<string, ?float>> ligne → devise → prix
     */
    public function prixLignesNote(array $etab, array $lignes): array
    {
        $codes = array_column(self::devisesCaisse($etab)['acceptees'], 'code');
        $prix = [];
        foreach ($lignes as $l) {
            $grille = $l['grille_prix_id'] !== null ? (int) $l['grille_prix_id'] : (int) $etab['grille_prix_id'];
            $f = $this->formatsDeGrille($etab, $grille)[(int) $l['conditionnement_id']] ?? null;
            $envoi = json_decode((string) ($l['prix_devises'] ?? ''), true);
            foreach ($codes as $code) {
                $p = $f['format']['prix'][$code] ?? null;
                $p ??= is_array($envoi) && is_numeric($envoi[$code] ?? null) ? (float) $envoi[$code] : null;
                $prix[(int) $l['id']][$code] = $p === null ? null : (float) $p;
            }
        }
        return $prix;
    }

    /**
     * Devises acceptées par l'établissement et devise du ticket : la devise locale, le dollar en deuxième
     * position (ordre de DeviseRepository::actives()).
     * @return array{acceptees: array<int, array>, principale: array}
     */
    public static function devisesCaisse(array $etab): array
    {
        $devises = new DeviseRepository();
        $acceptees = $devises->accepteesParmi((new EtablissementRepository())->deviseIds((int) $etab['id']));
        $locale = $devises->locale();
        $principale = ($locale ? ($acceptees[(int) $locale['id']] ?? null) : null)
            ?? $acceptees[(int) $etab['devise_affichage_id']] ?? $devises->reference() ?? reset($acceptees);
        return ['acceptees' => $acceptees, 'principale' => $principale];
    }

    /**
     * Comptes actifs de l'établissement (ou communs à tous) qui tiennent au moins une devise acceptée,
     * rangés par type (caisse, mobile money, banque), ceux de l'établissement avant les communs, puis dans l'ordre de la trésorerie (position).
     * Sans la caisse des achats au marché (fonds du chef), sauf si c'est la seule caisse (StockRepository::fondsMarcheHorsCaisse).
     * @return list<array{id: int, nom: string, type: string, commun: bool, devises: list<string>}>
     */
    public static function comptesCaisse(int $etablissementId, array $acceptees): array
    {
        $codes = array_column($acceptees, 'code', 'id');
        $lignes = Database::tous(
            "SELECT c.id, c.nom, c.type, c.etablissement_id, cd.devise_id
               FROM comptes_tresorerie c JOIN compte_devises cd ON cd.compte_id = c.id
              WHERE c.active = 1 AND (c.etablissement_id IS NULL OR c.etablissement_id = ?) AND c.id <> ?
              ORDER BY FIELD(c.type, 'caisse', 'mobile_money', 'banque'), c.etablissement_id IS NULL, c.position, c.nom, c.id",
            [$etablissementId, StockRepository::fondsMarcheHorsCaisse($etablissementId) ?? 0]
        );
        $comptes = [];
        foreach ($lignes as $ligne) {
            $code = $codes[(int) $ligne['devise_id']] ?? null;
            if ($code === null) {
                continue;
            }
            $id = (int) $ligne['id'];
            $comptes[$id] ??= ['id' => $id, 'nom' => $ligne['nom'], 'type' => $ligne['type'], 'commun' => $ligne['etablissement_id'] === null, 'devises' => []];
            $comptes[$id]['devises'][] = $code;
        }
        // Devises dans l'ordre de la caisse (locale d'abord, dollar ensuite)
        $ordre = array_flip(array_values($codes));
        foreach ($comptes as &$compte) {
            usort($compte['devises'], static fn (string $a, string $b) => $ordre[$a] <=> $ordre[$b]);
        }
        return array_values($comptes);
    }

    /**
     * Personnes de l'établissement (un rôle pour lui ou pour tous) : qui a servi le client, et garants possibles
     * (un autre agent, le gérant, le promoteur…). « vend » : peut servir (caisse.vendre).
     * @return list<array{id: int, nom: string, avatar: ?string, vend: bool}>
     */
    public static function personnel(int $etablissementId): array
    {
        $lignes = Database::tous(
            'SELECT DISTINCT u.id, u.nom, u.avatar FROM utilisateurs u JOIN utilisateur_roles ur ON ur.utilisateur_id = u.id
              WHERE u.actif = 1 AND (ur.etablissement_id IS NULL OR ur.etablissement_id = ?) ORDER BY u.nom',
            [$etablissementId]
        );
        return array_map(static fn (array $u) => [
            'id' => (int) $u['id'], 'nom' => $u['nom'], 'avatar' => $u['avatar'],
            'vend' => Acces::a((int) $u['id'], 'caisse.vendre', $etablissementId),
        ], $lignes);
    }

    /**
     * Personne qui vend sur ce navigateur et son établissement : la personne connectée par PIN
     * (caisse partagée), ou le compte de gestion (appareil personnel, établissement « e »).
     * @return array{personne: array, etab: array, appareil: ?array}|null
     */
    public static function vendeur(?string $slug): ?array
    {
        $etablissements = new EtablissementRepository();
        $appareil = CaissePartagee::appareil();
        if ($appareil !== null) {
            $caissier = CaissePartagee::caissier();
            $etab = $etablissements->parSlug($appareil['slug']);
            return $caissier && $etab ? ['personne' => $caissier, 'etab' => $etab, 'appareil' => $appareil] : null;
        }
        $utilisateur = Session::utilisateur();
        $etab = $slug !== null ? $etablissements->parSlug($slug) : null;
        return $utilisateur && $etab ? ['personne' => ['id' => (int) $utilisateur['id'], 'nom' => $utilisateur['nom']], 'etab' => $etab, 'appareil' => null] : null;
    }

    /**
     * Encaisser le ticket : la caisse envoie les lignes (format, quantité ; composants d'une formule) et les paiements
     * (devise, compte, montant reçu ; cartes cashless ; ardoise), les remises et la fidélité. Le serveur recalcule les
     * prix, les remises, le minimum de consommation, les totaux et le règlement ; il refuse tout ce qui ne colle pas.
     * « poste » : caisse (le bar), entree (tarifs d'entrée : un billet à code QR par entrée), vestiaire (frais de dépôt :
     * un ticket par objet). Réponse JSON : numéro, reçu (HTML), et si la caisse est verrouillée.
     */
    public function encaisser(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        Session::demarrer();
        $jeton = $_POST['csrf'] ?? '';
        if (!is_string($jeton) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $jeton)) {
            $this->repondre(419, ['erreur' => t('encaisser.expire')]);
        }
        $demande = json_decode(is_string($_POST['vente'] ?? null) ? $_POST['vente'] : '', true);
        if (!is_array($demande)) {
            $this->repondre(422, ['erreur' => t('encaisser.illisible')]);
        }
        // Vente faite sans réseau, envoyée au retour du réseau (caisse-hors-ligne.js) : la personne vient de la signature
        // de la vente (sa clé hors ligne sur cet appareil) ou de la session ; l'heure est celle de l'appareil, bornée.
        // La vente a déjà eu lieu : ni le stock bloqué, ni l'abonnement, ni le pointage, ni une session fermée ne la
        // refusent ; ce qui aurait été refusé en ligne est noté sur la vente et au journal.
        $horsLigne = is_array($demande['hors_ligne'] ?? null)
            ? CaisseHorsLigneController::lireEnvoi($demande, (string) $_POST['vente'], is_string($_POST['signature'] ?? null) ? $_POST['signature'] : '')
            : null;
        $vendeur = $horsLigne !== null ? $horsLigne['vendeur'] : self::vendeur(is_string($demande['etab'] ?? null) ? $demande['etab'] : null);
        if ($vendeur === null) {
            $this->repondre(401, ['erreur' => t('encaisser.connexion')]);
        }
        ['personne' => $personne, 'etab' => $etab, 'appareil' => $appareil] = $vendeur;
        $etabId = (int) $etab['id'];
        $uid = (int) $personne['id'];
        $notes = $horsLigne['notes'] ?? [];
        $moment = $horsLigne['moment'] ?? null;
        // La caisse dit qui vend (D.moi) : une page restée ouverte au nom d'une autre personne ne vend jamais sous ce nom-ci
        if ($horsLigne === null && isset($demande['moi']) && (int) $demande['moi'] !== $uid) {
            $this->repondre(409, ['erreur' => t('horsligne.personne_changee'), 'code' => 'personne_changee']);
        }
        $poste = in_array($demande['poste'] ?? null, ['entree', 'vestiaire'], true) ? $demande['poste'] : 'caisse';
        if ($horsLigne !== null && $poste !== 'caisse') {
            $this->repondre(422, ['erreur' => t('horsligne.reseau_requis'), 'code' => 'hors_ligne_reseau']);
        }
        $droit = match ($poste) { 'entree' => 'entrees.vendre', 'vestiaire' => 'vestiaire.gerer', default => 'caisse.encaisser' };
        if (!Acces::a($uid, $droit, $etabId) || ($poste !== 'caisse' && !in_array($poste, self::postesPermis($uid, $etabId), true))) {
            if ($horsLigne === null) {
                $this->repondre(403, ['erreur' => t('encaisser.droit')]);
            }
            // Droit retiré depuis (ou personne désactivée) : la vente signée a eu lieu, elle est gardée et signalée
            $notes[] = 'droit';
        }
        $cle = is_string($demande['cle'] ?? null) ? $demande['cle'] : '';
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $cle)) {
            $this->repondre(422, ['erreur' => t('encaisser.illisible')]);
        }
        $ventes = new VenteRepository();
        if ($horsLigne !== null) {
            if (($deja = $ventes->parCle($cle)) !== null) {
                // Déjà reçue (envoi précédent dont la réponse s'est perdue, ou vente passée en ligne) : jamais deux ventes
                CaisseHorsLigneController::repondreEnvoi((int) $deja['id'], true);
            }
            // Session de caisse ouverte à l'heure de la vente (même close depuis) ; sans session : notée, pas refusée
            $session = CaisseHorsLigneController::sessionAuMoment($etab, $personne, $moment, $horsLigne['session'], $notes);
            if (CaisseHorsLigneController::pointageManquant($etab, $personne, $moment)) {
                $notes[] = 'pointage';
            }
            $this->momentTaux = $moment->format('Y-m-d H:i:s');
        } else {
            $session = self::sessionExigee($etab, $personne);
            // Pointage obligatoire pour ouvrir la caisse (réglage de l'établissement, module Personnel et paie) : refus 409
            CaissePointageController::exigerPointage($etab, $personne);
            if (($deja = $ventes->parCle($cle)) !== null) {
                // Double envoi : la même vente, sans rien refaire
                $this->repondreVente((int) $deja['id'], $etab, $appareil);
            }
        }
        if (isset($_SESSION['nuit_sans_vente'][$cle])) {
            // Double envoi d'entrées ou de dépôts gratuits : les mêmes billets
            $this->repondreSansVente($_SESSION['nuit_sans_vente'][$cle], $etab, $appareil);
        }

        // Payer une note ouverte (en entier, par articles ou une part égale) : les lignes viennent de la note
        $noteDemande = $poste === 'caisse' && is_array($demande['note'] ?? null) ? $demande['note'] : null;
        $note = null;
        if ($horsLigne !== null && ($noteDemande !== null || !empty($demande['credit']) || !empty($demande['cartes']) || !empty($demande['fidelite']))) {
            // Notes, ardoise, cashless et fidélité demandent le réseau : la caisse ne les met jamais en attente
            $this->repondre(422, ['erreur' => t('horsligne.reseau_requis'), 'code' => 'hors_ligne_reseau']);
        }
        if ($noteDemande !== null) {
            $note = (new NoteRepository())->note((int) ($noteDemande['id'] ?? 0));
            if ($note === null || (int) $note['etablissement_id'] !== $etabId || $note['type'] !== 'note') {
                $this->repondre(422, ['erreur' => t('encaisser.illisible')]);
            }
            if ($note['statut'] !== 'ouverte') {
                $this->repondre(409, ['erreur' => t('note.deja_fermee'), 'code' => 'note_changee']);
            }
            $contexte = $this->contexte($etab, $note['zone_code'], false);
        } elseif ($poste === 'caisse') {
            // Hors ligne : la grille valable à l'heure de la vente, et toute la carte (le service a pu changer depuis)
            $contexte = $this->contexte($etab, is_string($demande['zone'] ?? null) ? $demande['zone'] : null, $horsLigne === null, $moment);
        } else {
            $contexte = $this->contexteNuit($etab, $poste);
        }
        $acceptees = array_column($contexte['acceptees'], null, 'code');
        $principale = $contexte['principale'];
        $p = $principale['code'];
        $reference = $this->devisesRepo()->reference();
        $formats = [];
        foreach ($contexte['donnees']['produits'] as $produit) {
            foreach ($produit['formats'] as $format) {
                $formats[$format['id']] = ['format' => $format, 'produit' => $produit];
            }
        }
        $prixDe = static function (array $prixUnitaires, float $quantite) use ($acceptees): array {
            $brut = [];
            foreach (array_keys($acceptees) as $code) {
                $brut[$code] = ($prixUnitaires[$code] ?? null) === null ? null : (float) $prixUnitaires[$code] * $quantite;
            }
            return $brut;
        };

        // ---------- Lignes ----------
        // Chaque ligne de base : sa clé pour les remises (« i3 » : ligne du ticket, « n42 » : ligne de note), ses montants
        // bruts dans chaque devise, la ligne de vente, les composants d'une formule, les billets ou dépôts à créer
        $base = [];
        $partNote = null;
        $pourCuisine = [];
        if ($note !== null) {
            try {
                $partNote = NoteRepository::part($note, $noteDemande);
            } catch (\InvalidArgumentException) {
                $this->repondre(422, ['erreur' => t('encaisser.ligne_invalide')]);
            }
            // Un article déjà servi se paie toujours, même si le stock l'a épuisé depuis (on ne bloque jamais une vente
            // déjà servie) : le refus « épuisé » ne vaut que pour ajouter une ligne et pour la vente directe.
            // Prix recalculés avec la grille du moment de chaque commande (happy hour, VIP) ; quantités fractionnaires possibles
            $prixNote = $this->prixLignesNote($etab, array_column($partNote, 'ligne'));
            foreach ($partNote as $pn) {
                $l = $pn['ligne'];
                $q = $pn['quantite'];
                $pu = $prixNote[(int) $l['id']];
                if (($pu[$p] ?? null) === null) {
                    $this->repondre(422, ['erreur' => t('encaisser.ligne_invalide')]);
                }
                $composants = array_map(static fn (array $c) => $c + ['quantite' => $c['qte'] * $q], self::composantsNote($l));
                $base[] = [
                    'cle' => 'n' . (int) $l['id'], 'brut' => $prixDe($pu, $q), 'remisable' => true, 'composants' => $composants,
                    'ligne' => [
                        'produit_id' => $l['produit_id'] !== null ? (int) $l['produit_id'] : null,
                        'conditionnement_id' => $l['conditionnement_id'] !== null ? (int) $l['conditionnement_id'] : null,
                        'libelle' => $l['libelle'], 'format' => $l['format'], 'quantite' => $q, 'prix' => $pu[$p],
                        'prix_reference' => $reference ? ($pu[$reference['code']] ?? null) : null, 'nature' => $composants !== [] ? 'formule' : 'article',
                    ],
                ];
            }
        } else {
            $lignesDemande = is_array($demande['lignes'] ?? null) ? array_values($demande['lignes']) : [];
            // Stock en mode « bloquer » : la liste des articles épuisés, plutôt qu'un refus sans explication
            $epuisees = [];
            foreach ($poste === 'caisse' ? $lignesDemande : [] as $ligne) {
                $f = $formats[(int) ($ligne['id'] ?? 0)] ?? null;
                if ($f !== null && !empty($f['format']['epuise'])) {
                    $epuisees[] = $f['format']['taille'] !== '' ? $f['produit']['nom'] . ' ' . $f['format']['taille'] : $f['produit']['nom'];
                }
            }
            if ($epuisees !== [] && $horsLigne === null) {
                $this->repondre(422, ['erreur' => t('encaisser.epuise', ['articles' => implode(', ', array_unique($epuisees))]), 'code' => 'epuise']);
            }
            if ($epuisees !== []) {
                // Vendu sans réseau alors que le stock était à zéro (mode « bloquer ») : gardé, signalé ; le stock descend quand même
                $notes[] = 'stock';
            }
            $formules = new \App\Repositories\FormuleRepository();
            $billetterie = new \App\Repositories\BilletterieRepository();
            foreach ($lignesDemande as $i => $ligne) {
                $formatId = (int) ($ligne['id'] ?? 0);
                $quantite = (int) ($ligne['qte'] ?? 0);
                $f = $formats[$formatId] ?? null;
                if ($horsLigne !== null) {
                    // Format retiré de la carte, sans prix dans la grille, en rupture depuis : la ligne vendue reste, au prix
                    // de l'appareil si le serveur n'en a plus (noté)
                    $f = CaisseHorsLigneController::formatVendu($f, $formatId, $ligne, $acceptees, $p, $notes);
                }
                // Entrée réduite d'un invité pointé à la porte : son tarif, même s'il n'est pas en vente à tous
                $invite = null;
                if ($poste === 'entree' && !empty($ligne['invite'])) {
                    $invite = (new \App\Repositories\PromoteurRepository())->invite((int) $ligne['invite']);
                    $soiree = $contexte['soiree']['evenement'] ?? null;
                    if ($invite === null || $soiree === null || (int) $invite['evenement_id'] !== (int) $soiree['id'] || $invite['entree'] !== 'reduite'
                        || (int) $invite['tarif_id'] !== $formatId || $quantite > 1 + (int) $invite['accompagnants']) {
                        $this->repondre(422, ['erreur' => t('encaisser.ligne_invalide')]);
                    }
                    $tarif = $billetterie->tarif($formatId);
                    $f = ['format' => ['id' => $formatId, 'nom' => t('nuit.categorie_' . $tarif['categorie']), 'taille' => '', 'disponible' => true,
                        'prix' => $billetterie->prix($formatId, $contexte['acceptees'])], 'produit' => ['nom' => champ($tarif, 'nom'), 'entree' => $tarif['categorie']]];
                }
                if ($f === null || $quantite < 1 || $quantite > 999 || !$f['format']['disponible'] || ($f['format']['prix'][$p] ?? null) === null
                    || ($poste !== 'caisse' && $quantite > 50) || (!empty($f['produit']['cache']) && $invite === null)) {
                    $this->repondre(422, ['erreur' => t('encaisser.ligne_invalide')]);
                }
                $prix = (float) $f['format']['prix'][$p];
                $prixRef = $reference && isset($f['format']['prix'][$reference['code']]) ? (float) $f['format']['prix'][$reference['code']] : null;
                if ($poste === 'entree') {
                    $base[] = [
                        'cle' => 'i' . $i, 'brut' => $prixDe($f['format']['prix'], $quantite), 'remisable' => true, 'composants' => [],
                        'billets' => ['tarif_id' => $formatId, 'categorie' => $f['produit']['entree'], 'invite' => $invite !== null ? (int) $invite['id'] : null],
                        'ligne' => ['produit_id' => null, 'conditionnement_id' => null, 'libelle' => t('nuit.ligne_entree', ['tarif' => $f['produit']['nom']]),
                            'format' => null, 'quantite' => $quantite, 'prix' => $prix, 'prix_reference' => $prixRef, 'nature' => 'entree'],
                    ];
                    continue;
                }
                if ($poste === 'vestiaire') {
                    $description = CaisseNoteController::texteLibre($ligne['rem'] ?? null);
                    $base[] = [
                        'cle' => 'i' . $i, 'brut' => $prixDe($f['format']['prix'], $quantite), 'remisable' => true, 'composants' => [],
                        'vestiaire' => ['description' => $description],
                        'ligne' => ['produit_id' => null, 'conditionnement_id' => null, 'libelle' => t('nuit.vestiaire_depot'), 'format' => $description,
                            'quantite' => $quantite, 'prix' => $prix, 'prix_reference' => $prixRef, 'nature' => 'vestiaire'],
                    ];
                    continue;
                }
                // Formule : les composants choisis (exactement le nombre de chaque groupe, rien d'épuisé)
                $composants = [];
                $formule = $formules->formule((int) $f['format']['tailleId']);
                if ($formule !== null) {
                    try {
                        $composants = $formules->valider($formule, $ligne['comp'] ?? null, $horsLigne === null ? $contexte['epuises'] : []);
                    } catch (\InvalidArgumentException $e) {
                        $message = $e->getMessage();
                        if ($horsLigne === null) {
                            $this->repondre(422, str_starts_with($message, 'epuise:')
                                ? ['erreur' => t('encaisser.epuise', ['articles' => substr($message, 7)]), 'code' => 'epuise']
                                : ['erreur' => t('nuit.composants_invalides', ['formule' => $f['produit']['nom']]), 'code' => 'composants']);
                        }
                        // Formule changée depuis la vente faite sans réseau : la ligne reste, sans ses composants (notée)
                        $composants = [];
                        $notes[] = 'formule';
                    }
                    $composants = array_map(static fn (array $c) => $c + ['qte' => $c['quantite']], $composants);
                    foreach ($composants as &$c) {
                        $c['quantite'] = $c['qte'] * $quantite;
                    }
                    unset($c);
                }
                $nom = $f['format']['taille'] !== '' ? $f['produit']['nom'] . ' ' . $f['format']['taille'] : $f['produit']['nom'];
                $vente = [
                    'produit_id' => (int) $f['format']['tailleId'], 'conditionnement_id' => $formatId,
                    'libelle' => $nom, 'format' => (!$f['format']['unite'] || $f['format']['freres'] > 1) ? $f['format']['nom'] : null,
                    'quantite' => $quantite, 'prix' => $prix, 'prix_reference' => $prixRef, 'nature' => $composants !== [] ? 'formule' : 'article',
                ];
                $base[] = ['cle' => 'i' . $i, 'brut' => $prixDe($f['format']['prix'], $quantite), 'remisable' => true, 'composants' => $composants, 'ligne' => $vente];
                // Vente directe d'un plat : il part quand même en cuisine (avec ses précisions)
                $remarque = CaisseNoteController::texteLibre($ligne['rem'] ?? null);
                if ($composants !== []) {
                    $remarque = CaisseNoteController::texteLibre(self::texteComposants($composants, 1) . ($remarque !== null ? ' — ' . $remarque : ''));
                }
                $pourCuisine[] = $vente + [
                    'categorie_id' => (int) ($f['produit']['sous'] ?: $f['produit']['categorie']),
                    'remarque' => $remarque, 'allergie' => CaisseNoteController::texteLibre($ligne['all'] ?? null),
                    'prix_devises' => array_filter($f['format']['prix'], static fn ($x) => $x !== null), 'grille' => $contexte['grille'],
                ];
            }
        }
        if ($base === []) {
            $this->repondre(422, ['erreur' => t('encaisser.vide')]);
        }

        // ---------- Minimum de consommation (paiement qui ferme une note du carré VIP) ----------
        $minimum = $note !== null ? $this->minimumNote($note, $partNote, $etab, $contexte, $acceptees) : null;
        $minimumLigne = null;
        $leverMinimum = $minimum !== null && !empty($demande['minimum']['lever']);
        if ($minimum !== null && !$leverMinimum && $minimum['action'] === 'ajouter') {
            $minimumLigne = $minimum['manque'];
        }

        // ---------- Remises, offerts et fidélité ----------
        $demandeRemises = is_array($demande['remises'] ?? null) ? $demande['remises'] : [];
        $indexParCle = array_flip(array_column($base, 'cle'));
        $remisesLignes = [];
        $remiseTicket = null;
        try {
            foreach (is_array($demandeRemises['lignes'] ?? null) ? $demandeRemises['lignes'] : [] as $cleLigne => $r) {
                if (!isset($indexParCle[$cleLigne])) {
                    throw new \InvalidArgumentException('ligne');
                }
                $remisesLignes[$indexParCle[$cleLigne]] = Remises::lire($r, $acceptees);
            }
            if (($demandeRemises['ticket'] ?? null) !== null) {
                $remiseTicket = Remises::lire($demandeRemises['ticket'], $acceptees);
            }
        } catch (\InvalidArgumentException) {
            $this->repondre(422, ['erreur' => t('nuit.remise_invalide'), 'code' => 'remise']);
        }
        $fidelite = $poste === 'caisse' && is_array($demande['fidelite'] ?? null) ? $this->fidelite($demande['fidelite'], $etab, $base, $remisesLignes, $remiseTicket) : null;
        $calculees = array_map(static fn (array $b) => ['brut' => $b['brut'], 'remisable' => $b['remisable']], $base);
        if ($minimumLigne !== null) {
            $calculees[] = ['brut' => $minimumLigne, 'remisable' => false];
        }
        try {
            $calcul = Remises::appliquer($calculees, $remisesLignes, $remiseTicket, $acceptees);
        } catch (\InvalidArgumentException) {
            $this->repondre(422, ['erreur' => t('nuit.remise_trop'), 'code' => 'remise']);
        }
        // Part la plus grande accordée à la main (une ligne offerte = 100 %) : au-delà de la limite, le PIN d'un supérieur
        $partMax = 0.0;
        foreach ($remisesLignes as $i => $r) {
            if (empty($r['fidelite'])) {
                $partMax = max($partMax, $calcul['lignes'][$i]['part']);
            }
        }
        if ($remiseTicket !== null && empty($remiseTicket['fidelite'])) {
            $partMax = max($partMax, $calcul['partTicket']);
        }
        $validationRemise = $partMax > 0 && !\App\Repositories\RemiseRepository::permise($uid, $etabId, $partMax);
        if ($validationRemise && $horsLigne !== null) {
            // Sans réseau, la caisse n'accorde que dans la limite de la personne : au-delà (limite changée depuis), noté
            $validationRemise = false;
            $notes[] = 'remise';
        }
        if ($validationRemise && empty($demandeRemises['validateur'])) {
            $this->repondre(403, ['erreur' => t('nuit.remise_au_dela', ['part' => self::pourcent($partMax)]), 'code' => 'remise_validation']);
        }
        $totaux = array_filter($calcul['totaux'], static fn ($t) => $t !== null);
        if (!isset($totaux[$p])) {
            $this->repondre(422, ['erreur' => t('encaisser.ligne_invalide')]);
        }

        // ---------- Paiements ----------
        // Un compte de cet établissement (ou commun) qui tient la devise, montant positif ;
        // le moyen enregistré est le type du compte (caisse, mobile money, banque)
        $comptes = array_column(self::comptesCaisse($etabId, $contexte['acceptees']), null, 'id');
        $paiements = [];
        foreach (is_array($demande['paiements'] ?? null) ? $demande['paiements'] : [] as $pa) {
            $code = is_string($pa['devise'] ?? null) ? $pa['devise'] : '';
            $compte = $comptes[(int) ($pa['compte'] ?? 0)] ?? null;
            if ($compte === null && $horsLigne !== null) {
                // Compte désactivé depuis la vente : l'argent y est entré quand même
                $compte = CaisseHorsLigneController::compteDesactive((int) ($pa['compte'] ?? 0), $etabId, $contexte['acceptees']);
            }
            $recu = is_numeric($pa['recu'] ?? null) ? round((float) $pa['recu'], (int) ($acceptees[$code]['decimales'] ?? 2)) : 0.0;
            if (!isset($totaux[$code]) || $compte === null || !in_array($code, $compte['devises'], true) || $recu <= 0 || $recu > 1e12) {
                $this->repondre(422, ['erreur' => t('encaisser.paiement_invalide')]);
            }
            $paiements[] = ['moyen' => $compte['type'], 'compte' => $compte, 'devise' => $acceptees[$code], 'recu' => $recu, 'taux' => $this->taux($acceptees[$code])];
        }
        // Cartes cashless : la part payée avec la carte, dans une devise de la carte (aucun mouvement de trésorerie)
        $cartes = [];
        $partCartes = 0.0;
        foreach (is_array($demande['cartes'] ?? null) ? $demande['cartes'] : [] as $pc) {
            $code = is_string($pc['devise'] ?? null) ? $pc['devise'] : '';
            $carte = \App\Repositories\CashlessRepository::disponible() ? (new \App\Repositories\CashlessRepository())->parCode($pc['code'] ?? null) : null;
            $montant = is_numeric($pc['montant'] ?? null) ? round((float) $pc['montant'], (int) ($acceptees[$code]['decimales'] ?? 2)) : 0.0;
            if ($carte === null || !isset($totaux[$code]) || $montant <= 0 || $montant > 1e12) {
                $this->repondre(422, ['erreur' => t('nuit.carte_invalide'), 'code' => 'carte']);
            }
            if ($carte['statut'] !== 'active') {
                $this->repondre(409, ['erreur' => t('nuit.carte_bloquee', ['carte' => $carte['code']]), 'code' => 'carte_bloquee']);
            }
            if ($montant > ($carte['soldes'][(int) $acceptees[$code]['id']] ?? 0.0) + 0.0005) {
                $this->repondre(409, ['erreur' => t('nuit.carte_solde', ['carte' => $carte['code']]), 'code' => 'carte_solde']);
            }
            $cartes[] = ['carte' => $carte, 'devise' => $acceptees[$code], 'montant' => $montant, 'taux' => $this->taux($acceptees[$code])];
            $partCartes += $totaux[$code] > 0 ? $montant / $totaux[$code] : 0.0;
        }
        if ($partCartes > 1 + 1e-6) {
            $this->repondre(422, ['erreur' => t('nuit.carte_trop'), 'code' => 'carte']);
        }
        $credit = $poste === 'caisse' && is_array($demande['credit'] ?? null) ? $this->credit($demande['credit'], $personne, $etab, $totaux, $acceptees) : null;
        $gratuit = $totaux[$p] <= 0.0005;
        if ($paiements === [] && $cartes === [] && $credit === null && !$gratuit) {
            $this->repondre(422, ['erreur' => t('encaisser.sans_paiement')]);
        }
        // L'ardoise et les cartes comptent comme des paiements dans leur devise : chacune couvre sa part du ticket
        $parts = array_map(static fn (array $pa) => ['devise' => $pa['devise']['code'], 'recu' => $pa['recu']], $paiements);
        foreach ($cartes as $pc) {
            $parts[] = ['devise' => $pc['devise']['code'], 'recu' => $pc['montant']];
        }
        if ($credit !== null) {
            $parts[] = ['devise' => $credit['devise']['code'], 'recu' => $credit['montant']];
        }
        $reglement = Reglement::calculer($totaux, $acceptees, $parts, $p);
        $ecart = null;
        if ($horsLigne !== null) {
            // Prix changés entre la caisse et le serveur (happy hour finie, prix modifié) : les paiements reçus et la monnaie
            // rendue restent ceux de la caisse ; l'écart de total est gardé sur la vente et montré au gérant
            $totalAppareil = $horsLigne['total'][$p] ?? null;
            if ($totalAppareil !== null && abs($totalAppareil - $totaux[$p]) >= Reglement::pas($acceptees[$p]) / 2 - 1e-9) {
                $ecart = round($totalAppareil - $totaux[$p], 3);
                $notes[] = 'prix';
            } elseif (!$reglement['regle'] && !$gratuit) {
                $ecart = round(-$reglement['reste'][$p], 3);
                $notes[] = 'prix';
            }
            $reglement['regle'] = true;
            $reglement['rendu'] = CaisseHorsLigneController::renduAppareil($horsLigne['rendu'], $reglement['rendu'], $acceptees);
        }
        if (!$reglement['regle'] && !$gratuit) {
            $this->repondre(422, ['erreur' => t('encaisser.reste', ['reste' => $this->devisesRepo()->formater($reglement['reste'][$p], $principale)])]);
        }
        // On ne rend pas de monnaie sur une ardoise : elle ne couvre que le reste
        if ($credit !== null && array_sum($reglement['rendu']) > 0) {
            $this->repondre(422, ['erreur' => t('encaisser.credit_trop')]);
        }

        // Monnaie rendue dans la devise choisie, depuis une caisse qui tient cette devise :
        // celle qui a reçu des espèces dans cette devise, sinon la première caisse de l'établissement
        $rendu = null;
        if (array_sum($reglement['rendu']) > 0) {
            $codeRendu = is_string($demande['rendu'] ?? null) && isset($reglement['rendu'][$demande['rendu']]) ? $demande['rendu'] : $p;
            $caisseRendu = null;
            foreach ($paiements as $pa) {
                if ($pa['moyen'] === 'caisse' && in_array($codeRendu, $pa['compte']['devises'], true)) {
                    $caisseRendu = $pa['compte'];
                    break;
                }
            }
            foreach ($caisseRendu === null ? $comptes : [] as $compte) {
                if ($compte['type'] === 'caisse' && in_array($codeRendu, $compte['devises'], true)) {
                    $caisseRendu = $compte;
                    break;
                }
            }
            if ($caisseRendu === null) {
                $this->repondre(422, ['erreur' => t('encaisser.rendu_impossible', ['devise' => $codeRendu])]);
            }
            if ($reglement['rendu'][$codeRendu] > 0) {
                $rendu = ['montant' => $reglement['rendu'][$codeRendu], 'devise' => $acceptees[$codeRendu], 'compte' => $caisseRendu, 'taux' => $this->taux($acceptees[$codeRendu])];
            }
        }

        // ---------- Validations par un supérieur (après toutes les autres vérifications) ----------
        $validePar = null;
        if ($validationRemise) {
            try {
                $validePar = PinSuperieur::verifier((int) $demandeRemises['validateur'], (string) ($demandeRemises['pin'] ?? ''), $appareil,
                    static fn (int $id): bool => \App\Repositories\RemiseRepository::permise($id, $etabId, $partMax), 'remise');
            } catch (PinRefuse $e) {
                $this->repondre($e->statut, ['erreur' => $e->getMessage(), 'code' => 'remise_' . $e->raison]);
            }
        }
        $minimumLevePar = null;
        if ($leverMinimum) {
            if (Acces::a($uid, 'caisse.minimum', $etabId)) {
                $minimumLevePar = ['id' => $uid, 'nom' => $personne['nom']];
            } else {
                try {
                    $minimumLevePar = PinSuperieur::verifier((int) ($demande['minimum']['validateur'] ?? 0), (string) ($demande['minimum']['pin'] ?? ''), $appareil,
                        static fn (int $id): bool => Acces::a($id, 'caisse.minimum', $etabId), 'minimum');
                } catch (PinRefuse $e) {
                    $this->repondre($e->statut, ['erreur' => $e->getMessage(), 'code' => 'minimum_' . $e->raison]);
                }
            }
        }

        // ---------- Lignes de la vente : chaque ligne (remise comprise), puis ses composants à prix 0 ----------
        $lignes = [];
        foreach ($base as $i => $b) {
            $partLigne = 1 - (1 - $calcul['lignes'][$i]['part']) * (1 - $calcul['partTicket']);
            $offert = $partLigne >= 1 - 1e-9;
            $brutP = $b['ligne']['prix'] * $b['ligne']['quantite'];
            $lignes[] = $b['ligne'] + ['remise' => round(max(0.0, $brutP - (float) $calcul['lignes'][$i]['net'][$p]), 3), 'offert' => $offert];
            $base[$i]['index'] = count($lignes) - 1;
            $base[$i]['net'] = (float) $calcul['lignes'][$i]['net'][$p];
            foreach ($b['composants'] as $c) {
                $lignes[] = [
                    'produit_id' => $c['produit_id'], 'conditionnement_id' => $c['conditionnement_id'], 'libelle' => $c['libelle'], 'format' => $c['format'],
                    'quantite' => $c['quantite'], 'prix' => 0.0, 'prix_reference' => $reference ? 0.0 : null, 'nature' => 'composant',
                    'parent' => $base[$i]['index'], 'remise' => 0.0, 'offert' => $offert,
                ];
            }
        }
        if ($minimumLigne !== null) {
            $lignes[] = ['produit_id' => null, 'conditionnement_id' => null, 'libelle' => t('nuit.minimum_ligne'), 'format' => null, 'quantite' => 1,
                'prix' => (float) $minimumLigne[$p], 'prix_reference' => $reference ? ($minimumLigne[$reference['code']] ?? null) : null, 'nature' => 'minimum',
                'remise' => 0.0, 'offert' => false];
        }
        $remises = [];
        foreach ($remisesLignes as $i => $r) {
            $remises[] = ['ligne' => $base[$i]['index'], 'type' => !empty($r['fidelite']) ? 'fidelite' : $r['type'], 'valeur' => $r['valeur'],
                'devise_id' => $r['devise'] !== null ? (int) $acceptees[$r['devise']]['id'] : null, 'part' => $calcul['lignes'][$i]['part'],
                'montant' => round((float) $base[$i]['brut'][$p] * $calcul['lignes'][$i]['part'], 3), 'motif' => $r['motif'], 'precision' => $r['precision'],
                'accorde_par' => $uid, 'valide_par' => empty($r['fidelite']) && $validePar !== null ? $validePar['id'] : null, 'recompense' => $r['recompense'] ?? null];
        }
        if ($remiseTicket !== null) {
            $apresLignes = 0.0;
            foreach ($base as $i => $b) {
                $apresLignes += (float) $b['brut'][$p] * (1 - $calcul['lignes'][$i]['part']);
            }
            $remises[] = ['ligne' => null, 'type' => !empty($remiseTicket['fidelite']) ? 'fidelite' : $remiseTicket['type'], 'valeur' => $remiseTicket['valeur'],
                'devise_id' => $remiseTicket['devise'] !== null ? (int) $acceptees[$remiseTicket['devise']]['id'] : null, 'part' => $calcul['partTicket'],
                'montant' => round($apresLignes * $calcul['partTicket'], 3), 'motif' => $remiseTicket['motif'], 'precision' => $remiseTicket['precision'],
                'accorde_par' => $uid, 'valide_par' => empty($remiseTicket['fidelite']) && $validePar !== null ? $validePar['id'] : null,
                'recompense' => $remiseTicket['recompense'] ?? null];
        }
        $brut = (float) $calcul['bruts'][$p];

        // Entrées gratuites ou dépôts sans frais, sans paiement : les billets ou tickets, sans vente (pas de ticket à 0)
        if ($poste !== 'caisse' && $gratuit && $paiements === [] && $cartes === []) {
            $crees = (new \App\Repositories\GestionTresorerieRepository())->transaction(fn () => $this->billetsEtDepots(null, [], $base, $etab, $contexte, $principale, $uid));
            $_SESSION['nuit_sans_vente'] = array_slice(($_SESSION['nuit_sans_vente'] ?? []) + [$cle => $crees], -30, null, true);
            Journal::ecrire($poste === 'entree' ? 'entrees_gratuites' : 'vestiaire_depot', null, ['billets' => count($crees['billets']), 'depots' => count($crees['depots'])], $uid, $appareil ? (int) $appareil['id'] : null);
            $this->repondreSansVente($crees, $etab, $appareil);
        }

        $vente = [
            'etablissement' => $etab, 'cle' => $cle, 'zone_id' => $contexte['zone'] ? (int) $contexte['zone']['id'] : null,
            'grille_id' => $contexte['grille'], 'utilisateur_id' => $uid, 'appareil_id' => $appareil ? (int) $appareil['id'] : null,
            'devise' => $principale, 'total' => $totaux[$p], 'brut' => $brut, 'remise' => round(max(0.0, $brut - $totaux[$p]), 3),
            'reference' => $reference, 'total_reference' => $reference ? ($totaux[$reference['code']] ?? null) : null,
            'totaux' => array_map(fn (string $code) => ['devise' => $acceptees[$code], 'total' => $totaux[$code], 'taux' => $this->taux($acceptees[$code])], array_keys($totaux)),
            'lignes' => $lignes, 'paiements' => $paiements, 'rendu' => $rendu,
            'credits' => $credit !== null ? [$credit] : [],
            'cashless' => array_map(static fn (array $pc) => ['carte_id' => (int) $pc['carte']['id'], 'devise' => $pc['devise'], 'montant' => $pc['montant'], 'taux' => $pc['taux']], $cartes),
            'remises' => $remises,
            'client_id' => $fidelite !== null ? (int) $fidelite['client']['id'] : null,
            'session_id' => $session !== null ? (int) $session['id'] : null,
            'apres' => [],
        ];
        if ($horsLigne !== null) {
            $notes = array_values(array_unique($notes));
            $vente['date'] = $moment->format('Y-m-d H:i:s');
            $vente['hors_ligne'] = ['heure_appareil' => $horsLigne['heure_appareil'], 'numero' => $horsLigne['numero'], 'ecart' => $ecart, 'notes' => $notes];
        }
        if ($note !== null) {
            // Dans la transaction de la vente : la note garde ce qui est payé (et se ferme s'il ne reste rien) ;
            // si quelqu'un a payé ou changé ces lignes entre-temps, la vente est annulée
            $parts = ($noteDemande['mode'] ?? '') === 'egal' ? (int) $noteDemande['parts'] : null;
            $vente['apres'][] = static function (int $venteId) use ($note, $partNote, $parts, $minimumLevePar): void {
                (new NoteRepository())->appliquerPaiement((int) $note['id'], $partNote, $venteId, $parts);
                if ($minimumLevePar !== null) {
                    (new \App\Repositories\MinimumRepository())->lever((int) $note['id'], $minimumLevePar['id']);
                }
                // Note d'un invité entièrement payée : commission du promoteur sur la note
                if (!empty($note['invite_id'])) {
                    (new \App\Repositories\PromoteurRepository())->commissionNote((int) $note['id']);
                }
            };
        }
        if ($poste !== 'caisse') {
            $vente['apres'][] = fn (int $venteId, array $ligneIds) => $this->billetsEtDepots($venteId, $ligneIds, $base, $etab, $contexte, $principale, $uid);
        }
        if ($fidelite !== null) {
            $vente['apres'][] = static function (int $venteId) use ($fidelite, $totaux, $reference, $acceptees, $remises, $etabId, $uid): void {
                self::pointsFidelite($venteId, $fidelite, $totaux, $reference, $acceptees, $remises, $etabId, $uid);
            };
        }
        try {
            $id = $ventes->enregistrer($vente);
        } catch (\DomainException $e) {
            $this->repondre(409, match ($e->getMessage()) {
                'solde' => ['erreur' => t('nuit.carte_solde', ['carte' => '']), 'code' => 'carte_solde'],
                'bloquee' => ['erreur' => t('nuit.carte_bloquee', ['carte' => '']), 'code' => 'carte_bloquee'],
                'points' => ['erreur' => t('nuit.points_insuffisants'), 'code' => 'points'],
                default => ['erreur' => t('note.changee'), 'code' => 'note_changee'],
            });
        }
        $numero = (string) (Database::un('SELECT numero FROM ventes WHERE id = ?', [$id])['numero'] ?? $id);
        $appareilId = $appareil ? (int) $appareil['id'] : null;
        if ($remises !== []) {
            Journal::ecrire('remise_accordee', $numero, [
                'remise' => round($brut - $totaux[$p], 3), 'devise' => $p, 'part_max' => round($partMax, 4),
                'motifs' => array_values(array_unique(array_column($remises, 'motif'))),
                'demande_par' => $personne['nom'], 'valide_par' => $validePar['nom'] ?? null,
            ], $uid, $appareilId);
        }
        if ($minimumLevePar !== null && $note !== null) {
            NoteRepository::journal('minimum_leve', $note, ['vente' => $id, 'demande_par' => $personne['nom'], 'valide_par' => $minimumLevePar['nom']], $uid, $appareilId);
        }
        if ($note !== null) {
            NoteRepository::journal('note_payee', $note, ['vente' => $id, 'mode' => $noteDemande['mode'] ?? 'tout'], $uid, $appareilId);
            $apres = (new NoteRepository())->note((int) $note['id']);
            $this->repondreVente($id, $etab, $appareil, ['note' => ['id' => (int) $note['id'], 'statut' => $apres['statut'] ?? 'payee']]);
        }
        if ($horsLigne !== null) {
            // Rien ne part en cuisine des heures après : la caisse a demandé d'annoncer les plats (reçu provisoire)
            CaisseHorsLigneController::journaliser($id, $numero, $horsLigne, $notes, $ecart, $p, $totaux[$p], $uid, $appareilId);
            CaisseHorsLigneController::repondreEnvoi($id, false);
        }
        // Vente directe (comptoir, à emporter) : les plats partent quand même en cuisine
        if ($poste === 'caisse') {
            CaisseNoteController::venteEnCuisine($id, $etab, $personne, $contexte, $pourCuisine);
        }
        $this->repondreVente($id, $etab, $appareil);
    }

    /** « 25 % » */
    private static function pourcent(float $part): string
    {
        return rtrim(rtrim(number_format($part * 100, 1, ',', ' '), '0'), ',') . ' %';
    }

    /**
     * Composants d'une ligne de note (formule), pour UNE unité : [{conditionnement_id, produit_id, libelle, format, qte}].
     * @return list<array>
     */
    public static function composantsNote(array $ligne): array
    {
        $json = json_decode((string) ($ligne['composants'] ?? ''), true);
        if (!is_array($json)) {
            return [];
        }
        $composants = [];
        foreach ($json as $c) {
            if (is_array($c) && (int) ($c['id'] ?? 0) > 0 && (float) ($c['qte'] ?? 0) > 0) {
                $composants[] = ['conditionnement_id' => (int) $c['id'], 'produit_id' => isset($c['produit_id']) ? (int) $c['produit_id'] : null,
                    'libelle' => (string) ($c['libelle'] ?? ''), 'format' => isset($c['format']) ? (string) $c['format'] : null, 'qte' => (float) $c['qte']];
            }
        }
        return $composants;
    }

    /** « Coca-Cola ×2, Fanta ×2 » (composants d'une formule, pour la cuisine, le bar et la note). */
    public static function texteComposants(array $composants, float $parUnite): string
    {
        return implode(', ', array_map(static fn (array $c) => $c['libelle'] . ' ×' . NoteRepository::quantiteLisible(($c['qte'] ?? $c['quantite']) * $parUnite), $composants));
    }

    /**
     * Minimum de consommation d'une note qui se ferme avec ce paiement (toutes ses lignes payées) : ce qui manque dans
     * chaque devise, si le minimum de sa table ou de sa zone n'est pas atteint et n'a pas été levé ; sinon null.
     * @return array{action: string, manque: array<string, ?float>, minimum: array<string, ?float>}|null
     */
    private function minimumNote(array $note, array $partNote, array $etab, array $contexte, array $acceptees): ?array
    {
        if ($note['minimum_leve_le'] ?? null) {
            return null;
        }
        $restant = 0.0;
        foreach ($note['lignes'] as $l) {
            $restant += NoteRepository::reste($l);
        }
        foreach ($partNote as $pn) {
            $restant -= $pn['quantite'];
        }
        if ($restant > NoteRepository::EPSILON * max(1, count($note['lignes']))) {
            return null;
        }
        $info = $this->minimumInfo($note, $etab, $contexte, $acceptees);
        return $info !== null && !$info['atteint'] ? $info : null;
    }

    /**
     * Minimum de la note (table, puis zone) et progression : consommé (toutes les lignes non annulées, au prix du
     * moment de leur commande), minimum et manque par devise.
     */
    public function minimumInfo(array $note, array $etab, array $contexte, array $acceptees): ?array
    {
        $minimum = (new \App\Repositories\MinimumRepository())->pour((int) $etab['id'], $note['zone_id'] !== null ? (int) $note['zone_id'] : null,
            $note['table_id'] !== null ? (int) $note['table_id'] : null);
        if ($minimum === null) {
            return null;
        }
        $actives = array_values(array_filter($note['lignes'], static fn (array $l) => $l['annulee_le'] === null));
        $prix = $this->prixLignesNote($etab, $actives);
        $consomme = array_fill_keys(array_keys($acceptees), 0.0);
        foreach ($actives as $l) {
            foreach (array_keys($acceptees) as $code) {
                $pu = $prix[(int) $l['id']][$code] ?? null;
                $consomme[$code] = $consomme[$code] === null || $pu === null ? null : $consomme[$code] + $pu * (float) $l['quantite'];
            }
        }
        $m = \App\Repositories\MinimumRepository::manque($minimum, $consomme, $acceptees, $contexte['principale']['code'], $this->devisesRepo());
        return ['action' => $minimum['action'], 'consomme' => $consomme] + $m;
    }

    /**
     * Fidélité demandée à l'encaissement : client identifié, et récompense éventuelle (remise en % sur le ticket, ou un
     * article offert sur la ligne de ce format). Les points sont vérifiés à nouveau dans la transaction de la vente.
     */
    private function fidelite(array $demande, array $etab, array $base, array &$remisesLignes, ?array &$remiseTicket): array
    {
        $repo = new \App\Repositories\FideliteRepository();
        $reglages = $repo->reglages((int) $etab['id']);
        $client = (new DetteRepository())->client((int) ($demande['client'] ?? 0));
        if ($reglages === null || $client === null || !$client['actif'] || $client['type'] === 'personnel') {
            $this->repondre(422, ['erreur' => t('nuit.fidelite_invalide'), 'code' => 'fidelite']);
        }
        $recompense = null;
        if (!empty($demande['recompense'])) {
            $recompense = $repo->recompense((int) $demande['recompense']);
            if ($recompense === null || (int) $recompense['etablissement_id'] !== (int) $etab['id'] || !$recompense['actif']) {
                $this->repondre(422, ['erreur' => t('nuit.fidelite_invalide'), 'code' => 'fidelite']);
            }
            if ($repo->solde((int) $client['id']) < (int) $recompense['points']) {
                $this->repondre(409, ['erreur' => t('nuit.points_insuffisants'), 'code' => 'points']);
            }
            $nom = champ($recompense, 'nom');
            if ($recompense['type'] === 'remise') {
                if ($remiseTicket !== null) {
                    $this->repondre(422, ['erreur' => t('nuit.remise_double'), 'code' => 'remise']);
                }
                $remiseTicket = ['type' => 'pourcent', 'valeur' => (float) $recompense['pourcent'], 'devise' => null, 'motif' => 'fidelite',
                    'precision' => $nom, 'fidelite' => true, 'recompense' => (int) $recompense['id']];
            } else {
                $trouve = null;
                foreach ($base as $i => $b) {
                    if (($b['ligne']['conditionnement_id'] ?? null) === (int) $recompense['conditionnement_id'] && !isset($remisesLignes[$i])) {
                        $trouve = $i;
                        break;
                    }
                }
                if ($trouve === null) {
                    $this->repondre(422, ['erreur' => t('nuit.recompense_article', ['recompense' => $nom]), 'code' => 'fidelite']);
                }
                // Un seul article offert : sur une ligne de 2, la moitié
                $remisesLignes[$trouve] = ['type' => 'pourcent', 'valeur' => round(100 / (float) $base[$trouve]['ligne']['quantite'], 3), 'devise' => null,
                    'motif' => 'fidelite', 'precision' => $nom, 'fidelite' => true, 'recompense' => (int) $recompense['id']];
            }
        }
        return ['client' => $client, 'reglages' => $reglages, 'recompense' => $recompense];
    }

    /** Points de la vente (dans sa transaction) : récompense utilisée, puis points gagnés sur le total payé. */
    private static function pointsFidelite(int $venteId, array $fidelite, array $totaux, ?array $reference, array $acceptees, array $remises, int $etabId, int $uid): void
    {
        $repo = new \App\Repositories\FideliteRepository();
        $clientId = (int) $fidelite['client']['id'];
        if ($fidelite['recompense'] !== null) {
            if ($repo->solde($clientId, true) < (int) $fidelite['recompense']['points']) {
                throw new \DomainException('points');
            }
            $repo->mouvement($clientId, $etabId, $venteId, 'utilisation', -(int) $fidelite['recompense']['points'], (int) $fidelite['recompense']['id'], $uid);
        }
        $code = $fidelite['reglages']['devise_code'];
        $montant = $totaux[$code] ?? null;
        if ($montant === null && $reference !== null && isset($totaux[$reference['code']], $acceptees[$code]['taux'])) {
            $montant = $totaux[$reference['code']] * (float) $acceptees[$code]['taux'];
        }
        $points = \App\Repositories\FideliteRepository::pointsPour($fidelite['reglages'], (float) ($montant ?? 0));
        $repo->mouvement($clientId, $etabId, $venteId, 'gain', $points, null, $uid);
    }

    /**
     * Billets d'entrée et tickets de vestiaire d'une vente (ou sans vente : entrées gratuites, dépôts sans frais), dans la
     * transaction : un billet par entrée (utilisé tout de suite pour un invité pointé), un ticket par dépôt.
     * @param array<int, int> $ligneIds index de ligne de vente → id
     * @return array{billets: list<int>, depots: list<int>}
     */
    private function billetsEtDepots(?int $venteId, array $ligneIds, array $base, array $etab, array $contexte, array $principale, int $uid): array
    {
        $billetterie = new \App\Repositories\BilletterieRepository();
        $vestiaire = new \App\Repositories\VestiaireRepository();
        $crees = ['billets' => [], 'depots' => []];
        foreach ($base as $b) {
            $q = (int) $b['ligne']['quantite'];
            if (isset($b['billets'])) {
                $soiree = $contexte['soiree'] ?? $billetterie->soiree($etab);
                $unitaire = $q > 0 ? round((float) ($b['net'] ?? 0) / $q, 3) : 0.0;
                for ($k = 0; $k < $q; $k++) {
                    $crees['billets'][] = $billetterie->creer([
                        'etablissement_id' => (int) $etab['id'], 'evenement_id' => $soiree['evenement'] ? (int) $soiree['evenement']['id'] : null,
                        'journee' => $soiree['journee'], 'tarif_id' => $b['billets']['tarif_id'], 'categorie' => $b['billets']['categorie'],
                        'vente_id' => $venteId, 'vente_ligne_id' => isset($b['index']) ? ($ligneIds[$b['index']] ?? null) : null,
                        'invite_id' => $b['billets']['invite'], 'prix' => $unitaire, 'devise_id' => (int) $principale['id'],
                        'utilise' => $b['billets']['invite'] !== null, 'par' => $uid,
                    ])['id'];
                }
            }
            if (isset($b['vestiaire'])) {
                for ($k = 0; $k < $q; $k++) {
                    $crees['depots'][] = (int) $vestiaire->deposer($etab, $b['vestiaire']['description'], 1, $venteId, $uid)['id'];
                }
            }
        }
        return $crees;
    }

    /** Réponse d'entrées gratuites ou de dépôts sans frais : billets et tickets à imprimer, sans vente. */
    private function repondreSansVente(array $crees, array $etab, ?array $appareil): never
    {
        $verrouille = false;
        if ($appareil !== null && $etab['verrou_apres_encaissement']) {
            CaissePartagee::verrouiller();
            $verrouille = true;
        }
        $this->repondre(200, [
            'numero' => null,
            'recu' => View::rendre('caisse/_billets', [
                'etablissement' => $etab,
                'billets' => (new \App\Repositories\BilletterieRepository())->parIds($crees['billets']),
                'depots' => (new \App\Repositories\VestiaireRepository())->parIds($crees['depots']),
                'seul' => true,
            ]),
            'verrouille' => $verrouille,
            'csrf' => Session::jetonCsrf(),
        ]);
    }

    /**
     * Part du ticket mise à l'ardoise d'un client : droit « caisse.credit », client connu, montant dans une devise
     * du ticket, personne qui a servi et garant parmi le personnel de l'établissement, échéance dans l'année.
     * Plafond, retard ou crédit bloqué : refusé, sauf pour qui a « dettes.gerer » et coche « Forcer ».
     * @return array{client_id: int, devise: array, montant: float, echeance: string, servi_par: int, garant_id: ?int, note: ?string}
     */
    private function credit(array $c, array $personne, array $etab, array $totaux, array $acceptees): array
    {
        $etabId = (int) $etab['id'];
        if (!Acces::a($personne['id'], 'caisse.credit', $etabId)) {
            $this->repondre(403, ['erreur' => t('encaisser.credit_droit')]);
        }
        $code = is_string($c['devise'] ?? null) ? $c['devise'] : '';
        $montant = is_numeric($c['montant'] ?? null) ? round((float) $c['montant'], (int) ($acceptees[$code]['decimales'] ?? 2)) : 0.0;
        $dettes = new DetteRepository();
        $client = $dettes->client((int) ($c['client'] ?? 0));
        if (!isset($totaux[$code]) || $montant <= 0 || $montant > 1e12 || $client === null || !$client['actif'] || $client['type'] === 'personnel') {
            $this->repondre(422, ['erreur' => t('encaisser.credit_invalide')]);
        }
        $personnel = array_column(self::personnel($etabId), null, 'id');
        $servi = (int) ($c['servi'] ?? $personne['id']);
        $garant = ($c['garant'] ?? null) ? (int) $c['garant'] : null;
        $echeance = is_string($c['echeance'] ?? null) ? $c['echeance'] : '';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $echeance);
        if (!isset($personnel[$servi]) || ($garant !== null && (!isset($personnel[$garant]) || $garant === $servi))
            || $date === false || $date->format('Y-m-d') !== $echeance || $echeance < date('Y-m-d') || $echeance > date('Y-m-d', strtotime('+365 days'))) {
            $this->repondre(422, ['erreur' => t('encaisser.credit_invalide')]);
        }
        $problemes = $dettes->empechements($client, (int) $acceptees[$code]['id'], $montant);
        if ($problemes !== []) {
            $peutForcer = Acces::a($personne['id'], 'dettes.gerer', $etabId);
            if (!$peutForcer || empty($c['forcer'])) {
                $messages = array_map(fn (array $p) => match ($p['code']) {
                    'bloque' => t('encaisser.credit_bloque', ['client' => $client['nom']]),
                    'retard' => t('encaisser.credit_retard', ['client' => $client['nom'], 'date' => date('d/m/Y', strtotime($p['date']))]),
                    default => t('encaisser.credit_plafond', [
                        'client' => $client['nom'],
                        'encours' => $this->devisesRepo()->formater($p['encours'], $this->devisesRepo()->actives()[$p['devise_id']] ?? $acceptees[$code]),
                        'plafond' => $this->devisesRepo()->formater($p['plafond'], $this->devisesRepo()->actives()[$p['devise_id']] ?? $acceptees[$code]),
                    ]),
                }, $problemes);
                $this->repondre(422, [
                    'erreur' => implode(' ', $messages) . ' ' . t($peutForcer ? 'encaisser.credit_forcer' : 'encaisser.credit_gerant'),
                    'code' => 'credit_refuse', 'peutForcer' => $peutForcer,
                ]);
            }
            Journal::ecrire('credit_force', $client['nom'], ['problemes' => array_column($problemes, 'code'), 'montant' => $montant, 'devise' => $code], $personne['id']);
        }
        $note = is_string($c['note'] ?? null) ? trim($c['note']) : '';
        return [
            'client_id' => (int) $client['id'], 'devise' => $acceptees[$code], 'montant' => $montant, 'echeance' => $echeance,
            'servi_par' => $servi, 'garant_id' => $garant, 'note' => $note !== '' ? mb_substr($note, 0, 255) : null,
        ];
    }

    /**
     * Session de caisse ouverte de la personne, si l'établissement les demande ; sinon refus 409.
     * null : l'établissement ne demande pas de session.
     */
    public static function sessionExigee(array $etab, array $personne): ?array
    {
        if (!SessionCaisseRepository::obligatoires($etab)) {
            return null;
        }
        $session = (new SessionCaisseRepository())->ouverte((int) $etab['id'], $personne['id']);
        if ($session === null) {
            http_response_code(409);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['erreur' => t('session.fermee'), 'code' => 'session_fermee'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        return $session;
    }

    /** Réponse d'une vente enregistrée ; la caisse partagée se verrouille si l'établissement l'exige. */
    private function repondreVente(int $id, array $etab, ?array $appareil, array $extra = []): never
    {
        $verrouille = false;
        if ($appareil !== null && $etab['verrou_apres_encaissement']) {
            CaissePartagee::verrouiller();
            $verrouille = true;
        }
        $recu = (new VenteRepository())->recu($id);
        // Après la vente, le stock a baissé : la caisse grise ce qui vient de s'épuiser (mode « bloquer »)
        $zoneVente = $recu['vente']['zone_id'] !== null ? Database::un('SELECT * FROM zones WHERE id = ?', [(int) $recu['vente']['zone_id']]) : null;
        $this->repondre(200, [
            'epuises' => self::epuises($etab, $zoneVente),
            'numero' => $recu['vente']['numero'],
            'recu' => View::rendre('caisse/_recu', $recu),
            'verrouille' => $verrouille,
            'csrf' => Session::jetonCsrf(),
        ] + $extra);
    }

    private function repondre(int $code, array $donnees): never
    {
        http_response_code($code);
        echo json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    private ?DeviseRepository $devisesRepo = null;

    private function devisesRepo(): DeviseRepository
    {
        return $this->devisesRepo ??= new DeviseRepository();
    }

    /** Heure d'une vente faite sans réseau : ses paiements gardent le taux valable à cette heure-là. */
    private ?string $momentTaux = null;

    /** Taux en vigueur (unités de la devise pour 1 unité de référence), gardé avec chaque paiement. */
    private function taux(array $devise): ?float
    {
        if ($this->momentTaux !== null && empty($devise['est_reference'])) {
            $ligne = Database::un('SELECT taux FROM taux_change WHERE devise_id = ? AND valable_depuis <= ? ORDER BY valable_depuis DESC, id DESC LIMIT 1',
                [(int) $devise['id'], $this->momentTaux]);
            if ($ligne !== null && (float) $ligne['taux'] > 0) {
                return (float) $ligne['taux'];
            }
        }
        $taux = $this->devisesRepo()->actives()[(int) $devise['id']]['taux'] ?? null;
        return $taux !== null && (float) $taux > 0 ? (float) $taux : null;
    }

    /** Connexion par PIN sur une caisse partagée. */
    public function pin(): void
    {
        Session::verifierCsrf();
        $appareil = CaissePartagee::appareil();
        if ($appareil === null) {
            http_response_code(403);
            exit(t('caisse_pin.appareil_requis'));
        }
        $etab = (new EtablissementRepository())->parSlug($appareil['slug']);
        if ($etab === null) {
            pageIntrouvable();
        }
        $appareilId = (int) $appareil['id'];
        if (CaissePartagee::appareilSature($appareilId)) {
            $this->afficherQui($appareil, $etab, t('caisse_pin.appareil_sature'), null, 429);
            return;
        }
        $utilisateurId = (int) ($_POST['utilisateur_id'] ?? 0);
        $pin = (string) ($_POST['pin'] ?? '');
        $personne = $utilisateurId > 0
            ? Database::un('SELECT * FROM utilisateurs WHERE id = ? AND actif = 1 AND pin IS NOT NULL', [$utilisateurId])
            : null;
        $ecran = in_array($_POST['ecran'] ?? '', ['entree', 'vestiaire'], true) ? $_POST['ecran'] : 'caisse';
        if ($personne === null || self::postesPermis($utilisateurId, (int) $etab['id']) === []) {
            $this->afficherQui($appareil, $etab, t('caisse_pin.choisir'), null, 422, $ecran);
            return;
        }
        if ($personne['pin_bloque_le'] !== null) {
            $this->afficherQui($appareil, $etab, t('caisse_pin.bloque', ['nom' => $personne['nom']]), $utilisateurId, 423, $ecran);
            return;
        }
        if (!CaissePartagee::pinValide($pin) || !password_verify($pin, $personne['pin'])) {
            $essais = (int) $personne['pin_essais'] + 1;
            $bloque = $essais >= CaissePartagee::ESSAIS_PIN;
            Database::executer(
                'UPDATE utilisateurs SET pin_essais = ?, pin_bloque_le = ? WHERE id = ?',
                [$bloque ? 0 : $essais, $bloque ? date('Y-m-d H:i:s') : null, $utilisateurId]
            );
            Database::executer('UPDATE appareils_caisse SET pin_echecs = pin_echecs + 1 WHERE id = ?', [$appareilId]);
            Journal::ecrire($bloque ? 'pin_bloque' : 'pin_echec', $personne['nom'], [], $utilisateurId, $appareilId);
            if ($bloque) {
                // Compte aussi comme un échec pour l'appareil
                Journal::ecrire('pin_echec', $personne['nom'], [], $utilisateurId, $appareilId);
            }
            $message = $bloque
                ? t('caisse_pin.bloque', ['nom' => $personne['nom']])
                : t('caisse_pin.incorrect', ['reste' => CaissePartagee::ESSAIS_PIN - $essais]);
            $this->afficherQui($appareil, $etab, $message, $bloque ? null : $utilisateurId, 422, $ecran);
            return;
        }
        Database::executer('UPDATE utilisateurs SET pin_essais = 0 WHERE id = ?', [$utilisateurId]);
        CaissePartagee::connecter($personne, $appareil);
        Journal::ecrire('pin_connexion', $appareil['nom'], [], $utilisateurId, $appareilId);
        $zone = is_string($_POST['zone'] ?? null) && preg_match('/^[a-z0-9-]+$/', $_POST['zone']) ? $_POST['zone'] : '';
        // Retour à l'écran d'où l'on vient (entrée, vestiaire), s'il est permis ; sinon le premier permis
        $postes = self::postesPermis($utilisateurId, (int) $etab['id']);
        $ecran = in_array($ecran, $postes, true) ? $ecran : $postes[0];
        rediriger(($ecran === 'caisse' ? '/caisse' : '/caisse/' . $ecran) . ($zone !== '' && $ecran === 'caisse' ? '?zone=' . $zone : ''));
    }

    /** Photo de profil prise sur la caisse (selfie), si l'établissement le permet. */
    public function photo(): void
    {
        Session::verifierCsrf();
        $appareil = CaissePartagee::appareil();
        if ($appareil !== null) {
            $personne = CaissePartagee::caissier();
            $etab = (new EtablissementRepository())->parSlug($appareil['slug']);
        } else {
            $utilisateur = Session::exigerConnexion();
            $personne = ['id' => (int) $utilisateur['id'], 'nom' => $utilisateur['nom']];
            $etab = (new EtablissementRepository())->parSlug(is_string($_GET['e'] ?? null) ? $_GET['e'] : '');
        }
        if ($personne === null || $etab === null || !$etab['photo_a_la_caisse'] || !Acces::a($personne['id'], 'caisse.vendre', (int) $etab['id'])) {
            http_response_code(403);
            exit(t('acces.interdit_titre'));
        }
        $erreur = Avatars::traiterFormulaire($personne['id'], $personne['nom']);
        $_SESSION['caisse_photo'] = $erreur ?? t('profil.photo_enregistree_moi');
        rediriger('/caisse' . ($appareil === null ? '?e=' . $etab['slug'] : ''));
    }

    /** Verrouille la caisse partagée : retour à « Qui êtes-vous ? » (après chaque encaissement, ou à la demande). */
    public function verrouiller(): void
    {
        Session::verifierCsrf();
        $caissier = CaissePartagee::caissier();
        if ($caissier !== null) {
            Journal::ecrire('caisse_verrouillee', null, [], $caissier['id'], $caissier['appareil_id']);
        }
        CaissePartagee::verrouiller();
        $ecran = in_array($_POST['ecran'] ?? '', ['entree', 'vestiaire'], true) ? '/' . $_POST['ecran'] : '';
        rediriger('/caisse' . $ecran);
    }

    /** Un gérant fait de cet appareil une caisse partagée de l'établissement. */
    public function autoriserAppareil(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $etab = (new EtablissementRepository())->parSlug((string) ($_POST['e'] ?? ''));
        if ($etab === null || !Acces::a((int) $utilisateur['id'], 'caisse.appareils', (int) $etab['id'])) {
            http_response_code(403);
            View::afficher('gestion/interdit', ['titre' => t('acces.interdit_titre'), 'utilisateur' => $utilisateur], 'gestion');
            return;
        }
        $nom = trim((string) ($_POST['nom'] ?? ''));
        $nom = $nom === '' ? t('caisse_pin.nom_defaut', ['etablissement' => $etab['nom']]) : mb_substr($nom, 0, 80);
        $id = CaissePartagee::autoriser($nom, (int) $etab['id'], (int) $utilisateur['id']);
        Journal::ecrire('appareil_autorise', $nom, ['etablissement' => $etab['nom']], (int) $utilisateur['id'], $id);
        // Le gérant est déconnecté : la tablette attend maintenant le PIN de chacun
        Session::deconnecter();
        rediriger('/caisse');
    }

    /** Écran « Qui êtes-vous ? » : les personnes qui peuvent vendre ici, puis le pavé du PIN. */
    private function afficherQui(array $appareil, array $etab, ?string $erreur = null, ?int $choisi = null, int $code = 200, string $ecran = 'caisse'): void
    {
        http_response_code($code);
        // Écran de verrouillage de la caisse du bar : gardé par le service worker pour changer de personne sans réseau
        header('X-Caisse-Page: ' . ($code === 200 && $ecran === 'caisse' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? 'verrou' : 'autre'));
        header('X-Caisse-Partagee: 1');
        $personnes = [];
        foreach (Database::tous('SELECT id, nom, poste, avatar, pin_bloque_le FROM utilisateurs WHERE actif = 1 AND pin IS NOT NULL ORDER BY nom') as $ligne) {
            // Le bar, l'entrée ou le vestiaire : chacun entre avec son PIN et arrive sur son écran
            if (self::postesPermis((int) $ligne['id'], (int) $etab['id']) !== []) {
                $personnes[] = $ligne;
            }
        }
        View::afficher('caisse/qui', [
            'titre' => t('caisse_pin.titre'),
            'etab' => $etab,
            'appareil' => $appareil,
            'personnes' => $personnes,
            'erreur' => $erreur,
            'choisi' => $choisi,
            'zone' => is_string($_GET['zone'] ?? null) ? $_GET['zone'] : (is_string($_POST['zone'] ?? null) ? $_POST['zone'] : ''),
            'ecran' => $ecran,
            'horsLigne' => CaisseHorsLigneController::donnees($etab, $appareil, null, null, 'verrou', Session::jetonCsrf()),
            'scripts' => ['/assets/js/caisse-pin.js', '/assets/js/caisse-hors-ligne-base.js', '/assets/js/caisse-hors-ligne.js'],
        ], 'caisse');
    }

    /**
     * Catalogue de la caisse, avec le prix de chaque format dans chaque devise acceptée.
     * Les prix sont calculés côté serveur pour respecter les prix fixes et les arrondis de chaque devise.
     *
     * Onglets : catégories principales, chacune avec ses sous-catégories (« sous »).
     * Un produit à tailles est une seule tuile : ses formats sont ceux de toutes ses tailles,
     * chacun avec le libellé de sa taille (« Grande 72 cl ») et sa disponibilité.
     */
    private function donnees(int $etablissementId, int $grille, int $grilleDefaut, array $acceptees, array $principale, string $type,
                             array $visibles = [], ?array $service = null): array
    {
        $catalogue = new CatalogueRepository(new DeviseRepository());
        $categories = [];
        $produits = [];

        foreach ($acceptees as $devise) {
            foreach ($catalogue->menu($etablissementId, $grille, $grilleDefaut, $devise, false) as $categorie) {
                // Service en cours qui ne vend que certaines catégories (cuisine fermée) : les autres disparaissent
                if ($visibles !== [] && !in_array((int) $categorie['id'], $visibles, true)) {
                    continue;
                }
                $categories[$categorie['id']] ??= ['id' => $categorie['id'], 'nom' => champ($categorie, 'nom'), 'sous' => []];
                $groupes = [[$categorie, 0]];
                foreach ($categorie['sous_categories'] as $sous) {
                    $categories[$categorie['id']]['sous'][$sous['id']] ??= ['id' => $sous['id'], 'nom' => champ($sous, 'nom')];
                    $groupes[] = [$sous, (int) $sous['id']];
                }
                foreach ($groupes as [$groupe, $sousId]) {
                    foreach ($groupe['produits'] as $produit) {
                        $this->ajouterProduit($produits, $produit, (int) $categorie['id'], $sousId, $devise);
                    }
                }
            }
        }

        foreach ($produits as &$produit) {
            $produit['formats'] = array_values($produit['formats']);
        }
        unset($produit);
        foreach ($categories as &$categorie) {
            $categorie['sous'] = array_values($categorie['sous']);
        }
        unset($categorie);

        return [
            // Mode comptoir rapide : celui du service en cours (Club), sinon celui du type de lieu (nightclub)
            'mode' => ($service !== null ? $service['rapide'] : $type === 'nightclub') ? 'rapide' : 'standard',
            'langue' => \App\Core\I18n::langue(),
            'principale' => $principale['code'],
            'devises' => array_values(array_map(static fn (array $d) => [
                'code' => $d['code'],
                'symbole' => $d['symbole'],
                'decimales' => (int) $d['decimales'],
                'symboleAvant' => (bool) $d['symbole_avant'],
                'arrondi' => (float) $d['arrondi'],
            ], $acceptees)),
            'categories' => array_values($categories),
            'produits' => array_values($produits),
        ];
    }

    /** Ajoute un produit (et ses tailles) au catalogue de la caisse, avec ses prix dans une devise. */
    private function ajouterProduit(array &$produits, array $produit, int $categorieId, int $sousId, array $devise): void
    {
        $id = (int) $produit['id'];
        $recherche = $produit['nom_fr'] . ' ' . $produit['nom_en'];
        foreach ($produit['tailles'] as $taille) {
            $recherche .= ' ' . $taille['taille_fr'] . ' ' . $taille['taille_en'] . ' ' . $taille['contenance'];
        }
        $produits[$id] ??= [
            'id' => $id,
            'categorie' => $categorieId,
            'sous' => $sousId,
            'nom' => champ($produit, 'nom'),
            'recherche' => mb_strtolower($recherche),
            'disponible' => (bool) $produit['disponible'],
            'photo' => urlImageSure($produit['photo']),
            'tailles' => count($produit['tailles']),
            'formats' => [],
        ];
        // Formats du produit lui-même, puis ceux de chaque taille
        $sources = [[$produit, '', (bool) $produit['disponible']]];
        foreach ($produit['tailles'] as $taille) {
            $sources[] = [$taille, $taille['libelle_taille'], (bool) $taille['disponible']];
        }
        foreach ($sources as [$source, $libelleTaille, $disponible]) {
            foreach ($source['conditionnements'] as $cond) {
                $produits[$id]['formats'][$cond['id']] ??= [
                    'id' => (int) $cond['id'],
                    'nom' => champ($cond, 'nom'),
                    'unite' => (float) $cond['quantite_base'] === 1.0,
                    'taille' => $libelleTaille,
                    'tailleId' => (int) $source['id'],
                    'freres' => count($source['conditionnements']),
                    'disponible' => $disponible,
                    'prix' => [],
                ];
                $produits[$id]['formats'][$cond['id']]['prix'][$devise['code']] = $cond['montant'];
            }
        }
    }
}
