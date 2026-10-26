<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Csv;
use App\Core\Session;
use App\Core\View;
use App\Repositories\DeviseRepository;
use App\Repositories\EvenementRepository;
use App\Repositories\RapportRepository;

/**
 * Événements > Soirées : liste en calendrier (à venir, puis passées avec leur résultat), fiche d'une soirée
 * (recettes de la nuit, coûts rattachés, coût des marchandises, résultat, budget), création et modification,
 * rattachement des dépenses, annulation. Événements > Rentabilité : les soirées passées classées par résultat.
 * evenements.voir pour lire, evenements.gerer pour modifier, pour l'établissement de la soirée.
 */
final class EvenementController
{
    private EvenementRepository $evenements;

    public function __construct()
    {
        $this->evenements = new EvenementRepository();
    }

    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $uid = (int) $utilisateur['id'];
        $etablissements = $this->evenements->etablissements($uid, 'evenements.voir');
        $etablissement = $this->etablissementChoisi($etablissements);
        $voirMarges = $this->voirMarges($uid);

        $aVenir = $this->evenements->liste($uid, $etablissement, 'a_venir');
        $passes = array_slice($this->evenements->liste($uid, $etablissement, 'passes'), 0, 40);
        foreach ($passes as &$ev) {
            $ev['bilan'] = $this->evenements->bilan($ev, $voirMarges, false);
        }
        unset($ev);

        if (Csv::demande()) {
            $this->csvListe(array_merge($aVenir, $passes), $voirMarges);
        }

        View::afficher('gestion/evenements/index', [
            'titre' => t('evenements.titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'etablissements' => $etablissements,
            'etablissement' => $etablissement,
            'aVenir' => $aVenir,
            'passes' => $passes,
            'peutCreer' => $this->evenements->etablissements($uid, 'evenements.gerer') !== [],
            'locale' => $this->evenements->rapports()->locale(),
            'reference' => $this->evenements->rapports()->reference(),
            'taux' => $this->evenements->rapports()->tauxLocal(),
        ], 'gestion');
    }

    public function rentabilite(): void
    {
        $utilisateur = Session::exigerConnexion();
        $uid = (int) $utilisateur['id'];
        $etablissements = $this->evenements->etablissements($uid, 'evenements.voir');
        $etablissement = $this->etablissementChoisi($etablissements);
        $periode = TresorerieSaisie::periode('annee');
        $voirMarges = $this->voirMarges($uid);
        $soirees = [];
        foreach ($this->evenements->liste($uid, $etablissement, 'passes') as $ev) {
            if ($ev['statut'] === 'annule' || $ev['debut'] < $periode['debut'] || $ev['debut'] > $periode['fin']) {
                continue;
            }
            $ev['bilan'] = $this->evenements->bilan($ev, $voirMarges, false);
            $soirees[] = $ev;
        }
        usort($soirees, static fn (array $a, array $b) => $b['bilan']['resultat'] <=> $a['bilan']['resultat']);
        $totaux = ['ca_locale' => 0.0, 'ca_reference' => 0.0, 'couts' => 0.0, 'marchandises' => 0.0, 'resultat' => 0.0, 'tickets' => 0];
        foreach ($soirees as $ev) {
            $totaux['ca_locale'] += $ev['bilan']['totaux']['ca_locale'];
            $totaux['ca_reference'] += $ev['bilan']['totaux']['ca_reference'];
            $totaux['tickets'] += $ev['bilan']['totaux']['tickets'];
            $totaux['couts'] += $ev['bilan']['couts'];
            $totaux['marchandises'] += $ev['bilan']['marchandises'];
            $totaux['resultat'] += $ev['bilan']['resultat'];
        }
        if (Csv::demande()) {
            $this->csvListe($soirees, $voirMarges, 'evenements-rentabilite', $periode);
        }
        View::afficher('gestion/evenements/rentabilite', [
            'titre' => t('evenements.rentabilite_titre'),
            'utilisateur' => $utilisateur,
            'etablissements' => $etablissements,
            'etablissement' => $etablissement,
            'periode' => $periode,
            'soirees' => $soirees,
            'totaux' => $totaux,
            'voirMarges' => $voirMarges,
            'locale' => $this->evenements->rapports()->locale(),
            'reference' => $this->evenements->rapports()->reference(),
            'taux' => $this->evenements->rapports()->tauxLocal(),
        ], 'gestion');
    }

