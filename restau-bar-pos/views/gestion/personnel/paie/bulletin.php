<?php
/**
 * Bulletin de paie : brouillon modifiable (heures, retenues proposées à réduire ou étaler, primes) puis validé,
 * payé depuis la trésorerie (compte + devise, une ou plusieurs lignes), annulé.
 * @var ?string $flash
 * @var array $bulletin
 * @var ?array $employe
 * @var array<int, float> $reste reste à payer par devise
 * @var array<int, ?float> $maximums maximum des retenues proposées (dette, manquant) par ligne
 * @var bool $peutPayer
 * @var array<int, array> $comptes
 * @var array<int, array> $devises
 * @var ?array $reference
 * @var list<array> $lignesPaiement
 * @var array<string, string> $erreurs
 * @var list<string> $avertissements
 * @var bool $voirComptes
 */

use App\Controllers\Gestion\PersonnelAffichage as P;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;
use App\Repositories\PaieRepository;

$b = $bulletin;
$id = (int) $b['id'];
$brouillon = $b['statut'] === 'brouillon';
$jeton = e(App\Core\Session::jetonCsrf());
$gains = array_values(array_filter($b['lignes'], static fn ($l) => in_array($l['type'], PaieRepository::GAINS, true)));
$retenues = array_values(array_filter($b['lignes'], static fn ($l) => !in_array($l['type'], PaieRepository::GAINS, true)));
$statutBadge = ['brouillon' => 'neutre', 'valide' => 'braise', 'paye' => 'succes', 'annule' => 'erreur'][$b['statut']];
$invalide = static fn (string $champ): string => isset($erreurs[$champ]) ? ' aria-invalid="true" aria-describedby="erreur-' . $champ . '"' : '';
$erreur = static fn (string $champ): string => isset($erreurs[$champ]) ? '<p class="champ__erreur" id="erreur-' . $champ . '">' . e($erreurs[$champ]) . '</p>' : '';
$enAttente = $b['absences_injustifiees'] > 0 && $brouillon;

$ligne = static function (array $l) use ($brouillon, $maximums, $invalide, $erreur): string {
    ob_start();
    $lid = (int) $l['id'];
    $editableQte = $brouillon && in_array($l['type'], ['heures_sup', 'ferie', 'absence', 'sans_solde'], true);
    $retenueProposee = in_array($l['type'], ['dette', 'manquant'], true);
    ?>
    <tr data-ligne="<?= $lid ?>" data-type="<?= e($l['type']) ?>" data-devise="<?= (int) $l['devise_id'] ?>">
        <th scope="row">
            <?= e(P::libelleLigne($l)) ?>
            <?php if ($retenueProposee): ?>
                <span class="tre-sous-texte"><?= e(t('pers.reste_du', ['montant' => P::montant($l['source_du'], (int) $l['source_devise_id'])])) ?><?php if ((int) $l['source_devise_id'] !== (int) $l['devise_id']): ?> · <?= e(t('pers.converti_au_taux')) ?><?php endif; ?></span>
            <?php endif; ?>
            <?php if (!$l['auto']): ?><span class="badge badge--neutre"><?= e(t('pers.type_' . $l['type'])) ?></span><?php endif; ?>
        </th>
        <td class="nombre per-bulletin__qte">
            <?php if ($editableQte): ?>
                <label class="visuellement-cache" for="qte-<?= $lid ?>"><?= e(t('pers.quantite')) ?></label>
                <input id="qte-<?= $lid ?>" class="per-saisie-courte" name="quantite[<?= $lid ?>]" inputmode="decimal" value="<?= e(A::saisie($l['quantite'])) ?>"<?= $invalide('quantite-' . $lid) ?>>
                <?= $erreur('quantite-' . $lid) ?>
            <?php elseif ($l['quantite'] !== null && $l['type'] !== 'commission'): ?>
                <?= e(P::nombre($l['quantite'])) ?>
            <?php endif; ?>
        </td>
        <td class="nombre per-bulletin__montant">
            <?php if ($brouillon && ($retenueProposee || !$l['auto'])): ?>
                <span class="per-retenue">
                    <label class="visuellement-cache" for="montant-<?= $lid ?>"><?= e(t('pers.montant')) ?></label>
                    <input id="montant-<?= $lid ?>" class="per-saisie-courte" name="montant[<?= $lid ?>]" inputmode="decimal" value="<?= e(A::saisie($l['montant'])) ?>"
                           data-max="<?= $retenueProposee ? e((string) $maximums[$lid]) : '' ?>" data-decimales="<?= (int) (P::devises()[(int) $l['devise_id']]['decimales'] ?? 2) ?>"<?= $invalide('montant-' . $lid) ?>>
                    <?php if ($retenueProposee): ?>
                        <label class="visuellement-cache" for="etaler-<?= $lid ?>"><?= e(t('pers.etaler')) ?></label>
                        <select id="etaler-<?= $lid ?>" name="etaler[<?= $lid ?>]" data-etaler="<?= $lid ?>">
                            <option value="1"><?= e(t('pers.etaler_non')) ?></option>
                            <?php foreach ([2, 3, 4, 6] as $n): ?><option value="<?= $n ?>"><?= e(t('pers.etaler_sur', ['n' => $n])) ?></option><?php endforeach; ?>
                        </select>
                        <span class="tre-sous-texte"><?= e(t('pers.maximum', ['montant' => P::montant($maximums[$lid], (int) $l['devise_id'])])) ?></span>
                    <?php endif; ?>
                </span>
                <?= $erreur('montant-' . $lid) ?>
            <?php else: ?>
                <span class="montant"><?= e(P::montant($l['montant'], (int) $l['devise_id'])) ?></span>
            <?php endif; ?>
            <?php if ($brouillon && ($l['type'] === 'avance' || !$l['auto'])): ?>
                <label class="coche per-retirer"><input type="checkbox" name="retirer[]" value="<?= $lid ?>"> <?= e(t($l['type'] === 'avance' ? 'pers.reporter' : 'pers.retirer')) ?></label>
            <?php endif; ?>
        </td>
    </tr>
    <?php
    return (string) ob_get_clean();
};
?>
<?= View::rendre('gestion/personnel/_onglets-paie', ['actif' => 'bulletins']) ?>
<link rel="stylesheet" href="/assets/css/gestion-dettes.css">

