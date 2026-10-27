<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Acces;
use App\Core\CaissePartagee;
use App\Core\Database;
use App\Core\Journal;
use App\Core\Plateforme;
use App\Core\Reglement;
use App\Core\Session;
use App\Core\View;
use App\Repositories\EtablissementRepository;
use App\Repositories\SessionCaisseRepository;
use App\Repositories\VenteRepository;

/**
 * Mode hors ligne de la caisse (cahier des charges, sections 15 et 17) : la caisse vend sans réseau, les ventes
 * payées attendent sur l'appareil (IndexedDB, caisse-hors-ligne.js) et partent au retour du réseau par
 * /caisse/encaisser avec « hors_ligne » ; la clé unique de chaque ticket empêche tout double comptage.
 *
 * Qui a vendu : chaque personne reçoit, quand elle est en ligne sur un appareil, une clé hors ligne (un secret
 * aléatoire, jamais le PIN). L'appareil signe avec elle chaque vente faite sans réseau (HMAC-SHA256 du texte exact
 * de la vente) et le serveur vérifie la signature à l'envoi. Sur une caisse partagée, ce secret n'est gardé que
 * chiffré par une clé tirée du PIN (PBKDF2) : changer de personne sans réseau demande donc le bon PIN, et le
 * déchiffrement réussi est la vérification locale du PIN (5 essais, puis il faut le réseau).
 *
 * Ce fichier sert aussi le service worker (/caisse/sw.js, portée /caisse), l'état du réseau (/caisse/etat), les
 * évènements de la caisse partagée vécus sans réseau (PIN faux ou juste) et la page de secours sans réseau.
 */
final class CaisseHorsLigneController
{
    /** Une vente faite sans réseau n'est jamais datée de plus de 72 h avant son arrivée (sinon : heure d'arrivée, notée). */
    public const HEURES_MAX = 72;
    /** Avance tolérée de l'horloge de l'appareil, au-delà : heure d'arrivée (notée). */
    private const AVANCE_MINUTES = 5;

    // ---------------------------------------------------------------------------------------------
    // Envoi d'une vente faite sans réseau (appelé par CaisseController::encaisser)
    // ---------------------------------------------------------------------------------------------

