<?php
/**
 * Ajout ou modification d'une devise.
 * @var ?array $devise null pour une nouvelle devise
 * @var array $valeurs
 * @var array<string, string> $erreurs par champ
 * @var array $etablissements établissements qui acceptent la devise
 * @var bool $premiere aucune devise n'existe encore : celle-ci sera la référence
 */
$jeton = e(App\Core\Session::jetonCsrf());
$action = $devise ? '/gestion/devises/' . (int) $devise['id'] : '/gestion/devises/nouvelle';
$invalide = static fn (string $champ): string => isset($erreurs[$champ]) ? 'aria-invalid="true" aria-describedby="erreur-' . $champ . '"' : '';
$erreurChamp = static fn (string $champ): string => isset($erreurs[$champ])
    ? '<p class="dev-erreur-champ" id="erreur-' . $champ . '">' . e($erreurs[$champ]) . '</p>'
    : '';
$arrondi = (string) $valeurs['arrondi'];
if (is_numeric($arrondi)) {
    $arrondi = App\Controllers\Gestion\DeviseAffichage::tauxSaisie($arrondi);
}
?>
<link rel="stylesheet" href="/assets/css/gestion-devises.css">

<p class="dev-retour"><a class="lien" href="/gestion/devises">← <?= e(t('devises.titre')) ?></a></p>
<h1>
    <?= e($devise ? t('devises.modifier_titre', ['code' => $devise['code']]) : t('devises.nouvelle')) ?>
    <?php if ($devise && $devise['est_reference']): ?><span class="pastille"><?= e(t('gestion.reference')) ?></span><?php endif; ?>
</h1>
<?php if ($premiere): ?>
    <p class="aide"><?= e(t('devises.premiere_aide')) ?></p>
<?php endif; ?>

<?= App\Core\View::rendre('gestion/devises/_erreurs', ['erreurs' => array_values($erreurs)]) ?>

