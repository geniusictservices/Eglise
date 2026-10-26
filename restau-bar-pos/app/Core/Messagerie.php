<?php

declare(strict_types=1);

namespace App\Core;

/**
 * SMS par Africa's Talking et messages WhatsApp Business (API Cloud de Meta), inscrits au journal « messages ».
 *
 * Réglages (Paramètres > Messagerie, table parametres) :
 * - sms.utilisateur, sms.cle, sms.expediteur (facultatif), sms.bac_a_sable (« 1 » : environnement de test d'Africa's Talking) ;
 * - whatsapp.numero_id, whatsapp.jeton, whatsapp.version, whatsapp.langue_fr, whatsapp.langue_en, et un modèle
 *   approuvé par Meta pour chaque événement : whatsapp.modele_rappel_client, whatsapp.modele_rappel_responsable,
 *   whatsapp.modele_garant_demande (un message d'entreprise hors conversation doit passer par un modèle).
 *
 * Sans aucun canal réglé, le message est seulement inscrit au journal (« simule ») : rien ne part.
 * Une même relance (dette, événement, étape, destinataire) ne part qu'une fois, même si le script tourne deux fois.
 * Les adresses des services peuvent être remplacées (réglages avancés sms.url et whatsapp.url : autre point d'accès, tests).
 */
final class Messagerie
{
    public const URL_SMS = 'https://api.africastalking.com/version1/messaging';
    public const URL_SMS_BAC_A_SABLE = 'https://api.sandbox.africastalking.com/version1/messaging';
    public const URL_WHATSAPP = 'https://graph.facebook.com/{version}/{numero}/messages';

    public static function smsPret(): bool
    {
        return Parametres::get('sms.utilisateur') !== null && Parametres::get('sms.cle') !== null;
    }

    public static function whatsappPret(?string $evenement = null): bool
    {
        return Parametres::get('whatsapp.numero_id') !== null && Parametres::get('whatsapp.jeton') !== null
            && ($evenement === null || $evenement === 'test' || Parametres::get('whatsapp.modele_' . $evenement) !== null);
    }

    /**
     * Canaux à essayer, dans l'ordre : réglage « relances.canal » (prefere, sms, whatsapp) et canal préféré
     * du destinataire ; avec « prefere », l'autre canal sert de secours.
     * @return list<string>
     */
    public static function canaux(?string $prefere = null): array
    {
        $reglage = Parametres::get('relances.canal', 'prefere');
        if ($reglage === 'sms' || $reglage === 'whatsapp') {
            return [$reglage];
        }
        return $prefere === 'whatsapp' ? ['whatsapp', 'sms'] : ['sms', 'whatsapp'];
    }

