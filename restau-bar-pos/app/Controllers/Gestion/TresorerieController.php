<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Session;
use App\Core\View;
use App\Repositories\GestionEtablissementRepository;
use App\Repositories\GestionTresorerieRepository;

/**
 * Trésorerie : vue d'ensemble des comptes (groupés par type, un solde par devise), création et modification
 * d'un compte avec les devises qu'il tient, fiche d'un compte avec ses mouvements sur une période.
 */
final class TresorerieController
{
    private GestionTresorerieRepository $tresorerie;

    public function __construct()
    {
        $this->tresorerie = new GestionTresorerieRepository();
    }

    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $devises = $this->tresorerie->devises();
        $reference = $this->tresorerie->reference();
        $comptes = $this->tresorerie->comptes();

        $groupes = array_fill_keys(GestionTresorerieRepository::TYPES, []);
        $inactifs = [];
        $totaux = [];
        $sousTotaux = [];
        foreach ($comptes as $compte) {
            if (!$compte['active']) {
                $inactifs[] = $compte;
                continue;
            }
            $groupes[$compte['type']][] = $compte;
            foreach ($compte['devises'] as $deviseId => $ligne) {
                $totaux[$deviseId] = ($totaux[$deviseId] ?? 0) + $ligne['solde'];
                $sousTotaux[$compte['type']][$deviseId] = ($sousTotaux[$compte['type']][$deviseId] ?? 0) + $ligne['solde'];
            }
        }
        // Ordre des devises : locale, dollar, autres
        $ordre = array_flip(array_keys($devises));
        $trier = static fn (array &$liste) => uksort($liste, static fn ($a, $b) => ($ordre[$a] ?? 999) <=> ($ordre[$b] ?? 999));
        $trier($totaux);
        foreach ($sousTotaux as &$liste) {
            $trier($liste);
        }
        unset($liste);

        // Total général converti dans la devise de référence, au taux du jour
        $totalReference = 0.0;
        $sansTaux = [];
        foreach ($totaux as $deviseId => $montant) {
            $taux = $devises[$deviseId]['taux'] ?? null;
            if ($taux === null || (float) $taux <= 0) {
                $sansTaux[] = $devises[$deviseId]['code'] ?? '?';
                continue;
            }
            $totalReference += $montant / (float) $taux;
        }
        $locale = $this->tresorerie->deviseRepository()->locale();

        View::afficher('gestion/tresorerie/index', [
            'titre' => t('tresorerie.titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'devises' => $devises,
            'reference' => $reference,
            'locale' => $locale,
            'groupes' => $groupes,
            'sousTotaux' => $sousTotaux,
            'inactifs' => $inactifs,
            'totaux' => $totaux,
            'totalReference' => $reference ? round($totalReference, (int) $reference['decimales']) : null,
            'totalLocale' => $locale && $reference && (int) $locale['id'] !== (int) $reference['id']
                ? $this->tresorerie->deviseRepository()->convertirDepuisReference($totalReference, $locale)
                : null,
            'sansTaux' => $sansTaux,
            'nbComptes' => count($comptes),
        ], 'gestion');
    }

    public function nouveau(): void
    {
        $utilisateur = Session::exigerConnexion();
        $type = in_array($_GET['type'] ?? null, GestionTresorerieRepository::TYPES, true) ? $_GET['type'] : 'caisse';
        // Devise locale cochée d'office ; les autres devises actives à cocher au besoin
        $locale = $this->tresorerie->deviseRepository()->locale() ?? $this->tresorerie->reference();
        $devises = [];
        foreach ($this->tresorerie->deviseRepository()->actives() as $id => $devise) {
            $devises[$id] = ['tenue' => $locale !== null && (int) $locale['id'] === $id, 'solde_initial' => ''];
        }
        $this->afficherFormulaire($utilisateur, null, [
            'nom' => '', 'type' => $type, 'operateur' => '', 'numero' => '',
            'etablissement_id' => null, 'solde_initial_le' => date('Y-m-d'), 'devises' => $devises,
            'position' => $this->tresorerie->positionSuivante($type),
        ]);
    }

    public function creer(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        [$valeurs, $erreurs] = $this->lire(null);
        if ($erreurs !== []) {
            $this->afficherFormulaire($utilisateur, null, $valeurs, $erreurs, 422);
            return;
        }
        $id = $this->tresorerie->creerCompte($valeurs, (int) $utilisateur['id']);
        Session::flash(t('tresorerie.compte_cree', ['nom' => $valeurs['nom']]));
        rediriger('/gestion/tresorerie/comptes/' . $id);
    }

