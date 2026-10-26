<?php
/**
 * Stock > Dépôts et comptoirs : lieux par établissement, routage des ventes et réglages du stock.
 * @var list<array> $blocs etablissement, lieux, routage, zones, appareils, parametres, modifiable
 * @var list<array> $centraux dépôts centraux
 * @var bool $centralModifiable
 * @var list<array> $categories arbre (niveau 0 et 1)
 * @var array<string, string> $erreurs
 * @var array $valeurs
 */

use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$csrf = '<input type="hidden" name="csrf" value="' . $jeton . '">';

/** Liste des lieux avec, si modifiable, leur formulaire. */
$listeLieux = static function (array $lieux, bool $modifiable) use ($csrf, $erreurs, $valeurs): string {
    ob_start(); ?>
    <ul class="stk-lieux-liste">
        <?php foreach ($lieux as $l): $cle = 'lieu-' . (int) $l['id']; $v = $valeurs[$cle] ?? $l; ?>
            <li class="stk-lieu<?= $l['actif'] ? '' : ' stk-lieu--inactif' ?>" data-lieu="<?= (int) $l['id'] ?>">
                <div class="stk-lieu__tete">
                    <span class="stk-lieu__nom"><?= App\Core\Icones::svg($l['type'] === 'depot' ? 'lieux' : 'stock', 20) ?><strong><?= e($l['nom']) ?></strong></span>
                    <span class="stk-lieu__badges">
                        <span class="badge badge--neutre"><?= e(t('stock.type_lieu_' . $l['type'])) ?></span>
                        <?php if ($l['par_defaut']): ?><span class="badge badge--lave"><?= e(t('stock.par_defaut')) ?></span><?php endif; ?>
                        <?php if (!empty($l['vente_gros'])): ?><span class="badge badge--lave" data-vente-gros><?= e(t('stock.vente_gros_badge')) ?></span><?php endif; ?>
                        <?php if (!$l['actif']): ?><span class="badge badge--erreur"><?= e(t('stock.inactif')) ?></span><?php endif; ?>
                    </span>
                </div>
                <?php if ($l['description']): ?><p class="aide"><?= e($l['description']) ?></p><?php endif; ?>
                <?php if ($modifiable): ?>
                    <details class="stk-details"<?= isset($erreurs[$cle]) ? ' open' : '' ?>>
                        <summary class="lien"><?= e(t('stock.modifier_lieu')) ?></summary>
                        <form method="post" action="/gestion/stock/lieux/<?= (int) $l['id'] ?>" class="stk-form-lieu">
                            <?= $csrf ?>
                            <div class="champ">
                                <label for="nom-<?= (int) $l['id'] ?>"><?= e(t('stock.nom_lieu')) ?></label>
                                <input id="nom-<?= (int) $l['id'] ?>" name="nom" maxlength="80" value="<?= e($v['nom']) ?>"<?= isset($erreurs[$cle]) ? ' aria-invalid="true" aria-describedby="erreur-' . $cle . '"' : '' ?>>
                                <?php if (isset($erreurs[$cle])): ?><p class="champ__erreur" id="erreur-<?= $cle ?>"><?= e($erreurs[$cle]) ?></p><?php endif; ?>
                            </div>
                            <div class="champ">
                                <label for="description-<?= (int) $l['id'] ?>"><?= e(t('stock.description_lieu')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
                                <input id="description-<?= (int) $l['id'] ?>" name="description" maxlength="255" value="<?= e((string) $v['description']) ?>">
                            </div>
                            <?php if (!$l['par_defaut']): ?>
                                <label class="coche"><input type="checkbox" name="actif" value="1" <?= $l['actif'] ? 'checked' : '' ?>> <?= e(t('stock.lieu_actif')) ?></label>
                            <?php endif; ?>
                            <?php if ($l['type'] === 'depot' && $l['etablissement_id'] !== null && array_key_exists('vente_gros', $l)): ?>
                                <label class="coche"><input type="checkbox" name="vente_gros" value="1" <?= !empty($l['vente_gros']) ? 'checked' : '' ?>> <?= e(t('stock.vente_gros_option')) ?></label>
                                <p class="champ__aide"><?= e(t('stock.vente_gros_option_aide')) ?></p>
                            <?php endif; ?>
                            <div class="tre-actions"><button class="bouton bouton--secondaire" type="submit"><?= e(t('stock.enregistrer')) ?></button></div>
                        </form>
                        <div class="tre-actions">
                            <?php if ($l['type'] === 'comptoir' && !$l['par_defaut'] && $l['etablissement_id'] !== null): ?>
                                <form method="post" action="/gestion/stock/lieux/<?= (int) $l['id'] ?>/defaut"><?= $csrf ?><button class="bouton bouton--secondaire bouton--petit" type="submit"><?= e(t('stock.rendre_defaut')) ?></button></form>
                            <?php endif; ?>
                            <?php if (!$l['par_defaut']): ?>
                                <form method="post" action="/gestion/stock/lieux/<?= (int) $l['id'] ?>/supprimer"><?= $csrf ?><button class="lien tre-lien-danger" type="submit"><?= e(t('stock.supprimer_lieu')) ?></button></form>
                            <?php endif; ?>
                        </div>
                    </details>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php return (string) ob_get_clean();
};

/** Formulaire d'ajout d'un lieu ($cle : id de l'établissement ou « central »). */
$ajout = static function (string $cle, bool $central) use ($csrf, $erreurs, $valeurs): string {
    $v = $valeurs['nouveau-' . $cle] ?? ['nom' => '', 'type' => 'depot', 'description' => ''];
    $erreur = $erreurs['nouveau-' . $cle] ?? null;
    ob_start(); ?>
    <details class="stk-details stk-ajout"<?= $erreur ? ' open' : '' ?>>
        <summary class="bouton bouton--secondaire bouton--petit">+ <?= e(t($central ? 'stock.ajouter_central' : 'stock.ajouter_lieu')) ?></summary>
        <form method="post" action="/gestion/stock/lieux" class="stk-form-lieu">
            <?= $csrf ?>
            <input type="hidden" name="etablissement_id" value="<?= e($cle) ?>">
            <?php if (!$central): ?>
                <fieldset class="tre-groupe-champs">
                    <legend><?= e(t('stock.type_lieu')) ?></legend>
                    <div class="tre-moyens tre-moyens--compact stk-deux">
                        <?php foreach (['depot', 'comptoir'] as $type): ?>
                            <label class="tre-moyen"><input type="radio" name="type" value="<?= $type ?>" <?= $v['type'] === $type ? 'checked' : '' ?>><span><?= e(t('stock.type_lieu_' . $type)) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
            <?php endif; ?>
            <div class="champ">
                <label for="nouveau-nom-<?= e($cle) ?>"><?= e(t('stock.nom_lieu')) ?></label>
                <input id="nouveau-nom-<?= e($cle) ?>" name="nom" maxlength="80" value="<?= e($v['nom']) ?>" placeholder="<?= e(t($central ? 'stock.nom_central_exemple' : 'stock.nom_lieu_exemple')) ?>"<?= $erreur ? ' aria-invalid="true" aria-describedby="erreur-nouveau-' . e($cle) . '"' : '' ?>>
                <?php if ($erreur): ?><p class="champ__erreur" id="erreur-nouveau-<?= e($cle) ?>"><?= e($erreur) ?></p><?php endif; ?>
            </div>
            <div class="champ">
                <label for="nouveau-description-<?= e($cle) ?>"><?= e(t('stock.description_lieu')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
                <input id="nouveau-description-<?= e($cle) ?>" name="description" maxlength="255" value="<?= e((string) $v['description']) ?>">
            </div>
            <div class="tre-actions"><button class="bouton" type="submit"><?= e(t('stock.creer_lieu')) ?></button></div>
        </form>
    </details>
    <?php return (string) ob_get_clean();
};
?>
<?= View::rendre('gestion/stock/_onglets', ['flash' => $flash ?? null]) ?>

<div class="tre-entete">
    <div>
        <h1><?= e(t('stock.lieux_titre')) ?></h1>
        <p class="aide"><?= e(t('stock.lieux_aide')) ?></p>
    </div>
</div>

<?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => array_values($erreurs)]) ?>

