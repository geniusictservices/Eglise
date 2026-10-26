<?php
/**
 * API de messagerie de la plateforme : Africa's Talking (SMS), WhatsApp Cloud API ; essai ; sender ID par client ;
 * messages envoyés ce mois-ci par chaque instance (nombres seulement).
 * @var array<string, string> $valeurs
 * @var array<string, string> $masques
 * @var array<string, string> $erreurs
 * @var list<array{client: array, usage: ?array}> $usages
 * @var string $mois
 * @var list<array> $essais
 * @var bool $smsPret
 * @var bool $whatsappPret
 */

use App\Console\Affichage as A;
use App\Console\SessionConsole;
use App\Core\Messagerie;

$csrf = SessionConsole::jetonCsrf();
$err = static fn (string $cle): string => isset($erreurs[$cle]) ? '<p class="champ__erreur" id="err-' . e($cle) . '">' . e($erreurs[$cle]) . '</p>' : '';
$inv = static fn (string $cle): string => isset($erreurs[$cle]) ? ' aria-invalid="true" aria-describedby="err-' . e($cle) . '"' : '';
$champ = static function (string $nom, string $cle, string $libelle, string $aide = '', string $placeholder = '') use ($valeurs, $err, $inv): string {
    return '<div class="champ"><label for="' . e($nom) . '">' . e($libelle) . '</label><input id="' . e($nom) . '" name="' . e($nom) . '" value="' . e($valeurs[$cle] ?? '') . '"'
        . ($placeholder !== '' ? ' placeholder="' . e($placeholder) . '"' : '') . ' autocapitalize="none" spellcheck="false"' . $inv($nom) . '>'
        . ($aide !== '' ? '<p class="champ__aide">' . e($aide) . '</p>' : '') . $err($nom) . '</div>';
};
$secret = static function (string $nom, string $cle, string $libelle) use ($masques): string {
    $masque = $masques[$cle];
    return '<div class="champ"><label for="' . e($nom) . '">' . e($libelle) . '</label>'
        . '<input id="' . e($nom) . '" name="' . e($nom) . '" type="password" autocomplete="off" placeholder="' . e($masque !== '' ? $masque : t('console.messagerie.non_enregistre')) . '" data-masque="' . e($masque) . '">'
        . '<p class="champ__aide">' . e($masque !== '' ? t('console.messagerie.secret_garde', ['masque' => $masque]) : t('console.messagerie.secret_vide')) . '</p>'
        . ($masque !== '' ? '<label class="coche"><input type="checkbox" name="' . e($nom) . '_effacer" value="1"> ' . e(t('console.messagerie.effacer')) . '</label>' : '')
        . '</div>';
};
?>
<h1><?= e(t('console.messagerie.titre')) ?></h1>
<p class="aide"><?= e(t('console.messagerie.aide')) ?></p>
<p class="con-etats">
    <span class="badge badge--<?= $smsPret ? 'succes' : 'neutre' ?>" data-etat-sms="<?= $smsPret ? 'pret' : 'non' ?>">SMS · <?= e(t($smsPret ? 'console.messagerie.pret' : 'console.messagerie.non_regle')) ?></span>
    <span class="badge badge--<?= $whatsappPret ? 'succes' : 'neutre' ?>" data-etat-whatsapp="<?= $whatsappPret ? 'pret' : 'non' ?>">WhatsApp · <?= e(t($whatsappPret ? 'console.messagerie.pret' : 'console.messagerie.non_regle')) ?></span>
</p>
<?php if ($erreurs !== [] && !isset($erreurs['essai'])): ?><p class="alerte alerte--erreur" role="alert"><?= e(t('console.corriger')) ?></p><?php endif; ?>

