<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Session;
use App\Core\View;
use App\Repositories\GestionDepenseRepository;
use App\Repositories\GestionEtablissementRepository;
use App\Repositories\GestionTresorerieRepository;

/**
 * Dépenses : liste filtrée avec les totaux par devise et l'équivalent en devise de référence,
 * saisie et modification. Chaque paiement choisit le compte (groupés par type : caisse, mobile money, banque),
 * une des devises que ce compte tient, et le montant ; une dépense payée dans deux devises a deux paiements
 * (depuis le même compte ou deux comptes). Découvert signalé par compte et par devise, sans bloquer.
 */
final class DepenseController
{
    private const MAX_PAIEMENTS = 6;
    private const LIGNE_VIDE = ['compte_id' => null, 'devise_id' => null, 'montant' => ''];

    private GestionTresorerieRepository $tresorerie;
    private GestionDepenseRepository $depenses;

    public function __construct()
    {
        $this->tresorerie = new GestionTresorerieRepository();
        $this->depenses = new GestionDepenseRepository($this->tresorerie);
    }

    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $periode = TresorerieSaisie::periode('mois');
        $etablissements = (new GestionEtablissementRepository())->liste();
        $categories = $this->depenses->categories();

        $etablissement = is_string($_GET['etablissement'] ?? null) ? $_GET['etablissement'] : '';
        if ($etablissement !== 'commun' && !in_array($etablissement, array_map('strval', array_column($etablissements, 'id')), true)) {
            $etablissement = '';
        }
        $categorie = TresorerieSaisie::id($_GET['categorie'] ?? null);
        if ($categorie !== null && !in_array($categorie, array_map('intval', array_column($categories, 'id')), true)) {
            $categorie = null;
        }
        $moyen = in_array($_GET['moyen'] ?? null, GestionTresorerieRepository::TYPES, true) ? $_GET['moyen'] : null;
        $filtres = ['debut' => $periode['debut'], 'fin' => $periode['fin'], 'categorie' => $categorie, 'etablissement' => $etablissement, 'moyen' => $moyen];
        $liste = $this->depenses->depenses($filtres);

        // Totaux : par devise (paiements du moyen choisi), équivalent en référence, et par catégorie
        $devises = $this->tresorerie->devises();
        $reference = $this->tresorerie->reference();
        $totaux = [];
        $totalReference = 0.0;
        $parCategorie = [];
        foreach ($liste as &$depense) {
            $depense['equivalent'] = 0.0;
            foreach ($depense['paiements'] as $paiement) {
                $equivalent = $this->equivalent($paiement, $devises, $reference);
                $depense['equivalent'] += $equivalent;
                if ($moyen !== null && $paiement['compte_type'] !== $moyen) {
                    continue;
                }
                $deviseId = (int) $paiement['devise_id'];
                $totaux[$deviseId] = ($totaux[$deviseId] ?? 0) + (float) $paiement['montant'];
                $totalReference += $equivalent;
                $cle = (int) $depense['categorie_id'];
                $parCategorie[$cle] ??= ['nom' => champ($depense, 'categorie_nom'), 'montant' => 0.0, 'nombre' => 0];
                $parCategorie[$cle]['montant'] += $equivalent;
            }
            if (isset($parCategorie[(int) $depense['categorie_id']])) {
                $parCategorie[(int) $depense['categorie_id']]['nombre']++;
            }
        }
        unset($depense);
        $ordre = array_flip(array_keys($devises));
        uksort($totaux, static fn ($a, $b) => ($ordre[$a] ?? 999) <=> ($ordre[$b] ?? 999));
        uasort($parCategorie, static fn ($a, $b) => $b['montant'] <=> $a['montant']);

        View::afficher('gestion/depenses/index', [
            'titre' => t('depenses.titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'periode' => $periode,
            'filtres' => $filtres,
            'depenses' => $liste,
            'devises' => $devises,
            'reference' => $reference,
            'totaux' => $totaux,
            'totalReference' => $totalReference,
            'parCategorie' => $parCategorie,
            'categories' => $categories,
            'etablissements' => $etablissements,
        ], 'gestion');
    }

