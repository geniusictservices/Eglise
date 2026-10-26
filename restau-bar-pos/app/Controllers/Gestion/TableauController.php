<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Csv;
use App\Core\Database;
use App\Core\Journee;
use App\Core\Session;
use App\Core\View;
use App\Repositories\AchatRepository;
use App\Repositories\DeviseRepository;
use App\Repositories\EtablissementRepository;
use App\Repositories\GestionDetteRepository;
use App\Repositories\RapportRepository;

/**
 * Tableau de bord de la direction (cahier des charges, 13.6), Rapports > Tableau de bord (rapports.voir) :
 * sur une période de journées d'exploitation et un établissement (ou tous) — chiffre d'affaires, encaissé et part à
 * crédit, marge (rapports.marges, si le stock connaît les coûts), dépenses, salaires, résultat, comparés à la période
 * précédente ; le CA jour par jour, par service et par établissement ; les produits les plus rentables et les moins
 * vendus ; les créances clients (ancienneté) et les dettes fournisseurs ; la serveuse ou le serveur de la semaine ;
 * la présence du personnel. Export CSV (une ligne par journée) et impression.
 */
final class TableauController
{
    public const PERIODES = ['jour', '7j', 'mois', 'mois_precedent', 'annee'];

    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $uid = (int) $utilisateur['id'];
        $devisesRepo = new DeviseRepository();
        $repo = new RapportRepository($devisesRepo);
        $locale = $repo->locale();
        $reference = $repo->reference();

        $etablissements = RapportRepository::etablissements($uid, 'rapports.voir');
        $etablissement = TresorerieSaisie::id($_GET['etablissement'] ?? null);
        $etab = null;
        foreach ($etablissements as $e) {
            if ((int) $e['id'] === $etablissement) {
                $etab = $e;
            }
        }
        if ($etab === null) {
            $etablissement = null;
        }

        // Période : « aujourd'hui » est la journée d'exploitation en cours (à 2 h du matin, c'est encore hier)
        $periode = TresorerieSaisie::periode('7j');
        if (!in_array($periode['cle'], array_merge(self::PERIODES, ['perso']), true)) {
            $periode = TresorerieSaisie::periode('7j');
            $periode['cle'] = '7j';
        }
        if ($periode['cle'] === 'jour') {
            $jour = class_exists(Journee::class) ? Journee::aujourdhui($etab) : date('Y-m-d');
            $periode['du'] = $periode['au'] = $jour;
        }
        $bornes = RapportRepository::bornes($periode);
        $precedente = RapportRepository::precedente($bornes);
        $filtre = ['du' => $bornes['du'], 'au' => $bornes['au'], 'etablissement' => $etablissement];

        $ventes = $repo->ventes($filtre, $uid);
        $ventesAvant = $repo->ventes(['du' => $precedente['du'], 'au' => $precedente['au'], 'etablissement' => $etablissement], $uid);
        $totaux = RapportRepository::totaux($ventes);
        $totauxAvant = RapportRepository::totaux($ventesAvant);

        $depenses = $repo->depenses($bornes['du'], $bornes['au'], $etablissement, $uid);
        $depensesAvant = $repo->depenses($precedente['du'], $precedente['au'], $etablissement, $uid);
        $salaires = $repo->salaires($bornes['du'], $bornes['au'], $etablissement, $uid);
        $salairesAvant = $repo->salaires($precedente['du'], $precedente['au'], $etablissement, $uid);

        $voirMarges = Acces::a($uid, 'rapports.marges') && RapportRepository::coutsDisponibles();
        $resultat = static function (array $t, array $d, ?array $s) use ($voirMarges): float {
            return ($voirMarges ? $t['marge_reference'] : $t['ca_reference']) - $d['reference'] - ($s['reference'] ?? 0.0);
        };

        // Par journée (toutes les journées de la période, même sans vente), par service, par établissement
        $parJour = RapportRepository::grouper($ventes, static fn (array $v) => $v['journee']);
        $jours = [];
        foreach (RapportRepository::journees($bornes['du'], $bornes['au']) as $j) {
            $jours[$j] = $parJour[$j] ?? RapportRepository::completer(RapportRepository::vide());
        }
        $servicesVente = $repo->services($ventes);
        $parService = RapportRepository::grouper($ventes, static fn (array $v) => $servicesVente[$v['id']] ?? '');
        uasort($parService, static fn (array $a, array $b) => $b['ca_locale'] <=> $a['ca_locale']);
        $parEtablissement = RapportRepository::grouper($ventes, static fn (array $v) => $v['etablissement_id']);

