<?php
/**
 * Fiche d'une dette : montant, reste, échéance (en rouge si dépassée), ticket, personnes liées (servi par, accordé par,
 * garant et sa réponse, responsable), remboursements reçus, messages envoyés, dette d'origine ou mise à la charge.
 * Décisions du gérant (dettes.gerer) : changer le responsable, mettre à la charge du responsable (après l'échéance),
 * annuler (sans remboursement) ou remettre, avec un motif.
 * @var ?string $flash
 * @var array $fiche dette, remboursements, origine, transfert, messages, aDesRemboursements
 * @var array<int, array> $devises
 * @var string $aujourdhui
 * @var bool $peutGerer
 * @var bool $peutRembourser
 * @var bool $voirVentes
 * @var bool $voirClients
 * @var array $utilisateurs actifs
 * @var array<string, string> $erreurs
 * @var array $saisie
 * @var ?string $ouvert formulaire à déplier (après une erreur)
 */

use App\Controllers\Gestion\DetteAffichage as D;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$d = $fiche['dette'];
$ouverte = $d['statut'] === 'ouverte';
$retard = $ouverte && $d['echeance'] < $aujourdhui;
$joursRetard = $retard ? (int) round((strtotime($aujourdhui . ' 12:00') - strtotime($d['echeance'] . ' 12:00')) / 86400) : 0;
$montant = static fn ($m): string => D::montant($m, $d['devise_id'], $devises);
$jeton = e(App\Core\Session::jetonCsrf());
$base = '/gestion/dettes/' . (int) $d['id'];
$personnelDuResponsable = $d['client_type'] === 'personnel' && (int) $d['client_utilisateur_id'] === (int) $d['responsable_id'];
$peutCharger = $ouverte && $retard && $d['responsable_id'] !== null && !$personnelDuResponsable;
$erreurChamp = static fn (string $champ): string => isset($erreurs[$champ])
    ? '<p class="champ__erreur" id="erreur-' . $champ . '">' . e($erreurs[$champ]) . '</p>' : '';
$invalide = static fn (string $champ): string => isset($erreurs[$champ]) ? ' aria-invalid="true" aria-describedby="erreur-' . $champ . '"' : '';
$personne = static function (?string $nom, ?string $avatar, ?string $vide = null): string {
    if ($nom === null) {
        return '<span class="aide">' . e($vide ?? '—') . '</span>';
    }
    return '<span class="det-personne">' . View::rendre('partials/_avatar', ['nom' => $nom, 'photo' => $avatar, 'classe' => 'avatar--petit']) . '<span>' . e($nom) . '</span></span>';
};
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-dettes.css">

<p class="tre-retour"><a class="lien" href="/gestion/dettes">← <?= e(t('gdettes.titre')) ?></a></p>

<div class="tre-entete det-entete">
    <div>
        <p class="surtitre"><?= e($d['etablissement_nom']) ?></p>
        <h1>
            <?= e(t('gdettes.fiche_titre', ['id' => $d['id']])) ?>
            <span class="badge <?= D::badgeDette($d['statut']) ?>" data-statut="<?= e($d['statut']) ?>"><?= e(t('gdettes.statut_' . $d['statut'])) ?></span>
        </h1>
        <p class="aide">
            <?php if ($voirClients): ?><a class="lien" href="/gestion/clients/<?= (int) $d['client_id'] ?>"><?= e($d['client_nom']) ?></a><?php else: ?><?= e($d['client_nom']) ?><?php endif; ?>
            <?= $d['client_telephone'] ? ' · <span class="montant">' . e(afficherTelephone($d['client_telephone'])) . '</span>' : '' ?>
            · <?= e(t('gdettes.creee_le', ['date' => A::dateHeure($d['cree_le'])])) ?>
        </p>
    </div>
    <?php if ($ouverte && $peutRembourser): ?>
        <div class="tre-entete__actions">
            <a class="bouton" href="/gestion/remboursements/nouveau?client=<?= (int) $d['client_id'] ?>"><?= e(t('gdettes.encaisser')) ?></a>
        </div>
    <?php endif; ?>
