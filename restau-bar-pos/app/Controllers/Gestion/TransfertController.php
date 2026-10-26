<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Session;
use App\Core\View;
use App\Repositories\GestionTresorerieRepository;

/**
 * Transferts entre comptes de trésorerie : dépôt en banque, retrait mobile money, change de dollars en francs…
 * Chaque côté choisit un compte ET une des devises qu'il tient. Le même compte dans deux devises est un change
 * dans la caisse (100 $ → 285 000 FC dans « Caisse du Comptoir ») ; même compte et même devise est refusé.
 * Entre deux devises, le montant reçu est proposé au taux du jour (avec l'arrondi de la devise reçue)
 * et reste modifiable : le taux réellement appliqué est enregistré.
 * Découvert (par compte et par devise) : on prévient (confirmation) sans bloquer, car le comptage d'une caisse peut être faux.
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
            'compte_source_id' => $source, 'devise_source_id' => null,
            'compte_destination_id' => null, 'devise_destination_id' => null,
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
            'devise_source_id' => TresorerieSaisie::id($_POST['devise_source_id'] ?? null),
            'compte_destination_id' => TresorerieSaisie::id($_POST['compte_destination_id'] ?? null),
            'devise_destination_id' => TresorerieSaisie::id($_POST['devise_destination_id'] ?? null),
            'montant_source' => TresorerieSaisie::post('montant_source'),
            'montant_destination' => TresorerieSaisie::post('montant_destination'),
            'date' => TresorerieSaisie::post('date'),
            'motif' => mb_substr(TresorerieSaisie::post('motif'), 0, 300),
        ];
        $erreurs = [];

        [$source, $deviseSource] = $this->cote($comptes, $devises, $valeurs, 'source', $erreurs);
        [$destination, $deviseDestination] = $this->cote($comptes, $devises, $valeurs, 'destination', $erreurs);
        if ($source !== null && $destination !== null && (int) $source['id'] === (int) $destination['id']
            && $deviseSource !== null && $deviseDestination !== null && (int) $deviseSource['id'] === (int) $deviseDestination['id']) {
            $erreurs['devise_destination_id'] = t('transferts.erreur_meme_compte', ['compte' => $source['nom'], 'code' => $deviseSource['code']]);
        }
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

        // Avertissements : découvert dans la devise qui sort, taux très éloigné du taux du jour
        $avertissements = [];
        $soldeSource = GestionTresorerieRepository::solde($source, (int) $deviseSource['id']);
        $soldeApres = $soldeSource - $montantSource;
        if ($soldeApres < -0.0005) {
            $avertissements[] = t('tresorerie.avertissement_decouvert', [
                'compte' => $source['nom'],
                'code' => $deviseSource['code'],
                'solde' => TresorerieAffichage::montant($soldeSource, $deviseSource),
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

        $this->tresorerie->creerTransfert($source, (int) $deviseSource['id'], $destination, (int) $deviseDestination['id'],
            $montantSource, $montantDestination, $date, $valeurs['motif'] !== '' ? $valeurs['motif'] : null, (int) $utilisateur['id']);
        Session::flash((int) $source['id'] === (int) $destination['id']
            ? t('transferts.change_enregistre', [
                'montant' => TresorerieAffichage::montant($montantSource, $deviseSource),
                'recu' => TresorerieAffichage::montant($montantDestination, $deviseDestination),
                'compte' => $source['nom'],
            ])
            : t('transferts.enregistre', [
                'montant' => TresorerieAffichage::montant($montantSource, $deviseSource),
                'source' => $source['nom'],
                'destination' => $destination['nom'],
            ]));
        rediriger('/gestion/tresorerie/transferts');
    }

    /**
     * Lit un côté du transfert (source ou destination) : compte actif, puis une devise qu'il tient.
     * Une seule devise tenue : elle est prise d'office.
     * @return array{0: ?array, 1: ?array} compte et devise
     */
    private function cote(array $comptes, array $devises, array $valeurs, string $cote, array &$erreurs): array
    {
        $compteId = $valeurs['compte_' . $cote . '_id'];
        $deviseId = $valeurs['devise_' . $cote . '_id'];
        $compte = $compteId !== null ? ($comptes[$compteId] ?? null) : null;
        if ($compte === null) {
            $erreurs['compte_' . $cote . '_id'] = $compteId !== null && $this->tresorerie->compte($compteId)
                ? t('tresorerie.erreur_compte_inactif')
                : t('transferts.erreur_' . $cote);
            return [null, null];
        }
        $tenues = array_keys(array_filter($compte['devises'], static fn (array $d): bool => $d['tenue']));
        if ($deviseId === null && count($tenues) === 1) {
            $deviseId = $tenues[0];
        }
        if ($deviseId === null || !in_array($deviseId, $tenues, true) || !isset($devises[$deviseId])) {
            $erreurs['devise_' . $cote . '_id'] = t('tresorerie.erreur_devise_compte', [
                'compte' => $compte['nom'],
                'codes' => implode(', ', array_map(static fn (int $id): string => $devises[$id]['code'] ?? '?', $tenues)),
            ]);
            return [$compte, null];
        }
        return [$compte, $devises[$deviseId]];
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
