<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\BaseConsole;
use App\Core\I18n;
use App\Core\Messagerie;

/**
 * Relances d'abonnement au propriétaire de chaque bar (bin/console-relances.php, chaque heure) :
 * par SMS (compte Africa's Talking de la plateforme) et par e-mail, dans la langue du client.
 *
 * Étapes (réglage relances.etapes, « -7,-3,-1,1 » par défaut : J-7, J-3, la veille, le lendemain de l'échéance),
 * puis deux avis : passage en lecture seule et suspension. Un passage manqué (serveur arrêté) est rattrapé
 * pendant deux jours. Chaque message (client, échéance, étape, canal) ne part qu'une fois. Un statut forcé à la
 * main n'est pas relancé. Sans SMS ni e-mail réglés, les messages sont seulement inscrits (« simule »).
 */
final class Relances
{
    private const RATTRAPAGE = 2;

    /** @return array{envoye: int, simule: int, echec: int, deja: int} */
    public static function passer(?string $aujourdhui = null, bool $maintenant = false): array
    {
        $compte = ['envoye' => 0, 'simule' => 0, 'echec' => 0, 'deja' => 0];
        if (Reglages::get('relances.actives', '1') !== '1') {
            return $compte;
        }
        if (!$maintenant && $aujourdhui === null && (int) date('G') < (int) Reglages::get('relances.heure', '9')) {
            return $compte;
        }
        $aujourdhui ??= date('Y-m-d');
        $etapes = self::etapes((string) Reglages::get('relances.etapes', '-7,-3,-1,1'));
        foreach (Clients::tous($aujourdhui) as $client) {
            $etape = self::etapeDu($client, $etapes, $aujourdhui);
            if ($etape === null) {
                continue;
            }
            foreach (self::envoyerA($client, $etape, $aujourdhui) as $statut) {
                $compte[$statut]++;
            }
        }
        Reglages::definir('relances.dernier_passage', date('Y-m-d H:i:s'));
        return $compte;
    }

    /** @return list<int> */
    public static function etapes(string $saisie): array
    {
        $etapes = [];
        foreach (explode(',', $saisie) as $morceau) {
            if (preg_match('/^\s*([+-]?\d{1,3})\s*$/', $morceau, $m)) {
                $etapes[] = (int) $m[1];
            }
        }
        $etapes = array_values(array_unique($etapes));
        sort($etapes);
        return $etapes;
    }

    /**
     * Étape atteinte aujourd'hui (avec rattrapage), ou null : « J-7 », « J+1 », « lecture_seule », « suspendu ».
     * @param list<int> $etapes
     */
    public static function etapeDu(array $client, array $etapes, string $aujourdhui): ?array
    {
        if ($client['installe_le'] === null || $client['echeance'] === null || $client['statut']['force']) {
            return null;
        }
        $jours = Abonnement::ecart($client['echeance'], $aujourdhui);   // positif : en retard
        $grace = (int) $client['jours_grace'];
        $lecture = (int) $client['jours_lecture_seule'];
        $avis = [
            'suspendu' => $grace + $lecture + 1,
            'lecture_seule' => $grace + 1,
        ];
        foreach ($avis as $code => $jour) {
            if ($jours >= $jour && $jours - $jour <= self::RATTRAPAGE) {
                return ['code' => $code, 'jour' => $jour, 'jours' => $jours];
            }
        }
        $choisie = null;
        foreach ($etapes as $e) {
            if ($e <= $jours && $jours - $e <= self::RATTRAPAGE && $e <= $grace) {
                $choisie = $e;
            }
        }
        if ($choisie === null) {
            return null;
        }
        return ['code' => 'J' . ($choisie >= 0 ? '+' : '') . $choisie, 'jour' => $choisie, 'jours' => $jours];
    }