    public function fiche(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        $ev = $this->evenementOu404($id, $utilisateur);
        $this->afficherFiche($utilisateur, $ev);
    }

    public function nouveau(): void
    {
        $utilisateur = Session::exigerConnexion();
        $etablissements = $this->evenements->etablissements((int) $utilisateur['id'], 'evenements.gerer');
        if ($etablissements === []) {
            DetteAffichage::interdit($utilisateur);
        }
        // Prochain vendredi, de 22 h à 5 h
        $vendredi = new \DateTimeImmutable('friday');
        $this->afficherFormulaire($utilisateur, null, [
            'etablissement_id' => (string) (TresorerieSaisie::id($_GET['etablissement'] ?? null) ?? $etablissements[0]['id']),
            'titre' => '', 'theme' => '', 'artistes' => '', 'description' => '',
            'debut' => $vendredi->setTime(22, 0)->format('Y-m-d\TH:i'),
            'fin' => $vendredi->modify('+1 day')->setTime(5, 0)->format('Y-m-d\TH:i'),
            'budget' => '', 'budget_devise_id' => (string) ((new DeviseRepository())->reference()['id'] ?? ''),
        ]);
    }

    public function creer(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        [$valeurs, $propres, $erreurs] = $this->lire((int) $utilisateur['id']);
        if ($erreurs !== []) {
            $this->afficherFormulaire($utilisateur, null, $valeurs, $erreurs, 422);
            return;
        }
        $id = $this->evenements->enregistrer(null, $propres, (int) $utilisateur['id']);
        Session::flash(t('evenements.cree', ['titre' => $propres['titre']]));
        rediriger('/gestion/evenements/' . $id);
    }

    public function editer(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        $ev = $this->evenementOu404($id, $utilisateur);
        $this->exigerGerer($utilisateur, $ev);
        $this->afficherFormulaire($utilisateur, $ev, [
            'etablissement_id' => (string) $ev['etablissement_id'], 'titre' => $ev['titre'], 'theme' => (string) $ev['theme'],
            'artistes' => (string) $ev['artistes'], 'description' => (string) $ev['description'],
            'debut' => TresorerieAffichage::champDate($ev['debut']), 'fin' => TresorerieAffichage::champDate($ev['fin']),
            'budget' => $ev['budget'] !== null ? TresorerieAffichage::saisie($ev['budget']) : '',
            'budget_devise_id' => (string) ($ev['budget_devise_id'] ?? ''),
        ]);
    }

    public function modifier(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $ev = $this->evenementOu404($id, $utilisateur);
        $this->exigerGerer($utilisateur, $ev);
        [$valeurs, $propres, $erreurs] = $this->lire((int) $utilisateur['id']);
        if ($erreurs !== []) {
            $this->afficherFormulaire($utilisateur, $ev, $valeurs, $erreurs, 422);
            return;
        }
        $this->evenements->enregistrer((int) $ev['id'], $propres, (int) $utilisateur['id']);
        Session::flash(t('evenements.enregistre', ['titre' => $propres['titre']]));
        rediriger('/gestion/evenements/' . (int) $ev['id']);
    }

    /** Rattacher les dépenses cochées, détacher celles décochées. */
    public function couts(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $ev = $this->evenementOu404($id, $utilisateur);
        $this->exigerGerer($utilisateur, $ev);
        $ids = static fn (mixed $liste): array => array_values(array_filter(array_map(
            static fn ($v) => TresorerieSaisie::id(is_string($v) ? $v : null), is_array($liste) ? $liste : [])));
        $n = $this->evenements->lier($ev, $ids($_POST['lier'] ?? []), $ids($_POST['detacher'] ?? []), (int) $utilisateur['id']);
        Session::flash(t('evenements.couts_enregistres', ['n' => $n]));
        rediriger('/gestion/evenements/' . (int) $ev['id'] . '#couts');
    }

    public function statut(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $ev = $this->evenementOu404($id, $utilisateur);
        $this->exigerGerer($utilisateur, $ev);
        $statut = ($_POST['statut'] ?? '') === 'annule' ? 'annule' : 'prevu';
        $this->evenements->definirStatut((int) $ev['id'], $statut, (int) $utilisateur['id']);
        Session::flash(t($statut === 'annule' ? 'evenements.annule_flash' : 'evenements.retabli_flash', ['titre' => $ev['titre']]));
        rediriger('/gestion/evenements/' . (int) $ev['id']);
    }

