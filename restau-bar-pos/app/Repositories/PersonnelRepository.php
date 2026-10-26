<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\CaissePartagee;
use App\Core\Database;
use App\Core\Journal;

/**
 * Employés (module Personnel et paie) : fiche liée ou non à un compte utilisateur, établissements,
 * contrat, salaire dans une ou plusieurs devises (par mois, semaine ou soirée), documents, PIN de pointage
 * pour qui n'a pas de compte, téléphone reconnu pour le pointage.
 *
 * Portée : une personne voit les employés dont au moins un établissement est dans sa portée
 * (Acces::etablissements(), null = tous).
 */
final class PersonnelRepository
{
    public const CONTRATS = ['cdi', 'cdd', 'journalier'];
    public const PERIODES = ['mois', 'semaine', 'soiree'];
    /** Documents acceptés : PDF et images, 5 Mo au plus */
    public const DOCUMENTS = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    public const DOCUMENT_MAX = 5 * 1024 * 1024;

    /** Condition SQL « l'employé e a un établissement dans la portée » ; params ajoutés à $params. */
    public static function conditionPortee(?array $portee, array &$params, string $alias = 'e'): string
    {
        if ($portee === null) {
            return '1 = 1';
        }
        if ($portee === []) {
            return '1 = 0';
        }
        array_push($params, ...array_map('intval', $portee));
        return "EXISTS (SELECT 1 FROM employe_etablissements ee WHERE ee.employe_id = $alias.id AND ee.etablissement_id IN ("
            . implode(',', array_fill(0, count($portee), '?')) . '))';
    }