</div>

<?php if ($flash): ?>
    <p class="alerte" role="status"><?= e($flash) ?></p>
<?php endif; ?>
<?php if (isset($erreurs['decision'])): ?>
    <p class="alerte alerte--erreur" role="alert"><?= e($erreurs['decision']) ?></p>
<?php endif; ?>

<?php if ($fiche['transfert']): $tr = $fiche['transfert']; ?>
    <p class="alerte tre-note det-note" data-transfert="<?= (int) $tr['id'] ?>">
        <?= e(t('gdettes.transferee_texte', ['nom' => (string) $tr['responsable_nom'], 'montant' => D::montant($tr['montant'], $tr['devise_id'], $devises)])) ?>
        <a class="lien" href="/gestion/dettes/<?= (int) $tr['id'] ?>"><?= e(t('gdettes.voir_dette', ['id' => $tr['id']])) ?></a>
    </p>
<?php endif; ?>
<?php if ($fiche['origine']): $o = $fiche['origine']; ?>
    <p class="alerte tre-note det-note" data-origine="<?= (int) $o['id'] ?>">
        <?= e(t('gdettes.origine_texte', ['client' => $o['client_nom'], 'date' => A::jour($o['cree_le'])])) ?>
        <a class="lien" href="/gestion/dettes/<?= (int) $o['id'] ?>"><?= e(t('gdettes.voir_dette', ['id' => $o['id']])) ?></a>
    </p>
<?php endif; ?>

<section class="tre-totaux" aria-labelledby="det-chiffres-titre">
    <h2 class="visuellement-cache" id="det-chiffres-titre"><?= e(t('gdettes.la_dette')) ?></h2>
    <div class="chiffres det-chiffres">
        <div class="chiffre">
            <span class="chiffre__valeur" data-montant><?= e($montant($d['montant'])) ?></span>
            <span class="chiffre__libelle"><?= e(t('gdettes.col_montant')) ?></span>
        </div>
        <div class="chiffre">
            <span class="chiffre__valeur" data-reste><?= e($ouverte ? $montant($d['reste']) : '—') ?></span>
            <span class="chiffre__libelle"><?= e(t('gdettes.col_reste')) ?></span>
        </div>
        <div class="chiffre<?= $retard ? ' det-chiffre--retard' : '' ?>">
            <span class="chiffre__valeur det-chiffre--texte<?= $retard ? ' det-retard' : '' ?>" data-echeance="<?= e($d['echeance']) ?>"><?= e(A::jour($d['echeance'])) ?></span>
            <span class="chiffre__libelle"><?= e($retard ? t('gdettes.jours_retard', ['n' => $joursRetard]) : t('gdettes.col_echeance')) ?></span>
        </div>
    </div>
</section>