<form method="post" action="/messagerie" class="con-form" novalidate autocomplete="off">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <section class="bloc" aria-labelledby="con-at">
        <h2 id="con-at"><?= e(t('console.messagerie.at_titre')) ?></h2>
        <p class="aide"><?= e(t('console.messagerie.at_aide')) ?> <a class="lien" href="https://account.africastalking.com/" target="_blank" rel="noopener">account.africastalking.com</a></p>
        <div class="con-grille">
            <?= $champ('sms_utilisateur', 'sms.utilisateur', t('console.messagerie.at_utilisateur'), t('console.messagerie.at_utilisateur_aide')) ?>
            <?= $secret('sms_cle', 'sms.cle', t('console.messagerie.at_cle')) ?>
            <?= $champ('sms_expediteur', 'sms.expediteur', t('console.messagerie.at_expediteur'), t('console.messagerie.at_expediteur_aide'), 'RESTOBAR') ?>
        </div>
        <label class="coche"><input type="checkbox" name="sms_bac_a_sable" value="1"<?= ($valeurs['sms.bac_a_sable'] ?? '') === '1' ? ' checked' : '' ?>> <?= e(t('console.messagerie.at_bac_a_sable')) ?></label>
    </section>

    <section class="bloc" aria-labelledby="con-wa">
        <h2 id="con-wa"><?= e(t('console.messagerie.wa_titre')) ?></h2>
        <p class="aide"><?= e(t('console.messagerie.wa_aide')) ?> <a class="lien" href="https://business.facebook.com/" target="_blank" rel="noopener">business.facebook.com</a></p>
        <div class="con-grille">
            <?= $champ('whatsapp_numero_id', 'whatsapp.numero_id', t('console.messagerie.wa_numero_id'), t('console.messagerie.wa_numero_id_aide')) ?>
            <?= $champ('whatsapp_waba_id', 'whatsapp.waba_id', t('console.messagerie.wa_waba_id'), t('console.messagerie.wa_waba_id_aide')) ?>
            <?= $secret('whatsapp_jeton', 'whatsapp.jeton', t('console.messagerie.wa_jeton')) ?>
            <?= $champ('whatsapp_version', 'whatsapp.version', t('console.messagerie.wa_version'), t('console.messagerie.wa_version_aide'), 'v21.0') ?>
        </div>
        <h3 class="con-sous-titre"><?= e(t('console.messagerie.wa_modeles')) ?></h3>
        <p class="aide"><?= e(t('console.messagerie.wa_modeles_aide')) ?></p>
        <div class="con-grille">
            <?= $champ('whatsapp_modele_rappel_client', 'whatsapp.modele_rappel_client', t('console.messagerie.modele_rappel_client'), t('console.messagerie.modele_rappel_client_aide')) ?>
            <?= $champ('whatsapp_modele_rappel_responsable', 'whatsapp.modele_rappel_responsable', t('console.messagerie.modele_rappel_responsable'), t('console.messagerie.modele_rappel_responsable_aide')) ?>
            <?= $champ('whatsapp_modele_garant_demande', 'whatsapp.modele_garant_demande', t('console.messagerie.modele_garant_demande'), t('console.messagerie.modele_garant_demande_aide')) ?>
            <?= $champ('whatsapp_langue_fr', 'whatsapp.langue_fr', t('console.messagerie.langue_fr'), t('console.messagerie.langue_aide'), 'fr') ?>
            <?= $champ('whatsapp_langue_en', 'whatsapp.langue_en', t('console.messagerie.langue_en'), t('console.messagerie.langue_aide'), 'en') ?>
        </div>
    </section>

    <details class="bloc con-avance">
        <summary><?= e(t('console.messagerie.avance')) ?></summary>
        <p class="aide"><?= e(t('console.messagerie.avance_aide')) ?></p>
        <div class="con-grille">
            <?= $champ('sms_url', 'sms.url', t('console.messagerie.sms_url'), '', Messagerie::URL_SMS) ?>
            <?= $champ('whatsapp_url', 'whatsapp.url', t('console.messagerie.whatsapp_url'), '', 'https://graph.facebook.com/v21.0/{phone-number-id}/messages') ?>
        </div>
    </details>
    <p class="con-actions"><button class="bouton" type="submit"><?= e(t('console.enregistrer')) ?></button></p>
</form>

