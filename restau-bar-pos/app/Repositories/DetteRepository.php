<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Journal;
use App\Core\Reglement;

/**
 * Clients et dettes (cahier des charges, section 7), utilisé par la caisse, la gestion et les relances.
 *
 * Une dette engage la personne qui a servi le client (responsable par défaut). Le serveur peut désigner
 * un garant (un autre agent, le gérant, le promoteur…) : tant que le garant n'a pas confirmé, le serveur
 * reste responsable ; s'il accepte, il devient le responsable ; s'il refuse, rien ne change.
 * Après l'échéance, le gérant peut mettre la dette à la charge du responsable : la dette du client est
 * close (« transferee ») et une dette du même reste s'ouvre au nom du responsable (client « personnel »),
 * à retenir sur sa paie.
 *
 * Un remboursement est fait dans une devise, entre dans un compte de trésorerie et se répartit sur les
 * dettes ouvertes du client, les plus anciennes d'abord, converti au taux du jour dans la devise de chaque dette.
 */
final class DetteRepository
{
    private GestionTresorerieRepository $tresorerie;

    /** Maximum remboursable (dans la devise payée) après un refus « trop ». */
    public float $maximum = 0.0;

    public function __construct(?GestionTresorerieRepository $tresorerie = null)
    {
        $this->tresorerie = $tresorerie ?? new GestionTresorerieRepository();
    }

    // ---------- Clients ----------

    /** Numéro au format international (+243…) ; un numéro local (0970…, 970…) est pris en RDC. */
    public static function normaliserTelephone(?string $saisie, string $indicatif = '243'): ?string
    {
        $chiffres = preg_replace('/\D+/', '', (string) $saisie) ?? '';
        if ($chiffres === '') {
            return null;
        }
        if (str_starts_with(trim((string) $saisie), '+') || str_starts_with($chiffres, '00')) {
            $chiffres = ltrim(str_starts_with($chiffres, '00') ? substr($chiffres, 2) : $chiffres, '0');
        } elseif (str_starts_with($chiffres, $indicatif) && strlen($chiffres) > 10) {
            // déjà avec l'indicatif, sans le +
        } else {
            $chiffres = $indicatif . ltrim($chiffres, '0');
        }
        return strlen($chiffres) >= 8 && strlen($chiffres) <= 15 ? '+' . $chiffres : null;
    }

    public function client(int $id): ?array
    {
        return Database::un('SELECT * FROM clients WHERE id = ?', [$id]);
    }

    public function clientParTelephone(string $telephone): ?array
    {
        return Database::un('SELECT * FROM clients WHERE telephone = ?', [$telephone]);
    }

