<?php
/**
 * Fiche d'un employé : informations, présence du mois, bulletins, avances, dettes du personnel et manquants
 * de caisse (personnel.paie), pointage (téléphone reconnu, PIN de pointage sans compte), documents (personnel.gerer).
 * @var ?string $flash
 * @var array $employe
 * @var array<int, string> $etablissementsNoms
 * @var array $bilan totaux et jours du mois
 * @var string $moisDu
 * @var ?array $present
 * @var bool $voirPaie
 * @var bool $gerer
 * @var list<array> $bulletins
 * @var list<array> $avances
 * @var ?array $dettes
 * @var list<array> $manquants
 * @var list<array> $telephones
 * @var list<array> $documents
 * @var list<array> $corrections
 * @var bool $voirDettes
 * @var ?string $erreurPin
 * @var ?string $erreurDocument
 */

use App\Controllers\Gestion\PersonnelAffichage as P;
use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$t = $bilan['totaux'];
$id = (int) $employe['id'];
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-dettes.css">
<link rel="stylesheet" href="/assets/css/gestion-personnel.css">

<p class="tre-retour"><a class="lien" href="/gestion/personnel/employes">← <?= e(t('pers.employes_titre')) ?></a></p>
<div class="tre-entete per-entete">
    <div class="per-entete__identite">
        <?= View::rendre('partials/_avatar', ['nom' => $employe['nom'], 'photo' => $employe['avatar'] ?? null, 'classe' => 'avatar--moyen']) ?>
        <div>
            <h1><?= e($employe['nom']) ?></h1>
            <p class="per-badges">
                <span class="badge badge--neutre"><?= e(t('pers.contrat_' . $employe['contrat'])) ?></span>
                <?php if (!$employe['actif']): ?><span class="badge badge--erreur"><?= e(t('pers.inactif')) ?></span><?php endif; ?>
                <?php if ($present): ?><span class="badge badge--succes"><?= e(t('pers.present_ici', ['heure' => P::heure($present['arrivee']), 'etablissement' => $present['etablissement_nom']])) ?></span><?php endif; ?>
                <?php if ($employe['utilisateur_id'] === null): ?><span class="badge badge--glace"><?= e(t('pers.sans_compte')) ?></span><?php endif; ?>
            </p>
        </div>
    </div>
    <?php if ($gerer): ?>
        <div class="tre-entete__actions">
            <a class="bouton bouton--secondaire" href="/gestion/personnel/employes/<?= $id ?>/modifier"><?= e(t('pers.modifier')) ?></a>
            <form method="post" action="/gestion/personnel/employes/<?= $id ?>/activer">
                <input type="hidden" name="csrf" value="<?= $jeton ?>">
                <input type="hidden" name="actif" value="<?= $employe['actif'] ? '0' : '1' ?>">
                <button class="bouton bouton--fantome" type="submit"><?= e(t($employe['actif'] ? 'pers.desactiver' : 'pers.reactiver')) ?></button>
            </form>
        </div>
    <?php endif; ?>
</div>

<?php if ($flash): ?><p class="alerte" role="status"><?= e($flash) ?></p><?php endif; ?>

