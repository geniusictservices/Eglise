<?php
/**
 * Fiche d'un client : coordonnées et préférences, encours par devise, dettes, remboursements, messages envoyés.
 * Un client « personnel » est lié à un membre du personnel (dettes mises à sa charge) : lecture seule.
 * @var ?string $flash
 * @var array $fiche client, dettes, remboursements, encours, retard, totaux, messages, origines
 * @var array<int, array> $devises
 * @var bool $peutModifier
 * @var bool $voirDettes
 * @var bool $peutRembourser
 * @var bool $voirVentes
 * @var bool $voirUtilisateurs
 */

use App\Controllers\Gestion\DetteAffichage as D;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$client = $fiche['client'];
$personnel = $client['type'] === 'personnel';
$encours = D::ordonner($fiche['encours'], $devises);
$telephone = $personnel ? $client['utilisateur_telephone'] : $client['telephone'];
$oui = t('gclients.oui');
$non = t('gclients.non');
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-dettes.css">

<p class="tre-retour"><a class="lien" href="/gestion/clients">← <?= e(t('gclients.titre')) ?></a></p>

<div class="tre-entete det-entete">
    <div>
        <p class="surtitre"><?= e(t('gclients.type_' . $client['type'])) ?></p>
        <h1>
            <?= e($client['nom']) ?>
            <?php if ($client['bloque']): ?><span class="badge badge--erreur"><?= e(t('gclients.badge_bloque')) ?></span><?php endif; ?>
            <?php if ($fiche['retard']): ?><span class="badge badge--erreur"><?= e(t('gclients.retard_depuis', ['date' => A::jour($fiche['retard'])])) ?></span><?php endif; ?>
        </h1>
        <?php if ($telephone): ?><p class="aide montant"><?= e(afficherTelephone($telephone)) ?></p><?php endif; ?>
    </div>
    <div class="tre-entete__actions">
        <?php if ($peutRembourser && $encours !== []): ?>
            <a class="bouton" href="/gestion/remboursements/nouveau?client=<?= (int) $client['id'] ?>"><?= e(t('gdettes.encaisser')) ?></a>
        <?php endif; ?>
        <?php if ($peutModifier): ?>
            <a class="bouton bouton--secondaire" href="/gestion/clients/<?= (int) $client['id'] ?>/modifier"><?= e(t('gclients.modifier')) ?></a>
        <?php endif; ?>
    </div>
</div>

<?php if ($flash): ?>
    <p class="alerte" role="status"><?= e($flash) ?></p>
<?php endif; ?>

<?php if ($personnel): ?>
    <p class="alerte tre-note det-note" data-personnel>
        <?= e(t('gclients.personnel_texte', ['nom' => (string) $client['utilisateur_nom']])) ?>
        <?php if ($voirUtilisateurs && $client['utilisateur_id']): ?>
            <a class="lien" href="/gestion/utilisateurs/<?= (int) $client['utilisateur_id'] ?>"><?= e(t('gclients.voir_utilisateur')) ?></a>
        <?php endif; ?>
    </p>
<?php endif; ?>

<section class="tre-totaux" aria-labelledby="det-encours-titre">
    <h2 class="visuellement-cache" id="det-encours-titre"><?= e(t('gclients.encours')) ?></h2>
    <div class="chiffres det-chiffres">
        <?php if ($encours === []): ?>
            <div class="chiffre">
                <span class="chiffre__valeur" data-encours="aucun"><?= e(t('gclients.rien_du')) ?></span>
                <span class="chiffre__libelle"><?= e(t('gclients.encours')) ?></span>
            </div>
        <?php endif; ?>
        <?php foreach ($encours as $deviseId => $reste): ?>
            <div class="chiffre">
                <span class="chiffre__valeur" data-encours="<?= e($devises[$deviseId]['code'] ?? '') ?>"><?= e(D::montant($reste, $deviseId, $devises)) ?></span>
                <span class="chiffre__libelle"><?= e(t('gclients.encours_en', ['code' => $devises[$deviseId]['code'] ?? ''])) ?></span>
            </div>
        <?php endforeach; ?>
        <div class="chiffre">
            <span class="chiffre__valeur det-chiffre--texte"><?= e($client['plafond'] !== null ? D::montant($client['plafond'], $client['plafond_devise_id'], $devises) : t('gclients.sans_plafond')) ?></span>
            <span class="chiffre__libelle"><?= e(t('gclients.plafond')) ?></span>
        </div>
    </div>