        // Produits
        $produits = $repo->produits($ventes);
        $rentables = array_values($produits);
        $avecMarge = $voirMarges && array_filter($rentables, static fn (array $p) => $p['marge'] !== null) !== [];
        usort($rentables, $avecMarge
            ? static fn (array $a, array $b) => [$b['marge'] ?? -INF, $b['locale']] <=> [$a['marge'] ?? -INF, $a['locale']]
            : static fn (array $a, array $b) => $b['locale'] <=> $a['locale']);
        $rentables = array_slice($rentables, 0, 8);
        $moinsVendus = $repo->moinsVendus($produits, $etablissement);

        // Créances clients et dettes fournisseurs (si le module Achats est là et la personne le voit)
        $creances = (new GestionDetteRepository())->rapportCreances($uid, $etablissement);
        $fournisseurs = null;
        if (Acces::a($uid, 'achats.voir') && RapportRepository::tableExiste('receptions') && class_exists(AchatRepository::class)) {
            try {
                $portee = Acces::etablissements($uid, 'achats.voir');
                if ($etablissement !== null) {
                    $portee = [$etablissement];
                }
                $fournisseurs = ['devises' => [], 'reference' => 0.0, 'en_retard' => 0, 'nombre' => 0];
                foreach ((new AchatRepository())->dettes($portee) as $f) {
                    $fournisseurs['nombre']++;
                    $fournisseurs['en_retard'] += (int) $f['en_retard'];
                    foreach ($f['par_devise'] as $did => $m) {
                        $fournisseurs['devises'][$did] = ($fournisseurs['devises'][$did] ?? 0.0) + $m;
                        $fournisseurs['reference'] += $repo->enReference((float) $m, (int) $did);
                    }
                }
            } catch (\Throwable) {
                $fournisseurs = null;
            }
        }

        // Serveuse ou serveur de la semaine : les 7 dernières journées (aujourd'hui compris) contre les 7 d'avant,
        // pour comparer deux semaines entières quel que soit le jour
        $aujourdhui = class_exists(Journee::class) ? Journee::aujourdhui($etab) : date('Y-m-d');
        $debutSemaine = (new \DateTimeImmutable($aujourdhui))->modify('-6 days');
        $semaine = ['du' => $debutSemaine->format('Y-m-d'), 'au' => $aujourdhui];
        $semaineAvant = ['du' => $debutSemaine->modify('-7 days')->format('Y-m-d'), 'au' => $debutSemaine->modify('-1 day')->format('Y-m-d')];
        $classementSemaine = RapportRepository::classement($repo->ventes($semaine + ['etablissement' => $etablissement], $uid), 'ca', false);
        $classementAvant = RapportRepository::classement($repo->ventes($semaineAvant + ['etablissement' => $etablissement], $uid), 'ca', false);
        $vedette = null;
        foreach ($classementSemaine as $ligne) {
            if ($ligne['utilisateur_id'] !== null && $ligne['tickets'] > 0) {
                $vedette = $ligne;
                break;
            }
        }
        if ($vedette !== null) {
            $vedette['personne'] = RapportRepository::personnes([$vedette['utilisateur_id']])[$vedette['utilisateur_id']] ?? null;
            $vedette['avant'] = 0.0;
            $vedette['rang_avant'] = null;
            foreach ($classementAvant as $ligne) {
                if ($ligne['utilisateur_id'] === $vedette['utilisateur_id']) {
                    $vedette['avant'] = $ligne['retenu_locale'];
                    $vedette['rang_avant'] = $ligne['rang'];
                }
            }
        }

        $nomsEtablissements = array_column(Database::tous('SELECT id, nom FROM etablissements'), 'nom', 'id');

        if (Csv::demande()) {
            $this->csv($jours, $locale, $reference, $periode, $voirMarges);
        }

