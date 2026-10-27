<?php
/**
 * Nuit > Minimums de consommation : par zone (carré VIP) ou par table, pour une note (une soirée), montants dans la
 * devise locale et/ou en dollars ; à l'encaissement, la différence s'ajoute (ou la caisse avertit seulement).
 * @var list<array> $etablissements @var array $etab @var list<array> $minimums @var list<array> $zones @var list<array> $tables
 * @var array<int, array> $acceptees @var bool $peutModifier @var ?string $flash @var ?string $erreur
 */
use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$desactive = $peutModifier ? '' : ' disabled';
$formulaire = static function (?array $m) use ($jeton, $etab, $zones, $tables, $acceptees, $desactive): string {
    $cible = $m ? ($m['table_id'] !== null ? 't' . $m['table_id'] : 'z' . $m['zone_id']) : '';
    ob_start(); ?>
    <form class="sal-form" method="post" action="/gestion/nuit/minimums<?= $m ? '/' . (int) $m['id'] : '' ?>">
        <input type="hidden" name="csrf" value="<?= $jeton ?>">
        <input type="hidden" name="etablissement" value="<?= (int) $etab['id'] ?>">
        <label class="champ"><span><?= e(t('gnuit.minimum_cible')) ?></span>
            <select class="saisie" name="cible" required<?= $desactive ?>>
                <option value=""><?= e(t('gnuit.choisir')) ?></option>
                <optgroup label="<?= e(t('gestion.zone')) ?>"><?php foreach ($zones as $z): ?><option value="z<?= (int) $z['id'] ?>" <?= $cible === 'z' . $z['id'] ? 'selected' : '' ?>><?= e(champ($z, 'nom')) ?></option><?php endforeach; ?></optgroup>
                <optgroup label="<?= e(t('gnuit.tables')) ?>"><?php foreach ($tables as $t): ?><option value="t<?= (int) $t['id'] ?>" <?= $cible === 't' . $t['id'] ? 'selected' : '' ?>><?= e($t['nom']) ?></option><?php endforeach; ?></optgroup>
            </select></label>
        <?= View::rendre('gestion/nuit/_montants', ['acceptees' => $acceptees, 'valeurs' => $m['montants'] ?? [], 'id' => 'minimum-' . ($m['id'] ?? 'nouveau'), 'desactive' => $desactive !== '']) ?>
        <label class="champ"><span><?= e(t('gnuit.minimum_action')) ?></span>
            <select class="saisie" name="action"<?= $desactive ?>>
                <option value="ajouter"><?= e(t('gnuit.minimum_ajouter')) ?></option>
                <option value="avertir" <?= ($m['action'] ?? '') === 'avertir' ? 'selected' : '' ?>><?= e(t('gnuit.minimum_avertir')) ?></option>
            </select></label>
        <?php if ($m): ?><label class="coche"><input type="checkbox" name="actif" value="1" <?= $m['actif'] ? 'checked' : '' ?><?= $desactive ?>> <span><?= e(t('gsalle.actif')) ?></span></label><?php endif; ?>
        <?php if (!$desactive): ?>
            <div class="sal-form__actions">
                <button class="bouton" type="submit"><?= e(t($m ? 'gsalle.enregistrer' : 'gnuit.minimum_nouveau')) ?></button>
                <?php if ($m): ?><button class="bouton bouton--danger" type="submit" formaction="/gestion/nuit/minimums/<?= (int) $m['id'] ?>/supprimer" formnovalidate><?= e(t('gsalle.supprimer')) ?></button><?php endif; ?>
            </div>
        <?php endif; ?>
    </form>
    <?php return (string) ob_get_clean();
};
?>
<link rel="stylesheet" href="/assets/css/gestion-salle.css">
<link rel="stylesheet" href="/assets/css/gestion-nuit.css">
<div class="sal-entete">
    <div>
        <h1><?= e(t('gnuit.minimums_titre')) ?></h1>
        <p class="aide"><?= e(t('gnuit.minimums_aide')) ?></p>
    </div>
</div>
<?php if ($flash): ?><p class="alerte" role="status"><?= e($flash) ?></p><?php endif; ?>
<?php if ($erreur): ?><p class="alerte alerte--erreur" role="alert"><?= e($erreur) ?></p><?php endif; ?>
<?= View::rendre('gestion/salle/_etablissements', ['etablissements' => $etablissements, 'etab' => $etab, 'garder' => []]) ?>
<section class="bloc" aria-labelledby="nuit-minimums">
    <h2 id="nuit-minimums"><?= e(t('gnuit.minimums')) ?></h2>
    <ul class="sal-tables">
        <?php foreach ($minimums as $m): ?>
            <li class="sal-ligne">
                <details class="sal-details">
                    <summary class="sal-details__resume">
                        <strong><?= e($m['table_nom'] ? t('salle.table_nom', ['nom' => $m['table_nom']]) : champ(['nom_fr' => $m['zone_fr'], 'nom_en' => $m['zone_en']], 'nom')) ?></strong>
                        <span class="montant">· <?= View::rendre('gestion/nuit/_prix', ['montants' => $m['montants'], 'devises' => $acceptees]) ?></span>
                        <span class="sal-sous">· <?= e(t($m['action'] === 'ajouter' ? 'gnuit.minimum_ajouter' : 'gnuit.minimum_avertir')) ?><?= $m['actif'] ? '' : ' · ' . e(t('gnuit.inactif')) ?></span>
                    </summary>
                    <?= $formulaire($m) ?>
                </details>
            </li>
        <?php endforeach; ?>
        <?php if ($minimums === []): ?><li><?= e(t('gnuit.aucun_minimum')) ?></li><?php endif; ?>
    </ul>
    <?php if ($peutModifier): ?>
        <h3><?= e(t('gnuit.minimum_nouveau')) ?></h3>
        <?= $formulaire(null) ?>
    <?php endif; ?>
</section>
