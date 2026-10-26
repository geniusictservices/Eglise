<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Session;
use App\Core\View;
use App\Repositories\GestionDeviseRepository;

/**
 * Devises et taux de change : liste des devises, taux du jour, historique,
 * devise de référence, ajout et modification d'une devise.
 */
final class DeviseController
{
    private const FORMAT_DATE = 'Y-m-d\TH:i';

    private GestionDeviseRepository $devises;

    public function __construct()
    {
        $this->devises = new GestionDeviseRepository();
    }

    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $this->afficher($utilisateur);
    }

    /** Taux du jour : une ligne dans taux_change pour chaque taux modifié. */
    public function enregistrerTaux(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();

        $reference = $this->devises->reference();
        if ($reference === null) {
            rediriger('/gestion/devises');
        }
        $saisies = is_array($_POST['taux'] ?? null) ? $_POST['taux'] : [];
        $erreurs = [];

        // Date de début : maintenant si le champ est vide
        $texteDate = is_string($_POST['valable_depuis'] ?? null) ? trim($_POST['valable_depuis']) : '';
        $debut = $texteDate === '' ? new \DateTimeImmutable() : $this->lireDate($texteDate);
        if ($debut === null) {
            $erreurs['valable_depuis'] = t('devises.erreur_date');
        } elseif ($debut < new \DateTimeImmutable('-30 days')) {
            $erreurs['valable_depuis'] = t('devises.erreur_date_passee');
        } elseif ($debut > new \DateTimeImmutable('+365 days')) {
            $erreurs['valable_depuis'] = t('devises.erreur_date_future');
        }

        $aEnregistrer = [];
        $codes = [];
        foreach ($this->devises->toutes() as $devise) {
            $id = (int) $devise['id'];
            if (!$devise['active'] || $devise['est_reference'] || !array_key_exists($id, $saisies)) {
                continue;
            }
            $texte = is_string($saisies[$id]) ? trim($saisies[$id]) : '';
            $params = ['code' => $devise['code'], 'reference' => $reference['code']];
            if ($texte === '') {
                if ($devise['taux'] !== null) {
                    $erreurs['taux-' . $id] = t('devises.erreur_taux_vide', $params);
                }
                continue;
            }
            $nombre = DeviseAffichage::lireNombre($texte);
            if ($nombre === null) {
                $erreurs['taux-' . $id] = t('devises.erreur_taux_nombre', $params);
                continue;
            }
            $taux = round((float) $nombre, 6);
            if ($taux <= 0) {
                $erreurs['taux-' . $id] = t('devises.erreur_taux_zero', $params);
                continue;
            }
            if ($taux >= 1e12) {
                $erreurs['taux-' . $id] = t('devises.erreur_taux_grand', $params);
                continue;
            }
            if ($devise['taux'] !== null && abs($taux - (float) $devise['taux']) < 0.0000005) {
                continue; // inchangé
            }
            if ($debut !== null && $devise['taux_depuis'] !== null && $debut->format('Y-m-d H:i:s') < $devise['taux_depuis']) {
                $erreurs['taux-' . $id] = t('devises.erreur_taux_anterieur', $params + [
                    'date' => DeviseAffichage::dateHeure($devise['taux_depuis']),
                ]);
                continue;
            }
            $aEnregistrer[$id] = GestionDeviseRepository::decimal($taux);
            $codes[] = $devise['code'];
        }

        if ($erreurs !== []) {
            $this->afficher($utilisateur, [
                'erreursTaux' => $erreurs,
                'saisiesTaux' => array_map(static fn ($v) => is_string($v) ? $v : '', $saisies),
                'valableDepuis' => $texteDate,
            ], 422);
            return;
        }
        if ($aEnregistrer === []) {
            Session::flash(t('devises.taux_inchanges'));
            rediriger('/gestion/devises#taux');
        }

        $this->devises->enregistrerTaux($aEnregistrer, $debut->format('Y-m-d H:i:00'), (int) $utilisateur['id'], (int) $reference['id']);
        $message = count($codes) === 1
            ? t('devises.taux_enregistre', ['codes' => $codes[0]])
            : t('devises.taux_enregistres', ['n' => count($codes), 'codes' => implode(', ', $codes)]);
        if ($debut > new \DateTimeImmutable()) {
            $message .= ' ' . t('devises.taux_a_partir', ['date' => DeviseAffichage::dateHeure($debut->format('Y-m-d H:i:s'))]);
        }
        Session::flash($message);
        rediriger('/gestion/devises#taux');
    }

    /** Changement de devise de référence, en deux temps : explication puis confirmation. */
    public function choisirReference(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();

        $id = (int) ($_POST['devise_id'] ?? 0);
        $nouvelle = $id > 0 ? $this->devises->parId($id) : null;
        $ancienne = $this->devises->reference();
        if ($nouvelle === null || $ancienne === null || (int) $nouvelle['id'] === (int) $ancienne['id']) {
            $this->afficher($utilisateur, ['erreurs' => [t('devises.erreur_reference_choix')]], 422);
            return;
        }
        $params = ['code' => $nouvelle['code'], 'ancienne' => $ancienne['code']];
        $prix = $this->devises->prixEnregistres((int) $ancienne['id']);
        if ($prix['prix'] > 0) {
            $this->afficher($utilisateur, ['erreurs' => [t('devises.reference_bloquee', [
                'code' => $ancienne['code'], 'prix' => $prix['prix'], 'produits' => $prix['produits'],
            ])]], 422);
            return;
        }
        if (!$nouvelle['active']) {
            $this->afficher($utilisateur, ['erreurs' => [t('devises.erreur_reference_inactive', $params)]], 422);
            return;
        }
        if ($nouvelle['taux'] === null || (float) $nouvelle['taux'] <= 0) {
            $this->afficher($utilisateur, ['erreurs' => [t('devises.erreur_reference_sans_taux', $params)]], 422);
            return;
        }

        if (empty($_POST['confirme'])) {
            $this->afficher($utilisateur, ['confirmation' => [
                'type' => 'reference',
                'devise' => $nouvelle,
                'ancienne' => $ancienne,
                'nouveauxTaux' => $this->nouveauxTaux($ancienne, $nouvelle),
            ]]);
            return;
        }

        $this->devises->changerReference((int) $ancienne['id'], (int) $nouvelle['id'], (float) $nouvelle['taux'], (int) $utilisateur['id']);
        Session::flash(t('devises.reference_changee', $params));
        rediriger('/gestion/devises');
    }

    public function nouvelle(): void
    {
        $utilisateur = Session::exigerConnexion();
        $this->afficherFiche($utilisateur, null, [
            'code' => '', 'symbole' => '', 'nom_fr' => '', 'nom_en' => '', 'decimales' => 2, 'arrondi' => '0',
            'symbole_avant' => false, 'position' => $this->positionSuivante(), 'active' => true,
        ]);
    }

    public function creer(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        [$valeurs, $erreurs] = $this->lire(null);
        if ($erreurs !== []) {
            $this->afficherFiche($utilisateur, null, $valeurs, $erreurs, 422);
            return;
        }
        $id = $this->devises->creer($valeurs);
        $devise = $this->devises->parId($id);
        if ($devise['est_reference']) {
            Session::flash(t('devises.creee_reference', ['code' => $devise['code']]));
        } elseif ($devise['active']) {
            Session::flash(t('devises.creee_active', ['code' => $devise['code']]));
        } else {
            Session::flash(t('devises.creee', ['code' => $devise['code']]));
        }
        rediriger($devise['active'] && !$devise['est_reference'] ? '/gestion/devises#taux' : '/gestion/devises#devise-' . $id);
    }

    public function fiche(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        $devise = $this->deviseOu404($id);
        $this->afficherFiche($utilisateur, $devise, $devise);
    }

    public function modifier(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $devise = $this->deviseOu404($id);
        [$valeurs, $erreurs] = $this->lire((int) $devise['id']);
        if ($erreurs !== []) {
            $this->afficherFiche($utilisateur, $devise, $valeurs, $erreurs, 422);
            return;
        }
        $this->devises->modifier((int) $devise['id'], $valeurs);
        Session::flash(t('devises.enregistree', ['code' => $valeurs['code']]));
        rediriger('/gestion/devises#devise-' . (int) $devise['id']);
    }

    public function activer(string $id): void
    {
        Session::exigerConnexion();
        Session::verifierCsrf();
        $devise = $this->deviseOu404($id);
        $this->devises->definirActive((int) $devise['id'], true);
        if ($devise['taux'] === null && !$devise['est_reference']) {
            Session::flash(t('devises.activee_sans_taux', ['code' => $devise['code']]));
            rediriger('/gestion/devises#taux');
        }
        Session::flash(t('devises.activee', ['code' => $devise['code']]));
        rediriger('/gestion/devises#devise-' . (int) $devise['id']);
    }

    /** Désactivation : impossible pour la référence, confirmation si des établissements l'acceptent. */
    public function desactiver(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $devise = $this->deviseOu404($id);
        if ($devise['est_reference']) {
            $this->afficher($utilisateur, ['erreurs' => [t('devises.erreur_desactiver_reference', ['code' => $devise['code']])]], 422);
            return;
        }
        $etablissements = $this->devises->etablissementsAcceptant((int) $devise['id']);
        if ($etablissements !== [] && empty($_POST['confirme'])) {
            $this->afficher($utilisateur, ['confirmation' => [
                'type' => 'desactiver',
                'devise' => $devise,
                'etablissements' => $etablissements,
            ]]);
            return;
        }
        $this->devises->definirActive((int) $devise['id'], false);
        Session::flash(t('devises.desactivee', ['code' => $devise['code']]));
        rediriger('/gestion/devises#devise-' . (int) $devise['id']);
    }

    private function afficher(array $utilisateur, array $etat = [], int $code = 200): void
    {
        http_response_code($code);
        $devises = $this->devises->toutes();
        $reference = null;
        foreach ($devises as $devise) {
            if ($devise['est_reference']) {
                $reference = $devise;
            }
        }
        $prixReference = $reference ? $this->devises->prixEnregistres((int) $reference['id']) : ['prix' => 0, 'produits' => 0];

        View::afficher('gestion/devises/index', [
            'titre' => t('devises.titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'devises' => $devises,
            'reference' => $reference,
            'prixReference' => $prixReference,
            'historique' => $this->devises->historique(30),
            'enVigueur' => array_flip($this->devises->idsTauxEnVigueur()),
            'maintenant' => date('Y-m-d H:i:s'),
            'valableDepuis' => $etat['valableDepuis'] ?? '',
            'valableDepuisDefaut' => date(self::FORMAT_DATE),
            'saisiesTaux' => $etat['saisiesTaux'] ?? [],
            'erreursTaux' => $etat['erreursTaux'] ?? [],
            'erreurs' => $etat['erreurs'] ?? [],
            'confirmation' => $etat['confirmation'] ?? null,
        ], 'gestion');
    }

    private function afficherFiche(array $utilisateur, ?array $devise, array $valeurs, array $erreurs = [], int $code = 200): void
    {
        http_response_code($code);
        View::afficher('gestion/devises/fiche', [
            'titre' => $devise ? t('devises.modifier_titre', ['code' => $devise['code']]) : t('devises.nouvelle'),
            'utilisateur' => $utilisateur,
            'devise' => $devise,
            'valeurs' => $valeurs,
            'erreurs' => $erreurs,
            'etablissements' => $devise ? $this->devises->etablissementsAcceptant((int) $devise['id']) : [],
            'premiere' => $this->devises->reference() === null,
        ], 'gestion');
    }

    /** @return array{array, array<string, string>} valeurs lues et erreurs par champ */
    private function lire(?int $id): array
    {
        $texte = static fn (string $nom): string => is_string($_POST[$nom] ?? null)
            ? trim((string) preg_replace('/\p{Cc}/u', '', $_POST[$nom]))
            : '';

        $valeurs = [
            'code' => strtoupper($texte('code')),
            'symbole' => $texte('symbole'),
            'nom_fr' => $texte('nom_fr'),
            'nom_en' => $texte('nom_en'),
            'decimales' => $texte('decimales'),
            'arrondi' => $texte('arrondi'),
            'symbole_avant' => ($_POST['symbole_avant'] ?? '0') === '1',
            'position' => $texte('position'),
            'active' => !empty($_POST['active']),
        ];
        $erreurs = [];

        if (!preg_match('/^[A-Z]{3}$/', $valeurs['code'])) {
            $erreurs['code'] = t('devises.erreur_code');
        } elseif ($this->devises->codeExiste($valeurs['code'], $id)) {
            $erreurs['code'] = t('devises.erreur_code_existe', ['code' => $valeurs['code']]);
        }
        if ($valeurs['symbole'] === '') {
            $erreurs['symbole'] = t('devises.erreur_symbole');
        } elseif (mb_strlen($valeurs['symbole']) > 8) {
            $erreurs['symbole'] = t('devises.erreur_symbole_long');
        }
        if ($valeurs['nom_fr'] === '') {
            $erreurs['nom_fr'] = t('devises.erreur_nom');
        } elseif (mb_strlen($valeurs['nom_fr']) > 64) {
            $erreurs['nom_fr'] = t('devises.erreur_nom_long');
        }
        if (mb_strlen($valeurs['nom_en']) > 64) {
            $erreurs['nom_en'] = t('devises.erreur_nom_long');
        }

        if (!in_array($valeurs['decimales'], ['0', '1', '2', '3'], true)) {
            $erreurs['decimales'] = t('devises.erreur_decimales');
        }
        $valeurs['decimales'] = (int) $valeurs['decimales'];

        $arrondi = $valeurs['arrondi'] === '' ? '0' : DeviseAffichage::lireNombre($valeurs['arrondi']);
        if ($arrondi === null || (float) $arrondi > 1000000) {
            $erreurs['arrondi'] = t('devises.erreur_arrondi');
        } elseif (preg_match('/\.\d{5,}$/', $arrondi)) {
            $erreurs['arrondi'] = t('devises.erreur_arrondi_precision');
        } elseif ((float) $arrondi > 0 && !isset($erreurs['decimales'])) {
            // Avec 0 décimale, l'arrondi doit être entier ; avec 2 décimales, un multiple de 0,01…
            $multiple = (float) $arrondi * 10 ** $valeurs['decimales'];
            if (abs($multiple - round($multiple)) > 1e-6) {
                $erreurs['arrondi'] = t('devises.erreur_arrondi_decimales', [
                    'n' => $valeurs['decimales'],
                    'pas' => DeviseAffichage::taux(10 ** -$valeurs['decimales']),
                ]);
            }
        }
        $valeurs['arrondi'] = $arrondi ?? $valeurs['arrondi'];

        if ($valeurs['position'] === '') {
            $valeurs['position'] = (string) $this->positionSuivante();
        }
        if (!ctype_digit($valeurs['position']) || (int) $valeurs['position'] > 999) {
            $erreurs['position'] = t('devises.erreur_position');
        }
        $valeurs['position'] = (int) $valeurs['position'];

        return [$valeurs, $erreurs];
    }

    /** Taux recalculés si la nouvelle devise devient la référence : code => taux. */
    private function nouveauxTaux(array $ancienne, array $nouvelle): array
    {
        $resultat = [$ancienne['code'] => 1 / (float) $nouvelle['taux']];
        foreach ($this->devises->toutes() as $devise) {
            if ($devise['est_reference'] || (int) $devise['id'] === (int) $nouvelle['id'] || $devise['taux'] === null) {
                continue;
            }
            $resultat[$devise['code']] = (float) $devise['taux'] / (float) $nouvelle['taux'];
        }
        return $resultat;
    }

    private function positionSuivante(): int
    {
        $positions = array_column($this->devises->toutes(), 'position');
        return min(999, ($positions ? max(array_map('intval', $positions)) : 0) + 1);
    }

    private function lireDate(string $texte): ?\DateTimeImmutable
    {
        foreach ([self::FORMAT_DATE, 'Y-m-d\TH:i:s', 'Y-m-d H:i'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $texte);
            if ($date !== false && $date->format($format) === $texte) {
                return $date;
            }
        }
        return null;
    }

    private function deviseOu404(string $id): array
    {
        $devise = ctype_digit($id) ? $this->devises->parId((int) $id) : null;
        if ($devise === null) {
            pageIntrouvable();
        }
        return $devise;
    }
}
