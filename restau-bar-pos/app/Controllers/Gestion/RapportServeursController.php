<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Csv;
use App\Core\Session;
use App\Core\View;
use App\Repositories\GestionDetteRepository;
use App\Repositories\RapportRepository;

/**
 * Rapports > Personnel (cahier des charges, 13.7) : serveuse ou serveur de la période et dettes des clients liées
 * aux utilisateurs.
 *
 * Classement de toutes les personnes qui ont vendu sur la période (journées d'exploitation, établissement) :
 * chiffre d'affaires, tickets, panier moyen, articles, crédits accordés, annulations, tables servies (si la salle le
 * sait) ; critère choisi (CA encaissé par défaut, tickets, panier moyen) ; option « une vente à crédit ne compte
 * qu'une fois remboursée » ; comparaison avec la période précédente et évolution jour par jour.
 * Dettes : par responsable, le reste dû, les clients, le retard, l'ancienneté et le remboursé de la période ; les
 * dettes du personnel lui-même à part.
 *
 * Chacun voit ses propres résultats (écran ouvert à toute personne qui a la gestion) ; le classement complet et
 * les dettes de tous demandent rapports.voir, dans les établissements de sa portée.
 */
final class RapportServeursController
{
    public const PERIODES = ['jour', '7j', 'mois', 'mois_precedent', 'annee'];
    public const CRITERES = ['ca', 'tickets', 'panier'];

    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $uid = (int) $utilisateur['id'];
        $toutVoir = Acces::a($uid, 'rapports.voir');
        $repo = new RapportRepository();
        $locale = $repo->locale();
        $reference = $repo->reference();

        $etablissements = $toutVoir ? RapportRepository::etablissements($uid, 'rapports.voir') : [];
        $etablissement = TresorerieSaisie::id($_GET['etablissement'] ?? null);
        if ($etablissement !== null && !in_array($etablissement, array_map('intval', array_column($etablissements, 'id')), true)) {
            $etablissement = null;
        }
        $periode = TresorerieSaisie::periode('7j');
        if (!in_array($periode['cle'], array_merge(self::PERIODES, ['perso']), true)) {
            $periode = TresorerieSaisie::periode('7j');
            $periode['cle'] = '7j';
        }
        $bornes = RapportRepository::bornes($periode);
        $precedente = RapportRepository::precedente($bornes);
        $critere = in_array($_GET['critere'] ?? null, self::CRITERES, true) ? $_GET['critere'] : 'ca';
        $creditRembourse = ($_GET['credit_rembourse'] ?? '') === '1';
        $enSalle = RapportRepository::serviceEnSalle();
        $attribution = $enSalle && ($_GET['attribution'] ?? '') === 'caisse' ? 'caisse' : 'serveur';

        // Sans rapports.voir : seulement ses propres ventes, où qu'elles soient
        $base = ['etablissement' => $etablissement, 'attribution' => $attribution, 'vendeur' => $toutVoir ? null : $uid];
        $ventes = $repo->ventes(['du' => $bornes['du'], 'au' => $bornes['au']] + $base, $uid, $toutVoir);
        $ventesAvant = $repo->ventes(['du' => $precedente['du'], 'au' => $precedente['au']] + $base, $uid, $toutVoir);
        $classement = RapportRepository::classement($ventes, $critere, $creditRembourse);
        $avant = [];
        foreach (RapportRepository::classement($ventesAvant, $critere, $creditRembourse) as $ligne) {
            $avant[(int) $ligne['utilisateur_id']] = $ligne;
        }
        $credits = $repo->creditsAccordes(['du' => $bornes['du'], 'au' => $bornes['au'], 'etablissement' => $etablissement], $uid, $toutVoir);
        $tables = $repo->tablesServies($ventes);
        // Colonne « Tables servies » seulement si la salle a servi des tables sur la période
        if ($tables !== null && array_sum($tables) === 0) {
            $tables = null;
        }

