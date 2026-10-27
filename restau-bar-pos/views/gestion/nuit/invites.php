<?php
/**
 * Nuit > Invités : liste d'une soirée (nom, téléphone, +N, promoteur, entrée gratuite ou réduite, entrés), ajout,
 * modification, retrait (pas après l'arrivée).
 * @var list<array> $evenements @var ?array $evenement @var list<array> $invites @var string $q @var list<array> $promoteurs
 * @var list<array> $tarifs @var ?array $modifie @var ?string $flash @var ?string $erreur
 */
use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$total = array_sum(array_map(static fn (array $i) => 1 + (int) $i['accompagnants'], $invites));
$entres = array_sum(array_map(static fn (array $i) => (int) $i['arrives'], $invites));
?>
<link rel="stylesheet" href="/assets/css/gestion-salle.css">
<link rel="stylesheet" href="/assets/css/gestion-nuit.css">
<div class="sal-entete">
    <div>
        <h1><?= e(t('gnuit.invites_titre')) ?></h1>
        <p class="aide"><?= e(t('gnuit.invites_aide')) ?></p>
    </div>
</div>
<?php if ($flash): ?><p class="alerte" role="status"><?= e($flash) ?></p><?php endif; ?>
<?php if ($evenements === []): ?>
    <p class="alerte"><?= e(t('gnuit.aucune_soiree')) ?> <a href="/gestion/evenements/nouveau"><?= e(t('gnuit.creer_soiree')) ?></a></p>
    <?php return; ?>
<?php endif; ?>
<nav class="sal-puces" aria-label="<?= e(t('gnuit.soiree')) ?>">
    <?php foreach ($evenements as $ev): ?>
        <a class="puce" href="?evenement=<?= (int) $ev['id'] ?>"<?= $evenement && (int) $ev['id'] === (int) $evenement['id'] ? ' aria-current="true"' : '' ?>><?= e($ev['titre']) ?> · <?= e(date('d/m', strtotime($ev['debut']))) ?> (<?= (int) $ev['invites'] ?>)</a>
    <?php endforeach; ?>
</nav>
<?php if ($evenement): ?>
    <section class="bloc" aria-labelledby="nuit-liste">
        <h2 id="nuit-liste"><?= e($evenement['titre']) ?> · <?= e($evenement['etablissement_nom']) ?> · <?= e(date('d/m/Y H:i', strtotime($evenement['debut']))) ?></h2>
        <p class="aide"><?= e(t('gnuit.invites_compte', ['invites' => count($invites), 'personnes' => $total, 'entres' => $entres])) ?></p>
        <form class="sal-jour" method="get" action="/gestion/nuit/invites">
            <input type="hidden" name="evenement" value="<?= (int) $evenement['id'] ?>">
            <label class="champ"><span><?= e(t('nuit.c_invite_chercher')) ?></span><input class="saisie" type="search" name="q" value="<?= e($q) ?>"></label>
            <button class="bouton bouton--secondaire" type="submit"><?= e(t('tresorerie.afficher')) ?></button>
        </form>
        <div class="defile">
            <table class="tableau">
                <thead><tr><th><?= e(t('gnuit.nom')) ?></th><th><?= e(t('gnuit.promoteur')) ?></th><th><?= e(t('gnuit.entree')) ?></th><th class="nombre"><?= e(t('gnuit.entres')) ?></th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($invites as $i): ?>
                        <tr<?= (int) $i['arrives'] > 0 ? ' class="nuit-ligne--entre"' : '' ?>>
                            <td><strong><?= e($i['nom']) ?></strong><?= (int) $i['accompagnants'] > 0 ? ' +' . (int) $i['accompagnants'] : '' ?><?= $i['telephone'] ? '<br><small>' . e(afficherTelephone($i['telephone'])) . '</small>' : '' ?><?= $i['note'] ? '<br><small>' . e($i['note']) . '</small>' : '' ?></td>
                            <td><?= e($i['promoteur_nom'] ?? '—') ?></td>
                            <td><?= e($i['entree'] === 'gratuite' ? t('nuit.c_invite_gratuite') : t('nuit.c_invite_reduite') . ($i['tarif_fr'] ? ' · ' . champ($i, 'tarif') : '')) ?></td>
                            <td class="nombre"><?= (int) $i['arrives'] ?> / <?= 1 + (int) $i['accompagnants'] ?><?= $i['arrive_le'] ? '<br><small>' . e(date('H:i', strtotime($i['arrive_le']))) . '</small>' : '' ?></td>
                            <td class="nuit-actions-ligne">
                                <a class="bouton bouton--petit bouton--secondaire" href="?evenement=<?= (int) $evenement['id'] ?>&amp;modifier=<?= (int) $i['id'] ?>#formulaire"><?= e(t('gnuit.modifier')) ?></a>
                                <?php if ((int) $i['arrives'] === 0): ?>
                                    <form method="post" action="/gestion/nuit/invites/<?= (int) $i['id'] ?>/retirer"><input type="hidden" name="csrf" value="<?= $jeton ?>"><button class="bouton bouton--petit bouton--danger" type="submit"><?= e(t('gnuit.retirer')) ?></button></form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($invites === []): ?><tr><td colspan="5"><?= e(t('nuit.c_invite_aucun')) ?></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <section class="bloc" aria-labelledby="formulaire" id="formulaire">
        <h2 id="formulaire-titre"><?= e(t($modifie ? 'gnuit.invite_modifier' : 'gnuit.invite_ajouter')) ?></h2>
        <?= View::rendre('gestion/nuit/_invite-form', ['action' => '/gestion/nuit/invites' . ($modifie ? '/' . (int) $modifie['id'] : ''), 'evenement' => $evenement,
            'invite' => $modifie, 'promoteurs' => $promoteurs, 'tarifs' => $tarifs]) ?>
    </section>
<?php endif; ?>