<div class="det-colonnes">
    <section class="bloc" aria-labelledby="per-infos">
        <h2 id="per-infos"><?= e(t('pers.section_infos')) ?></h2>
        <dl class="det-infos">
            <div><dt><?= e(t('pers.champ_poste')) ?></dt><dd><?= e((string) ($employe['poste'] ?: '—')) ?></dd></div>
            <div><dt><?= e(t('pers.champ_etablissement')) ?></dt><dd><?= e(implode(', ', array_map(static fn ($e) => $etablissementsNoms[$e] ?? '?', $employe['etablissements']))) ?></dd></div>
            <div><dt><?= e(t('pers.champ_telephone')) ?></dt><dd><?= e(afficherTelephone($employe['telephone'] ?? null) ?: '—') ?></dd></div>
            <div><dt><?= e(t('pers.champ_compte')) ?></dt><dd><?= e($employe['compte_nom'] ?? t('pers.sans_compte')) ?></dd></div>
            <div><dt><?= e(t('pers.champ_debut')) ?></dt><dd><?= e(P::jour($employe['debut'])) ?><?= $employe['fin'] ? ' → ' . e(P::jour($employe['fin'])) : '' ?></dd></div>
            <?php if ($voirPaie): ?>
                <div><dt><?= e(t('pers.section_salaire')) ?></dt><dd class="montant" data-salaire><?= e(P::montants($employe['salaires'], t('pers.salaire_non_fixe'))) ?> <span class="tre-sous-texte"><?= e(t('pers.par_' . $employe['periode'])) ?></span></dd></div>
                <?php if ($employe['commission_pct'] !== null): ?><div><dt><?= e(t('pers.champ_commission')) ?></dt><dd><?= e(P::nombre($employe['commission_pct'])) ?> %</dd></div><?php endif; ?>
                <div><dt><?= e(t('pers.champ_pourboires')) ?></dt><dd><?= e(t($employe['pourboires'] ? 'pers.oui' : 'pers.non')) ?></dd></div>
            <?php endif; ?>
        </dl>
        <?php if (trim((string) $employe['notes']) !== ''): ?><p class="det-texte-long per-notes"><?= e($employe['notes']) ?></p><?php endif; ?>
    </section>

    <section class="bloc" aria-labelledby="per-mois">
        <h2 id="per-mois"><?= e(t('pers.presence_du_mois', ['mois' => P::nomMois((int) substr($moisDu, 5, 2))])) ?></h2>
        <div class="chiffres per-chiffres">
            <div class="chiffre"><span class="chiffre__valeur"><?= e(P::duree($t['minutes_travaillees'])) ?></span><span class="chiffre__libelle"><?= e(t('pers.heures_travaillees_sur', ['prevu' => P::duree($t['minutes_planifiees'])])) ?></span></div>
            <div class="chiffre"><span class="chiffre__valeur"><?= (int) $t['retards'] ?></span><span class="chiffre__libelle"><?= e(t('pers.retards_n', ['duree' => P::duree($t['minutes_retard'])])) ?></span></div>
            <div class="chiffre"><span class="chiffre__valeur"><?= (int) ($t['absences'] + $t['en_attente']) ?></span><span class="chiffre__libelle"><?= e(t('pers.absences_n', ['j' => $t['absences_justifiees']])) ?></span></div>
            <div class="chiffre"><span class="chiffre__valeur"><?= e(P::duree($t['minutes_sup'])) ?></span><span class="chiffre__libelle"><?= e(t('pers.col_heures_sup')) ?></span></div>
        </div>
        <p class="det-pied"><a class="lien" href="/gestion/personnel/presence/pointages?employe=<?= $id ?>&amp;periode=mois"><?= e(t('pers.voir_pointages')) ?></a></p>
    </section>
</div>

