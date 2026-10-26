<?php
/**
 * Fiche d'un remboursement : client, montant et devise, compte, qui l'a encaissé, répartition sur les dettes
 * (dans la devise de chaque dette), mouvements de trésorerie ; annulation (dettes.gerer, motif obligatoire).
 * @var ?string $flash
 * @var array $fiche remboursement, affectations, mouvements
 * @var array<int, array> $devises
 * @var ?array $reference
 * @var bool $peutAnnuler
 * @var bool $voirComptes
 * @var bool $voirClients
 * @var bool $voirVentes
 * @var array<string, string> $erreurs
 * @var string $motif
 */

use App\Controllers\Gestion\DetteAffichage as D;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$r = $fiche['remboursement'];
$annule = $r['statut'] === 'annule';
$jeton = e(App\Core\Session::jetonCsrf());
$montant = D::montant($r['montant'], $r['devise_id'], $devises);
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-dettes.css">

<p class="tre-retour"><a class="lien" href="/gestion/remboursements">← <?= e(t('gremb.titre')) ?></a></p>

<div class="tre-entete det-entete">
    <div>
        <p class="surtitre"><?= e($r['etablissement_nom']) ?></p>
        <h1>
            <?= e(t('gremb.fiche_titre', ['id' => $r['id']])) ?>
            <span class="badge <?= $annule ? 'badge--erreur' : 'badge--succes' ?>" data-statut="<?= e($r['statut']) ?>"><?= e(t($annule ? 'gremb.badge_annule' : 'gremb.badge_valide')) ?></span>
        </h1>
        <p class="aide"><?= e(A::dateHeure($r['cree_le'])) ?></p>
    </div>
</div>

<?php if ($flash): ?>
    <p class="alerte" role="status"><?= e($flash) ?></p>
<?php endif; ?>
<?php if (isset($erreurs['annulation'])): ?>
    <p class="alerte alerte--erreur" role="alert"><?= e($erreurs['annulation']) ?></p>
<?php endif; ?>

<?php if ($annule): ?>
    <section class="det-annule" aria-labelledby="det-annule-titre">
        <h2 id="det-annule-titre"><?= e(t('gremb.annule_titre')) ?></h2>
        <dl class="det-infos">
            <div><dt><?= e(t('gventes.annulee_le')) ?></dt><dd><?= e(A::dateHeure($r['annule_le'])) ?></dd></div>
            <div><dt><?= e(t('gventes.annulee_par')) ?></dt><dd><?= e($r['annule_par_nom'] ?? '—') ?></dd></div>
            <div><dt><?= e(t('gventes.motif')) ?></dt><dd class="det-texte-long"><?= e((string) $r['motif_annulation']) ?></dd></div>
        </dl>
        <p class="aide"><?= e(t('gremb.annule_aide')) ?></p>
    </section>
<?php endif; ?>

<section class="tre-totaux" aria-labelledby="det-chiffres-titre">
    <h2 class="visuellement-cache" id="det-chiffres-titre"><?= e(t('gremb.le_remboursement')) ?></h2>
    <div class="chiffres det-chiffres">
        <div class="chiffre">
            <span class="chiffre__valeur<?= $annule ? ' det-barre' : '' ?>" data-montant><?= e($montant) ?></span>
            <span class="chiffre__libelle"><?= e(t('gremb.recu')) ?></span>
        </div>
        <?php if ($reference && $r['contre_valeur'] !== null && (int) $r['devise_id'] !== (int) $reference['id']): ?>
            <div class="chiffre">
                <span class="chiffre__valeur">≈ <?= e(A::montant($r['contre_valeur'], $reference)) ?></span>
                <span class="chiffre__libelle"><?= e(t('gremb.equivalent', ['code' => $reference['code']])) ?></span>
            </div>
        <?php endif; ?>
    </div>
</section>

