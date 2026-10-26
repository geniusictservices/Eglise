<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Database;
use App\Core\Journal;
use App\Core\Session;
use App\Core\View;
use App\Repositories\AchatRepository;
use App\Repositories\DetteRepository;
use App\Repositories\DeviseRepository;
use App\Repositories\EmballageRepository;
use App\Repositories\GestionStockRepository;
use App\Repositories\GestionTresorerieRepository;
use App\Repositories\StockRepository;
use App\Repositories\VenteGrosRepository;
use App\Repositories\VenteRepository;

/**
 * Ventes > Vente en gros : commandes des clients revendeurs au dépôt (par casier, carton), au prix de gros, sans
 * passer par la caisse. La vente s'enregistre comme toute vente (VenteRepository::enregistrer : numéro de ticket,
 * paiements dans les comptes de trésorerie, reste à l'ardoise du client) et sort tout du dépôt. Emballages :
 * prêtés (le client les doit) ou consigne facturée sur la vente ; les vides rapportés rentrent au dépôt et
 * diminuent ce que le client doit (la consigne d'un emballage consigné est remboursée sur la vente).
 * Droits : ventes.gros pour l'établissement du dépôt ; ardoise : caisse.credit ou dettes.gerer (forcer :
 * dettes.gerer) ; prix de gros : carte.prix.
 */
final class VenteGrosController
{
    private const MAX_LIGNES = 30;
    private const MAX_PAIEMENTS = 4;

    private StockRepository $stock;
    private VenteGrosRepository $gros;
    private GestionStockRepository $depot;
    private GestionTresorerieRepository $tresorerie;

    public function __construct()
    {
        $this->stock = new StockRepository();
        $this->gros = new VenteGrosRepository();
        $this->depot = new GestionStockRepository($this->stock);
        $this->tresorerie = new GestionTresorerieRepository();
    }

    // ---------- Liste ----------

    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $uid = (int) $utilisateur['id'];
        $portee = Acces::etablissements($uid, 'ventes.gros');
        $periode = TresorerieSaisie::periode('mois');
        $client = StockAcces::id($_GET['client'] ?? null);
        View::afficher('gestion/gros/index', [
            'titre' => t('gros.titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'periode' => $periode,
            'depots' => $this->gros->depots($portee),
            'ventes' => $this->gros->ventes($periode['debut'], $periode['fin'], $portee, $client),
            'client' => $client !== null ? (new DetteRepository())->client($client) : null,
            'soldes' => $this->gros->soldes(),
            'emballages' => (new EmballageRepository($this->stock))->emballages(),
            'devises' => VenteController::devises(),
            'voirVentes' => Acces::a($uid, 'ventes.voir'),
            'peutParametrer' => Acces::a($uid, 'stock.parametrer'),
            'voirPrix' => Acces::a($uid, 'carte.voir') || Acces::a($uid, 'ventes.gros'),
        ], 'gestion');
    }

    // ---------- Nouvelle vente ----------

    public function nouvelle(): void
    {
        $utilisateur = Session::exigerConnexion();
        $depots = $this->depotsPermis((int) $utilisateur['id']);
        $locale = (new DeviseRepository())->locale();
        $this->afficherFormulaire($utilisateur, $depots, [
            'lieu_id' => StockAcces::id($_GET['lieu'] ?? null) ?? (count($depots) === 1 ? (int) $depots[0]['id'] : null),
            'client_id' => StockAcces::id($_GET['client'] ?? null), 'devise_id' => $locale ? (int) $locale['id'] : null,
            'date' => '', 'notes' => '', 'mode_emballages' => 'pret', 'echeance' => date('Y-m-d', strtotime('+30 days')), 'forcer' => false,
        ], [['conditionnement_id' => null, 'quantite' => '', 'prix' => '']], [], [['compte_id' => null, 'montant' => '']]);
    }

