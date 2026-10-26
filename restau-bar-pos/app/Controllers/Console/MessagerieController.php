<?php

declare(strict_types=1);

namespace App\Controllers\Console;

use App\Console\Clients;
use App\Console\Instances;
use App\Console\Journal;
use App\Console\Reglages;
use App\Console\SessionConsole;
use App\Core\BaseConsole;
use App\Core\Messagerie;
use App\Repositories\DetteRepository;

/**
 * API de messagerie, communes à tous les clients : Africa's Talking (SMS) et WhatsApp Cloud API (Meta).
 * Les secrets ne sont jamais réaffichés (« •••• 1234 ») : champ vide = garder, case « Effacer » pour retirer.
 * Message d'essai, sender ID propre à chaque client, nombre de messages envoyés ce mois-ci par chaque instance.
 */
final class MessagerieController extends Base
{
    /** Champs du formulaire → clés des réglages (les secrets à part). */
    private const CHAMPS = [
        'sms_utilisateur' => 'sms.utilisateur', 'sms_expediteur' => 'sms.expediteur', 'sms_url' => 'sms.url',
        'whatsapp_numero_id' => 'whatsapp.numero_id', 'whatsapp_waba_id' => 'whatsapp.waba_id', 'whatsapp_version' => 'whatsapp.version',
        'whatsapp_modele_rappel_client' => 'whatsapp.modele_rappel_client', 'whatsapp_modele_rappel_responsable' => 'whatsapp.modele_rappel_responsable',
        'whatsapp_modele_garant_demande' => 'whatsapp.modele_garant_demande', 'whatsapp_langue_fr' => 'whatsapp.langue_fr',
        'whatsapp_langue_en' => 'whatsapp.langue_en', 'whatsapp_url' => 'whatsapp.url',
    ];
    private const SECRETS = ['sms_cle' => 'sms.cle', 'whatsapp_jeton' => 'whatsapp.jeton'];

    public function index(): void
    {
        $this->admin();
        $this->afficher();
    }

    public function enregistrer(): void
    {
        $admin = $this->poste();
        $valeurs = [];
        $erreurs = [];
        foreach (self::CHAMPS as $champ => $cle) {
            $valeurs[$cle] = self::texte($champ, 255);
        }
        $valeurs['sms.bac_a_sable'] = !empty($_POST['sms_bac_a_sable']) ? '1' : '0';
        if ($valeurs['sms.expediteur'] !== '' && !preg_match('/^(?=.*[A-Za-z])[A-Za-z0-9]{3,11}$/', $valeurs['sms.expediteur'])) {
            $erreurs['sms_expediteur'] = t('console.messagerie.erreur_expediteur');
        }
        if ($valeurs['whatsapp.version'] !== '' && !preg_match('/^v\d{1,2}\.\d$/', $valeurs['whatsapp.version'])) {
            $erreurs['whatsapp_version'] = t('console.messagerie.erreur_version');
        }
        foreach (['whatsapp.numero_id' => 'whatsapp_numero_id', 'whatsapp.waba_id' => 'whatsapp_waba_id'] as $cle => $champ) {
            if ($valeurs[$cle] !== '' && !ctype_digit($valeurs[$cle])) {
                $erreurs[$champ] = t('console.messagerie.erreur_identifiant');
            }
        }
        foreach (['sms.url' => 'sms_url', 'whatsapp.url' => 'whatsapp_url'] as $cle => $champ) {
            if ($valeurs[$cle] !== '' && !preg_match('#^https?://[^\s]+$#', $valeurs[$cle])) {
                $erreurs[$champ] = t('console.messagerie.erreur_url');
            }
        }
        foreach (['whatsapp.modele_rappel_client', 'whatsapp.modele_rappel_responsable', 'whatsapp.modele_garant_demande'] as $cle) {
            if ($valeurs[$cle] !== '' && !preg_match('/^[a-z0-9_]{1,512}$/', $valeurs[$cle])) {
                $erreurs[str_replace('.', '_', $cle)] = t('console.messagerie.erreur_modele');
            }
        }
        if ($erreurs !== []) {
            $this->afficher($erreurs, $valeurs, 422);
            return;
        }
        $changes = [];
        foreach ($valeurs as $cle => $valeur) {
            if (Reglages::get($cle) !== ($valeur === '' ? null : $valeur)) {
                $changes[] = $cle;
            }
            Reglages::definir($cle, $valeur === '' ? null : $valeur, (int) $admin['id']);
        }
        foreach (self::SECRETS as $champ => $cle) {
            $nouveau = is_string($_POST[$champ] ?? null) ? trim($_POST[$champ]) : '';
            if (!empty($_POST[$champ . '_effacer'])) {
                Reglages::definir($cle, null, (int) $admin['id']);
                $changes[] = $cle . ' (effacé)';
            } elseif ($nouveau !== '') {
                Reglages::definir($cle, $nouveau, (int) $admin['id']);
                $changes[] = $cle;
            }
        }
        Journal::ecrire('messagerie_reglee', null, ['changes' => $changes], (int) $admin['id']);
        SessionConsole::flash(t('console.enregistre'));
        rediriger('/messagerie');
    }

