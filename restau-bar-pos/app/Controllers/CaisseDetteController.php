<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Acces;
use App\Core\Database;
use App\Core\Reglement;
use App\Core\Session;
use App\Core\View;
use App\Repositories\DetteRepository;
use App\Repositories\DeviseRepository;
use App\Repositories\GestionDeviseRepository;

/**
 * Ardoises à la caisse (réponses JSON pour caisse.js) : recherche et création rapide de client pour mettre
 * un ticket à crédit, réponse du garant, remboursement encaissé à la caisse avec son reçu.
 * La personne est celle de la caisse (PIN sur une caisse partagée, ou compte de gestion sur un appareil personnel).
 */
final class CaisseDetteController
{
    private DetteRepository $dettes;
    private DeviseRepository $devises;

    public function __construct()
    {
        $this->dettes = new DetteRepository();
        $this->devises = new DeviseRepository();
    }

    /** GET /caisse/clients?etab=…&q=… : clients trouvés par nom ou téléphone, avec ce qu'ils doivent. */
    public function chercher(): void
    {
        ['etab' => $etab, 'personne' => $personne] = $this->vendeur(is_string($_GET['etab'] ?? null) ? $_GET['etab'] : null);
        $this->exiger($personne, $etab, ['caisse.credit', 'caisse.rembourser']);
        $acceptees = CaisseController::devisesCaisse($etab)['acceptees'];
        $q = is_string($_GET['q'] ?? null) ? mb_substr($_GET['q'], 0, 60) : '';
        $this->repondre(200, ['clients' => array_map(fn (array $c) => $this->clientJson($c, $acceptees), $this->dettes->rechercher($q))]);
    }

    /** GET /caisse/clients/{id}?etab=… : un client et ses dettes ouvertes (pour un remboursement). */
    public function client(string $id): void
    {
        ['etab' => $etab, 'personne' => $personne] = $this->vendeur(is_string($_GET['etab'] ?? null) ? $_GET['etab'] : null);
        $this->exiger($personne, $etab, ['caisse.credit', 'caisse.rembourser']);
        $client = ctype_digit($id) ? $this->dettes->client((int) $id) : null;
        if ($client === null || $client['type'] === 'personnel') {
            $this->repondre(404, ['erreur' => t('ardoise.client_introuvable')]);
        }
        $acceptees = CaisseController::devisesCaisse($etab)['acceptees'];
        $this->repondre(200, ['client' => $this->clientJson($client, $acceptees, true)]);
    }

    /** POST /caisse/clients : création rapide (nom, téléphone, WhatsApp) pendant l'encaissement. */
    public function creer(): void
    {
        $this->verifierJeton();
        ['etab' => $etab, 'personne' => $personne] = $this->vendeur(is_string($_POST['etab'] ?? null) ? $_POST['etab'] : null);
        $this->exiger($personne, $etab, ['caisse.credit']);
        try {
            $id = $this->dettes->creerClient([
                'nom' => is_string($_POST['nom'] ?? null) ? $_POST['nom'] : '',
                'telephone' => is_string($_POST['telephone'] ?? null) ? $_POST['telephone'] : '',
                'canal' => ($_POST['canal'] ?? '') === 'whatsapp' ? 'whatsapp' : 'sms',
                'langue' => ($_POST['langue'] ?? '') === 'en' ? 'en' : 'fr',
            ], $personne['id']);
        } catch (\InvalidArgumentException $e) {
            $this->repondre(422, ['erreur' => t('ardoise.client_' . $e->getMessage()), 'champ' => $e->getMessage() === 'nom' ? 'nom' : 'telephone']);
        }
        $acceptees = CaisseController::devisesCaisse($etab)['acceptees'];
        $this->repondre(201, ['client' => $this->clientJson($this->dettes->client($id), $acceptees), 'csrf' => Session::jetonCsrf()]);
    }