        View::afficher('gestion/tableau', [
            'titre' => t('gestion.tableau'),
            'utilisateur' => $utilisateur,
            'periode' => $periode,
            'periodes' => self::PERIODES,
            'bornes' => $bornes,
            'precedente' => $precedente,
            'etablissement' => $etablissement,
            'etablissements' => $etablissements,
            'nomsEtablissements' => $nomsEtablissements,
            'locale' => $locale,
            'reference' => $reference,
            'taux' => $repo->tauxLocal(),
            'totaux' => $totaux,
            'totauxAvant' => $totauxAvant,
            'depenses' => $depenses,
            'depensesAvant' => $depensesAvant,
            'salaires' => $salaires,
            'salairesAvant' => $salairesAvant,
            'resultat' => $resultat($totaux, $depenses, $salaires),
            'resultatAvant' => $resultat($totauxAvant, $depensesAvant, $salairesAvant),
            'voirMarges' => $voirMarges,
            'margesPermises' => Acces::a($uid, 'rapports.marges'),
            'jours' => $jours,
            'parService' => $parService,
            'parEtablissement' => $parEtablissement,
            'rentables' => $rentables,
            'avecMarge' => $avecMarge,
            'moinsVendus' => $moinsVendus,
            'creances' => $creances,
            'fournisseurs' => $fournisseurs,
            'vedette' => $vedette,
            'semaine' => $semaine,
            'presence' => $repo->presence($bornes['du'], $bornes['au'], $etablissement, $uid),
            'devises' => VenteController::devises(),
            'liens' => [
                'creances' => Acces::a($uid, 'rapports.voir'),
                'depenses' => Acces::a($uid, 'finance.voir'),
                'fournisseurs' => Acces::a($uid, 'achats.voir'),
                'presence' => Acces::a($uid, 'personnel.voir'),
            ],
            // Le tableau d'origine (établissements, produits, scans, taux) reste en bas de la page
            'nbProduits' => (int) Database::un('SELECT COUNT(*) AS n FROM produits WHERE actif = 1 AND parent_id IS NULL')['n'],
            'scans7j' => (int) Database::un('SELECT COALESCE(SUM(nombre), 0) AS n FROM qr_scans WHERE jour >= ?', [date('Y-m-d', strtotime('-6 days'))])['n'],
            'tousEtablissements' => (new EtablissementRepository())->actifs(),
            'devisesActives' => $devisesRepo->actives(),
        ], 'gestion');
    }

    /** Une ligne par journée d'exploitation : tickets, CA, encaissé, part à crédit, articles, annulations, marge. */
    private function csv(array $jours, ?array $locale, ?array $reference, array $periode, bool $voirMarges): never
    {
        $codeL = $locale['code'] ?? '';
        $codeR = $reference['code'] ?? '';
        $entetes = [t('rapports.col_jour'), t('rapports.col_tickets'), t('rapports.col_articles'),
            t('rapports.csv_ca', ['code' => $codeL]), t('rapports.csv_ca', ['code' => $codeR]),
            t('rapports.csv_encaisse', ['code' => $codeL]), t('rapports.csv_credit', ['code' => $codeL]),
            t('rapports.csv_panier', ['code' => $codeL]), t('rapports.col_annulees')];
        if ($voirMarges) {
            $entetes[] = t('rapports.csv_cout', ['code' => $codeR]);
            $entetes[] = t('rapports.csv_marge', ['code' => $codeR]);
        }
        $lignes = [];
        $total = RapportRepository::vide();
        foreach ($jours as $jour => $t) {
            foreach (['tickets', 'articles', 'ca_locale', 'ca_reference', 'credit_locale', 'credit_reference', 'cout', 'annulees', 'annulees_locale', 'rembourse_locale', 'rembourse_reference', 'encaisse_reference', 'ca_couvert'] as $cle) {
                $total[$cle] += $t[$cle];
            }
            $lignes[] = $this->ligneCsv((string) $jour, $t, $locale, $reference, $voirMarges);
        }
        $lignes[] = $this->ligneCsv(t('rapports.total'), RapportRepository::completer($total), $locale, $reference, $voirMarges);
        Csv::envoyer(Csv::nomFichier('tableau-de-bord', $periode['du'] !== null ? $periode : null), $entetes, $lignes);
    }

    private function ligneCsv(string $libelle, array $t, ?array $locale, ?array $reference, bool $voirMarges): array
    {
        $ligne = [$libelle, $t['tickets'], Csv::nombre($t['articles'], 0), Csv::montant($t['ca_locale'], $locale), Csv::montant($t['ca_reference'], $reference),
            Csv::montant($t['encaisse_locale'], $locale), Csv::montant($t['credit_locale'], $locale), Csv::montant($t['panier_locale'], $locale), $t['annulees']];
        if ($voirMarges) {
            $ligne[] = Csv::montant($t['cout'], $reference);
            $ligne[] = Csv::montant($t['marge_reference'], $reference);
        }
        return $ligne;
    }
}