<section class="bloc" id="essai" aria-labelledby="con-essai">
    <h2 id="con-essai"><?= e(t('console.messagerie.essai_titre')) ?></h2>
    <p class="aide"><?= e(t('console.messagerie.essai_aide')) ?></p>
    <form method="post" action="/messagerie/essai" class="con-form con-ligne" novalidate>
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <div class="champ"><label for="essai-tel"><?= e(t('console.messagerie.numero')) ?></label><input id="essai-tel" name="telephone" type="tel" placeholder="+243 81 000 0000"<?= $inv('essai') ?>><?= $err('essai') ?></div>
        <div class="champ"><label for="essai-canal"><?= e(t('console.relances.canal')) ?></label><select id="essai-canal" name="canal"><option value="sms">SMS</option><option value="whatsapp">WhatsApp</option></select></div>
        <button class="bouton bouton--secondaire" type="submit"><?= e(t('console.messagerie.envoyer_essai')) ?></button>
    </form>
    <?php if ($essais !== []): ?>
        <ul class="con-liste con-petit">
            <?php foreach ($essais as $m): ?>
                <li data-essai="<?= e($m['statut']) ?>"><?= e(A::dateHeure($m['cree_le'])) ?> · <?= e(t('console.canal.' . $m['canal'])) ?> · <?= e($m['destinataire']) ?> · <span class="badge badge--<?= $m['statut'] === 'envoye' ? 'succes' : ($m['statut'] === 'echec' ? 'erreur' : 'neutre') ?>"><?= e(t('console.message.' . $m['statut'])) ?></span> <?= e((string) $m['erreur']) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="bloc" id="expediteurs" aria-labelledby="con-usage">
    <div class="con-entete">
        <h2 id="con-usage"><?= e(t('console.messagerie.usage_titre')) ?></h2>
        <form method="get" action="/messagerie#expediteurs" class="con-ligne">
            <div class="champ"><label for="mois"><?= e(t('console.messagerie.mois')) ?></label><input id="mois" name="mois" type="month" value="<?= e($mois) ?>"></div>
            <button class="bouton bouton--secondaire bouton--petit" type="submit"><?= e(t('console.afficher')) ?></button>
        </form>
    </div>
    <p class="aide"><?= e(t('console.messagerie.usage_aide')) ?></p>
    <div class="defile"><table class="tableau con-usage">
        <thead><tr><th><?= e(t('console.client.client')) ?></th><th class="nombre">SMS</th><th class="nombre"><?= e(t('console.messagerie.inclus')) ?></th><th class="nombre">WhatsApp</th><th><?= e(t('console.messagerie.sender_id')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($usages as ['client' => $c, 'usage' => $u]): ?>
            <tr data-usage-client="<?= e($c['sous_domaine']) ?>">
                <td><a class="lien" href="/clients/<?= (int) $c['id'] ?>"><?= e($c['nom']) ?></a></td>
                <td class="nombre" data-sms><?= $u === null ? '—' : ($u['ok'] ? (int) $u['sms'] : '<span class="badge badge--erreur">' . e(t('console.instance.connexion_echec')) . '</span>') ?></td>
                <td class="nombre"><?= (int) $c['sms_inclus'] ?><?= $u && $u['ok'] && (int) $u['sms'] > (int) $c['sms_inclus'] ? ' <span class="badge badge--braise">+' . ((int) $u['sms'] - (int) $c['sms_inclus']) . '</span>' : '' ?></td>
                <td class="nombre" data-whatsapp><?= $u && $u['ok'] ? (int) $u['whatsapp'] : '—' ?></td>
                <td>
                    <form method="post" action="/clients/<?= (int) $c['id'] ?>/expediteur" class="con-ligne con-ligne--serree">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="retour" value="messagerie">
                        <label class="visuellement-cache" for="exp-<?= (int) $c['id'] ?>"><?= e(t('console.messagerie.sender_id')) ?> · <?= e($c['nom']) ?></label>
                        <input class="saisie con-saisie-courte" id="exp-<?= (int) $c['id'] ?>" name="expediteur_sms" maxlength="11" value="<?= e((string) $c['expediteur_sms']) ?>" placeholder="<?= e($valeurs['sms.expediteur'] ?: '—') ?>">
                        <button class="bouton bouton--fantome bouton--petit" type="submit"><?= e(t('console.enregistrer')) ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