</section>

<div class="det-colonnes">
    <section class="bloc" aria-labelledby="det-infos-titre">
        <h2 id="det-infos-titre"><?= e(t('gclients.coordonnees')) ?></h2>
        <dl class="det-infos">
            <div><dt><?= e(t('gclients.champ_telephone')) ?></dt><dd class="montant"><?= e($telephone ? afficherTelephone($telephone) : t('gclients.sans_telephone')) ?></dd></div>
            <?php if (!$personnel): ?>
                <div><dt><?= e(t('gclients.champ_email')) ?></dt><dd><?= e($client['email'] ?: '—') ?></dd></div>
                <div><dt><?= e(t('gclients.champ_anniversaire')) ?></dt><dd><?= e($client['anniversaire'] ? A::jour($client['anniversaire']) : '—') ?></dd></div>
            <?php endif; ?>
            <div><dt><?= e(t('gclients.champ_langue')) ?></dt><dd><?= e(t('gclients.langue_' . $client['langue'])) ?></dd></div>
            <?php if (!$personnel): ?>
                <div><dt><?= e(t('gclients.champ_canal')) ?></dt><dd><?= e(t('messagerie.canal_' . $client['canal'])) ?></dd></div>
                <div><dt><?= e(t('gclients.champ_relances')) ?></dt><dd><?= e($client['relances'] ? t('gclients.relances_oui') : t('gclients.relances_non')) ?></dd></div>
            <?php endif; ?>
            <div><dt><?= e(t('gclients.champ_bloque')) ?></dt><dd><?= e($client['bloque'] ? $oui : $non) ?></dd></div>
            <?php if ($client['notes']): ?>
                <div><dt><?= e(t('gclients.champ_notes')) ?></dt><dd class="det-texte-long"><?= e($client['notes']) ?></dd></div>
            <?php endif; ?>
            <div><dt><?= e(t('gclients.cree_le')) ?></dt><dd><?= e(A::dateHeure($client['cree_le'])) ?><?= $client['cree_par_nom'] ? ' · ' . e(t('gclients.par', ['nom' => $client['cree_par_nom']])) : '' ?></dd></div>
        </dl>
    </section>

    <section class="bloc" aria-labelledby="det-historique-titre">
        <h2 id="det-historique-titre"><?= e(t('gclients.historique')) ?></h2>
        <dl class="det-infos">
            <div><dt><?= e(t('gclients.total_credite')) ?></dt><dd class="montant"><?= e(D::montants($fiche['totaux']['credite'], $devises) ?: '—') ?></dd></div>
            <div><dt><?= e(t('gclients.total_rembourse')) ?></dt><dd class="montant"><?= e(D::montants($fiche['totaux']['rembourse'], $devises) ?: '—') ?></dd></div>
            <div><dt><?= e(t('gclients.nb_dettes')) ?></dt><dd><?= count($fiche['dettes']) ?></dd></div>
        </dl>
        <?php if ($voirDettes && $fiche['dettes'] !== []): ?>
            <p class="det-pied"><a class="lien" href="/gestion/dettes?client=<?= (int) $client['id'] ?>&amp;statut=toutes"><?= e(t('gclients.voir_dettes')) ?></a></p>
        <?php endif; ?>
        <?php
        // Fidélité (module Nuit) : solde de points et derniers mouvements
        $fidelite = App\Repositories\FideliteRepository::disponible() ? new App\Repositories\FideliteRepository() : null;
        $fidMouvements = $fidelite ? $fidelite->historique((int) $client['id'], 8) : [];
        ?>
        <?php if ($fidMouvements !== []): ?>
            <h3 id="det-fidelite"><?= e(t('gnuit.fidelite_titre')) ?></h3>
            <dl class="det-infos" data-bloc="fidelite">
                <div><dt><?= e(t('gnuit.points')) ?></dt><dd data-points><?= (int) $fidelite->solde((int) $client['id']) ?></dd></div>
                <?php foreach ($fidMouvements as $m): ?>
                    <div><dt><?= e(A::dateHeure($m['cree_le'])) ?></dt><dd><?= e(t('gnuit.fid_' . $m['type'])) ?> · <?= ((int) $m['points'] > 0 ? '+' : '') . (int) $m['points'] ?><?= $m['numero'] ? ' · ' . e($m['numero']) : '' ?><?= ($m['recompense_' . App\Core\I18n::langue()] ?? null) ? ' · ' . e($m['recompense_' . App\Core\I18n::langue()]) : '' ?></dd></div>
                <?php endforeach; ?>
            </dl>
        <?php endif; ?>
    </section>
