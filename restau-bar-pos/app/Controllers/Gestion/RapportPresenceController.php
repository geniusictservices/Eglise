<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\View;
use App\Repositories\PaieRepository;
use App\Repositories\PersonnelRepository;
use App\Repositories\PlanningRepository;

/**
 * Rapports > Présence (module Personnel et paie) : présence de la période par employé (heures planifiées et
 * travaillées, retards, absences, départs anticipés, heures sup), export CSV ; masse salariale par établissement
 * et par mois pour qui a « personnel.paie ». Le classement des vendeurs est dans Rapports > Personnel.
 */
final class RapportPresenceController
{
    public function index(): void
    {
        $utilisateur = PersonnelAcces::connecte();
        $uid = (int) $utilisateur['id'];
        $periode = TresorerieSaisie::periode('mois');
        if ($periode['cle'] === 'tout') {
            $periode = TresorerieSaisie::periode('annee');
        }
        $du = (string) $periode['du'];
        $au = (string) $periode['au'];
        $etablissements = PersonnelAcces::etablissements($uid, 'rapports.voir');
        $etab = PersonnelAcces::etablissementChoisi($etablissements, $_GET['etablissement'] ?? null, true);
        $portee = $etab ? [(int) $etab['id']] : PersonnelAcces::portee($uid, 'rapports.voir');
        $employes = (new PersonnelRepository())->liste($portee, ['statut' => 'tous']);
        $bilan = (new PlanningRepository())->bilan(array_map(static fn ($e) => (int) $e['id'], $employes), $du, $au, $etab ? (int) $etab['id'] : null);
        $lignes = [];
        $totaux = [];
        foreach ($employes as $e) {
            $t = $bilan[(int) $e['id']]['totaux'] ?? null;
            if ($t === null || ($t['creneaux'] === 0 && $t['jours_travailles'] === 0)) {
                continue;
            }
            $lignes[] = ['employe' => $e, 'totaux' => $t];
            foreach ($t as $cle => $valeur) {
                $totaux[$cle] = ($totaux[$cle] ?? 0) + $valeur;
            }
        }
        usort($lignes, static fn ($a, $b) => $b['totaux']['minutes_travaillees'] <=> $a['totaux']['minutes_travaillees']);

        if (($_GET['format'] ?? '') === 'csv') {
            $this->csv($lignes, $du, $au);
            return;
        }
        $porteePaie = PersonnelAcces::portee($uid, 'personnel.paie');
        $voirPaie = Acces::a($uid, 'personnel.paie') && ($etab === null || $porteePaie === null || in_array((int) $etab['id'], $porteePaie, true));
        View::afficher('gestion/personnel/rapport', [
            'titre' => t('pers.rapport_titre'),
            'utilisateur' => $utilisateur,
            'periode' => $periode,
            'etablissements' => $etablissements,
            'etab' => $etab,
            'lignes' => $lignes,
            'totaux' => $totaux,
            'voirPaie' => $voirPaie,
            'masse' => $voirPaie ? (new PaieRepository())->masseSalariale($porteePaie, $du, $au, $etab ? (int) $etab['id'] : null) : [],
        ], 'gestion');
    }

    private function csv(array $lignes, string $du, string $au): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="presence-' . $du . '-' . $au . '.csv"');
        $sortie = fopen('php://output', 'w');
        fwrite($sortie, "\xEF\xBB\xBF");
        fputcsv($sortie, [t('pers.col_employe'), t('pers.col_poste'), t('gestion.etablissement'), t('pers.col_creneaux'), t('pers.col_jours_travailles'),
            t('pers.col_heures_planifiees'), t('pers.col_heures_travaillees'), t('pers.col_retards'), t('pers.col_minutes_retard'),
            t('pers.col_absences'), t('pers.col_justifiees'), t('pers.col_departs_anticipes'), t('pers.col_heures_sup'), t('pers.col_heures_ferie')], ';', '"', '');
        foreach ($lignes as $l) {
            $t = $l['totaux'];
            fputcsv($sortie, [$l['employe']['nom'], (string) $l['employe']['poste'], $l['employe']['etablissement_nom'], $t['creneaux'], $t['jours_travailles'],
                round($t['minutes_planifiees'] / 60, 2), round($t['minutes_travaillees'] / 60, 2), $t['retards'], $t['minutes_retard'],
                $t['absences'] + $t['en_attente'], $t['absences_justifiees'], $t['departs_anticipes'], round($t['minutes_sup'] / 60, 2), round($t['minutes_ferie'] / 60, 2)], ';', '"', '');
        }
        fclose($sortie);
    }
}
