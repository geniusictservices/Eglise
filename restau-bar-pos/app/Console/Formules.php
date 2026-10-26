<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\BaseConsole;
use App\Core\Modules;

/** Formules d'abonnement : modules inclus, limites, prix par période en dollars, SMS compris par mois. */
final class Formules
{
    /** @return list<array> */
    public static function toutes(bool $avecArchivees = true): array
    {
        $formules = BaseConsole::tous(
            'SELECT f.*, (SELECT COUNT(*) FROM clients c WHERE c.formule_id = f.id) AS clients
               FROM formules f ' . ($avecArchivees ? '' : 'WHERE f.actif = 1 ') . 'ORDER BY f.actif DESC, COALESCE(f.prix_mois, f.prix_annee / 12, 0), f.nom'
        );
        return array_map([self::class, 'decoder'], $formules);
    }

    public static function une(int $id): ?array
    {
        $formule = BaseConsole::un('SELECT f.*, (SELECT COUNT(*) FROM clients c WHERE c.formule_id = f.id) AS clients FROM formules f WHERE f.id = ?', [$id]);
        return $formule === null ? null : self::decoder($formule);
    }

    private static function decoder(array $formule): array
    {
        $modules = json_decode((string) $formule['modules'], true);
        $formule['modules'] = is_array($modules) ? array_values(array_intersect(Modules::offre(), $modules)) : [];
        return $formule;
    }

    /**
     * Lit et vérifie le formulaire.
     * @return array{0: array, 1: array<string, string>} valeurs, erreurs
     */
    public static function saisie(array $post): array
    {
        $texte = static fn (string $cle, int $max): string => mb_substr(trim((string) preg_replace('/\p{Cc}/u', '', is_string($post[$cle] ?? null) ? $post[$cle] : '')), 0, $max);
        $valeurs = ['nom' => $texte('nom', 80), 'description' => $texte('description', 255)];
        $erreurs = [];
        if ($valeurs['nom'] === '') {
            $erreurs['nom'] = t('console.formule.erreur_nom');
        }
        $choisis = is_array($post['modules'] ?? null) ? array_map('strval', $post['modules']) : [];
        $valeurs['modules'] = array_values(array_intersect(Modules::offre(), $choisis));
        foreach (['max_etablissements', 'max_utilisateurs'] as $cle) {
            $brut = $texte($cle, 6);
            $valeurs[$cle] = $brut === '' ? null : $brut;
            if ($brut !== '' && (!ctype_digit($brut) || (int) $brut < 1 || (int) $brut > 9999)) {
                $erreurs[$cle] = t('console.formule.erreur_limite');
            }
        }
        foreach (['prix_mois', 'prix_trimestre', 'prix_annee'] as $cle) {
            $brut = str_replace([',', ' ', '$'], ['.', '', ''], $texte($cle, 12));
            $valeurs[$cle] = $brut === '' ? null : $brut;
            if ($brut !== '' && (!is_numeric($brut) || (float) $brut < 0 || (float) $brut > 99999)) {
                $erreurs[$cle] = t('console.formule.erreur_prix');
            }
        }
        if ($valeurs['prix_mois'] === null && $valeurs['prix_trimestre'] === null && $valeurs['prix_annee'] === null && !isset($erreurs['prix_mois'])) {
            $erreurs['prix_mois'] = t('console.formule.erreur_une_periode');
        }
        $sms = $texte('sms_inclus', 7);
        $valeurs['sms_inclus'] = $sms === '' ? '0' : $sms;
        if (!ctype_digit($valeurs['sms_inclus'])) {
            $erreurs['sms_inclus'] = t('console.formule.erreur_sms');
        }
        $valeurs['actif'] = empty($post['archivee']) ? 1 : 0;
        return [$valeurs, $erreurs];
    }

    public static function enregistrer(?int $id, array $v): int
    {
        $params = [
            $v['nom'], $v['description'] !== '' ? $v['description'] : null, json_encode($v['modules']),
            $v['max_etablissements'], $v['max_utilisateurs'], $v['prix_mois'], $v['prix_trimestre'], $v['prix_annee'],
            (int) $v['sms_inclus'], (int) $v['actif'],
        ];
        if ($id === null) {
            BaseConsole::executer(
                'INSERT INTO formules (nom, description, modules, max_etablissements, max_utilisateurs, prix_mois, prix_trimestre, prix_annee, sms_inclus, actif)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                $params
            );
            return BaseConsole::dernierId();
        }
        BaseConsole::executer(
            'UPDATE formules SET nom = ?, description = ?, modules = ?, max_etablissements = ?, max_utilisateurs = ?, prix_mois = ?,
                    prix_trimestre = ?, prix_annee = ?, sms_inclus = ?, actif = ? WHERE id = ?',
            [...$params, $id]
        );
        return $id;
    }

    /** Supprimée seulement si aucun client ne l'a jamais eue ; sinon on l'archive. */
    public static function supprimer(int $id): bool
    {
        return BaseConsole::executer('DELETE FROM formules WHERE id = ? AND NOT EXISTS (SELECT 1 FROM clients WHERE formule_id = ?)', [$id, $id]) > 0;
    }
}
