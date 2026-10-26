<?php
/**
 * Liste des établissements.
 * @var ?string $flash
 * @var array $etablissements avec 'etat' et 'sans_horaires'
 */
?>
<link rel="stylesheet" href="/assets/css/gestion-etablissements.css">

<div class="etb-entete">
    <h1><?= e(t('etab.titre')) ?></h1>
    <div class="etb-entete__actions">
        <a class="bouton bouton--secondaire" href="/gestion/grilles"><?= e(t('grilles.titre')) ?></a>
        <a class="bouton" href="/gestion/etablissements/nouveau">+ <?= e(t('etab.nouveau')) ?></a>
    </div>
</div>
<p class="aide"><?= e(t('etab.liste_aide')) ?></p>

<?php if ($flash): ?>
    <p class="alerte" role="status"><?= e($flash) ?></p>
<?php endif; ?>

<?php if ($etablissements === []): ?>
    <section class="bloc">
        <p><?= e(t('etab.liste_vide')) ?></p>
        <p><a class="bouton" href="/gestion/etablissements/nouveau">+ <?= e(t('etab.nouveau')) ?></a></p>
    </section>
<?php else: ?>
    <div class="bloc etb-defile">
        <table class="tableau etb-liste">
            <thead>
            <tr>
                <th scope="col"><?= e(t('gestion.nom_colonne')) ?></th>
                <th scope="col"><?= e(t('gestion.type')) ?></th>
                <th scope="col"><?= e(t('etab.en_ce_moment')) ?></th>
                <th scope="col"><?= e(t('gestion.page_publique')) ?></th>
                <th scope="col"><?= e(t('etab.statut')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($etablissements as $etab):
                $etat = $etab['etat'];
                ?>
                <tr class="<?= $etab['actif'] ? '' : 'etb-liste__inactif' ?>">
                    <th scope="row">
                        <a class="etb-liste__nom" href="/gestion/etablissements/<?= (int) $etab['id'] ?>"><?= e($etab['nom']) ?></a>
                        <?php if (!empty($etab['ville'])): ?><span class="etb-facultatif"><?= e($etab['ville']) ?></span><?php endif; ?>
                    </th>
                    <td><?= e(t('type.' . $etab['type'])) ?></td>
                    <td>
                        <?php if ($etab['sans_horaires']): ?>
                            <span class="etb-etat etb-etat--inconnu"><?= e(t('etab.sans_horaires')) ?></span>
                        <?php elseif ($etat['ouvert']): ?>
                            <span class="etb-etat etb-etat--ouvert"><?= e(t('etab.ouvert_jusqua', ['heure' => $etat['jusqua']])) ?></span>
                        <?php else: ?>
                            <span class="etb-etat etb-etat--ferme"><?= e(t('etat.ferme')) ?></span>
                            <?php if ($etat['prochain']): ?>
                                <span class="etb-facultatif"><?= e($etat['prochain']['aujourdhui']
                                    ? t('etab.ouvre_aujourdhui', ['heure' => $etat['prochain']['heure']])
                                    : t('etab.ouvre_jour', ['jour' => t('jour_long.' . $etat['prochain']['jour']), 'heure' => $etat['prochain']['heure']])) ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($etab['actif']): ?>
                            <a class="lien etb-lien" href="/e/<?= e($etab['slug']) ?>" target="_blank" rel="noopener">/e/<?= e($etab['slug']) ?> ↗</a>
                        <?php else: ?>
                            <span class="etb-facultatif"><?= e(t('etab.vitrine_hors_ligne')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($etab['actif']): ?>
                            <span class="pastille"><?= e(t('etab.actif')) ?></span>
                        <?php else: ?>
                            <span class="pastille pastille--alerte"><?= e(t('etab.desactive')) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