    public function creer(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $uid = (int) $utilisateur['id'];
        $depots = $this->depotsPermis($uid);
        if ($depots === []) {
            StockAcces::interdit($utilisateur);
        }
        $valeurs = [
            'lieu_id' => StockAcces::id($_POST['lieu_id'] ?? null),
            'client_id' => StockAcces::id($_POST['client_id'] ?? null),
            'devise_id' => StockAcces::id($_POST['devise_id'] ?? null),
            'date' => TresorerieSaisie::post('date'),
            'notes' => mb_substr(TresorerieSaisie::post('notes'), 0, 255),
            'mode_emballages' => ($_POST['mode_emballages'] ?? '') === 'facturer' ? 'facturer' : 'pret',
            'echeance' => TresorerieSaisie::post('echeance'),
            'forcer' => !empty($_POST['forcer']),
        ];
        $saisies = ReceptionController::lireSaisies(self::MAX_LIGNES);
        $retoursSaisis = is_array($_POST['retours'] ?? null) ? $_POST['retours'] : [];
        $paiementsSaisis = [];
        foreach (is_array($_POST['paiements'] ?? null) ? array_slice($_POST['paiements'], 0, self::MAX_PAIEMENTS) : [] as $p) {
            if (is_array($p)) {
                $paiementsSaisis[] = ['compte_id' => StockAcces::id($p['compte_id'] ?? null), 'montant' => TresorerieSaisie::texte($p['montant'] ?? null)];
            }
        }
        $paiementsSaisis = $paiementsSaisis ?: [['compte_id' => null, 'montant' => '']];
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'ajouter_ligne' || $action === 'ajouter_paiement') {
            if ($action === 'ajouter_ligne') {
                $saisies[] = ['conditionnement_id' => null, 'quantite' => '', 'prix' => ''];
            } elseif (count($paiementsSaisis) < self::MAX_PAIEMENTS) {
                $paiementsSaisis[] = ['compte_id' => null, 'montant' => ''];
            }
            $this->afficherFormulaire($utilisateur, $depots, $valeurs, $saisies, $retoursSaisis, $paiementsSaisis);
            return;
        }

        $erreurs = [];
        $lieu = null;
        foreach ($depots as $d) {
            if ((int) $d['id'] === $valeurs['lieu_id']) {
                $lieu = $d;
            }
        }
        if ($lieu === null) {
            $erreurs['lieu_id'] = t('gros.erreur_depot');
        }
        $etabId = $lieu !== null ? (int) $lieu['etablissement_id'] : null;
        $dettes = new DetteRepository();
        $client = $valeurs['client_id'] !== null ? $dettes->client($valeurs['client_id']) : null;
        if ($client === null || !$client['actif'] || $client['type'] === 'personnel') {
            $erreurs['client_id'] = t('gros.erreur_client');
            $client = null;
        }
        $devises = (new DeviseRepository())->actives();
        $devise = $valeurs['devise_id'] !== null ? ($devises[$valeurs['devise_id']] ?? null) : null;
        if ($devise === null || $devise['taux'] === null || (float) $devise['taux'] <= 0) {
            $erreurs['devise_id'] = t('gros.erreur_devise');
            $devise = null;
        }
        [$date, $erreurDate] = TresorerieSaisie::dateOperation($valeurs['date']);
        if ($erreurDate !== null) {
            $erreurs['date'] = $erreurDate;
        }

