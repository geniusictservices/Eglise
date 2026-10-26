<?php
/**
 * Personnel > Avances : liste (à déduire, déduites, annulées) et versement d'une avance depuis la trésorerie.
 * @var ?string $flash
 * @var ?string $statut
 * @var list<array> $avances
 * @var list<array> $employes employés à qui la personne peut verser une avance
 * @var array $valeurs employe_id, date, motif
 * @var list<array> $lignes
 * @var array<int, array> $comptes
 * @var array<int, array> $devises
 * @var ?array $reference
 * @var array<string, string> $erreurs
 * @var list<string> $avertissements
 * @var bool $peutPayer
 * @var ?array $porteePayer
 */

use App\Controllers\Gestion\PersonnelAffichage as P;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$invalide = static fn (string $champ): string => isset($erreurs[$champ]) ? ' aria-invalid="true" aria-describedby="erreur-' . $champ . '"' : '';
$erreur = static fn (string $champ): string => isset($erreurs[$champ]) ? '<p class="champ__erreur" id="erreur-' . $champ . '">' . e($erreurs[$champ]) . '</p>' : '';
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-dettes.css">
<link rel="stylesheet" href="/assets/css/gestion-personnel.css">

<h1><?= e(t('pers.avances_titre')) ?></h1>
<p class="aide"><?= e(t('pers.avances_aide')) ?></p>
<?php if ($flash): ?><p class="alerte" role="status"><?= e($flash) ?></p><?php endif; ?>
<?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => array_values($erreurs)]) ?>

