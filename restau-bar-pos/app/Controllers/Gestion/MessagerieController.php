<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Config;
use App\Core\I18n;
use App\Core\Messagerie;
use App\Core\Parametres;
use App\Core\Session;
use App\Core\View;
use App\Repositories\DetteRepository;
use App\Repositories\GestionDetteRepository;

/**
 * Paramètres > Messagerie (parametres.messagerie) : relances automatiques (étapes, canal, responsable, heure d'envoi),
 * dernier passage du script horaire, état des canaux, message d'essai et journal des messages.
 *
 * Les comptes SMS (Africa's Talking) et WhatsApp Business sont communs à toutes les instances et réglés par la
 * plateforme (console super admin) : l'instance ne voit ni n'écrit aucune clé (aucun réglage sms.* ni whatsapp.*).
 * Elle montre seulement si chaque canal est prêt (Messagerie::smsPret(), Messagerie::whatsappPret()).
 */
final class MessagerieController
{
    /** Le script tourne chaque heure : au-delà de deux heures sans passage, il ne tourne sans doute plus. */
    private const RETARD_PASSAGE = 7200;

    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $this->afficher($utilisateur, $this->valeursEnregistrees());
    }

    public function enregistrer(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $valeurs = [];
        $erreurs = [];
        $valeurs['relances.actives'] = !empty($_POST['relances_actives']) ? '1' : '0';
        $valeurs['relances.responsable'] = !empty($_POST['relances_responsable']) ? '1' : '0';
        $canal = $_POST['relances_canal'] ?? 'prefere';
        $valeurs['relances.canal'] = in_array($canal, ['prefere', 'sms', 'whatsapp'], true) ? $canal : 'prefere';
        $heure = TresorerieSaisie::texte($_POST['relances_heure'] ?? null);
        $valeurs['relances.heure'] = $heure;
        if (!ctype_digit($heure) || (int) $heure > 23) {
            $erreurs['relances.heure'] = t('messagerie.erreur_heure');
        } else {
            $valeurs['relances.heure'] = (string) (int) $heure;
        }
        $saisie = TresorerieSaisie::texte($_POST['relances_etapes'] ?? null);
        $etapes = self::etapes($saisie);
        $valeurs['relances.etapes'] = $etapes ?? $saisie;
        if ($etapes === null) {
            $erreurs['relances.etapes'] = t('messagerie.erreur_etapes');
        }
        if ($erreurs !== []) {
            $this->afficher($utilisateur, $valeurs, $erreurs, null, 422);
            return;
        }
        foreach ($valeurs as $cle => $valeur) {
            Parametres::definir($cle, $valeur, (int) $utilisateur['id']);
        }
        Session::flash(t('messagerie.enregistre_flash'));
        rediriger('/gestion/messagerie');
    }

    public function essai(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $saisie = TresorerieSaisie::post('telephone');
        $telephone = DetteRepository::normaliserTelephone($saisie);
        if ($telephone === null) {
            $this->afficher($utilisateur, $this->valeursEnregistrees(), ['essai' => t('messagerie.erreur_telephone')], ['telephone' => $saisie], 422);
            return;
        }
        $langue = I18n::langue();
        $nom = Config::get('parametres', [])['nom_client'] ?? 'restau-bar-pos';
        $resultat = Messagerie::envoyer($telephone, I18n::dans($langue, 'msg.test', ['etab' => $nom]),
            ['evenement' => 'test', 'utilisateur_id' => null, 'langue' => $langue], Messagerie::canaux());
        $message = match ($resultat['statut']) {
            'envoye' => t('messagerie.essai_envoye', ['telephone' => afficherTelephone($telephone), 'canal' => t('messagerie.canal_' . $resultat['canal'])]),
            'simule' => t('messagerie.essai_simule', ['telephone' => afficherTelephone($telephone)]),
            default => t('messagerie.essai_echec', ['erreur' => (string) $resultat['erreur']]),
        };
        if ($resultat['statut'] === 'echec') {
            $this->afficher($utilisateur, $this->valeursEnregistrees(), ['essai' => $message], ['telephone' => $saisie], 502);
            return;
        }
        Session::flash($message);
        rediriger('/gestion/messagerie#essai');
    }

    public function journal(): void
    {
        $utilisateur = Session::exigerConnexion();
        $periode = TresorerieSaisie::periode('mois');
        $filtres = ['debut' => $periode['debut'], 'fin' => $periode['fin']];
        foreach (['statut' => GestionDetteRepository::STATUTS_MESSAGE, 'evenement' => GestionDetteRepository::EVENEMENTS, 'canal' => ['sms', 'whatsapp']] as $cle => $permis) {
            $filtres[$cle] = in_array($_GET[$cle] ?? null, $permis, true) ? $_GET[$cle] : null;
        }
        $depot = new GestionDetteRepository();
        $page = DetteAffichage::page();
        $journal = $depot->journalMessages($filtres, $page);
        $pages = max(1, (int) ceil($journal['total'] / GestionDetteRepository::PAR_PAGE));
        if ($page > $pages) {
            $page = $pages;
            $journal = $depot->journalMessages($filtres, $page);
        }
        View::afficher('gestion/messagerie/journal', [
            'titre' => t('messagerie.journal_titre'),
            'utilisateur' => $utilisateur,
            'periode' => $periode,
            'filtres' => $filtres,
            'messages' => $journal['messages'],
            'nombre' => $journal['total'],
            'compte' => $journal['compte'],
            'page' => $page,
            'pages' => $pages,
            'voirDettes' => Acces::a((int) $utilisateur['id'], 'dettes.voir'),
        ], 'gestion');
    }

    /**
     * Étapes de relance saisies (« -1, 1, 7, 30 » : la veille de l'échéance, puis 1, 7 et 30 jours après),
     * normalisées et triées ; null si une valeur est illisible ou hors de -30…365, ou s'il y en a plus de 10.
     */
    public static function etapes(string $saisie): ?string
    {
        $saisie = str_replace(["\u{2212}", ';', ' '], ['-', ',', ''], $saisie);
        if ($saisie === '') {
            return null;
        }
        $liste = [];
        foreach (explode(',', $saisie) as $morceau) {
            if (!preg_match('/^[+-]?\d{1,3}$/', $morceau) || (int) $morceau < -30 || (int) $morceau > 365 || (int) $morceau === 0 && str_starts_with($morceau, '-')) {
                return null;
            }
            $liste[] = (int) $morceau;
        }
        $liste = array_values(array_unique($liste));
        sort($liste);
        return count($liste) > 10 ? null : implode(',', $liste);
    }

    /** @return array<string, string> réglages des relances enregistrés */
    private function valeursEnregistrees(): array
    {
        return [
            'relances.actives' => Parametres::get('relances.actives', '1'),
            'relances.responsable' => Parametres::get('relances.responsable', '1'),
            'relances.canal' => Parametres::get('relances.canal', 'prefere'),
            'relances.heure' => Parametres::get('relances.heure', '10'),
            'relances.etapes' => Parametres::get('relances.etapes', '-1,1,7,30'),
        ];
    }

    private function afficher(array $utilisateur, array $valeurs, array $erreurs = [], ?array $essai = null, int $code = 200): void
    {
        http_response_code($code);
        $passage = Parametres::get('relances.dernier_passage');
        $age = $passage !== null && ($t = strtotime($passage)) !== false ? time() - $t : null;
        View::afficher('gestion/messagerie/index', [
            'titre' => t('messagerie.titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'valeurs' => $valeurs,
            'erreurs' => $erreurs,
            'essai' => $essai ?? ['telephone' => ''],
            'smsPret' => Messagerie::smsPret(),
            'whatsappPret' => Messagerie::whatsappPret(),
            'dernierPassage' => $passage,
            'passageEnRetard' => $age === null || $age > self::RETARD_PASSAGE,
            'cron' => '5 * * * * php ' . RACINE . '/bin/relances.php --toutes',
        ], 'gestion');
    }
}