        // Lignes : prix saisi, sinon le prix de gros du format dans la devise de la vente
        $articles = array_filter($this->depot->articles(), static fn (array $a): bool => !$a['emballage']);
        [$lignes, $erreursLignes] = ReceptionController::lireLignes($articles, $saisies, $devise, false);
        $erreurs += $erreursLignes;
        $grille = $this->gros->prix();
        foreach ($lignes as $i => &$l) {
            if ($l['prix'] === null) {
                $l['prix'] = $devise !== null ? ($grille[$l['conditionnement_id']][(int) $devise['id']] ?? null) : null;
                if ($l['prix'] === null && $devise !== null) {
                    $erreurs['lignes-' . $i . '-prix'] = t('stock.ligne_n', ['n' => $i + 1]) . ' : ' . t('gros.erreur_sans_prix', ['code' => $devise['code']]);
                }
            }
        }
        unset($l);
        if ($lignes === [] && $erreursLignes === []) {
            $erreurs['lignes'] = t('stock.erreur_aucune_ligne');
        }
        // Mode « bloquer » : pas plus que ce que le dépôt a
        if ($lieu !== null && $lignes !== [] && $this->stock->parametres($etabId)['mode_negatif'] === 'bloquer') {
            $besoins = [];
            foreach ($lignes as $l) {
                $besoins[$l['produit_id']] = ($besoins[$l['produit_id']] ?? 0) + $l['quantite_base'];
            }
            foreach ($besoins as $produitId => $q) {
                $dispo = $this->stock->quantite((int) $lieu['id'], $produitId);
                if ($dispo < $q - 0.0005) {
                    $erreurs['lignes'] = t('gros.erreur_stock', ['article' => champ($articles[$produitId], 'nom'), 'stock' => StockAffichage::quantite($dispo, $articles[$produitId])]);
                    break;
                }
            }
        }

        // Emballages : ceux qui partent avec la marchandise (liens format → emballage), ceux que le client rapporte
        $emballageRepo = new EmballageRepository($this->stock);
        $catalogue = $emballageRepo->emballages();
        $liens = $emballageRepo->liens();
        $sortants = [];
        foreach ($lignes as $l) {
            foreach ($liens[$l['conditionnement_id']] ?? [] as $embId => $parFormat) {
                $sortants[$embId] = ($sortants[$embId] ?? 0) + $parFormat * $l['quantite'];
            }
        }
        $soldes = $client !== null ? $emballageRepo->soldesClient((int) $client['id']) : ['pretes' => [], 'consignes' => []];
        $facturer = $valeurs['mode_emballages'] === 'facturer';
        $retours = [];
        foreach ($catalogue as $embId => $emb) {
            $texte = TresorerieSaisie::texte(is_scalar($retoursSaisis[$embId] ?? null) ? (string) $retoursSaisis[$embId] : null);
            if ($texte === '') {
                continue;
            }
            [$q, $erreur] = StockAcces::quantite($texte, true);
            if ($erreur !== null) {
                $erreurs['retours-' . $embId] = champ($emb, 'nom') . ' : ' . $erreur;
                continue;
            }
            $possible = max(0.0, $soldes['pretes'][$embId] ?? 0) + max(0.0, $soldes['consignes'][$embId] ?? 0) + ($sortants[$embId] ?? 0);
            if ($q > $possible + 0.0005) {
                $erreurs['retours-' . $embId] = t('gros.erreur_retours', ['emballage' => champ($emb, 'nom'), 'n' => StockAffichage::nombre($possible)]);
                continue;
            }
            if ($q > 0) {
                $retours[$embId] = $q;
            }
        }

