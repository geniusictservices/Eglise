<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Acces;
use App\Core\Database;
use App\Core\Journal;

/**
 * Clients et dettes dans l'espace de gestion : listes filtrées et paginées avec leurs totaux, fiches,
 * modification d'un client, remboursements, journal des messages et rapport des créances.
 * Les écritures métier (créer une dette, rembourser, décisions du gérant) restent dans DetteRepository.
 *
 * Portée : les clients sont communs à tous les établissements (le téléphone est unique), mais leurs dettes,
 * leur encours et leurs remboursements ne se lisent que pour les établissements où la personne a la
 * permission (clients.voir sur l'écran Clients, dettes.voir sur Dettes et Remboursements, rapports.voir
 * sur le rapport). La portée est appliquée dans chaque requête, comme GestionVenteRepository.
 */
final class GestionDetteRepository
{
    public const PAR_PAGE = 50;
    public const TYPES_CLIENT = ['particulier', 'habitue', 'vip', 'entreprise', 'revendeur', 'personnel'];
    /** Types qu'on choisit dans le formulaire (« personnel » se crée seul, avec le membre du personnel lié). */
    public const TYPES_SAISIS = ['particulier', 'habitue', 'vip', 'entreprise', 'revendeur'];
    public const STATUTS = ['ouverte', 'soldee', 'annulee', 'remise', 'transferee'];
    public const STATUTS_REMBOURSEMENT = ['valide', 'annule'];
    /** Ancienneté d'une dette ouverte, en jours depuis sa création. */
    public const TRANCHES = ['0_30', '31_60', 'plus_60'];
    public const EVENEMENTS = ['rappel_client', 'rappel_responsable', 'garant_demande', 'test'];
    public const STATUTS_MESSAGE = ['envoye', 'echec', 'simule'];

    private GestionTresorerieRepository $tresorerie;
    private DetteRepository $dettes;
    /** @var array<int, ?float> devise_id → valeur d'une unité en devise de référence (taux du jour) */
    private array $facteurs = [];

    public function __construct(?GestionTresorerieRepository $tresorerie = null)
    {
        $this->tresorerie = $tresorerie ?? new GestionTresorerieRepository();
        $this->dettes = new DetteRepository($this->tresorerie);
    }

    public function dettes(): DetteRepository
    {
        return $this->dettes;
    }

    // ---------- Portée ----------

    /**
     * Condition SQL limitant une colonne etablissement_id aux établissements où la personne a la permission.
     * @return array{0: string, 1: list<int>}
     */
    public function portee(int $utilisateurId, string $permission, string $colonne): array
    {
        if (!Acces::a($utilisateurId, $permission)) {
            return ['0 = 1', []];
        }
        $ids = Acces::etablissements($utilisateurId, $permission);
        if ($ids === null) {
            return ['1 = 1', []];
        }
        if ($ids === []) {
            return ['0 = 1', []];
        }
        return [$colonne . ' IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')', array_map('intval', $ids)];
    }

    /** Établissements que la personne peut choisir (permission donnée), par nom. */
    public function etablissements(int $utilisateurId, string $permission): array
    {
        if (!Acces::a($utilisateurId, $permission)) {
            return [];
        }
        $ids = Acces::etablissements($utilisateurId, $permission);
        $tous = Database::tous('SELECT id, nom, slug, type, credit_delai_jours FROM etablissements ORDER BY nom');
        return array_values(array_filter($tous, static fn (array $e) => $ids === null || in_array((int) $e['id'], $ids, true)));
    }

    /** Membres du personnel actifs (changer le responsable d'une dette). */
    public function utilisateursActifs(): array
    {
        return Database::tous('SELECT id, nom, telephone, avatar FROM utilisateurs WHERE actif = 1 ORDER BY nom');
    }

    /** Valeur d'un montant en devise de référence au taux du jour (null si un taux manque). */
    public function enReference(float $montant, int $deviseId): ?float
    {
        $reference = $this->tresorerie->reference();
        if ($reference === null) {
            return null;
        }
        if (!array_key_exists($deviseId, $this->facteurs)) {
            $this->facteurs[$deviseId] = $this->dettes->convertir(1.0, $deviseId, (int) $reference['id']);
        }
        return $this->facteurs[$deviseId] === null ? null : $montant * $this->facteurs[$deviseId];
    }

    // ---------- Clients ----------

    /**
     * Clients filtrés (recherche nom ou téléphone, type, « avec dette », « en retard »), par nom, une page à la fois,
     * chacun avec son encours (reste par devise) et sa plus ancienne échéance dépassée, dans la portée.
     * @param array{q?: string, type?: ?string, avec_dette?: bool, retard?: bool} $filtres
     * @return array{clients: list<array>, total: int}
     */
    public function listeClients(array $filtres, int $utilisateurId, int $page, ?string $aujourdhui = null): array
    {
        $aujourdhui ??= date('Y-m-d');
        [$portee, $paramsPortee] = $this->portee($utilisateurId, 'clients.voir', 'd.etablissement_id');
        $conditions = ['1 = 1'];
        $params = [];
        $q = trim((string) ($filtres['q'] ?? ''));
        if ($q !== '') {
            // Chiffres du téléphone, sans le 0 d'un numéro local (0971… est enregistré +243971…)
            $chiffres = ltrim(preg_replace('/\D+/', '', $q) ?? '', '0');
            $conditions[] = "(c.nom LIKE ? OR c.email LIKE ? OR (? <> '' AND REPLACE(c.telephone, '+', '') LIKE ?))";
            array_push($params, '%' . $q . '%', '%' . $q . '%', $chiffres, '%' . $chiffres . '%');
        }
        if (!empty($filtres['type']) && in_array($filtres['type'], self::TYPES_CLIENT, true)) {
            $conditions[] = 'c.type = ?';
            $params[] = $filtres['type'];
        }
        if (!empty($filtres['avec_dette'])) {
            $conditions[] = "EXISTS (SELECT 1 FROM dettes d WHERE d.client_id = c.id AND d.statut = 'ouverte' AND d.reste > 0 AND $portee)";
            array_push($params, ...$paramsPortee);
        }
        if (!empty($filtres['retard'])) {
            $conditions[] = "EXISTS (SELECT 1 FROM dettes d WHERE d.client_id = c.id AND d.statut = 'ouverte' AND d.reste > 0 AND d.echeance < ? AND $portee)";
            array_push($params, $aujourdhui, ...$paramsPortee);
        }
        $where = implode(' AND ', $conditions);
        $total = (int) (Database::un('SELECT COUNT(*) AS n FROM clients c WHERE ' . $where, $params)['n'] ?? 0);
        $decalage = max(0, ($page - 1) * self::PAR_PAGE);
        $clients = Database::tous(
            'SELECT c.*, u.nom AS utilisateur_nom, u.telephone AS utilisateur_telephone
               FROM clients c LEFT JOIN utilisateurs u ON u.id = c.utilisateur_id
              WHERE ' . $where . '
           ORDER BY c.nom, c.id
              LIMIT ' . self::PAR_PAGE . ' OFFSET ' . $decalage,
            $params
        );
        $ids = array_map(static fn (array $c) => (int) $c['id'], $clients);
        $encours = $this->encoursDe($ids, $utilisateurId, 'clients.voir', $aujourdhui);
        foreach ($clients as &$client) {
            $client['encours'] = $encours[(int) $client['id']]['encours'] ?? [];
            $client['retard'] = $encours[(int) $client['id']]['retard'] ?? null;
            $client['nb_ouvertes'] = $encours[(int) $client['id']]['nb'] ?? 0;
        }
        unset($client);
        return ['clients' => $clients, 'total' => $total];
    }