    /**
     * Recherche à la caisse : nom (début d'un mot) ou chiffres du téléphone ; clients actifs, sans le personnel.
     * Chaque client vient avec son encours (reste par devise) et son retard éventuel.
     * @return list<array>
     */
    public function rechercher(string $q, int $limite = 8): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        // Un numéro local (0971…) se cherche sans son zéro : le numéro est gardé au format +243971…
        $chiffres = ltrim(preg_replace('/\D+/', '', $q) ?? '', '0');
        $lignes = Database::tous(
            "SELECT * FROM clients
              WHERE actif = 1 AND type <> 'personnel'
                AND (nom LIKE ? OR nom LIKE ? OR (? <> '' AND REPLACE(telephone, '+', '') LIKE ?))
              ORDER BY nom LIMIT " . max(1, min(50, $limite)),
            [$q . '%', '% ' . $q . '%', $chiffres, '%' . $chiffres . '%']
        );
        return array_map(fn (array $c) => $c + ['encours' => $this->encours((int) $c['id']), 'retard' => $this->enRetard((int) $c['id'])], $lignes);
    }

    /**
     * @param array{nom: string, telephone?: ?string, email?: ?string, anniversaire?: ?string, langue?: string, type?: string,
     *              notes?: ?string, plafond?: ?float, plafond_devise_id?: ?int, canal?: string, relances?: bool, utilisateur_id?: ?int} $d
     * @throws \InvalidArgumentException nom vide, téléphone invalide ou déjà utilisé
     */
    public function creerClient(array $d, ?int $par): int
    {
        $nom = trim(preg_replace('/\s+/u', ' ', (string) ($d['nom'] ?? '')) ?? '');
        if ($nom === '' || mb_strlen($nom) > 128) {
            throw new \InvalidArgumentException('nom');
        }
        $telephone = null;
        if (trim((string) ($d['telephone'] ?? '')) !== '') {
            $telephone = self::normaliserTelephone($d['telephone']);
            if ($telephone === null) {
                throw new \InvalidArgumentException('telephone');
            }
            if ($this->clientParTelephone($telephone) !== null) {
                throw new \InvalidArgumentException('telephone_pris');
            }
        }
        Database::executer(
            'INSERT INTO clients (nom, telephone, email, anniversaire, langue, type, utilisateur_id, notes, plafond, plafond_devise_id, canal, relances, cree_par)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $nom, $telephone, ($d['email'] ?? null) ?: null, ($d['anniversaire'] ?? null) ?: null,
                in_array($d['langue'] ?? 'fr', ['fr', 'en'], true) ? ($d['langue'] ?? 'fr') : 'fr',
                $d['type'] ?? 'particulier', $d['utilisateur_id'] ?? null, ($d['notes'] ?? null) ?: null,
                isset($d['plafond']) && $d['plafond'] !== null ? GestionTresorerieRepository::decimal((float) $d['plafond']) : null,
                $d['plafond_devise_id'] ?? null, ($d['canal'] ?? 'sms') === 'whatsapp' ? 'whatsapp' : 'sms',
                ($d['relances'] ?? true) ? 1 : 0, $par,
            ]
        );
        $id = Database::dernierId();
        Journal::ecrire('client_cree', $nom, ['client' => $id], $par);
        return $id;
    }

    /** Client « personnel » d'un utilisateur, créé au besoin (dettes mises à sa charge, consommations du personnel). */
    public function clientDuPersonnel(int $utilisateurId): int
    {
        $client = Database::un('SELECT id FROM clients WHERE utilisateur_id = ?', [$utilisateurId]);
        if ($client !== null) {
            return (int) $client['id'];
        }
        $u = Database::un('SELECT nom, telephone, langue FROM utilisateurs WHERE id = ?', [$utilisateurId]);
        if ($u === null) {
            throw new \InvalidArgumentException('utilisateur');
        }
        // Le numéro reste sur la fiche de l'utilisateur : les relances du personnel le lisent là
        Database::executer(
            "INSERT INTO clients (nom, langue, type, utilisateur_id, relances) VALUES (?, ?, 'personnel', ?, 0)",
            [$u['nom'], $u['langue'] ?: 'fr', $utilisateurId]
        );
        return Database::dernierId();
    }

    // ---------- Encours ----------

    /** @return list<array> dettes ouvertes d'un client, les plus anciennes d'abord */
    public function dettesOuvertes(int $clientId, bool $verrouiller = false): array
    {
        return Database::tous(
            "SELECT * FROM dettes WHERE client_id = ? AND statut = 'ouverte' ORDER BY cree_le, id" . ($verrouiller ? ' FOR UPDATE' : ''),
            [$clientId]
        );
    }

    /** @return array<int, float> reste dû par devise (devise_id → montant) */
    public function encours(int $clientId): array
    {
        $resultat = [];
        foreach (Database::tous("SELECT devise_id, SUM(reste) AS reste FROM dettes WHERE client_id = ? AND statut = 'ouverte' GROUP BY devise_id", [$clientId]) as $l) {
            $resultat[(int) $l['devise_id']] = (float) $l['reste'];
        }
        return $resultat;
    }

    /** Plus ancienne échéance dépassée (Y-m-d), ou null. */
    public function enRetard(int $clientId, ?string $aujourdhui = null): ?string
    {
        $l = Database::un(
            "SELECT MIN(echeance) AS e FROM dettes WHERE client_id = ? AND statut = 'ouverte' AND echeance < ?",
            [$clientId, $aujourdhui ?? date('Y-m-d')]
        );
        return $l['e'] ?? null;
    }

    /** Montant d'une devise vers une autre au taux du jour (null si un taux manque). */
    public function convertir(float $montant, int $deDevise, int $versDevise, ?string $date = null): ?float
    {
        if ($deDevise === $versDevise) {
            return $montant;
        }
        $devises = $this->tresorerie->devises();
        if (!isset($devises[$deDevise], $devises[$versDevise])) {
            return null;
        }
        $date ??= date('Y-m-d H:i:s');
        $de = $this->tresorerie->tauxA($devises[$deDevise], $date);
        $vers = $this->tresorerie->tauxA($devises[$versDevise], $date);
        return $de && $vers ? $montant * $vers / $de : null;
    }

    /**
     * Peut-on mettre ce montant à crédit ? Liste des empêchements (vide = oui) :
     * « bloque » (crédit bloqué par le gérant), « retard » (une dette a passé son échéance),
     * « plafond » (encours + montant au-dessus du plafond). Le gérant (dettes.gerer) peut passer outre.
     * @return list<array{code: string, date?: string, plafond?: float, devise_id?: int, encours?: float}>
     */
    public function empechements(array $client, int $deviseId, float $montant): array
    {
        $problemes = [];
        if ($client['bloque']) {
            $problemes[] = ['code' => 'bloque'];
        }
        if (($date = $this->enRetard((int) $client['id'])) !== null) {
            $problemes[] = ['code' => 'retard', 'date' => $date];
        }
        if ($client['plafond'] !== null && $client['plafond_devise_id'] !== null) {
            $vers = (int) $client['plafond_devise_id'];
            $total = $this->convertir($montant, $deviseId, $vers) ?? 0.0;
            foreach ($this->encours((int) $client['id']) as $devise => $reste) {
                $total += $this->convertir($reste, $devise, $vers) ?? 0.0;
            }
            if ($total > (float) $client['plafond'] + 1e-6) {
                $problemes[] = ['code' => 'plafond', 'plafond' => (float) $client['plafond'], 'devise_id' => $vers, 'encours' => $total - ($this->convertir($montant, $deviseId, $vers) ?? 0.0)];
            }
        }
        return $problemes;
    }

    // ---------- Création ----------

    /**
     * Nouvelle dette. À appeler dans la transaction de la vente (VenteRepository::enregistrer).
     * Le garant, s'il y en a un autre que le serveur, est « en attente » : le serveur reste responsable
     * jusqu'à sa réponse (bin/relances.php le prévient par SMS ou WhatsApp).
     *
     * @param array{client_id: int, etablissement_id: int, vente_id?: ?int, origine_id?: ?int, devise_id: int, montant: float,
     *              echeance: string, servi_par: ?int, accorde_par: ?int, garant_id?: ?int, responsable_id?: ?int,
     *              note?: ?string, date?: string} $d
     */
    public function creer(array $d): int
    {
        $garant = isset($d['garant_id']) && $d['garant_id'] && (int) $d['garant_id'] !== (int) $d['servi_par'] ? (int) $d['garant_id'] : null;
        Database::executer(
            'INSERT INTO dettes (client_id, etablissement_id, vente_id, origine_id, devise_id, montant, reste, echeance,
                                 servi_par, accorde_par, garant_id, garant_statut, responsable_id, note, cree_le)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $d['client_id'], $d['etablissement_id'], $d['vente_id'] ?? null, $d['origine_id'] ?? null, $d['devise_id'],
                GestionTresorerieRepository::decimal($d['montant']), GestionTresorerieRepository::decimal($d['montant']), $d['echeance'],
                $d['servi_par'], $d['accorde_par'], $garant, $garant ? 'en_attente' : null,
                $d['responsable_id'] ?? $d['servi_par'], ($d['note'] ?? null) ? mb_substr($d['note'], 0, 255) : null,
                $d['date'] ?? date('Y-m-d H:i:s'),
            ]
        );
        $id = Database::dernierId();
        Journal::ecrire('dette_creee', 'dette ' . $id, ['client' => $d['client_id'], 'montant' => $d['montant'], 'garant' => $garant], $d['accorde_par']);
        return $id;
    }

    public function dette(int $id, bool $verrouiller = false): ?array
    {
        return Database::un('SELECT * FROM dettes WHERE id = ?' . ($verrouiller ? ' FOR UPDATE' : ''), [$id]);
    }

    /** @return list<array> dettes de la vente */
    public function dettesDeVente(int $venteId): array
    {
        return Database::tous(
            'SELECT d.*, c.nom AS client_nom, c.telephone AS client_telephone FROM dettes d JOIN clients c ON c.id = d.client_id WHERE d.vente_id = ? ORDER BY d.id',
            [$venteId]
        );
    }

    // ---------- Garant ----------

    /** @return list<array> garanties qui attendent la réponse de cette personne */
    public function garantiesEnAttente(int $utilisateurId): array
    {
        return Database::tous(
            "SELECT d.*, c.nom AS client_nom, s.nom AS servi_nom, e.nom AS etablissement_nom
               FROM dettes d JOIN clients c ON c.id = d.client_id JOIN etablissements e ON e.id = d.etablissement_id
               LEFT JOIN utilisateurs s ON s.id = d.servi_par
              WHERE d.garant_id = ? AND d.garant_statut = 'en_attente' AND d.statut = 'ouverte'
              ORDER BY d.cree_le, d.id",
            [$utilisateurId]
        );
    }

    /**
     * Réponse du garant : seul le garant désigné répond, une fois, tant que la dette est ouverte.
     * @throws \DomainException
     */
    public function repondreGarant(int $detteId, int $utilisateurId, bool $accepte): void
    {
        $this->transaction(function () use ($detteId, $utilisateurId, $accepte) {
            $dette = $this->dette($detteId, true);
            if ($dette === null || (int) $dette['garant_id'] !== $utilisateurId || $dette['garant_statut'] !== 'en_attente' || $dette['statut'] !== 'ouverte') {
                throw new \DomainException('garantie_introuvable');
            }
            Database::executer(
                'UPDATE dettes SET garant_statut = ?, garant_repondu_le = NOW(), responsable_id = ? WHERE id = ?',
                [$accepte ? 'accepte' : 'refuse', $accepte ? $utilisateurId : $dette['responsable_id'], $detteId]
            );
            Journal::ecrire($accepte ? 'garantie_acceptee' : 'garantie_refusee', 'dette ' . $detteId, [], $utilisateurId);
        });
    }

    // ---------- Remboursements ----------

    /**
     * Remboursement d'un client, réparti sur ses dettes ouvertes (les plus anciennes d'abord, ou seulement
     * celles indiquées), converti au taux du jour dans la devise de chaque dette. Entre dans le compte choisi.
     * Un montant plus grand que ce que le client doit est refusé (avec le maximum dans la devise payée).
     *
     * @param array{client_id: int, etablissement_id: int, compte: array{id: int, type: string}, devise_id: int, montant: float,
     *              utilisateur_id: ?int, appareil_id?: ?int, cle?: ?string, dettes?: ?list<int>, date?: string, session_id?: ?int} $r
     * @return int identifiant du remboursement (le même pour une clé déjà enregistrée)
     * @throws \DomainException « montant », « rien_a_rembourser », « trop » (maximum dans $this->maximum), « taux »
     */
    public function rembourser(array $r): int
    {
        return $this->transaction(function () use ($r) {
            if (!empty($r['cle']) && ($deja = Database::un('SELECT id FROM remboursements WHERE cle = ? FOR UPDATE', [$r['cle']])) !== null) {
                return (int) $deja['id'];
            }
            $date = $r['date'] ?? date('Y-m-d H:i:s');
            $devises = $this->tresorerie->devises();
            $devisePaiement = $devises[(int) $r['devise_id']] ?? null;
            if ($devisePaiement === null || $r['montant'] <= 0) {
                throw new \DomainException('montant');
            }
            $dettes = $this->dettesOuvertes((int) $r['client_id'], true);
            if (!empty($r['dettes'])) {
                $choisies = array_map('intval', $r['dettes']);
                $dettes = array_values(array_filter($dettes, static fn (array $d) => in_array((int) $d['id'], $choisies, true)));
            }
            if ($dettes === []) {
                throw new \DomainException('rien_a_rembourser');
            }

            // Répartition : le paiement (dans sa devise) couvre les dettes une à une
            $disponible = (float) $r['montant'];
            $affectations = [];
            $maximum = 0.0;
            foreach ($dettes as $dette) {
                $deviseDette = $devises[(int) $dette['devise_id']] ?? ['decimales' => 2, 'arrondi' => 0];
                $resteEnPaiement = $this->convertir((float) $dette['reste'], (int) $dette['devise_id'], (int) $r['devise_id'], $date);
                if ($resteEnPaiement === null) {
                    throw new \DomainException('taux');
                }
                $maximum += $resteEnPaiement;
                if ($disponible <= 1e-9) {
                    continue;
                }
                $pris = min($disponible, $resteEnPaiement);
                $enDette = $this->convertir($pris, (int) $r['devise_id'], (int) $dette['devise_id'], $date) ?? 0.0;
                // Quelques francs d'écart d'arrondi soldent la dette (moins d'un demi-pas de sa devise)
                $nouveauReste = (float) $dette['reste'] - $enDette;
                if ($nouveauReste < Reglement::pas($deviseDette) / 2) {
                    $enDette = (float) $dette['reste'];
                }
                $affectations[] = ['dette' => $dette, 'montant' => round($enDette, 3)];
                $disponible -= $pris;
            }
            $pasPaiement = Reglement::pas($devisePaiement);
            if ($disponible > $pasPaiement / 2) {
                $this->maximum = Reglement::versLeHaut($maximum, $devisePaiement);
                throw new \DomainException('trop');
            }

            $taux = $this->tresorerie->tauxA($devisePaiement, $date);
            Database::executer(
                'INSERT INTO remboursements (client_id, etablissement_id, compte_id, moyen, devise_id, montant, taux, contre_valeur, utilisateur_id, appareil_id, cle, cree_le)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $r['client_id'], $r['etablissement_id'], (int) $r['compte']['id'], $r['compte']['type'], $r['devise_id'],
                    GestionTresorerieRepository::decimal((float) $r['montant']),
                    $taux !== null ? GestionDeviseRepository::decimal($taux) : null,
                    $taux ? GestionTresorerieRepository::decimal(round((float) $r['montant'] / $taux, 3)) : null,
                    $r['utilisateur_id'], $r['appareil_id'] ?? null, ($r['cle'] ?? null) ?: null, $date,
                ]
            );
            $id = Database::dernierId();
            if (!empty($r['session_id'])) {
                Database::executer('UPDATE remboursements SET session_id = ? WHERE id = ?', [(int) $r['session_id'], $id]);
            }
            foreach ($affectations as $a) {
                Database::executer('INSERT INTO remboursement_dettes (remboursement_id, dette_id, montant) VALUES (?, ?, ?)',
                    [$id, (int) $a['dette']['id'], GestionTresorerieRepository::decimal($a['montant'])]);
                $reste = max(0.0, round((float) $a['dette']['reste'] - $a['montant'], 3));
                Database::executer('UPDATE dettes SET reste = ?, statut = ?, cloturee_le = ? WHERE id = ?', [
                    GestionTresorerieRepository::decimal($reste), $reste > 0 ? 'ouverte' : 'soldee', $reste > 0 ? null : $date, (int) $a['dette']['id'],
                ]);
            }
            $client = $this->client((int) $r['client_id']);
            $this->tresorerie->ajouterMouvement(['id' => (int) $r['compte']['id']], 'entree', (float) $r['montant'], 'remboursement', $id, $date,
                'Remboursement ' . ($client['nom'] ?? ''), $r['utilisateur_id'], (int) $r['devise_id']);
            Journal::ecrire('remboursement', $client['nom'] ?? null, ['remboursement' => $id, 'montant' => $r['montant'], 'devise' => $devisePaiement['code']], $r['utilisateur_id'], $r['appareil_id'] ?? null);
            return $id;
        });
    }

    public function remboursement(int $id): ?array
    {
        return Database::un('SELECT * FROM remboursements WHERE id = ?', [$id]);
    }

    /** @return list<array> répartition d'un remboursement sur les dettes */
    public function affectations(int $remboursementId): array
    {
        return Database::tous(
            'SELECT rd.*, d.devise_id, d.vente_id, d.cree_le AS dette_le, d.reste FROM remboursement_dettes rd JOIN dettes d ON d.id = rd.dette_id
              WHERE rd.remboursement_id = ? ORDER BY d.cree_le, d.id',
            [$remboursementId]
        );
    }

    /**
     * Annuler un remboursement saisi par erreur (gérant) : les dettes retrouvent leur reste, l'argent ressort du compte.
     * @throws \DomainException
     */
    public function annulerRemboursement(int $id, int $par, string $motif): void
    {
        $motif = trim($motif);
        if ($motif === '') {
            throw new \DomainException('motif');
        }
        $this->transaction(function () use ($id, $par, $motif) {
            $r = Database::un('SELECT * FROM remboursements WHERE id = ? FOR UPDATE', [$id]);
            if ($r === null || $r['statut'] !== 'valide') {
                throw new \DomainException('remboursement_introuvable');
            }
            foreach ($this->affectations($id) as $a) {
                $dette = $this->dette((int) $a['dette_id'], true);
                if (!in_array($dette['statut'], ['ouverte', 'soldee'], true)) {
                    throw new \DomainException('dette_close');
                }
                Database::executer("UPDATE dettes SET reste = reste + ?, statut = 'ouverte', cloturee_le = NULL WHERE id = ?",
                    [GestionTresorerieRepository::decimal((float) $a['montant']), (int) $a['dette_id']]);
            }
            $maintenant = date('Y-m-d H:i:s');
            Database::executer("UPDATE remboursements SET statut = 'annule', annule_le = ?, annule_par = ?, motif_annulation = ? WHERE id = ?",
                [$maintenant, $par, mb_substr($motif, 0, 255), $id]);
            $this->tresorerie->ajouterMouvement(['id' => (int) $r['compte_id']], 'sortie', (float) $r['montant'], 'remboursement', $id, $maintenant,
                'Annulation du remboursement ' . $id, $par, (int) $r['devise_id']);
            Journal::ecrire('remboursement_annule', 'remboursement ' . $id, ['motif' => mb_substr($motif, 0, 255)], $par);
        });
    }

    // ---------- Décisions du gérant ----------

    /** @throws \DomainException */
    public function changerResponsable(int $detteId, int $nouveau, int $par, string $motif): void
    {
        $this->transaction(function () use ($detteId, $nouveau, $par, $motif) {
            $dette = $this->dette($detteId, true);
            if ($dette === null || $dette['statut'] !== 'ouverte') {
                throw new \DomainException('dette_close');
            }
            if (Database::un('SELECT id FROM utilisateurs WHERE id = ? AND actif = 1', [$nouveau]) === null) {
                throw new \DomainException('utilisateur');
            }
            Database::executer('UPDATE dettes SET responsable_id = ? WHERE id = ?', [$nouveau, $detteId]);
            Journal::ecrire('dette_responsable', 'dette ' . $detteId, ['ancien' => $dette['responsable_id'], 'nouveau' => $nouveau, 'motif' => mb_substr(trim($motif), 0, 255)], $par);
        });
    }

    /**
     * Mettre une dette échue à la charge de son responsable : la dette du client est close (« transferee »)
     * et une dette du même reste s'ouvre au nom du responsable, à retenir sur sa paie.
     * @return int la nouvelle dette
     * @throws \DomainException « dette_close », « pas_echue », « sans_responsable », « motif »
     */
    public function mettreALaCharge(int $detteId, int $par, string $motif, ?string $aujourdhui = null): int
    {
        $motif = trim($motif);
        if ($motif === '') {
            throw new \DomainException('motif');
        }
        return $this->transaction(function () use ($detteId, $par, $motif, $aujourdhui) {
            $dette = $this->dette($detteId, true);
            if ($dette === null || $dette['statut'] !== 'ouverte' || (float) $dette['reste'] <= 0) {
                throw new \DomainException('dette_close');
            }
            if ($dette['echeance'] >= ($aujourdhui ?? date('Y-m-d'))) {
                throw new \DomainException('pas_echue');
            }
            if ($dette['responsable_id'] === null) {
                throw new \DomainException('sans_responsable');
            }
            $responsable = (int) $dette['responsable_id'];
            $client = $this->client((int) $dette['client_id']);
            if ((int) ($client['utilisateur_id'] ?? 0) === $responsable) {
                throw new \DomainException('deja_au_personnel');
            }
            $maintenant = date('Y-m-d H:i:s');
            Database::executer("UPDATE dettes SET statut = 'transferee', motif = ?, cloturee_par = ?, cloturee_le = ? WHERE id = ?",
                [mb_substr($motif, 0, 255), $par, $maintenant, $detteId]);
            $nouvelle = $this->creer([
                'client_id' => $this->clientDuPersonnel($responsable), 'etablissement_id' => (int) $dette['etablissement_id'],
                'vente_id' => null, 'origine_id' => $detteId, 'devise_id' => (int) $dette['devise_id'], 'montant' => (float) $dette['reste'],
                'echeance' => date('Y-m-d'), 'servi_par' => $dette['servi_par'] !== null ? (int) $dette['servi_par'] : null,
                'accorde_par' => $par, 'responsable_id' => $responsable,
                'note' => 'Dette de ' . ($client['nom'] ?? '') . ' mise à charge', 'date' => $maintenant,
            ]);
            Journal::ecrire('dette_a_charge', 'dette ' . $detteId, ['responsable' => $responsable, 'nouvelle' => $nouvelle, 'motif' => mb_substr($motif, 0, 255)], $par);
            return $nouvelle;
        });
    }

    /**
     * Annuler (erreur, la dette n'aurait pas dû exister : seulement sans remboursement) ou remettre
     * (geste commercial, sur le reste) une dette, avec un motif.
     * @throws \DomainException « motif », « dette_close », « deja_rembourse »
     */
    public function cloturer(int $detteId, string $statut, int $par, string $motif): void
    {
        $motif = trim($motif);
        if ($motif === '' || !in_array($statut, ['annulee', 'remise'], true)) {
            throw new \DomainException('motif');
        }
        $this->transaction(function () use ($detteId, $statut, $par, $motif) {
            $dette = $this->dette($detteId, true);
            if ($dette === null || $dette['statut'] !== 'ouverte') {
                throw new \DomainException('dette_close');
            }
            if ($statut === 'annulee' && $this->aDesRemboursements($detteId)) {
                throw new \DomainException('deja_rembourse');
            }
            Database::executer('UPDATE dettes SET statut = ?, motif = ?, cloturee_par = ?, cloturee_le = NOW() WHERE id = ?',
                [$statut, mb_substr($motif, 0, 255), $par, $detteId]);
            Journal::ecrire($statut === 'annulee' ? 'dette_annulee' : 'dette_remise', 'dette ' . $detteId,
                ['reste' => (float) $dette['reste'], 'motif' => mb_substr($motif, 0, 255)], $par);
        });
    }

    public function aDesRemboursements(int $detteId): bool
    {
        return Database::un(
            "SELECT 1 FROM remboursement_dettes rd JOIN remboursements r ON r.id = rd.remboursement_id WHERE rd.dette_id = ? AND r.statut = 'valide' LIMIT 1",
            [$detteId]
        ) !== null;
    }

    /**
     * Annulation d'une vente : ses dettes sont annulées avec elle, à condition qu'aucune n'ait été remboursée
     * ni mise à la charge de quelqu'un. À appeler dans la transaction de l'annulation.
     * @throws \DomainException « vente_avec_remboursement »
     */
    public function annulerPourVente(int $venteId, int $par, string $motif): void
    {
        $dettes = Database::tous('SELECT * FROM dettes WHERE vente_id = ? FOR UPDATE', [$venteId]);
        foreach ($dettes as $dette) {
            if ($dette['statut'] !== 'ouverte' || $this->aDesRemboursements((int) $dette['id'])) {
                throw new \DomainException('vente_avec_remboursement');
            }
        }
        foreach ($dettes as $dette) {
            Database::executer("UPDATE dettes SET statut = 'annulee', motif = ?, cloturee_par = ?, cloturee_le = NOW() WHERE id = ?",
                [mb_substr('Vente annulée : ' . trim($motif), 0, 255), $par, (int) $dette['id']]);
        }
    }

    // ---------- Relances ----------

    /**
     * Étape de relance atteinte aujourd'hui pour une échéance : la plus avancée des étapes déjà passées
     * (-1 = la veille, 1 = le lendemain, 7, 30…). Une étape négative ne vaut que jusqu'à l'échéance.
     * Si le script n'a pas tourné pendant quelques jours, seule la dernière étape atteinte part.
     */
    public static function etapeDuJour(string $echeance, string $aujourdhui, array $etapes): ?int
    {
        $jours = (int) round((strtotime($aujourdhui . ' 12:00') - strtotime($echeance . ' 12:00')) / 86400);
        $atteinte = null;
        sort($etapes);
        foreach ($etapes as $etape) {
            if ($jours >= $etape && ($etape >= 0 || $jours < 0)) {
                $atteinte = $etape;
            }
        }
        return $atteinte;
    }

    public static function libelleEtape(int $etape): string
    {
        return $etape < 0 ? 'J' . $etape : 'J+' . $etape;
    }

    /**
     * Dettes ouvertes à relancer aujourd'hui, avec leur client, leur responsable et l'étape atteinte.
     * @return list<array>
     */
    public function aRelancer(string $aujourdhui, array $etapes): array
    {
        if ($etapes === []) {
            return [];
        }
        $avance = -min(0, min($etapes));
        $lignes = Database::tous(
            "SELECT d.*, c.nom AS client_nom, c.telephone AS client_telephone, c.langue AS client_langue, c.canal AS client_canal,
                    c.relances AS client_relances, c.type AS client_type, c.utilisateur_id AS client_utilisateur,
                    r.nom AS responsable_nom, r.telephone AS responsable_telephone, r.langue AS responsable_langue,
                    e.nom AS etablissement_nom
               FROM dettes d JOIN clients c ON c.id = d.client_id JOIN etablissements e ON e.id = d.etablissement_id
               LEFT JOIN utilisateurs r ON r.id = d.responsable_id AND r.actif = 1
              WHERE d.statut = 'ouverte' AND d.reste > 0 AND d.echeance <= DATE_ADD(?, INTERVAL ? DAY)
              ORDER BY d.echeance, d.id",
            [$aujourdhui, $avance]
        );
        $resultat = [];
        foreach ($lignes as $ligne) {
            $etape = self::etapeDuJour($ligne['echeance'], $aujourdhui, $etapes);
            if ($etape !== null) {
                $resultat[] = $ligne + ['etape' => $etape];
            }
        }
        return $resultat;
    }

    /** @return list<array> garanties en attente pas encore annoncées au garant */
    public function garantiesAAnnoncer(): array
    {
        return Database::tous(
            "SELECT d.*, c.nom AS client_nom, g.nom AS garant_nom, g.telephone AS garant_telephone, g.langue AS garant_langue,
                    s.nom AS servi_nom, e.nom AS etablissement_nom
               FROM dettes d JOIN clients c ON c.id = d.client_id JOIN etablissements e ON e.id = d.etablissement_id
               JOIN utilisateurs g ON g.id = d.garant_id AND g.actif = 1
               LEFT JOIN utilisateurs s ON s.id = d.servi_par
              WHERE d.garant_statut = 'en_attente' AND d.statut = 'ouverte'
                AND NOT EXISTS (SELECT 1 FROM messages m WHERE m.dette_id = d.id AND m.evenement = 'garant_demande')
              ORDER BY d.id"
        );
    }

    /** @template T @param callable(): T $f @return T */
    private function transaction(callable $f): mixed
    {
        $pdo = Database::pdo();
        if ($pdo->inTransaction()) {
            return $f();
        }
        $pdo->beginTransaction();
        try {
            $resultat = $f();
            $pdo->commit();
            return $resultat;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
