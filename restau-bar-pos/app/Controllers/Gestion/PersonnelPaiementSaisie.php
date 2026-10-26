<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Repositories\GestionTresorerieRepository;

/**
 * Lignes de paiement d'un salaire ou d'une avance (même saisie que les dépenses : compte rangé sous son type,
 * une des devises qu'il tient, montant), et découvert signalé avant d'enregistrer.
 */
final class PersonnelPaiementSaisie
{
    public const MAX_LIGNES = 4;
    public const LIGNE_VIDE = ['compte_id' => null, 'devise_id' => null, 'montant' => ''];

    /** Comptes actifs de l'établissement et comptes communs (forme de GestionTresorerieRepository::comptes()). */
    public static function comptes(GestionTresorerieRepository $tresorerie, int $etablissementId): array
    {
        return array_filter($tresorerie->comptesActifs(), static fn (array $c) => $c['etablissement_id'] === null || (int) $c['etablissement_id'] === $etablissementId);
    }

    /**
     * @return array{lignes: list<array>, paiements: list<array{compte: array, devise: array, montant: float}>, erreurs: array<string, string>}
     */
    public static function lire(array $comptes, array $devises): array
    {
        $lignes = [];
        foreach (is_array($_POST['paiements'] ?? null) ? $_POST['paiements'] : [] as $ligne) {
            if (!is_array($ligne) || count($lignes) >= self::MAX_LIGNES) {
                continue;
            }
            $compteId = TresorerieSaisie::id($ligne['compte_id'] ?? null);
            $deviseId = TresorerieSaisie::id($ligne['devise_id'] ?? null);
            if ($deviseId === null && $compteId !== null && isset($comptes[$compteId])) {
                $tenues = array_keys(array_filter($comptes[$compteId]['devises'], static fn (array $d): bool => $d['tenue']));
                $deviseId = count($tenues) === 1 ? $tenues[0] : null;
            }
            $lignes[] = ['compte_id' => $compteId, 'devise_id' => $deviseId, 'montant' => TresorerieSaisie::texte($ligne['montant'] ?? null)];
        }
        $erreurs = [];
        $paiements = [];
        $vus = [];
        foreach ($lignes as $i => $ligne) {
            if ($ligne['compte_id'] === null && $ligne['montant'] === '') {
                continue;
            }
            $numero = ['n' => $i + 1];
            $compte = $ligne['compte_id'] !== null ? ($comptes[$ligne['compte_id']] ?? null) : null;
            if ($compte === null) {
                $erreurs['paiements-' . $i . '-compte'] = t('depenses.erreur_compte', $numero);
                continue;
            }
            if ($ligne['devise_id'] === null || !GestionTresorerieRepository::tient($compte, $ligne['devise_id']) || !isset($devises[$ligne['devise_id']])) {
                $tenues = array_keys(array_filter($compte['devises'], static fn (array $d): bool => $d['tenue']));
                $erreurs['paiements-' . $i . '-devise'] = t('depenses.paiement_n', $numero) . ' : ' . t('tresorerie.erreur_devise_compte', [
                    'compte' => $compte['nom'], 'codes' => implode(', ', array_map(static fn (int $id): string => $devises[$id]['code'] ?? '?', $tenues)),
                ]);
                continue;
            }
            $devise = $devises[$ligne['devise_id']];
            $cle = (int) $compte['id'] . '-' . (int) $devise['id'];
            if (isset($vus[$cle])) {
                $erreurs['paiements-' . $i . '-compte'] = t('depenses.erreur_compte_double', $numero + ['compte' => $compte['nom'], 'code' => $devise['code']]);
                continue;
            }
            [$montant, $erreur] = TresorerieSaisie::montant($ligne['montant'], $devise);
            if ($erreur !== null) {
                $erreurs['paiements-' . $i . '-montant'] = t('depenses.paiement_n', $numero) . ' : ' . $erreur;
                continue;
            }
            $vus[$cle] = true;
            $paiements[] = ['compte' => $compte, 'devise' => $devise, 'montant' => $montant];
        }
        if ($paiements === [] && $erreurs === []) {
            $erreurs['paiements'] = t('depenses.erreur_aucun_paiement');
        }
        return ['lignes' => $lignes ?: [self::LIGNE_VIDE], 'paiements' => $paiements, 'erreurs' => $erreurs];
    }

    /** Ajoute une ligne (sans JavaScript, bouton « Ajouter un paiement »). */
    public static function ajouterLigne(array $lignes): array
    {
        if (count($lignes) < self::MAX_LIGNES) {
            $lignes[] = self::LIGNE_VIDE;
        }
        return $lignes;
    }

    /** @return list<string> découverts (compte, devise) que ces paiements provoqueraient */
    public static function decouverts(array $paiements): array
    {
        $avertissements = [];
        foreach ($paiements as $p) {
            $solde = GestionTresorerieRepository::solde($p['compte'], (int) $p['devise']['id']);
            $apres = $solde - $p['montant'];
            if ($apres < -0.0005) {
                $avertissements[] = t('tresorerie.avertissement_decouvert', [
                    'compte' => $p['compte']['nom'], 'code' => $p['devise']['code'],
                    'solde' => TresorerieAffichage::montant($solde, $p['devise']), 'apres' => TresorerieAffichage::montant($apres, $p['devise']),
                ]);
            }
        }
        return $avertissements;
    }

    /** Lignes proposées pour payer ces montants (devise → montant) : une caisse de l'établissement qui tient chaque devise. */
    public static function proposer(array $comptes, array $montants): array
    {
        $lignes = [];
        foreach ($montants as $deviseId => $montant) {
            $choisi = null;
            foreach ($comptes as $c) {
                if (GestionTresorerieRepository::tient($c, (int) $deviseId) && ($choisi === null || ($c['type'] === 'caisse' && $choisi['type'] !== 'caisse'))) {
                    $choisi = $c;
                }
            }
            $lignes[] = ['compte_id' => $choisi ? (int) $choisi['id'] : null, 'devise_id' => (int) $deviseId, 'montant' => TresorerieAffichage::saisie($montant)];
        }
        return $lignes ?: [self::LIGNE_VIDE];
    }
}