        $venteLignes = [];
        $emballagesMvt = [];
        $total = 0.0;
        $achats = new AchatRepository($this->stock, $this->tresorerie);
        if ($devise !== null && $date !== null) {
            $taux = (float) $devise['taux'];
            foreach ($lignes as $l) {
                $a = $articles[$l['produit_id']];
                $format = GestionStockRepository::format($a, $l['conditionnement_id']);
                $venteLignes[] = [
                    'produit_id' => $l['produit_id'], 'conditionnement_id' => $l['conditionnement_id'],
                    'libelle' => champ($a, 'nom'), 'format' => $format !== null ? champ($format, 'nom') : null,
                    'quantite' => $l['quantite'], 'prix' => (float) $l['prix'], 'prix_reference' => round((float) $l['prix'] / $taux, 3),
                ];
                $total += $l['quantite'] * (float) $l['prix'];
            }
            foreach ($sortants as $embId => $q) {
                if (!isset($catalogue[$embId]) || $q <= 0.0005) {
                    continue;
                }
                $valeur = $achats->convertir((float) $catalogue[$embId]['valeur_consigne'], (int) $catalogue[$embId]['devise_id'], $devise, $date);
                if ($facturer) {
                    $venteLignes[] = ['produit_id' => null, 'conditionnement_id' => null, 'libelle' => t('gros.ligne_consigne', ['emballage' => champ($catalogue[$embId], 'nom')]),
                                      'format' => null, 'quantite' => $q, 'prix' => $valeur, 'prix_reference' => round($valeur / $taux, 3)];
                    $total += $q * $valeur;
                }
                $emballagesMvt[] = ['produit_id' => $embId, 'type' => $facturer ? 'consigne_facturee' : 'pret', 'quantite' => $q,
                                    'valeur' => $facturer ? $q * $valeur : null, 'devise_id' => $facturer ? (int) $devise['id'] : null];
            }
            // Vides rapportés : d'abord ceux prêtés, puis ceux consignés (consigne remboursée sur la vente)
            foreach ($retours as $embId => $q) {
                $pretes = max(0.0, $soldes['pretes'][$embId] ?? 0) + (!$facturer ? ($sortants[$embId] ?? 0) : 0);
                $deLaPret = min($q, $pretes);
                if ($deLaPret > 0.0005) {
                    $emballagesMvt[] = ['produit_id' => $embId, 'type' => 'retour', 'quantite' => -$deLaPret, 'valeur' => null, 'devise_id' => null];
                }
                $consignes = $q - $deLaPret;
                if ($consignes > 0.0005) {
                    $valeur = $achats->convertir((float) $catalogue[$embId]['valeur_consigne'], (int) $catalogue[$embId]['devise_id'], $devise, $date);
                    $venteLignes[] = ['produit_id' => null, 'conditionnement_id' => null, 'libelle' => t('gros.ligne_consigne_rendue', ['emballage' => champ($catalogue[$embId], 'nom')]),
                                      'format' => null, 'quantite' => $consignes, 'prix' => -$valeur, 'prix_reference' => -round($valeur / $taux, 3)];
                    $total -= $consignes * $valeur;
                    $emballagesMvt[] = ['produit_id' => $embId, 'type' => 'consigne_facturee', 'quantite' => -$consignes, 'valeur' => -$consignes * $valeur, 'devise_id' => (int) $devise['id']];
                }
            }
            $total = round($total, (int) $devise['decimales']);
            if ($lignes !== [] && $total <= 0 && $erreurs === []) {
                $erreurs['lignes'] = t('gros.erreur_total');
            }
        }