<div class="det-colonnes">
    <section class="bloc" aria-labelledby="det-personnes-titre">
        <h2 id="det-personnes-titre"><?= e(t('gdettes.personnes')) ?></h2>
        <dl class="det-infos">
            <div><dt><?= e(t('gdettes.servi_par')) ?></dt><dd><?= $personne($d['servi_nom'], $d['servi_avatar']) ?></dd></div>
            <div><dt><?= e(t('gdettes.accorde_par')) ?></dt><dd><?= $personne($d['accorde_nom'], $d['accorde_avatar']) ?></dd></div>
            <div>
                <dt><?= e(t('gdettes.garant')) ?></dt>
                <dd>
                    <?= $personne($d['garant_nom'], $d['garant_avatar'], t('gdettes.sans_garant')) ?>
                    <?php if ($d['garant_statut']): ?>
                        <span class="badge <?= $d['garant_statut'] === 'accepte' ? 'badge--succes' : ($d['garant_statut'] === 'refuse' ? 'badge--erreur' : 'badge--glace') ?>" data-garant="<?= e($d['garant_statut']) ?>"><?= e(t('gdettes.garant_' . $d['garant_statut'])) ?></span>
                        <?php if ($d['garant_repondu_le']): ?><span class="tre-sous-texte"><?= e(t('gdettes.repondu_le', ['date' => A::dateHeure($d['garant_repondu_le'])])) ?></span><?php endif; ?>
                    <?php endif; ?>
                </dd>
            </div>
            <div><dt><?= e(t('gdettes.col_responsable')) ?></dt><dd data-responsable="<?= (int) $d['responsable_id'] ?>"><?= $personne($d['responsable_nom'], $d['responsable_avatar'], t('gdettes.sans_responsable')) ?></dd></div>
        </dl>
        <p class="aide"><?= e(t('gdettes.responsable_aide')) ?></p>
    </section>

    <section class="bloc" aria-labelledby="det-origine-titre">
        <h2 id="det-origine-titre"><?= e(t('gdettes.la_dette')) ?></h2>
        <dl class="det-infos">
            <div>
                <dt><?= e(t('gdettes.ticket')) ?></dt>
                <dd>
                    <?php if ($d['vente_id'] && $voirVentes): ?>
                        <a class="lien" href="/gestion/ventes/<?= (int) $d['vente_id'] ?>" data-ticket><?= e((string) $d['vente_numero']) ?></a>
                    <?php elseif ($d['vente_id']): ?>
                        <?= e((string) $d['vente_numero']) ?>
                    <?php else: ?>
                        <span class="aide"><?= e(t('gdettes.sans_ticket')) ?></span>
                    <?php endif; ?>
                    <?php if (($d['vente_statut'] ?? null) === 'annulee'): ?><span class="badge badge--erreur"><?= e(t('gventes.badge_annulee')) ?></span><?php endif; ?>
                </dd>
            </div>
            <div><dt><?= e(t('gestion.devise')) ?></dt><dd><?= e($devises[(int) $d['devise_id']]['code'] ?? '') ?></dd></div>
            <?php if ($d['note']): ?><div><dt><?= e(t('gdettes.note')) ?></dt><dd class="det-texte-long"><?= e($d['note']) ?></dd></div><?php endif; ?>
            <?php if (!$ouverte && $d['cloturee_le']): ?>
                <div><dt><?= e(t('gdettes.cloturee_le')) ?></dt><dd><?= e(A::dateHeure($d['cloturee_le'])) ?><?= $d['cloturee_par_nom'] ? ' · ' . e(t('gclients.par', ['nom' => $d['cloturee_par_nom']])) : '' ?></dd></div>
            <?php endif; ?>
            <?php if ($d['motif']): ?><div><dt><?= e(t('gventes.motif')) ?></dt><dd class="det-texte-long" data-motif><?= e($d['motif']) ?></dd></div><?php endif; ?>
        </dl>
    </section>
</div>

<section class="bloc" aria-labelledby="det-remb-titre">
    <h2 id="det-remb-titre"><?= e(t('gdettes.remboursements_recus')) ?></h2>
    <?php if ($fiche['remboursements'] === []): ?>
        <p class="aide"><?= e(t('gdettes.aucun_remboursement')) ?></p>
    <?php else: ?>
        <ul class="det-lignes">
            <?php foreach ($fiche['remboursements'] as $r): $annule = $r['statut'] === 'annule'; ?>
                <li class="<?= $annule ? 'det-ligne--close' : '' ?>" data-remboursement="<?= (int) $r['id'] ?>">
                    <span>
                        <a class="det-lien-fort" href="/gestion/remboursements/<?= (int) $r['id'] ?>"><?= e(t('gremb.fiche_titre', ['id' => $r['id']])) ?></a>
                        <?php if ($annule): ?><span class="badge badge--erreur"><?= e(t('gremb.badge_annule')) ?></span><?php endif; ?>
                        <span class="tre-sous-texte"><?= e(A::dateHeure($r['cree_le'])) ?> · <?= e($r['compte_nom']) ?><?= $r['utilisateur_nom'] ? ' · ' . e($r['utilisateur_nom']) : '' ?>
                            <?php if ((int) $r['devise_id'] !== (int) $d['devise_id']): ?> · <?= e(t('gdettes.paye_en', ['montant' => D::montant($r['montant'], $r['devise_id'], $devises)])) ?><?php endif; ?></span>
                    </span>
                    <strong class="montant<?= $annule ? ' det-barre' : ' tre-entree' ?>"><?= e($montant($r['affecte'])) ?></strong>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="bloc" aria-labelledby="det-messages-titre">
    <h2 id="det-messages-titre"><?= e(t('gclients.messages')) ?></h2>
    <p class="aide"><?= e(t('gdettes.messages_aide')) ?></p>
    <?php if ($fiche['messages'] === []): ?>
        <p class="aide"><?= e(t('gclients.aucun_message')) ?></p>
    <?php else: ?>
        <?= View::rendre('gestion/dettes/_messages', ['messages' => $fiche['messages'], 'avecDette' => false]) ?>
    <?php endif; ?>