<div class="det-colonnes">
    <section class="bloc" aria-labelledby="det-infos-titre">
        <h2 id="det-infos-titre"><?= e(t('gremb.le_remboursement')) ?></h2>
        <dl class="det-infos">
            <div><dt><?= e(t('gremb.client')) ?></dt><dd>
                <?php if ($voirClients): ?><a class="lien" href="/gestion/clients/<?= (int) $r['client_id'] ?>"><?= e($r['client_nom']) ?></a><?php else: ?><?= e($r['client_nom']) ?><?php endif; ?>
            </dd></div>
            <div><dt><?= e(t('gremb.col_compte')) ?></dt><dd><span class="det-compte"><?= View::rendre('gestion/tresorerie/_icone', ['type' => $r['compte_type']]) ?>
                <?php if ($voirComptes): ?><a class="lien" href="/gestion/tresorerie/comptes/<?= (int) $r['compte_id'] ?>"><?= e($r['compte_nom']) ?></a><?php else: ?><span><?= e($r['compte_nom']) ?></span><?php endif; ?>
                </span> <span class="tre-sous-texte"><?= e(t('tresorerie.type_' . $r['moyen'])) ?></span></dd></div>
            <div><dt><?= e(t('gremb.encaisse_par')) ?></dt><dd><?= e($r['utilisateur_nom'] ?? t('gventes.vendeur_inconnu')) ?><?= $r['appareil_nom'] ? ' · ' . e($r['appareil_nom']) : '' ?></dd></div>
            <?php if ($r['taux'] !== null && $reference && (int) $r['devise_id'] !== (int) $reference['id']): ?>
                <div><dt><?= e(t('gremb.taux')) ?></dt><dd class="montant"><?= e(A::taux($reference['code'], $devises[(int) $r['devise_id']]['code'] ?? '', (float) $r['taux'])) ?></dd></div>
            <?php endif; ?>
        </dl>
    </section>

    <section class="bloc" aria-labelledby="det-mouv-titre">
        <h2 id="det-mouv-titre"><?= e(t('gventes.mouvements')) ?></h2>
        <?php if ($fiche['mouvements'] === []): ?>
            <p class="aide">—</p>
        <?php else: ?>
            <ul class="det-lignes">
                <?php foreach ($fiche['mouvements'] as $m): $entree = $m['sens'] === 'entree'; ?>
                    <li data-mouvement="<?= (int) $m['id'] ?>">
                        <span><?= e($m['compte_nom']) ?><span class="tre-sous-texte"><?= e(A::dateHeure($m['date_operation'])) ?> · <?= e((string) $m['libelle']) ?></span></span>
                        <strong class="montant<?= $entree ? ' tre-entree' : ' tre-negatif' ?>"><?= $entree ? '+' : '−' ?><?= e(D::montant($m['montant'], $m['devise_id'], $devises)) ?></strong>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<section class="bloc" aria-labelledby="det-affect-titre">
    <h2 id="det-affect-titre"><?= e(t('gremb.repartition')) ?></h2>
    <p class="aide"><?= e(t('gremb.repartition_aide')) ?></p>
    <ul class="det-lignes">
        <?php foreach ($fiche['affectations'] as $a): ?>
            <li data-affectation="<?= (int) $a['dette_id'] ?>">
                <span>
                    <a class="det-lien-fort" href="/gestion/dettes/<?= (int) $a['dette_id'] ?>"><?= e(t('gdettes.fiche_titre', ['id' => $a['dette_id']])) ?></a>
                    <span class="badge <?= D::badgeDette($a['statut']) ?>"><?= e(t('gdettes.statut_' . $a['statut'])) ?></span>
                    <span class="tre-sous-texte"><?= e(A::jour($a['dette_le'])) ?> · <?= e($a['etablissement_nom']) ?>
                        <?php if ($a['vente_id']): ?> · <?php if ($voirVentes): ?><a class="lien" href="/gestion/ventes/<?= (int) $a['vente_id'] ?>"><?= e((string) $a['vente_numero']) ?></a><?php else: ?><?= e((string) $a['vente_numero']) ?><?php endif; ?><?php endif; ?>
                        · <?= e(t('gremb.sur', ['montant' => D::montant($a['dette_montant'], $a['devise_id'], $devises)])) ?>
                        <?php if ($a['statut'] === 'ouverte'): ?> · <?= e(t('gremb.reste_aujourdhui', ['montant' => D::montant($a['reste'], $a['devise_id'], $devises)])) ?><?php endif; ?></span>
                </span>
                <strong class="montant"><?= e(D::montant($a['montant'], $a['devise_id'], $devises)) ?></strong>
            </li>
        <?php endforeach; ?>
    </ul>
</section>

<?php if ($peutAnnuler): ?>
    <section class="bloc det-danger" aria-labelledby="det-annuler-titre" id="annuler">
        <h2 id="det-annuler-titre"><?= e(t('gremb.annuler_titre')) ?></h2>
        <p class="aide"><?= e(t('gremb.annuler_aide')) ?></p>
        <form method="post" action="/gestion/remboursements/<?= (int) $r['id'] ?>/annuler" class="det-decision__form" novalidate>
            <input type="hidden" name="csrf" value="<?= $jeton ?>">
            <div class="champ">
                <label for="motif"><?= e(t('gventes.motif')) ?></label>
                <textarea id="motif" name="motif" rows="3" maxlength="255" required placeholder="<?= e(t('gremb.motif_exemple')) ?>"
                    <?= isset($erreurs['motif']) ? ' aria-invalid="true" aria-describedby="erreur-motif"' : '' ?>><?= e($motif) ?></textarea>
                <?php if (isset($erreurs['motif'])): ?><p class="champ__erreur" id="erreur-motif"><?= e($erreurs['motif']) ?></p><?php endif; ?>
            </div>
            <label class="coche det-coche">
                <input type="checkbox" name="confirme" value="1"<?= isset($erreurs['confirme']) ? ' aria-invalid="true" aria-describedby="erreur-confirme"' : '' ?>>
                <span><?= e(t('gremb.confirmer', ['montant' => $montant, 'nom' => $r['client_nom']])) ?></span>
            </label>
            <?php if (isset($erreurs['confirme'])): ?><p class="champ__erreur" id="erreur-confirme"><?= e($erreurs['confirme']) ?></p><?php endif; ?>
            <div class="tre-actions"><button class="bouton bouton--danger" type="submit"><?= e(t('gremb.annuler_bouton')) ?></button></div>
        </form>
    </section>
<?php endif; ?>