<p class="tre-retour"><a class="lien" href="/gestion/personnel/paie?etablissement=<?= (int) $b['etablissement_id'] ?>&amp;<?= $b['periode'] === 'semaine' ? 'type=semaine&amp;semaine=' . e($b['debut']) : 'mois=' . e(substr($b['debut'], 0, 7)) ?>">← <?= e(t('pers.paie_titre')) ?></a></p>
<div class="tre-entete">
    <div>
        <p class="surtitre"><?= e(t('pers.bulletin')) ?> · <?= e($b['etablissement_nom']) ?></p>
        <h1 class="det-entete"><?= e($b['employe_nom']) ?> <span class="badge badge--<?= $statutBadge ?>" data-statut="<?= e($b['statut']) ?>"><?= e(t('pers.bulletin_' . $b['statut'])) ?></span></h1>
        <p class="aide"><?= e(P::periode($b['debut'], $b['fin'])) ?> · <?= e(t('pers.periode_' . $b['periode'])) ?><?= $b['poste'] ? ' · ' . e($b['poste']) : '' ?></p>
    </div>
    <div class="tre-entete__actions">
        <a class="bouton bouton--secondaire" href="/gestion/personnel/paie/<?= $id ?>/imprimer" target="_blank" rel="noopener"><?= e(t('pers.imprimer')) ?></a>
    </div>
</div>

<?php if ($flash): ?><p class="alerte" role="status"><?= e($flash) ?></p><?php endif; ?>
<?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => array_values($erreurs)]) ?>

<div class="chiffres per-chiffres">
    <div class="chiffre"><span class="chiffre__valeur"><?= e(P::nombre($b['heures_travaillees'])) ?> h</span><span class="chiffre__libelle"><?= e(t('pers.heures_sur', ['n' => P::nombre($b['heures_planifiees'])])) ?></span></div>
    <div class="chiffre"><span class="chiffre__valeur"><?= (int) $b['jours_travailles'] ?></span><span class="chiffre__libelle"><?= e(t('pers.jours_travailles_sur', ['n' => (int) $b['creneaux_planifies']])) ?></span></div>
    <div class="chiffre"><span class="chiffre__valeur"><?= (int) $b['retards'] ?></span><span class="chiffre__libelle"><?= e(t('pers.retards_n', ['duree' => P::duree((int) $b['retard_minutes'])])) ?></span></div>
    <div class="chiffre"><span class="chiffre__valeur"><?= (int) $b['absences_injustifiees'] ?></span><span class="chiffre__libelle"><?= e(t('pers.absences_injustifiees')) ?></span></div>
