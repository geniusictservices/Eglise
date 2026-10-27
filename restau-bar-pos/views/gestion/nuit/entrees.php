<?php
/**
 * Nuit > Billetterie : tarifs d'entrée (par défaut et par soirée, par catégorie), ce qui est en vente ce soir, comptes
 * et billets d'une nuit.
 * @var list<array> $etablissements @var array $etab @var list<array> $tarifs @var list<array> $nuits @var string $journee
 * @var list<array> $billets @var array $compteurs @var array $soiree @var list<array> $enVente @var list<array> $evenements
 * @var array<int, array> $acceptees @var bool $peutModifier @var bool $peutVendre @var ?string $flash @var ?string $erreur
 */
use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$desactive = $peutModifier ? '' : ' disabled';
$formateur = new App\Repositories\DeviseRepository();
$devisesCode = array_column($acceptees, null, 'code');
$statuts = ['valide' => 'badge--glace', 'utilise' => 'badge--succes', 'annule' => 'badge--neutre'];
$formulaire = static function (?array $t) use ($jeton, $etab, $evenements, $acceptees, $desactive): string {
    ob_start(); ?>
    <form class="sal-form" method="post" action="/gestion/nuit/entrees/tarifs<?= $t ? '/' . (int) $t['id'] : '' ?>">
        <input type="hidden" name="csrf" value="<?= $jeton ?>">
        <input type="hidden" name="etablissement" value="<?= (int) $etab['id'] ?>">
        <label class="champ"><span><?= e(t('gnuit.soiree')) ?></span>
            <select class="saisie" name="evenement_id"<?= $desactive ?>>
                <option value="0"><?= e(t('gnuit.tarif_defaut')) ?></option>
                <?php foreach ($evenements as $ev): ?><option value="<?= (int) $ev['id'] ?>" <?= $t && (int) $t['evenement_id'] === (int) $ev['id'] ? 'selected' : '' ?>><?= e($ev['titre'] . ' · ' . date('d/m', strtotime($ev['debut']))) ?></option><?php endforeach; ?>
            </select></label>
        <label class="champ"><span><?= e(t('gnuit.categorie')) ?></span>
            <select class="saisie" name="categorie"<?= $desactive ?>>
                <?php foreach (App\Repositories\BilletterieRepository::CATEGORIES as $c): ?><option value="<?= $c ?>" <?= ($t['categorie'] ?? 'normal') === $c ? 'selected' : '' ?>><?= e(t('nuit.categorie_' . $c)) ?></option><?php endforeach; ?>
            </select></label>
        <label class="champ"><span><?= e(t('gsalle.nom_fr')) ?></span><input class="saisie" name="nom_fr" maxlength="64" required value="<?= e($t['nom_fr'] ?? '') ?>"<?= $desactive ?>></label>
        <label class="champ"><span><?= e(t('gsalle.nom_en')) ?></span><input class="saisie" name="nom_en" maxlength="64" value="<?= e($t['nom_en'] ?? '') ?>"<?= $desactive ?>></label>
        <?= View::rendre('gestion/nuit/_montants', ['acceptees' => $acceptees, 'valeurs' => $t['montants'] ?? [], 'id' => 'tarif-' . ($t['id'] ?? 'nouveau'), 'desactive' => $desactive !== '']) ?>
        <label class="champ"><span><?= e(t('gnuit.fin_vente')) ?></span><input class="saisie" type="datetime-local" name="fin_vente" value="<?= $t && $t['fin_vente'] ? e(date('Y-m-d\TH:i', strtotime($t['fin_vente']))) : '' ?>"<?= $desactive ?>></label>
        <?php if ($t): ?><label class="coche"><input type="checkbox" name="actif" value="1" <?= $t['actif'] ? 'checked' : '' ?><?= $desactive ?>> <span><?= e(t('gsalle.actif')) ?></span></label><?php endif; ?>
        <?php if (!$desactive): ?>
            <div class="sal-form__actions">
                <button class="bouton" type="submit"><?= e(t($t ? 'gsalle.enregistrer' : 'gnuit.tarif_ajouter')) ?></button>
                <?php if ($t): ?><button class="bouton bouton--danger" type="submit" formaction="/gestion/nuit/entrees/tarifs/<?= (int) $t['id'] ?>/supprimer" formnovalidate><?= e(t('gsalle.supprimer')) ?></button><?php endif; ?>
            </div>
        <?php endif; ?>
    </form>
    <?php return (string) ob_get_clean();
};
?>
<link rel="stylesheet" href="/assets/css/gestion-salle.css">
<link rel="stylesheet" href="/assets/css/gestion-nuit.css">
<div class="sal-entete">
    <div>
        <h1><?= e(t('gnuit.entrees_titre')) ?></h1>
        <p class="aide"><?= e(t('gnuit.entrees_aide')) ?></p>
    </div>
    <?php if ($peutVendre): ?><a class="bouton" href="/caisse/entree?e=<?= e($etab['slug']) ?>"><?= e(t('gnuit.ouvrir_entree')) ?></a><?php endif; ?>
