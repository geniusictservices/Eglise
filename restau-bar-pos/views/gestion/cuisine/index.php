<?php
/**
 * Cuisine > Postes et écrans : postes, routage des catégories, écrans autorisés, temps de préparation du jour.
 * @var ?string $flash
 * @var ?string $erreur
 * @var list<array> $etablissements
 * @var array $etab
 * @var list<array> $postes
 * @var list<array> $categories
 * @var array<int, int> $routage catégorie → poste (routage propre)
 * @var array<int, ?int> $parStock catégorie → poste donné par le comptoir du stock (sans routage propre)
 * @var list<array> $comptoirs comptoirs du module stock
 * @var list<array> $ecrans
 * @var list<array> $temps
 * @var bool $peutModifier
 */
$jeton = e(App\Core\Session::jetonCsrf());
$desactive = $peutModifier ? '' : ' disabled';
$nomPoste = [];
foreach ($postes as $p) {
    $nomPoste[(int) $p['id']] = $p['nom'];
}
?>
<link rel="stylesheet" href="/assets/css/gestion-salle.css">
<div class="sal-entete">
    <div>
        <h1><?= e(t('gcuisine.titre')) ?></h1>
        <p class="aide"><?= e(t('gcuisine.aide')) ?></p>
    </div>
    <a class="bouton" href="/caisse/cuisine?etab=<?= e($etab['slug']) ?>"><?= e(t('gcuisine.ouvrir_ecran')) ?></a>
</div>
<?php if ($flash): ?><p class="alerte" role="status"><?= e($flash) ?></p><?php endif; ?>
<?php if ($erreur): ?><p class="alerte alerte--erreur" role="alert"><?= e($erreur) ?></p><?php endif; ?>
<?= App\Core\View::rendre('gestion/salle/_etablissements', ['etablissements' => $etablissements, 'etab' => $etab, 'garder' => []]) ?>

<section class="bloc" aria-labelledby="cui-postes-titre">
    <h2 id="cui-postes-titre"><?= e(t('gcuisine.postes')) ?></h2>
    <?php if ($postes === []): ?><p class="aide"><?= e(t('gcuisine.aucun_poste')) ?></p><?php endif; ?>
    <?php foreach (array_merge($postes, $peutModifier ? [null] : []) as $p): ?>
        <form class="sal-form" method="post" action="/gestion/cuisine/postes<?= $p ? '/' . (int) $p['id'] : '' ?>">
            <input type="hidden" name="csrf" value="<?= $jeton ?>">
            <input type="hidden" name="etablissement" value="<?= (int) $etab['id'] ?>">
            <?php if ($p === null): ?><h3 class="sal-happy__titre"><?= e(t('gcuisine.ajouter')) ?></h3><?php endif; ?>
            <label class="champ"><span><?= e(t('gcuisine.nom')) ?></span><input class="saisie" name="nom" maxlength="60" required value="<?= e($p['nom'] ?? '') ?>" placeholder="<?= e(t('gcuisine.nom_exemple')) ?>"<?= $desactive ?>></label>
            <label class="champ sal-champ--court"><span><?= e(t('gcuisine.alerte')) ?></span><input class="saisie" name="alerte" type="number" min="1" max="240" value="<?= (int) ($p['alerte_minutes'] ?? 15) ?>"<?= $desactive ?>></label>
            <?php if ($comptoirs !== []): ?>
                <label class="champ"><span><?= e(t('gcuisine.comptoir')) ?></span>
                    <select class="saisie" name="lieu"<?= $desactive ?>>
                        <option value="0"><?= e(t('gcuisine.sans_comptoir')) ?></option>
                        <?php foreach ($comptoirs as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $p && (int) $p['lieu_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nom']) ?></option><?php endforeach; ?>
                    </select></label>
            <?php endif; ?>
            <?php if ($p !== null): ?><label class="coche"><input type="checkbox" name="actif" value="1" <?= $p['actif'] ? 'checked' : '' ?><?= $desactive ?>> <span><?= e(t('gsalle.actif')) ?></span></label><?php endif; ?>
            <?php if ($peutModifier): ?><div class="sal-form__actions"><button class="bouton" type="submit"><?= e(t($p ? 'gsalle.enregistrer' : 'gcuisine.ajouter')) ?></button></div><?php endif; ?>
        </form>
    <?php endforeach; ?>
    <?php if ($comptoirs !== []): ?><p class="aide"><?= e(t('gcuisine.comptoir_aide')) ?></p><?php endif; ?>