</div>

<?php if ($brouillon): ?>
<form method="post" action="/gestion/personnel/paie/<?= $id ?>" class="tre-formulaire" id="formulaire-bulletin" novalidate>
    <input type="hidden" name="csrf" value="<?= $jeton ?>">
    <button type="submit" class="visuellement-cache" tabindex="-1" aria-hidden="true" name="action" value="enregistrer"><?= e(t('pers.enregistrer')) ?></button>
    <p class="aide"><?= e(t('pers.brouillon_aide')) ?></p>
<?php endif; ?>

<section class="bloc" aria-labelledby="per-gains">
    <h2 id="per-gains"><?= e(t('pers.gains')) ?></h2>
    <div class="defile">
        <table class="tableau per-bulletin">
            <thead><tr><th scope="col"><?= e(t('pers.libelle')) ?></th><th scope="col" class="nombre"><?= e(t('pers.quantite')) ?></th><th scope="col" class="nombre"><?= e(t('pers.montant')) ?></th></tr></thead>
            <tbody>
                <?php foreach ($gains as $l): ?><?= $ligne($l) ?><?php endforeach; ?>
                <?php if ($gains === []): ?><tr><td colspan="3" class="aide"><?= e(t('pers.aucun_gain')) ?></td></tr><?php endif; ?>
            </tbody>
            <tfoot><tr><th scope="row"><?= e(t('pers.total_brut')) ?></th><td></td><td class="nombre montant" data-total="brut"><strong><?= e(P::montants($b['totaux']['gains'])) ?></strong></td></tr></tfoot>
        </table>
    </div>
</section>

<section class="bloc" aria-labelledby="per-retenues-titre">
    <h2 id="per-retenues-titre"><?= e(t('pers.retenues')) ?></h2>
    <?php if ($brouillon): ?><p class="aide"><?= e(t('pers.retenues_aide')) ?></p><?php endif; ?>
    <?php if ($enAttente): ?><p class="aide"><?= e(t('pers.absences_a_verifier')) ?> <a class="lien" href="/gestion/personnel/conges?statut=en_attente"><?= e(t('pers.conges_titre')) ?></a></p><?php endif; ?>
    <div class="defile">
        <table class="tableau per-bulletin">
            <thead><tr><th scope="col"><?= e(t('pers.libelle')) ?></th><th scope="col" class="nombre"><?= e(t('pers.quantite')) ?></th><th scope="col" class="nombre"><?= e(t('pers.montant')) ?></th></tr></thead>
            <tbody>
                <?php foreach ($retenues as $l): ?><?= $ligne($l) ?><?php endforeach; ?>
                <?php if ($retenues === []): ?><tr><td colspan="3" class="aide"><?= e(t('pers.aucune_retenue')) ?></td></tr><?php endif; ?>
            </tbody>
            <tfoot><tr><th scope="row"><?= e(t('pers.total_retenues')) ?></th><td></td><td class="nombre montant" data-total="retenues"><strong><?= e(P::montants($b['totaux']['retenues'])) ?></strong></td></tr></tfoot>
        </table>
    </div>
</section>

<section class="bloc per-net" aria-labelledby="per-net-titre">
    <h2 id="per-net-titre"><?= e(t('pers.net_a_payer')) ?></h2>
    <ul class="per-net__devises">
        <?php foreach (array_keys($devises) as $d): if (!isset($b['totaux']['net'][$d])) continue; ?>
            <li data-net="<?= e($devises[$d]['code']) ?>"><span class="surtitre"><?= e($devises[$d]['code']) ?></span><strong class="montant per-net__montant<?= $b['totaux']['net'][$d] < 0 ? ' tre-negatif' : '' ?>"><?= e(P::montant($b['totaux']['net'][$d], $d)) ?></strong>
                <?php if (!$brouillon && $b['statut'] !== 'annule'): ?><span class="tre-sous-texte"><?= e(t('pers.paye_reste', ['paye' => P::montant($b['paye'][$d] ?? 0, $d), 'reste' => P::montant($reste[$d] ?? 0, $d)])) ?></span><?php endif; ?></li>
        <?php endforeach; ?>
        <?php if ($b['totaux']['net'] === []): ?><li class="aide"><?= e(t('pers.rien_a_payer')) ?></li><?php endif; ?>
    </ul>
