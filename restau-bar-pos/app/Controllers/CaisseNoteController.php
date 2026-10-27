<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Acces;
use App\Core\CaissePartagee;
use App\Core\Database;
use App\Core\Journal;
use App\Core\Plateforme;
use App\Core\Session;
use App\Core\View;
use App\Repositories\CuisineRepository;
use App\Repositories\DeviseRepository;
use App\Repositories\NoteRepository;
use App\Repositories\SalleRepository;
use App\Repositories\ServiceRepository;

/**
 * Notes ouvertes à la caisse (réponses JSON) : état de la salle, envoyer un ticket à une table ou à une note nommée
 * sans le payer, ajouter des lignes (plusieurs serveurs), addition (pré-note), lancer la suite en cuisine, plats
 * servis, transférer et fusionner des notes, annuler une ligne déjà envoyée (validation par un supérieur).
 * Le paiement d'une note passe par CaisseController::encaisser() (champ « note ») : tous les moyens de paiement.
 *
 * Droits : « caisse.vendre » pour tout ce qui précède (un serveur sans « caisse.encaisser » ouvre des notes,
 * envoie et demande l'addition ; un caissier encaisse) ; « caisse.annuler » pour valider une annulation.
 */
final class CaisseNoteController
{
    public const REMARQUES = ['bleu', 'saignant', 'a_point', 'bien_cuit', 'sans_piment', 'sans_oignon', 'sans_glacons', 'peu_sale', 'a_emporter'];
    public const MOTIFS = ['erreur', 'change_avis', 'renvoye', 'attente', 'autre'];
    public const INTERVALLE_MS = 8000;

    private NoteRepository $notes;

    public function __construct()
    {
        $this->notes = new NoteRepository();
    }

    // ---------- Données de la caisse ----------

    /**
     * Ce que la caisse doit savoir de la salle et de la cuisine (null sans le module Salle) : tables du plan,
     * zones et leur grille du moment, modules du service en cours, personnes qui peuvent valider une annulation.
     */
    public static function donneesCaisse(array $etab, array $personne, array $contexte, array $zones): ?array
    {
        if (!Plateforme::moduleInclus('salle')) {
            return null;
        }
        $etabId = (int) $etab['id'];
        $services = new ServiceRepository();
        $modules = $services->modules($etab, $contexte['service']);
        $modules['cuisine'] = $modules['cuisine'] && Plateforme::moduleInclus('cuisine');
        $tables = array_map(static fn (array $t) => [
            'id' => (int) $t['id'], 'nom' => $t['nom'], 'zone' => $t['zone_code'] ?? '', 'places' => (int) $t['places'], 'forme' => $t['forme'],
            'x' => (int) $t['x'], 'y' => (int) $t['y'], 'l' => (int) $t['largeur'], 'h' => (int) $t['hauteur'],
        ], (new SalleRepository())->tables($etabId));
        $zonesJson = array_map(static fn (array $z) => [
            'code' => $z['code'], 'nom' => champ($z, 'nom'), 'grille' => $services->grille($etab, $z)['grille'],
        ], $zones);
        $appareil = CaissePartagee::appareil();
        $textesRemarques = [];
        foreach (self::REMARQUES as $r) {
            $textesRemarques[] = t('note.remarque_' . $r);
        }
        return [
            'tables' => $tables,
            'zones' => $zonesJson,
            'grilleSansZone' => $services->grille($etab, null)['grille'],
            'colonnes' => SalleRepository::COLONNES, 'rangees' => SalleRepository::RANGEES,
            'modules' => $modules,
            'service' => $contexte['service'] ? ['nom' => champ($contexte['service'], 'nom'), 'debut' => $contexte['service']['debut'], 'fin' => $contexte['service']['fin']] : null,
            'happy' => $contexte['happy'] ? ['nom' => champ($contexte['happy'], 'nom'), 'fin' => substr($contexte['happy']['fin'], 0, 5)] : null,
            'annuler' => Acces::a($personne['id'], 'caisse.annuler', $etabId),
            'valideurs' => self::valideurs($etabId),
            'remarques' => $textesRemarques,
            'motifs' => array_map(static fn (string $m) => ['code' => $m, 'texte' => t('note.motif_' . $m)], self::MOTIFS),
            'verrouEnvoi' => $appareil !== null && (bool) $etab['verrou_apres_encaissement'],
            'intervalle' => self::INTERVALLE_MS,
            'textes' => self::textes(),
        ];
    }

    /** Personnes qui peuvent valider une annulation avec leur PIN (caisse.annuler pour l'établissement). */
    public static function valideurs(int $etabId): array
    {
        $liste = [];
        foreach (Database::tous('SELECT id, nom, avatar, pin_bloque_le FROM utilisateurs WHERE actif = 1 AND pin IS NOT NULL ORDER BY nom') as $u) {
            if (Acces::a((int) $u['id'], 'caisse.annuler', $etabId)) {
                $liste[] = ['id' => (int) $u['id'], 'nom' => $u['nom'], 'avatar' => $u['avatar'], 'bloque' => $u['pin_bloque_le'] !== null];
            }
        }
        return $liste;
    }