    public function nouvelle(): void
    {
        $utilisateur = Session::exigerConnexion();
        $comptes = $this->tresorerie->comptesActifs();
        $locale = $this->tresorerie->deviseRepository()->locale();
        $compteId = TresorerieSaisie::id($_GET['compte'] ?? null);
        $compte = $compteId !== null ? ($comptes[$compteId] ?? null) : null;
        if ($compte === null) {
            // Une caisse qui tient la devise locale, sinon le premier compte actif
            foreach ($comptes as $candidat) {
                if ($candidat['type'] === 'caisse' && $locale && GestionTresorerieRepository::tient($candidat, (int) $locale['id'])) {
                    $compte = $candidat;
                    break;
                }
            }
            $compte ??= $comptes ? reset($comptes) : null;
        }
        $this->afficherFormulaire($utilisateur, null, [
            'date' => date('Y-m-d\TH:i'), 'categorie_id' => null, 'etablissement_id' => '',
            'libelle' => '', 'beneficiaire' => '', 'notes' => '',
        ], [['compte_id' => $compte ? (int) $compte['id'] : null, 'devise_id' => $compte ? $this->devisePreferee($compte, []) : null, 'montant' => '']]);
    }

    /** Devise proposée pour un paiement depuis ce compte : la locale si possible, et pas une déjà utilisée par la dépense. */
    private function devisePreferee(array $compte, array $dejaPrises): ?int
    {
        $tenues = array_keys(array_filter($compte['devises'], static fn (array $d): bool => $d['tenue']));
        foreach ($tenues as $deviseId) {
            if (!in_array($deviseId, $dejaPrises, true)) {
                return $deviseId;
            }
        }
        return $tenues[0] ?? null;
    }

    public function creer(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $this->enregistrer($utilisateur, null);
    }

    public function fiche(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        $depense = $this->depenseOu404($id);
        $lignes = array_map(static fn (array $p): array => [
            'compte_id' => (int) $p['compte_id'], 'devise_id' => (int) $p['devise_id'], 'montant' => TresorerieAffichage::saisie($p['montant']),
        ], $depense['paiements']);
        $this->afficherFormulaire($utilisateur, $depense, [
            'date' => TresorerieAffichage::champDate($depense['date_depense']),
            'categorie_id' => (int) $depense['categorie_id'],
            'etablissement_id' => $depense['etablissement_id'] !== null ? (string) $depense['etablissement_id'] : '',
            'libelle' => $depense['libelle'], 'beneficiaire' => (string) $depense['beneficiaire'], 'notes' => (string) $depense['notes'],
        ], $lignes ?: [self::LIGNE_VIDE]);
    }

    public function modifier(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $this->enregistrer($utilisateur, $this->depenseOu404($id));
    }

    public function supprimer(string $id): void
    {
        Session::exigerConnexion();
        Session::verifierCsrf();
        $depense = $this->depenseOu404($id);
        $this->depenses->supprimer((int) $depense['id']);
        Session::flash(t('depenses.supprimee', ['libelle' => $depense['libelle']]));
        rediriger('/gestion/depenses');
    }