<?php foreach ($blocs as $b): $etab = $b['etablissement']; $eid = (int) $etab['id'];
    $comptoirs = array_values(array_filter($b['lieux'], static fn (array $l): bool => $l['type'] === 'comptoir' && $l['actif']));
    $defaut = array_values(array_filter($comptoirs, static fn (array $l): bool => (bool) $l['par_defaut']))[0] ?? ($comptoirs[0] ?? null);
    $selectComptoir = static function (string $nom, string $id, ?int $choisi, string $libelle) use ($comptoirs, $defaut, $b): string {
        if (!$b['modifiable']) {
            foreach ($comptoirs as $c) {
                if ((int) $c['id'] === $choisi) {
                    return '<strong>' . e($c['nom']) . '</strong>';
                }
            }
            return e(t('stock.routage_defaut', ['nom' => $defaut['nom'] ?? '']));
        }
        $html = '<label class="visuellement-cache" for="' . $id . '">' . e($libelle) . '</label><select id="' . $id . '" name="' . $nom . '" class="saisie">';
        $html .= '<option value="">' . e(t('stock.routage_defaut', ['nom' => $defaut['nom'] ?? ''])) . '</option>';
        foreach ($comptoirs as $c) {
            $html .= '<option value="' . (int) $c['id'] . '"' . ((int) $c['id'] === $choisi ? ' selected' : '') . '>' . e($c['nom']) . '</option>';
        }
        return $html . '</select>';
    };
    ?>
    <section class="bloc stk-etab" id="etablissement-<?= $eid ?>" aria-labelledby="etab-titre-<?= $eid ?>">
        <h2 id="etab-titre-<?= $eid ?>"><?= e($etab['nom']) ?></h2>
        <?= $listeLieux($b['lieux'], $b['modifiable']) ?>
        <?php if ($b['modifiable']): ?><?= $ajout((string) $eid, false) ?><?php endif; ?>

        <div class="stk-sous-bloc" id="routage-<?= $eid ?>">
            <h3><?= e(t('stock.routage_titre')) ?></h3>
            <p class="aide"><?= e(t('stock.routage_aide')) ?></p>
            <form method="post" action="/gestion/stock/lieux/routage/<?= $eid ?>">
                <?= $csrf ?>
                <div class="tre-defile">
                    <table class="tableau stk-routage">
                        <thead><tr><th scope="col"><?= e(t('stock.routage_quoi')) ?></th><th scope="col"><?= e(t('stock.routage_comptoir')) ?></th></tr></thead>
                        <tbody>
                            <?php foreach ($categories as $c): ?>
                                <tr>
                                    <th scope="row" class="<?= $c['niveau'] ? 'stk-retrait' : '' ?>"><?= e(champ($c, 'nom')) ?></th>
                                    <td><?= $selectComptoir('categories[' . (int) $c['id'] . ']', 'r-' . $eid . '-c-' . (int) $c['id'], $b['routage']['categories'][(int) $c['id']] ?? null, champ($c, 'nom')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php foreach ($b['zones'] as $z): ?>
                                <tr>
                                    <th scope="row"><?= e(t('stock.routage_zone', ['nom' => champ($z, 'nom')])) ?></th>
                                    <td><?= $selectComptoir('zones[' . (int) $z['id'] . ']', 'r-' . $eid . '-z-' . (int) $z['id'], $b['routage']['zones'][(int) $z['id']] ?? null, champ($z, 'nom')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php foreach ($b['appareils'] as $a): ?>
                                <tr>
                                    <th scope="row"><?= e(t('stock.routage_appareil', ['nom' => $a['nom']])) ?></th>
                                    <td><?= $selectComptoir('appareils[' . (int) $a['id'] . ']', 'r-' . $eid . '-a-' . (int) $a['id'], $b['routage']['appareils'][(int) $a['id']] ?? null, $a['nom']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($b['modifiable']): ?><div class="tre-actions"><button class="bouton bouton--secondaire" type="submit"><?= e(t('stock.enregistrer_routage')) ?></button></div><?php endif; ?>
            </form>
        </div>

        <div class="stk-sous-bloc" id="reglages-<?= $eid ?>">
            <h3><?= e(t('stock.reglages_titre')) ?></h3>
            <form method="post" action="/gestion/stock/lieux/reglages/<?= $eid ?>">
                <?= $csrf ?>
                <fieldset class="tre-groupe-champs">
                    <legend><?= e(t('stock.mode_negatif')) ?></legend>
                    <div class="stk-choix">
                        <?php foreach (['vendre', 'bloquer'] as $mode): ?>
                            <label class="stk-choix__option">
                                <input type="radio" name="mode_negatif" value="<?= $mode ?>" <?= $b['parametres']['mode_negatif'] === $mode ? 'checked' : '' ?><?= $b['modifiable'] ? '' : ' disabled' ?>>
                                <span><strong><?= e(t('stock.mode_' . $mode)) ?></strong><span class="tre-sous-texte"><?= e(t('stock.mode_' . $mode . '_aide')) ?></span></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <label class="coche"><input type="checkbox" name="validation_separee" value="1" <?= $b['parametres']['validation_separee'] ? 'checked' : '' ?><?= $b['modifiable'] ? '' : ' disabled' ?>> <?= e(t('stock.validation_separee')) ?></label>
                <?php if (isset($b['comptesMarche'])): ?>
                    <div class="stk-form-grille stk-reglages-cuisine">
                        <div class="champ">
                            <label for="alerte-peremption-<?= $eid ?>"><?= e(t('stock.alerte_peremption')) ?></label>
                            <input type="number" min="0" max="60" step="1" id="alerte-peremption-<?= $eid ?>" name="alerte_peremption" value="<?= (int) $b['parametres']['alerte_peremption'] ?>"<?= $b['modifiable'] ? '' : ' disabled' ?>>
                            <p class="champ__aide"><?= e(t('stock.alerte_peremption_aide')) ?></p>
                        </div>
                        <div class="champ">
                            <label for="compte-marche-<?= $eid ?>"><?= e(t('stock.compte_marche')) ?></label>
                            <select id="compte-marche-<?= $eid ?>" name="compte_marche_id"<?= $b['modifiable'] ? '' : ' disabled' ?>>
                                <option value=""><?= e(t('stock.compte_marche_aucun')) ?></option>
                                <?php foreach ($b['comptesMarche'] as $c): ?>
                                    <option value="<?= (int) $c['id'] ?>" <?= (int) ($b['parametres']['compte_marche_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nom']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="champ__aide"><?= e(t('stock.compte_marche_aide')) ?></p>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($b['modifiable']): ?><div class="tre-actions"><button class="bouton bouton--secondaire" type="submit"><?= e(t('stock.enregistrer_reglages')) ?></button></div><?php endif; ?>
            </form>
        </div>
    </section>
<?php endforeach; ?>

<section class="bloc stk-etab" aria-labelledby="central-titre">
    <h2 id="central-titre"><?= e(t('stock.centraux_titre')) ?></h2>
    <p class="aide"><?= e(t('stock.centraux_aide')) ?></p>
    <?php if ($centraux !== []): ?><?= $listeLieux($centraux, $centralModifiable) ?><?php endif; ?>
    <?php if ($centralModifiable): ?><?= $ajout('central', true) ?><?php endif; ?>
</section>