<?php if ($voirPaie): ?>
    <section class="bloc" aria-labelledby="per-bulletins">
        <h2 id="per-bulletins"><?= e(t('pers.bulletins')) ?></h2>
        <?php if ($bulletins === []): ?>
            <p class="aide"><?= e(t('pers.aucun_bulletin')) ?></p>
        <?php else: ?>
            <div class="defile">
                <table class="tableau per-tableau">
                    <thead><tr><th scope="col"><?= e(t('pers.col_periode')) ?></th><th scope="col"><?= e(t('pers.statut')) ?></th><th scope="col" class="nombre"><?= e(t('pers.col_net')) ?></th><th scope="col" class="nombre"><?= e(t('pers.col_paye')) ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($bulletins as $b): ?>
                            <tr>
                                <td><a class="det-lien-fort" href="/gestion/personnel/paie/<?= (int) $b['id'] ?>"><?= e(P::periode($b['debut'], $b['fin'])) ?></a></td>
                                <td><span class="badge badge--<?= ['brouillon' => 'neutre', 'valide' => 'braise', 'paye' => 'succes', 'annule' => 'erreur'][$b['statut']] ?>"><?= e(t('pers.bulletin_' . $b['statut'])) ?></span></td>
                                <td class="nombre montant"><?= e(P::montants($b['totaux']['net'])) ?></td>
                                <td class="nombre montant"><?= e(P::montants($b['paye'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <div class="det-colonnes">
        <section class="bloc" aria-labelledby="per-avances">
            <h2 id="per-avances"><?= e(t('pers.avances_titre')) ?></h2>
            <?php if ($avances === []): ?>
                <p class="aide"><?= e(t('pers.aucune_avance')) ?></p>
            <?php else: ?>
                <ul class="det-lignes">
                    <?php foreach ($avances as $a): $m = []; foreach ($a['lignes'] as $l) { $m[(int) $l['devise_id']] = ($m[(int) $l['devise_id']] ?? 0) + (float) $l['montant']; } ?>
                        <li><span><?= e(P::jour($a['date_avance'])) ?> <span class="badge badge--<?= ['a_deduire' => 'braise', 'deduite' => 'succes', 'annulee' => 'neutre'][$a['statut']] ?>"><?= e(t('pers.avance_' . $a['statut'])) ?></span></span><span class="montant"><?= e(P::montants($m)) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <p class="det-pied"><a class="lien" href="/gestion/personnel/avances?employe=<?= $id ?>"><?= e(t('pers.nouvelle_avance')) ?></a></p>
        </section>

        <section class="bloc" aria-labelledby="per-retenues">
            <h2 id="per-retenues"><?= e(t('pers.retenues_a_venir')) ?></h2>
            <p class="aide"><?= e(t('pers.retenues_a_venir_aide')) ?></p>
            <h3 class="det-sous-titre"><?= e(t('pers.dettes_personnel')) ?></h3>
            <?php if (empty($dettes['encours'])): ?>
                <p class="aide"><?= e(t('pers.aucune_dette')) ?></p>
            <?php else: ?>
                <p class="montant" data-dettes><strong><?= e(P::montants($dettes['encours'])) ?></strong>
                    <?php if ($voirDettes && $dettes['client']): ?> · <a class="lien" href="/gestion/clients/<?= (int) $dettes['client']['id'] ?>"><?= e(t('pers.voir_dettes')) ?></a><?php endif; ?></p>
            <?php endif; ?>
            <h3 class="det-sous-titre"><?= e(t('pers.manquants')) ?></h3>
            <?php if ($manquants === []): ?>
                <p class="aide"><?= e(t('pers.aucun_manquant')) ?></p>
            <?php else: ?>
                <ul class="det-lignes">
                    <?php foreach ($manquants as $m): ?>
                        <li data-manquant="<?= (int) $m['session_id'] ?>"><span><?= e(t('pers.manquant_session', ['n' => $m['session_id'], 'date' => P::jour($m['date']), 'etablissement' => $m['etablissement']])) ?>
                            <?php if ($m['reste'] <= 0.0005): ?><span class="badge badge--succes"><?= e(t('pers.manquant_retenu')) ?></span><?php elseif ($m['retenu'] > 0): ?><span class="badge badge--braise"><?= e(t('pers.manquant_reste', ['montant' => P::montant($m['reste'], $m['devise_id'])])) ?></span><?php endif; ?></span>
                            <span class="montant"><?= e(P::montant($m['manquant'], $m['devise_id'])) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
<?php endif; ?>

<?php if ($gerer): ?>
    <div class="det-colonnes">
        <section class="bloc" id="pointage" aria-labelledby="per-pointage">
            <h2 id="per-pointage"><?= e(t('pers.section_pointage')) ?></h2>
            <p class="aide"><?= e(t($employe['utilisateur_id'] !== null ? 'pers.pointage_aide_compte' : 'pers.pointage_aide_sans_compte')) ?></p>
            <?php if ($employe['utilisateur_id'] === null): ?>
                <p><?= $employe['pin_pointage'] !== null ? '<span class="badge badge--succes">' . e(t('pers.pin_defini')) . '</span>' : '<span class="badge badge--braise">' . e(t('pers.pin_absent')) . '</span>' ?>
                    <?php if ($employe['pin_bloque_le'] !== null): ?><span class="badge badge--erreur"><?= e(t('pers.pin_bloque')) ?></span><?php endif; ?></p>
                <form method="post" action="/gestion/personnel/employes/<?= $id ?>/pin" class="per-ligne-formulaire" novalidate>
                    <input type="hidden" name="csrf" value="<?= $jeton ?>">
                    <div class="champ">
                        <label for="pin"><?= e(t('pers.champ_pin')) ?></label>
                        <input id="pin" name="pin" type="password" inputmode="numeric" maxlength="6" autocomplete="new-password"<?= $erreurPin ? ' aria-invalid="true" aria-describedby="erreur-pin"' : '' ?>>
                        <?php if ($erreurPin): ?><p class="champ__erreur" id="erreur-pin"><?= e($erreurPin) ?></p><?php endif; ?>
                    </div>
                    <button class="bouton bouton--secondaire" type="submit"><?= e(t('pers.definir_pin')) ?></button>
                </form>
                <?php if ($employe['pin_bloque_le'] !== null): ?>
                    <form method="post" action="/gestion/personnel/employes/<?= $id ?>/debloquer"><input type="hidden" name="csrf" value="<?= $jeton ?>"><button class="lien" type="submit"><?= e(t('pers.debloquer_pin')) ?></button></form>
                <?php endif; ?>
            <?php endif; ?>
            <h3 class="det-sous-titre"><?= e(t('pers.telephone_reconnu')) ?></h3>
            <?php $actifs = array_filter($telephones, static fn ($x) => $x['actif']); ?>
            <?php if ($actifs === []): ?>
                <p class="aide"><?= e(t('pers.aucun_telephone')) ?></p>
            <?php else: ?>
                <ul class="det-lignes">
                    <?php foreach ($actifs as $tel): ?>
                        <li><span><?= e(t('pers.telephone_depuis', ['date' => P::jour($tel['cree_le']), 'usage' => $tel['dernier_usage'] ? P::jour($tel['dernier_usage']) : '—'])) ?><span class="tre-sous-texte"><?= e(mb_substr((string) $tel['appareil'], 0, 80)) ?></span></span>
                            <form method="post" action="/gestion/personnel/employes/<?= $id ?>/telephones/<?= (int) $tel['id'] ?>/retirer"><input type="hidden" name="csrf" value="<?= $jeton ?>"><button class="lien" type="submit"><?= e(t('pers.retirer')) ?></button></form></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="bloc" id="documents" aria-labelledby="per-documents">
            <h2 id="per-documents"><?= e(t('pers.documents')) ?></h2>
            <?php if ($documents === []): ?>
                <p class="aide"><?= e(t('pers.aucun_document')) ?></p>
            <?php else: ?>
                <ul class="det-lignes">
                    <?php foreach ($documents as $d): ?>
                        <li><span><a class="det-lien-fort" href="/gestion/personnel/employes/<?= $id ?>/documents/<?= (int) $d['id'] ?>" target="_blank" rel="noopener"><?= e($d['nom']) ?></a>
                            <span class="tre-sous-texte"><?= e(P::jour($d['ajoute_le']) . ' · ' . round($d['taille'] / 1024) . ' Ko' . ($d['ajoute_par_nom'] ? ' · ' . $d['ajoute_par_nom'] : '')) ?></span></span>
                            <form method="post" action="/gestion/personnel/employes/<?= $id ?>/documents/<?= (int) $d['id'] ?>/supprimer"><input type="hidden" name="csrf" value="<?= $jeton ?>"><button class="lien" type="submit"><?= e(t('pers.supprimer')) ?></button></form></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <form method="post" action="/gestion/personnel/employes/<?= $id ?>/documents" enctype="multipart/form-data" class="per-document-formulaire" novalidate>
                <input type="hidden" name="csrf" value="<?= $jeton ?>">
                <div class="champ">
                    <label for="doc-nom"><?= e(t('pers.champ_document_nom')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
                    <input id="doc-nom" name="nom" maxlength="120" placeholder="<?= e(t('pers.champ_document_exemple')) ?>">
                </div>
                <div class="champ">
                    <label for="doc-fichier"><?= e(t('pers.champ_document')) ?></label>
                    <input id="doc-fichier" name="document" type="file" accept="application/pdf,image/jpeg,image/png,image/webp"<?= $erreurDocument ? ' aria-invalid="true" aria-describedby="erreur-document"' : '' ?>>
                    <p class="champ__aide"><?= e(t('pers.champ_document_aide')) ?></p>
                    <?php if ($erreurDocument): ?><p class="champ__erreur" id="erreur-document"><?= e($erreurDocument) ?></p><?php endif; ?>
                </div>
                <button class="bouton bouton--secondaire" type="submit"><?= e(t('pers.ajouter_document')) ?></button>
            </form>
        </section>
    </div>
<?php endif; ?>

<?php if ($corrections !== []): ?>
    <section class="bloc" aria-labelledby="per-corrections">
        <h2 id="per-corrections"><?= e(t('pers.corrections_recentes')) ?></h2>
        <?= View::rendre('gestion/personnel/presence/_corrections', ['corrections' => $corrections, 'avecNom' => false]) ?>
    </section>
<?php endif; ?>
