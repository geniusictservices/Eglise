<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Database;
use App\Core\Session;
use App\Core\View;
use App\Repositories\FideliteRepository;
use App\Repositories\GestionTresorerieRepository;

/**
 * Ventes > Fidélité (au choix de chaque établissement, cahier des charges section 4) : activation, points par
 * montant dépensé, récompenses (remise en % ou article offert), meilleurs clients. Le solde de chaque client est sur
 * sa fiche. Voir : clients.voir ; modifier : fidelite.parametrer.
 */
final class FideliteController
{
    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $liste = SalleAcces::etablissements((int) $utilisateur['id'], 'clients.voir');
        $etab = SalleAcces::choisi($liste);
        if ($etab === null) {
            SalleAcces::interdit($utilisateur);
        }
        $repo = new FideliteRepository();
        View::afficher('gestion/nuit/fidelite', [
            'titre' => t('gnuit.fidelite_titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'etablissements' => $liste, 'etab' => $etab,
            'reglages' => $repo->reglages((int) $etab['id'], true),
            'recompenses' => $repo->recompenses((int) $etab['id'], false),
            'classement' => $repo->classement((int) $etab['id']),
            'devises' => (new GestionTresorerieRepository())->devises(),
            'formats' => (new \App\Repositories\FormuleRepository())->formatsVendables(),
            'peutModifier' => Acces::a((int) $utilisateur['id'], 'fidelite.parametrer', (int) $etab['id']),
        ], 'gestion');
    }

    private function etab(array $utilisateur): array
    {
        Session::verifierCsrf();
        return SalleAcces::exiger($utilisateur, 'fidelite.parametrer', SalleAcces::entier($_POST['etablissement'] ?? 0, 0, PHP_INT_MAX, 0));
    }

    public function reglages(): void
    {
        $utilisateur = Session::exigerConnexion();
        $etab = $this->etab($utilisateur);
        $devises = (new GestionTresorerieRepository())->devises();
        $deviseId = SalleAcces::entier($_POST['devise_id'] ?? 0, 0, PHP_INT_MAX, 0);
        [$montant, $erreur] = isset($devises[$deviseId]) ? TresorerieSaisie::montant($_POST['montant_point'] ?? '', $devises[$deviseId]) : [null, t('gnuit.erreur_devise')];
        if ($erreur !== null) {
            Session::flash($erreur);
        } else {
            (new FideliteRepository())->definirReglages((int) $etab['id'], !empty($_POST['actif']), (float) $montant, $deviseId, (int) $utilisateur['id']);
            Session::flash(t('gnuit.fidelite_enregistree'));
        }
        rediriger('/gestion/fidelite?etablissement=' . (int) $etab['id']);
    }

    public function recompense(?string $id = null): void
    {
        $utilisateur = Session::exigerConnexion();
        $etab = $this->etab($utilisateur);
        $type = ($_POST['type'] ?? '') === 'offert' ? 'offert' : 'remise';
        $nom = SalleAcces::texte($_POST['nom_fr'] ?? '', 80);
        $points = SalleAcces::entier($_POST['points'] ?? 0, 0, 1000000, 0);
        $pourcent = $type === 'remise' ? (float) (DeviseAffichage::lireNombre((string) ($_POST['pourcent'] ?? '')) ?? 0) : null;
        $format = $type === 'offert' ? SalleAcces::entier($_POST['conditionnement_id'] ?? 0, 0, PHP_INT_MAX, 0) : null;
        $erreur = null;
        if ($nom === '' || $points < 1) {
            $erreur = t('gnuit.erreur_recompense');
        } elseif ($type === 'remise' && ($pourcent <= 0 || $pourcent > 100)) {
            $erreur = t('gnuit.erreur_pourcent');
        } elseif ($type === 'offert' && Database::un('SELECT id FROM conditionnements WHERE id = ? AND vendable = 1', [$format]) === null) {
            $erreur = t('gnuit.erreur_recompense');
        }
        if ($erreur !== null) {
            Session::flash($erreur);
            rediriger('/gestion/fidelite?etablissement=' . (int) $etab['id']);
        }
        (new FideliteRepository())->enregistrerRecompense($id !== null ? (int) $id : null, (int) $etab['id'], [
            'nom_fr' => $nom, 'nom_en' => SalleAcces::texte($_POST['nom_en'] ?? '', 80), 'points' => $points, 'type' => $type,
            'pourcent' => $pourcent, 'conditionnement_id' => $format, 'actif' => $id === null || !empty($_POST['actif']),
        ], (int) $utilisateur['id']);
        Session::flash(t('gnuit.recompense_enregistree', ['nom' => $nom]));
        rediriger('/gestion/fidelite?etablissement=' . (int) $etab['id']);
    }
}