</section>

<section class="bloc" aria-labelledby="cui-routage-titre">
    <h2 id="cui-routage-titre"><?= e(t('gcuisine.routage')) ?></h2>
    <p class="aide"><?= e(t('gcuisine.routage_aide')) ?></p>
    <form method="post" action="/gestion/cuisine/routage">
        <input type="hidden" name="csrf" value="<?= $jeton ?>">
        <input type="hidden" name="etablissement" value="<?= (int) $etab['id'] ?>">
        <div class="defile"><table class="tableau">
            <thead><tr><th><?= e(t('gcuisine.categorie')) ?></th><th><?= e(t('gcuisine.poste')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($categories as $c): $id = (int) $c['id']; $stock = $parStock[$id] ?? null; ?>
                <tr>
                    <td><?= $c['parent_id'] !== null ? '<span class="sal-sous">↳</span> ' : '' ?><label for="routage-<?= $id ?>"><?= e(champ($c, 'nom')) ?></label></td>
                    <td>
                        <select class="saisie" id="routage-<?= $id ?>" name="routage[<?= $id ?>]"<?= $desactive ?>>
                            <option value="0"><?= e($stock !== null ? t('gcuisine.selon_stock', ['poste' => $nomPoste[$stock] ?? '']) : ($c['parent_id'] !== null ? t('gcuisine.comme_parent') : t('gcuisine.pas_cuisine'))) ?></option>
                            <?php foreach ($postes as $p): if (!$p['actif']) { continue; } ?><option value="<?= (int) $p['id'] ?>" <?= ($routage[$id] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['nom']) ?></option><?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php if ($peutModifier && $postes !== []): ?><div class="sal-form__actions"><button class="bouton" type="submit"><?= e(t('gcuisine.routage_enregistrer')) ?></button></div><?php endif; ?>
    </form>
</section>

<section class="bloc" aria-labelledby="cui-ecrans-titre">
    <h2 id="cui-ecrans-titre"><?= e(t('gcuisine.ecrans')) ?></h2>
    <p class="aide"><?= e(t('gcuisine.ecrans_aide')) ?></p>
    <?php if ($ecrans === []): ?><p class="aide"><?= e(t('gcuisine.aucun_ecran')) ?></p><?php else: ?>
        <ul class="sal-liste-simple">
            <?php foreach ($ecrans as $ec): ?>
                <li class="sal-ecran">
                    <span><strong><?= e($ec['nom']) ?></strong> · <?= e($ec['poste_nom'] ?? t('cuisine.tous_postes')) ?> · <?= e(t('gcuisine.autorise_par', ['nom' => $ec['autorise_par_nom'] ?? '—', 'date' => date('d/m/Y', strtotime($ec['cree_le']))])) ?><?= $ec['dernier_usage'] ? ' · ' . e(t('gcuisine.dernier_usage', ['date' => date('d/m H:i', strtotime($ec['dernier_usage']))])) : '' ?></span>
                    <?php if ($peutModifier): ?>
                        <form method="post" action="/gestion/cuisine/ecrans/<?= (int) $ec['id'] ?>/retirer"><input type="hidden" name="csrf" value="<?= $jeton ?>"><button class="bouton bouton--danger bouton--petit" type="submit"><?= e(t('gcuisine.retirer')) ?></button></form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php if ($postes !== []): ?>
    <section class="bloc" aria-labelledby="cui-temps-titre">
        <h2 id="cui-temps-titre"><?= e(t('gsalle.temps_titre')) ?></h2>
        <div class="defile"><table class="tableau">
            <thead><tr><th><?= e(t('gcuisine.poste')) ?></th><th class="nombre"><?= e(t('gsalle.plats')) ?></th><th class="nombre"><?= e(t('gsalle.temps_moyen')) ?></th><th class="nombre"><?= e(t('gsalle.retards')) ?></th></tr></thead>
            <tbody><?php foreach ($temps as $tp): ?><tr><td><?= e($tp['poste']) ?></td><td class="nombre"><?= $tp['plats'] ?></td><td class="nombre"><?= $tp['moyenne'] !== null ? e(t('note.minutes', ['n' => str_replace('.', ',', (string) $tp['moyenne'])])) : '—' ?></td><td class="nombre"><?= $tp['retards'] ?></td></tr><?php endforeach; ?></tbody>
        </table></div>
    </section>
<?php endif; ?>