</section>

<?php if ($peutGerer && $ouverte): ?>
    <section class="bloc det-decisions" aria-labelledby="det-decisions-titre" id="decisions">
        <h2 id="det-decisions-titre"><?= e(t('gdettes.decisions')) ?></h2>
        <p class="aide"><?= e(t('gdettes.decisions_aide')) ?></p>

        <details class="det-decision" id="responsable"<?= $ouvert === 'responsable' ? ' open' : '' ?>>
            <summary><?= e(t('gdettes.changer_responsable')) ?></summary>
            <form method="post" action="<?= $base ?>/responsable" class="det-decision__form" novalidate>
                <input type="hidden" name="csrf" value="<?= $jeton ?>">
                <div class="champ">
                    <label for="responsable_id"><?= e(t('gdettes.nouveau_responsable')) ?></label>
                    <select id="responsable_id" name="responsable_id" required<?= $invalide('responsable_id') ?>>
                        <option value=""><?= e(t('gdettes.choisir_personne')) ?></option>
                        <?php foreach ($utilisateurs as $u): ?>
                            <option value="<?= (int) $u['id'] ?>" <?= (int) ($saisie['responsable_id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?><?= (int) $u['id'] === (int) $d['responsable_id'] ? ' disabled' : '' ?>><?= e($u['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= $erreurChamp('responsable_id') ?>
                </div>
                <div class="champ">
                    <label for="motif_responsable"><?= e(t('gventes.motif')) ?></label>
                    <input id="motif_responsable" name="motif_responsable" maxlength="255" required value="<?= e($saisie['motif_responsable'] ?? '') ?>"
                           placeholder="<?= e(t('gdettes.motif_responsable_exemple')) ?>"<?= $invalide('motif_responsable') ?>>
                    <?= $erreurChamp('motif_responsable') ?>
                </div>
                <div class="tre-actions"><button class="bouton bouton--secondaire" type="submit"><?= e(t('gdettes.changer_responsable')) ?></button></div>
            </form>
        </details>

        <details class="det-decision" id="charge"<?= $ouvert === 'charge' ? ' open' : '' ?>>
            <summary><?= e(t('gdettes.mettre_a_charge')) ?></summary>
            <?php if ($peutCharger): ?>
                <p class="aide"><?= e(t('gdettes.a_charge_aide', ['nom' => (string) $d['responsable_nom'], 'reste' => $montant($d['reste'])])) ?></p>
                <form method="post" action="<?= $base ?>/a-charge" class="det-decision__form" novalidate>
                    <input type="hidden" name="csrf" value="<?= $jeton ?>">
                    <div class="champ">
                        <label for="motif_charge"><?= e(t('gventes.motif')) ?></label>
                        <input id="motif_charge" name="motif_charge" maxlength="255" required value="<?= e($saisie['motif_charge'] ?? '') ?>"
                               placeholder="<?= e(t('gdettes.motif_charge_exemple')) ?>"<?= $invalide('motif_charge') ?>>
                        <?= $erreurChamp('motif_charge') ?>
                    </div>
                    <label class="coche det-coche">
                        <input type="checkbox" name="confirme" value="1"<?= $invalide('confirme_charge') ?>>
                        <span><?= e(t('gdettes.confirmer_charge', ['nom' => (string) $d['responsable_nom'], 'reste' => $montant($d['reste'])])) ?></span>
                    </label>
                    <?= $erreurChamp('confirme_charge') ?>
                    <div class="tre-actions"><button class="bouton bouton--danger" type="submit"><?= e(t('gdettes.mettre_a_charge')) ?></button></div>
                </form>
            <?php elseif ($personnelDuResponsable): ?>
                <p class="aide" data-charge-impossible="personnel"><?= e(t('gdettes.erreur_deja_au_personnel')) ?></p>
            <?php elseif ($d['responsable_id'] === null): ?>
                <p class="aide" data-charge-impossible="sans_responsable"><?= e(t('gdettes.erreur_sans_responsable')) ?></p>
            <?php else: ?>
                <p class="aide" data-charge-impossible="pas_echue"><?= e(t('gdettes.a_charge_pas_encore', ['date' => A::jour(date('Y-m-d', strtotime($d['echeance'] . ' +1 day')))])) ?></p>
            <?php endif; ?>
        </details>

        <details class="det-decision" id="cloture"<?= $ouvert === 'cloture' ? ' open' : '' ?>>
            <summary><?= e(t('gdettes.annuler_remettre')) ?></summary>
            <form method="post" action="<?= $base ?>/cloturer" class="det-decision__form" novalidate>
                <input type="hidden" name="csrf" value="<?= $jeton ?>">
                <fieldset class="tre-groupe-champs">
                    <legend><?= e(t('gdettes.decision')) ?></legend>
                    <div class="det-radios">
                        <label class="coche">
                            <input type="radio" name="statut" value="remise" <?= ($saisie['statut'] ?? 'remise') === 'remise' || $fiche['aDesRemboursements'] ? 'checked' : '' ?>>
                            <span><strong><?= e(t('gdettes.remettre')) ?></strong> <span class="tre-facultatif"><?= e(t('gdettes.remettre_aide', ['reste' => $montant($d['reste'])])) ?></span></span>
                        </label>
                        <label class="coche">
                            <input type="radio" name="statut" value="annulee" <?= ($saisie['statut'] ?? '') === 'annulee' && !$fiche['aDesRemboursements'] ? 'checked' : '' ?><?= $fiche['aDesRemboursements'] ? ' disabled' : '' ?>>
                            <span><strong><?= e(t('gdettes.annuler')) ?></strong> <span class="tre-facultatif"><?= e($fiche['aDesRemboursements'] ? t('gdettes.annuler_impossible') : t('gdettes.annuler_aide')) ?></span></span>
                        </label>
                    </div>
                    <?= $erreurChamp('statut') ?>
                </fieldset>
                <div class="champ">
                    <label for="motif_cloture"><?= e(t('gventes.motif')) ?></label>
                    <input id="motif_cloture" name="motif_cloture" maxlength="255" required value="<?= e($saisie['motif_cloture'] ?? '') ?>"
                           placeholder="<?= e(t('gdettes.motif_cloture_exemple')) ?>"<?= $invalide('motif_cloture') ?>>
                    <?= $erreurChamp('motif_cloture') ?>
                </div>
                <label class="coche det-coche">
                    <input type="checkbox" name="confirme" value="1"<?= $invalide('confirme_cloture') ?>>
                    <span><?= e(t('gdettes.confirmer_cloture', ['client' => $d['client_nom'], 'reste' => $montant($d['reste'])])) ?></span>
                </label>
                <?= $erreurChamp('confirme_cloture') ?>
                <div class="tre-actions"><button class="bouton bouton--danger" type="submit"><?= e(t('gdettes.cloturer_bouton')) ?></button></div>
            </form>
        </details>
    </section>
<?php endif; ?>