<section class="bloc">
    <form method="post" action="<?= e($action) ?>" class="formulaire dev-formulaire" id="formulaire-devise"
          data-langue="<?= e(App\Core\I18n::langue()) ?>">
        <input type="hidden" name="csrf" value="<?= $jeton ?>">

        <div class="dev-paire">
            <div class="champ">
                <label for="code"><?= e(t('devises.champ_code')) ?></label>
                <input id="code" name="code" required maxlength="3" minlength="3" pattern="[A-Za-z]{3}" autocapitalize="characters"
                       autocomplete="off" spellcheck="false" class="dev-majuscules" value="<?= e($valeurs['code']) ?>"
                       placeholder="EUR" <?= $invalide('code') ?>>
                <p class="aide"><?= e(t('devises.champ_code_aide')) ?></p>
                <?= $erreurChamp('code') ?>
            </div>
            <div class="champ">
                <label for="symbole"><?= e(t('devises.champ_symbole')) ?></label>
                <input id="symbole" name="symbole" required maxlength="8" autocomplete="off" value="<?= e($valeurs['symbole']) ?>"
                       placeholder="€" data-apercu <?= $invalide('symbole') ?>>
                <?= $erreurChamp('symbole') ?>
            </div>
        </div>

        <div class="dev-paire">
            <div class="champ">
                <label for="nom_fr"><?= e(t('devises.champ_nom_fr')) ?></label>
                <input id="nom_fr" name="nom_fr" required maxlength="64" lang="fr" value="<?= e($valeurs['nom_fr']) ?>"
                       placeholder="Euro" <?= $invalide('nom_fr') ?>>
                <?= $erreurChamp('nom_fr') ?>
            </div>
            <div class="champ">
                <label for="nom_en"><?= e(t('devises.champ_nom_en')) ?> <span class="dev-facultatif"><?= e(t('devises.facultatif')) ?></span></label>
                <input id="nom_en" name="nom_en" maxlength="64" lang="en" value="<?= e($valeurs['nom_en']) ?>"
                       placeholder="Euro" <?= $invalide('nom_en') ?>>
                <?= $erreurChamp('nom_en') ?>
            </div>
        </div>

        <fieldset class="dev-groupe" <?= isset($erreurs['decimales']) ? 'aria-describedby="erreur-decimales"' : '' ?>>
            <legend><?= e(t('devises.champ_decimales')) ?></legend>
            <div class="dev-choix">
                <?php foreach ([0, 1, 2, 3] as $n): ?>
                    <label class="dev-choix__option">
                        <input type="radio" name="decimales" value="<?= $n ?>" data-apercu <?= (string) $valeurs['decimales'] === (string) $n ? 'checked' : '' ?>>
                        <span><?= $n ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="aide"><?= e(t('devises.champ_decimales_aide')) ?></p>
            <?= $erreurChamp('decimales') ?>
        </fieldset>

        <div class="champ">
            <label for="arrondi"><?= e(t('devises.champ_arrondi')) ?></label>
            <input id="arrondi" name="arrondi" inputmode="decimal" autocomplete="off" value="<?= e($arrondi) ?>"
                   placeholder="0" data-apercu <?= $invalide('arrondi') ?>>
            <p class="aide"><?= e(t('devises.champ_arrondi_aide')) ?></p>
            <?= $erreurChamp('arrondi') ?>
        </div>

        <fieldset class="dev-groupe">
            <legend><?= e(t('devises.champ_position_symbole')) ?></legend>
            <div class="dev-choix">
                <label class="dev-choix__option">
                    <input type="radio" name="symbole_avant" value="1" data-apercu <?= $valeurs['symbole_avant'] ? 'checked' : '' ?>>
                    <span><?= e(t('devises.symbole_avant')) ?></span>
                </label>
                <label class="dev-choix__option">
                    <input type="radio" name="symbole_avant" value="0" data-apercu <?= !$valeurs['symbole_avant'] ? 'checked' : '' ?>>
                    <span><?= e(t('devises.symbole_apres')) ?></span>
                </label>
            </div>
        </fieldset>

        <p class="dev-apercu" aria-live="polite">
            <?= e(t('devises.apercu')) ?> <strong id="apercu-montant" class="nombre" data-montant="28437.5">—</strong>
        </p>

        <div class="champ dev-champ-court">
            <label for="position"><?= e(t('devises.champ_position')) ?></label>
            <input id="position" name="position" inputmode="numeric" pattern="[0-9]*" maxlength="3" value="<?= e((string) $valeurs['position']) ?>" <?= $invalide('position') ?>>
            <p class="aide"><?= e(t('devises.champ_position_aide')) ?></p>
            <?= $erreurChamp('position') ?>
        </div>

        <?php if ($devise === null && !$premiere): ?>
            <label class="dev-case">
                <input type="checkbox" name="active" value="1" <?= !empty($valeurs['active']) ? 'checked' : '' ?>>
                <span><?= e(t('devises.champ_active')) ?></span>
            </label>
        <?php endif; ?>

        <div class="dev-actions">
            <button class="bouton" type="submit"><?= e($devise ? t('devises.enregistrer') : t('devises.creer')) ?></button>
            <a class="bouton bouton--secondaire" href="/gestion/devises"><?= e(t('devises.annuler')) ?></a>
        </div>
    </form>
</section>

<?php if ($devise): ?>
    <section class="bloc">
        <h2><?= e(t('devises.etat_titre')) ?></h2>
        <?php if ($devise['est_reference']): ?>
            <p><?= e(t('devises.reference_toujours_active_long', ['code' => $devise['code']])) ?></p>
        <?php else: ?>
            <p>
                <?= e($devise['active'] ? t('devises.etat_active', ['code' => $devise['code']]) : t('devises.etat_inactive', ['code' => $devise['code']])) ?>
            </p>
            <?php if ($etablissements !== []): ?>
                <p class="aide"><?= e(t('devises.acceptee_par', ['liste' => implode(', ', array_column($etablissements, 'nom'))])) ?></p>
            <?php else: ?>
                <p class="aide"><?= e(t('devises.acceptee_par_aucun')) ?></p>
            <?php endif; ?>
            <form method="post" action="/gestion/devises/<?= (int) $devise['id'] ?>/<?= $devise['active'] ? 'desactiver#confirmation' : 'activer' ?>" class="dev-actions">
                <input type="hidden" name="csrf" value="<?= $jeton ?>">
                <button class="bouton <?= $devise['active'] ? 'bouton--secondaire' : '' ?>" type="submit">
                    <?= e($devise['active'] ? t('devises.desactiver_code', ['code' => $devise['code']]) : t('devises.activer_code', ['code' => $devise['code']])) ?>
                </button>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>

<script src="/assets/js/gestion-devises.js" defer></script>
