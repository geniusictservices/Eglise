<?php

declare(strict_types=1);

namespace App\Controllers\Console;

use App\Console\Journal;
use App\Console\Relances;
use App\Console\Reglages;
use App\Console\SessionConsole;
use App\Repositories\DetteRepository;

/** Réglages de la plateforme : nom et contact (montrés aux bars), e-mail d'envoi, relances d'abonnement, installation. */
final class ReglagesController extends Base
{
    public function index(): void
    {
        $this->admin();
        $this->afficher();
    }

    public function enregistrer(): void
    {
        $admin = $this->poste();
        $v = [
            'plateforme.nom' => self::texte('plateforme_nom', 60),
            'plateforme.contact_telephone' => self::texte('plateforme_contact_telephone', 32),
            'plateforme.contact_email' => strtolower(self::texte('plateforme_contact_email', 128)),
            'email.expediteur' => strtolower(self::texte('email_expediteur', 128)),
            'relances.actives' => !empty($_POST['relances_actives']) ? '1' : '0',
            'relances.etapes' => self::texte('relances_etapes', 60),
            'relances.heure' => self::texte('relances_heure', 2),
            'installation.cpanel_prefixe' => self::texte('installation_cpanel_prefixe', 32),
            'installation.db_hote' => self::texte('installation_db_hote', 128),
        ];
        $erreurs = [];
        if ($v['plateforme.nom'] === '') {
            $erreurs['plateforme_nom'] = t('console.reglages.erreur_nom');
        }
        if ($v['plateforme.contact_telephone'] !== '') {
            $tel = DetteRepository::normaliserTelephone($v['plateforme.contact_telephone']);
            $tel === null ? $erreurs['plateforme_contact_telephone'] = t('console.erreur_telephone') : $v['plateforme.contact_telephone'] = $tel;
        }
        foreach (['plateforme.contact_email' => 'plateforme_contact_email', 'email.expediteur' => 'email_expediteur'] as $cle => $champ) {
            if ($v[$cle] !== '' && !filter_var($v[$cle], FILTER_VALIDATE_EMAIL)) {
                $erreurs[$champ] = t('console.erreur_email');
            }
        }
        $etapes = Relances::etapes($v['relances.etapes']);
        if ($etapes === []) {
            $erreurs['relances_etapes'] = t('console.reglages.erreur_etapes');
        } else {
            $v['relances.etapes'] = implode(',', $etapes);
        }
        if (!ctype_digit($v['relances.heure']) || (int) $v['relances.heure'] > 23) {
            $erreurs['relances_heure'] = t('console.reglages.erreur_heure');
        }
        if ($v['installation.cpanel_prefixe'] !== '' && !preg_match('/^[a-z0-9]{1,32}$/', $v['installation.cpanel_prefixe'])) {
            $erreurs['installation_cpanel_prefixe'] = t('console.reglages.erreur_prefixe');
        }
        if ($erreurs !== []) {
            $this->afficher($erreurs, $v, 422);
            return;
        }
        foreach ($v as $cle => $valeur) {
            Reglages::definir($cle, $valeur === '' ? null : $valeur, (int) $admin['id']);
        }
        Journal::ecrire('reglages_modifies', null, [], (int) $admin['id']);
        SessionConsole::flash(t('console.enregistre'));
        rediriger('/reglages');
    }

    private function afficher(array $erreurs = [], array $saisie = [], int $code = 200): void
    {
        $cles = ['plateforme.nom', 'plateforme.contact_telephone', 'plateforme.contact_email', 'email.expediteur', 'relances.actives',
            'relances.etapes', 'relances.heure', 'installation.cpanel_prefixe', 'installation.db_hote'];
        $valeurs = [];
        foreach ($cles as $cle) {
            $valeurs[$cle] = $saisie[$cle] ?? (string) Reglages::get($cle, '');
        }
        $this->vue('reglages', [
            'titre' => t('console.reglages.titre'),
            'valeurs' => $valeurs,
            'erreurs' => $erreurs,
            'dernierPassage' => Reglages::get('relances.dernier_passage'),
            'racine' => RACINE,
            'php' => PHP_BINARY,
        ], $code);
    }
}
