<?php
/**
 * Ventes > Fidélité : activation par établissement, points par montant dépensé, récompenses, meilleurs clients.
 * @var list<array> $etablissements @var array $etab @var ?array $reglages @var list<array> $recompenses @var list<array> $classement
 * @var array<int, array> $devises @var list<array> $formats @var bool $peutModifier @var ?string $flash
 */
use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$desactive = $peutModifier ? '' : ' disabled';
$formulaire = static function (?array $r) use ($jeton, $etab, $formats, $desactive): string {
    ob_start(); ?>
    <form class="sal-form" method="post" action="/gestion/fidelite/recompenses<?= $r ? '/' . (int) $r['id'] : '' ?>">
        <input type="hidden" name="csrf" value="<?= $jeton ?>">
        <input type="hidden" name="etablissement" value="<?= (int) $etab['id'] ?>">
        <label class="champ"><span><?= e(t('gsalle.nom_fr')) ?></span><input class="saisie" name="nom_fr" maxlength="80" required value="<?= e($r['nom_fr'] ?? '') ?>"<?= $desactive ?>></label>
        <label class="champ"><span><?= e(t('gsalle.nom_en')) ?></span><input class="saisie" name="nom_en" maxlength="80" value="<?= e($r['nom_en'] ?? '') ?>"<?= $desactive ?>></label>
        <label class="champ sal-champ--court"><span><?= e(t('gnuit.points')) ?></span><input class="saisie" type="number" name="points" min="1" required value="<?= (int) ($r['points'] ?? 100) ?>"<?= $desactive ?>></label>
        <label class="champ"><span><?= e(t('gnuit.recompense_type')) ?></span>
            <select class="saisie" name="type"<?= $desactive ?>><option value="remise"><?= e(t('gnuit.recompense_remise')) ?></option><option value="offert" <?= ($r['type'] ?? '') === 'offert' ? 'selected' : '' ?>><?= e(t('gnuit.recompense_offert')) ?></option></select></label>
        <label class="champ sal-champ--court"><span><?= e(t('gnuit.pourcent_libelle')) ?></span><input class="saisie" name="pourcent" inputmode="decimal" value="<?= isset($r['pourcent']) ? e(rtrim(rtrim((string) $r['pourcent'], '0'), '.')) : '' ?>"<?= $desactive ?>></label>
        <label class="champ"><span><?= e(t('gnuit.article_offert')) ?></span>
            <select class="saisie" name="conditionnement_id"<?= $desactive ?>><option value="0">—</option>
                <?php foreach ($formats as $f): ?><option value="<?= (int) $f['id'] ?>" <?= (int) ($r['conditionnement_id'] ?? 0) === (int) $f['id'] ? 'selected' : '' ?>><?= e($f['libelle'] . ' · ' . $f['format']) ?></option><?php endforeach; ?></select></label>
        <?php if ($r): ?><label class="coche"><input type="checkbox" name="actif" value="1" <?= $r['actif'] ? 'checked' : '' ?><?= $desactive ?>> <span><?= e(t('gsalle.actif')) ?></span></label><?php endif; ?>
        <?php if (!$desactive): ?><div class="sal-form__actions"><button class="bouton" type="submit"><?= e(t($r ? 'gsalle.enregistrer' : 'gnuit.recompense_ajouter')) ?></button></div><?php endif; ?>
    </form>
    <?php return (string) ob_get_clean();
};
?>
<link rel="stylesheet" href="/assets/css/gestion-salle.css">
<link rel="stylesheet" href="/assets/css/gestion-nuit.css">
<div class="sal-entete">
    <div>
        <h1><?= e(t('gnuit.fidelite_titre')) ?></h1>
        <p class="aide"><?= e(t('gnuit.fidelite_aide')) ?></p>
    </div>
