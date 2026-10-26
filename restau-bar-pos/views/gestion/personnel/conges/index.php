<?php
/**
 * Personnel > Congés : demandes de congé et justifications d'absence ; le gérant accepte ou refuse.
 * @var ?string $flash
 * @var ?string $statut
 * @var list<array> $absences
 * @var list<array> $employes
 * @var ?array $porteeGerer
 * @var bool $peutGerer
 * @var array<string, string> $erreurs
 * @var ?array $saisie
 * @var int $enAttente
 */

use App\Controllers\Gestion\PersonnelAffichage as P;
use App\Core\View;
use App\Repositories\PlanningRepository;

$jeton = e(App\Core\Session::jetonCsrf());
$invalide = static fn (string $champ): string => isset($erreurs[$champ]) ? ' aria-invalid="true" aria-describedby="erreur-' . $champ . '"' : '';
$erreur = static fn (string $champ): string => isset($erreurs[$champ]) ? '<p class="champ__erreur" id="erreur-' . $champ . '">' . e($erreurs[$champ]) . '</p>' : '';
$s = $saisie ?? [];
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-dettes.css">
<link rel="stylesheet" href="/assets/css/gestion-personnel.css">

<h1><?= e(t('pers.conges_titre')) ?></h1>
<p class="aide"><?= e(t('pers.conges_aide')) ?></p>
<?php if ($flash): ?><p class="alerte" role="status"><?= e($flash) ?></p><?php endif; ?>
<?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => array_values($erreurs)]) ?>

<nav class="tre-puces per-onglets" aria-label="<?= e(t('pers.statut')) ?>">
    <a class="puce" href="/gestion/personnel/conges"<?= $statut === null ? ' aria-current="true"' : '' ?>><?= e(t('pers.toutes')) ?></a>
    <?php foreach (['en_attente', 'acceptee', 'refusee'] as $st): ?>
        <a class="puce" href="/gestion/personnel/conges?statut=<?= $st ?>"<?= $statut === $st ? ' aria-current="true"' : '' ?>><?= e(t('pers.absence_' . $st)) ?><?= $st === 'en_attente' && $enAttente > 0 ? ' <span class="det-compteur">' . $enAttente . '</span>' : '' ?></a>
    <?php endforeach; ?>
</nav>

