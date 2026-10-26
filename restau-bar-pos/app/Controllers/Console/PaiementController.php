<?php

declare(strict_types=1);

namespace App\Controllers\Console;

use App\Console\Abonnement;
use App\Console\Clients;
use App\Console\Journal;
use App\Console\SessionConsole;

/**
 * Paiements notés à la main (mobile money, virement, espèces, avec leur référence) : chacun prolonge l'échéance
 * d'une ou plusieurs périodes et sort le client de l'essai ; le dernier paiement saisi par erreur s'annule.
 */
final class PaiementController extends Base
{
    public function enregistrer(string $id): void
    {
        $admin = $this->poste();
        $client = ctype_digit($id) ? Clients::un((int) $id) : null;
        if ($client === null) {
            pageIntrouvable();
        }
        $v = [
            'montant' => str_replace([',', ' '], ['.', ''], self::texte('montant', 16)),
            'devise' => self::texte('devise', 3), 'moyen' => self::texte('moyen', 20), 'reference' => self::texte('reference', 64),
            'paye_le' => self::date('paye_le'), 'periode' => self::texte('periode', 10), 'periodes' => self::texte('periodes', 2),
            'echeance' => self::texte('echeance', 10) === '' ? null : self::date('echeance'), 'note' => self::texte('note', 255),
        ];
        $erreurs = [];
        if (!is_numeric($v['montant']) || (float) $v['montant'] <= 0 || (float) $v['montant'] > 99999999) {
            $erreurs['montant'] = t('console.paiement.erreur_montant');
        }
        if (!in_array($v['devise'], Clients::DEVISES, true)) {
            $erreurs['devise'] = t('console.paiement.erreur_devise');
        }
        if (!in_array($v['moyen'], Clients::MOYENS, true)) {
            $erreurs['moyen'] = t('console.paiement.erreur_moyen');
        }
        if ($v['moyen'] !== 'especes' && $v['reference'] === '') {
            $erreurs['reference'] = t('console.paiement.erreur_reference');
        }
        if ($v['paye_le'] === null || $v['paye_le'] > date('Y-m-d')) {
            $erreurs['paye_le'] = t('console.paiement.erreur_date');
        }
        if (!isset(Abonnement::PERIODES[$v['periode']])) {
            $erreurs['periode'] = t('console.client.erreur_periode');
        }
        if (!ctype_digit($v['periodes']) || (int) $v['periodes'] < 1 || (int) $v['periodes'] > 24) {
            $erreurs['periodes'] = t('console.paiement.erreur_periodes');
        }
        if (self::texte('echeance', 10) !== '' && $v['echeance'] === null) {
            $erreurs['echeance'] = t('console.erreur_date');
        }
        if ($erreurs !== []) {
            (new ClientController())->ficheAvecErreurs($client, ['paiement' => $erreurs], ['paiement' => $v]);
            return;
        }
        $resultat = Clients::enregistrerPaiement($client, $v, (int) $admin['id']);
        Journal::ecrire('paiement', (int) $client['id'], [
            'montant' => $v['montant'], 'devise' => $v['devise'], 'moyen' => $v['moyen'], 'reference' => $v['reference'],
            'echeance_avant' => $resultat['echeance_avant'], 'echeance_apres' => $resultat['echeance_apres'],
        ], (int) $admin['id']);
        SessionConsole::flash(t('console.paiement.enregistre_flash', ['date' => self::jour($resultat['echeance_apres'])]));
        rediriger('/clients/' . $client['id'] . '#paiements');
    }

    public function annuler(string $id, string $paiement): void
    {
        $admin = $this->poste();
        $motif = self::texte('motif', 255);
        if (!ctype_digit($id) || !ctype_digit($paiement)) {
            pageIntrouvable();
        }
        if ($motif === '') {
            SessionConsole::flash(t('console.paiement.erreur_motif'), 'erreur');
        } elseif (Clients::annulerPaiement((int) $id, (int) $paiement, $motif, (int) $admin['id'])) {
            Journal::ecrire('paiement_annule', (int) $id, ['paiement' => (int) $paiement, 'motif' => $motif], (int) $admin['id']);
            SessionConsole::flash(t('console.paiement.annule_flash'));
        } else {
            SessionConsole::flash(t('console.paiement.erreur_annulation'), 'erreur');
        }
        rediriger('/clients/' . (int) $id . '#paiements');
    }

    public static function jour(?string $date): string
    {
        if ($date === null) {
            return '—';
        }
        return \App\Core\I18n::langue() === 'fr' ? date('d/m/Y', strtotime($date)) : date('M j, Y', strtotime($date));
    }
}