</div>
<?php if ($flash): ?><p class="alerte" role="status"><?= e($flash) ?></p><?php endif; ?>
<?= View::rendre('gestion/salle/_etablissements', ['etablissements' => $etablissements, 'etab' => $etab, 'garder' => []]) ?>
<section class="bloc" aria-labelledby="fid-reglages">
    <h2 id="fid-reglages"><?= e(t('gnuit.fidelite_reglages')) ?></h2>
    <form class="sal-form" method="post" action="/gestion/fidelite/reglages">
        <input type="hidden" name="csrf" value="<?= $jeton ?>">
        <input type="hidden" name="etablissement" value="<?= (int) $etab['id'] ?>">
        <label class="coche"><input type="checkbox" name="actif" value="1" <?= !empty($reglages['actif']) ? 'checked' : '' ?><?= $desactive ?>> <span><?= e(t('gnuit.fidelite_activer')) ?></span></label>
        <label class="champ sal-champ--court"><span><?= e(t('gnuit.montant_point')) ?></span><input class="saisie" name="montant_point" inputmode="decimal" required value="<?= e(rtrim(rtrim((string) ($reglages['montant_point'] ?? '1'), '0'), '.')) ?>"<?= $desactive ?>></label>
        <label class="champ sal-champ--court"><span><?= e(t('nuit.c_devise')) ?></span>
            <select class="saisie" name="devise_id"<?= $desactive ?>><?php foreach ($devises as $id => $d): ?><option value="<?= (int) $id ?>" <?= (int) ($reglages['devise_id'] ?? 0) === (int) $id || (!$reglages && $d['code'] === 'USD') ? 'selected' : '' ?>><?= e($d['code']) ?></option><?php endforeach; ?></select></label>
        <?php if (!$desactive): ?><div class="sal-form__actions"><button class="bouton" type="submit"><?= e(t('gsalle.enregistrer')) ?></button></div><?php endif; ?>
    </form>
    <p class="aide"><?= e(t('gnuit.montant_point_aide')) ?></p>
</section>
<section class="bloc" aria-labelledby="fid-recompenses">
    <h2 id="fid-recompenses"><?= e(t('gnuit.recompenses')) ?></h2>
    <ul class="sal-tables">
        <?php foreach ($recompenses as $r): ?>
            <li class="sal-ligne"><details class="sal-details"><summary class="sal-details__resume"><strong><?= e(champ($r, 'nom')) ?></strong>
                <span class="sal-sous">· <?= (int) $r['points'] ?> <?= e(t('gnuit.points')) ?> · <?= e($r['type'] === 'remise' ? rtrim(rtrim((string) $r['pourcent'], '0'), '.') . ' %' : champ(['nom_fr' => $r['produit_fr'], 'nom_en' => $r['produit_en']], 'nom')) ?><?= $r['actif'] ? '' : ' · ' . e(t('gnuit.inactif')) ?></span></summary>
                <?= $formulaire($r) ?></details></li>
        <?php endforeach; ?>
        <?php if ($recompenses === []): ?><li><?= e(t('nuit.c_fidelite_aucune')) ?></li><?php endif; ?>
    </ul>
    <?php if ($peutModifier): ?><h3><?= e(t('gnuit.recompense_ajouter')) ?></h3><?= $formulaire(null) ?><?php endif; ?>
</section>
<section class="bloc" aria-labelledby="fid-clients">
    <h2 id="fid-clients"><?= e(t('gnuit.meilleurs_clients')) ?></h2>
    <div class="defile">
        <table class="tableau">
            <thead><tr><th><?= e(t('gnuit.nom')) ?></th><th class="nombre"><?= e(t('gnuit.points')) ?></th><th class="nombre"><?= e(t('gnuit.points_gagnes')) ?></th><th><?= e(t('gnuit.dernier_passage')) ?></th></tr></thead>
            <tbody>
                <?php foreach ($classement as $c): ?>
                    <tr><td><a href="/gestion/clients/<?= (int) $c['id'] ?>"><?= e($c['nom']) ?></a></td><td class="nombre"><?= (int) $c['points'] ?></td><td class="nombre"><?= (int) $c['gagnes'] ?></td><td><?= e(date('d/m/Y', strtotime($c['dernier']))) ?></td></tr>
                <?php endforeach; ?>
                <?php if ($classement === []): ?><tr><td colspan="4"><?= e(t('gnuit.aucun_client')) ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