    /**
     * Employés de la portée, filtrés (établissement, recherche, actifs ou non), avec leurs établissements.
     * @param array{etablissement?: ?int, q?: string, statut?: string} $filtres statut : actifs (défaut), inactifs, tous
     * @return list<array>
     */
    public function liste(?array $portee, array $filtres = []): array
    {
        $params = [];
        $where = [self::conditionPortee($portee, $params)];
        $statut = $filtres['statut'] ?? 'actifs';
        if ($statut === 'actifs') {
            $where[] = 'e.actif = 1';
        } elseif ($statut === 'inactifs') {
            $where[] = 'e.actif = 0';
        }
        if (!empty($filtres['etablissement'])) {
            $where[] = 'EXISTS (SELECT 1 FROM employe_etablissements x WHERE x.employe_id = e.id AND x.etablissement_id = ?)';
            $params[] = (int) $filtres['etablissement'];
        }
        $q = trim((string) ($filtres['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(e.nom LIKE ? OR e.poste LIKE ? OR REPLACE(e.telephone, \'+\', \'\') LIKE ?)';
            $chiffres = preg_replace('/\D+/', '', $q) ?: '§';
            array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $chiffres . '%');
        }
        $lignes = Database::tous(
            'SELECT e.*, u.avatar, u.actif AS compte_actif, u.pin IS NOT NULL AS a_pin, p.nom AS etablissement_nom
               FROM employes e
               LEFT JOIN utilisateurs u ON u.id = e.utilisateur_id
               JOIN etablissements p ON p.id = e.etablissement_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY e.actif DESC, e.nom',
            $params
        );
        return $this->completer($lignes);
    }

    /** @return list<array> employés actifs d'un établissement (plannings, présence, caisse) */
    public function deLEtablissement(int $etablissementId, bool $actifs = true): array
    {
        $lignes = Database::tous(
            'SELECT e.*, u.avatar, u.pin IS NOT NULL AS a_pin, p.nom AS etablissement_nom
               FROM employes e JOIN employe_etablissements ee ON ee.employe_id = e.id AND ee.etablissement_id = ?
               LEFT JOIN utilisateurs u ON u.id = e.utilisateur_id
               JOIN etablissements p ON p.id = e.etablissement_id
              WHERE ' . ($actifs ? 'e.actif = 1' : '1 = 1') . ' ORDER BY e.nom',
            [$etablissementId]
        );
        return $this->completer($lignes);
    }

    /** Ajoute établissements (ids) et salaires (devise_id → montant) à chaque employé. */
    private function completer(array $lignes): array
    {
        if ($lignes === []) {
            return [];
        }
        $ids = array_map(static fn (array $l): int => (int) $l['id'], $lignes);
        $marques = implode(',', array_fill(0, count($ids), '?'));
        $etabs = [];
        foreach (Database::tous("SELECT employe_id, etablissement_id FROM employe_etablissements WHERE employe_id IN ($marques)", $ids) as $l) {
            $etabs[(int) $l['employe_id']][] = (int) $l['etablissement_id'];
        }
        $salaires = [];
        foreach (Database::tous("SELECT employe_id, devise_id, montant FROM employe_salaires WHERE employe_id IN ($marques)", $ids) as $l) {
            $salaires[(int) $l['employe_id']][(int) $l['devise_id']] = (float) $l['montant'];
        }
        foreach ($lignes as &$ligne) {
            $ligne['etablissements'] = $etabs[(int) $ligne['id']] ?? [(int) $ligne['etablissement_id']];
            $ligne['salaires'] = $salaires[(int) $ligne['id']] ?? [];
        }
        unset($ligne);
        return $lignes;
    }

    public function employe(int $id): ?array
    {
        $ligne = Database::un(
            'SELECT e.*, u.avatar, u.email AS compte_email, u.actif AS compte_actif, u.pin IS NOT NULL AS a_pin, u.nom AS compte_nom,
                    p.nom AS etablissement_nom, c.nom AS cree_par_nom
               FROM employes e
               LEFT JOIN utilisateurs u ON u.id = e.utilisateur_id
               JOIN etablissements p ON p.id = e.etablissement_id
               LEFT JOIN utilisateurs c ON c.id = e.cree_par
              WHERE e.id = ?',
            [$id]
        );
        return $ligne === null ? null : $this->completer([$ligne])[0];
    }

    public function parUtilisateur(int $utilisateurId): ?array
    {
        $ligne = Database::un('SELECT * FROM employes WHERE utilisateur_id = ?', [$utilisateurId]);
        return $ligne === null ? null : $this->completer([$ligne])[0];
    }

    /** L'employé est-il visible dans cette portée ? */
    public static function dansPortee(array $employe, ?array $portee): bool
    {
        return $portee === null || array_intersect($employe['etablissements'], array_map('intval', $portee)) !== [];
    }

    /** Comptes utilisateurs actifs sans fiche employé (à lier), ou celui déjà lié. @return list<array> */
    public function comptesLibres(?int $dejaLie = null): array
    {
        return Database::tous(
            'SELECT u.id, u.nom, u.telephone, u.poste FROM utilisateurs u
              WHERE u.actif = 1 AND (u.id = ? OR NOT EXISTS (SELECT 1 FROM employes e WHERE e.utilisateur_id = u.id))
              ORDER BY u.nom',
            [$dejaLie ?? 0]
        );
    }

    public function telephonePris(string $telephone, int $saufId = 0): bool
    {
        return Database::un('SELECT id FROM employes WHERE telephone = ? AND id <> ?', [$telephone, $saufId]) !== null;
    }

    /**
     * Créer ou modifier un employé.
     * @param array{utilisateur_id: ?int, nom: string, telephone: ?string, poste: ?string, etablissement_id: int, etablissements: list<int>,
     *              contrat: string, debut: string, fin: ?string, periode: string, commission_pct: ?float, pourboires: bool, notes: ?string,
     *              salaires: array<int, float>} $v
     */
    public function enregistrer(?int $id, array $v, int $par): int
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $valeurs = [
                $v['utilisateur_id'], $v['nom'], $v['telephone'], $v['poste'], $v['etablissement_id'], $v['contrat'], $v['debut'], $v['fin'],
                $v['periode'], $v['commission_pct'] !== null ? number_format($v['commission_pct'], 2, '.', '') : null, $v['pourboires'] ? 1 : 0, $v['notes'],
            ];
            if ($id === null) {
                Database::executer(
                    'INSERT INTO employes (utilisateur_id, nom, telephone, poste, etablissement_id, contrat, debut, fin, periode, commission_pct, pourboires, notes, cree_par)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [...$valeurs, $par]
                );
                $id = Database::dernierId();
                Journal::ecrire('employe_cree', $v['nom'], ['employe' => $id], $par);
            } else {
                Database::executer(
                    'UPDATE employes SET utilisateur_id = ?, nom = ?, telephone = ?, poste = ?, etablissement_id = ?, contrat = ?, debut = ?, fin = ?,
                            periode = ?, commission_pct = ?, pourboires = ?, notes = ? WHERE id = ?',
                    [...$valeurs, $id]
                );
                Journal::ecrire('employe_modifie', $v['nom'], ['employe' => $id], $par);
            }
            Database::executer('DELETE FROM employe_etablissements WHERE employe_id = ?', [$id]);
            foreach (array_unique(array_merge([$v['etablissement_id']], $v['etablissements'])) as $etab) {
                Database::executer('INSERT INTO employe_etablissements (employe_id, etablissement_id) VALUES (?, ?)', [$id, (int) $etab]);
            }
            $avant = array_column(Database::tous('SELECT devise_id, montant FROM employe_salaires WHERE employe_id = ?', [$id]), 'montant', 'devise_id');
            Database::executer('DELETE FROM employe_salaires WHERE employe_id = ?', [$id]);
            foreach ($v['salaires'] as $deviseId => $montant) {
                if ($montant > 0) {
                    Database::executer('INSERT INTO employe_salaires (employe_id, devise_id, montant) VALUES (?, ?, ?)',
                        [$id, (int) $deviseId, GestionTresorerieRepository::decimal($montant)]);
                }
            }
            $apres = array_filter($v['salaires'], static fn ($m) => $m > 0);
            if (array_map('floatval', $avant) != $apres) {
                Journal::ecrire('salaire_modifie', $v['nom'], ['employe' => $id, 'avant' => $avant, 'apres' => $apres], $par);
            }
            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function definirActif(int $id, bool $actif, int $par): void
    {
        Database::executer('UPDATE employes SET actif = ? WHERE id = ?', [$actif ? 1 : 0, $id]);
        if (!$actif) {
            // Un employé parti ne pointe plus : ses téléphones sont oubliés
            Database::executer('UPDATE pointage_telephones SET actif = 0 WHERE employe_id = ?', [$id]);
        }
        Journal::ecrire($actif ? 'employe_reactive' : 'employe_desactive', 'employe ' . $id, [], $par);
    }

    // ---------- PIN de pointage (employé sans compte) ----------

    /** @throws \InvalidArgumentException « format », « simple » */
    public function definirPinPointage(int $id, string $pin, int $par): void
    {
        if (!CaissePartagee::pinValide($pin)) {
            throw new \InvalidArgumentException('format');
        }
        if (CaissePartagee::pinTropSimple($pin)) {
            throw new \InvalidArgumentException('simple');
        }
        Database::executer('UPDATE employes SET pin_pointage = ?, pin_essais = 0, pin_bloque_le = NULL WHERE id = ?', [password_hash($pin, PASSWORD_DEFAULT), $id]);
        Journal::ecrire('employe_pin_pointage', 'employe ' . $id, [], $par);
    }

    public function debloquerPin(int $id, int $par): void
    {
        Database::executer('UPDATE employes SET pin_essais = 0, pin_bloque_le = NULL WHERE id = ?', [$id]);
        Journal::ecrire('employe_pin_debloque', 'employe ' . $id, [], $par);
    }

    // ---------- Téléphones de pointage ----------

    /** @return list<array> */
    public function telephones(int $employeId): array
    {
        return Database::tous('SELECT * FROM pointage_telephones WHERE employe_id = ? ORDER BY actif DESC, cree_le DESC LIMIT 10', [$employeId]);
    }

    public function retirerTelephone(int $employeId, int $telephoneId, int $par): void
    {
        Database::executer('UPDATE pointage_telephones SET actif = 0 WHERE id = ? AND employe_id = ?', [$telephoneId, $employeId]);
        Journal::ecrire('pointage_telephone_retire', 'employe ' . $employeId, ['telephone' => $telephoneId], $par);
    }

    // ---------- Documents ----------

    public static function dossierDocuments(): string
    {
        return RACINE . '/storage/documents/' . \App\Core\Instance::courante()->sousDomaine . '/employes';
    }

    /** @return list<array> */
    public function documents(int $employeId): array
    {
        return Database::tous(
            'SELECT d.*, u.nom AS ajoute_par_nom FROM employe_documents d LEFT JOIN utilisateurs u ON u.id = d.ajoute_par
              WHERE d.employe_id = ? ORDER BY d.ajoute_le DESC, d.id DESC',
            [$employeId]
        );
    }

    public function document(int $employeId, int $documentId): ?array
    {
        return Database::un('SELECT * FROM employe_documents WHERE id = ? AND employe_id = ?', [$documentId, $employeId]);
    }

    /**
     * Range un fichier envoyé (déjà contrôlé : type et taille) hors de la racine web.
     * @throws \RuntimeException dossier impossible à créer ou fichier impossible à déplacer
     */
    public function ajouterDocument(int $employeId, string $nom, string $temporaire, string $mime, int $taille, int $par): int
    {
        $dossier = self::dossierDocuments();
        if (!is_dir($dossier) && !mkdir($dossier, 0750, true) && !is_dir($dossier)) {
            throw new \RuntimeException('dossier');
        }
        $fichier = $employeId . '-' . bin2hex(random_bytes(12)) . '.' . self::DOCUMENTS[$mime];
        $deplace = is_uploaded_file($temporaire) ? move_uploaded_file($temporaire, $dossier . '/' . $fichier) : rename($temporaire, $dossier . '/' . $fichier);
        if (!$deplace) {
            throw new \RuntimeException('deplacement');
        }
        Database::executer('INSERT INTO employe_documents (employe_id, nom, fichier, type_mime, taille, ajoute_par) VALUES (?, ?, ?, ?, ?, ?)',
            [$employeId, mb_substr($nom, 0, 255), $fichier, $mime, $taille, $par]);
        $id = Database::dernierId();
        Journal::ecrire('employe_document_ajoute', $nom, ['employe' => $employeId, 'document' => $id], $par);
        return $id;
    }

    public function supprimerDocument(array $document, int $par): void
    {
        Database::executer('DELETE FROM employe_documents WHERE id = ?', [(int) $document['id']]);
        $chemin = self::dossierDocuments() . '/' . basename($document['fichier']);
        if (is_file($chemin)) {
            unlink($chemin);
        }
        Journal::ecrire('employe_document_supprime', $document['nom'], ['employe' => (int) $document['employe_id']], $par);
    }

    // ---------- Résumés de la fiche ----------

    /** Dettes du personnel (client « personnel » lié au compte) : encours par devise et dettes ouvertes. */
    public function dettes(array $employe): array
    {
        if (empty($employe['utilisateur_id'])) {
            return ['client' => null, 'encours' => [], 'dettes' => []];
        }
        $client = Database::un("SELECT * FROM clients WHERE utilisateur_id = ? AND type = 'personnel'", [(int) $employe['utilisateur_id']]);
        if ($client === null) {
            return ['client' => null, 'encours' => [], 'dettes' => []];
        }
        $dettes = new DetteRepository();
        return ['client' => $client, 'encours' => $dettes->encours((int) $client['id']), 'dettes' => $dettes->dettesOuvertes((int) $client['id'])];
    }

    /**
     * Manquants de caisse (écarts négatifs des sessions clôturées de la personne), avec ce qui en a déjà été
     * retenu sur des bulletins validés. @return list<array{session_id: int, devise_id: int, manquant: float, retenu: float, reste: float, date: string, etablissement: string}>
     */
    public function manquants(array $employe, bool $seulementRestants = true, ?int $saufBulletin = null): array
    {
        if (empty($employe['utilisateur_id'])) {
            return [];
        }
        $lignes = Database::tous(
            "SELECT s.id AS session_id, scc.devise_id, -SUM(scc.ecart) AS manquant, s.fermee_le, e.nom AS etablissement
               FROM sessions_caisse s JOIN session_caisse_comptes scc ON scc.session_id = s.id JOIN etablissements e ON e.id = s.etablissement_id
              WHERE s.utilisateur_id = ? AND s.statut = 'fermee' AND scc.ecart IS NOT NULL
              GROUP BY s.id, scc.devise_id, s.fermee_le, e.nom
             HAVING SUM(scc.ecart) < -0.0005
              ORDER BY s.fermee_le, s.id",
            [(int) $employe['utilisateur_id']]
        );
        $resultat = [];
        foreach ($lignes as $l) {
            $retenu = self::dejaRetenu((int) $l['session_id'], (int) $l['devise_id'], $saufBulletin);
            $reste = round((float) $l['manquant'] - $retenu, 3);
            if ($seulementRestants && $reste <= 0.0005) {
                continue;
            }
            $resultat[] = [
                'session_id' => (int) $l['session_id'], 'devise_id' => (int) $l['devise_id'], 'manquant' => (float) $l['manquant'],
                'retenu' => $retenu, 'reste' => max(0.0, $reste), 'date' => (string) $l['fermee_le'], 'etablissement' => $l['etablissement'],
            ];
        }
        return $resultat;
    }

    /** Part d'un manquant (session, devise) déjà retenue par des bulletins validés ou payés. */
    public static function dejaRetenu(int $sessionId, int $deviseId, ?int $saufBulletin = null): float
    {
        return (float) Database::un(
            "SELECT COALESCE(SUM(l.source_montant), 0) AS n FROM bulletin_lignes l JOIN bulletins b ON b.id = l.bulletin_id
              WHERE l.type = 'manquant' AND l.session_id = ? AND l.source_devise_id = ? AND b.statut IN ('valide', 'paye') AND b.id <> ?",
            [$sessionId, $deviseId, $saufBulletin ?? 0]
        )['n'];
    }
}