</div>
<?php if ($flash): ?><p class="alerte" role="status"><?= e($flash) ?></p><?php endif; ?>
<?php if ($erreur): ?><p class="alerte alerte--erreur" role="alert"><?= e($erreur) ?></p><?php endif; ?>
<?= View::rendre('gestion/salle/_etablissements', ['etablissements' => $etablissements, 'etab' => $etab, 'garder' => []]) ?>

<section class="bloc" aria-labelledby="nuit-ce-soir">
    <h2 id="nuit-ce-soir"><?= e(t('gnuit.ce_soir', ['date' => date('d/m/Y', strtotime($soiree['journee']))])) ?><?= $soiree['evenement'] ? ' · ' . e($soiree['evenement']['titre']) : '' ?></h2>
    <?php if ($enVente === []): ?>
        <p class="aide"><?= e(t('gnuit.rien_en_vente')) ?></p>
    <?php else: ?>
        <ul class="nuit-tarifs">
            <?php foreach ($enVente as $t): ?>
                <li><span class="badge badge--glace"><?= e(t('nuit.categorie_' . $t['categorie'])) ?></span> <strong><?= e($t['nom']) ?></strong>
                    <span class="montant"><?= e(implode(' · ', array_filter(array_map(static fn ($c, $m) => $m === null ? null : $formateur->formater((float) $m, $devisesCode[$c]), array_keys($t['prix']), $t['prix'])))) ?></span>
                    <?php if ($t['fin_vente']): ?><span class="aide"><?= e(t('gnuit.jusqu_a', ['heure' => date('d/m H:i', strtotime($t['fin_vente']))])) ?></span><?php endif; ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="bloc" aria-labelledby="nuit-tarifs">
    <h2 id="nuit-tarifs"><?= e(t('gnuit.tarifs')) ?></h2>
    <p class="aide"><?= e(t('gnuit.tarifs_aide')) ?></p>
    <ul class="sal-tables">
        <?php foreach ($tarifs as $t): ?>
            <li class="sal-ligne" id="tarif-<?= (int) $t['id'] ?>">
                <details class="sal-details">
                    <summary class="sal-details__resume">
                        <span class="badge <?= $t['actif'] ? 'badge--glace' : 'badge--neutre' ?>"><?= e(t('nuit.categorie_' . $t['categorie'])) ?></span>
                        <strong><?= e(champ($t, 'nom')) ?></strong>
                        <span class="sal-sous">· <?= e($t['evenement_titre'] ? $t['evenement_titre'] . ' ' . date('d/m', strtotime($t['evenement_debut'])) : t('gnuit.tarif_defaut')) ?></span>
                        <span class="montant">· <?= View::rendre('gestion/nuit/_prix', ['montants' => $t['montants'], 'devises' => $acceptees]) ?></span>
                        <?php if (!$t['actif']): ?><span class="sal-sous">· <?= e(t('gnuit.inactif')) ?></span><?php endif; ?>
                    </summary>
                    <?= $formulaire($t) ?>
                </details>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php if ($peutModifier): ?>
        <h3><?= e(t('gnuit.tarif_ajouter')) ?></h3>
        <?= $formulaire(null) ?>
    <?php endif; ?>
</section>