    /** Fiche d'un compte : solde de chaque devise, mouvements de la période avec le solde après chaque ligne. */
    public function fiche(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        $compte = $this->compteOu404($id);
        $this->afficherFiche($utilisateur, $compte);
    }

    public function editer(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        $compte = $this->compteOu404($id);
        $valeurs = $compte;
        $valeurs['solde_initial_le'] = substr((string) $compte['solde_initial_le'], 0, 10);
        $valeurs['devises'] = [];
        foreach ($this->choixDevises($compte) as $deviseId => $devise) {
            $tenue = GestionTresorerieRepository::tient($compte, $deviseId);
            $initial = $compte['devises'][$deviseId]['solde_initial'] ?? 0.0;
            $valeurs['devises'][$deviseId] = ['tenue' => $tenue, 'solde_initial' => $tenue && $initial > 0 ? TresorerieAffichage::saisie($initial) : ''];
        }
        $this->afficherFormulaire($utilisateur, $compte, $valeurs);
    }

    public function modifier(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $compte = $this->compteOu404($id);
        [$valeurs, $erreurs] = $this->lire($compte);
        if ($erreurs !== []) {
            $this->afficherFormulaire($utilisateur, $compte, $valeurs, $erreurs, 422);
            return;
        }
        $this->tresorerie->modifierCompte((int) $compte['id'], $valeurs, (int) $utilisateur['id']);
        Session::flash(t('tresorerie.compte_enregistre', ['nom' => $valeurs['nom']]));
        rediriger('/gestion/tresorerie/comptes/' . (int) $compte['id']);
    }

    /** Désactivation : confirmation si le compte a encore de l'argent dans une de ses devises. */
    public function desactiver(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $compte = $this->compteOu404($id);
        $nonNul = array_filter($compte['devises'], static fn (array $d): bool => abs($d['solde']) >= 0.0005);
        if ($nonNul !== [] && empty($_POST['confirme'])) {
            $this->afficherFiche($utilisateur, $compte, ['confirmerDesactivation' => true]);
            return;
        }
        $this->tresorerie->definirActif((int) $compte['id'], false);
        Session::flash(t('tresorerie.compte_desactive', ['nom' => $compte['nom']]));
        rediriger('/gestion/tresorerie');
    }

    public function activer(string $id): void
    {
        Session::exigerConnexion();
        Session::verifierCsrf();
        $compte = $this->compteOu404($id);
        $this->tresorerie->definirActif((int) $compte['id'], true);
        Session::flash(t('tresorerie.compte_active', ['nom' => $compte['nom']]));
        rediriger('/gestion/tresorerie/comptes/' . (int) $compte['id']);
    }

    /** Suppression : seulement pour un compte sans mouvement (hors soldes initiaux), sinon on le désactive. */
    public function supprimer(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $compte = $this->compteOu404($id);
        if ((int) $compte['nb_mouvements'] > 0) {
            $this->afficherFiche($utilisateur, $compte, ['erreurs' => [t('tresorerie.erreur_supprimer_mouvements', ['nom' => $compte['nom']])]], 422);
            return;
        }
        $this->tresorerie->supprimerCompte((int) $compte['id']);
        Session::flash(t('tresorerie.compte_supprime', ['nom' => $compte['nom']]));
        rediriger('/gestion/tresorerie');
    }