    /** POST /caisse/garanties : le garant accepte ou refuse une dette (seulement lui). */
    public function garantie(): void
    {
        $this->verifierJeton();
        ['personne' => $personne] = $this->vendeur(is_string($_POST['etab'] ?? null) ? $_POST['etab'] : null);
        try {
            $this->dettes->repondreGarant((int) ($_POST['dette'] ?? 0), $personne['id'], ($_POST['reponse'] ?? '') === 'accepte');
        } catch (\DomainException) {
            $this->repondre(409, ['erreur' => t('ardoise.garantie_introuvable'), 'garanties' => self::garantiesJson($personne['id'])]);
        }
        $this->repondre(200, ['garanties' => self::garantiesJson($personne['id']), 'csrf' => Session::jetonCsrf()]);
    }

    /**
     * POST /caisse/rembourser : le client rembourse à la caisse, dans une devise, sur un compte de l'établissement
     * qui la tient ; réparti sur ses dettes les plus anciennes. Réponse : reçu à imprimer.
     */
    public function rembourser(): void
    {
        $this->verifierJeton();
        $demande = json_decode(is_string($_POST['remboursement'] ?? null) ? $_POST['remboursement'] : '', true);
        if (!is_array($demande)) {
            $this->repondre(422, ['erreur' => t('encaisser.illisible')]);
        }
        ['etab' => $etab, 'personne' => $personne, 'appareil' => $appareil] = $this->vendeur(is_string($demande['etab'] ?? null) ? $demande['etab'] : null);
        $this->exiger($personne, $etab, ['caisse.rembourser']);
        $cle = is_string($demande['cle'] ?? null) ? $demande['cle'] : '';
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $cle)) {
            $this->repondre(422, ['erreur' => t('encaisser.illisible')]);
        }
        $acceptees = array_column(CaisseController::devisesCaisse($etab)['acceptees'], null, 'code');
        $code = is_string($demande['devise'] ?? null) ? $demande['devise'] : '';
        $comptes = array_column(CaisseController::comptesCaisse((int) $etab['id'], $acceptees), null, 'id');
        $compte = $comptes[(int) ($demande['compte'] ?? 0)] ?? null;
        $montant = is_numeric($demande['montant'] ?? null) ? round((float) $demande['montant'], (int) ($acceptees[$code]['decimales'] ?? 2)) : 0.0;
        $client = $this->dettes->client((int) ($demande['client'] ?? 0));
        if (!isset($acceptees[$code]) || $compte === null || !in_array($code, $compte['devises'], true) || $montant <= 0 || $montant > 1e12
            || $client === null || $client['type'] === 'personnel') {
            $this->repondre(422, ['erreur' => t('ardoise.remboursement_invalide')]);
        }
        try {
            $id = $this->dettes->rembourser([
                'client_id' => (int) $client['id'], 'etablissement_id' => (int) $etab['id'], 'compte' => $compte,
                'devise_id' => (int) $acceptees[$code]['id'], 'montant' => $montant, 'utilisateur_id' => $personne['id'],
                'appareil_id' => $appareil ? (int) $appareil['id'] : null, 'cle' => $cle,
            ]);
        } catch (\DomainException $e) {
            $this->repondre(422, ['erreur' => match ($e->getMessage()) {
                'trop' => t('ardoise.trop', ['maximum' => $this->devises->formater($this->dettes->maximum, $acceptees[$code])]),
                'rien_a_rembourser' => t('ardoise.rien_a_rembourser', ['client' => $client['nom']]),
                default => t('ardoise.remboursement_invalide'),
            }]);
        }
        $this->repondre(200, [
            'recu' => View::rendre('caisse/_recu-remboursement', $this->recu($id)),
            'client' => $this->clientJson($this->dettes->client((int) $client['id']), $acceptees),
            'csrf' => Session::jetonCsrf(),
        ]);
    }

    /** Données du reçu d'un remboursement. */
    private function recu(int $id): array
    {
        $r = $this->dettes->remboursement($id);
        $devises = array_column((new GestionDeviseRepository())->toutes(), null, 'id');
        return [
            'remboursement' => $r,
            'client' => $this->dettes->client((int) $r['client_id']),
            'compte' => Database::un('SELECT nom, type FROM comptes_tresorerie WHERE id = ?', [(int) $r['compte_id']]),
            'caissier' => Database::un('SELECT nom FROM utilisateurs WHERE id = ?', [(int) $r['utilisateur_id']]),
            'affectations' => $this->dettes->affectations($id),
            'encours' => $this->dettes->encours((int) $r['client_id']),
            'devises' => $devises,
            'etablissement' => Database::un('SELECT * FROM etablissements WHERE id = ?', [(int) $r['etablissement_id']]),
        ];
    }

    /**
     * Garanties qui attendent la réponse de cette personne, prêtes pour la caisse.
     * @return list<array{id: int, client: string, montant: string, servi: ?string, etablissement: string, date: string}>
     */
    public static function garantiesJson(int $utilisateurId): array
    {
        $devises = (new DeviseRepository())->actives();
        return array_map(static fn (array $d) => [
            'id' => (int) $d['id'], 'client' => $d['client_nom'], 'servi' => $d['servi_nom'], 'etablissement' => $d['etablissement_nom'],
            'montant' => isset($devises[(int) $d['devise_id']]) ? (new DeviseRepository())->formater((float) $d['montant'], $devises[(int) $d['devise_id']]) : $d['montant'],
            'date' => date('d/m/Y', strtotime($d['cree_le'])), 'echeance' => date('d/m/Y', strtotime($d['echeance'])),
        ], (new DetteRepository())->garantiesEnAttente($utilisateurId));
    }

    /**
     * Client pour la caisse : encours par devise (texte), retard, blocage, et ce qu'il doit en tout dans chaque
     * devise acceptée (arrondi vers le haut), pour proposer « Tout rembourser ».
     */
    private function clientJson(array $c, array $acceptees, bool $avecDettes = false): array
    {
        $actives = $this->devises->actives();
        $encours = $this->dettes->encours((int) $c['id']);
        $texte = [];
        foreach ($actives as $id => $devise) {
            if (($encours[$id] ?? 0) > 0) {
                $texte[] = $this->devises->formater($encours[$id], $devise);
            }
        }
        $du = [];
        foreach ($acceptees as $devise) {
            $total = 0.0;
            foreach ($encours as $id => $reste) {
                $total += $this->dettes->convertir($reste, $id, (int) $devise['id']) ?? 0.0;
            }
            $du[$devise['code']] = $total > 0 ? Reglement::versLeHaut($total, $devise) : 0.0;
        }
        $retard = $this->dettes->enRetard((int) $c['id']);
        $json = [
            'id' => (int) $c['id'], 'nom' => $c['nom'], 'telephone' => $c['telephone'] ? afficherTelephone($c['telephone']) : null,
            'type' => $c['type'], 'bloque' => (bool) $c['bloque'], 'retard' => $retard ? date('d/m/Y', strtotime($retard)) : null,
            'encours' => $texte, 'du' => $du,
        ];
        if ($avecDettes) {
            $json['dettes'] = array_map(fn (array $d) => [
                'id' => (int) $d['id'], 'date' => date('d/m/Y', strtotime($d['cree_le'])), 'echeance' => date('d/m/Y', strtotime($d['echeance'])),
                'enRetard' => $d['echeance'] < date('Y-m-d'),
                'reste' => isset($actives[(int) $d['devise_id']]) ? $this->devises->formater((float) $d['reste'], $actives[(int) $d['devise_id']]) : $d['reste'],
            ], $this->dettes->dettesOuvertes((int) $c['id']));
        }
        return $json;
    }

    /** @return array{personne: array, etab: array, appareil: ?array} */
    private function vendeur(?string $slug): array
    {
        $vendeur = CaisseController::vendeur($slug);
        if ($vendeur === null) {
            $this->repondre(401, ['erreur' => t('encaisser.connexion')]);
        }
        return $vendeur;
    }

    /** @param list<string> $permissions une seule suffit */
    private function exiger(array $personne, array $etab, array $permissions): void
    {
        foreach ($permissions as $permission) {
            if (Acces::a($personne['id'], $permission, (int) $etab['id'])) {
                return;
            }
        }
        $this->repondre(403, ['erreur' => t('ardoise.droit')]);
    }

    private function verifierJeton(): void
    {
        Session::demarrer();
        $jeton = $_POST['csrf'] ?? '';
        if (!is_string($jeton) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $jeton)) {
            $this->repondre(419, ['erreur' => t('encaisser.expire')]);
        }
    }

    private function repondre(int $code, array $donnees): never
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($code);
        echo json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
}