    /**
     * Envoie un message et l'inscrit au journal.
     *
     * @param array{evenement: string, etape?: ?string, dette_id?: ?int, client_id?: ?int, utilisateur_id?: ?int, langue?: string} $contexte
     * @param list<string> $canaux             dans l'ordre d'essai (voir canaux())
     * @param list<string> $parametresWhatsapp valeurs des variables {{1}}, {{2}}… du modèle WhatsApp de l'événement
     * @return array{statut: string, canal: ?string, erreur: ?string, id: ?int} statut : envoye, echec, simule, deja
     */
    public static function envoyer(string $telephone, string $texte, array $contexte, array $canaux, array $parametresWhatsapp = []): array
    {
        $evenement = $contexte['evenement'];
        $prets = array_values(array_filter($canaux, static fn (string $c) => $c === 'sms' ? self::smsPret() : self::whatsappPret($evenement)));
        // Réserver la ligne du journal d'abord : un deuxième passage simultané ne renvoie pas le message
        $reserve = Database::executer(
            'INSERT IGNORE INTO messages (canal, destinataire, client_id, utilisateur_id, dette_id, evenement, etape, texte, statut, erreur)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $prets[0] ?? ($canaux[0] ?? 'sms'), $telephone, $contexte['client_id'] ?? null, $contexte['utilisateur_id'] ?? null,
                $contexte['dette_id'] ?? null, $evenement, $contexte['etape'] ?? null, $texte,
                $prets === [] ? 'simule' : 'echec', $prets === [] ? null : 'en cours',
            ]
        );
        if ($reserve === 0) {
            return ['statut' => 'deja', 'canal' => null, 'erreur' => null, 'id' => null];
        }
        $id = Database::dernierId();
        if ($prets === []) {
            return ['statut' => 'simule', 'canal' => $canaux[0] ?? 'sms', 'erreur' => null, 'id' => $id];
        }
        $erreurs = [];
        foreach ($prets as $canal) {
            $resultat = $canal === 'sms'
                ? self::sms($telephone, $texte)
                : self::whatsapp($telephone, $evenement, $contexte['langue'] ?? 'fr', $parametresWhatsapp, $texte);
            if ($resultat['ok']) {
                Database::executer("UPDATE messages SET canal = ?, statut = 'envoye', fournisseur_id = ?, erreur = NULL WHERE id = ?",
                    [$canal, $resultat['id'] !== null ? mb_substr($resultat['id'], 0, 128) : null, $id]);
                return ['statut' => 'envoye', 'canal' => $canal, 'erreur' => null, 'id' => $id];
            }
            $erreurs[] = $canal . ' : ' . $resultat['erreur'];
        }
        $erreur = mb_substr(implode(' ; ', $erreurs), 0, 255);
        Database::executer("UPDATE messages SET statut = 'echec', erreur = ? WHERE id = ?", [$erreur, $id]);
        return ['statut' => 'echec', 'canal' => end($prets) ?: null, 'erreur' => $erreur, 'id' => $id];
    }

    /** @return array{ok: bool, id: ?string, erreur: ?string} */
    private static function sms(string $telephone, string $texte): array
    {
        $url = Parametres::get('sms.url') ?? (Parametres::get('sms.bac_a_sable') === '1' ? self::URL_SMS_BAC_A_SABLE : self::URL_SMS);
        $champs = ['username' => Parametres::get('sms.utilisateur'), 'to' => $telephone, 'message' => $texte];
        if (($expediteur = Parametres::get('sms.expediteur')) !== null) {
            $champs['from'] = $expediteur;
        }
        $reponse = self::poster($url, [
            'apiKey: ' . Parametres::get('sms.cle'),
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ], http_build_query($champs));
        if ($reponse['erreur'] !== null) {
            return ['ok' => false, 'id' => null, 'erreur' => $reponse['erreur']];
        }
        $json = json_decode($reponse['corps'], true);
        $destinataire = $json['SMSMessageData']['Recipients'][0] ?? null;
        // Codes 100 (traité), 101 (envoyé), 102 (en file) : accepté par Africa's Talking
        if (is_array($destinataire) && in_array((int) ($destinataire['statusCode'] ?? 0), [100, 101, 102], true)) {
            return ['ok' => true, 'id' => (string) ($destinataire['messageId'] ?? ''), 'erreur' => null];
        }
        $message = $destinataire['status'] ?? ($json['SMSMessageData']['Message'] ?? trim(strip_tags($reponse['corps'])));
        return ['ok' => false, 'id' => null, 'erreur' => 'HTTP ' . $reponse['code'] . ' ' . mb_substr((string) $message, 0, 160)];
    }

    /** @return array{ok: bool, id: ?string, erreur: ?string} */
    private static function whatsapp(string $telephone, string $evenement, string $langue, array $parametres, string $texte): array
    {
        $url = Parametres::get('whatsapp.url') ?? strtr(self::URL_WHATSAPP, [
            '{version}' => Parametres::get('whatsapp.version', 'v21.0'),
            '{numero}' => rawurlencode((string) Parametres::get('whatsapp.numero_id')),
        ]);
        $modele = Parametres::get('whatsapp.modele_' . $evenement);
        $message = ['messaging_product' => 'whatsapp', 'to' => ltrim($telephone, '+')];
        if ($modele !== null) {
            $message += ['type' => 'template', 'template' => [
                'name' => $modele,
                'language' => ['code' => Parametres::get('whatsapp.langue_' . $langue, $langue)],
                'components' => $parametres === [] ? [] : [[
                    'type' => 'body',
                    'parameters' => array_map(static fn ($v) => ['type' => 'text', 'text' => (string) $v], array_values($parametres)),
                ]],
            ]];
        } else {
            // Message d'essai : texte libre (n'arrive que si la personne a écrit au numéro dans les dernières 24 h)
            $message += ['type' => 'text', 'text' => ['body' => $texte]];
        }
        $reponse = self::poster($url, [
            'Authorization: Bearer ' . Parametres::get('whatsapp.jeton'),
            'Content-Type: application/json',
        ], json_encode($message, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if ($reponse['erreur'] !== null) {
            return ['ok' => false, 'id' => null, 'erreur' => $reponse['erreur']];
        }
        $json = json_decode($reponse['corps'], true);
        if ($reponse['code'] >= 200 && $reponse['code'] < 300 && isset($json['messages'][0]['id'])) {
            return ['ok' => true, 'id' => (string) $json['messages'][0]['id'], 'erreur' => null];
        }
        $message = $json['error']['message'] ?? trim(strip_tags($reponse['corps']));
        return ['ok' => false, 'id' => null, 'erreur' => 'HTTP ' . $reponse['code'] . ' ' . mb_substr((string) $message, 0, 160)];
    }

    /** @return array{code: int, corps: string, erreur: ?string} */
    private static function poster(string $url, array $entetes, string $corps): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $corps,
            CURLOPT_HTTPHEADER => $entetes,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
        ]);
        $reponse = curl_exec($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $erreur = $reponse === false ? 'réseau : ' . curl_error($curl) : null;
        curl_close($curl);
        return ['code' => $code, 'corps' => is_string($reponse) ? $reponse : '', 'erreur' => $erreur];
    }
}
