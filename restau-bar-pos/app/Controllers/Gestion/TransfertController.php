<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Session;
use App\Core\View;
use App\Repositories\GestionTresorerieRepository;

/**
 * Transferts entre comptes de trésorerie : dépôt en banque, retrait mobile money, change de dollars en francs…
 * Entre deux devises, le montant reçu est proposé au taux du jour (avec l'arrondi de la devise reçue)
 * et reste modifiable : le taux réellement appliqué est enregistré.
 * Découvert : on prévient (confirmation) sans bloquer, car le comptage d'une caisse peut être faux.
 */
final class TransfertController
{
    /** Écart avec le taux du jour au-delà duquel on demande confirmation (faute de frappe probable). */
    private const ECART_TAUX = 0.2;

    private GestionTresorerieRepository $tresorerie;

    public function __construct()
    {
        $this->tresorerie = new GestionTresorerieRepository();
    }

    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $source = TresorerieSaisie::id($_GET['source'] ?? null);
        $this->afficher($utilisateur, [
            'compte_source_id' => $source, 'compte_destination_id' => null,
            'montant_source' => '', 'montant_destination' => '', 'date' => '', 'motif' => '',
        ]);
    }

    public function creer(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();

        $comptes = $this->tresorerie->comptesActifs();
        $devises = $this->tresorerie->devises();
        $valeurs = [
            'compte_source_id' => TresorerieSaisie::id($_POST['compte_source_id'] ?? null),
            'compte_destination_id' => TresorerieSaisie::id($_POST['compte_destination_id'] ?? null),
            'montant_source' => TresorerieSaisie::post('montant_source'),
            'montant_destination' => TresorerieSaisie::post('montant_destination'),
            'date' => TresorerieSaisie::post('date'),
            'motif' => mb_substr(TresorerieSaisie::post('motif'), 0, 300),
        ];
        $erreurs = [];

        $source = $valeurs['compte_source_id'] !== null ? ($comptes[$valeurs['compte_source_id']] ?? null) : null;
        $destination = $valeurs['compte_destination_id'] !== null ? ($comptes[$valeurs['compte_destination_id']] ?? null) : null;
        if ($source === null) {
            $erreurs['compte_source_id'] = $valeurs['compte_source_id'] !== null && $this->tresorerie->compte($valeurs['compte_source_id'])
                ? t('tresorerie.erreur_compte_inactif')
                : t('transferts.erreur_source');
        }
        if ($destination === null) {
            $erreurs['compte_destination_id'] = $valeurs['compte_destination_id'] !== null && $this->tresorerie->compte($valeurs['compte_destination_id'])
                ? t('tresorerie.erreur_compte_inactif')
                : t('transferts.erreur_destination');
        } elseif ($source !== null && (int) $source['id'] === (int) $destination['id']) {
            $erreurs['compte_destination_id'] = t('transferts.erreur_meme_compte');
        }

        $deviseSource = $source ? ($devises[(int) $source['devise_id']] ?? null) : null;
        $deviseDestination = $destination ? ($devises[(int) $destination['devise_id']] ?? null) : null;
        $memeDevise = $deviseSource && $deviseDestination && (int) $deviseSource['id'] === (int) $deviseDestination['id'];

        $montantSource = null;
        if ($deviseSource !== null) {
            [$montantSource, $erreur] = TresorerieSaisie::montant($valeurs['montant_source'], $deviseSource);
            if ($erreur !== null) {
                $erreurs['montant_source'] = $erreur;
            }
        } elseif ($valeurs['montant_source'] === '') {
            $erreurs['montant_source'] = t('transferts.erreur_montant_vide');
        }

        $montantDestination = null;
        $propose = null;
        if ($memeDevise) {
            $montantDestination = $montantSource;
        } elseif ($deviseSource && $deviseDestination && $montantSource !== null) {
            $propose = $this->proposer($montantSource, $deviseSource, $deviseDestination);
            if ($valeurs['montant_destination'] === '') {
                if ($propose === null) {
                    $erreurs['montant_destination'] = t('transferts.erreur_sans_taux', [
                        'source' => $deviseSource['code'], 'destination' => $deviseDestination['code'],
                    ]);
                }
            } else {
                [$montantDestination, $erreur] = TresorerieSaisie::montant($valeurs['montant_destination'], $deviseDestination);
                if ($erreur !== null) {
                    $erreurs['montant_destination'] = $erreur;
                }
            }
        }

        [$date, $erreurDate] = TresorerieSaisie::dateOperation($valeurs['date']);
        if ($erreurDate !== null) {
            $erreurs['date'] = $erreurDate;
        }
        if (mb_strlen($valeurs['motif']) > 255) {
            $erreurs['motif'] = t('tresorerie.erreur_texte_long', ['n' => 255]);
        }

        if ($erreurs !== []) {
            $this->afficher($utilisateur, $valeurs, $erreurs, [], 422);
            return;
        }

        // Montant reçu laissé vide entre deux devises : on propose celui du taux du jour, à vérifier
        if ($montantDestination === null) {
            $valeurs['montant_destination'] = TresorerieAffichage::saisie($propose);
            $this->afficher($utilisateur, $valeurs, [], [t('transferts.montant_propose', [
                'montant' => TresorerieAffichage::montant($propose, $deviseDestination),
            ])]);
            return;
        }

        // Avertissements : découvert, taux très éloigné du taux du jour
        $avertissements = [];
        $soldeApres = (float) $source['solde'] - $montantSource;
        if ($soldeApres < -0.0005) {
            $avertissements[] = t('tresorerie.avertissement_decouvert', [
                'compte' => $source['nom'],
                'solde' => TresorerieAffichage::montant($source['solde'], $deviseSource),
                'apres' => TresorerieAffichage::montant($soldeApres, $deviseSource),
            ]);
        }
        if (!$memeDevise && $propose !== null && $propose > 0 && abs($montantDestination / $propose - 1) > self::ECART_TAUX) {
            $avertissements[] = t('transferts.avertissement_taux', [
                'applique' => TresorerieAffichage::taux($deviseSource['code'], $deviseDestination['code'], $montantDestination / $montantSource),
                'jour' => TresorerieAffichage::taux($deviseSource['code'], $deviseDestination['code'], $propose / $montantSource),
            ]);
        }
        if ($avertissements !== [] && empty($_POST['confirme'])) {
            $this->afficher($utilisateur, $valeurs, [], [], 200, $avertissements);
            return;
        }

        $this->tresorerie->creerTransfert($source, $destination, $montantSource, $montantDestination, $date,
            $valeurs['motif'] !== '' ? $valeurs['motif'] : null, (int) $utilisateur['id']);
        Session::flash(t('transferts.enregistre', [
            'montant' => TresorerieAffichage::montant($montantSource, $deviseSource),
            'source' => $source['nom'],
            'destination' => $destination['nom'],
        ]));
        rediriger('/gestion/tresorerie/transferts');
    }

    /** Annulation d'un transfert : ses deux lignes du journal disparaissent avec lui. */
    public function supprimer(string $id): void
    {
        Session::exigerConnexion();
        Session::verifierCsrf();
        $transfert = ctype_digit($id) ? $this->tresorerie->transfert((int) $id) : null;
        if ($transfert === null) {
            pageIntrouvable();
        }
        $this->tresorerie->supprimerTransfert((int) $transfert['id']);
        Session::flash(t('transferts.supprime'));
        rediriger('/gestion/tresorerie/transferts');
    }

    /** Montant reçu au taux du jour, arrondi selon la devise reçue (null si un taux manque). */
    private function proposer(float $montant, array $deviseSource, array $deviseDestination): ?float
    {
        if ($deviseSource['taux'] === null || (float) $deviseSource['taux'] <= 0) {
            return null;
        }
        return $this->tresorerie->deviseRepository()->convertirDepuisReference($montant / (float) $deviseSource['taux'], $deviseDestination);
    }

    private function afficher(array $utilisateur, array $valeurs, array $erreurs = [], array $notes = [], int $code = 200, array $avertissements = []): void
    {
        http_response_code($code);
        $periode = TresorerieSaisie::periode('mois');
        View::afficher('gestion/tresorerie/transferts', [
            'titre' => t('transferts.titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'valeurs' => $valeurs,
            'erreurs' => $erreurs,
            'notes' => $notes,
            'avertissements' => $avertissements,
            'comptes' => $this->tresorerie->comptesActifs(),
            'devises' => $this->tresorerie->devises(),
            'periode' => $periode,
            'transferts' => $this->tresorerie->transferts($periode['debut'], $periode['fin']),
        ], 'gestion');
    }
}