        // Évolution jour par jour (CA retenu) de chaque personne
        $journees = RapportRepository::journees($bornes['du'], $bornes['au']);
        $evolution = [];
        foreach ($ventes as $v) {
            if ($v['statut'] !== 'payee') {
                continue;
            }
            $retenu = $v['total_locale'] * ($creditRembourse ? 1 - $v['credit'] + $v['rembourse'] : 1);
            $id = (int) ($v['vendeur_id'] ?? 0);
            $evolution[$id][$v['journee']] = ($evolution[$id][$v['journee']] ?? 0.0) + $retenu;
        }

        // Personne détaillée : celle choisie (rapports.voir), sinon soi-même
        $choisi = $toutVoir ? TresorerieSaisie::id($_GET['utilisateur'] ?? null) : $uid;
        $ids = array_merge(array_map(static fn (array $l) => (int) $l['utilisateur_id'], $classement), array_keys($credits), [$uid]);

        // Dettes des clients liées aux utilisateurs
        $deviseDettes = TresorerieSaisie::id($_GET['devise'] ?? null);
        $devises = VenteController::devises();
        if ($deviseDettes !== null && !isset($devises[$deviseDettes])) {
            $deviseDettes = null;
        }
        $filtreDettes = [
            'du' => $bornes['du'], 'au' => $bornes['au'], 'etablissement' => $etablissement,
            'devise' => $deviseDettes, 'retard' => ($_GET['retard'] ?? '') === '1',
        ];
        $dettes = $repo->dettesParResponsable($filtreDettes, $uid, $toutVoir);
        $ids = array_merge($ids, array_keys($dettes['clients']), array_keys($dettes['personnel']));
        if ($choisi !== null) {
            $ids[] = $choisi;
        }
        $personnes = RapportRepository::personnes($ids);
        if ($choisi !== null && !isset($personnes[$choisi])) {
            $choisi = null;
        }

        if (Csv::demande()) {
            if (($_GET['tableau'] ?? '') === 'dettes') {
                $this->csvDettes($dettes, $personnes, $devises, $reference, $periode);
            }
            $this->csvClassement($classement, $avant, $credits, $tables, $personnes, $locale, $reference, $periode);
        }