    /** @return list<string> statuts (envoye, simule, echec, deja), un par canal */
    private static function envoyerA(array $client, array $etape, string $aujourdhui): array
    {
        $langue = in_array($client['langue'], ['fr', 'en'], true) ? $client['langue'] : 'fr';
        $type = match (true) {
            $etape['code'] === 'suspendu' => 'suspendu',
            $etape['code'] === 'lecture_seule' => 'lecture_seule',
            $etape['jour'] >= 0 => 'retard',
            !empty($client['essai']) => 'essai',
            default => 'avant',
        };
        $valeurs = self::valeurs($client, $langue);
        $statuts = [];
        if (!empty($client['contact_telephone'])) {
            $texte = I18n::dans($langue, 'console.relance.sms.' . $type, $valeurs);
            $statuts[] = self::canal($client, $etape['code'], 'sms', (string) $client['contact_telephone'], $texte,
                static fn () => Messagerie::transmettre('sms', (string) $client['contact_telephone'], $texte, Reglages::messagerie()),
                ($r = Reglages::messagerie()) !== [] && ($r['sms.utilisateur'] ?? '') !== '' && ($r['sms.cle'] ?? '') !== '');
        }
        if (!empty($client['contact_email'])) {
            $sujet = I18n::dans($langue, 'console.relance.email.sujet.' . $type, $valeurs);
            $corps = I18n::dans($langue, 'console.relance.email.corps.' . $type, $valeurs);
            $statuts[] = self::canal($client, $etape['code'], 'email', (string) $client['contact_email'], $sujet . "\n\n" . $corps,
                static fn () => Courriel::envoyer((string) $client['contact_email'], $sujet, $corps) + ['id' => null],
                Courriel::pret());
        }
        return $statuts;
    }

    /** Réserve la ligne du journal (une seule fois), envoie, puis note le résultat. */
    private static function canal(array $client, string $etape, string $canal, string $destinataire, string $texte, callable $envoi, bool $pret): string
    {
        $reserve = BaseConsole::executer(
            "INSERT IGNORE INTO messages (client_id, canal, destinataire, evenement, etape, echeance, texte, statut, erreur)
             VALUES (?, ?, ?, 'relance_abonnement', ?, ?, ?, ?, ?)",
            [(int) $client['id'], $canal, $destinataire, $etape, $client['echeance'], $texte, $pret ? 'echec' : 'simule', $pret ? 'en cours' : null]
        );
        if ($reserve === 0) {
            return 'deja';
        }
        $id = BaseConsole::dernierId();
        if (!$pret) {
            return 'simule';
        }
        $resultat = $envoi();
        if ($resultat['ok']) {
            BaseConsole::executer("UPDATE messages SET statut = 'envoye', erreur = NULL, fournisseur_id = ? WHERE id = ?",
                [isset($resultat['id']) ? mb_substr((string) $resultat['id'], 0, 128) : null, $id]);
            return 'envoye';
        }
        BaseConsole::executer("UPDATE messages SET statut = 'echec', erreur = ? WHERE id = ?", [mb_substr((string) $resultat['erreur'], 0, 255), $id]);
        return 'echec';
    }

    private static function valeurs(array $client, string $langue): array
    {
        $date = static fn (?string $d): string => $d === null ? '' : ($langue === 'fr' ? date('d/m/Y', strtotime($d)) : date('M j, Y', strtotime($d)));
        $prix = Abonnement::prix($client, $client['periode']);
        $nombre = $prix === null ? '' : ($langue === 'fr' ? number_format($prix, $prix == floor($prix) ? 0 : 2, ',', ' ') . ' $' : '$' . number_format($prix, $prix == floor($prix) ? 0 : 2));
        $telephone = Reglages::get('plateforme.contact_telephone');
        $email = Reglages::get('plateforme.contact_email');
        return [
            'plateforme' => Reglages::get('plateforme.nom', 'restau-bar-pos'),
            'client' => $client['nom'],
            'contact_nom' => $client['contact_nom'] ?: $client['nom'],
            'formule' => $client['formule_nom'] ?? '',
            'montant' => $nombre,
            'periode' => I18n::dans($langue, 'console.periode.' . $client['periode']),
            'date' => $date($client['echeance']),
            'grace' => $date($client['statut']['grace_jusqua']),
            'suspension' => $date($client['statut']['lecture_seule_jusqua']),
            'contact' => $telephone ?? $email ?? '',
            'contact_complet' => trim(implode(' · ', array_filter([$telephone, $email]))),
        ];
    }
}
