<?php
/**
 * Nuit > Formules : bouteilles avec softs et formules (un produit de la carte et ses groupes de composants : la
 * bouteille fixe, « 4 softs au choix »…). Le prix est celui du produit dans la carte (chaque grille).
 * @var list<array> $formules @var list<array> $candidats @var list<array> $formats @var ?array $modifie @var ?array $produitModifie
 * @var bool $peutModifier @var ?string $flash
 */
$jeton = e(App\Core\Session::jetonCsrf());
$groupes = $modifie['groupes'] ?? [];
$groupes = array_merge($groupes, array_fill(0, max(2, 3 - count($groupes)), null));
$parCategorie = [];
foreach ($formats as $f) {
    $parCategorie[$f['categorie']][] = $f;
}
?>
<link rel="stylesheet" href="/assets/css/gestion-salle.css">
<link rel="stylesheet" href="/assets/css/gestion-nuit.css">
<div class="sal-entete">
    <div>
        <h1><?= e(t('gnuit.formules_titre')) ?></h1>
        <p class="aide"><?= e(t('gnuit.formules_aide')) ?></p>
    </div>
    <a class="bouton bouton--secondaire" href="/gestion/catalogue"><?= e(t('gnuit.prix_dans_carte')) ?></a>
</div>
<?php if ($flash): ?><p class="alerte" role="status"><?= e($flash) ?></p><?php endif; ?>
<section class="bloc" aria-labelledby="nuit-formules">
    <h2 id="nuit-formules"><?= e(t('gnuit.formules')) ?></h2>
    <ul class="sal-tables">
        <?php foreach ($formules as $f): ?>
            <li class="sal-ligne">
                <strong><a href="?produit=<?= (int) $f['produit_id'] ?>#formulaire"><?= e(champ($f, 'nom')) ?></a></strong>
                <span class="sal-sous">· <?= e(champ(['nom_fr' => $f['categorie_fr'], 'nom_en' => $f['categorie_en']], 'nom')) ?><?= $f['actif'] ? '' : ' · ' . e(t('gnuit.inactif')) ?></span>
                <ul class="nuit-tarifs">
                    <?php foreach ($f['formule']['groupes'] ?? [] as $g): ?>
                        <li><?= e($g['nom']) ?> : <?= count($g['options']) === 1 ? (int) $g['choix'] . ' × ' . e($g['options'][0]['libelle'] . ' · ' . $g['options'][0]['format']) : e(t('gnuit.au_choix', ['n' => $g['choix'], 'options' => implode(', ', array_map(static fn (array $o) => $o['libelle'], $g['options']))])) ?></li>
                    <?php endforeach; ?>
                </ul>
            </li>
        <?php endforeach; ?>
        <?php if ($formules === []): ?><li><?= e(t('gnuit.aucune_formule')) ?></li><?php endif; ?>
    </ul>
</section>
<?php if ($peutModifier): ?>
    <section class="bloc" id="formulaire" aria-labelledby="formule-titre">
        <h2 id="formule-titre"><?= e($produitModifie ? t('gnuit.formule_modifier', ['nom' => champ($produitModifie, 'nom')]) : t('gnuit.formule_ajouter')) ?></h2>
        <form class="nuit-form-formule" method="post" action="/gestion/nuit/formules">
            <input type="hidden" name="csrf" value="<?= $jeton ?>">
            <?php if ($produitModifie): ?>
                <input type="hidden" name="produit_id" value="<?= (int) $produitModifie['id'] ?>">
                <input type="hidden" name="actif" value="0">
                <label class="coche"><input type="checkbox" name="actif" value="1" <?= ($modifie['actif'] ?? true) ? 'checked' : '' ?>> <span><?= e(t('gsalle.actif')) ?></span></label>
            <?php else: ?>
                <label class="champ"><span><?= e(t('gnuit.produit_formule')) ?></span>
                    <select class="saisie" name="produit_id" required><option value=""><?= e(t('gnuit.choisir')) ?></option>
                        <?php foreach ($candidats as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e(champ($c, 'nom')) ?> · <?= e(champ(['nom_fr' => $c['categorie_fr'], 'nom_en' => $c['categorie_en']], 'nom')) ?></option><?php endforeach; ?>
                    </select>
                    <span class="champ__aide"><?= e(t('gnuit.produit_formule_aide')) ?></span></label>
            <?php endif; ?>
            <?php foreach ($groupes as $i => $g): $choisies = array_column($g['options'] ?? [], 'id'); ?>
                <fieldset class="nuit-formule__groupe">
                    <legend><?= e(t('gnuit.groupe', ['n' => $i + 1])) ?></legend>
                    <div class="sal-form">
                        <label class="champ"><span><?= e(t('gsalle.nom_fr')) ?></span><input class="saisie" name="groupes[<?= $i ?>][nom_fr]" maxlength="64" value="<?= e($g['nom_fr'] ?? '') ?>" placeholder="<?= e(t('gnuit.groupe_exemple')) ?>"></label>
                        <label class="champ"><span><?= e(t('gsalle.nom_en')) ?></span><input class="saisie" name="groupes[<?= $i ?>][nom_en]" maxlength="64" value="<?= e($g['nom_en'] ?? '') ?>"></label>
                        <label class="champ sal-champ--court"><span><?= e(t('gnuit.a_choisir')) ?></span><input class="saisie" type="number" min="1" max="50" name="groupes[<?= $i ?>][choix]" value="<?= (int) ($g['choix'] ?? 1) ?>"></label>
                        <label class="champ champ--large"><span><?= e(t('gnuit.options')) ?></span>
                            <select class="saisie nuit-multiple" name="groupes[<?= $i ?>][options][]" multiple size="6">
                                <?php foreach ($parCategorie as $categorie => $liste): ?>
                                    <optgroup label="<?= e($categorie) ?>">
                                        <?php foreach ($liste as $f): ?><option value="<?= (int) $f['id'] ?>" <?= in_array((int) $f['id'], $choisies, true) ? 'selected' : '' ?>><?= e($f['libelle'] . ' · ' . $f['format']) ?></option><?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                            <span class="champ__aide"><?= e(t('gnuit.options_aide')) ?></span></label>
                    </div>
                </fieldset>
            <?php endforeach; ?>
            <div class="sal-form__actions">
                <button class="bouton" type="submit"><?= e(t('gsalle.enregistrer')) ?></button>
                <?php if ($produitModifie): ?><button class="bouton bouton--danger" type="submit" formaction="/gestion/nuit/formules/<?= (int) $produitModifie['id'] ?>/retirer" formnovalidate><?= e(t('gnuit.formule_retirer')) ?></button><?php endif; ?>
            </div>
        </form>
    </section>
<?php endif; ?>
