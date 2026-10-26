<?php
/**
 * Paramètres > Messagerie : état des canaux (comptes SMS et WhatsApp communs, réglés par la plateforme : aucune clé ici),
 * dernier passage du script des relances, réglages des relances automatiques, message d'essai.
 * @var ?string $flash
 * @var array<string, string> $valeurs relances.*
 * @var array<string, string> $erreurs indexées par clé de réglage (ou « essai »)
 * @var array $essai telephone
 * @var bool $smsPret
 * @var bool $whatsappPret
 * @var ?string $dernierPassage
 * @var bool $passageEnRetard
 * @var string $cron
 */

use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$invalide = static fn (string $cle, string $aide = ''): string => isset($erreurs[$cle])
    ? ' aria-invalid="true" aria-describedby="erreur-' . str_replace('.', '_', $cle) . ($aide !== '' ? ' ' . $aide : '') . '"'
    : ($aide !== '' ? ' aria-describedby="' . $aide . '"' : '');
$erreurChamp = static fn (string $cle): string => isset($erreurs[$cle])
    ? '<p class="champ__erreur" id="erreur-' . str_replace('.', '_', $cle) . '">' . e($erreurs[$cle]) . '</p>' : '';
$actives = ($valeurs['relances.actives'] ?? '1') === '1';
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-dettes.css">

<div class="tre-entete">
    <div>
        <h1><?= e(t('messagerie.titre')) ?></h1>
        <p class="aide"><?= e(t('messagerie.aide')) ?></p>
    </div>
</div>
<?= View::rendre('gestion/messagerie/_onglets', ['actif' => 'reglages']) ?>

<?php if ($flash): ?>
    <p class="alerte" role="status"><?= e($flash) ?></p>
<?php endif; ?>

<section class="det-etats" aria-label="<?= e(t('messagerie.etat')) ?>">
    <div class="det-etat" data-etat="sms" data-pret="<?= $smsPret ? '1' : '0' ?>">
        <strong><?= e(t('messagerie.canal_sms')) ?></strong>
        <span class="badge <?= $smsPret ? 'badge--succes' : 'badge--neutre' ?>"><?= e(t($smsPret ? 'messagerie.pret' : 'messagerie.non_regle')) ?></span>
    </div>
    <div class="det-etat" data-etat="whatsapp" data-pret="<?= $whatsappPret ? '1' : '0' ?>">
        <strong><?= e(t('messagerie.canal_whatsapp')) ?></strong>
        <span class="badge <?= $whatsappPret ? 'badge--succes' : 'badge--neutre' ?>"><?= e(t($whatsappPret ? 'messagerie.pret' : 'messagerie.non_regle')) ?></span>
    </div>
    <div class="det-etat" data-etat="relances">
        <strong><?= e(t('messagerie.relances_titre')) ?></strong>
        <span class="badge <?= $actives ? 'badge--succes' : 'badge--neutre' ?>"><?= e(t($actives ? 'messagerie.relances_on' : 'messagerie.relances_off')) ?></span>
    </div>
</section>
<p class="aide det-etats__aide"><?= e(t('messagerie.plateforme_aide')) ?></p>
<?php if (!$smsPret && !$whatsappPret): ?>
    <p class="alerte tre-note det-note" data-simule><?= e(t('messagerie.rien_ne_part')) ?></p>
<?php endif; ?>

<section class="bloc" aria-labelledby="det-passage-titre" id="passage">
    <h2 id="det-passage-titre"><?= e(t('messagerie.passage_titre')) ?></h2>
    <p data-dernier-passage="<?= e((string) $dernierPassage) ?>">
        <?= e($dernierPassage ? t('messagerie.dernier_passage', ['date' => A::dateHeure($dernierPassage)]) : t('messagerie.jamais_passe')) ?>
    </p>
    <?php if ($passageEnRetard): ?>
        <div class="tre-avertissement" role="status" data-passage-retard>
            <p class="tre-avertissement__titre"><?= e(t($dernierPassage ? 'messagerie.passage_ancien' : 'messagerie.passage_jamais')) ?></p>
            <p><?= e(t('messagerie.passage_conseil')) ?></p>
        </div>
    <?php endif; ?>
    <p class="aide"><?= e(t('messagerie.cron_aide')) ?></p>
    <pre class="det-code"><code><?= e($cron) ?></code></pre>
</section>

<?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => array_values(array_diff_key($erreurs, ['essai' => 1]))]) ?>

