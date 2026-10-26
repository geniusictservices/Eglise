<?php
/**
 * Personnel > Présence > Bornes : tablettes autorisées qui affichent le code QR changeant, et autorisation
 * de ce navigateur comme borne (le gérant est ensuite déconnecté, comme pour une caisse partagée).
 * @var ?string $flash
 * @var list<array> $bornes
 * @var list<array> $etablissements établissements où la personne gère le personnel
 * @var ?array $cetteBorne
 */

use App\Controllers\Gestion\PersonnelAffichage as P;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
?>
<?= View::rendre('gestion/personnel/_onglets-presence', ['actif' => 'bornes']) ?>
<h1><?= e(t('pers.bornes_titre')) ?></h1>
<p class="aide"><?= e(t('pers.bornes_aide')) ?></p>
<?php if ($flash): ?><p class="alerte" role="status"><?= e($flash) ?></p><?php endif; ?>

<?php if ($cetteBorne): ?>
    <p class="alerte" role="status"><?= e(t('pers.cette_borne', ['nom' => $cetteBorne['nom']])) ?> <a class="lien" href="/pointage/borne"><?= e(t('pers.ouvrir_borne')) ?></a></p>
<?php endif; ?>

<section class="bloc" aria-labelledby="per-bornes">
    <h2 id="per-bornes"><?= e(t('pers.bornes_liste')) ?></h2>
    <?php if ($bornes === []): ?>
        <p class="aide"><?= e(t('pers.aucune_borne')) ?></p>
    <?php else: ?>
        <ul class="det-lignes">
            <?php foreach ($bornes as $b): ?>
                <li data-borne="<?= (int) $b['id'] ?>">
                    <span><strong><?= e($b['nom']) ?></strong> <?= $b['actif'] ? '' : '<span class="badge badge--neutre">' . e(t('pers.retiree')) . '</span>' ?>
                        <span class="tre-sous-texte"><?= e($b['etablissement_nom'] . ' · ' . t('pers.autorisee_par', ['nom' => $b['autorise_par_nom'] ?? '?', 'date' => P::jour($b['cree_le'])]) . ' · ' . t('pers.dernier_usage', ['date' => $b['dernier_usage'] ? A::dateHeure($b['dernier_usage']) : '—'])) ?></span></span>
                    <?php if ($b['actif'] && in_array((int) $b['etablissement_id'], array_map(static fn ($e) => (int) $e['id'], $etablissements), true)): ?>
                        <form method="post" action="/gestion/personnel/presence/bornes/<?= (int) $b['id'] ?>/retirer"><input type="hidden" name="csrf" value="<?= $jeton ?>"><button class="lien" type="submit"><?= e(t('pers.retirer')) ?></button></form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php if ($etablissements !== []): ?>
    <section class="bloc" aria-labelledby="per-autoriser">
        <h2 id="per-autoriser"><?= e(t('pers.autoriser_borne')) ?></h2>
        <p class="aide"><?= e(t('pers.autoriser_borne_aide')) ?></p>
        <form method="post" action="/gestion/personnel/presence/bornes" class="per-ligne-formulaire">
            <input type="hidden" name="csrf" value="<?= $jeton ?>">
            <div class="champ">
                <label for="borne-etab"><?= e(t('gestion.etablissement')) ?></label>
                <select id="borne-etab" name="etablissement_id">
                    <?php foreach ($etablissements as $e): ?><option value="<?= (int) $e['id'] ?>"><?= e($e['nom']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="champ">
                <label for="borne-nom"><?= e(t('pers.champ_nom_borne')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
                <input id="borne-nom" name="nom" maxlength="80" placeholder="<?= e(t('pers.champ_nom_borne_exemple')) ?>">
            </div>
            <button class="bouton" type="submit"><?= e(t('pers.autoriser_borne_oui')) ?></button>
        </form>
    </section>
<?php endif; ?>