        // Paiements dans la devise de la vente ; le reste va à l'ardoise du client
        $comptes = $etabId !== null ? $this->comptes($etabId) : [];
        $paiements = [];
        $paye = 0.0;
        foreach ($paiementsSaisis as $i => $p) {
            if ($p['compte_id'] === null && $p['montant'] === '') {
                continue;
            }
            $n = ['n' => $i + 1];
            $compte = $p['compte_id'] !== null ? ($comptes[$p['compte_id']] ?? null) : null;
            if ($compte === null || $devise === null || !GestionTresorerieRepository::tient($compte, (int) $devise['id'])) {
                $erreurs['paiements-' . $i . '-compte'] = t('gros.erreur_compte', $n + ['code' => $devise['code'] ?? '']);
                continue;
            }
            [$montant, $erreur] = TresorerieSaisie::montant($p['montant'], $devise);
            if ($erreur !== null) {
                $erreurs['paiements-' . $i . '-montant'] = t('depenses.paiement_n', $n) . ' : ' . $erreur;
                continue;
            }
            $paye += $montant;
            $paiements[] = ['moyen' => $compte['type'], 'compte' => $compte, 'devise' => $devise, 'recu' => $montant, 'taux' => (float) $devise['taux']];
        }
        $tolerance = $devise !== null ? max((float) $devise['arrondi'], 10 ** -(int) $devise['decimales']) / 2 : 0.005;
        $reste = round($total - $paye, $devise !== null ? (int) $devise['decimales'] : 2);
        if ($erreurs === [] && $reste < -$tolerance) {
            $erreurs['paiements'] = t('gros.erreur_trop_paye', ['paye' => TresorerieAffichage::montant($paye, $devise), 'total' => TresorerieAffichage::montant($total, $devise)]);
        }
        $credits = [];
        if ($erreurs === [] && $reste > $tolerance && $client !== null && $devise !== null) {
            if (!Acces::a($uid, 'caisse.credit', $etabId) && !Acces::a($uid, 'dettes.gerer', $etabId)) {
                $erreurs['paiements'] = t('gros.erreur_droit_credit', ['reste' => TresorerieAffichage::montant($reste, $devise)]);
            } else {
                $echeance = \DateTimeImmutable::createFromFormat('!Y-m-d', $valeurs['echeance']);
                if ($echeance === false || $echeance->format('Y-m-d') !== $valeurs['echeance'] || $valeurs['echeance'] < date('Y-m-d') || $valeurs['echeance'] > date('Y-m-d', strtotime('+365 days'))) {
                    $erreurs['echeance'] = t('gros.erreur_echeance');
                } else {
                    $problemes = $dettes->empechements($client, (int) $devise['id'], $reste);
                    if ($problemes !== [] && !($valeurs['forcer'] && Acces::a($uid, 'dettes.gerer', $etabId))) {
                        $erreurs['paiements'] = t('gros.erreur_credit_refuse', ['client' => $client['nom'], 'motifs' => implode(', ', array_map(static fn (array $p): string => t('gros.empechement_' . $p['code']), $problemes))])
                            . (Acces::a($uid, 'dettes.gerer', $etabId) ? ' ' . t('gros.forcer_possible') : '');
                    } else {
                        if ($problemes !== []) {
                            Journal::ecrire('credit_force', $client['nom'], ['problemes' => array_column($problemes, 'code'), 'montant' => $reste, 'devise' => $devise['code']], $uid);
                        }
                        $credits[] = ['client_id' => (int) $client['id'], 'devise' => $devise, 'montant' => $reste, 'echeance' => $valeurs['echeance'],
                                      'servi_par' => $uid, 'garant_id' => null, 'note' => t('gros.note_credit')];
                    }
                }
            }
        }
        if ($erreurs !== []) {
            $this->afficherFormulaire($utilisateur, $depots, $valeurs, $saisies ?: [['conditionnement_id' => null, 'quantite' => '', 'prix' => '']], $retoursSaisis, $paiementsSaisis, $erreurs, 422);
            return;
        }