<form method="post" action="/gestion/messagerie" class="tre-formulaire" novalidate>
    <input type="hidden" name="csrf" value="<?= $jeton ?>">
    <section class="bloc" aria-labelledby="det-relances-titre">
        <h2 id="det-relances-titre"><?= e(t('messagerie.relances_titre')) ?></h2>
        <p class="aide"><?= e(t('messagerie.relances_aide')) ?></p>
        <label class="coche det-coche">
            <input type="checkbox" name="relances_actives" value="1" <?= $actives ? 'checked' : '' ?>>
            <span><?= e(t('messagerie.relances_actives')) ?></span>
        </label>
        <div class="tre-paire">
            <div class="champ">
                <label for="relances_etapes"><?= e(t('messagerie.relances_etapes')) ?></label>
                <input id="relances_etapes" name="relances_etapes" maxlength="64" autocomplete="off" spellcheck="false" inputmode="text"
                       value="<?= e($valeurs['relances.etapes'] ?? '') ?>" placeholder="-1,1,7,30"<?= $invalide('relances.etapes', 'relances_etapes-aide') ?>>
                <p class="champ__aide" id="relances_etapes-aide"><?= e(t('messagerie.relances_etapes_aide')) ?></p>
                <?= $erreurChamp('relances.etapes') ?>
            </div>
            <div class="champ">
                <label for="relances_heure"><?= e(t('messagerie.relances_heure')) ?></label>
                <select id="relances_heure" name="relances_heure"<?= $invalide('relances.heure', 'relances_heure-aide') ?>>
                    <?php for ($h = 0; $h < 24; $h++): ?>
                        <option value="<?= $h ?>" <?= (string) ($valeurs['relances.heure'] ?? '10') === (string) $h ? 'selected' : '' ?>><?= e(sprintf('%02d:00', $h)) ?></option>
                    <?php endfor; ?>
                </select>
                <p class="champ__aide" id="relances_heure-aide"><?= e(t('messagerie.relances_heure_aide')) ?></p>
                <?= $erreurChamp('relances.heure') ?>
            </div>
        </div>
        <fieldset class="tre-groupe-champs det-groupe">
            <legend><?= e(t('messagerie.relances_canal')) ?></legend>
            <div class="tre-moyens tre-moyens--compact">
                <?php foreach (['prefere', 'sms', 'whatsapp'] as $canal): ?>
                    <label class="tre-moyen"><input type="radio" name="relances_canal" value="<?= $canal ?>" <?= ($valeurs['relances.canal'] ?? 'prefere') === $canal ? 'checked' : '' ?>><span><?= e(t('messagerie.relances_canal_' . $canal)) ?></span></label>
                <?php endforeach; ?>
            </div>
            <p class="champ__aide"><?= e(t('messagerie.relances_canal_aide')) ?></p>
        </fieldset>
        <label class="coche det-coche">
            <input type="checkbox" name="relances_responsable" value="1" <?= ($valeurs['relances.responsable'] ?? '1') === '1' ? 'checked' : '' ?>>
            <span><?= e(t('messagerie.relances_responsable')) ?></span>
        </label>
        <p class="champ__aide"><?= e(t('messagerie.relances_responsable_aide')) ?></p>
        <div class="tre-actions">
            <button class="bouton" type="submit"><?= e(t('messagerie.enregistrer')) ?></button>
        </div>
    </section>
</form>

<section class="bloc" aria-labelledby="det-essai-titre" id="essai">
    <h2 id="det-essai-titre"><?= e(t('messagerie.essai_titre')) ?></h2>
    <p class="aide"><?= e(t('messagerie.essai_aide')) ?></p>
    <?php if (isset($erreurs['essai'])): ?>
        <p class="alerte alerte--erreur" role="alert" id="erreur-essai"><?= e($erreurs['essai']) ?></p>
    <?php endif; ?>
    <form method="post" action="/gestion/messagerie/essai" class="det-recherche" novalidate>
        <input type="hidden" name="csrf" value="<?= $jeton ?>">
        <div class="champ">
            <label for="essai-telephone"><?= e(t('messagerie.essai_telephone')) ?></label>
            <input id="essai-telephone" name="telephone" type="tel" inputmode="tel" maxlength="32" required value="<?= e($essai['telephone'] ?? '') ?>"
                   placeholder="+243 97 000 0000"<?= isset($erreurs['essai']) ? ' aria-invalid="true" aria-describedby="erreur-essai"' : '' ?>>
        </div>
        <button class="bouton bouton--secondaire" type="submit"><?= e(t('messagerie.essai_envoyer')) ?></button>
    </form>
</section>
