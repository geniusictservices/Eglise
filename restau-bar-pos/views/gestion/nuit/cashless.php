<?php
/**
 * Cashless > Cartes : recherche (numéro, porteur), statut, soldes par devise ; création d'un lot à imprimer.
 * @var list<array> $cartes @var string $q @var ?string $statut @var array<int, array> $devises @var ?string $flash
 */
$jeton = e(App\Core\Session::jetonCsrf());
$formateur = new App\Repositories\DeviseRepository();
$soldes = static function (array $c) use ($devises, $formateur): string {
    $t = [];
    foreach ($devises as $id => $d) {
        if (($c['soldes'][$id] ?? 0) > 0) {
            $t[] = $formateur->formater((float) $c['soldes'][$id], $d);
        }
    }
    return $t === [] ? '—' : implode(' · ', $t);
};
?>
<link rel="stylesheet" href="/assets/css/gestion-salle.css">
<link rel="stylesheet" href="/assets/css/gestion-nuit.css">
<div class="sal-entete">
    <div>
        <h1><?= e(t('gnuit.cashless_titre')) ?></h1>
        <p class="aide"><?= e(t('gnuit.cashless_aide')) ?></p>
    </div>
    <a class="bouton bouton--secondaire" href="/gestion/cashless/passif"><?= e(t('gnuit.passif_titre')) ?></a>
</div>
<?php if ($flash): ?><p class="alerte" role="status"><?= e($flash) ?></p><?php endif; ?>
<section class="bloc" aria-labelledby="cartes-creer">
    <h2 id="cartes-creer"><?= e(t('gnuit.cartes_creer')) ?></h2>
    <form class="sal-form" method="post" action="/gestion/cashless">
        <input type="hidden" name="csrf" value="<?= $jeton ?>">
        <label class="champ sal-champ--court"><span><?= e(t('gnuit.nombre')) ?></span><input class="saisie" type="number" name="nombre" min="1" max="200" value="10" required></label>
        <label class="champ"><span><?= e(t('gnuit.support')) ?></span>
            <select class="saisie" name="support"><option value="carte"><?= e(t('nuit.carte_titre')) ?></option><option value="bracelet"><?= e(t('nuit.bracelet_titre')) ?></option></select></label>
        <label class="champ"><span><?= e(t('nuit.c_carte_porteur')) ?></span><input class="saisie" name="porteur" maxlength="128"></label>
        <div class="sal-form__actions"><button class="bouton" type="submit"><?= e(t('gnuit.cartes_creer_bouton')) ?></button></div>
    </form>
</section>
<section class="bloc" aria-labelledby="cartes-liste">
    <h2 id="cartes-liste"><?= e(t('gnuit.cartes')) ?></h2>
    <form class="sal-jour" method="get" action="/gestion/cashless">
        <label class="champ"><span><?= e(t('gnuit.cartes_chercher')) ?></span><input class="saisie" type="search" name="q" value="<?= e($q) ?>"></label>
        <label class="champ"><span><?= e(t('gnuit.statut')) ?></span>
            <select class="saisie" name="statut"><option value=""><?= e(t('gnuit.toutes')) ?></option>
                <option value="active" <?= $statut === 'active' ? 'selected' : '' ?>><?= e(t('gnuit.carte_active')) ?></option>
                <option value="bloquee" <?= $statut === 'bloquee' ? 'selected' : '' ?>><?= e(t('nuit.c_carte_bloquee')) ?></option></select></label>
        <button class="bouton bouton--secondaire" type="submit"><?= e(t('tresorerie.afficher')) ?></button>
    </form>
    <div class="defile">
        <table class="tableau">
            <thead><tr><th><?= e(t('gnuit.numero')) ?></th><th><?= e(t('gnuit.porteur')) ?></th><th><?= e(t('gnuit.statut')) ?></th><th class="nombre"><?= e(t('nuit.c_soldes')) ?></th></tr></thead>
            <tbody>
                <?php foreach ($cartes as $c): ?>
                    <tr><td><a href="/gestion/cashless/<?= (int) $c['id'] ?>"><strong><?= e('C' . str_pad((string) $c['numero'], 4, '0', STR_PAD_LEFT)) ?></strong></a> <small><?= e(t('gnuit.support_' . $c['support'])) ?></small></td>
                        <td><?= e($c['porteur'] ?? $c['client_nom'] ?? '—') ?></td>
                        <td><span class="badge <?= $c['statut'] === 'active' ? 'badge--succes' : 'badge--erreur' ?>"><?= e($c['statut'] === 'active' ? t('gnuit.carte_active') : t('nuit.c_carte_bloquee')) ?></span></td>
                        <td class="nombre"><?= e($soldes($c)) ?></td></tr>
                <?php endforeach; ?>
                <?php if ($cartes === []): ?><tr><td colspan="4"><?= e(t('gnuit.aucune_carte')) ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