    private function enregistrer(array $utilisateur, ?array $depense): void
    {
        $devises = $this->tresorerie->devises();
        $tousComptes = array_column($this->tresorerie->comptes(), null, 'id');
        // Comptes utilisables : les actifs, et ceux déjà utilisés par cette dépense ; ce qu'elle sortait déjà
        // de chaque (compte, devise) compte dans le solde disponible
        $dejaUtilises = $depense ? array_map(static fn (array $p): int => (int) $p['compte_id'], $depense['paiements']) : [];
        $anciennesSorties = [];
        foreach ($depense['paiements'] ?? [] as $paiement) {
            $cle = (int) $paiement['compte_id'] . '-' . (int) $paiement['devise_id'];
            $anciennesSorties[$cle] = ($anciennesSorties[$cle] ?? 0) + (float) $paiement['montant'];
        }

        $valeurs = [
            'date' => TresorerieSaisie::post('date'),
            'categorie_id' => TresorerieSaisie::id($_POST['categorie_id'] ?? null),
            'etablissement_id' => TresorerieSaisie::post('etablissement_id'),
            'libelle' => mb_substr(TresorerieSaisie::post('libelle'), 0, 300),
            'beneficiaire' => mb_substr(TresorerieSaisie::post('beneficiaire'), 0, 200),
            'notes' => mb_substr(TresorerieSaisie::post('notes', true), 0, 5000),
        ];

        // Lignes de paiement saisies (les lignes entièrement vides sont ignorées)
        $lignes = [];
        foreach (is_array($_POST['paiements'] ?? null) ? $_POST['paiements'] : [] as $ligne) {
            if (!is_array($ligne) || count($lignes) >= self::MAX_PAIEMENTS) {
                continue;
            }
            $compteId = TresorerieSaisie::id($ligne['compte_id'] ?? null);
            $deviseId = TresorerieSaisie::id($ligne['devise_id'] ?? null);
            // Compte à une seule devise : elle est prise d'office
            if ($deviseId === null && $compteId !== null && isset($tousComptes[$compteId])) {
                $tenues = array_keys(array_filter($tousComptes[$compteId]['devises'], static fn (array $d): bool => $d['tenue']));
                $deviseId = count($tenues) === 1 ? $tenues[0] : null;
            }
            $lignes[] = ['compte_id' => $compteId, 'devise_id' => $deviseId, 'montant' => TresorerieSaisie::texte($ligne['montant'] ?? null)];
        }

        // Sans JavaScript : « Ajouter un paiement » renvoie le formulaire avec une ligne de plus :
        // le même compte dans une autre devise s'il en tient une autre
        if (($_POST['action'] ?? '') === 'ajouter_ligne') {
            if (count($lignes) < self::MAX_PAIEMENTS) {
                $derniere = $lignes[count($lignes) - 1] ?? null;
                $compte = $derniere && $derniere['compte_id'] !== null ? ($tousComptes[$derniere['compte_id']] ?? null) : null;
                $prises = [];
                foreach ($lignes as $l) {
                    if ($compte !== null && $l['compte_id'] === (int) $compte['id'] && $l['devise_id'] !== null) {
                        $prises[] = $l['devise_id'];
                    }
                }
                $lignes[] = $compte
                    ? ['compte_id' => (int) $compte['id'], 'devise_id' => $this->devisePreferee($compte, $prises), 'montant' => '']
                    : self::LIGNE_VIDE;
            }
            $this->afficherFormulaire($utilisateur, $depense, $valeurs, $lignes);
            return;
        }

        $erreurs = [];
        [$date, $erreurDate] = TresorerieSaisie::dateOperation($valeurs['date']);
        if ($erreurDate !== null) {
            $erreurs['date'] = $erreurDate;
        }
        $categorie = $valeurs['categorie_id'] !== null ? $this->depenses->categorie($valeurs['categorie_id']) : null;
        if ($categorie === null || (!$categorie['active'] && (int) ($depense['categorie_id'] ?? 0) !== (int) $categorie['id'])) {
            $erreurs['categorie_id'] = t('depenses.erreur_categorie');
        }
        $etablissementId = null;
        if ($valeurs['etablissement_id'] !== '') {
            $etablissementId = TresorerieSaisie::id($valeurs['etablissement_id']);
            $ids = array_map('intval', array_column((new GestionEtablissementRepository())->liste(), 'id'));
            if ($etablissementId === null || !in_array($etablissementId, $ids, true)) {
                $erreurs['etablissement_id'] = t('tresorerie.erreur_etablissement');
                $valeurs['etablissement_id'] = '';
                $etablissementId = null;
            }
        }
        if ($valeurs['libelle'] === '') {
            $erreurs['libelle'] = t('depenses.erreur_libelle');
        } elseif (mb_strlen($valeurs['libelle']) > 255) {
            $erreurs['libelle'] = t('tresorerie.erreur_texte_long', ['n' => 255]);
        }
        if (mb_strlen($valeurs['beneficiaire']) > 128) {
            $erreurs['beneficiaire'] = t('tresorerie.erreur_texte_long', ['n' => 128]);
        }

        $paiements = [];
        $vus = [];
        foreach ($lignes as $i => $ligne) {
            if ($ligne['compte_id'] === null && $ligne['montant'] === '') {
                continue;
            }
            $numero = ['n' => $i + 1];
            $compte = $ligne['compte_id'] !== null ? ($tousComptes[$ligne['compte_id']] ?? null) : null;
            $devise = null;
            if ($compte === null) {
                $erreurs['paiements-' . $i . '-compte'] = t('depenses.erreur_compte', $numero);
            } elseif (!$compte['active'] && !in_array((int) $compte['id'], $dejaUtilises, true)) {
                $erreurs['paiements-' . $i . '-compte'] = t('depenses.erreur_compte_inactif', $numero + ['compte' => $compte['nom']]);
                $compte = null;
            } elseif ($ligne['devise_id'] === null || !GestionTresorerieRepository::tient($compte, $ligne['devise_id']) || !isset($devises[$ligne['devise_id']])) {
                $tenues = array_keys(array_filter($compte['devises'], static fn (array $d): bool => $d['tenue']));
                $erreurs['paiements-' . $i . '-devise'] = t('depenses.paiement_n', $numero) . ' : ' . t('tresorerie.erreur_devise_compte', [
                    'compte' => $compte['nom'],
                    'codes' => implode(', ', array_map(static fn (int $id): string => $devises[$id]['code'] ?? '?', $tenues)),
                ]);
            } else {
                $devise = $devises[$ligne['devise_id']];
                $cle = (int) $compte['id'] . '-' . (int) $devise['id'];
                if (isset($vus[$cle])) {
                    $erreurs['paiements-' . $i . '-compte'] = t('depenses.erreur_compte_double', $numero + ['compte' => $compte['nom'], 'code' => $devise['code']]);
                    $devise = null;
                }
            }
            if ($devise !== null) {
                [$montant, $erreur] = TresorerieSaisie::montant($ligne['montant'], $devise);
                if ($erreur !== null) {
                    $erreurs['paiements-' . $i . '-montant'] = t('depenses.paiement_n', $numero) . ' : ' . $erreur;
                } else {
                    $vus[(int) $compte['id'] . '-' . (int) $devise['id']] = true;
                    $paiements[] = ['compte' => $compte, 'devise' => $devise, 'montant' => $montant];
                }
            }
        }
        if ($paiements === [] && !array_filter(array_keys($erreurs), static fn ($cle) => str_starts_with((string) $cle, 'paiements-'))) {
            $erreurs['paiements'] = t('depenses.erreur_aucun_paiement');
        }

        if ($erreurs !== []) {
            $this->afficherFormulaire($utilisateur, $depense, $valeurs, $lignes ?: [self::LIGNE_VIDE], $erreurs, [], 422);
            return;
        }

        // Découvert dans la devise payée : prévenir sans bloquer (le comptage d'une caisse peut être faux)
        $avertissements = [];
        foreach ($paiements as $paiement) {
            $compte = $paiement['compte'];
            $deviseId = (int) $paiement['devise']['id'];
            $solde = GestionTresorerieRepository::solde($compte, $deviseId) + ($anciennesSorties[(int) $compte['id'] . '-' . $deviseId] ?? 0);
            $apres = $solde - $paiement['montant'];
            if ($apres < -0.0005) {
                $avertissements[] = t('tresorerie.avertissement_decouvert', [
                    'compte' => $compte['nom'],
                    'code' => $paiement['devise']['code'],
                    'solde' => TresorerieAffichage::montant($solde, $paiement['devise']),
                    'apres' => TresorerieAffichage::montant($apres, $paiement['devise']),
                ]);
            }
        }
        if ($avertissements !== [] && empty($_POST['confirme'])) {
            $this->afficherFormulaire($utilisateur, $depense, $valeurs, $lignes, [], $avertissements);
            return;
        }

        $enregistrement = [
            'date_depense' => $date,
            'categorie_id' => (int) $categorie['id'],
            'etablissement_id' => $etablissementId,
            'libelle' => $valeurs['libelle'],
            'beneficiaire' => $valeurs['beneficiaire'] !== '' ? $valeurs['beneficiaire'] : null,
            'notes' => $valeurs['notes'] !== '' ? $valeurs['notes'] : null,
        ];
        $resume = implode(' + ', array_map(static fn (array $p): string => TresorerieAffichage::montant($p['montant'], $p['devise']), $paiements));
        if ($depense === null) {
            $this->depenses->creer($enregistrement, $paiements, (int) $utilisateur['id']);
            Session::flash(t('depenses.enregistree', ['libelle' => $valeurs['libelle'], 'montant' => $resume]));
        } else {
            $this->depenses->modifier((int) $depense['id'], $enregistrement, $paiements, (int) $utilisateur['id']);
            Session::flash(t('depenses.modifiee', ['libelle' => $valeurs['libelle'], 'montant' => $resume]));
        }
        rediriger('/gestion/depenses');
    }