    private function afficherFiche(array $utilisateur, array $compte, array $etat = [], int $code = 200): void
    {
        http_response_code($code);
        $devises = $this->tresorerie->devises();
        $periode = TresorerieSaisie::periode('mois');
        // Filtre de devise : une des devises du compte (?devise=2), sinon toutes
        $filtre = TresorerieSaisie::id($_GET['devise'] ?? null);
        if ($filtre !== null && !isset($compte['devises'][$filtre])) {
            $filtre = null;
        }
        $affichees = $filtre !== null ? [$filtre] : array_keys($compte['devises']);

        $mouvements = $this->tresorerie->mouvements((int) $compte['id'], $periode['debut'], $periode['fin'], $filtre);
        $avant = $periode['du'] !== null ? $this->tresorerie->soldesAvant((int) $compte['id'], $periode['debut']) : [];
        $soldesDebut = $soldes = $entrees = $sorties = [];
        foreach ($affichees as $deviseId) {
            $soldesDebut[$deviseId] = $soldes[$deviseId] = $avant[$deviseId] ?? 0.0;
            $entrees[$deviseId] = $sorties[$deviseId] = 0.0;
        }
        foreach ($mouvements as &$mouvement) {
            $deviseId = (int) $mouvement['devise_id'];
            $montant = (float) $mouvement['montant'];
            $soldes[$deviseId] ??= 0.0;
            $entrees[$deviseId] ??= 0.0;
            $sorties[$deviseId] ??= 0.0;
            if ($mouvement['sens'] === 'entree') {
                $soldes[$deviseId] += $montant;
                $entrees[$deviseId] += $montant;
            } else {
                $soldes[$deviseId] -= $montant;
                $sorties[$deviseId] += $montant;
            }
            $mouvement['solde_apres'] = $soldes[$deviseId];
        }
        unset($mouvement);

        View::afficher('gestion/tresorerie/compte', [
            'titre' => $compte['nom'],
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'compte' => $compte,
            'devises' => $devises,
            'filtre' => $filtre,
            'periode' => $periode,
            'mouvements' => $mouvements,
            'soldesDebut' => $soldesDebut,
            'soldesFin' => $soldes,
            'entrees' => $entrees,
            'sorties' => $sorties,
            'erreurs' => $etat['erreurs'] ?? [],
            'confirmerDesactivation' => $etat['confirmerDesactivation'] ?? false,
        ], 'gestion');
    }

    private function afficherFormulaire(array $utilisateur, ?array $compte, array $valeurs, array $erreurs = [], int $code = 200): void
    {
        http_response_code($code);
        View::afficher('gestion/tresorerie/compte-form', [
            'titre' => $compte ? t('tresorerie.modifier_compte', ['nom' => $compte['nom']]) : t('tresorerie.nouveau_compte'),
            'utilisateur' => $utilisateur,
            'compte' => $compte,
            'valeurs' => $valeurs,
            'erreurs' => $erreurs,
            'choixDevises' => $this->choixDevises($compte),
            'etablissements' => (new GestionEtablissementRepository())->liste(),
        ], 'gestion');
    }

    /**
     * Devises proposées dans le formulaire : les actives (locale, dollar, autres), plus celles, désactivées
     * depuis, que le compte tient encore.
     * @return array<int, array>
     */
    private function choixDevises(?array $compte): array
    {
        $toutes = $this->tresorerie->devises();
        $choix = $this->tresorerie->deviseRepository()->actives();
        foreach ($compte['devises'] ?? [] as $deviseId => $ligne) {
            if ($ligne['tenue'] && !isset($choix[$deviseId]) && isset($toutes[$deviseId])) {
                $choix[$deviseId] = $toutes[$deviseId];
            }
        }
        return $choix;
    }