    /** Message d'essai à un numéro : SMS, ou WhatsApp en texte libre (arrive seulement dans la fenêtre de 24 h). */
    public function essai(): void
    {
        $this->poste();
        $canal = ($_POST['canal'] ?? '') === 'whatsapp' ? 'whatsapp' : 'sms';
        $telephone = DetteRepository::normaliserTelephone(self::texte('telephone', 32));
        if ($telephone === null) {
            $this->afficher(['essai' => t('console.erreur_telephone')], [], 422);
            return;
        }
        $texte = t('console.messagerie.texte_essai', ['plateforme' => Reglages::get('plateforme.nom', 'restau-bar-pos'), 'heure' => date('H:i')]);
        $resultat = Messagerie::transmettre($canal, $telephone, $texte, Reglages::messagerie());
        $statut = $resultat['ok'] ? 'envoye' : ($resultat['erreur'] === 'non réglé' ? 'simule' : 'echec');
        BaseConsole::executer(
            "INSERT INTO messages (canal, destinataire, evenement, texte, statut, fournisseur_id, erreur) VALUES (?, ?, 'test', ?, ?, ?, ?)",
            [$canal, $telephone, $texte, $statut, $resultat['id'] ? mb_substr($resultat['id'], 0, 128) : null, $resultat['ok'] ? null : mb_substr((string) $resultat['erreur'], 0, 255)]
        );
        Journal::ecrire('messagerie_essai', null, ['canal' => $canal, 'statut' => $statut]);
        SessionConsole::flash(match ($statut) {
            'envoye' => t('console.messagerie.essai_envoye', ['canal' => strtoupper($canal), 'numero' => $telephone]),
            'simule' => t('console.messagerie.essai_non_regle'),
            default => t('console.messagerie.essai_echec', ['erreur' => (string) $resultat['erreur']]),
        }, $statut === 'envoye' ? 'succes' : 'erreur');
        rediriger('/messagerie#essai');
    }

    private function afficher(array $erreurs = [], array $saisie = [], int $code = 200): void
    {
        $mois = is_string($_GET['mois'] ?? null) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $_GET['mois']) ? $_GET['mois'] : date('Y-m');
        $usages = [];
        foreach (Clients::tous() as $client) {
            $usages[] = ['client' => $client, 'usage' => $client['installe_le'] !== null ? Instances::usage($client['sous_domaine'], $mois) : null];
        }
        $valeurs = [];
        foreach (self::CHAMPS as $cle) {
            $valeurs[$cle] = $saisie[$cle] ?? (string) Reglages::get($cle, '');
        }
        $valeurs['sms.bac_a_sable'] = $saisie['sms.bac_a_sable'] ?? (string) Reglages::get('sms.bac_a_sable', '0');
        $this->vue('messagerie', [
            'titre' => t('console.messagerie.titre'),
            'valeurs' => $valeurs,
            'masques' => ['sms.cle' => Reglages::masque('sms.cle'), 'whatsapp.jeton' => Reglages::masque('whatsapp.jeton')],
            'erreurs' => $erreurs,
            'usages' => $usages,
            'mois' => $mois,
            'essais' => BaseConsole::tous("SELECT * FROM messages WHERE evenement = 'test' ORDER BY id DESC LIMIT 5"),
            'smsPret' => Reglages::get('sms.utilisateur') !== null && Reglages::get('sms.cle') !== null,
            'whatsappPret' => Reglages::get('whatsapp.numero_id') !== null && Reglages::get('whatsapp.jeton') !== null,
        ], $code);
    }
}