    /**
     * Lit le bloc « hors_ligne » d'une vente envoyée au retour du réseau : appareil ou session qui l'envoie,
     * personne qui a vendu (signature de sa clé hors ligne, ou la personne de la session), heure bornée.
     * Répond lui-même (et s'arrête) si l'envoi ne peut pas être attribué : 401 sans appareil ni session,
     * 403 « signature » si la personne n'est ni signée ni celle de la session (la caisse garde la vente).
     *
     * @return array{vendeur: array{personne: array, etab: array, appareil: ?array}, moment: \DateTimeImmutable,
     *               heure_appareil: ?string, notes: list<string>, numero: ?string, total: array<string, float>,
     *               rendu: ?array{devise: string, montant: float}, session: ?int}
     */
    public static function lireEnvoi(array $demande, string $texte, string $signature): array
    {
        $h = $demande['hors_ligne'];
        $slug = is_string($demande['etab'] ?? null) ? $demande['etab'] : '';
        $appareil = CaissePartagee::appareil();
        $etablissements = new EtablissementRepository();
        if ($appareil !== null) {
            // Caisse partagée : l'appareil autorisé par un gérant envoie (même verrouillé) ; la vente est de son établissement
            $etab = $etablissements->parSlug($appareil['slug']);
            if ($etab === null || ($slug !== '' && $slug !== $etab['slug'])) {
                self::repondre(422, ['erreur' => t('encaisser.illisible')]);
            }
            $session = CaissePartagee::caissier();
            $sessionId = $session !== null ? (int) $session['id'] : null;
        } else {
            $utilisateur = Session::utilisateur();
            $etab = $slug !== '' ? $etablissements->parSlug($slug) : null;
            if ($utilisateur === null) {
                self::repondre(401, ['erreur' => t('horsligne.reconnecter'), 'code' => 'connexion']);
            }
            if ($etab === null || !in_array('caisse', CaisseController::postesPermis((int) $utilisateur['id'], (int) $etab['id']), true)) {
                self::repondre(403, ['erreur' => t('encaisser.droit')]);
            }
            $sessionId = (int) $utilisateur['id'];
        }
        $vendeurId = (int) ($h['vendeur'] ?? 0);
        $notes = [];

        // Heure de la vente : celle de l'appareil (corrigée de son décalage), jamais dans le futur ni plus vieille que 72 h
        $maintenant = new \DateTimeImmutable();
        $heure = is_string($h['heure'] ?? null) ? self::lireHeure($h['heure']) : null;
        $moment = $heure ?? $maintenant;
        if ($heure === null || $heure > $maintenant->modify('+' . self::AVANCE_MINUTES . ' minutes')
            || $heure < $maintenant->modify('-' . self::HEURES_MAX . ' hours')) {
            $moment = $maintenant;
            $notes[] = 'heure';
        } elseif ($heure > $maintenant) {
            $moment = $maintenant;
        }

        // Qui a vendu : la signature de sa clé hors ligne (sur cet appareil, valable à l'heure de la vente), sinon la session
        $cle = (int) ($h['cle_id'] ?? 0) > 0 ? Database::un('SELECT * FROM caisse_cles_hors_ligne WHERE id = ?', [(int) $h['cle_id']]) : null;
        $signee = $cle !== null && (int) $cle['utilisateur_id'] === $vendeurId && (int) $cle['etablissement_id'] === (int) $etab['id']
            && ($cle['appareil_id'] === null ? $appareil === null : $appareil !== null && (int) $cle['appareil_id'] === (int) $appareil['id'])
            && ($cle['revoquee_le'] === null || strtotime($cle['revoquee_le']) >= $moment->getTimestamp())
            && preg_match('/^[0-9a-f]{64}$/', $signature) === 1
            && hash_equals(hash_hmac('sha256', $texte, (string) hex2bin($cle['secret'])), $signature);
        if (!$signee && $vendeurId !== $sessionId) {
            $nom = Database::un('SELECT nom FROM utilisateurs WHERE id = ?', [$vendeurId])['nom'] ?? '';
            self::repondre(403, ['erreur' => t('horsligne.signature', ['nom' => $nom]), 'code' => 'signature']);
        }
        if ($signee) {
            Database::executer('UPDATE caisse_cles_hors_ligne SET dernier_usage = NOW() WHERE id = ?', [(int) $cle['id']]);
        }
        $personne = Database::un('SELECT id, nom, actif FROM utilisateurs WHERE id = ?', [$vendeurId]);
        if ($personne === null) {
            self::repondre(403, ['erreur' => t('horsligne.signature', ['nom' => '']), 'code' => 'signature']);
        }
        if (!$personne['actif']) {
            $notes[] = 'droit';
        }
        // Abonnement en lecture seule ou suspendu (console) : la caisse n'aurait pas dû s'ouvrir ; la vente a eu lieu
        if (Plateforme::active() && in_array(Plateforme::etat()['code'] ?? '', ['lecture_seule', 'suspendu'], true)) {
            $notes[] = 'abonnement';
        }

        $total = [];
        foreach (is_array($h['total'] ?? null) ? $h['total'] : [] as $code => $montant) {
            if (is_string($code) && is_numeric($montant)) {
                $total[$code] = (float) $montant;
            }
        }
        $rendu = is_array($h['rendu'] ?? null) && is_string($h['rendu']['devise'] ?? null) && is_numeric($h['rendu']['montant'] ?? null)
            ? ['devise' => $h['rendu']['devise'], 'montant' => (float) $h['rendu']['montant']] : null;
        $numero = is_string($h['numero'] ?? null) && preg_match('/^[A-Za-z0-9-]{1,40}$/', $h['numero']) ? $h['numero'] : null;
        return [
            'vendeur' => ['personne' => ['id' => (int) $personne['id'], 'nom' => $personne['nom']], 'etab' => $etab, 'appareil' => $appareil],
            'moment' => $moment, 'heure_appareil' => $heure?->format('Y-m-d H:i:s'), 'notes' => $notes, 'numero' => $numero,
            'total' => $total, 'rendu' => $rendu, 'session' => (int) ($h['session'] ?? 0) ?: null,
        ];
    }