<div class="det-colonnes per-colonnes-larges">
    <section class="bloc" aria-labelledby="per-demandes">
        <h2 id="per-demandes"><?= e(t('pers.demandes')) ?></h2>
        <?php if ($absences === []): ?>
            <p class="aide"><?= e(t('pers.aucune_demande')) ?></p>
        <?php else: ?>
            <ul class="per-demandes">
                <?php foreach ($absences as $a): ?>
                    <li class="per-demande" data-absence="<?= (int) $a['id'] ?>">
                        <p class="per-demande__tete"><strong><?= e($a['employe_nom']) ?></strong>
                            <span class="badge badge--glace"><?= e(t('pers.type_' . $a['type'])) ?></span>
                            <span class="badge badge--<?= ['en_attente' => 'braise', 'acceptee' => 'succes', 'refusee' => 'erreur'][$a['statut']] ?>"><?= e(t('pers.absence_' . $a['statut'])) ?></span></p>
                        <p><?= e(t('pers.du_au', ['du' => P::jour($a['debut']), 'au' => P::jour($a['fin'])])) ?> · <?= e(t('pers.n_jours', ['n' => (int) round((strtotime($a['fin']) - strtotime($a['debut'])) / 86400) + 1])) ?></p>
                        <?php if ($a['motif']): ?><p class="det-texte-long aide"><?= e($a['motif']) ?></p><?php endif; ?>
                        <p class="tre-sous-texte"><?= e(t('pers.demandee_par', ['nom' => $a['demande_par_nom'] ?? '?', 'date' => P::jour($a['demande_le'])])) ?>
                            <?php if ($a['decide_par_nom']): ?> · <?= e(t('pers.decidee_par', ['nom' => $a['decide_par_nom'], 'date' => P::jour($a['decide_le'])])) ?><?= $a['decision_motif'] ? ' : « ' . e($a['decision_motif']) . ' »' : '' ?><?php endif; ?></p>
                        <?php if ($a['statut'] === 'en_attente' && $peutGerer): ?>
                            <form method="post" action="/gestion/personnel/conges/<?= (int) $a['id'] ?>/decider" class="per-ligne-formulaire" novalidate>
                                <input type="hidden" name="csrf" value="<?= $jeton ?>">
                                <div class="champ">
                                    <label for="motif-<?= (int) $a['id'] ?>"><?= e(t('pers.champ_motif_decision')) ?></label>
                                    <input id="motif-<?= (int) $a['id'] ?>" name="motif" maxlength="255"<?= $invalide('decision-' . $a['id']) ?>>
                                    <?= $erreur('decision-' . $a['id']) ?>
                                </div>
                                <div class="per-boutons">
                                    <button class="bouton bouton--petit" type="submit" name="decision" value="accepter"><?= e(t('pers.accepter')) ?></button>
                                    <button class="bouton bouton--secondaire bouton--petit" type="submit" name="decision" value="refuser"><?= e(t('pers.refuser')) ?></button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="bloc" aria-labelledby="per-nouvelle-demande">
        <h2 id="per-nouvelle-demande"><?= e(t('pers.nouvelle_demande')) ?></h2>
        <form method="post" action="/gestion/personnel/conges" class="tre-formulaire" novalidate>
            <input type="hidden" name="csrf" value="<?= $jeton ?>">
            <div class="champ">
                <label for="employe_id"><?= e(t('pers.col_employe')) ?></label>
                <select id="employe_id" name="employe_id"<?= $invalide('employe_id') ?>>
                    <option value=""><?= e(t('pers.choisir_employe')) ?></option>
                    <?php foreach ($employes as $e): ?><option value="<?= (int) $e['id'] ?>" <?= (string) ($s['employe_id'] ?? '') === (string) $e['id'] ? 'selected' : '' ?>><?= e($e['nom']) ?></option><?php endforeach; ?>
                </select>
                <?= $erreur('employe_id') ?>
            </div>
            <div class="champ">
                <label for="type"><?= e(t('pers.champ_type_absence')) ?></label>
                <select id="type" name="type"<?= $invalide('type') ?>>
                    <?php foreach (PlanningRepository::TYPES_ABSENCE as $ty): ?><option value="<?= $ty ?>" <?= ($s['type'] ?? '') === $ty ? 'selected' : '' ?>><?= e(t('pers.type_' . $ty)) ?></option><?php endforeach; ?>
                </select>
                <?= $erreur('type') ?>
            </div>
            <div class="tre-paire">
                <div class="champ">
                    <label for="du"><?= e(t('tresorerie.du')) ?></label>
                    <input id="du" name="du" type="date" value="<?= e((string) ($s['du'] ?? date('Y-m-d'))) ?>"<?= $invalide('du') ?>>
                    <?= $erreur('du') ?>
                </div>
                <div class="champ">
                    <label for="au"><?= e(t('tresorerie.au')) ?></label>
                    <input id="au" name="au" type="date" value="<?= e((string) ($s['au'] ?? date('Y-m-d'))) ?>"<?= $invalide('au') ?>>
                    <?= $erreur('au') ?>
                </div>
            </div>
            <div class="champ">
                <label for="motif"><?= e(t('pers.champ_motif')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
                <textarea id="motif" name="motif" rows="3" maxlength="500"<?= $invalide('motif') ?>><?= e((string) ($s['motif'] ?? '')) ?></textarea>
                <?= $erreur('motif') ?>
            </div>
            <div class="tre-actions"><button class="bouton" type="submit"><?= e(t('pers.enregistrer_demande')) ?></button></div>
        </form>
    </section>
</div>