    /** Textes de caisse-salle.js (lang/<langue>/salle.php, « note.* »). */
    private static function textes(): array
    {
        $cles = ['salle', 'fermer', 'comptoir', 'vente_comptoir', 'nouvelle_note', 'nom_note', 'nom_exemple', 'ouvrir', 'libre', 'occupee', 'addition_demandee',
            'reservee', 'pret', 'prets', 'a_lancer', 'minutes', 'heures', 'envoyer', 'envoyer_table', 'envoye', 'envoye_verrou', 'addition', 'encaisser_note',
            'partager', 'transferer', 'fusionner', 'lancer', 'servi', 'partager_court', 'deja_envoye', 'nouveau', 'par', 'annuler_ligne', 'annulee', 'choisir_table',
            'choisir_transfert', 'choisir_fusion', 'occupee_fusionner', 'options', 'remarque', 'remarque_libre', 'allergie', 'allergie_aide', 'temps_2',
            'temps_2_aide', 'valider', 'pin_titre', 'pin_aide', 'pin_qui', 'motif', 'quantite_annuler', 'confirmer_annulation', 'statut_sans', 'statut_attente',
            'statut_recu', 'statut_preparation', 'statut_pret', 'statut_servi', 'par_articles', 'parts_egales', 'nb_parts', 'part_de', 'payer_part',
            'payer_selection', 'reste_note', 'tout', 'aucune', 'notes_sans_table', 'reservation', 'personnes', 'client_arrive', 'erreur', 'note_fermee',
            'places', 'prix_changes', 'recharger', 'imprimer_bons', 'fermer_vide', 'zone_toutes', 'sans_zone', 'plats_prets', 'notifications', 'table',
            'nouvelle_table_note', 'selection_vide', 'part_suivante', 'mode_comptoir', 'tables_vides', 'allergie_badge', 'servi_par_defaut'];
        $textes = [];
        foreach ($cles as $cle) {
            $textes[$cle] = t('note.' . $cle);
        }
        return $textes;
    }

    /** Texte libre d'une ligne (précision, allergie) : 160 caractères au plus, null si vide. */
    public static function texteLibre(mixed $valeur): ?string
    {
        if (!is_string($valeur)) {
            return null;
        }
        $valeur = trim(preg_replace('/\s+/u', ' ', $valeur) ?? '');
        return $valeur === '' ? null : mb_substr($valeur, 0, 160);
    }

    // ---------- Lecture ----------