    /** « 2026-10-27T14:05:09.120Z » ou « 2026-10-27 16:05:09 » (heure locale de l'instance). */
    private static function lireHeure(string $texte): ?\DateTimeImmutable
    {
        try {
            $d = new \DateTimeImmutable($texte);
        } catch (\Exception) {
            return null;
        }
        // En heure locale de l'instance, comme toutes les dates de la base
        return $d->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    /**
     * Session de caisse de la personne ouverte à l'heure de la vente (même clôturée depuis : la vente y est rattachée,
     * car l'argent était dans sa caisse). Sans session alors que l'établissement les demande : « session », sans refus.
     */
    public static function sessionAuMoment(array $etab, array $personne, \DateTimeImmutable $moment, ?int $indice, array &$notes): ?array
    {
        $heure = $moment->format('Y-m-d H:i:s');
        $session = Database::un(
            'SELECT * FROM sessions_caisse WHERE etablissement_id = ? AND utilisateur_id = ? AND ouverte_le <= ? AND (fermee_le IS NULL OR fermee_le >= ?)
              ORDER BY id = ? DESC, ouverte_le DESC LIMIT 1',
            [(int) $etab['id'], (int) $personne['id'], $heure, $heure, (int) $indice]
        );
        if ($session === null && SessionCaisseRepository::obligatoires($etab)) {
            $notes[] = 'session';
        }
        if ($session !== null && $session['statut'] === 'fermee') {
            $notes[] = 'session_close';
        }
        return $session;
    }

    /** Pointage obligatoire et personne non pointée à l'heure de la vente ? (en ligne, refus 409 ; hors ligne, noté) */
    public static function pointageManquant(array $etab, array $personne, \DateTimeImmutable $moment): bool
    {
        if (empty($etab['pointage_obligatoire']) || !\App\Repositories\PersonnelRepository::installe() || !Plateforme::moduleInclus('personnel')) {
            return false;
        }
        $employe = (new \App\Repositories\PersonnelRepository())->parUtilisateur((int) $personne['id']);
        if ($employe === null || !$employe['actif']) {
            return false;
        }
        $heure = $moment->format('Y-m-d H:i:s');
        return Database::un(
            'SELECT id FROM pointages WHERE employe_id = ? AND etablissement_id = ? AND arrivee <= ? AND (depart IS NULL OR depart >= ?) AND arrivee >= ? LIMIT 1',
            [(int) $employe['id'], (int) $etab['id'], $heure, $heure, $moment->modify('-' . \App\Repositories\PointageRepository::HEURES_MAX . ' hours')->format('Y-m-d H:i:s')]
        ) === null;
    }

    /**
     * Format d'une ligne vendue sans réseau, pour le serveur : celui de la carte s'il existe encore (disponible ou pas,
     * avec ses prix de la grille de l'heure de la vente) ; sinon, le format de la base avec les prix de l'appareil.
     * Une différence de prix, une rupture ou un format retiré sont notés.
     * @param array{format: array, produit: array}|null $f format de la carte du moment
     * @return array{format: array, produit: array}|null null : ligne illisible
     */
    public static function formatVendu(?array $f, int $formatId, array $ligne, array $acceptees, string $principale, array &$notes): ?array
    {
        $pu = [];
        foreach (is_array($ligne['pu'] ?? null) ? $ligne['pu'] : [] as $code => $prix) {
            if (isset($acceptees[$code]) && is_numeric($prix) && (float) $prix >= 0 && (float) $prix < 1e12) {
                $pu[$code] = (float) $prix;
            }
        }
        if ($f === null) {
            $cond = Database::un(
                'SELECT c.id, c.nom_fr, c.nom_en, c.produit_id, c.quantite_base, p.nom_fr AS produit_fr, p.nom_en AS produit_en, p.categorie_id
                   FROM conditionnements c JOIN produits p ON p.id = c.produit_id WHERE c.id = ?',
                [$formatId]
            );
            if ($cond === null || !isset($pu[$principale])) {
                return null;
            }
            $notes[] = 'prix';
            $nom = is_string($ligne['nom'] ?? null) && trim($ligne['nom']) !== '' ? mb_substr(trim($ligne['nom']), 0, 160) : champ($cond, 'produit');
            return [
                'format' => ['id' => $formatId, 'nom' => champ($cond, 'nom'), 'unite' => (float) $cond['quantite_base'] === 1.0, 'taille' => '',
                    'tailleId' => (int) $cond['produit_id'], 'freres' => 2, 'disponible' => true, 'prix' => $pu + array_fill_keys(array_keys($acceptees), null)],
                'produit' => ['nom' => $nom, 'categorie' => (int) $cond['categorie_id'], 'sous' => 0],
            ];
        }
        if (!$f['format']['disponible'] || !empty($f['format']['epuise'])) {
            $f['format']['disponible'] = true;
            $notes[] = 'stock';
        }
        $serveur = $f['format']['prix'][$principale] ?? null;
        if ($serveur === null) {
            if (!isset($pu[$principale])) {
                return null;
            }
            $f['format']['prix'] = $pu + $f['format']['prix'];
            $notes[] = 'prix';
        } elseif (isset($pu[$principale]) && abs($pu[$principale] - (float) $serveur) > 0.0005) {
            $notes[] = 'prix';
        }
        return $f;
    }

    /** Compte d'une ligne de paiement désactivé depuis la vente (de l'établissement ou commun, avec la devise), ou null. */
    public static function compteDesactive(int $id, int $etabId, array $acceptees): ?array
    {
        $codes = array_column($acceptees, 'code', 'id');
        $lignes = Database::tous(
            'SELECT c.id, c.nom, c.type, c.etablissement_id, cd.devise_id FROM comptes_tresorerie c JOIN compte_devises cd ON cd.compte_id = c.id
              WHERE c.id = ? AND (c.etablissement_id IS NULL OR c.etablissement_id = ?)',
            [$id, $etabId]
        );
        $compte = null;
        foreach ($lignes as $l) {
            $compte ??= ['id' => (int) $l['id'], 'nom' => $l['nom'], 'type' => $l['type'], 'commun' => $l['etablissement_id'] === null, 'devises' => []];
            if (isset($codes[(int) $l['devise_id']])) {
                $compte['devises'][] = $codes[(int) $l['devise_id']];
            }
        }
        return $compte;
    }

    /**
     * Monnaie rendue : celle que la caisse a rendue (dans sa devise), et non celle que le serveur aurait calculée avec
     * ses prix ; la trésorerie suit l'argent réel. Sans indication de la caisse : le calcul du serveur.
     * @param array<string, float> $calcule
     * @return array<string, float>
     */
    public static function renduAppareil(?array $rendu, array $calcule, array $acceptees): array
    {
        if ($rendu === null) {
            return $calcule;
        }
        $resultat = array_map(static fn () => 0.0, $calcule);
        if (isset($acceptees[$rendu['devise']]) && $rendu['montant'] > 0 && $rendu['montant'] < 1e12) {
            $resultat[$rendu['devise']] = round($rendu['montant'], (int) $acceptees[$rendu['devise']]['decimales']);
        }
        return $resultat;
    }

    /** Journal : la vente faite sans réseau, son heure, son reçu provisoire, et ce qui aurait été refusé en ligne. */
    public static function journaliser(int $venteId, string $numero, array $h, array $notes, ?float $ecart, string $devise, float $total, int $uid, ?int $appareilId): void
    {
        Journal::ecrire('vente_hors_ligne', $numero, array_filter([
            'vente' => $venteId, 'provisoire' => $h['numero'], 'heure_appareil' => $h['heure_appareil'], 'enregistree_a' => $h['moment']->format('Y-m-d H:i:s'),
            'notes' => $notes, 'ecart' => $ecart, 'devise' => $devise, 'total' => $total,
        ], static fn ($x) => $x !== null && $x !== []), $uid, $appareilId);
    }

    /** Réponse à l'envoi d'une vente faite sans réseau : son numéro définitif (jamais de verrouillage ici). */
    public static function repondreEnvoi(int $venteId, bool $deja): never
    {
        $vente = Database::un('SELECT id, numero, ' . (VenteRepository::horsLigneDisponible() ? 'ecart_hors_ligne, notes_hors_ligne' : 'NULL AS ecart_hors_ligne, NULL AS notes_hors_ligne') . ' FROM ventes WHERE id = ?', [$venteId]);
        self::repondre(200, [
            'numero' => $vente['numero'], 'id' => (int) $vente['id'], 'deja' => $deja,
            'ecart' => $vente['ecart_hors_ligne'] !== null ? (float) $vente['ecart_hors_ligne'] : null,
            'notes' => $vente['notes_hors_ligne'] ? explode(',', $vente['notes_hors_ligne']) : [],
            'csrf' => Session::jetonCsrf(),
        ]);
    }

    // ---------------------------------------------------------------------------------------------
    // Clés hors ligne, vérificateurs de PIN de la caisse partagée
    // ---------------------------------------------------------------------------------------------

    /**
     * Empreinte du PIN pour un appareil (16 caractères) : change quand le PIN change. Elle ne dit rien du PIN
     * (empreinte de son hachage bcrypt) ; l'appareil efface son vérificateur quand elle change.
     */
    public static function versionPin(?string $hachage, int $appareilId): ?string
    {
        return $hachage === null ? null : substr(hash('sha256', 'hors-ligne|' . $hachage . '|' . $appareilId), 0, 16);
    }

    /**
     * Personnes qui peuvent entrer sur cette caisse partagée, avec la version de leur PIN : l'appareil garde (et
     * rafraîchit à chaque chargement en ligne) le vérificateur hors ligne de celles qui sont déjà entrées ici.
     * @return list<array{id: int, version: string}>
     */
    public static function personnesAppareil(array $appareil, array $etab): array
    {
        $liste = [];
        foreach (Database::tous('SELECT id, pin FROM utilisateurs WHERE actif = 1 AND pin IS NOT NULL AND pin_bloque_le IS NULL ORDER BY id') as $u) {
            if (in_array('caisse', CaisseController::postesPermis((int) $u['id'], (int) $etab['id']), true)) {
                $liste[] = ['id' => (int) $u['id'], 'version' => self::versionPin($u['pin'], (int) $appareil['id'])];
            }
        }
        return $liste;
    }

    /** Données du mode hors ligne pour caisse-hors-ligne.js (la caisse et l'écran de verrouillage). */
    public static function donnees(array $etab, ?array $appareil, ?array $personne, ?array $zone, string $page, string $csrf): array
    {
        $textes = [];
        foreach (self::CLES_TEXTES as $cle) {
            $textes[$cle] = t('horsligne.' . $cle);
        }
        foreach (['stock', 'session', 'session_close', 'pointage', 'prix', 'remise', 'heure', 'droit', 'abonnement', 'formule'] as $n) {
            $textes['note_' . $n] = t('horsligne.note_' . $n);
        }
        foreach (['total' => 'recu.total', 'rendu' => 'recu.rendu', 'zone' => 'recu.zone', 'soit' => 'recu.soit', 'brut' => 'nuit.recu_brut', 'remises' => 'nuit.recu_remises'] as $k => $cle) {
            $textes['recu_' . $k] = t($cle);
        }
        $textes['types'] = ['caisse' => t('tresorerie.type_caisse'), 'mobile_money' => t('tresorerie.type_mobile_money'), 'banque' => t('tresorerie.type_banque')];
        return [
            'page' => $page,
            'etab' => $etab['slug'],
            'etabNom' => $etab['nom'],
            'adresse' => (string) ($etab['adresse'] ?? ''),
            'telephone' => !empty($etab['telephone']) ? afficherTelephone($etab['telephone']) : '',
            'zone' => $zone['code'] ?? '',
            'zoneNom' => $zone ? champ($zone, 'nom') : '',
            'partagee' => $appareil !== null,
            'appareil' => $appareil ? ['id' => (int) $appareil['id'], 'nom' => $appareil['nom']] : null,
            'moi' => $personne ? (int) $personne['id'] : null,
            'nom' => $personne['nom'] ?? null,
            'personnes' => $appareil !== null ? self::personnesAppareil($appareil, $etab) : [],
            'heure' => (int) round(microtime(true) * 1000),
            'langue' => \App\Core\I18n::langue(),
            // Fuseau de l'instance : l'heure des reçus provisoires est celle de l'établissement, pas celle de l'appareil
            'fuseau' => date_default_timezone_get(),
            'csrf' => $csrf,
            'essais' => CaissePartagee::ESSAIS_PIN,
            'echecsAppareil' => CaissePartagee::ECHECS_APPAREIL,
            'textes' => $textes,
        ];
    }

    /**
     * Catégories dont les plats partent en cuisine (une vente directe en ligne y envoie ses plats) : sans réseau, le
     * reçu provisoire demande de les annoncer.
     * @return list<int>
     */
    public static function categoriesCuisine(array $etab, array $donnees, ?array $zone, ?array $appareil): array
    {
        if (empty($donnees['salle']['modules']['cuisine']) || !Plateforme::moduleInclus('cuisine')) {
            return [];
        }
        $liste = [];
        try {
            $cuisine = new \App\Repositories\CuisineRepository();
            foreach ($donnees['categories'] as $categorie) {
                foreach (array_merge([(int) $categorie['id']], array_map('intval', array_column($categorie['sous'], 'id'))) as $id) {
                    if ($cuisine->postePour((int) $etab['id'], $id, $zone ? (int) $zone['id'] : null, $appareil ? (int) $appareil['id'] : null) !== null) {
                        $liste[] = $id;
                    }
                }
            }
        } catch (\Throwable $e) {
            return [];
        }
        return $liste;
    }

    /** Textes « horsligne.* » envoyés à la caisse. */
    private const CLES_TEXTES = [
        'en_ligne', 'hors_ligne', 'en_attente_n', 'en_attente_1', 'titre', 'envoyer', 'envoi', 'aucune', 'provisoire', 'provisoire_n',
        'en_attente_envoi', 'recu_note', 'recu_cuisine', 'reseau_requis', 'reseau_court', 'refusee', 'bloquee', 'connexion', 'connexion_partagee',
        'reconnecter', 'deverrouiller', 'reessayer', 'reimprimer', 'envoyee', 'deja', 'ecart', 'retour', 'retour_pin', 'page_cache',
        'pin_hors_ligne', 'pin_incorrect', 'pin_bloque', 'pin_appareil', 'pin_indisponible', 'pin_court', 'aide', 'aide_reseau', 'remise_validation',
        'session_hors_ligne', 'vendeur', 'heure', 'derniere', 'appareil_sature', 'imprimer', 'fermer', 'sans_cle', 'aide_court', 'bande',
    ];

    /**
     * POST /caisse/hors-ligne/cle : la clé hors ligne de la personne sur cet appareil (créée la première fois ou quand
     * son PIN a changé). Caisse partagée : la personne entrée par PIN ; appareil personnel : le compte connecté.
     */
    public function cle(): void
    {
        $this->exigerJeton();
        $appareil = CaissePartagee::appareil();
        if ($appareil !== null) {
            $caissier = CaissePartagee::caissier();
            $etab = (new EtablissementRepository())->parSlug($appareil['slug']);
            $uid = $caissier !== null ? (int) $caissier['id'] : 0;
        } else {
            $utilisateur = Session::utilisateur();
            $etab = (new EtablissementRepository())->parSlug(is_string($_POST['etab'] ?? null) ? $_POST['etab'] : '');
            $uid = $utilisateur !== null ? (int) $utilisateur['id'] : 0;
        }
        if ($uid === 0) {
            self::repondre(401, ['erreur' => t('encaisser.connexion')]);
        }
        if ($etab === null || !in_array('caisse', CaisseController::postesPermis($uid, (int) $etab['id']), true)) {
            self::repondre(403, ['erreur' => t('encaisser.droit')]);
        }
        $appareilId = $appareil !== null ? (int) $appareil['id'] : null;
        $version = $appareil !== null ? self::versionPin(Database::un('SELECT pin FROM utilisateurs WHERE id = ?', [$uid])['pin'] ?? null, $appareilId) : null;
        $cle = Database::un(
            'SELECT * FROM caisse_cles_hors_ligne WHERE utilisateur_id = ? AND etablissement_id = ? AND appareil_id <=> ? AND revoquee_le IS NULL
              ORDER BY id DESC LIMIT 1',
            [$uid, (int) $etab['id'], $appareilId]
        );
        if ($cle !== null && $cle['version_pin'] !== $version) {
            // PIN changé : l'ancienne clé ne signe plus rien de nouveau
            Database::executer('UPDATE caisse_cles_hors_ligne SET revoquee_le = NOW() WHERE id = ?', [(int) $cle['id']]);
            $cle = null;
        }
        if ($cle === null) {
            Database::executer(
                'INSERT INTO caisse_cles_hors_ligne (utilisateur_id, appareil_id, etablissement_id, secret, version_pin) VALUES (?, ?, ?, ?, ?)',
                [$uid, $appareilId, (int) $etab['id'], bin2hex(random_bytes(32)), $version]
            );
            $cle = Database::un('SELECT * FROM caisse_cles_hors_ligne WHERE id = ?', [Database::dernierId()]);
        }
        self::repondre(200, ['id' => (int) $cle['id'], 'secret' => $cle['secret'], 'version' => $version, 'utilisateur' => $uid]);
    }

    /**
     * POST /caisse/hors-ligne/evenements : ce qui s'est passé sans réseau sur la caisse partagée, dans l'ordre :
     * PIN faux (compte comme un essai : 5 essais faux bloquent le PIN, comme en ligne), PIN juste (remet le compte à zéro),
     * verrouillage. evenements = [{type: pin_echec|pin_ok|verrou, uid, heure}]
     */
    public function evenements(): void
    {
        $this->exigerJeton();
        $appareil = CaissePartagee::appareil();
        if ($appareil === null) {
            self::repondre(403, ['erreur' => t('caisse_pin.appareil_requis')]);
        }
        $liste = json_decode(is_string($_POST['evenements'] ?? null) ? $_POST['evenements'] : '', true);
        $appareilId = (int) $appareil['id'];
        $traites = 0;
        foreach (is_array($liste) ? array_slice($liste, 0, 200) : [] as $ev) {
            $uid = (int) ($ev['uid'] ?? 0);
            $type = $ev['type'] ?? '';
            $heure = is_string($ev['heure'] ?? null) ? self::lireHeure($ev['heure'])?->format('Y-m-d H:i:s') : null;
            $personne = $uid > 0 ? Database::un('SELECT id, nom, pin_essais, pin_bloque_le FROM utilisateurs WHERE id = ?', [$uid]) : null;
            if ($personne === null || !in_array($type, ['pin_echec', 'pin_ok', 'verrou'], true)) {
                continue;
            }
            $traites++;
            if ($type === 'pin_echec') {
                $essais = (int) $personne['pin_essais'] + 1;
                $bloque = $personne['pin_bloque_le'] === null && $essais >= CaissePartagee::ESSAIS_PIN;
                Database::executer('UPDATE utilisateurs SET pin_essais = ?, pin_bloque_le = COALESCE(pin_bloque_le, ?) WHERE id = ?',
                    [$bloque ? 0 : $essais, $bloque ? date('Y-m-d H:i:s') : null, $uid]);
                Journal::ecrire('pin_echec_hors_ligne', $personne['nom'], ['heure' => $heure], $uid, $appareilId);
                if ($bloque) {
                    Journal::ecrire('pin_bloque', $personne['nom'], ['hors_ligne' => true], $uid, $appareilId);
                }
            } elseif ($type === 'pin_ok') {
                Database::executer('UPDATE utilisateurs SET pin_essais = 0 WHERE id = ?', [$uid]);
                Journal::ecrire('pin_connexion_hors_ligne', $appareil['nom'], ['heure' => $heure], $uid, $appareilId);
            } else {
                Journal::ecrire('caisse_verrouillee', null, ['hors_ligne' => true, 'heure' => $heure], $uid, $appareilId);
            }
        }
        self::repondre(200, ['traites' => $traites]);
    }

    /** GET /caisse/etat : le serveur répond (réseau revenu) et donne son heure (décalage de l'horloge de l'appareil). */
    public function etat(): void
    {
        header('Cache-Control: no-store');
        self::repondre(200, ['heure' => (int) round(microtime(true) * 1000)]);
    }

    /**
     * GET /caisse/sw.js : le service worker de la caisse (public/assets/js/caisse-sw.js), avec sa version (empreinte des
     * fichiers de la caisse : une mise à jour du code remplace les caches) et la liste des fichiers gardés d'avance.
     * Portée /caisse (en-tête Service-Worker-Allowed) : il ne touche jamais aux pages de la gestion.
     */
    public function serviceWorker(): void
    {
        $fichiers = array_merge(self::FICHIERS, ['/assets/js/caisse-sw.js']);
        $empreinte = '';
        foreach ($fichiers as $f) {
            $chemin = RACINE . '/public' . $f;
            $empreinte .= $f . (is_file($chemin) ? filemtime($chemin) . filesize($chemin) : '0');
        }
        // Les pages de la caisse changent aussi avec leurs gabarits et leurs textes
        foreach (array_merge(glob(RACINE . '/views/caisse/*.php') ?: [], glob(RACINE . '/lang/*/hors-ligne.php') ?: [], [RACINE . '/app/Controllers/CaisseController.php']) as $f) {
            $empreinte .= basename($f) . filemtime($f);
        }
        $version = substr(hash('sha256', $empreinte), 0, 12);
        header('Content-Type: application/javascript; charset=utf-8');
        header('Service-Worker-Allowed: /caisse');
        header('Cache-Control: no-cache');
        echo '// Version ' . $version . "\n";
        echo 'self.RBP_VERSION = ' . json_encode($version) . ";\n";
        echo 'self.RBP_FICHIERS = ' . json_encode(array_merge(self::FICHIERS, ['/caisse/hors-ligne/page']), JSON_UNESCAPED_SLASHES) . ";\n";
        readfile(RACINE . '/public/assets/js/caisse-sw.js');
    }

    /** Fichiers de la caisse gardés d'avance par le service worker. */
    private const FICHIERS = [
        '/assets/css/volcan.css', '/assets/css/caisse.css', '/assets/css/caisse-nuit.css', '/assets/js/theme.js',
        '/assets/vendor/qrcode.js', '/assets/js/caisse.js', '/assets/js/caisse-ardoise.js', '/assets/js/caisse-session.js',
        '/assets/js/caisse-salle.js', '/assets/js/scanner-qr.js', '/assets/js/caisse-nuit.js', '/assets/js/photo-profil.js',
        '/assets/js/caisse-pin.js', '/assets/js/caisse-hors-ligne-base.js', '/assets/js/caisse-hors-ligne.js',
    ];

    /** GET /caisse/hors-ligne/page : page de secours montrée sans réseau quand l'écran demandé n'est pas sur l'appareil. */
    public function page(): void
    {
        header('Cache-Control: no-cache');
        View::afficher('caisse/hors-ligne', ['titre' => t('horsligne.page_titre')], null);
    }

    private function exigerJeton(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        Session::demarrer();
        $jeton = $_POST['csrf'] ?? '';
        if (!is_string($jeton) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $jeton)) {
            self::repondre(419, ['erreur' => t('encaisser.expire'), 'code' => 'expire']);
        }
    }

    public static function repondre(int $code, array $donnees): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
}
