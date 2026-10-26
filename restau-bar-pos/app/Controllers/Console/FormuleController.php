<?php

declare(strict_types=1);

namespace App\Controllers\Console;

use App\Console\Formules;
use App\Console\Journal;
use App\Console\SessionConsole;
use App\Core\Modules;

/** Formules d'abonnement : modules inclus (App\Core\Modules::offre()), limites, prix par période, SMS compris. */
final class FormuleController extends Base
{
    public function index(): void
    {
        $this->admin();
        $this->vue('formules/index', ['titre' => t('console.formules.titre'), 'formules' => Formules::toutes()]);
    }

    public function nouvelle(): void
    {
        $this->admin();
        $this->formulaire(null, [
            'nom' => '', 'description' => '', 'modules' => ['caisse', 'ventes', 'carte'], 'max_etablissements' => '1', 'max_utilisateurs' => '10',
            'prix_mois' => '', 'prix_trimestre' => '', 'prix_annee' => '', 'sms_inclus' => '100', 'actif' => 1,
        ]);
    }

    public function creer(): void
    {
        $admin = $this->poste();
        [$v, $erreurs] = Formules::saisie($_POST);
        if ($erreurs !== []) {
            $this->formulaire(null, $v, $erreurs, 422);
            return;
        }
        $id = Formules::enregistrer(null, $v);
        Journal::ecrire('formule_creee', null, ['formule' => $v['nom']], (int) $admin['id']);
        SessionConsole::flash(t('console.enregistre'));
        rediriger('/formules');
    }

    public function fiche(string $id): void
    {
        $this->admin();
        $formule = $this->formule($id);
        $this->formulaire($formule, $formule);
    }

    public function modifier(string $id): void
    {
        $admin = $this->poste();
        $formule = $this->formule($id);
        [$v, $erreurs] = Formules::saisie($_POST);
        if ($erreurs !== []) {
            $this->formulaire($formule, $v, $erreurs, 422);
            return;
        }
        Formules::enregistrer((int) $formule['id'], $v);
        Journal::ecrire('formule_modifiee', null, ['formule' => $v['nom'], 'modules' => $v['modules']], (int) $admin['id']);
        SessionConsole::flash(t('console.formule.modifiee_flash', ['n' => (int) $formule['clients']]));
        rediriger('/formules');
    }

    public function supprimer(string $id): void
    {
        $admin = $this->poste();
        $formule = $this->formule($id);
        if (Formules::supprimer((int) $formule['id'])) {
            Journal::ecrire('formule_supprimee', null, ['formule' => $formule['nom']], (int) $admin['id']);
            SessionConsole::flash(t('console.formule.supprimee_flash'));
        } else {
            SessionConsole::flash(t('console.formule.erreur_suppression'), 'erreur');
        }
        rediriger('/formules');
    }

    private function formule(string $id): array
    {
        $formule = ctype_digit($id) ? Formules::une((int) $id) : null;
        if ($formule === null) {
            pageIntrouvable();
        }
        return $formule;
    }

    private function formulaire(?array $formule, array $saisie, array $erreurs = [], int $code = 200): void
    {
        $this->vue('formules/fiche', [
            'titre' => $formule ? $formule['nom'] : t('console.formule.nouvelle'),
            'formule' => $formule,
            'saisie' => $saisie,
            'erreurs' => $erreurs,
            'offre' => Modules::offre(),
            'aVenir' => Modules::A_VENIR,
        ], $code);
    }
}
