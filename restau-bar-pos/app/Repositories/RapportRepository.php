<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Acces;
use App\Core\Database;
use App\Core\I18n;
use App\Core\Journee;

/**
 * Données des tableaux de bord de la direction et des rapports du personnel (cahier des charges, 13.6 et 13.7).
 *
 * Les ventes d'une période se lisent par journée d'exploitation (section 5 : une vente à 2 h du matin compte pour
 * la veille, selon l'heure de fin de journée de chaque établissement, App\Core\Journee), une ligne par vente, puis
 * s'agrègent ici en PHP : par jour, par service (horaires de l'établissement), par établissement, par vendeur.
 *
 * Chaque vente porte :
 *   - son total dans la devise locale (vente_totaux) et dans la devise de référence (prix en dollars) ;
 *   - sa part mise à crédit (dettes de la vente / total du ticket dans la devise de chaque dette) et la part de ce
 *     crédit déjà remboursée (y compris après une mise à la charge du responsable) ;
 *   - son coût des marchandises (stock_mouvements « vente » et « recette » au CUMP), si le module stock l'a calculé.
 *
 * Les tables des autres modules (stock, personnel, salle) ne sont lues que si elles existent.
 * La portée (établissements où la personne a rapports.voir) est appliquée dans chaque requête.
 */
final class RapportRepository
{
    /**
     * Les composants d'une formule (softs compris dans une bouteille, à 0) ne comptent pas parmi les produits vendus.
     * La colonne vente_lignes.nature n'existe qu'à partir de la migration 022.
     */
    public static function sansComposants(string $alias): string
    {
        static $nature = null;
        $nature ??= Database::un("SHOW COLUMNS FROM vente_lignes LIKE 'nature'") !== null;
        return $nature ? " AND $alias.nature <> 'composant'" : '';
    }

    /** @var array<string, bool> */
    private static array $tables = [];
    /** @var array<string, bool> */
    private static array $colonnes = [];

    private DeviseRepository $devises;
    private ?array $locale;
    private ?array $reference;

    public function __construct(?DeviseRepository $devises = null)
    {
        $this->devises = $devises ?? new DeviseRepository();
        $this->reference = $this->devises->reference();
        $this->locale = $this->devises->locale() ?? $this->reference;
    }

    public function locale(): ?array
    {
        return $this->locale;
    }

    public function reference(): ?array
    {
        return $this->reference;
    }

    /** Taux du jour de la devise locale (unités pour 1 unité de référence). */
    public function tauxLocal(): float
    {
        $taux = $this->locale['taux'] ?? null;
        return $taux !== null && (float) $taux > 0 ? (float) $taux : 1.0;
    }

    /** Équivalent en devise locale (au taux du jour) d'un montant en devise de référence. */
    public function versLocale(float $reference): float
    {
        return $reference * $this->tauxLocal();
    }

    // ---------- Schéma (modules construits en parallèle) ----------