    /**
     * Encours des clients donnés, dans la portée : reste par devise, nombre de dettes ouvertes, plus ancienne échéance dépassée.
     * @param list<int> $clientIds
     * @return array<int, array{encours: array<int, float>, nb: int, retard: ?string}>
     */
    public function encoursDe(array $clientIds, int $utilisateurId, string $permission, ?string $aujourdhui = null): array
    {
        if ($clientIds === []) {
            return [];
        }
        $aujourdhui ??= date('Y-m-d');
        [$portee, $paramsPortee] = $this->portee($utilisateurId, $permission, 'd.etablissement_id');
        $lignes = Database::tous(
            "SELECT d.client_id, d.devise_id, SUM(d.reste) AS reste, COUNT(*) AS nb, MIN(IF(d.echeance < ?, d.echeance, NULL)) AS retard
               FROM dettes d
              WHERE d.statut = 'ouverte' AND d.reste > 0 AND d.client_id IN (" . implode(', ', array_fill(0, count($clientIds), '?')) . ") AND $portee
           GROUP BY d.client_id, d.devise_id",
            array_merge([$aujourdhui], $clientIds, $paramsPortee)
        );
        $resultat = [];
        foreach ($lignes as $l) {
            $id = (int) $l['client_id'];
            $resultat[$id] ??= ['encours' => [], 'nb' => 0, 'retard' => null];
            $resultat[$id]['encours'][(int) $l['devise_id']] = (float) $l['reste'];
            $resultat[$id]['nb'] += (int) $l['nb'];
            if ($l['retard'] !== null && ($resultat[$id]['retard'] === null || $l['retard'] < $resultat[$id]['retard'])) {
                $resultat[$id]['retard'] = $l['retard'];
            }
        }
        return $resultat;
    }

    /** Encours total (par devise) des clients qui répondent aux filtres, dans la portée. */
    public function encoursTotal(array $filtres, int $utilisateurId, ?string $aujourdhui = null): array
    {
        $aujourdhui ??= date('Y-m-d');
        [$portee, $params] = $this->portee($utilisateurId, 'clients.voir', 'd.etablissement_id');
        $conditions = ["d.statut = 'ouverte'", 'd.reste > 0', $portee];
        $q = trim((string) ($filtres['q'] ?? ''));
        if ($q !== '') {
            // Chiffres du téléphone, sans le 0 d'un numéro local (0971… est enregistré +243971…)
            $chiffres = ltrim(preg_replace('/\D+/', '', $q) ?? '', '0');
            $conditions[] = "(c.nom LIKE ? OR c.email LIKE ? OR (? <> '' AND REPLACE(c.telephone, '+', '') LIKE ?))";
            array_push($params, '%' . $q . '%', '%' . $q . '%', $chiffres, '%' . $chiffres . '%');
        }
        if (!empty($filtres['type']) && in_array($filtres['type'], self::TYPES_CLIENT, true)) {
            $conditions[] = 'c.type = ?';
            $params[] = $filtres['type'];
        }
        if (!empty($filtres['retard'])) {
            [$porteeRetard, $paramsRetard] = $this->portee($utilisateurId, 'clients.voir', 'd2.etablissement_id');
            $conditions[] = "EXISTS (SELECT 1 FROM dettes d2 WHERE d2.client_id = c.id AND d2.statut = 'ouverte' AND d2.reste > 0 AND d2.echeance < ? AND $porteeRetard)";
            array_push($params, $aujourdhui, ...$paramsRetard);
        }
        $resultat = [];
        foreach (Database::tous(
            'SELECT d.devise_id, SUM(d.reste) AS reste, COUNT(DISTINCT d.client_id) AS clients
               FROM dettes d JOIN clients c ON c.id = d.client_id
              WHERE ' . implode(' AND ', $conditions) . ' GROUP BY d.devise_id',
            $params
        ) as $l) {
            $resultat[(int) $l['devise_id']] = (float) $l['reste'];
        }
        return $resultat;
    }