    /** GET /caisse/salle?etab= : tables et notes, ruptures, grille du moment, plats prêts pour moi. */
    public function etat(): void
    {
        ['personne' => $personne, 'etab' => $etab] = $this->acces(false);
        $etabId = (int) $etab['id'];
        $etat = (new SalleRepository())->etat($etabId, $this->notes);
        $zone = is_string($_GET['zone'] ?? null) && $_GET['zone'] !== '' ? (new \App\Repositories\EtablissementRepository())->zoneParCode($etabId, $_GET['zone']) : null;
        // Stock en mode « bloquer » : formats épuisés au comptoir qui sert cette caisse (appareil, zone)
        $epuises = CaisseController::epuises($etab, $zone);
        $this->repondre(200, [
            'tables' => $etat['tables'], 'notes' => $etat['notes'],
            'indisponibles' => (new CuisineRepository())->indisponibles($etabId), 'epuises' => $epuises,
            'grille' => (new ServiceRepository())->grille($etab, $zone)['grille'],
            'prets' => $this->pretsPour($etabId, $personne['id']),
            'maintenant' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Plats prêts des notes de cette personne (qui a ouvert la note ou commandé la ligne, ou encaissé la vente directe). */
    private function pretsPour(int $etabId, int $uid): array
    {
        $lignes = Database::tous(
            "SELECT l.id, l.note_id, l.quantite, l.libelle, n.type, n.nom AS note_nom, t.nom AS table_nom, v.numero AS vente_numero
               FROM note_lignes l JOIN notes n ON n.id = l.note_id
          LEFT JOIN salle_tables t ON t.id = n.table_id LEFT JOIN ventes v ON v.id = n.vente_id
              WHERE n.etablissement_id = ? AND l.statut = 'pret' AND l.annulee_le IS NULL AND n.statut IN ('ouverte', 'payee')
                AND (n.serveur_id = ? OR l.ajoute_par = ?) AND l.pret_le >= ?
              ORDER BY l.pret_le, l.id",
            [$etabId, $uid, $uid, date('Y-m-d H:i:s', time() - 6 * 3600)]
        );
        $prets = [];
        foreach ($lignes as $l) {
            $id = (int) $l['note_id'];
            $prets[$id] ??= ['note' => $id, 'titre' => CuisineRepository::titreNote($l), 'comptoir' => $l['type'] === 'comptoir', 'lignes' => []];
            $prets[$id]['lignes'][] = ['id' => (int) $l['id'], 'qte' => (int) $l['quantite'], 'libelle' => $l['libelle']];
        }
        return array_values($prets);
    }

    /** GET /caisse/notes/{id}?etab= */
    public function voir(string $id): void
    {
        ['etab' => $etab] = $this->acces(false);
        $note = $this->noteOu404($id, $etab);
        $this->repondre(200, ['note' => $this->noteJson($etab, $note)]);
    }

    /** Note pour la caisse : lignes (avec leurs prix actuels dans chaque devise), reste à payer par devise. */
    public function noteJson(array $etab, array $note): array
    {
        $actives = array_values(array_filter($note['lignes'], static fn (array $l) => $l['annulee_le'] === null));
        $prix = (new CaisseController())->prixLignesNote($etab, $actives);
        $codes = array_column(CaisseController::devisesCaisse($etab)['acceptees'], 'code');
        $totaux = array_fill_keys($codes, 0.0);
        $lignes = [];
        foreach ($note['lignes'] as $l) {
            $reste = NoteRepository::reste($l);
            $pu = $prix[(int) $l['id']] ?? [];
            if ($l['annulee_le'] === null) {
                foreach ($codes as $code) {
                    $totaux[$code] = $totaux[$code] === null || ($pu[$code] ?? null) === null ? ($reste > 0 ? null : $totaux[$code]) : $totaux[$code] + $pu[$code] * $reste;
                }
            }
            $lignes[] = [
                'id' => (int) $l['id'], 'libelle' => $l['libelle'], 'format' => $l['format'], 'qte' => (int) $l['quantite'],
                'payee' => (float) $l['quantite_payee'], 'reste' => $reste, 'prix' => $pu, 'statut' => $l['statut'],
                'poste' => $l['poste_nom'], 'rem' => $l['remarque'], 'all' => $l['allergie'], 'temps' => (int) $l['temps'],
                'par' => $l['ajoute_par_nom'], 'parId' => $l['ajoute_par'] !== null ? (int) $l['ajoute_par'] : null,
                'le' => substr($l['ajoute_le'], 11, 5), 'annulee' => $l['annulee_le'] !== null, 'motif' => $l['annulation_motif'],
                'validePar' => $l['validee_par_nom'], 'perte' => (bool) $l['perte'],
            ];
        }
        // Minimum de consommation (carré VIP) : progression ; invité de la note (promoteur)
        $minimum = null;
        if (\App\Repositories\MinimumRepository::disponible() && $note['type'] === 'note') {
            $caisse = new CaisseController();
            ['acceptees' => $acc, 'principale' => $principale] = CaisseController::devisesCaisse($etab);
            $info = $caisse->minimumInfo($note, $etab, ['principale' => $principale], array_column($acc, null, 'code'));
            if ($info !== null) {
                $minimum = ['action' => $info['action'], 'minimum' => $info['minimum'], 'manque' => $info['manque'], 'consomme' => $info['consomme'],
                    'atteint' => $info['atteint'], 'leve' => ($note['minimum_leve_le'] ?? null) !== null];
            }
        }
        $invite = null;
        if (!empty($note['invite_id'])) {
            $i = Database::un('SELECT i.nom, p.nom AS promoteur FROM invites i LEFT JOIN promoteurs p ON p.id = i.promoteur_id WHERE i.id = ?', [(int) $note['invite_id']]);
            $invite = $i !== null ? ['id' => (int) $note['invite_id'], 'nom' => $i['nom'], 'promoteur' => $i['promoteur']] : null;
        }
        return [
            'minimum' => $minimum, 'invite' => $invite,
            'id' => (int) $note['id'], 'version' => (int) $note['version'], 'statut' => $note['statut'],
            'table' => $note['table_id'] !== null ? ['id' => (int) $note['table_id'], 'nom' => $note['table_nom']] : null,
            'nom' => $note['nom'], 'zone' => $note['zone_code'] ?? '', 'zoneNom' => $note['zone_nom_fr'] !== null ? champ($note, 'zone_nom') : null,
            'serveur' => $note['serveur_id'] !== null ? ['id' => (int) $note['serveur_id'], 'nom' => $note['serveur_nom']] : null,
            'ouverte' => $note['ouverte_le'], 'minutes' => (int) floor((time() - strtotime($note['ouverte_le'])) / 60),
            'addition' => $note['addition_le'] !== null, 'parts' => $note['parts'] !== null ? (int) $note['parts'] : null, 'partsPayees' => (int) $note['parts_payees'],
            'lignes' => $lignes, 'totaux' => $totaux,
            'ventes' => array_map(static fn (array $v) => $v['numero'], $this->notes->ventes((int) $note['id'])),
        ];
    }

    // ---------- Envoyer ----------

    /**
     * POST /caisse/notes/envoyer : envoi = {cle, note | table | nom, reservation?, lignes: [{id, qte, rem, all, temps}]}.
     * Crée la note si besoin (une table déjà occupée reçoit les lignes dans sa note), chiffre les lignes avec la
     * grille du moment de la zone, les route vers leur poste ; un double envoi (même clé) n'ajoute rien.
     */
    public function envoyer(): void
    {
        ['personne' => $personne, 'etab' => $etab, 'appareil' => $appareil] = $this->acces(true);
        $etabId = (int) $etab['id'];
        $envoi = json_decode(is_string($_POST['envoi'] ?? null) ? $_POST['envoi'] : '', true);
        if (!is_array($envoi)) {
            $this->repondre(422, ['erreur' => t('encaisser.illisible')]);
        }
        $cle = is_string($envoi['cle'] ?? null) && preg_match('/^[0-9a-f-]{36}$/', $envoi['cle']) ? $envoi['cle'] : null;
        $deja = $cle !== null ? ($_SESSION['envois_notes'][$cle] ?? null) : null;
        if ($deja !== null && ($note = $this->notes->note((int) $deja)) !== null) {
            $this->repondre(200, ['note' => $this->noteJson($etab, $note), 'bons' => [], 'verrouille' => false]);
        }

        $salle = new SalleRepository();
        $note = null;
        $table = null;
        if (!empty($envoi['note'])) {
            $note = $this->notes->note((int) $envoi['note']);
            if ($note === null || (int) $note['etablissement_id'] !== $etabId || $note['type'] !== 'note') {
                $this->repondre(404, ['erreur' => t('note.introuvable')]);
            }
            if ($note['statut'] !== 'ouverte') {
                $this->repondre(409, ['erreur' => t('note.deja_fermee'), 'code' => 'note_fermee']);
            }
        } elseif (!empty($envoi['table'])) {
            $table = $salle->table((int) $envoi['table']);
            if ($table === null || (int) $table['etablissement_id'] !== $etabId || !$table['actif']) {
                $this->repondre(422, ['erreur' => t('note.table_inconnue')]);
            }
            // La table a été ouverte entre-temps par un collègue : on ajoute à sa note
            $ouverte = $this->notes->ouverteSurTable((int) $table['id']);
            $note = $ouverte !== null ? $this->notes->note((int) $ouverte['id']) : null;
        } else {
            $nom = self::texteLibre($envoi['nom'] ?? null);
            if ($nom === null) {
                $this->repondre(422, ['erreur' => t('note.nom_requis')]);
            }
        }

        // Lignes : vérifiées et chiffrées comme à l'encaissement, avec la grille de la zone de la note
        // Zone : celle de la note, sinon celle de la table, sinon (note nommée) la zone choisie à la caisse
        $zoneNommee = $note === null && $table === null && is_string($envoi['zone'] ?? null) && $envoi['zone'] !== ''
            ? (new \App\Repositories\EtablissementRepository())->zoneParCode($etabId, $envoi['zone']) : null;
        $zoneCode = $note !== null ? ($note['zone_code'] ?? null) : ($table['zone_code'] ?? $zoneNommee['code'] ?? null);
        $caisse = new CaisseController();
        $contexte = $caisse->contexte($etab, $zoneCode);
        $principale = $contexte['principale']['code'];
        $formats = [];
        foreach ($contexte['donnees']['produits'] as $produit) {
            foreach ($produit['formats'] as $format) {
                $formats[$format['id']] = ['format' => $format, 'produit' => $produit];
            }
        }
        $modules = (new ServiceRepository())->modules($etab, $contexte['service']);
        $cuisine = $modules['cuisine'] && Plateforme::moduleInclus('cuisine') ? new CuisineRepository() : null;
        $zoneId = $note !== null ? ($note['zone_id'] !== null ? (int) $note['zone_id'] : null)
            : ($table !== null ? ($table['zone_id'] !== null ? (int) $table['zone_id'] : null) : ($zoneNommee ? (int) $zoneNommee['id'] : null));
        $lignes = [];
        $formules = new \App\Repositories\FormuleRepository();
        foreach (is_array($envoi['lignes'] ?? null) ? $envoi['lignes'] : [] as $l) {
            $f = $formats[(int) ($l['id'] ?? 0)] ?? null;
            $quantite = (int) ($l['qte'] ?? 0);
            if ($f !== null && !empty($f['format']['epuise'])) {
                $nomEpuise = $f['format']['taille'] !== '' ? $f['produit']['nom'] . ' ' . $f['format']['taille'] : $f['produit']['nom'];
                $this->repondre(422, ['erreur' => t('encaisser.epuise', ['articles' => $nomEpuise]), 'code' => 'epuise']);
            }
            if ($f === null || $quantite < 1 || $quantite > 999 || !$f['format']['disponible'] || ($f['format']['prix'][$principale] ?? null) === null) {
                $this->repondre(422, ['erreur' => t('encaisser.ligne_invalide')]);
            }
            $categorie = (int) ($f['produit']['sous'] ?: $f['produit']['categorie']);
            // Formule (bouteille avec softs) : les composants choisis, vérifiés (le stock compte : on ajoute une ligne)
            $composants = [];
            $formule = $formules->formule((int) $f['format']['tailleId']);
            if ($formule !== null) {
                try {
                    $composants = $formules->valider($formule, $l['comp'] ?? null, $contexte['epuises']);
                } catch (\InvalidArgumentException $e) {
                    $message = $e->getMessage();
                    $this->repondre(422, str_starts_with($message, 'epuise:')
                        ? ['erreur' => t('encaisser.epuise', ['articles' => substr($message, 7)]), 'code' => 'epuise']
                        : ['erreur' => t('nuit.composants_invalides', ['formule' => $f['produit']['nom']]), 'code' => 'composants']);
                }
            }
            $remarque = self::texteLibre($l['rem'] ?? null);
            if ($composants !== []) {
                $remarque = self::texteLibre(CaisseController::texteComposants($composants, 1) . ($remarque !== null ? ' — ' . $remarque : ''));
            }
            $lignes[] = [
                'produit_id' => (int) $f['format']['tailleId'], 'conditionnement_id' => (int) $f['format']['id'], 'categorie_id' => $categorie,
                'libelle' => $f['format']['taille'] !== '' ? $f['produit']['nom'] . ' ' . $f['format']['taille'] : $f['produit']['nom'],
                'format' => (!$f['format']['unite'] || $f['format']['freres'] > 1) ? $f['format']['nom'] : null,
                'quantite' => $quantite, 'grille' => $contexte['grille'], 'prix' => (float) $f['format']['prix'][$principale],
                'prix_devises' => array_filter($f['format']['prix'], static fn ($p) => $p !== null),
                'remarque' => $remarque, 'allergie' => self::texteLibre($l['all'] ?? null),
                'temps' => (int) ($l['temps'] ?? 1) === 2 ? 2 : 1,
                'poste_id' => $cuisine?->postePour($etabId, $categorie, $zoneId, $appareil ? (int) $appareil['id'] : null),
                'composants' => array_map(static fn (array $c) => ['id' => $c['conditionnement_id'], 'qte' => $c['quantite'], 'libelle' => $c['libelle'],
                    'format' => $c['format'], 'produit_id' => $c['produit_id']], $composants),
            ];
        }
        if ($lignes === []) {
            $this->repondre(422, ['erreur' => t('encaisser.vide')]);
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            if ($note === null) {
                $reservation = !empty($envoi['reservation']) ? $salle->reservation((int) $envoi['reservation']) : null;
                $noteId = $this->notes->creer([
                    'etablissement_id' => $etabId, 'table_id' => $table ? (int) $table['id'] : null,
                    'nom' => $table ? null : self::texteLibre($envoi['nom'] ?? null), 'zone_id' => $zoneId, 'serveur_id' => $personne['id'],
                    'couverts' => $reservation ? (int) $reservation['personnes'] : null,
                    'reservation_id' => $reservation && (int) $reservation['etablissement_id'] === $etabId ? (int) $reservation['id'] : null,
                ]);
                $nouvelle = true;
            } else {
                $noteId = (int) $note['id'];
                $nouvelle = false;
            }
            $bons = $this->notes->ajouter($noteId, $lignes, $personne['id']);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        if ($cle !== null) {
            $_SESSION['envois_notes'] = array_slice(($_SESSION['envois_notes'] ?? []) + [$cle => $noteId], -50, null, true);
        }
        $note = $this->notes->note($noteId);
        if ($nouvelle) {
            NoteRepository::journal('note_ouverte', $note, [], $personne['id'], $appareil ? (int) $appareil['id'] : null);
        }
        // Caisse partagée verrouillée après chaque encaissement : aussi après un envoi (chacun garde ses commandes)
        $verrouille = false;
        if ($appareil !== null && $etab['verrou_apres_encaissement']) {
            CaissePartagee::verrouiller();
            $verrouille = true;
        }
        $this->repondre(200, ['note' => $this->noteJson($etab, $note), 'bons' => $bons, 'verrouille' => $verrouille, 'csrf' => Session::jetonCsrf()]);
    }

    /**
     * Vente directe (comptoir, à emporter, mode rapide) avec des plats : une note « comptoir » déjà payée part en
     * cuisine pour qu'ils soient préparés. Ne bloque jamais la vente.
     */
    public static function venteEnCuisine(int $venteId, array $etab, array $personne, array $contexte, array $lignes): void
    {
        if ($lignes === [] || !Plateforme::moduleInclus('salle') || !Plateforme::moduleInclus('cuisine')) {
            return;
        }
        try {
            $modules = (new ServiceRepository())->modules($etab, $contexte['service']);
            if (!$modules['cuisine']) {
                return;
            }
            $cuisine = new CuisineRepository();
            $etabId = (int) $etab['id'];
            $appareil = CaissePartagee::appareil();
            $zoneId = $contexte['zone'] ? (int) $contexte['zone']['id'] : null;
            $aEnvoyer = [];
            foreach ($lignes as $l) {
                $poste = $cuisine->postePour($etabId, $l['categorie_id'], $zoneId, $appareil ? (int) $appareil['id'] : null);
                if ($poste !== null) {
                    $aEnvoyer[] = [
                        'produit_id' => $l['produit_id'], 'conditionnement_id' => $l['conditionnement_id'], 'categorie_id' => $l['categorie_id'],
                        'libelle' => $l['libelle'], 'format' => $l['format'], 'quantite' => (int) $l['quantite'], 'grille' => $l['grille'],
                        'prix' => $l['prix'], 'prix_devises' => $l['prix_devises'], 'remarque' => $l['remarque'], 'allergie' => $l['allergie'],
                        'temps' => 1, 'poste_id' => $poste,
                    ];
                }
            }
            if ($aEnvoyer === []) {
                return;
            }
            $notes = new NoteRepository();
            $id = $notes->creer([
                'etablissement_id' => $etabId, 'type' => 'comptoir', 'statut' => 'payee', 'table_id' => null, 'nom' => null,
                'zone_id' => $zoneId, 'serveur_id' => $personne['id'], 'vente_id' => $venteId,
            ]);
            $notes->ajouter($id, $aEnvoyer, $personne['id']);
            Database::executer('UPDATE note_lignes SET quantite_payee = quantite WHERE note_id = ?', [$id]);
        } catch (\Throwable $e) {
            error_log('Vente directe non envoyée en cuisine : ' . $e->getMessage());
        }
    }

    // ---------- Actions sur une note ----------

    /** POST /caisse/notes/{id}/addition : addition demandée, pré-note à imprimer (80 mm). */
    public function addition(string $id): void
    {
        ['personne' => $personne, 'etab' => $etab, 'appareil' => $appareil] = $this->acces(true);
        $note = $this->noteOuverte($id, $etab);
        $this->notes->demanderAddition((int) $note['id'], $personne['id']);
        $note = $this->notes->note((int) $note['id']);
        NoteRepository::journal('note_addition', $note, [], $personne['id'], $appareil ? (int) $appareil['id'] : null);
        $json = $this->noteJson($etab, $note);
        $devises = (new DeviseRepository());
        $this->repondre(200, [
            'note' => $json,
            'addition' => View::rendre('caisse/_addition', ['note' => $note, 'json' => $json, 'etablissement' => $etab, 'personne' => $personne,
                'devises' => array_column(CaisseController::devisesCaisse($etab)['acceptees'], null, 'code'), 'formateur' => $devises]),
            'csrf' => Session::jetonCsrf(),
        ]);
    }

    /** POST /caisse/notes/{id}/lancer : les plats « à lancer » partent en cuisine. */
    public function lancer(string $id): void
    {
        ['personne' => $personne, 'etab' => $etab] = $this->acces(true);
        $note = $this->noteOuverte($id, $etab);
        $bons = $this->notes->lancer((int) $note['id'], $personne['id']);
        $this->repondre(200, ['note' => $this->noteJson($etab, $this->notes->note((int) $note['id'])), 'bons' => $bons, 'csrf' => Session::jetonCsrf()]);
    }

    /** POST /caisse/notes/{id}/servi : plats prêts apportés (lignes = [ids], toutes les prêtes sinon). */
    public function servi(string $id): void
    {
        ['etab' => $etab] = $this->acces(true);
        $note = $this->noteOu404($id, $etab);
        $ids = json_decode(is_string($_POST['lignes'] ?? null) ? $_POST['lignes'] : 'null', true);
        $this->notes->servir((int) $note['id'], is_array($ids) ? array_map('intval', $ids) : null);
        $this->repondre(200, ['ok' => true, 'csrf' => Session::jetonCsrf()]);
    }

    /** POST /caisse/notes/{id}/transferer : table = id (libre) ou nom (note nommée). Table occupée : 409, proposer la fusion. */
    public function transferer(string $id): void
    {
        ['personne' => $personne, 'etab' => $etab, 'appareil' => $appareil] = $this->acces(true);
        $note = $this->noteOuverte($id, $etab);
        $avant = CuisineRepository::titreNote($note);
        $tableId = (int) ($_POST['table'] ?? 0);
        if ($tableId > 0) {
            $table = (new SalleRepository())->table($tableId);
            if ($table === null || (int) $table['etablissement_id'] !== (int) $etab['id'] || !$table['actif']) {
                $this->repondre(422, ['erreur' => t('note.table_inconnue')]);
            }
            $occupee = $this->notes->ouverteSurTable($tableId);
            if ($occupee !== null && (int) $occupee['id'] !== (int) $note['id']) {
                $this->repondre(409, ['erreur' => t('note.table_occupee', ['table' => $table['nom']]), 'code' => 'occupee', 'cible' => (int) $occupee['id']]);
            }
            $this->notes->transferer((int) $note['id'], $tableId, $table['zone_id'] !== null ? (int) $table['zone_id'] : null, null);
        } else {
            $nom = self::texteLibre($_POST['nom'] ?? null);
            if ($nom === null) {
                $this->repondre(422, ['erreur' => t('note.nom_requis')]);
            }
            $this->notes->transferer((int) $note['id'], null, $note['zone_id'] !== null ? (int) $note['zone_id'] : null, $nom);
        }
        $note = $this->notes->note((int) $note['id']);
        NoteRepository::journal('note_transferee', $note, ['de' => $avant], $personne['id'], $appareil ? (int) $appareil['id'] : null);
        $this->repondre(200, ['note' => $this->noteJson($etab, $note), 'csrf' => Session::jetonCsrf()]);
    }

    /** POST /caisse/notes/{id}/fusionner : cible = note ouverte qui reçoit toutes les lignes de celle-ci. */
    public function fusionner(string $id): void
    {
        ['personne' => $personne, 'etab' => $etab, 'appareil' => $appareil] = $this->acces(true);
        $note = $this->noteOuverte($id, $etab);
        $cible = $this->noteOuverte((string) (int) ($_POST['cible'] ?? 0), $etab);
        if ((int) $cible['id'] === (int) $note['id']) {
            $this->repondre(422, ['erreur' => t('note.fusion_meme')]);
        }
        $this->notes->fusionner((int) $note['id'], (int) $cible['id']);
        $cible = $this->notes->note((int) $cible['id']);
        NoteRepository::journal('note_fusionnee', $cible, ['source' => CuisineRepository::titreNote($note) . ' #' . (int) $note['id']], $personne['id'], $appareil ? (int) $appareil['id'] : null);
        $this->repondre(200, ['note' => $this->noteJson($etab, $cible), 'csrf' => Session::jetonCsrf()]);
    }

    /** POST /caisse/notes/{id}/fermer : note ouverte par erreur, sans aucune ligne. */
    public function fermer(string $id): void
    {
        ['etab' => $etab] = $this->acces(true);
        $note = $this->noteOuverte($id, $etab);
        if (!$this->notes->fermerVide((int) $note['id'])) {
            $this->repondre(422, ['erreur' => t('note.pas_vide')]);
        }
        $this->repondre(200, ['ok' => true, 'csrf' => Session::jetonCsrf()]);
    }

    /**
     * POST /caisse/notes/lignes/{id}/annuler : quantite, motif, et si la personne n'a pas « caisse.annuler » :
     * validateur + pin (le supérieur tape son PIN sur l'appareil). Le journal garde les deux noms.
     * Une ligne pas encore lancée (« à lancer ») s'annule sans validation.
     */
    public function annulerLigne(string $id): void
    {
        ['personne' => $personne, 'etab' => $etab, 'appareil' => $appareil] = $this->acces(true);
        $etabId = (int) $etab['id'];
        $ligne = ctype_digit($id) ? $this->notes->ligne((int) $id) : null;
        if ($ligne === null || (int) $ligne['etablissement_id'] !== $etabId) {
            $this->repondre(404, ['erreur' => t('note.introuvable')]);
        }
        if ($ligne['note_statut'] !== 'ouverte' || $ligne['annulee_le'] !== null || (float) $ligne['quantite_payee'] > NoteRepository::EPSILON) {
            $this->repondre(409, ['erreur' => t('note.ligne_non_annulable'), 'code' => 'non_annulable']);
        }
        $motifCode = in_array($_POST['motif'] ?? '', self::MOTIFS, true) ? $_POST['motif'] : 'autre';
        $precision = self::texteLibre($_POST['precision'] ?? null);
        $motif = t('note.motif_' . $motifCode) . ($precision !== null ? ' : ' . $precision : '');
        $validePar = null;
        if ($ligne['statut'] !== 'attente') {
            if (Acces::a($personne['id'], 'caisse.annuler', $etabId)) {
                $validePar = $personne['id'];
            } else {
                $validePar = $this->verifierPin((int) ($_POST['validateur'] ?? 0), (string) ($_POST['pin'] ?? ''), $etabId, $appareil);
            }
        }
        try {
            $annulee = $this->notes->annulerLigne($ligne, max(1, (int) ($_POST['quantite'] ?? $ligne['quantite'])), $personne['id'], $validePar, $motif);
        } catch (\DomainException) {
            $this->repondre(409, ['erreur' => t('note.ligne_non_annulable'), 'code' => 'non_annulable']);
        }
        $valideur = $validePar !== null ? (Database::un('SELECT nom FROM utilisateurs WHERE id = ?', [$validePar])['nom'] ?? null) : null;
        NoteRepository::journal('note_ligne_annulee', $annulee, [
            'ligne' => $annulee['libelle'], 'quantite' => (int) $annulee['quantite'], 'motif' => $motif,
            'demande_par' => $personne['nom'], 'valide_par' => $valideur, 'perte' => (bool) $annulee['perte'],
        ], $personne['id'], $appareil ? (int) $appareil['id'] : null);
        $this->repondre(200, ['note' => $this->noteJson($etab, $this->notes->note((int) $ligne['note_id'])), 'csrf' => Session::jetonCsrf()]);
    }

    /**
     * PIN d'un supérieur (caisse.annuler pour l'établissement) : mêmes règles que la connexion par PIN
     * (5 essais puis PIN bloqué ; trop d'échecs sur une caisse partagée : l'appareil refuse les PIN 10 minutes).
     */
    private function verifierPin(int $valideurId, string $pin, int $etabId, ?array $appareil): int
    {
        if ($appareil !== null && CaissePartagee::appareilSature((int) $appareil['id'])) {
            $this->repondre(429, ['erreur' => t('caisse_pin.appareil_sature'), 'code' => 'pin']);
        }
        $valideur = $valideurId > 0 ? Database::un('SELECT * FROM utilisateurs WHERE id = ? AND actif = 1 AND pin IS NOT NULL', [$valideurId]) : null;
        if ($valideur === null || !Acces::a($valideurId, 'caisse.annuler', $etabId)) {
            $this->repondre(403, ['erreur' => t('note.validation_requise'), 'code' => 'validation']);
        }
        if ($valideur['pin_bloque_le'] !== null) {
            $this->repondre(423, ['erreur' => t('caisse_pin.bloque', ['nom' => $valideur['nom']]), 'code' => 'pin']);
        }
        if (!CaissePartagee::pinValide($pin) || !password_verify($pin, $valideur['pin'])) {
            $essais = (int) $valideur['pin_essais'] + 1;
            $bloque = $essais >= CaissePartagee::ESSAIS_PIN;
            Database::executer('UPDATE utilisateurs SET pin_essais = ?, pin_bloque_le = ? WHERE id = ?', [$bloque ? 0 : $essais, $bloque ? date('Y-m-d H:i:s') : null, $valideurId]);
            Journal::ecrire($bloque ? 'pin_bloque' : 'pin_echec', $valideur['nom'], ['validation' => 'annulation'], $valideurId, $appareil ? (int) $appareil['id'] : null);
            $this->repondre(422, [
                'erreur' => $bloque ? t('caisse_pin.bloque', ['nom' => $valideur['nom']]) : t('caisse_pin.incorrect', ['reste' => CaissePartagee::ESSAIS_PIN - $essais]),
                'code' => 'pin',
            ]);
        }
        Database::executer('UPDATE utilisateurs SET pin_essais = 0 WHERE id = ?', [$valideurId]);
        return $valideurId;
    }

    /** GET /caisse/bon/{id}?etab= : bon de cuisine à imprimer (80 mm). */
    public function bon(string $id): void
    {
        ['etab' => $etab] = $this->acces(false);
        $bon = ctype_digit($id) ? (new CuisineRepository())->bon((int) $id) : null;
        if ($bon === null || (int) $bon['envoi']['etablissement_id'] !== (int) $etab['id']) {
            $this->repondre(404, ['erreur' => t('note.introuvable')]);
        }
        $this->repondre(200, ['bon' => View::rendre('cuisine/_bon', $bon)]);
    }

    // ---------- Outils ----------

    /**
     * Personne qui vend ici (caisse partagée ou appareil personnel), avec « caisse.vendre » et le module Salle.
     * $ecriture : jeton CSRF exigé (POST).
     * @return array{personne: array, etab: array, appareil: ?array}
     */
    private function acces(bool $ecriture): array
    {
        Session::demarrer();
        if ($ecriture) {
            $jeton = $_POST['csrf'] ?? '';
            if (!is_string($jeton) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $jeton)) {
                $this->repondre(419, ['erreur' => t('encaisser.expire')]);
            }
        }
        $slug = $ecriture ? ($_POST['etab'] ?? null) : ($_GET['etab'] ?? null);
        $vendeur = CaisseController::vendeur(is_string($slug) ? $slug : null);
        if ($vendeur === null) {
            $this->repondre(401, ['erreur' => t('encaisser.connexion')]);
        }
        if (!Acces::a($vendeur['personne']['id'], 'caisse.vendre', (int) $vendeur['etab']['id'])) {
            $this->repondre(403, ['erreur' => t('acces.interdit_titre')]);
        }
        if (!Plateforme::moduleInclus('salle')) {
            $this->repondre(403, ['erreur' => t('note.module_absent')]);
        }
        return $vendeur;
    }

    private function noteOu404(string $id, array $etab): array
    {
        $note = ctype_digit($id) ? $this->notes->note((int) $id) : null;
        if ($note === null || (int) $note['etablissement_id'] !== (int) $etab['id']) {
            $this->repondre(404, ['erreur' => t('note.introuvable')]);
        }
        return $note;
    }

    private function noteOuverte(string $id, array $etab): array
    {
        $note = $this->noteOu404($id, $etab);
        if ($note['statut'] !== 'ouverte' || $note['type'] !== 'note') {
            $this->repondre(409, ['erreur' => t('note.deja_fermee'), 'code' => 'note_fermee']);
        }
        return $note;
    }

    private function repondre(int $code, array $donnees): never
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($code);
        echo json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
}
