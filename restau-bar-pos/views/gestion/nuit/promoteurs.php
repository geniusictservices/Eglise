<?php
/**
 * Nuit > Promoteurs : liste (commissions dues), création et modification (compte, devise, commission par entrée,
 * % des notes de ses invités).
 * @var list<array> $promoteurs @var array<int, float> $dus @var list<array> $etablissements @var array<int, array> $devises
 * @var list<array> $comptes comptes au rôle « promoteur » @var ?array $modifie @var ?string $flash
 */
$jeton = e(App\Core\Session::jetonCsrf());
$formateur = new App\Repositories\DeviseRepository();
$m = $modifie ?? [];
?>
<link rel="stylesheet" href="/assets/css/gestion-salle.css">
<link rel="stylesheet" href="/assets/css/gestion-nuit.css">
<div class="sal-entete">
    <div>
        <h1><?= e(t('gnuit.promoteurs_titre')) ?></h1>
        <p class="aide"><?= e(t('gnuit.promoteurs_aide')) ?></p>
    </div>
</div>
<?php if ($flash): ?><p class="alerte" role="status"><?= e($flash) ?></p><?php endif; ?>
<section class="bloc" aria-labelledby="nuit-promoteurs">
    <h2 id="nuit-promoteurs"><?= e(t('gnuit.promoteurs')) ?></h2>
    <div class="defile">
        <table class="tableau">
            <thead><tr><th><?= e(t('gnuit.nom')) ?></th><th><?= e(t('gnuit.commission')) ?></th><th><?= e(t('gnuit.compte_promoteur')) ?></th><th class="nombre"><?= e(t('gnuit.du')) ?></th><th></th></tr></thead>
            <tbody>
                <?php foreach ($promoteurs as $p): $d = $devises[(int) $p['devise_id']] ?? null; ?>
                    <tr<?= $p['actif'] ? '' : ' class="sal-annulee"' ?>>
                        <td><a href="/gestion/nuit/promoteurs/<?= (int) $p['id'] ?>"><strong><?= e($p['nom']) ?></strong></a><?= $p['telephone'] ? '<br><small>' . e(afficherTelephone($p['telephone'])) . '</small>' : '' ?><?= $p['etablissement_nom'] ? '<br><small>' . e($p['etablissement_nom']) . '</small>' : '' ?></td>
                        <td><?= $p['par_entree'] !== null && $d ? e(t('gnuit.par_entree', ['montant' => $formateur->formater((float) $p['par_entree'], $d)])) : '' ?><?= $p['pourcent_notes'] !== null ? '<br>' . e(t('gnuit.pourcent_notes', ['pourcent' => rtrim(rtrim((string) $p['pourcent_notes'], '0'), '.')])) : '' ?></td>
                        <td><?= e($p['utilisateur_nom'] ?? '—') ?></td>
                        <td class="nombre"><?= $d ? e($formateur->formater((float) ($dus[(int) $p['id']] ?? 0), $d)) : '' ?></td>
                        <td><a class="bouton bouton--petit bouton--secondaire" href="?modifier=<?= (int) $p['id'] ?>#formulaire"><?= e(t('gnuit.modifier')) ?></a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($promoteurs === []): ?><tr><td colspan="5"><?= e(t('gnuit.aucun_promoteur')) ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<section class="bloc" id="formulaire" aria-labelledby="formulaire-titre">
    <h2 id="formulaire-titre"><?= e(t($modifie ? 'gnuit.promoteur_modifier' : 'gnuit.promoteur_ajouter')) ?></h2>
    <form class="sal-form" method="post" action="/gestion/nuit/promoteurs<?= $modifie ? '/' . (int) $modifie['id'] : '' ?>">
        <input type="hidden" name="csrf" value="<?= $jeton ?>">
        <label class="champ"><span><?= e(t('gnuit.nom')) ?></span><input class="saisie" name="nom" maxlength="128" required value="<?= e($m['nom'] ?? '') ?>"></label>
        <label class="champ"><span><?= e(t('gnuit.telephone')) ?></span><input class="saisie" name="telephone" type="tel" value="<?= e(isset($m['telephone']) ? afficherTelephone($m['telephone']) : '') ?>"></label>
        <label class="champ"><span><?= e(t('gnuit.compte_promoteur')) ?></span>
            <select class="saisie" name="utilisateur_id"><option value="0">—</option>
                <?php foreach ($comptes as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) ($m['utilisateur_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nom']) ?></option><?php endforeach; ?>
            </select></label>
        <label class="champ"><span><?= e(t('gestion.etablissement')) ?></span>
            <select class="saisie" name="etablissement_id"><option value="0"><?= e(t('gnuit.tous_etablissements')) ?></option>
                <?php foreach ($etablissements as $e): ?><option value="<?= (int) $e['id'] ?>" <?= (int) ($m['etablissement_id'] ?? 0) === (int) $e['id'] ? 'selected' : '' ?>><?= e($e['nom']) ?></option><?php endforeach; ?>
            </select></label>
        <label class="champ sal-champ--court"><span><?= e(t('nuit.c_devise')) ?></span>
            <select class="saisie" name="devise_id">
                <?php foreach ($devises as $id => $d): ?><option value="<?= (int) $id ?>" <?= (int) ($m['devise_id'] ?? 0) === (int) $id || (!$modifie && $d['code'] === 'USD') ? 'selected' : '' ?>><?= e($d['code']) ?></option><?php endforeach; ?>
            </select></label>
        <label class="champ sal-champ--court"><span><?= e(t('gnuit.par_entree_libelle')) ?></span><input class="saisie" name="par_entree" inputmode="decimal" value="<?= isset($m['par_entree']) ? e(rtrim(rtrim((string) $m['par_entree'], '0'), '.')) : '' ?>"></label>
        <label class="champ sal-champ--court"><span><?= e(t('gnuit.pourcent_libelle')) ?></span><input class="saisie" name="pourcent_notes" inputmode="decimal" value="<?= isset($m['pourcent_notes']) ? e(rtrim(rtrim((string) $m['pourcent_notes'], '0'), '.')) : '' ?>"></label>
        <?php if ($modifie): ?><label class="coche"><input type="checkbox" name="actif" value="1" <?= $m['actif'] ? 'checked' : '' ?>> <span><?= e(t('gsalle.actif')) ?></span></label><?php endif; ?>
        <div class="sal-form__actions"><button class="bouton" type="submit"><?= e(t($modifie ? 'gsalle.enregistrer' : 'gnuit.promoteur_ajouter')) ?></button></div>
    </form>
    <p class="aide"><?= e(t('gnuit.promoteur_compte_aide')) ?></p>
</section>