</section>

<?php if ($brouillon): ?>
    <section class="bloc" aria-labelledby="per-ajouter-ligne">
        <h2 id="per-ajouter-ligne"><?= e(t('pers.ajouter_ligne')) ?></h2>
        <div class="per-nouvelle-ligne">
            <div class="champ">
                <label for="nouvelle-type"><?= e(t('pers.champ_type_ligne')) ?></label>
                <select id="nouvelle-type" name="nouvelle[type]">
                    <?php foreach (PaieRepository::MANUELLES as $ty): ?><option value="<?= $ty ?>"><?= e(t('pers.type_' . $ty)) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="champ">
                <label for="nouvelle-libelle"><?= e(t('pers.libelle')) ?></label>
                <input id="nouvelle-libelle" name="nouvelle[libelle]" maxlength="255" placeholder="<?= e(t('pers.champ_libelle_exemple')) ?>">
            </div>
            <div class="champ">
                <label for="nouvelle-devise"><?= e(t('depenses.champ_devise')) ?></label>
                <select id="nouvelle-devise" name="nouvelle[devise_id]">
                    <?php foreach ($devises as $d => $dv): if (!($dv['active'] ?? true)) continue; ?><option value="<?= (int) $d ?>"><?= e($dv['code']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="champ">
                <label for="nouvelle-montant"><?= e(t('pers.montant')) ?></label>
                <input id="nouvelle-montant" name="nouvelle[montant]" inputmode="decimal"<?= $invalide('nouvelle') ?>>
            </div>
        </div>
        <?= $erreur('nouvelle') ?>
        <div class="champ">
            <label for="note"><?= e(t('pers.champ_note_bulletin')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
            <textarea id="note" name="note" rows="2" maxlength="2000"><?= e((string) $b['note']) ?></textarea>
        </div>
        <div class="tre-actions">
            <button class="bouton bouton--secondaire" type="submit" name="action" value="enregistrer"><?= e(t('pers.enregistrer')) ?></button>
            <button class="bouton bouton--fantome" type="submit" name="action" value="recalculer" formnovalidate><?= e(t('pers.recalculer')) ?></button>
            <button class="bouton" type="submit" name="action" value="valider" data-valider data-confirmer="<?= e(t('pers.valider_confirmer')) ?>"><?= e(t('pers.valider_bulletin')) ?></button>
        </div>
        <p class="aide"><?= e(t('pers.valider_aide')) ?></p>
    </section>
</form>
<?php endif; ?>

<?php if (!empty($b['dettes_retenues'])): ?>
    <section class="bloc" aria-labelledby="per-dettes-retenues">
        <h2 id="per-dettes-retenues"><?= e(t('pers.dettes_reduites')) ?></h2>
        <p class="aide"><?= e(t('pers.dettes_reduites_aide')) ?></p>
        <ul class="det-lignes">
            <?php foreach ($b['dettes_retenues'] as $d): ?>
                <li><span><?= e(t('pers.dette_du', ['date' => P::jour($d['dette_le'])])) ?><?= $d['annule'] ? ' <span class="badge badge--neutre">' . e(t('pers.retenue_annulee')) . '</span>' : '' ?></span><span class="montant"><?= e(P::montant($d['montant'], (int) $d['devise_id'])) ?></span></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<?php if ($b['paiements'] !== []): ?>
    <section class="bloc" aria-labelledby="per-paiements">
        <h2 id="per-paiements"><?= e(t('pers.paiements')) ?></h2>
        <ul class="det-lignes">
            <?php foreach ($b['paiements'] as $p): $m = []; ?>
                <li data-paiement-personnel="<?= (int) $p['id'] ?>">
                    <span><?= e(A::dateHeure($p['date_paiement'])) ?> · <?= e($p['utilisateur_nom'] ?? '?') ?>
                        <?php if ($p['statut'] === 'annule'): ?><span class="badge badge--erreur"><?= e(t('pers.paiement_annule_badge')) ?></span><?php endif; ?>
                        <?php foreach ($p['lignes'] as $l): ?>
                            <span class="tre-sous-texte"><?= $voirComptes ? '<a class="lien" href="/gestion/tresorerie/comptes/' . (int) $l['compte_id'] . '">' . e($l['compte_nom']) . '</a>' : e($l['compte_nom']) ?> · <?= e(P::montant($l['montant'], (int) $l['devise_id'])) ?></span>
                        <?php endforeach; ?></span>
                    <?php if ($p['statut'] === 'valide' && $peutPayer): ?>
                        <details class="per-annuler-paiement">
                            <summary class="lien"><?= e(t('pers.annuler_paiement')) ?></summary>
                            <form method="post" action="/gestion/personnel/paie/paiements/<?= (int) $p['id'] ?>/annuler" class="per-ligne-formulaire">
                                <input type="hidden" name="csrf" value="<?= $jeton ?>">
                                <div class="champ"><label for="motif-p-<?= (int) $p['id'] ?>"><?= e(t('pers.champ_motif')) ?></label><input id="motif-p-<?= (int) $p['id'] ?>" name="motif" maxlength="255" required></div>
                                <button class="bouton bouton--danger bouton--petit" type="submit"><?= e(t('pers.annuler_paiement_oui')) ?></button>
                            </form>
                        </details>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<?php if ($b['statut'] === 'valide' && $peutPayer && $reste !== []): ?>
    <section class="bloc" id="payer" aria-labelledby="per-payer">
        <h2 id="per-payer"><?= e(t('pers.payer_salaire')) ?></h2>
        <p class="aide"><?= e(t('pers.payer_aide', ['reste' => P::montants($reste)])) ?></p>
        <?php
        $champsAvant = '<div class="tre-paire"><div class="champ"><label for="date">' . e(t('tresorerie.champ_date')) . '</label><input type="datetime-local" id="date" name="date" value="'
            . e(date('Y-m-d\TH:i')) . '"' . $invalide('date') . '>' . $erreur('date') . '</div><div class="champ"><label for="note-paiement">' . e(t('pers.champ_note')) . ' <span class="tre-facultatif">'
            . e(t('tresorerie.facultatif')) . '</span></label><input id="note-paiement" name="note" maxlength="255"></div></div>';
        ?>
        <?= View::rendre('gestion/personnel/paie/_paiement-formulaire', [
            'action' => '/gestion/personnel/paie/' . $id . '/payer', 'lignes' => $lignesPaiement, 'comptes' => $comptes, 'devises' => $devises,
            'reference' => $reference, 'erreurs' => $erreurs, 'avertissements' => $avertissements, 'boutonTexte' => t('pers.payer'), 'champsAvant' => $champsAvant,
        ]) ?>
    </section>
<?php endif; ?>

<?php if ($b['statut'] === 'annule'): ?>
    <p class="bloc aide"><?= e(t('pers.bulletin_annule_par', ['nom' => $b['annule_par_nom'] ?? '?', 'date' => A::dateHeure($b['annule_le']), 'motif' => $b['motif_annulation']])) ?></p>
<?php elseif ($b['statut'] !== 'paye' || $b['paiements'] === []): ?>
    <section class="bloc">
        <details class="tre-supprimer"<?= isset($erreurs['motif']) || isset($erreurs['annulation']) ? ' open' : '' ?>>
            <summary class="lien"><?= e(t($brouillon ? 'pers.supprimer_brouillon' : 'pers.annuler_bulletin')) ?></summary>
            <p><?= e(t($brouillon ? 'pers.supprimer_brouillon_texte' : 'pers.annuler_bulletin_texte')) ?></p>
            <form method="post" action="/gestion/personnel/paie/<?= $id ?>/annuler" class="per-ligne-formulaire" novalidate>
                <input type="hidden" name="csrf" value="<?= $jeton ?>">
                <?php if (!$brouillon): ?>
                    <div class="champ"><label for="motif-annulation"><?= e(t('pers.champ_motif')) ?></label><input id="motif-annulation" name="motif" maxlength="255"<?= $invalide('motif') ?>><?= $erreur('motif') ?></div>
                <?php endif; ?>
                <button class="bouton bouton--danger" type="submit"><?= e(t($brouillon ? 'pers.supprimer_brouillon' : 'pers.annuler_bulletin')) ?></button>
            </form>
        </details>
    </section>
<?php endif; ?>
<script src="/assets/js/gestion-personnel.js" defer></script>