    /** @return array{array, array<string, string>} valeurs lues et erreurs par champ */
    private function lire(?array $compte): array
    {
        $actives = $this->tresorerie->deviseRepository()->actives();
        $etablissements = array_column((new GestionEtablissementRepository())->liste(), null, 'id');

        $valeurs = [
            'nom' => mb_substr(TresorerieSaisie::post('nom'), 0, 200),
            'type' => TresorerieSaisie::post('type'),
            'operateur' => mb_substr(TresorerieSaisie::post('operateur'), 0, 200),
            'numero' => mb_substr(TresorerieSaisie::post('numero'), 0, 100),
            'etablissement_id' => TresorerieSaisie::id($_POST['etablissement_id'] ?? null),
            'solde_initial_le' => TresorerieSaisie::post('solde_initial_le'),
            'position' => TresorerieSaisie::post('position'),
            'devises' => [],
        ];
        $erreurs = [];

        if ($valeurs['nom'] === '') {
            $erreurs['nom'] = t('tresorerie.erreur_nom');
        } elseif (mb_strlen($valeurs['nom']) > 128) {
            $erreurs['nom'] = t('tresorerie.erreur_nom_long');
        } elseif ($this->tresorerie->nomPris($valeurs['nom'], $compte ? (int) $compte['id'] : 0)) {
            $erreurs['nom'] = t('tresorerie.erreur_nom_pris', ['nom' => $valeurs['nom']]);
        }
        if (!in_array($valeurs['type'], GestionTresorerieRepository::TYPES, true)) {
            $erreurs['type'] = t('tresorerie.erreur_type');
            $valeurs['type'] = 'caisse';
        }

        // Devises tenues : cochées, chacune avec son solde initial ; une devise qui a des mouvements reste
        $saisies = is_array($_POST['devises'] ?? null) ? $_POST['devises'] : [];
        $soldesInitiaux = [];
        foreach ($this->choixDevises($compte) as $deviseId => $devise) {
            $ligne = is_array($saisies[$deviseId] ?? null) ? $saisies[$deviseId] : [];
            $tenue = !empty($ligne['tenue']);
            $texte = TresorerieSaisie::texte($ligne['solde_initial'] ?? null);
            $existante = $compte['devises'][$deviseId] ?? null;
            $valeurs['devises'][$deviseId] = ['tenue' => $tenue, 'solde_initial' => $texte];
            if (!$tenue) {
                if ($existante !== null && $existante['tenue'] && $existante['nb_mouvements'] > 0) {
                    $erreurs['devise-' . $deviseId] = t('tresorerie.erreur_retirer_devise', ['code' => $devise['code'], 'nom' => $compte['nom']]);
                    $valeurs['devises'][$deviseId]['tenue'] = true;
                }
                continue;
            }
            if (!isset($actives[$deviseId]) && !($existante !== null && $existante['tenue'])) {
                $erreurs['devise-' . $deviseId] = t('tresorerie.erreur_devise');
                continue;
            }
            $soldesInitiaux[$deviseId] = 0.0;
            if ($texte !== '') {
                [$montant, $erreur] = TresorerieSaisie::montant($texte, $devise, true);
                if ($erreur !== null) {
                    $erreurs['devise-' . $deviseId] = t('tresorerie.solde_initial_en', ['code' => $devise['code']]) . ' : ' . $erreur;
                } else {
                    $soldesInitiaux[$deviseId] = $montant;
                }
            }
        }
        if (!array_filter(array_column($valeurs['devises'], 'tenue'))) {
            $erreurs['devises'] = t('tresorerie.erreur_devises');
        }

        if (mb_strlen($valeurs['operateur']) > 128) {
            $erreurs['operateur'] = t('tresorerie.erreur_texte_long', ['n' => 128]);
        }
        if (mb_strlen($valeurs['numero']) > 64) {
            $erreurs['numero'] = t('tresorerie.erreur_texte_long', ['n' => 64]);
        }
        if ($valeurs['etablissement_id'] !== null && !isset($etablissements[$valeurs['etablissement_id']])) {
            $erreurs['etablissement_id'] = t('tresorerie.erreur_etablissement');
            $valeurs['etablissement_id'] = null;
        }

        $dateSolde = $valeurs['solde_initial_le'] === '' ? date('Y-m-d') : $valeurs['solde_initial_le'];
        [$dateLue, $erreurDate] = TresorerieSaisie::dateOperation($dateSolde);
        if ($erreurDate !== null) {
            $erreurs['solde_initial_le'] = $erreurDate;
        }

        if ($valeurs['position'] === '') {
            $valeurs['position'] = (string) ($compte ? (int) $compte['position'] : $this->tresorerie->positionSuivante($valeurs['type']));
        }
        if (!ctype_digit($valeurs['position']) || (int) $valeurs['position'] > 999) {
            $erreurs['position'] = t('tresorerie.erreur_position');
        }

        if ($erreurs !== []) {
            return [$valeurs, $erreurs];
        }
        return [[
            'nom' => $valeurs['nom'],
            'type' => $valeurs['type'],
            'operateur' => $valeurs['operateur'] !== '' ? $valeurs['operateur'] : null,
            'numero' => $valeurs['numero'] !== '' ? $valeurs['numero'] : null,
            'etablissement_id' => $valeurs['etablissement_id'],
            'solde_initial_le' => substr((string) $dateLue, 0, 10) . ' 00:00:00',
            'position' => (int) $valeurs['position'],
            'devises' => $soldesInitiaux,
        ], []];
    }

    private function compteOu404(string $id): array
    {
        $compte = ctype_digit($id) ? $this->tresorerie->compte((int) $id) : null;
        if ($compte === null) {
            pageIntrouvable();
        }
        return $compte;
    }
}