    /** Équivalent d'un paiement en devise de référence : la contre-valeur du journal, sinon au taux du jour. */
    private function equivalent(array $paiement, array $devises, ?array $reference): float
    {
        if ($reference === null) {
            return 0.0;
        }
        if ($paiement['contre_valeur'] !== null && (int) $paiement['reference_id'] === (int) $reference['id']) {
            return (float) $paiement['contre_valeur'];
        }
        $taux = $devises[(int) $paiement['devise_id']]['taux'] ?? null;
        return $taux !== null && (float) $taux > 0 ? (float) $paiement['montant'] / (float) $taux : 0.0;
    }

    private function afficherFormulaire(array $utilisateur, ?array $depense, array $valeurs, array $lignes,
                                        array $erreurs = [], array $avertissements = [], int $code = 200): void
    {
        http_response_code($code);
        $comptes = $this->tresorerie->comptesActifs();
        // Un compte désactivé reste proposé s'il paie déjà cette dépense
        foreach ($depense['paiements'] ?? [] as $paiement) {
            if (!isset($comptes[(int) $paiement['compte_id']]) && ($compte = $this->tresorerie->compte((int) $paiement['compte_id']))) {
                $comptes[(int) $compte['id']] = $compte;
            }
        }
        $categories = array_filter($this->depenses->categories(), static fn (array $c): bool =>
            $c['active'] || (int) $c['id'] === (int) ($depense['categorie_id'] ?? 0));

        View::afficher('gestion/depenses/formulaire', [
            'titre' => $depense ? t('depenses.modifier_titre') : t('depenses.nouvelle'),
            'utilisateur' => $utilisateur,
            'depense' => $depense,
            'valeurs' => $valeurs,
            'lignes' => $lignes,
            'erreurs' => $erreurs,
            'avertissements' => $avertissements,
            'comptes' => $comptes,
            'devises' => $this->tresorerie->devises(),
            'reference' => $this->tresorerie->reference(),
            'categories' => $categories,
            'etablissements' => (new GestionEtablissementRepository())->liste(),
            'maxPaiements' => self::MAX_PAIEMENTS,
        ], 'gestion');
    }

    private function depenseOu404(string $id): array
    {
        $depense = ctype_digit($id) ? $this->depenses->depense((int) $id) : null;
        if ($depense === null) {
            pageIntrouvable();
        }
        return $depense;
    }
}