    public function supprimer(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $ev = $this->evenementOu404($id, $utilisateur);
        $this->exigerGerer($utilisateur, $ev);
        if (!$this->evenements->supprimer((int) $ev['id'], (int) $utilisateur['id'])) {
            $this->afficherFiche($utilisateur, $ev, t('evenements.erreur_supprimer'), 409);
            return;
        }
        Session::flash(t('evenements.supprime', ['titre' => $ev['titre']]));
        rediriger('/gestion/evenements');
    }

    // ---------- Affichage ----------

    private function afficherFiche(array $utilisateur, array $ev, ?string $erreur = null, int $code = 200): void
    {
        http_response_code($code);
        $uid = (int) $utilisateur['id'];
        $voirMarges = $this->voirMarges($uid);
        $bilan = $this->evenements->bilan($ev, $voirMarges);
        $peutGerer = Acces::a($uid, 'evenements.gerer', (int) $ev['etablissement_id']);
        if (Csv::demande()) {
            $this->csvFiche($ev, $bilan);
        }
        View::afficher('gestion/evenements/fiche', [
            'titre' => $ev['titre'],
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'erreur' => $erreur,
            'ev' => $ev,
            'bilan' => $bilan,
            'candidates' => $peutGerer ? $this->evenements->depenses($ev, true) : [],
            'peutGerer' => $peutGerer,
            'peutDepenser' => Acces::a($uid, 'finance.operations'),
            'voirDepenses' => Acces::a($uid, 'finance.voir'),
            'voirMarges' => $voirMarges,
            'devises' => VenteController::devises(),
            'locale' => $this->evenements->rapports()->locale(),
            'reference' => $this->evenements->rapports()->reference(),
            'taux' => $this->evenements->rapports()->tauxLocal(),
        ], 'gestion');
    }

    private function afficherFormulaire(array $utilisateur, ?array $ev, array $valeurs, array $erreurs = [], int $code = 200): void
    {
        http_response_code($code);
        View::afficher('gestion/evenements/formulaire', [
            'titre' => $ev ? t('evenements.modifier_titre', ['titre' => $ev['titre']]) : t('evenements.nouveau'),
            'utilisateur' => $utilisateur,
            'ev' => $ev,
            'valeurs' => $valeurs,
            'erreurs' => $erreurs,
            'etablissements' => $this->evenements->etablissements((int) $utilisateur['id'], 'evenements.gerer'),
            'devises' => (new DeviseRepository())->actives(),
        ], 'gestion');
    }