<nav class="tre-puces per-onglets" aria-label="<?= e(t('pers.statut')) ?>">
    <a class="puce" href="/gestion/personnel/avances"<?= $statut === null ? ' aria-current="true"' : '' ?>><?= e(t('pers.toutes')) ?></a>
    <?php foreach (['a_deduire', 'deduite', 'annulee'] as $st): ?>
        <a class="puce" href="/gestion/personnel/avances?statut=<?= $st ?>"<?= $statut === $st ? ' aria-current="true"' : '' ?>><?= e(t('pers.avance_' . $st)) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($peutPayer && $employes !== []): ?>
    <section class="bloc" id="nouvelle" aria-labelledby="per-nouvelle-avance">
        <h2 id="per-nouvelle-avance"><?= e(t('pers.nouvelle_avance')) ?></h2>
        <?php ob_start(); ?>
        <div class="tre-paire">
            <div class="champ">
                <label for="employe_id"><?= e(t('pers.col_employe')) ?></label>
                <select id="employe_id" name="employe_id"<?= $invalide('employe_id') ?> data-recharger-comptes>
                    <option value=""><?= e(t('pers.choisir_employe')) ?></option>
                    <?php foreach ($employes as $e): ?><option value="<?= (int) $e['id'] ?>" <?= (int) ($valeurs['employe_id'] ?? 0) === (int) $e['id'] ? 'selected' : '' ?>><?= e($e['nom'] . ' · ' . $e['etablissement_nom']) ?></option><?php endforeach; ?>
                </select>
                <?= $erreur('employe_id') ?>
            </div>
            <div class="champ">
                <label for="date"><?= e(t('tresorerie.champ_date')) ?></label>
                <input type="datetime-local" id="date" name="date" value="<?= e((string) $valeurs['date']) ?>"<?= $invalide('date') ?>>
                <?= $erreur('date') ?>
            </div>
        </div>
        <div class="champ">
            <label for="motif"><?= e(t('pers.champ_motif')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
            <input id="motif" name="motif" maxlength="255" value="<?= e((string) $valeurs['motif']) ?>" placeholder="<?= e(t('pers.champ_motif_avance_exemple')) ?>"<?= $invalide('motif') ?>>
            <?= $erreur('motif') ?>
        </div>
        <p class="aide"><?= e(t('pers.avance_paiement_aide')) ?></p>
        <?php $champsAvant = (string) ob_get_clean(); ?>
        <?= View::rendre('gestion/personnel/paie/_paiement-formulaire', [
            'action' => '/gestion/personnel/avances', 'lignes' => $lignes, 'comptes' => $comptes, 'devises' => $devises, 'reference' => $reference,
            'erreurs' => $erreurs, 'avertissements' => $avertissements, 'boutonTexte' => t('pers.verser_avance'), 'champsAvant' => $champsAvant,
        ]) ?>
    </section>
<?php endif; ?>

<section class="bloc" aria-labelledby="per-avances-liste">
    <h2 id="per-avances-liste"><?= e(t('pers.n_avances', ['n' => count($avances)])) ?></h2>
    <?php if ($avances === []): ?>
        <p class="aide"><?= e(t('pers.aucune_avance')) ?></p>
    <?php else: ?>
        <div class="defile">
            <table class="tableau per-tableau">
                <thead><tr><th scope="col"><?= e(t('tresorerie.champ_date')) ?></th><th scope="col"><?= e(t('pers.col_employe')) ?></th><th scope="col" class="nombre"><?= e(t('pers.montant')) ?></th><th scope="col"><?= e(t('pers.statut')) ?></th><th scope="col"><span class="visuellement-cache"><?= e(t('pers.actions')) ?></span></th></tr></thead>
                <tbody>
                    <?php foreach ($avances as $a): $m = []; foreach ($a['lignes'] as $l) { $m[(int) $l['devise_id']] = ($m[(int) $l['devise_id']] ?? 0) + (float) $l['montant']; } ?>
                        <tr data-avance="<?= (int) $a['id'] ?>">
                            <td><?= e(A::dateHeure($a['date_avance'])) ?><span class="tre-sous-texte"><?= e((string) $a['motif']) ?></span></td>
                            <td><a class="lien" href="/gestion/personnel/employes/<?= (int) $a['employe_id'] ?>"><?= e($a['employe_nom']) ?></a><span class="tre-sous-texte"><?= e($a['etablissement_nom']) ?></span></td>
                            <td class="nombre montant"><strong><?= e(P::montants($m)) ?></strong>
                                <?php foreach ($a['lignes'] as $l): ?><span class="tre-sous-texte"><?= e($l['compte_nom'] . ' · ' . P::montant($l['montant'], (int) $l['devise_id'])) ?></span><?php endforeach; ?></td>
                            <td><span class="badge badge--<?= ['a_deduire' => 'braise', 'deduite' => 'succes', 'annulee' => 'neutre'][$a['statut']] ?>"><?= e(t('pers.avance_' . $a['statut'])) ?></span>
                                <?php if ($a['bulletin_id']): ?><span class="tre-sous-texte"><a class="lien" href="/gestion/personnel/paie/<?= (int) $a['bulletin_id'] ?>"><?= e(P::periode($a['bulletin_debut'], $a['bulletin_fin'])) ?></a></span><?php endif; ?></td>
                            <td>
                                <?php if ($a['statut'] === 'a_deduire' && $peutPayer && ($porteePayer === null || in_array((int) $a['etablissement_id'], $porteePayer, true))): ?>
                                    <details class="per-annuler-paiement">
                                        <summary class="lien"><?= e(t('pers.annuler')) ?></summary>
                                        <form method="post" action="/gestion/personnel/avances/<?= (int) $a['id'] ?>/annuler" class="per-ligne-formulaire">
                                            <input type="hidden" name="csrf" value="<?= $jeton ?>">
                                            <div class="champ"><label for="motif-a-<?= (int) $a['id'] ?>"><?= e(t('pers.champ_motif')) ?></label><input id="motif-a-<?= (int) $a['id'] ?>" name="motif" maxlength="255" required></div>
                                            <button class="bouton bouton--danger bouton--petit" type="submit"><?= e(t('pers.annuler_avance')) ?></button>
                                        </form>
                                    </details>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