<div class="nuit-grille">
    <section class="bloc" aria-labelledby="nuit-nuits">
        <h2 id="nuit-nuits"><?= e(t('gnuit.nuits')) ?></h2>
        <div class="defile">
            <table class="tableau">
                <thead><tr><th><?= e(t('gnuit.nuit')) ?></th><th class="nombre"><?= e(t('gnuit.billets')) ?></th><th class="nombre"><?= e(t('gnuit.entres')) ?></th></tr></thead>
                <tbody>
                    <?php foreach ($nuits as $n): ?>
                        <tr<?= $n['journee'] === $journee ? ' aria-current="true"' : '' ?>><td><a href="?etablissement=<?= (int) $etab['id'] ?>&amp;journee=<?= e($n['journee']) ?>"><?= e(date('d/m/Y', strtotime($n['journee']))) ?></a></td>
                            <td class="nombre"><?= (int) $n['billets'] ?></td><td class="nombre"><?= (int) $n['entres'] ?></td></tr>
                    <?php endforeach; ?>
                    <?php if ($nuits === []): ?><tr><td colspan="3"><?= e(t('gnuit.aucun_billet')) ?></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <section class="bloc" aria-labelledby="nuit-compteurs">
        <h2 id="nuit-compteurs"><?= e(t('gnuit.nuit_du', ['date' => date('d/m/Y', strtotime($journee))])) ?></h2>
        <div class="chiffres">
            <div class="chiffre"><span class="chiffre__libelle"><?= e(t('gnuit.billets')) ?></span><strong class="chiffre__valeur"><?= (int) $compteurs['vendus'] ?></strong></div>
            <div class="chiffre"><span class="chiffre__libelle"><?= e(t('gnuit.entres')) ?></span><strong class="chiffre__valeur"><?= (int) $compteurs['entres'] ?></strong></div>
            <div class="chiffre"><span class="chiffre__libelle"><?= e(t('gnuit.invites_entres')) ?></span><strong class="chiffre__valeur"><?= (int) $compteurs['invites'] ?></strong></div>
            <div class="chiffre"><span class="chiffre__libelle"><?= e(t('gnuit.refus')) ?></span><strong class="chiffre__valeur"><?= (int) $compteurs['refus'] ?></strong></div>
        </div>
        <ul class="nuit-tarifs">
            <?php foreach ($compteurs['categories'] as $c => $n): if ($n['vendus'] === 0) { continue; } ?>
                <li><?= e(t('nuit.categorie_' . $c)) ?> : <?= e(t('gnuit.vendus_entres', ['vendus' => $n['vendus'], 'entres' => $n['entres']])) ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
</div>

<section class="bloc" aria-labelledby="nuit-billets">
    <h2 id="nuit-billets"><?= e(t('gnuit.billets_nuit')) ?></h2>
    <div class="defile">
        <table class="tableau">
            <thead><tr><th><?= e(t('gnuit.code')) ?></th><th><?= e(t('gnuit.categorie')) ?></th><th class="nombre"><?= e(t('gnuit.prix')) ?></th><th><?= e(t('gnuit.statut')) ?></th><th><?= e(t('gnuit.vente')) ?></th><th><?= e(t('gnuit.entre_le')) ?></th></tr></thead>
            <tbody>
                <?php foreach ($billets as $b): ?>
                    <tr>
                        <td><code><?= e(App\Core\CodeQr::code('B', (int) $b['id'], $b['jeton'])) ?></code></td>
                        <td><?= e(t('nuit.categorie_' . $b['categorie'])) ?><?= $b['tarif_fr'] ? ' · ' . e(champ($b, 'tarif')) : '' ?><?= $b['invite_nom'] ? '<br><small>' . e(t('gnuit.invite_de', ['nom' => $b['invite_nom']])) . '</small>' : '' ?></td>
                        <td class="nombre"><?= $b['devise_id'] !== null && isset($acceptees[(int) $b['devise_id']]) ? e($formateur->formater((float) $b['prix'], $acceptees[(int) $b['devise_id']])) : '—' ?></td>
                        <td><span class="badge <?= $statuts[$b['statut']] ?>"><?= e(t('gnuit.billet_' . $b['statut'])) ?></span></td>
                        <td><?= $b['numero'] ? '<a href="/gestion/ventes/' . (int) $b['vente_id'] . '">' . e($b['numero']) . '</a>' : '—' ?></td>
                        <td><?= $b['utilise_le'] ? e(date('H:i', strtotime($b['utilise_le']))) . ($b['utilise_par_nom'] ? ' · ' . e($b['utilise_par_nom']) : '') : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($billets === []): ?><tr><td colspan="6"><?= e(t('gnuit.aucun_billet')) ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