    /** @return array{0: array, 1: array, 2: array<string, string>} valeurs saisies, valeurs propres, erreurs */
    private function lire(int $uid): array
    {
        $valeurs = [
            'etablissement_id' => TresorerieSaisie::post('etablissement_id'),
            'titre' => mb_substr(TresorerieSaisie::post('titre'), 0, 200),
            'theme' => mb_substr(TresorerieSaisie::post('theme'), 0, 400),
            'artistes' => mb_substr(TresorerieSaisie::post('artistes', true), 0, 800),
            'description' => mb_substr(TresorerieSaisie::post('description', true), 0, 6000),
            'debut' => TresorerieSaisie::post('debut'),
            'fin' => TresorerieSaisie::post('fin'),
            'budget' => TresorerieSaisie::post('budget'),
            'budget_devise_id' => TresorerieSaisie::post('budget_devise_id'),
        ];
        $erreurs = [];
        $etablissements = array_column($this->evenements->etablissements($uid, 'evenements.gerer'), null, 'id');
        $etab = TresorerieSaisie::id($valeurs['etablissement_id']);
        if ($etab === null || !isset($etablissements[$etab])) {
            $erreurs['etablissement_id'] = t('evenements.erreur_etablissement');
        }
        if ($valeurs['titre'] === '') {
            $erreurs['titre'] = t('evenements.erreur_titre');
        } elseif (mb_strlen($valeurs['titre']) > 128) {
            $erreurs['titre'] = t('tresorerie.erreur_texte_long', ['n' => 128]);
        }
        if (mb_strlen($valeurs['theme']) > 255) {
            $erreurs['theme'] = t('tresorerie.erreur_texte_long', ['n' => 255]);
        }
        if (mb_strlen($valeurs['artistes']) > 500) {
            $erreurs['artistes'] = t('tresorerie.erreur_texte_long', ['n' => 500]);
        }
        if (mb_strlen($valeurs['description']) > 5000) {
            $erreurs['description'] = t('tresorerie.erreur_texte_long', ['n' => 5000]);
        }
        $debut = TresorerieSaisie::dateHeure($valeurs['debut']);
        $fin = TresorerieSaisie::dateHeure($valeurs['fin']);
        if ($debut === null || $debut->format('Y') < '2000') {
            $erreurs['debut'] = t('evenements.erreur_debut');
        }
        if ($fin === null) {
            $erreurs['fin'] = t('evenements.erreur_fin');
        } elseif ($debut !== null && $fin <= $debut) {
            $erreurs['fin'] = t('evenements.erreur_fin_avant');
        } elseif ($debut !== null && $fin > $debut->modify('+48 hours')) {
            $erreurs['fin'] = t('evenements.erreur_duree');
        }
        $budget = null;
        $deviseId = null;
        if ($valeurs['budget'] !== '') {
            $devises = (new DeviseRepository())->actives();
            $deviseId = TresorerieSaisie::id($valeurs['budget_devise_id']);
            if ($deviseId === null || !isset($devises[$deviseId])) {
                $erreurs['budget'] = t('evenements.erreur_devise');
            } else {
                [$budget, $erreur] = TresorerieSaisie::montant($valeurs['budget'], $devises[$deviseId], true);
                if ($erreur !== null) {
                    $erreurs['budget'] = $erreur;
                }
            }
        }
        $propres = $erreurs !== [] ? [] : [
            'etablissement_id' => $etab,
            'titre' => $valeurs['titre'],
            'theme' => $valeurs['theme'] !== '' ? $valeurs['theme'] : null,
            'artistes' => $valeurs['artistes'] !== '' ? $valeurs['artistes'] : null,
            'description' => $valeurs['description'] !== '' ? $valeurs['description'] : null,
            'debut' => $debut->format('Y-m-d H:i:00'),
            'fin' => $fin->format('Y-m-d H:i:00'),
            'budget' => $budget,
            'budget_devise_id' => $deviseId,
        ];
        return [$valeurs, $propres, $erreurs];
    }

    // ---------- Exports ----------

    private function csvListe(array $soirees, bool $voirMarges, string $nom = 'evenements', ?array $periode = null): never
    {
        $repo = $this->evenements->rapports();
        $l = $repo->locale();
        $r = $repo->reference();
        $entetes = [t('evenements.col_date'), t('evenements.col_debut'), t('evenements.col_fin'), t('gestion.etablissement'), t('evenements.titre_champ'),
            t('evenements.theme'), t('evenements.artistes'), t('evenements.col_statut'), t('rapports.col_tickets'),
            t('rapports.csv_ca', ['code' => $l['code'] ?? '']), t('rapports.csv_ca', ['code' => $r['code'] ?? '']),
            t('evenements.csv_couts', ['code' => $r['code'] ?? ''])];
        if ($voirMarges) {
            $entetes[] = t('rapports.csv_cout', ['code' => $r['code'] ?? '']);
        }
        $entetes[] = t('evenements.csv_resultat', ['code' => $r['code'] ?? '']);
        $entetes[] = t('evenements.csv_budget');
        $entetes[] = t('rapports.csv_devise');
        $lignes = [];
        $devises = VenteController::devises();
        foreach ($soirees as $ev) {
            $b = $ev['bilan'] ?? null;
            $devise = $ev['budget_devise_id'] !== null ? ($devises[(int) $ev['budget_devise_id']] ?? null) : null;
            $ligne = [Csv::date($ev['debut'], false), Csv::date($ev['debut']), Csv::date($ev['fin']), $ev['etablissement_nom'], $ev['titre'], (string) $ev['theme'],
                str_replace("\n", ', ', (string) $ev['artistes']), t('evenements.statut_' . self::etat($ev)),
                $b['totaux']['tickets'] ?? '', $b !== null ? Csv::montant($b['totaux']['ca_locale'], $l) : '', $b !== null ? Csv::montant($b['totaux']['ca_reference'], $r) : '',
                $b !== null ? Csv::montant($b['couts'], $r) : ''];
            if ($voirMarges) {
                $ligne[] = $b !== null ? Csv::montant($b['marchandises'], $r) : '';
            }
            $ligne[] = $b !== null ? Csv::montant($b['resultat'], $r) : '';
            $ligne[] = $ev['budget'] !== null ? Csv::montant($ev['budget'], $devise) : '';
            $ligne[] = $devise['code'] ?? '';
            $lignes[] = $ligne;
        }
        Csv::envoyer(Csv::nomFichier($nom, $periode !== null && $periode['du'] !== null ? $periode : null), $entetes, $lignes);
    }

