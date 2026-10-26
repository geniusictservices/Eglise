<?php
/**
 * Détail d'une note : lignes (qui a commandé, quand, statut en cuisine, quantité payée), annulations (motif, qui a
 * demandé, qui a validé, perte), ventes créées.
 * @var array $etab
 * @var array $note
 * @var list<array> $ventes
 * @var array $devise
 * @var App\Repositories\DeviseRepository $formateur
 */
use App\Repositories\CuisineRepository;

$heure = static fn (?string $d): string => $d ? date('H:i', strtotime($d)) : '—';
?>
<link rel="stylesheet" href="/assets/css/gestion-salle.css">
<p><a class="lien" href="/gestion/salle/notes?etablissement=<?= (int) $etab['id'] ?>">← <?= e(t('gsalle.notes_titre')) ?></a></p>
<h1><?= e(CuisineRepository::titreNote($note)) ?> <span class="badge badge--neutre">#<?= (int) $note['id'] ?></span></h1>
<p class="aide"><?= e($etab['nom']) ?> · <?= e(t('gsalle.note_' . $note['statut'])) ?> · <?= e(t('gsalle.ouverte_par', ['nom' => $note['serveur_nom'] ?? '—', 'heure' => date('d/m H:i', strtotime($note['ouverte_le']))])) ?><?= $note['addition_le'] ? ' · ' . e(t('gsalle.addition_le', ['heure' => $heure($note['addition_le']), 'nom' => $note['addition_par_nom'] ?? ''])) : '' ?></p>

<section class="bloc">
    <h2><?= e(t('gsalle.lignes')) ?></h2>
    <div class="defile"><table class="tableau">
        <thead><tr><th class="nombre"><?= e(t('gsalle.qte')) ?></th><th><?= e(t('gsalle.article')) ?></th><th><?= e(t('gsalle.commande_par')) ?></th><th><?= e(t('gcuisine.poste')) ?></th><th><?= e(t('gsalle.cuisine')) ?></th><th class="nombre"><?= e(t('gsalle.payee')) ?></th><th class="nombre"><?= e(t('gsalle.prix')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($note['lignes'] as $l): ?>
            <tr<?= $l['annulee_le'] ? ' class="sal-annulee"' : '' ?>>
                <td class="nombre"><?= (int) $l['quantite'] ?></td>
                <td><?= e($l['libelle']) ?><?= $l['format'] ? ' · ' . e($l['format']) : '' ?>
                    <?php if ($l['remarque']): ?><br><small><?= e($l['remarque']) ?></small><?php endif; ?>
                    <?php if ($l['allergie']): ?><br><span class="badge badge--erreur"><?= e(t('note.allergie_badge') . ' ' . $l['allergie']) ?></span><?php endif; ?>
                    <?php if ($l['annulee_le']): ?><br><strong><?= e(t('gsalle.annulee_detail', ['heure' => $heure($l['annulee_le']), 'demande' => $l['annulee_par_nom'] ?? '—', 'valide' => $l['validee_par_nom'] ?? '—', 'motif' => $l['annulation_motif'] ?? ''])) ?></strong><?= $l['perte'] ? ' · ' . e(t('gsalle.perte')) : '' ?><?php endif; ?></td>
                <td><?= e($l['ajoute_par_nom'] ?? '—') ?> · <?= $heure($l['ajoute_le']) ?></td>
                <td><?= e($l['poste_nom'] ?? '—') ?></td>
                <td><?= $l['statut'] !== 'sans' ? e(t('note.statut_' . $l['statut'])) : '—' ?><?php if ($l['envoye_le'] && $l['pret_le']): ?><br><small><?= e(t('note.minutes', ['n' => (int) round((strtotime($l['pret_le']) - strtotime($l['envoye_le'])) / 60)])) ?></small><?php endif; ?></td>
                <td class="nombre"><?= e(rtrim(rtrim(number_format((float) $l['quantite_payee'], 3, ',', ' '), '0'), ',')) ?></td>
                <td class="nombre"><?= e($formateur->formater((float) $l['prix'], $devise)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<?php if ($ventes !== []): ?>
    <section class="bloc">
        <h2><?= e(t('gsalle.ventes_note')) ?></h2>
        <ul class="sal-liste-simple">
            <?php foreach ($ventes as $v): ?>
                <li><a class="lien" href="/gestion/ventes/<?= (int) $v['id'] ?>"><?= e($v['numero']) ?></a> · <?= e(date('d/m H:i', strtotime($v['cree_le']))) ?> · <?= e(number_format((float) $v['total'], 0, ',', ' ') . ' ' . $v['devise_code']) ?><?= $v['statut'] === 'annulee' ? ' · ' . e(t('recu.annule')) : '' ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>