    /**
     * Fiche d'un client : le client (et la personne liée pour un client « personnel »), son encours, ses dettes,
     * ses remboursements et les messages envoyés, dans la portée de la personne.
     */
    public function ficheClient(int $id, int $utilisateurId, ?string $aujourdhui = null): ?array
    {
        $aujourdhui ??= date('Y-m-d');
        $client = Database::un(
            'SELECT c.*, u.nom AS utilisateur_nom, u.telephone AS utilisateur_telephone, u.avatar AS utilisateur_avatar, u.actif AS utilisateur_actif,
                    cp.nom AS cree_par_nom, pd.code AS plafond_code
               FROM clients c
          LEFT JOIN utilisateurs u ON u.id = c.utilisateur_id
          LEFT JOIN utilisateurs cp ON cp.id = c.cree_par
          LEFT JOIN devises pd ON pd.id = c.plafond_devise_id
              WHERE c.id = ?',
            [$id]
        );
        if ($client === null) {
            return null;
        }
        [$portee, $params] = $this->portee($utilisateurId, 'clients.voir', 'd.etablissement_id');
        $dettes = Database::tous(
            'SELECT d.*, e.nom AS etablissement_nom, v.numero AS vente_numero, r.nom AS responsable_nom, s.nom AS servi_nom, g.nom AS garant_nom
               FROM dettes d
               JOIN etablissements e ON e.id = d.etablissement_id
          LEFT JOIN ventes v ON v.id = d.vente_id
          LEFT JOIN utilisateurs r ON r.id = d.responsable_id
          LEFT JOIN utilisateurs s ON s.id = d.servi_par
          LEFT JOIN utilisateurs g ON g.id = d.garant_id
              WHERE d.client_id = ? AND ' . $portee . '
           ORDER BY d.statut = \'ouverte\' DESC, d.cree_le DESC, d.id DESC',
            array_merge([$id], $params)
        );
        [$porteeR, $paramsR] = $this->portee($utilisateurId, 'clients.voir', 'r.etablissement_id');
        $remboursements = Database::tous(
            'SELECT r.*, e.nom AS etablissement_nom, k.nom AS compte_nom, k.type AS compte_type, u.nom AS utilisateur_nom
               FROM remboursements r
               JOIN etablissements e ON e.id = r.etablissement_id
               JOIN comptes_tresorerie k ON k.id = r.compte_id
          LEFT JOIN utilisateurs u ON u.id = r.utilisateur_id
              WHERE r.client_id = ? AND ' . $porteeR . '
           ORDER BY r.cree_le DESC, r.id DESC',
            array_merge([$id], $paramsR)
        );
        $detteIds = array_map(static fn (array $d) => (int) $d['id'], $dettes);
        $encours = $this->encoursDe([$id], $utilisateurId, 'clients.voir', $aujourdhui)[$id] ?? ['encours' => [], 'nb' => 0, 'retard' => null];
        $totaux = ['credite' => [], 'rembourse' => []];
        foreach ($dettes as $d) {
            if ($d['origine_id'] === null || $client['type'] === 'personnel') {
                $totaux['credite'][(int) $d['devise_id']] = ($totaux['credite'][(int) $d['devise_id']] ?? 0.0) + (float) $d['montant'];
            }
        }
        foreach ($remboursements as $r) {
            if ($r['statut'] === 'valide') {
                $totaux['rembourse'][(int) $r['devise_id']] = ($totaux['rembourse'][(int) $r['devise_id']] ?? 0.0) + (float) $r['montant'];
            }
        }
        return [
            'client' => $client,
            'dettes' => $dettes,
            'remboursements' => $remboursements,
            'encours' => $encours['encours'],
            'retard' => $encours['retard'],
            'totaux' => $totaux,
            'messages' => $this->messagesDe($id, $detteIds),
            // Un client « personnel » : ses dettes viennent de celles mises à sa charge
            'origines' => $client['type'] === 'personnel' ? $this->originesDe($detteIds) : [],
        ];
    }

    /** @return list<array> messages envoyés au client, ou à propos de ses dettes (responsable, garant), les plus récents d'abord */
    public function messagesDe(?int $clientId, array $detteIds, int $limite = 100): array
    {
        $conditions = [];
        $params = [];
        if ($clientId !== null) {
            $conditions[] = 'm.client_id = ?';
            $params[] = $clientId;
        }
        if ($detteIds !== []) {
            $conditions[] = 'm.dette_id IN (' . implode(', ', array_fill(0, count($detteIds), '?')) . ')';
            array_push($params, ...$detteIds);
        }
        if ($conditions === []) {
            return [];
        }
        return Database::tous(
            'SELECT m.*, c.nom AS client_nom, u.nom AS utilisateur_nom FROM messages m
          LEFT JOIN clients c ON c.id = m.client_id
          LEFT JOIN utilisateurs u ON u.id = m.utilisateur_id
              WHERE ' . implode(' OR ', $conditions) . '
           ORDER BY m.cree_le DESC, m.id DESC LIMIT ' . max(1, $limite),
            $params
        );
    }

    /** @return array<int, array> dette d'origine (client) de chaque dette mise à la charge du personnel, par dette */
    private function originesDe(array $detteIds): array
    {
        if ($detteIds === []) {
            return [];
        }
        $resultat = [];
        foreach (Database::tous(
            'SELECT d.id, d.origine_id, o.client_id, c.nom AS client_nom FROM dettes d
               JOIN dettes o ON o.id = d.origine_id JOIN clients c ON c.id = o.client_id
              WHERE d.id IN (' . implode(', ', array_fill(0, count($detteIds), '?')) . ')',
            $detteIds
        ) as $l) {
            $resultat[(int) $l['id']] = $l;
        }
        return $resultat;
    }