    public static function tableExiste(string $table): bool
    {
        if (!isset(self::$tables[$table])) {
            self::$tables[$table] = Database::un('SELECT 1 AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]) !== null;
        }
        return self::$tables[$table];
    }

    public static function colonneExiste(string $table, string $colonne): bool
    {
        $cle = $table . '.' . $colonne;
        if (!isset(self::$colonnes[$cle])) {
            self::$colonnes[$cle] = Database::un(
                'SELECT 1 AS n FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                [$table, $colonne]
            ) !== null;
        }
        return self::$colonnes[$cle];
    }

    // ---------- Portée et journées ----------

    /**
     * Condition SQL limitant une colonne etablissement_id aux établissements où la personne a la permission.
     * @return array{0: string, 1: list<int>}
     */
    public static function portee(int $utilisateurId, string $permission, string $colonne): array
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

    /** Établissements que la personne peut choisir (permission donnée). */
    public static function etablissements(int $utilisateurId, string $permission): array
    {
        if (!Acces::a($utilisateurId, $permission)) {
            return [];
        }
        $ids = Acces::etablissements($utilisateurId, $permission);
        $colonnes = 'id, nom, slug, type' . (self::colonneExiste('etablissements', 'fin_journee') ? ', fin_journee' : '');
        $tous = Database::tous('SELECT ' . $colonnes . ' FROM etablissements WHERE actif = 1 ORDER BY position, nom');
        return array_values(array_filter($tous, static fn (array $e) => $ids === null || in_array((int) $e['id'], $ids, true)));
    }

    /** Expression SQL de l'heure de fin de journée de l'établissement « e » (colonne, sinon valeur par défaut). */
    private static function finJournee(): string
    {
        if (self::colonneExiste('etablissements', 'fin_journee')) {
            return 'e.fin_journee';
        }
        return class_exists(Journee::class) ? Journee::DEFAUT : '05:00:00';
    }

    /** Expression SQL de la journée d'exploitation d'une colonne DATETIME (jointure « e » sur etablissements). */
    public static function journeeSql(string $colonne): string
    {
        $fin = self::finJournee();
        if (class_exists(Journee::class)) {
            return Journee::sql($colonne, $fin);
        }
        return 'DATE(SUBTIME(' . $colonne . ', ' . ($fin === 'e.fin_journee' ? $fin : "'" . $fin . "'") . '))';
    }

    /**
     * Ventes d'une période de journées : bornes larges sur cree_le (index), puis la journée exacte.
     * @return array{0: string, 1: list<string>}
     */
    private static function conditionJournees(string $colonne, string $du, string $au): array
    {
        $debut = $du . ' 00:00:00';
        $fin = (new \DateTimeImmutable($au))->modify('+2 days')->format('Y-m-d 00:00:00');
        return [$colonne . ' >= ? AND ' . $colonne . ' < ? AND ' . self::journeeSql($colonne) . ' BETWEEN ? AND ?', [$debut, $fin, $du, $au]];
    }

    /**
     * Dates de la période choisie (TresorerieSaisie::periode) ; « tout » va de la première vente à aujourd'hui.
     * @return array{du: string, au: string, jours: int}
     */
    public static function bornes(array $periode): array
    {
        $du = $periode['du'] ?? null;
        $au = $periode['au'] ?? null;
        if ($du === null) {
            $premiere = Database::un('SELECT MIN(cree_le) AS d FROM ventes');
            $du = $premiere['d'] !== null ? substr((string) $premiere['d'], 0, 10) : date('Y-m-d');
        }
        $au ??= date('Y-m-d');
        $jours = (int) (new \DateTimeImmutable($du))->diff(new \DateTimeImmutable($au))->days + 1;
        return ['du' => $du, 'au' => $au, 'jours' => $jours];
    }

    /** La période de même durée juste avant. @return array{du: string, au: string, jours: int} */
    public static function precedente(array $bornes): array
    {
        $du = new \DateTimeImmutable($bornes['du']);
        $jours = max(1, (int) $bornes['jours']);
        return [
            'du' => $du->modify('-' . $jours . ' days')->format('Y-m-d'),
            'au' => $du->modify('-1 day')->format('Y-m-d'),
            'jours' => $jours,
        ];
    }

    // ---------- Ventes de la période, une ligne par vente ----------

    /** Les notes de la salle disent qui a servi (serveur de la note) ; sinon c'est la personne qui a encaissé. */
    public static function serviceEnSalle(): bool
    {
        return self::tableExiste('note_paiements') && self::colonneExiste('notes', 'serveur_id');
    }

    /**
     * @param array{du?: string, au?: string, entre?: array{0: string, 1: string}, etablissement?: ?int, vendeur?: ?int, attribution?: string} $f
     *        attribution : « serveur » (qui a servi, si la salle le sait) ou « caisse » (qui a encaissé)
     * @param bool $toutePortee false : seulement les ventes de $f['vendeur'], où qu'elles soient (ses propres résultats)
     * @return list<array{id: int, numero: string, etablissement_id: int, vendeur_id: ?int, caissier_id: ?int, journee: string,
     *               cree_le: string, statut: string, total_locale: float, total_reference: float, encaisse_reference: float,
     *               articles: float, credit: float, rembourse: float, cout: ?float, cout_complet: bool}>
     */
    public function ventes(array $f, int $utilisateurId, bool $toutePortee = true): array
    {
        if ($this->locale === null) {
            return [];
        }
        if ($toutePortee) {
            [$portee, $params] = self::portee($utilisateurId, 'rapports.voir', 'v.etablissement_id');
        } else {
            [$portee, $params] = ['1 = 1', []];
        }
        // « entre » : une fenêtre exacte (la soirée d'un événement, de l'ouverture des portes à la fermeture)
        [$periode, $paramsPeriode] = isset($f['entre'])
            ? ['v.cree_le >= ? AND v.cree_le < ?', [$f['entre'][0], $f['entre'][1]]]
            : self::conditionJournees('v.cree_le', $f['du'], $f['au']);
        $where = $portee . ' AND ' . $periode;
        $params = array_merge($params, $paramsPeriode);
        if (!empty($f['etablissement'])) {
            $where .= ' AND v.etablissement_id = ?';
            $params[] = (int) $f['etablissement'];
        }
        $vendeur = 'v.utilisateur_id';
        if (($f['attribution'] ?? 'serveur') === 'serveur' && self::serviceEnSalle()) {
            $vendeur = 'COALESCE((SELECT n.serveur_id FROM note_paiements np JOIN notes n ON n.id = np.note_id
                                  WHERE np.vente_id = v.id AND n.serveur_id IS NOT NULL ORDER BY np.note_id LIMIT 1), v.utilisateur_id)';
        }
        if (!empty($f['vendeur'])) {
            $where .= ' AND ' . $vendeur . ' = ?';
            $params[] = (int) $f['vendeur'];
        }
        $lignes = Database::tous(
            'SELECT v.id, v.numero, v.etablissement_id, ' . $vendeur . ' AS vendeur_id, v.utilisateur_id AS caissier_id,
                    ' . self::journeeSql('v.cree_le') . ' AS journee, v.cree_le, v.statut, v.devise_id,
                    vt.total AS total_locale, v.total, v.total_reference, v.encaisse_reference,
                    (SELECT COALESCE(SUM(l.quantite), 0) FROM vente_lignes l WHERE l.vente_id = v.id) AS articles
               FROM ventes v
               JOIN etablissements e ON e.id = v.etablissement_id
          LEFT JOIN vente_totaux vt ON vt.vente_id = v.id AND vt.devise_id = ?
              WHERE ' . $where . '
           ORDER BY v.cree_le, v.id',
            array_merge([(int) $this->locale['id']], $params)
        );
        $taux = $this->tauxLocal();
        $ventes = [];
        foreach ($lignes as $l) {
            $reference = $l['total_reference'] !== null ? (float) $l['total_reference'] : null;
            $locale = $l['total_locale'] !== null ? (float) $l['total_locale']
                : ((int) $l['devise_id'] === (int) $this->locale['id'] ? (float) $l['total'] : ($reference ?? 0.0) * $taux);
            $ventes[(int) $l['id']] = [
                'id' => (int) $l['id'],
                'numero' => $l['numero'],
                'etablissement_id' => (int) $l['etablissement_id'],
                'vendeur_id' => $l['vendeur_id'] !== null ? (int) $l['vendeur_id'] : null,
                'caissier_id' => $l['caissier_id'] !== null ? (int) $l['caissier_id'] : null,
                'journee' => (string) $l['journee'],
                'cree_le' => (string) $l['cree_le'],
                'statut' => (string) $l['statut'],
                'total_locale' => $locale,
                'total_reference' => $reference ?? ($taux > 0 ? $locale / $taux : 0.0),
                'encaisse_reference' => $l['encaisse_reference'] !== null ? (float) $l['encaisse_reference'] : 0.0,
                'articles' => (float) $l['articles'],
                'credit' => 0.0,
                'rembourse' => 0.0,
                'cout' => null,
                'cout_complet' => false,
            ];
        }
        $this->ajouterCredits($ventes);
        $this->ajouterCouts($ventes);
        return array_values($ventes);
    }

    /** @param array<int, array> $ventes */
    private function ajouterCredits(array &$ventes): void
    {
        foreach (array_chunk(array_keys($ventes), 800) as $ids) {
            $lignes = Database::tous(
                'SELECT d.vente_id, d.montant, d.reste, d.statut, vt.total AS total_devise,
                        COALESCE((SELECT SUM(c.montant - c.reste) FROM dettes c WHERE c.origine_id = d.id), 0) AS enfant
                   FROM dettes d
                   JOIN vente_totaux vt ON vt.vente_id = d.vente_id AND vt.devise_id = d.devise_id
                  WHERE d.vente_id IN (' . implode(',', array_map('intval', $ids)) . ')'
            );
            foreach ($lignes as $l) {
                $total = (float) $l['total_devise'];
                if ($total <= 0) {
                    continue;
                }
                $id = (int) $l['vente_id'];
                $part = (float) $l['montant'] / $total;
                $rembourse = max(0.0, (float) $l['montant'] - (float) $l['reste']);
                if ($l['statut'] === 'transferee') {
                    $rembourse += (float) $l['enfant'];
                }
                $ventes[$id]['credit'] = min(1.0, $ventes[$id]['credit'] + $part);
                $ventes[$id]['rembourse'] = min($ventes[$id]['credit'], $ventes[$id]['rembourse'] + min($part, $rembourse / $total));
            }
        }
    }

    /** Coût des marchandises vendues de chaque vente (devise de référence), si le stock l'a calculé. */
    private function ajouterCouts(array &$ventes): void
    {
        if (!self::tableExiste('stock_mouvements')) {
            return;
        }
        foreach (array_chunk(array_keys($ventes), 800) as $ids) {
            $lignes = Database::tous(
                "SELECT source_id, SUM(-quantite * COALESCE(cout_unitaire, 0)) AS cout, SUM(cout_unitaire IS NULL) AS manquants
                   FROM stock_mouvements
                  WHERE type IN ('vente', 'recette') AND source_id IN (" . implode(',', array_map('intval', $ids)) . ')
               GROUP BY source_id'
            );
            foreach ($lignes as $l) {
                $id = (int) $l['source_id'];
                $ventes[$id]['cout'] = (float) $l['cout'];
                $ventes[$id]['cout_complet'] = (int) $l['manquants'] === 0;
            }
        }
    }

    /** Le module stock a-t-il des coûts (CUMP) ? Sinon, pas de marge à montrer. */
    public static function coutsDisponibles(): bool
    {
        return self::tableExiste('stock_couts') && Database::un('SELECT 1 AS n FROM stock_couts LIMIT 1') !== null;
    }

    // ---------- Agrégats ----------

    /** Totaux vides. */
    public static function vide(): array
    {
        return [
            'tickets' => 0, 'articles' => 0.0, 'ca_locale' => 0.0, 'ca_reference' => 0.0,
            'credit_locale' => 0.0, 'credit_reference' => 0.0, 'rembourse_locale' => 0.0, 'rembourse_reference' => 0.0,
            'encaisse_reference' => 0.0, 'annulees' => 0, 'annulees_locale' => 0.0,
            'cout' => 0.0, 'ca_couvert' => 0.0,
        ];
    }

    /** Ajoute une vente aux totaux (les ventes annulées ne comptent que dans « annulées »). */
    public static function ajouter(array &$t, array $v): void
    {
        if ($v['statut'] !== 'payee') {
            $t['annulees']++;
            $t['annulees_locale'] += $v['total_locale'];
            return;
        }
        $t['tickets']++;
        $t['articles'] += $v['articles'];
        $t['ca_locale'] += $v['total_locale'];
        $t['ca_reference'] += $v['total_reference'];
        $t['credit_locale'] += $v['total_locale'] * $v['credit'];
        $t['credit_reference'] += $v['total_reference'] * $v['credit'];
        $t['rembourse_locale'] += $v['total_locale'] * $v['rembourse'];
        $t['rembourse_reference'] += $v['total_reference'] * $v['rembourse'];
        $t['encaisse_reference'] += $v['encaisse_reference'];
        if ($v['cout'] !== null) {
            $t['cout'] += $v['cout'];
            if ($v['cout_complet']) {
                $t['ca_couvert'] += $v['total_reference'];
            }
        }
    }

    /** Chiffres dérivés : encaissé (CA moins la part à crédit), panier moyen, marge, couverture des coûts. */
    public static function completer(array $t): array
    {
        $t['encaisse_locale'] = $t['ca_locale'] - $t['credit_locale'];
        $t['encaisse_part_reference'] = $t['ca_reference'] - $t['credit_reference'];
        $t['non_rembourse_locale'] = $t['credit_locale'] - $t['rembourse_locale'];
        $t['non_rembourse_reference'] = $t['credit_reference'] - $t['rembourse_reference'];
        $t['panier_locale'] = $t['tickets'] > 0 ? $t['ca_locale'] / $t['tickets'] : 0.0;
        $t['panier_reference'] = $t['tickets'] > 0 ? $t['ca_reference'] / $t['tickets'] : 0.0;
        $t['marge_reference'] = $t['ca_reference'] - $t['cout'];
        $t['couverture'] = $t['ca_reference'] > 0 ? min(1.0, $t['ca_couvert'] / $t['ca_reference']) : 0.0;
        return $t;
    }

    /** @return array totaux complétés */
    public static function totaux(array $ventes): array
    {
        $t = self::vide();
        foreach ($ventes as $v) {
            self::ajouter($t, $v);
        }
        return self::completer($t);
    }

    /**
     * Groupes de ventes selon une clé (journee, etablissement_id, vendeur_id…), totaux complétés.
     * @return array<string, array>
     */
    public static function grouper(array $ventes, callable $cle): array
    {
        $groupes = [];
        foreach ($ventes as $v) {
            $k = (string) $cle($v);
            $groupes[$k] ??= self::vide();
            self::ajouter($groupes[$k], $v);
        }
        return array_map([self::class, 'completer'], $groupes);
    }

    /** Liste des journées de la période (Y-m-d), dans l'ordre. @return list<string> */
    public static function journees(string $du, string $au): array
    {
        $jours = [];
        $jour = new \DateTimeImmutable($du);
        $fin = new \DateTimeImmutable($au);
        while ($jour <= $fin && count($jours) < 400) {
            $jours[] = $jour->format('Y-m-d');
            $jour = $jour->modify('+1 day');
        }
        return $jours;
    }

    // ---------- Services horaires ----------

    /**
     * Service de chaque vente d'après les horaires de son établissement (« Restaurant » 11 h – 15 h, « Club » 22 h – 5 h :
     * une vente à 2 h le samedi appartient au service « Club » du vendredi). Sans service correspondant : null.
     * @return array<int, ?string> vente_id → nom du service dans la langue de l'interface
     */
    public function services(array $ventes): array
    {
        $horaires = [];
        foreach (Database::tous('SELECT etablissement_id, service_fr, service_en, jour, ouverture, fermeture FROM horaires') as $h) {
            $horaires[(int) $h['etablissement_id']][] = $h;
        }
        $nom = static fn (array $h): string => I18n::langue() === 'en' && $h['service_en'] !== '' ? $h['service_en'] : $h['service_fr'];
        $resultat = [];
        foreach ($ventes as $v) {
            $resultat[$v['id']] = null;
            $liste = $horaires[$v['etablissement_id']] ?? [];
            if ($liste === []) {
                continue;
            }
            $moment = new \DateTimeImmutable($v['cree_le']);
            $heure = $moment->format('H:i:s');
            $jour = (int) $moment->format('N');
            $veille = $jour === 1 ? 7 : $jour - 1;
            foreach ($liste as $h) {
                $ouverture = $h['ouverture'];
                $fermeture = $h['fermeture'];
                $traverse = $fermeture <= $ouverture;   // passe minuit (fermeture « 00:00 » comprise)
                $dans = (!$traverse && (int) $h['jour'] === $jour && $heure >= $ouverture && $heure < $fermeture)
                    || ($traverse && (int) $h['jour'] === $jour && $heure >= $ouverture)
                    || ($traverse && (int) $h['jour'] === $veille && $heure < $fermeture);
                if ($dans) {
                    $resultat[$v['id']] = $nom($h);
                    break;
                }
            }
        }
        return $resultat;
    }

    // ---------- Produits ----------

    /**
     * Produits vendus par les ventes données (payées) : quantité, montant en devise locale et en référence, coût et marge.
     * Le coût d'un produit est son coût moyen dans les sorties de stock de ces ventes, sinon son coût de revient actuel
     * (fiche technique ou CUMP) ; null s'il n'est pas connu.
     * @return array<int, array{produit_id: int, nom: string, quantite: float, locale: float, reference: float, cout: ?float, marge: ?float}>
     */
    public function produits(array $ventes): array
    {
        $ids = array_map(static fn (array $v) => $v['id'], array_filter($ventes, static fn (array $v) => $v['statut'] === 'payee'));
        if ($ids === [] || $this->locale === null) {
            return [];
        }
        $taux = $this->tauxLocal();
        $produits = [];
        $champNom = I18n::langue() === 'en' ? 'en' : 'fr';
        foreach (array_chunk($ids, 800) as $morceau) {
            $lignes = Database::tous(
                'SELECT COALESCE(c.produit_id, l.produit_id) AS produit_id,
                        MAX(COALESCE(pp.nom_' . $champNom . ', p.nom_' . $champNom . ', l.libelle)) AS nom_parent,
                        MAX(IF(pp.id IS NULL, NULL, p.taille_' . $champNom . ')) AS taille,
                        MAX(l.libelle) AS libelle,
                        SUM(l.quantite) AS quantite,
                        SUM(l.quantite * COALESCE(c.quantite_base, 1)) AS base,
                        SUM(CASE WHEN v.devise_id = ? THEN l.montant ELSE COALESCE(l.prix_reference, 0) * l.quantite * ? END) AS locale,
                        SUM(COALESCE(l.prix_reference, 0) * l.quantite) AS reference
                   FROM vente_lignes l
                   JOIN ventes v ON v.id = l.vente_id
              LEFT JOIN conditionnements c ON c.id = l.conditionnement_id
              LEFT JOIN produits p ON p.id = COALESCE(c.produit_id, l.produit_id)
              LEFT JOIN produits pp ON pp.id = p.parent_id
                  WHERE l.vente_id IN (' . implode(',', array_map('intval', $morceau)) . ')' . self::sansComposants('l') . '
               GROUP BY COALESCE(c.produit_id, l.produit_id)',
                [(int) $this->locale['id'], $taux]
            );
            foreach ($lignes as $l) {
                $id = (int) $l['produit_id'];
                $nom = $l['nom_parent'] !== null ? trim($l['nom_parent'] . ($l['taille'] ? ' ' . $l['taille'] : '')) : (string) $l['libelle'];
                if ($id === 0) {
                    $nom = t('nuit.hors_catalogue');   // entrées, vestiaire, minimum non atteint : lignes sans produit
                }
                $produits[$id] ??= ['produit_id' => $id, 'nom' => $nom, 'quantite' => 0.0, 'base' => 0.0, 'locale' => 0.0, 'reference' => 0.0, 'cout' => null, 'marge' => null];
                $produits[$id]['quantite'] += (float) $l['quantite'];
                $produits[$id]['base'] += (float) $l['base'];
                $produits[$id]['locale'] += (float) $l['locale'];
                $produits[$id]['reference'] += (float) $l['reference'];
            }
        }
        if (self::tableExiste('stock_mouvements')) {
            // Coût unitaire moyen (par unité de base) des sorties de ces ventes, produit vendu tel quel
            $unitaires = [];
            foreach (array_chunk($ids, 800) as $morceau) {
                foreach (Database::tous(
                    "SELECT produit_id, SUM(-quantite * cout_unitaire) AS cout, SUM(-quantite) AS base
                       FROM stock_mouvements
                      WHERE type = 'vente' AND cout_unitaire IS NOT NULL AND source_id IN (" . implode(',', array_map('intval', $morceau)) . ')
                   GROUP BY produit_id'
                ) as $l) {
                    $pid = (int) $l['produit_id'];
                    $unitaires[$pid]['cout'] = ($unitaires[$pid]['cout'] ?? 0.0) + (float) $l['cout'];
                    $unitaires[$pid]['base'] = ($unitaires[$pid]['base'] ?? 0.0) + (float) $l['base'];
                }
            }
            $stock = self::coutsDisponibles() ? new StockRepository() : null;
            foreach ($produits as $id => &$p) {
                $unitaire = null;
                if (isset($unitaires[$id]) && $unitaires[$id]['base'] > 0) {
                    $unitaire = $unitaires[$id]['cout'] / $unitaires[$id]['base'];
                } elseif ($stock !== null) {
                    $revient = $stock->coutRevient($id);
                    $unitaire = $revient['complet'] ? $revient['cout'] : null;
                }
                if ($unitaire !== null) {
                    $p['cout'] = $unitaire * $p['base'];
                    $p['marge'] = $p['reference'] - $p['cout'];
                }
            }
            unset($p);
        }
        return $produits;
    }

    /**
     * Produits vendables (actifs, avec un format vendable) de l'établissement, ou de tous, les moins vendus d'abord
     * (zéro compris) ; une taille (Primus Grande) est un produit à part, son produit parent ne compte pas.
     * @param array<int, array> $vendus résultat de produits()
     */
    public function moinsVendus(array $vendus, ?int $etablissementId, int $limite = 8): array
    {
        $champ = I18n::langue() === 'en' ? 'en' : 'fr';
        $params = [];
        $condition = '';
        if ($etablissementId !== null) {
            $condition = ' AND (NOT EXISTS (SELECT 1 FROM produit_etablissements pe0 WHERE pe0.produit_id = COALESCE(p.parent_id, p.id))
                              OR EXISTS (SELECT 1 FROM produit_etablissements pe WHERE pe.produit_id = COALESCE(p.parent_id, p.id) AND pe.etablissement_id = ?))';
            $params[] = $etablissementId;
        }
        $candidats = Database::tous(
            'SELECT p.id, TRIM(CONCAT(COALESCE(pp.nom_' . $champ . ', p.nom_' . $champ . '), IF(pp.id IS NULL, \'\', CONCAT(\' \', COALESCE(p.taille_' . $champ . ', \'\'))))) AS nom
               FROM produits p
          LEFT JOIN produits pp ON pp.id = p.parent_id
              WHERE p.actif = 1 AND (pp.id IS NULL OR pp.actif = 1)
                AND EXISTS (SELECT 1 FROM conditionnements c WHERE c.produit_id = p.id AND c.vendable = 1)
                AND NOT EXISTS (SELECT 1 FROM produits t WHERE t.parent_id = p.id AND t.actif = 1)' . $condition . '
           ORDER BY nom',
            $params
        );
        $liste = array_map(static fn (array $c) => [
            'produit_id' => (int) $c['id'], 'nom' => $c['nom'],
            'quantite' => $vendus[(int) $c['id']]['quantite'] ?? 0.0,
            'locale' => $vendus[(int) $c['id']]['locale'] ?? 0.0,
        ], $candidats);
        usort($liste, static fn (array $a, array $b) => [$a['quantite'], $a['locale'], $a['nom']] <=> [$b['quantite'], $b['locale'], $b['nom']]);
        return array_slice($liste, 0, $limite);
    }

    // ---------- Dépenses et salaires ----------

    /**
     * Dépenses de la période (date de la dépense), par devise et en référence, par catégorie et par établissement.
     * Portée : une personne limitée à certains établissements ne voit que leurs dépenses (pas les dépenses communes).
     * @return array{devises: array<int, float>, reference: float, categories: array<int, array>, etablissements: array<string, float>, nombre: int}
     */
    public function depenses(string $du, string $au, ?int $etablissementId, int $utilisateurId): array
    {
        $conditions = ['d.date_depense >= ?', 'd.date_depense < ?'];
        $params = [$du . ' 00:00:00', (new \DateTimeImmutable($au))->modify('+1 day')->format('Y-m-d 00:00:00')];
        $ids = Acces::etablissements($utilisateurId, 'rapports.voir');
        if ($etablissementId !== null) {
            $conditions[] = 'd.etablissement_id = ?';
            $params[] = $etablissementId;
        } elseif ($ids !== null) {
            $conditions[] = $ids === [] ? '0 = 1' : 'd.etablissement_id IN (' . implode(',', array_map('intval', $ids)) . ')';
        }
        $lignes = Database::tous(
            "SELECT d.id, d.etablissement_id, d.categorie_id, cat.nom_fr, cat.nom_en, p.devise_id, p.montant, m.contre_valeur, m.reference_id
               FROM depenses d
               JOIN categories_depenses cat ON cat.id = d.categorie_id
               JOIN depense_paiements p ON p.depense_id = d.id
          LEFT JOIN mouvements_tresorerie m ON m.nature = 'depense' AND m.source_id = d.id AND m.compte_id = p.compte_id AND m.devise_id = p.devise_id
              WHERE " . implode(' AND ', $conditions),
            $params
        );
        $resultat = ['devises' => [], 'reference' => 0.0, 'categories' => [], 'etablissements' => [], 'nombre' => 0];
        $vues = [];
        foreach ($lignes as $l) {
            $ref = $this->enReference((float) $l['montant'], (int) $l['devise_id'], $l['contre_valeur'], $l['reference_id']);
            $resultat['devises'][(int) $l['devise_id']] = ($resultat['devises'][(int) $l['devise_id']] ?? 0.0) + (float) $l['montant'];
            $resultat['reference'] += $ref;
            $cat = (int) $l['categorie_id'];
            $resultat['categories'][$cat] ??= ['nom' => champ($l, 'nom'), 'reference' => 0.0];
            $resultat['categories'][$cat]['reference'] += $ref;
            $etab = $l['etablissement_id'] !== null ? (string) $l['etablissement_id'] : 'commun';
            $resultat['etablissements'][$etab] = ($resultat['etablissements'][$etab] ?? 0.0) + $ref;
            $vues[(int) $l['id']] = true;
        }
        $resultat['nombre'] = count($vues);
        uasort($resultat['categories'], static fn (array $a, array $b) => $b['reference'] <=> $a['reference']);
        return $resultat;
    }

    /** Salaires payés sur la période (module Personnel et paie), en référence, par établissement ; null sans ce module. */
    public function salaires(string $du, string $au, ?int $etablissementId, int $utilisateurId): ?array
    {
        if (!self::tableExiste('paiements_personnel') || !self::tableExiste('paiement_personnel_lignes')) {
            return null;
        }
        $conditions = ["pp.type = 'salaire'", "pp.statut = 'valide'", 'pp.date_paiement >= ?', 'pp.date_paiement < ?'];
        $params = [$du . ' 00:00:00', (new \DateTimeImmutable($au))->modify('+1 day')->format('Y-m-d 00:00:00')];
        [$portee, $paramsPortee] = self::portee($utilisateurId, 'rapports.voir', 'pp.etablissement_id');
        $conditions[] = $portee;
        $params = array_merge($params, $paramsPortee);
        if ($etablissementId !== null) {
            $conditions[] = 'pp.etablissement_id = ?';
            $params[] = $etablissementId;
        }
        try {
            $lignes = Database::tous(
                "SELECT pp.etablissement_id, l.devise_id, l.montant, m.contre_valeur, m.reference_id
                   FROM paiements_personnel pp
                   JOIN paiement_personnel_lignes l ON l.paiement_id = pp.id
              LEFT JOIN mouvements_tresorerie m ON m.nature = 'salaire' AND m.source_id = pp.id AND m.compte_id = l.compte_id AND m.devise_id = l.devise_id
                  WHERE " . implode(' AND ', $conditions),
                $params
            );
        } catch (\Throwable) {
            return null;
        }
        $resultat = ['devises' => [], 'reference' => 0.0, 'etablissements' => []];
        foreach ($lignes as $l) {
            $ref = $this->enReference((float) $l['montant'], (int) $l['devise_id'], $l['contre_valeur'], $l['reference_id']);
            $resultat['devises'][(int) $l['devise_id']] = ($resultat['devises'][(int) $l['devise_id']] ?? 0.0) + (float) $l['montant'];
            $resultat['reference'] += $ref;
            $resultat['etablissements'][(string) $l['etablissement_id']] = ($resultat['etablissements'][(string) $l['etablissement_id']] ?? 0.0) + $ref;
        }
        return $resultat;
    }

    /** Contre-valeur du journal si elle est en devise de référence, sinon conversion au taux du jour. */
    public function enReference(float $montant, int $deviseId, mixed $contreValeur = null, mixed $referenceId = null): float
    {
        if ($this->reference === null) {
            return 0.0;
        }
        if ($contreValeur !== null && (int) $referenceId === (int) $this->reference['id']) {
            return (float) $contreValeur;
        }
        if ($deviseId === (int) $this->reference['id']) {
            return $montant;
        }
        $devise = $this->devises->actives()[$deviseId] ?? null;
        $taux = $devise['taux'] ?? null;
        return $taux !== null && (float) $taux > 0 ? $montant / (float) $taux : 0.0;
    }

    // ---------- Présence (module Personnel et paie) ----------

    /**
     * Résumé de la présence sur la période, si le module Personnel et paie existe : personnes venues, présences,
     * heures, retards (arrivée après le début du créneau prévu + tolérance), absences (créneau passé sans pointage),
     * personnes présentes maintenant.
     */
    public function presence(string $du, string $au, ?int $etablissementId, int $utilisateurId): ?array
    {
        if (!self::tableExiste('pointages') || !self::tableExiste('employes')) {
            return null;
        }
        try {
            [$portee, $params] = self::portee($utilisateurId, 'rapports.voir', 'p.etablissement_id');
            $where = $portee . ' AND p.journee BETWEEN ? AND ?';
            $params = array_merge($params, [$du, $au]);
            if ($etablissementId !== null) {
                $where .= ' AND p.etablissement_id = ?';
                $params[] = $etablissementId;
            }
            $ligne = Database::un(
                'SELECT COUNT(*) AS presences, COUNT(DISTINCT p.employe_id) AS personnes,
                        COALESCE(SUM(TIMESTAMPDIFF(MINUTE, p.arrivee, p.depart)), 0) AS minutes
                   FROM pointages p WHERE ' . $where,
                $params
            );
            [$porteeMaintenant, $paramsMaintenant] = self::portee($utilisateurId, 'rapports.voir', 'p.etablissement_id');
            $maintenant = Database::un(
                'SELECT COUNT(DISTINCT p.employe_id) AS n FROM pointages p WHERE p.depart IS NULL AND p.arrivee >= ? AND ' . $porteeMaintenant
                . ($etablissementId !== null ? ' AND p.etablissement_id = ' . $etablissementId : ''),
                array_merge([date('Y-m-d H:i:s', time() - 20 * 3600)], $paramsMaintenant)
            );
            $retards = null;
            $absences = null;
            if (self::tableExiste('plannings')) {
                $tolerance = (int) (Database::un("SELECT valeur FROM parametres WHERE cle = 'personnel.tolerance_retard'")['valeur'] ?? 5);
                [$porteePl, $paramsPl] = self::portee($utilisateurId, 'rapports.voir', 'pl.etablissement_id');
                $wherePl = $porteePl . ' AND pl.journee BETWEEN ? AND ?' . ($etablissementId !== null ? ' AND pl.etablissement_id = ' . $etablissementId : '');
                $paramsPl = array_merge($paramsPl, [$du, $au]);
                $retards = (int) Database::un(
                    'SELECT COUNT(*) AS n FROM plannings pl
                       JOIN pointages p ON p.employe_id = pl.employe_id AND p.journee = pl.journee
                      WHERE ' . $wherePl . ' AND p.arrivee > DATE_ADD(pl.debut, INTERVAL ' . max(0, $tolerance) . ' MINUTE)',
                    $paramsPl
                )['n'];
                $absences = (int) Database::un(
                    'SELECT COUNT(*) AS n FROM plannings pl
                      WHERE ' . $wherePl . ' AND pl.fin < NOW()
                        AND NOT EXISTS (SELECT 1 FROM pointages p WHERE p.employe_id = pl.employe_id AND p.journee = pl.journee)',
                    $paramsPl
                )['n'];
            }
            return [
                'presences' => (int) $ligne['presences'],
                'personnes' => (int) $ligne['personnes'],
                'heures' => round((int) $ligne['minutes'] / 60, 1),
                'maintenant' => (int) ($maintenant['n'] ?? 0),
                'retards' => $retards,
                'absences' => $absences,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    // ---------- Rapports du personnel ----------

    /**
     * Classement des personnes qui ont vendu : CA (tickets payés), part à crédit et remboursée, tickets, articles,
     * panier moyen, annulations ; « CA retenu » = CA, ou (option credit_rembourse) la partie payée plus la partie du
     * crédit déjà remboursée. Rangé selon le critère : ca (défaut), tickets ou panier.
     * @return list<array>
     */
    public static function classement(array $ventes, string $critere, bool $creditRembourse): array
    {
        $groupes = self::grouper($ventes, static fn (array $v) => $v['vendeur_id'] ?? 0);
        $liste = [];
        foreach ($groupes as $id => $t) {
            $t['utilisateur_id'] = (int) $id ?: null;
            $t['retenu_locale'] = $creditRembourse ? $t['ca_locale'] - $t['non_rembourse_locale'] : $t['ca_locale'];
            $t['retenu_reference'] = $creditRembourse ? $t['ca_reference'] - $t['non_rembourse_reference'] : $t['ca_reference'];
            $t['panier_retenu'] = $t['tickets'] > 0 ? $t['retenu_locale'] / $t['tickets'] : 0.0;
            if ($t['tickets'] === 0 && $t['annulees'] === 0) {
                continue;
            }
            $liste[] = $t;
        }
        $valeur = static fn (array $t): float => match ($critere) {
            'tickets' => (float) $t['tickets'],
            'panier' => $t['panier_retenu'],
            default => $t['retenu_locale'],
        };
        usort($liste, static fn (array $a, array $b) => [$valeur($b), $b['retenu_locale'], $b['tickets']] <=> [$valeur($a), $a['retenu_locale'], $a['tickets']]);
        foreach ($liste as $i => &$t) {
            $t['rang'] = $i + 1;
            $t['valeur'] = $valeur($t);
        }
        unset($t);
        return $liste;
    }

    /** Valeur du critère d'une ligne de classement (pour la comparer à la période précédente). */
    public static function valeurCritere(array $t, string $critere): float
    {
        return match ($critere) {
            'tickets' => (float) $t['tickets'],
            'panier' => $t['panier_retenu'],
            default => $t['retenu_locale'],
        };
    }

    /**
     * Crédits accordés sur la période (accordé par), par personne : nombre, montant par devise et en référence.
     * @return array<int, array{nombre: int, devises: array<int, float>, reference: float}>
     */
    public function creditsAccordes(array $f, int $utilisateurId, bool $toutePortee = true): array
    {
        [$portee, $params] = $toutePortee ? self::portee($utilisateurId, 'rapports.voir', 'd.etablissement_id') : ['d.accorde_par = ?', [$utilisateurId]];
        [$periode, $paramsPeriode] = self::conditionJournees('d.cree_le', $f['du'], $f['au']);
        // Ardoises nées d'une vente toujours payée (une vente annulée annule son crédit)
        $where = $portee . ' AND ' . $periode . " AND d.vente_id IS NOT NULL AND EXISTS (SELECT 1 FROM ventes va WHERE va.id = d.vente_id AND va.statut = 'payee')";
        $params = array_merge($params, $paramsPeriode);
        $filtre = '';
        $extra = [];
        if (!empty($f['etablissement'])) {
            $filtre = ' AND d.etablissement_id = ?';
            $extra[] = (int) $f['etablissement'];
        }
        $lignes = Database::tous(
            'SELECT d.accorde_par, d.devise_id, COUNT(*) AS n, SUM(d.montant) AS montant
               FROM dettes d JOIN etablissements e ON e.id = d.etablissement_id
              WHERE (' . $where . ')' . $filtre . '
           GROUP BY d.accorde_par, d.devise_id',
            array_merge($params, $extra)
        );
        $resultat = [];
        foreach ($lignes as $l) {
            $id = (int) ($l['accorde_par'] ?? 0);
            $resultat[$id] ??= ['nombre' => 0, 'devises' => [], 'reference' => 0.0];
            $resultat[$id]['nombre'] += (int) $l['n'];
            $resultat[$id]['devises'][(int) $l['devise_id']] = (float) $l['montant'];
            $resultat[$id]['reference'] += $this->enReference((float) $l['montant'], (int) $l['devise_id']);
        }
        return $resultat;
    }

    /** Tables servies par personne sur la période (notes de la salle payées par ses ventes), si la salle existe. */
    public function tablesServies(array $ventes): ?array
    {
        if (!self::serviceEnSalle() || !self::colonneExiste('notes', 'table_id')) {
            return null;
        }
        $ids = array_map(static fn (array $v) => $v['id'], array_filter($ventes, static fn (array $v) => $v['statut'] === 'payee'));
        $parVente = [];
        foreach ($ventes as $v) {
            $parVente[$v['id']] = $v['vendeur_id'] ?? 0;
        }
        $tables = [];
        foreach (array_chunk($ids, 800) as $morceau) {
            foreach (Database::tous(
                'SELECT DISTINCT np.vente_id, n.id AS note_id FROM note_paiements np JOIN notes n ON n.id = np.note_id
                  WHERE n.table_id IS NOT NULL AND np.vente_id IN (' . implode(',', array_map('intval', $morceau)) . ')'
            ) as $l) {
                $tables[$parVente[(int) $l['vente_id']] ?? 0][(int) $l['note_id']] = true;
            }
        }
        return array_map('count', $tables);
    }

    /** Noms et photos des personnes. @return array<int, array{nom: string, avatar: ?string}> */
    public static function personnes(array $ids): array
    {
        $ids = array_values(array_filter(array_unique(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }
        $resultat = [];
        foreach (Database::tous('SELECT id, nom, avatar FROM utilisateurs WHERE id IN (' . implode(',', $ids) . ')') as $u) {
            $resultat[(int) $u['id']] = ['nom' => $u['nom'], 'avatar' => $u['avatar']];
        }
        return $resultat;
    }

    /**
     * Dettes des clients liées aux utilisateurs (responsable) : pour chacun, le reste dû par devise et en référence,
     * les clients concernés, le montant en retard, l'ancienneté, et ce qui a été remboursé sur la période.
     * Les dettes du personnel lui-même (client de type « personnel ») sont à part.
     * @param array{du: string, au: string, etablissement?: ?int, devise?: ?int, retard?: bool, responsable?: ?int} $f
     * @param bool $toutePortee false : seulement les dettes dont la personne est responsable
     */
    public function dettesParResponsable(array $f, int $utilisateurId, bool $toutePortee = true, ?string $aujourdhui = null): array
    {
        $aujourdhui ??= date('Y-m-d');
        $depot = new GestionDetteRepository();
        [$portee, $params] = $toutePortee ? self::portee($utilisateurId, 'rapports.voir', 'd.etablissement_id') : ['d.responsable_id = ?', [$utilisateurId]];
        $where = "d.statut = 'ouverte' AND d.reste > 0 AND " . $portee;
        if (!empty($f['etablissement'])) {
            $where .= ' AND d.etablissement_id = ?';
            $params[] = (int) $f['etablissement'];
        }
        if (!empty($f['devise'])) {
            $where .= ' AND d.devise_id = ?';
            $params[] = (int) $f['devise'];
        }
        if (!empty($f['retard'])) {
            $where .= ' AND d.echeance < ?';
            $params[] = $aujourdhui;
        }
        if (!empty($f['responsable'])) {
            $where .= ' AND d.responsable_id = ?';
            $params[] = (int) $f['responsable'];
        }
        $lignes = Database::tous(
            'SELECT d.id, d.client_id, d.etablissement_id, d.devise_id, d.montant, d.reste, d.echeance, d.cree_le, d.responsable_id,
                    d.vente_id, v.numero, c.nom AS client_nom, c.type AS client_type, c.utilisateur_id AS client_utilisateur,
                    e.nom AS etablissement_nom, DATEDIFF(?, DATE(d.cree_le)) AS age
               FROM dettes d
               JOIN clients c ON c.id = d.client_id
               JOIN etablissements e ON e.id = d.etablissement_id
          LEFT JOIN ventes v ON v.id = d.vente_id
              WHERE ' . $where . '
           ORDER BY d.cree_le, d.id',
            array_merge([$aujourdhui], $params)
        );
        $vide = static fn (): array => ['nb' => 0, 'clients' => [], 'devises' => [], 'reference' => 0.0, 'retard' => 0.0,
            'tranches' => array_fill_keys(GestionDetteRepository::TRANCHES, 0.0), 'rembourse' => [], 'rembourse_reference' => 0.0, 'dettes' => []];
        $clients = [];
        $personnel = [];
        foreach ($lignes as $d) {
            $ref = $depot->enReference((float) $d['reste'], (int) $d['devise_id']) ?? 0.0;
            $enRetard = $d['echeance'] < $aujourdhui;
            $age = (int) $d['age'];
            $tranche = $age <= 30 ? '0_30' : ($age <= 60 ? '31_60' : 'plus_60');
            $d['en_retard'] = $enRetard;
            $d['tranche'] = $tranche;
            $d['reference'] = $ref;
            if ($d['client_type'] === 'personnel') {
                $cle = (int) ($d['client_utilisateur'] ?? 0);
                $personnel[$cle] ??= $vide() + ['nom' => $d['client_nom']];
                $groupe = &$personnel[$cle];
            } else {
                $cle = (int) ($d['responsable_id'] ?? 0);
                $clients[$cle] ??= $vide();
                $groupe = &$clients[$cle];
            }
            $groupe['nb']++;
            $groupe['clients'][(int) $d['client_id']] = true;
            $groupe['devises'][(int) $d['devise_id']] = ($groupe['devises'][(int) $d['devise_id']] ?? 0.0) + (float) $d['reste'];
            $groupe['reference'] += $ref;
            $groupe['tranches'][$tranche] += $ref;
            if ($enRetard) {
                $groupe['retard'] += $ref;
            }
            $groupe['dettes'][] = $d;
            unset($groupe);
        }

        // Remboursé sur la période, dettes de clients dont la personne est responsable (remboursements valides)
        [$porteeR, $paramsR] = $toutePortee ? self::portee($utilisateurId, 'rapports.voir', 'd.etablissement_id') : ['d.responsable_id = ?', [$utilisateurId]];
        $whereR = $porteeR . " AND r.statut = 'valide' AND r.cree_le >= ? AND r.cree_le < ? AND c.type <> 'personnel'";
        $paramsR = array_merge($paramsR, [$f['du'] . ' 00:00:00', (new \DateTimeImmutable($f['au']))->modify('+1 day')->format('Y-m-d 00:00:00')]);
        if (!empty($f['etablissement'])) {
            $whereR .= ' AND d.etablissement_id = ?';
            $paramsR[] = (int) $f['etablissement'];
        }
        if (!empty($f['devise'])) {
            $whereR .= ' AND d.devise_id = ?';
            $paramsR[] = (int) $f['devise'];
        }
        if (!empty($f['responsable'])) {
            $whereR .= ' AND d.responsable_id = ?';
            $paramsR[] = (int) $f['responsable'];
        }
        foreach (Database::tous(
            'SELECT d.responsable_id, d.devise_id, SUM(rd.montant) AS montant
               FROM remboursement_dettes rd
               JOIN remboursements r ON r.id = rd.remboursement_id
               JOIN dettes d ON d.id = rd.dette_id
               JOIN clients c ON c.id = d.client_id
              WHERE ' . $whereR . '
           GROUP BY d.responsable_id, d.devise_id',
            $paramsR
        ) as $l) {
            $cle = (int) ($l['responsable_id'] ?? 0);
            $clients[$cle] ??= $vide();
            $clients[$cle]['rembourse'][(int) $l['devise_id']] = (float) $l['montant'];
            $clients[$cle]['rembourse_reference'] += $depot->enReference((float) $l['montant'], (int) $l['devise_id']) ?? 0.0;
        }
        foreach ([&$clients, &$personnel] as &$liste) {
            foreach ($liste as &$g) {
                $g['nb_clients'] = count($g['clients']);
                unset($g['clients']);
            }
            unset($g);
            uasort($liste, static fn (array $a, array $b) => [$b['reference'], $b['rembourse_reference']] <=> [$a['reference'], $a['rembourse_reference']]);
        }
        unset($liste);
        return ['clients' => $clients, 'personnel' => $personnel];
    }
}