        $reference = (new DeviseRepository())->reference();
        $totalReference = $reference !== null ? ((int) $reference['id'] === (int) $devise['id'] ? $total : round($total / (float) $devise['taux'], 3)) : null;
        $totaux = [['devise' => $devise, 'total' => $total, 'taux' => (float) $devise['taux']]];
        if ($reference !== null && (int) $reference['id'] !== (int) $devise['id']) {
            $totaux[] = ['devise' => $reference, 'total' => $totalReference, 'taux' => 1.0];
        }
        $cle = self::uuid();
        StockRepository::prevoirVenteGros($cle, (int) $lieu['id'], $retours);
        $gros = $this->gros;
        $etab = Database::un('SELECT * FROM etablissements WHERE id = ?', [$etabId]);
        $id = (new VenteRepository())->enregistrer([
            'etablissement' => $etab, 'cle' => $cle, 'zone_id' => null, 'grille_id' => $this->gros->grille() ?? (int) $etab['grille_prix_id'],
            'utilisateur_id' => $uid, 'appareil_id' => null, 'devise' => $devise, 'total' => $total,
            'reference' => $reference, 'total_reference' => $totalReference, 'totaux' => $totaux,
            'lignes' => $venteLignes, 'paiements' => $paiements, 'rendu' => null, 'credits' => $credits, 'session_id' => null, 'date' => $date,
            'apres' => static fn (int $venteId) => $gros->rattacher($venteId, (int) $lieu['id'], (int) $client['id'], $facturer,
                $valeurs['notes'] !== '' ? $valeurs['notes'] : null, $emballagesMvt, $date, $uid),
        ]);
        $vente = Database::un('SELECT numero FROM ventes WHERE id = ?', [$id]);
        Session::flash(t('gros.vente_enregistree', ['numero' => $vente['numero'] ?? '', 'total' => TresorerieAffichage::montant($total, $devise), 'client' => $client['nom']])
            . ($credits !== [] ? ' ' . t('gros.vente_credit', ['reste' => TresorerieAffichage::montant($reste, $devise)]) : ''));
        rediriger('/gestion/gros?vente=' . $id);
    }

    // ---------- Prix de gros ----------

    public function prix(): void
    {
        $utilisateur = Session::exigerConnexion();
        $this->afficherPrix($utilisateur);
    }

    public function enregistrerPrix(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $uid = (int) $utilisateur['id'];
        if (!Acces::a($uid, 'carte.prix')) {
            StockAcces::interdit($utilisateur);
        }
        $devises = $this->devisesPrix();
        $formats = [];
        foreach ($this->articlesGros() as $a) {
            foreach ($a['conditionnements'] as $c) {
                $formats[(int) $c['id']] = true;
            }
        }
        $saisies = is_array($_POST['prix'] ?? null) ? $_POST['prix'] : [];
        $prix = [];
        $erreurs = [];
        foreach ($saisies as $condId => $parDevise) {
            $condId = (int) $condId;
            if (!isset($formats[$condId]) || !is_array($parDevise)) {
                continue;
            }
            foreach ($devises as $deviseId => $devise) {
                if (!array_key_exists((string) $deviseId, $parDevise) && !array_key_exists($deviseId, $parDevise)) {
                    continue;
                }
                $texte = TresorerieSaisie::texte(is_scalar($parDevise[$deviseId] ?? null) ? (string) $parDevise[$deviseId] : null);
                if ($texte === '') {
                    $prix[$condId][$deviseId] = null;
                    continue;
                }
                [$montant, $erreur] = TresorerieSaisie::montant($texte, $devise);
                if ($erreur !== null) {
                    $erreurs['prix-' . $condId . '-' . $deviseId] = $erreur;
                    continue;
                }
                $prix[$condId][$deviseId] = $montant;
            }
        }
        if ($erreurs !== []) {
            $this->afficherPrix($utilisateur, $saisies, $erreurs, 422);
            return;
        }
        $n = $this->gros->definirPrix($prix, $uid);
        Session::flash(t('gros.prix_enregistres', ['n' => $n]));
        rediriger('/gestion/gros/prix');
    }

    private function afficherPrix(array $utilisateur, array $saisies = [], array $erreurs = [], int $code = 200): void
    {
        http_response_code($code);
        $uid = (int) $utilisateur['id'];
        View::afficher('gestion/gros/prix', [
            'titre' => t('gros.prix_titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'articles' => $this->articlesGros(),
            'devises' => $this->devisesPrix(),
            'prix' => $this->gros->prix(),
            'cumps' => Acces::a($uid, 'rapports.marges') ? $this->depot->cumps() : [],
            'reference' => (new DeviseRepository())->reference(),
            'peutModifier' => Acces::a($uid, 'carte.prix'),
            'saisies' => $saisies,
            'erreurs' => $erreurs,
            'grille' => $this->gros->grille(),
        ], 'gestion');
    }

    /** Articles vendables en gros : tout article stocké qui n'est pas un emballage, avec ses formats. */
    private function articlesGros(): array
    {
        $idsPreparations = array_flip((new \App\Repositories\RecetteRepository($this->stock))->idsPreparations());
        return array_filter($this->depot->articles(), static fn (array $a): bool => !$a['emballage'] && !isset($idsPreparations[(int) $a['id']]));
    }

    /** Devises des prix de gros : locale et référence (actives). */
    private function devisesPrix(): array
    {
        return array_filter((new DeviseRepository())->actives(), static fn (array $d): bool => (bool) $d['est_locale'] || (bool) $d['est_reference']);
    }

    // ---------- Outils ----------

    /** Dépôts qui vendent en gros, dans les établissements où la personne a ventes.gros. */
    private function depotsPermis(int $uid): array
    {
        return array_values(array_filter($this->gros->depots(Acces::etablissements($uid, 'ventes.gros')),
            static fn (array $d): bool => Acces::a($uid, 'ventes.gros', (int) $d['etablissement_id'])));
    }

    /** Comptes actifs de l'établissement ou communs. */
    private function comptes(int $etabId): array
    {
        return array_filter($this->tresorerie->comptesActifs(), static fn (array $c): bool => $c['etablissement_id'] === null || (int) $c['etablissement_id'] === $etabId);
    }

    private static function uuid(): string
    {
        $o = random_bytes(16);
        $o[6] = chr((ord($o[6]) & 0x0f) | 0x40);
        $o[8] = chr((ord($o[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($o), 4));
    }

    private function afficherFormulaire(array $utilisateur, array $depots, array $valeurs, array $lignes, array $retours, array $paiements,
                                        array $erreurs = [], int $code = 200): void
    {
        http_response_code($code);
        $uid = (int) $utilisateur['id'];
        $lieu = null;
        foreach ($depots as $d) {
            if ((int) $d['id'] === (int) $valeurs['lieu_id']) {
                $lieu = $d;
            }
        }
        $articles = array_filter($this->depot->articles(), static fn (array $a): bool => !$a['emballage']);
        // Stock du dépôt choisi, affiché sous chaque ligne
        $stocks = [];
        foreach ($depots as $d) {
            foreach ($this->depot->niveaux([(int) $d['id']])[(int) $d['id']] ?? [] as $produitId => $n) {
                if (isset($articles[$produitId])) {
                    $stocks[(int) $d['id']][$produitId] = StockAffichage::quantite($n['quantite'], $articles[$produitId]);
                }
            }
        }
        $client = $valeurs['client_id'] !== null ? (new DetteRepository())->client((int) $valeurs['client_id']) : null;
        $etabId = $lieu !== null ? (int) $lieu['etablissement_id'] : null;
        View::afficher('gestion/gros/form', [
            'titre' => t('gros.nouvelle'),
            'utilisateur' => $utilisateur,
            'depots' => $depots,
            'lieu' => $lieu,
            'clients' => $this->gros->clients(),
            'client' => $client,
            'soldesClient' => $client !== null ? (new EmballageRepository($this->stock))->soldesClient((int) $client['id']) : ['pretes' => [], 'consignes' => []],
            'devises' => array_filter((new DeviseRepository())->actives(), static fn (array $d): bool => $d['taux'] !== null && (float) $d['taux'] > 0),
            'articles' => $articles,
            'stocks' => $stocks,
            'prixGros' => $this->gros->prix(),
            'emballages' => (new EmballageRepository($this->stock))->emballages(),
            'comptes' => $etabId !== null ? $this->comptes($etabId) : [],
            'peutCredit' => $etabId !== null && (Acces::a($uid, 'caisse.credit', $etabId) || Acces::a($uid, 'dettes.gerer', $etabId)),
            'peutForcer' => $etabId !== null && Acces::a($uid, 'dettes.gerer', $etabId),
            'valeurs' => $valeurs,
            'lignes' => $lignes,
            'retours' => $retours,
            'paiements' => $paiements,
            'erreurs' => $erreurs,
            'max' => self::MAX_LIGNES,
            'maxPaiements' => self::MAX_PAIEMENTS,
        ], 'gestion');
    }
}
