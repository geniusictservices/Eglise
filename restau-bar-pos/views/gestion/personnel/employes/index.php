<?php
/**
 * Personnel > Employés : liste filtrée (établissement, recherche, actifs), présence en ce moment, salaire (personnel.paie).
 * @var ?string $flash
 * @var list<array> $employes
 * @var array<int, array> $presents
 * @var array $etablissements
 * @var ?array $etab
 * @var string $statut
 * @var string $q
 * @var bool $peutGerer
 * @var bool $voirSalaires
 * @var ?array $portee portée de personnel.paie
 */

use App\Controllers\Gestion\PersonnelAffichage as P;
use App\Core\View;
use App\Repositories\PersonnelRepository;
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-personnel.css">

<div class="tre-entete">
    <div>
        <h1><?= e(t('pers.employes_titre')) ?></h1>
        <p class="aide"><?= e(t('pers.employes_aide')) ?></p>
    </div>
    <?php if ($peutGerer): ?>
        <div class="tre-entete__actions"><a class="bouton" href="/gestion/personnel/employes/nouveau">+ <?= e(t('pers.nouvel_employe')) ?></a></div>
    <?php endif; ?>
</div>

<?php if ($flash): ?><p class="alerte" role="status"><?= e($flash) ?></p><?php endif; ?>

<section class="bloc per-filtres" aria-label="<?= e(t('pers.filtres')) ?>">
    <?= View::rendre('gestion/personnel/_etablissements', ['etablissements' => $etablissements, 'etab' => $etab, 'tous' => true]) ?>
    <form method="get" action="/gestion/personnel/employes" class="per-filtres__formulaire">
        <?php if ($etab): ?><input type="hidden" name="etablissement" value="<?= (int) $etab['id'] ?>"><?php endif; ?>
        <div class="champ">
            <label for="q"><?= e(t('pers.rechercher')) ?></label>
            <input id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="<?= e(t('pers.rechercher_exemple')) ?>">
        </div>
        <div class="champ">
            <label for="statut"><?= e(t('pers.statut')) ?></label>
            <select id="statut" name="statut">
                <?php foreach (['actifs', 'inactifs', 'tous'] as $s): ?>
                    <option value="<?= $s ?>" <?= $statut === $s ? 'selected' : '' ?>><?= e(t('pers.statut_' . $s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="bouton bouton--secondaire" type="submit"><?= e(t('tresorerie.afficher')) ?></button>
    </form>
</section>

<?php if ($employes === []): ?>
    <p class="bloc aide"><?= e(t('pers.aucun_employe')) ?></p>
<?php else: ?>
    <section class="bloc" aria-labelledby="per-liste-titre">
        <h2 id="per-liste-titre"><?= e(t(count($employes) > 1 ? 'pers.n_employes' : 'pers.un_employe', ['n' => count($employes)])) ?></h2>
        <ul class="per-cartes">
            <?php foreach ($employes as $e): $present = $presents[(int) $e['id']] ?? null; ?>
                <li class="per-carte<?= $e['actif'] ? '' : ' per-carte--inactif' ?>" data-employe="<?= (int) $e['id'] ?>">
                    <a class="per-carte__lien" href="/gestion/personnel/employes/<?= (int) $e['id'] ?>">
                        <?= View::rendre('partials/_avatar', ['nom' => $e['nom'], 'photo' => $e['avatar'] ?? null, 'classe' => '']) ?>
                        <span class="per-carte__texte">
                            <strong class="per-carte__nom"><?= e($e['nom']) ?></strong>
                            <span class="per-carte__poste"><?= e(trim(($e['poste'] ?? '') . ' · ' . $e['etablissement_nom'], ' ·')) ?></span>
                            <span class="per-badges">
                                <span class="badge badge--neutre"><?= e(t('pers.contrat_' . $e['contrat'])) ?></span>
                                <?php if (!$e['actif']): ?><span class="badge badge--erreur"><?= e(t('pers.inactif')) ?></span>
                                <?php elseif ($present): ?><span class="badge badge--succes"><?= e(t('pers.present_depuis', ['heure' => P::heure($present['arrivee'])])) ?></span><?php endif; ?>
                                <?php if ($e['utilisateur_id'] === null): ?><span class="badge badge--glace"><?= e(t('pers.sans_compte')) ?></span><?php endif; ?>
                            </span>
                            <?php if ($voirSalaires && PersonnelRepository::dansPortee($e, $portee)): ?>
                                <span class="per-carte__salaire montant"><?= e(P::montants($e['salaires'], t('pers.salaire_non_fixe'))) ?> <span class="tre-sous-texte"><?= e(t('pers.par_' . $e['periode'])) ?></span></span>
                            <?php endif; ?>
                        </span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>