</div>

<section class="bloc" aria-labelledby="det-dettes-titre" id="dettes">
    <h2 id="det-dettes-titre"><?= e(t('gclients.ses_dettes')) ?></h2>
    <?php if ($fiche['dettes'] === []): ?>
        <p class="aide"><?= e(t('gclients.aucune_dette')) ?></p>
    <?php else: ?>
        <?= View::rendre('gestion/dettes/_liste', ['dettes' => $fiche['dettes'], 'devises' => $devises, 'avecClient' => false, 'liens' => $voirDettes]) ?>
        <?php if ($personnel && $fiche['origines'] !== []): ?>
            <ul class="det-origines">
                <?php foreach ($fiche['origines'] as $detteId => $o): ?>
                    <li><?= e(t('gclients.origine', ['id' => $detteId, 'client' => $o['client_nom']])) ?>
                        <?php if ($voirDettes): ?><a class="lien" href="/gestion/dettes/<?= (int) $o['origine_id'] ?>"><?= e(t('gdettes.fiche_titre', ['id' => $o['origine_id']])) ?></a><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="bloc" aria-labelledby="det-remb-titre" id="remboursements">
    <h2 id="det-remb-titre"><?= e(t('gclients.ses_remboursements')) ?></h2>
    <?php if ($fiche['remboursements'] === []): ?>
        <p class="aide"><?= e(t('gclients.aucun_remboursement')) ?></p>
    <?php else: ?>
        <ul class="det-lignes">
            <?php foreach ($fiche['remboursements'] as $r): $annule = $r['statut'] === 'annule'; ?>
                <li class="<?= $annule ? 'det-ligne--close' : '' ?>" data-remboursement="<?= (int) $r['id'] ?>">
                    <span>
                        <?php if ($voirDettes): ?>
                            <a class="det-lien-fort" href="/gestion/remboursements/<?= (int) $r['id'] ?>"><?= e(t('gremb.fiche_titre', ['id' => $r['id']])) ?></a>
                        <?php else: ?>
                            <strong><?= e(t('gremb.fiche_titre', ['id' => $r['id']])) ?></strong>
                        <?php endif; ?>
                        <?php if ($annule): ?><span class="badge badge--erreur"><?= e(t('gremb.badge_annule')) ?></span><?php endif; ?>
                        <span class="tre-sous-texte"><?= e(A::dateHeure($r['cree_le'])) ?> · <?= e($r['compte_nom']) ?> · <?= e($r['etablissement_nom']) ?><?= $r['utilisateur_nom'] ? ' · ' . e($r['utilisateur_nom']) : '' ?></span>
                    </span>
                    <strong class="montant<?= $annule ? ' det-barre' : ' tre-entree' ?>"><?= e(D::montant($r['montant'], $r['devise_id'], $devises)) ?></strong>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="bloc" aria-labelledby="det-messages-titre" id="messages">
    <h2 id="det-messages-titre"><?= e(t('gclients.messages')) ?></h2>
    <p class="aide"><?= e(t('gclients.messages_aide')) ?></p>
    <?php if ($fiche['messages'] === []): ?>
        <p class="aide"><?= e(t('gclients.aucun_message')) ?></p>
    <?php else: ?>
        <?= View::rendre('gestion/dettes/_messages', ['messages' => $fiche['messages'], 'avecDette' => $voirDettes]) ?>
    <?php endif; ?>
</section>