    /** Fiche en CSV : recettes par catégorie, puis chaque coût, puis le résultat. */
    private function csvFiche(array $ev, array $bilan): never
    {
        $repo = $this->evenements->rapports();
        $l = $repo->locale();
        $r = $repo->reference();
        $devises = VenteController::devises();
        $entetes = [t('evenements.csv_section'), t('evenements.csv_libelle'), t('evenements.col_date'), t('rapports.csv_montant'), t('rapports.csv_devise'),
            t('evenements.csv_equivalent', ['code' => $r['code'] ?? ''])];
        $lignes = [];
        foreach ($bilan['categories'] as $c) {
            $lignes[] = [t('evenements.recettes'), $c['nom'], '', Csv::montant($c['locale'], $l), $l['code'] ?? '', Csv::montant($c['reference'], $r)];
        }
        $lignes[] = [t('evenements.recettes'), t('rapports.total'), '', Csv::montant($bilan['totaux']['ca_locale'], $l), $l['code'] ?? '', Csv::montant($bilan['totaux']['ca_reference'], $r)];
        foreach ($bilan['depenses'] as $d) {
            foreach ($d['paiements'] as $p) {
                $devise = $devises[(int) $p['devise_id']] ?? null;
                $lignes[] = [t('evenements.couts'), $d['libelle'] . ' (' . champ($d, 'categorie_nom') . ')', Csv::date($d['date_depense'], false),
                    Csv::montant($p['montant'], $devise), $devise['code'] ?? '', Csv::montant($repo->enReference((float) $p['montant'], (int) $p['devise_id'], $p['contre_valeur'], $p['reference_id']), $r)];
            }
        }
        if ($bilan['marchandises'] > 0) {
            $lignes[] = [t('evenements.couts'), t('evenements.marchandises'), '', '', '', Csv::montant($bilan['marchandises'], $r)];
        }
        $lignes[] = [t('evenements.resultat'), $ev['titre'], Csv::date($ev['debut'], false), '', '', Csv::montant($bilan['resultat'], $r)];
        Csv::envoyer(Csv::nomFichier('soiree-' . $ev['titre'] . '-' . substr($ev['debut'], 0, 10)), $entetes, $lignes);
    }

    // ---------- Outils ----------

    /** « a_venir », « en_cours », « passe » ou « annule ». */
    public static function etat(array $ev, ?string $maintenant = null): string
    {
        $maintenant ??= date('Y-m-d H:i:s');
        if ($ev['statut'] === 'annule') {
            return 'annule';
        }
        if ($ev['fin'] < $maintenant) {
            return 'passe';
        }
        return $ev['debut'] <= $maintenant ? 'en_cours' : 'a_venir';
    }

    private function voirMarges(int $uid): bool
    {
        return Acces::a($uid, 'rapports.marges') && RapportRepository::coutsDisponibles();
    }

    private function etablissementChoisi(array $etablissements): ?int
    {
        $id = TresorerieSaisie::id($_GET['etablissement'] ?? null);
        return $id !== null && in_array($id, array_map('intval', array_column($etablissements, 'id')), true) ? $id : null;
    }

    private function evenementOu404(string $id, array $utilisateur): array
    {
        $ev = ctype_digit($id) && strlen($id) < 11 ? $this->evenements->evenement((int) $id, (int) $utilisateur['id']) : null;
        if ($ev === null) {
            pageIntrouvable();
        }
        return $ev;
    }

    private function exigerGerer(array $utilisateur, array $ev): void
    {
        if (!Acces::a((int) $utilisateur['id'], 'evenements.gerer', (int) $ev['etablissement_id'])) {
            DetteAffichage::interdit($utilisateur);
        }
    }
}