        View::afficher('gestion/rapports/personnel', [
            'titre' => t('rpersonnel.titre'),
            'utilisateur' => $utilisateur,
            'toutVoir' => $toutVoir,
            'periode' => $periode,
            'periodes' => self::PERIODES,
            'bornes' => $bornes,
            'precedente' => $precedente,
            'etablissement' => $etablissement,
            'etablissements' => $etablissements,
            'critere' => $critere,
            'creditRembourse' => $creditRembourse,
            'enSalle' => $enSalle,
            'attribution' => $attribution,
            'classement' => $classement,
            'avant' => $avant,
            'credits' => $credits,
            'tables' => $tables,
            'journees' => $journees,
            'evolution' => $evolution,
            'choisi' => $choisi,
            'personnes' => $personnes,
            'dettes' => $dettes,
            'filtreDettes' => $filtreDettes,
            'devises' => $devises,
            'locale' => $locale,
            'reference' => $reference,
            'moi' => $uid,
            'lienVentes' => Acces::a($uid, 'ventes.voir'),
            'lienDettes' => Acces::a($uid, 'dettes.voir'),
        ], 'gestion');
    }

    private function csvClassement(array $classement, array $avant, array $credits, ?array $tables, array $personnes, ?array $locale, ?array $reference, array $periode): never
    {
        $l = $locale['code'] ?? '';
        $r = $reference['code'] ?? '';
        $entetes = [t('rpersonnel.col_rang'), t('rpersonnel.col_personne'), t('rapports.col_tickets'), t('rapports.col_articles'),
            t('rapports.csv_ca', ['code' => $l]), t('rapports.csv_ca', ['code' => $r]), t('rpersonnel.csv_retenu', ['code' => $l]),
            t('rapports.csv_panier', ['code' => $l]), t('rpersonnel.csv_credit_ventes', ['code' => $l]), t('rpersonnel.csv_non_rembourse', ['code' => $l]),
            t('rpersonnel.csv_credits_accordes'), t('rpersonnel.csv_credits_accordes_ref', ['code' => $r]),
            t('rapports.col_annulees'), t('rpersonnel.csv_annulees_montant', ['code' => $l]), t('rpersonnel.col_tables'),
            t('rpersonnel.csv_precedent', ['code' => $l]), t('rpersonnel.csv_rang_precedent')];
        $lignes = [];
        foreach ($classement as $t) {
            $id = (int) $t['utilisateur_id'];
            $c = $credits[$id] ?? null;
            $p = $avant[$id] ?? null;
            $lignes[] = [$t['rang'], $personnes[$id]['nom'] ?? t('gventes.vendeur_inconnu'), $t['tickets'], Csv::nombre($t['articles'], 0),
                Csv::montant($t['ca_locale'], $locale), Csv::montant($t['ca_reference'], $reference), Csv::montant($t['retenu_locale'], $locale),
                Csv::montant($t['panier_retenu'], $locale), Csv::montant($t['credit_locale'], $locale), Csv::montant($t['non_rembourse_locale'], $locale),
                $c['nombre'] ?? 0, Csv::montant($c['reference'] ?? 0, $reference), $t['annulees'], Csv::montant($t['annulees_locale'], $locale),
                $tables !== null ? ($tables[$id] ?? 0) : '', $p !== null ? Csv::montant($p['retenu_locale'], $locale) : '', $p['rang'] ?? ''];
        }
        Csv::envoyer(Csv::nomFichier('personnel-classement', $periode['du'] !== null ? $periode : null), $entetes, $lignes);
    }

    /** Une ligne par dette ouverte : responsable, client, établissement, dates, reste dans sa devise et en référence. */
    private function csvDettes(array $dettes, array $personnes, array $devises, ?array $reference, array $periode): never
    {
        $entetes = [t('rpersonnel.csv_groupe'), t('rpersonnel.col_responsable'), t('gdettes.col_client'), t('gestion.etablissement'), t('rpersonnel.csv_ticket'),
            t('rpersonnel.csv_date'), t('rpersonnel.csv_echeance'), t('rpersonnel.csv_montant'), t('rpersonnel.csv_reste'), t('rapports.csv_devise'),
            t('rpersonnel.csv_reste_ref', ['code' => $reference['code'] ?? '']), t('rpersonnel.csv_anciennete'), t('rtableau.en_retard')];
        $lignes = [];
        foreach (['clients' => t('rpersonnel.csv_clients'), 'personnel' => t('rpersonnel.csv_personnel')] as $groupe => $libelle) {
            foreach ($dettes[$groupe] as $id => $g) {
                $nom = $groupe === 'personnel' ? ($g['nom'] ?? '') : ($personnes[$id]['nom'] ?? t('rpersonnel.sans_responsable'));
                foreach ($g['dettes'] as $d) {
                    $devise = $devises[(int) $d['devise_id']] ?? null;
                    $lignes[] = [$libelle, $nom, $d['client_nom'], $d['etablissement_nom'], $d['numero'] ?? '', Csv::date($d['cree_le'], false), Csv::date($d['echeance'], false),
                        Csv::montant($d['montant'], $devise), Csv::montant($d['reste'], $devise), $devise['code'] ?? '', Csv::montant($d['reference'], $reference),
                        t('gdettes.tranche_' . $d['tranche']), $d['en_retard'] ? t('rpersonnel.oui') : t('rpersonnel.non')];
                }
            }
        }
        Csv::envoyer(Csv::nomFichier('personnel-dettes', $periode['du'] !== null ? $periode : null), $entetes, $lignes);
    }
}