    /**
     * Valide et normalise la saisie d'un client.
     * @return array{0: array, 1: array<string, string>} valeurs prêtes pour la base, erreurs par champ (codes)
     */
    public static function lireClient(array $post): array
    {
        $texte = static fn (string $nom): string => trim((string) preg_replace('/\s+/u', ' ', is_string($post[$nom] ?? null) ? $post[$nom] : ''));
        $v = [
            'nom' => $texte('nom'),
            'telephone' => $texte('telephone'),
            'email' => $texte('email'),
            'anniversaire' => $texte('anniversaire'),
            'langue' => in_array($post['langue'] ?? null, ['fr', 'en'], true) ? $post['langue'] : 'fr',
            'type' => is_string($post['type'] ?? null) ? $post['type'] : 'particulier',
            'notes' => trim(str_replace("\r\n", "\n", is_string($post['notes'] ?? null) ? $post['notes'] : '')),
            'plafond' => $texte('plafond'),
            'plafond_devise_id' => is_string($post['plafond_devise_id'] ?? null) && ctype_digit($post['plafond_devise_id']) ? (int) $post['plafond_devise_id'] : null,
            'canal' => ($post['canal'] ?? 'sms') === 'whatsapp' ? 'whatsapp' : 'sms',
            'relances' => !empty($post['relances']),
            'bloque' => !empty($post['bloque']),
        ];
        $erreurs = [];
        if ($v['nom'] === '') {
            $erreurs['nom'] = 'nom';
        } elseif (mb_strlen($v['nom']) > 128) {
            $erreurs['nom'] = 'nom_long';
        }
        if ($v['telephone'] !== '' && DetteRepository::normaliserTelephone($v['telephone']) === null) {
            $erreurs['telephone'] = 'telephone';
        }
        if ($v['email'] !== '' && (mb_strlen($v['email']) > 128 || filter_var($v['email'], FILTER_VALIDATE_EMAIL) === false)) {
            $erreurs['email'] = 'email';
        }
        if ($v['anniversaire'] !== '') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $v['anniversaire']);
            if ($date === false || $date->format('Y-m-d') !== $v['anniversaire'] || $date->format('Y') < '1900' || $date > new \DateTimeImmutable('today')) {
                $erreurs['anniversaire'] = 'anniversaire';
            }
        }
        if (!in_array($v['type'], self::TYPES_SAISIS, true)) {
            $erreurs['type'] = 'type';
        }
        if (mb_strlen($v['notes']) > 5000) {
            $erreurs['notes'] = 'notes_long';
        }
        return [$v, $erreurs];
    }

    /**
     * Crée un client (DetteRepository::creerClient) avec le blocage du crédit en plus.
     * @param array $v valeurs de lireClient() avec plafond déjà converti (float|null)
     * @throws \InvalidArgumentException « nom », « telephone », « telephone_pris »
     */
    public function creerClient(array $v, ?int $par): int
    {
        $id = $this->dettes->creerClient([
            'nom' => $v['nom'], 'telephone' => $v['telephone'] !== '' ? $v['telephone'] : null, 'email' => $v['email'] ?: null,
            'anniversaire' => $v['anniversaire'] ?: null, 'langue' => $v['langue'], 'type' => $v['type'], 'notes' => $v['notes'] ?: null,
            'plafond' => $v['plafond'], 'plafond_devise_id' => $v['plafond'] !== null ? $v['plafond_devise_id'] : null,
            'canal' => $v['canal'], 'relances' => $v['relances'],
        ], $par);
        if ($v['bloque']) {
            Database::executer('UPDATE clients SET bloque = 1 WHERE id = ?', [$id]);
        }
        return $id;
    }

    /**
     * Modifie un client (pas un client « personnel », tenu à jour depuis la fiche de l'utilisateur) :
     * téléphone normalisé (+243…) et unique.
     * @param array $v valeurs de lireClient() avec plafond déjà converti (float|null)
     * @throws \InvalidArgumentException « introuvable », « personnel », « nom », « telephone », « telephone_pris »
     */
    public function modifierClient(int $id, array $v, ?int $par): void
    {
        $client = $this->dettes->client($id);
        if ($client === null) {
            throw new \InvalidArgumentException('introuvable');
        }
        if ($client['type'] === 'personnel') {
            throw new \InvalidArgumentException('personnel');
        }
        $nom = trim((string) preg_replace('/\s+/u', ' ', $v['nom']));
        if ($nom === '' || mb_strlen($nom) > 128) {
            throw new \InvalidArgumentException('nom');
        }
        $telephone = null;
        if ($v['telephone'] !== '') {
            $telephone = DetteRepository::normaliserTelephone($v['telephone']);
            if ($telephone === null) {
                throw new \InvalidArgumentException('telephone');
            }
            $autre = $this->dettes->clientParTelephone($telephone);
            if ($autre !== null && (int) $autre['id'] !== $id) {
                throw new \InvalidArgumentException('telephone_pris');
            }
        }
        if (!in_array($v['type'], self::TYPES_SAISIS, true)) {
            throw new \InvalidArgumentException('type');
        }
        Database::executer(
            'UPDATE clients SET nom = ?, telephone = ?, email = ?, anniversaire = ?, langue = ?, type = ?, notes = ?,
                    plafond = ?, plafond_devise_id = ?, canal = ?, relances = ?, bloque = ? WHERE id = ?',
            [
                $nom, $telephone, $v['email'] ?: null, $v['anniversaire'] ?: null, $v['langue'], $v['type'], $v['notes'] ?: null,
                $v['plafond'] !== null ? GestionTresorerieRepository::decimal((float) $v['plafond']) : null,
                $v['plafond'] !== null ? $v['plafond_devise_id'] : null,
                $v['canal'], $v['relances'] ? 1 : 0, $v['bloque'] ? 1 : 0, $id,
            ]
        );
        $changes = [];
        foreach (['nom' => $nom, 'telephone' => $telephone, 'bloque' => (int) $v['bloque'], 'plafond' => $v['plafond']] as $cle => $valeur) {
            if ((string) $client[$cle] !== (string) $valeur && !($cle === 'plafond' && $client[$cle] !== null && $valeur !== null && abs((float) $client[$cle] - (float) $valeur) < 1e-6)) {
                $changes[$cle] = $valeur;
            }
        }
        Journal::ecrire('client_modifie', $nom, ['client' => $id] + $changes, $par);
    }

    // ---------- Dettes ----------

    /**
     * Conditions de la liste des dettes : portée, période (création), statut, établissement, responsable, servi par,
     * garant en attente, échéance (retard, à venir), devise, client.
     * @return array{0: string, 1: list<mixed>}
     */
    private function conditionsDettes(array $f, int $utilisateurId, string $aujourdhui): array
    {
        [$portee, $params] = $this->portee($utilisateurId, 'dettes.voir', 'd.etablissement_id');
        $conditions = [$portee, 'd.cree_le BETWEEN ? AND ?'];
        array_push($params, $f['debut'], $f['fin']);
        if (!empty($f['statut']) && in_array($f['statut'], self::STATUTS, true)) {
            $conditions[] = 'd.statut = ?';
            $params[] = $f['statut'];
        }
        foreach (['etablissement' => 'd.etablissement_id', 'responsable' => 'd.responsable_id', 'servi' => 'd.servi_par', 'devise' => 'd.devise_id', 'client' => 'd.client_id'] as $cle => $colonne) {
            if (!empty($f[$cle])) {
                $conditions[] = $colonne . ' = ?';
                $params[] = (int) $f[$cle];
            }
        }
        if (!empty($f['garant'])) {
            $conditions[] = "d.garant_statut = 'en_attente' AND d.statut = 'ouverte'";
        }
        if (($f['echeance'] ?? null) === 'retard') {
            $conditions[] = "d.statut = 'ouverte' AND d.echeance < ?";
            $params[] = $aujourdhui;
        } elseif (($f['echeance'] ?? null) === 'a_venir') {
            $conditions[] = "d.statut = 'ouverte' AND d.echeance >= ?";
            $params[] = $aujourdhui;
        }
        return [implode(' AND ', $conditions), $params];
    }

    /** @return array{dettes: list<array>, total: int} */
    public function listeDettes(array $filtres, int $utilisateurId, int $page, ?string $aujourdhui = null): array
    {
        $aujourdhui ??= date('Y-m-d');
        [$where, $params] = $this->conditionsDettes($filtres, $utilisateurId, $aujourdhui);
        $total = (int) (Database::un('SELECT COUNT(*) AS n FROM dettes d WHERE ' . $where, $params)['n'] ?? 0);
        $decalage = max(0, ($page - 1) * self::PAR_PAGE);
        $dettes = Database::tous(
            'SELECT d.*, c.nom AS client_nom, c.type AS client_type, c.telephone AS client_telephone, e.nom AS etablissement_nom,
                    v.numero AS vente_numero, s.nom AS servi_nom, r.nom AS responsable_nom, r.avatar AS responsable_avatar, g.nom AS garant_nom
               FROM dettes d
               JOIN clients c ON c.id = d.client_id
               JOIN etablissements e ON e.id = d.etablissement_id
          LEFT JOIN ventes v ON v.id = d.vente_id
          LEFT JOIN utilisateurs s ON s.id = d.servi_par
          LEFT JOIN utilisateurs r ON r.id = d.responsable_id
          LEFT JOIN utilisateurs g ON g.id = d.garant_id
              WHERE ' . $where . '
           ORDER BY d.cree_le DESC, d.id DESC
              LIMIT ' . self::PAR_PAGE . ' OFFSET ' . $decalage,
            $params
        );
        return ['dettes' => $dettes, 'total' => $total];
    }

    /**
     * Totaux des dettes qui répondent aux filtres : par devise (nombre, montant accordé, reste dû des dettes ouvertes,
     * dont en retard) et ancienneté des dettes ouvertes (reste par tranche et par devise, depuis la création).
     * @return array{devises: array<int, array{nb: int, montant: float, reste: float, retard: float, ouvertes: int}>, tranches: array<string, array<int, float>>, nb: int}
     */
    public function totauxDettes(array $filtres, int $utilisateurId, ?string $aujourdhui = null): array
    {
        $aujourdhui ??= date('Y-m-d');
        [$where, $params] = $this->conditionsDettes($filtres, $utilisateurId, $aujourdhui);
        $devises = [];
        $nb = 0;
        foreach (Database::tous(
            "SELECT d.devise_id, COUNT(*) AS nb, SUM(d.montant) AS montant,
                    SUM(IF(d.statut = 'ouverte', d.reste, 0)) AS reste,
                    SUM(IF(d.statut = 'ouverte' AND d.echeance < ?, d.reste, 0)) AS retard,
                    SUM(d.statut = 'ouverte' AND d.reste > 0) AS ouvertes
               FROM dettes d WHERE $where GROUP BY d.devise_id",
            array_merge([$aujourdhui], $params)
        ) as $l) {
            $devises[(int) $l['devise_id']] = [
                'nb' => (int) $l['nb'], 'montant' => (float) $l['montant'], 'reste' => (float) $l['reste'],
                'retard' => (float) $l['retard'], 'ouvertes' => (int) $l['ouvertes'],
            ];
            $nb += (int) $l['nb'];
        }
        return ['devises' => $devises, 'tranches' => $this->tranches($where, $params, $aujourdhui), 'nb' => $nb];
    }

    /** @return array<string, array<int, float>> reste des dettes ouvertes par tranche d'ancienneté puis par devise */
    private function tranches(string $where, array $params, string $aujourdhui): array
    {
        $tranches = array_fill_keys(self::TRANCHES, []);
        foreach (Database::tous(
            "SELECT d.devise_id,
                    CASE WHEN DATEDIFF(?, DATE(d.cree_le)) <= 30 THEN '0_30' WHEN DATEDIFF(?, DATE(d.cree_le)) <= 60 THEN '31_60' ELSE 'plus_60' END AS tranche,
                    SUM(d.reste) AS reste
               FROM dettes d WHERE $where AND d.statut = 'ouverte' AND d.reste > 0
           GROUP BY d.devise_id, tranche",
            array_merge([$aujourdhui, $aujourdhui], $params)
        ) as $l) {
            $tranches[$l['tranche']][(int) $l['devise_id']] = (float) $l['reste'];
        }
        return $tranches;
    }

    /** Personnes liées aux dettes visibles : responsables et serveurs (filtres de la liste). */
    public function personnesDettes(int $utilisateurId): array
    {
        [$portee, $params] = $this->portee($utilisateurId, 'dettes.voir', 'd.etablissement_id');
        $liste = static fn (string $colonne): array => Database::tous(
            "SELECT u.id, u.nom FROM utilisateurs u WHERE EXISTS (SELECT 1 FROM dettes d WHERE d.$colonne = u.id AND $portee) ORDER BY u.nom",
            $params
        );
        return ['responsables' => $liste('responsable_id'), 'servis' => $liste('servi_par')];
    }

    /** Devises présentes dans les dettes visibles (filtre « devise »). */
    public function devisesDettes(int $utilisateurId): array
    {
        [$portee, $params] = $this->portee($utilisateurId, 'dettes.voir', 'd.etablissement_id');
        return array_map('intval', array_column(Database::tous("SELECT DISTINCT d.devise_id FROM dettes d WHERE $portee", $params), 'devise_id'));
    }

    /**
     * Fiche d'une dette visible par la personne (dettes.voir) : client, personnes liées, ticket, remboursements
     * reçus, messages envoyés, dette d'origine ou dette créée par la mise à la charge. null hors de la portée.
     */
    public function ficheDette(int $id, int $utilisateurId): ?array
    {
        [$portee, $params] = $this->portee($utilisateurId, 'dettes.voir', 'd.etablissement_id');
        $dette = Database::un(
            'SELECT d.*, c.nom AS client_nom, c.type AS client_type, c.telephone AS client_telephone, c.utilisateur_id AS client_utilisateur_id,
                    e.nom AS etablissement_nom, v.numero AS vente_numero, v.statut AS vente_statut,
                    s.nom AS servi_nom, s.avatar AS servi_avatar, a.nom AS accorde_nom, a.avatar AS accorde_avatar,
                    g.nom AS garant_nom, g.avatar AS garant_avatar, r.nom AS responsable_nom, r.avatar AS responsable_avatar, r.actif AS responsable_actif,
                    cl.nom AS cloturee_par_nom
               FROM dettes d
               JOIN clients c ON c.id = d.client_id
               JOIN etablissements e ON e.id = d.etablissement_id
          LEFT JOIN ventes v ON v.id = d.vente_id
          LEFT JOIN utilisateurs s ON s.id = d.servi_par
          LEFT JOIN utilisateurs a ON a.id = d.accorde_par
          LEFT JOIN utilisateurs g ON g.id = d.garant_id
          LEFT JOIN utilisateurs r ON r.id = d.responsable_id
          LEFT JOIN utilisateurs cl ON cl.id = d.cloturee_par
              WHERE d.id = ? AND ' . $portee,
            array_merge([$id], $params)
        );
        if ($dette === null) {
            return null;
        }
        $remboursements = Database::tous(
            'SELECT r.*, rd.montant AS affecte, k.nom AS compte_nom, k.type AS compte_type, u.nom AS utilisateur_nom
               FROM remboursement_dettes rd
               JOIN remboursements r ON r.id = rd.remboursement_id
               JOIN comptes_tresorerie k ON k.id = r.compte_id
          LEFT JOIN utilisateurs u ON u.id = r.utilisateur_id
              WHERE rd.dette_id = ?
           ORDER BY r.cree_le, r.id',
            [$id]
        );
        $origine = $dette['origine_id'] !== null ? Database::un(
            'SELECT d.id, d.client_id, d.montant, d.devise_id, d.cree_le, c.nom AS client_nom, v.numero AS vente_numero, d.vente_id
               FROM dettes d JOIN clients c ON c.id = d.client_id LEFT JOIN ventes v ON v.id = d.vente_id WHERE d.id = ?',
            [(int) $dette['origine_id']]
        ) : null;
        $transfert = Database::un(
            'SELECT d.id, d.client_id, d.montant, d.reste, d.statut, d.devise_id, c.nom AS client_nom, r.nom AS responsable_nom
               FROM dettes d JOIN clients c ON c.id = d.client_id LEFT JOIN utilisateurs r ON r.id = d.responsable_id
              WHERE d.origine_id = ? ORDER BY d.id DESC LIMIT 1',
            [$id]
        );
        return [
            'dette' => $dette,
            'remboursements' => $remboursements,
            'origine' => $origine,
            'transfert' => $transfert,
            'messages' => $this->messagesDe(null, [$id]),
            'aDesRemboursements' => $this->dettes->aDesRemboursements($id),
        ];
    }

    // ---------- Remboursements ----------

    /** @return array{0: string, 1: list<mixed>} */
    private function conditionsRemboursements(array $f, int $utilisateurId): array
    {
        [$portee, $params] = $this->portee($utilisateurId, 'dettes.voir', 'r.etablissement_id');
        $conditions = [$portee, 'r.cree_le BETWEEN ? AND ?'];
        array_push($params, $f['debut'], $f['fin']);
        foreach (['etablissement' => 'r.etablissement_id', 'utilisateur' => 'r.utilisateur_id', 'client' => 'r.client_id'] as $cle => $colonne) {
            if (!empty($f[$cle])) {
                $conditions[] = $colonne . ' = ?';
                $params[] = (int) $f[$cle];
            }
        }
        if (!empty($f['moyen']) && in_array($f['moyen'], GestionTresorerieRepository::TYPES, true)) {
            $conditions[] = 'r.moyen = ?';
            $params[] = $f['moyen'];
        }
        if (!empty($f['statut']) && in_array($f['statut'], self::STATUTS_REMBOURSEMENT, true)) {
            $conditions[] = 'r.statut = ?';
            $params[] = $f['statut'];
        }
        return [implode(' AND ', $conditions), $params];
    }

    /** @return array{remboursements: list<array>, total: int} */
    public function listeRemboursements(array $filtres, int $utilisateurId, int $page): array
    {
        [$where, $params] = $this->conditionsRemboursements($filtres, $utilisateurId);
        $total = (int) (Database::un('SELECT COUNT(*) AS n FROM remboursements r WHERE ' . $where, $params)['n'] ?? 0);
        $decalage = max(0, ($page - 1) * self::PAR_PAGE);
        $liste = Database::tous(
            'SELECT r.*, c.nom AS client_nom, e.nom AS etablissement_nom, k.nom AS compte_nom, k.type AS compte_type,
                    u.nom AS utilisateur_nom, u.avatar AS utilisateur_avatar,
                    (SELECT COUNT(*) FROM remboursement_dettes rd WHERE rd.remboursement_id = r.id) AS nb_dettes
               FROM remboursements r
               JOIN clients c ON c.id = r.client_id
               JOIN etablissements e ON e.id = r.etablissement_id
               JOIN comptes_tresorerie k ON k.id = r.compte_id
          LEFT JOIN utilisateurs u ON u.id = r.utilisateur_id
              WHERE ' . $where . '
           ORDER BY r.cree_le DESC, r.id DESC
              LIMIT ' . self::PAR_PAGE . ' OFFSET ' . $decalage,
            $params
        );
        return ['remboursements' => $liste, 'total' => $total];
    }

    /**
     * Totaux des remboursements valides qui répondent aux filtres (le filtre statut est ignoré) : par devise et par
     * type de compte, équivalent en devise de référence (au taux du remboursement), nombre d'annulés.
     * @return array{nb: int, devises: array<int, array<string, float>>, reference: float, annules: int}
     */
    public function totauxRemboursements(array $filtres, int $utilisateurId): array
    {
        [$where, $params] = $this->conditionsRemboursements(['statut' => 'valide'] + $filtres, $utilisateurId);
        $devises = [];
        $nb = 0;
        $reference = 0.0;
        foreach (Database::tous(
            'SELECT r.devise_id, r.moyen, COUNT(*) AS nb, SUM(r.montant) AS montant, COALESCE(SUM(r.contre_valeur), 0) AS reference
               FROM remboursements r WHERE ' . $where . ' GROUP BY r.devise_id, r.moyen',
            $params
        ) as $l) {
            $devises[(int) $l['devise_id']][$l['moyen']] = (float) $l['montant'];
            $nb += (int) $l['nb'];
            $reference += (float) $l['reference'];
        }
        [$whereA, $paramsA] = $this->conditionsRemboursements(['statut' => 'annule'] + $filtres, $utilisateurId);
        $annules = (int) (Database::un('SELECT COUNT(*) AS n FROM remboursements r WHERE ' . $whereA, $paramsA)['n'] ?? 0);
        return ['nb' => $nb, 'devises' => $devises, 'reference' => $reference, 'annules' => $annules];
    }

    /** Personnes qui ont encaissé des remboursements visibles (filtre « utilisateur »). */
    public function encaisseurs(int $utilisateurId): array
    {
        [$portee, $params] = $this->portee($utilisateurId, 'dettes.voir', 'r.etablissement_id');
        return Database::tous(
            "SELECT u.id, u.nom FROM utilisateurs u WHERE EXISTS (SELECT 1 FROM remboursements r WHERE r.utilisateur_id = u.id AND $portee) ORDER BY u.nom",
            $params
        );
    }

    /** Fiche d'un remboursement visible (dettes.voir) : client, compte, qui, répartition sur les dettes, mouvements. */
    public function ficheRemboursement(int $id, int $utilisateurId): ?array
    {
        [$portee, $params] = $this->portee($utilisateurId, 'dettes.voir', 'r.etablissement_id');
        $r = Database::un(
            'SELECT r.*, c.nom AS client_nom, c.telephone AS client_telephone, e.nom AS etablissement_nom, k.nom AS compte_nom, k.type AS compte_type,
                    u.nom AS utilisateur_nom, u.avatar AS utilisateur_avatar, a.nom AS annule_par_nom, ap.nom AS appareil_nom
               FROM remboursements r
               JOIN clients c ON c.id = r.client_id
               JOIN etablissements e ON e.id = r.etablissement_id
               JOIN comptes_tresorerie k ON k.id = r.compte_id
          LEFT JOIN utilisateurs u ON u.id = r.utilisateur_id
          LEFT JOIN utilisateurs a ON a.id = r.annule_par
          LEFT JOIN appareils_caisse ap ON ap.id = r.appareil_id
              WHERE r.id = ? AND ' . $portee,
            array_merge([$id], $params)
        );
        if ($r === null) {
            return null;
        }
        $affectations = Database::tous(
            'SELECT rd.montant, d.id AS dette_id, d.devise_id, d.montant AS dette_montant, d.reste, d.statut, d.echeance, d.cree_le AS dette_le,
                    d.vente_id, v.numero AS vente_numero, e.nom AS etablissement_nom
               FROM remboursement_dettes rd
               JOIN dettes d ON d.id = rd.dette_id
               JOIN etablissements e ON e.id = d.etablissement_id
          LEFT JOIN ventes v ON v.id = d.vente_id
              WHERE rd.remboursement_id = ? ORDER BY d.cree_le, d.id',
            [$id]
        );
        $mouvements = Database::tous(
            "SELECT m.*, k.nom AS compte_nom FROM mouvements_tresorerie m JOIN comptes_tresorerie k ON k.id = m.compte_id
              WHERE m.nature = 'remboursement' AND m.source_id = ? ORDER BY m.date_operation, m.id",
            [$id]
        );
        return ['remboursement' => $r, 'affectations' => $affectations, 'mouvements' => $mouvements];
    }

    /**
     * Dettes ouvertes d'un client qu'on peut rembourser ici (dettes.rembourser pour leur établissement), les plus anciennes d'abord.
     * @return list<array>
     */
    public function dettesARembourser(int $clientId, int $utilisateurId): array
    {
        [$portee, $params] = $this->portee($utilisateurId, 'dettes.rembourser', 'd.etablissement_id');
        return Database::tous(
            "SELECT d.*, e.nom AS etablissement_nom, v.numero AS vente_numero FROM dettes d
               JOIN etablissements e ON e.id = d.etablissement_id LEFT JOIN ventes v ON v.id = d.vente_id
              WHERE d.client_id = ? AND d.statut = 'ouverte' AND d.reste > 0 AND $portee
           ORDER BY d.cree_le, d.id",
            array_merge([$clientId], $params)
        );
    }

    /**
     * Comptes actifs où l'on peut encaisser pour ces établissements : les leurs et les comptes communs.
     * @param ?list<int> $etablissementIds null = tous
     * @return array<int, array> forme de GestionTresorerieRepository::comptes()
     */
    public function comptesPour(?array $etablissementIds): array
    {
        return array_filter($this->tresorerie->comptesActifs(), static fn (array $c) =>
            $c['etablissement_id'] === null || $etablissementIds === null || in_array((int) $c['etablissement_id'], $etablissementIds, true));
    }

    /** Clients trouvés pour un remboursement (nom ou téléphone), avec dettes ouvertes d'abord. */
    public function chercherClients(string $q, int $utilisateurId, int $limite = 20): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $liste = $this->listeClients(['q' => $q], $utilisateurId, 1);
        $clients = array_slice($liste['clients'], 0, $limite);
        $encours = $this->encoursDe(array_map(static fn (array $c) => (int) $c['id'], $clients), $utilisateurId, 'dettes.rembourser');
        foreach ($clients as &$c) {
            $c['encours'] = $encours[(int) $c['id']]['encours'] ?? [];
            $c['retard'] = $encours[(int) $c['id']]['retard'] ?? null;
        }
        unset($c);
        usort($clients, static fn (array $a, array $b) => [$b['encours'] !== [], $a['nom']] <=> [$a['encours'] !== [], $b['nom']]);
        return $clients;
    }

    // ---------- Garanties ----------

    public function nombreGarantiesEnAttente(int $utilisateurId): int
    {
        return (int) (Database::un(
            "SELECT COUNT(*) AS n FROM dettes WHERE garant_id = ? AND garant_statut = 'en_attente' AND statut = 'ouverte'",
            [$utilisateurId]
        )['n'] ?? 0);
    }

    /** @return list<array> garanties que la personne a déjà acceptées ou refusées (les 20 dernières) */
    public function garantiesRepondues(int $utilisateurId, int $limite = 20): array
    {
        return Database::tous(
            "SELECT d.*, c.nom AS client_nom, s.nom AS servi_nom, e.nom AS etablissement_nom
               FROM dettes d JOIN clients c ON c.id = d.client_id JOIN etablissements e ON e.id = d.etablissement_id
          LEFT JOIN utilisateurs s ON s.id = d.servi_par
              WHERE d.garant_id = ? AND d.garant_statut IN ('accepte', 'refuse')
           ORDER BY d.garant_repondu_le DESC, d.id DESC LIMIT " . max(1, $limite),
            [$utilisateurId]
        );
    }

    // ---------- Messages ----------

    /** @return array{messages: list<array>, total: int, compte: array<string, int>} */
    public function journalMessages(array $f, int $page): array
    {
        $conditions = ['m.cree_le BETWEEN ? AND ?'];
        $params = [$f['debut'], $f['fin']];
        foreach (['statut' => self::STATUTS_MESSAGE, 'evenement' => self::EVENEMENTS, 'canal' => ['sms', 'whatsapp']] as $cle => $permis) {
            if (!empty($f[$cle]) && in_array($f[$cle], $permis, true)) {
                $conditions[] = 'm.' . $cle . ' = ?';
                $params[] = $f[$cle];
            }
        }
        $where = implode(' AND ', $conditions);
        $total = (int) (Database::un('SELECT COUNT(*) AS n FROM messages m WHERE ' . $where, $params)['n'] ?? 0);
        // Nombre par statut avec les autres filtres (les puces de statut montrent chacune son compte)
        $sansStatut = $conditions;
        $paramsSansStatut = $params;
        if (!empty($f['statut']) && in_array($f['statut'], self::STATUTS_MESSAGE, true)) {
            $i = array_search('m.statut = ?', $sansStatut, true);
            unset($sansStatut[$i]);
            array_splice($paramsSansStatut, $i + 1, 1);
        }
        $compte = array_fill_keys(self::STATUTS_MESSAGE, 0);
        foreach (Database::tous('SELECT m.statut, COUNT(*) AS n FROM messages m WHERE ' . implode(' AND ', $sansStatut) . ' GROUP BY m.statut', $paramsSansStatut) as $l) {
            $compte[$l['statut']] = (int) $l['n'];
        }
        $decalage = max(0, ($page - 1) * self::PAR_PAGE);
        $messages = Database::tous(
            'SELECT m.*, c.nom AS client_nom, u.nom AS utilisateur_nom FROM messages m
          LEFT JOIN clients c ON c.id = m.client_id
          LEFT JOIN utilisateurs u ON u.id = m.utilisateur_id
              WHERE ' . $where . '
           ORDER BY m.cree_le DESC, m.id DESC
              LIMIT ' . self::PAR_PAGE . ' OFFSET ' . $decalage,
            $params
        );
        return ['messages' => $messages, 'total' => $total, 'compte' => $compte];
    }

    // ---------- Rapport des créances ----------

    /**
     * Créances ouvertes dans la portée (rapports.voir), éventuellement d'un établissement : totaux par devise et
     * en devise de référence, ancienneté, par responsable, par serveur, par établissement, par devise,
     * clients qui doivent le plus, garanties en attente.
     */
    public function rapportCreances(int $utilisateurId, ?int $etablissementId, ?string $aujourdhui = null): array
    {
        $aujourdhui ??= date('Y-m-d');
        [$portee, $params] = $this->portee($utilisateurId, 'rapports.voir', 'd.etablissement_id');
        $where = "d.statut = 'ouverte' AND d.reste > 0 AND $portee";
        if ($etablissementId !== null) {
            $where .= ' AND d.etablissement_id = ?';
            $params[] = $etablissementId;
        }
        $lignes = Database::tous(
            "SELECT d.id, d.client_id, d.etablissement_id, d.devise_id, d.reste, d.echeance, d.cree_le, d.responsable_id, d.servi_par,
                    d.garant_id, d.garant_statut, d.montant,
                    c.nom AS client_nom, c.type AS client_type, e.nom AS etablissement_nom,
                    r.nom AS responsable_nom, r.avatar AS responsable_avatar, s.nom AS servi_nom, s.avatar AS servi_avatar, g.nom AS garant_nom,
                    DATEDIFF(?, DATE(d.cree_le)) AS age
               FROM dettes d
               JOIN clients c ON c.id = d.client_id
               JOIN etablissements e ON e.id = d.etablissement_id
          LEFT JOIN utilisateurs r ON r.id = d.responsable_id
          LEFT JOIN utilisateurs s ON s.id = d.servi_par
          LEFT JOIN utilisateurs g ON g.id = d.garant_id
              WHERE $where
           ORDER BY d.cree_le, d.id",
            array_merge([$aujourdhui], $params)
        );

        $vide = static fn (): array => ['nb' => 0, 'devises' => [], 'reference' => 0.0, 'sans_taux' => false, 'retard' => 0.0];
        $ajouter = function (array &$groupe, array $d, bool $enRetard): void {
            $reste = (float) $d['reste'];
            $groupe['nb']++;
            $groupe['devises'][(int) $d['devise_id']] = ($groupe['devises'][(int) $d['devise_id']] ?? 0.0) + $reste;
            $ref = $this->enReference($reste, (int) $d['devise_id']);
            if ($ref === null) {
                $groupe['sans_taux'] = true;
            } else {
                $groupe['reference'] += $ref;
                if ($enRetard) {
                    $groupe['retard'] += $ref;
                }
            }
        };
        $total = $vide();
        $tranches = array_fill_keys(self::TRANCHES, $vide());
        $retard = $vide();
        $groupes = ['responsable' => [], 'servi' => [], 'etablissement' => [], 'devise' => [], 'client' => []];
        foreach ($lignes as $d) {
            $enRetard = $d['echeance'] < $aujourdhui;
            $ajouter($total, $d, $enRetard);
            $age = (int) $d['age'];
            $ajouter($tranches[$age <= 30 ? '0_30' : ($age <= 60 ? '31_60' : 'plus_60')], $d, $enRetard);
            if ($enRetard) {
                $ajouter($retard, $d, true);
            }
            $cles = [
                'responsable' => [(string) ($d['responsable_id'] ?? '0'), ['nom' => $d['responsable_nom'], 'avatar' => $d['responsable_avatar'], 'id' => $d['responsable_id']]],
                'servi' => [(string) ($d['servi_par'] ?? '0'), ['nom' => $d['servi_nom'], 'avatar' => $d['servi_avatar'], 'id' => $d['servi_par']]],
                'etablissement' => [(string) $d['etablissement_id'], ['nom' => $d['etablissement_nom'], 'id' => $d['etablissement_id']]],
                'devise' => [(string) $d['devise_id'], ['id' => $d['devise_id']]],
                'client' => [(string) $d['client_id'], ['nom' => $d['client_nom'], 'type' => $d['client_type'], 'id' => $d['client_id']]],
            ];
            foreach ($cles as $groupe => [$cle, $infos]) {
                $groupes[$groupe][$cle] ??= $vide() + $infos;
                $ajouter($groupes[$groupe][$cle], $d, $enRetard);
            }
        }
        foreach ($groupes as $nom => &$liste) {
            uasort($liste, static fn (array $a, array $b) => [$b['reference'], $b['nb']] <=> [$a['reference'], $a['nb']]);
            $liste = array_values($liste);
        }
        unset($liste);
        $groupes['client'] = array_slice($groupes['client'], 0, 10);

        $garanties = array_values(array_filter($lignes, static fn (array $d) => $d['garant_statut'] === 'en_attente'));
        return [
            'total' => $total, 'retard' => $retard, 'tranches' => $tranches, 'groupes' => $groupes,
            'garanties' => $garanties, 'nb' => count($lignes),
        ];
    }
}
