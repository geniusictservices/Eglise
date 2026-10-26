<?php
/**
 * @var array $codes
 * @var array $etablissements
 * @var array $zones
 * @var ?string $flash
 * @var string $base
 */
?>
<h1><?= e(t('gestion.qr')) ?></h1>
<p class="aide"><?= e(t('gestion.qr_aide')) ?></p>

<?php if ($flash): ?>
    <p class="alerte" role="status"><?= e($flash) ?></p>
<?php endif; ?>

<div class="grille-qr">
    <?php foreach ($codes as $qr): ?>
        <article class="carte-qr">
            <div class="carte-qr__image" data-qr="<?= e($base . '/q/' . $qr['code']) ?>" role="img"
                 aria-label="<?= e(t('gestion.qr_image', ['libelle' => $qr['libelle']])) ?>"></div>
            <div class="carte-qr__texte">
                <h2><?= e($qr['libelle']) ?></h2>
                <p><?= e($qr['etablissement']) ?><?= $qr['zone_fr'] ? ' · ' . e(champ($qr, 'zone')) : '' ?></p>
                <p class="carte-qr__url"><?= e($base . '/q/' . $qr['code']) ?></p>
                <p class="carte-qr__scans"><?= e(t('gestion.scans', ['total' => $qr['scans_total'], 'semaine' => $qr['scans_7j']])) ?></p>
                <div class="carte-qr__actions">
                    <a class="bouton bouton--secondaire" href="/gestion/qr/<?= e($qr['code']) ?>/imprimer" target="_blank" rel="noopener"><?= e(t('gestion.imprimer')) ?></a>
                    <button class="bouton bouton--secondaire" type="button" data-telecharger="<?= e($qr['code']) ?>"><?= e(t('gestion.telecharger_svg')) ?></button>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
</div>

<section class="bloc">
    <h2><?= e(t('gestion.qr_nouveau')) ?></h2>
    <form method="post" action="/gestion/qr" class="formulaire formulaire--ligne">
        <input type="hidden" name="csrf" value="<?= e(App\Core\Session::jetonCsrf()) ?>">
        <div class="champ">
            <label for="etablissement_id"><?= e(t('gestion.etablissement')) ?></label>
            <select id="etablissement_id" name="etablissement_id" required>
                <?php foreach ($etablissements as $etab): ?>
                    <option value="<?= (int) $etab['id'] ?>"><?= e($etab['nom']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="champ">
            <label for="zone_id"><?= e(t('gestion.zone')) ?></label>
            <select id="zone_id" name="zone_id">
                <option value=""><?= e(t('gestion.zone_aucune')) ?></option>
                <?php foreach ($etablissements as $etab): ?>
                    <optgroup label="<?= e($etab['nom']) ?>" data-etablissement="<?= (int) $etab['id'] ?>">
                        <?php foreach ($zones[$etab['id']] ?? [] as $zone): ?>
                            <option value="<?= (int) $zone['id'] ?>"><?= e(champ($zone, 'nom')) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="champ champ--large">
            <label for="libelle"><?= e(t('gestion.libelle')) ?></label>
            <input id="libelle" name="libelle" required maxlength="128" placeholder="<?= e(t('gestion.libelle_exemple')) ?>">
        </div>
        <button class="bouton" type="submit"><?= e(t('gestion.creer')) ?></button>
    </form>
</section>

<script src="/assets/vendor/qrcode.js"></script>
<script src="/assets/js/gestion-qr.js"></script>
