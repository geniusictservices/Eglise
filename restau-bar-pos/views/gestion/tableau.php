<?php
/**
 * Tableau de bord de la direction : chiffres de la période (comparés à la période précédente), CA jour par jour,
 * par service et par établissement, produits, créances et dettes fournisseurs, serveuse ou serveur de la semaine,
 * présence ; puis le résumé d'origine (établissements, produits, scans, devises et taux).
 * @var array $utilisateur
 * @var array $periode
 * @var list<string> $periodes
 * @var array $bornes du, au, jours
 * @var array $precedente du, au
 * @var ?int $etablissement
 * @var array $etablissements
 * @var array<int, string> $nomsEtablissements
 * @var ?array $locale
 * @var ?array $reference
 * @var float $taux
 * @var array $totaux
 * @var array $totauxAvant
 * @var array $depenses
 * @var array $depensesAvant
 * @var ?array $salaires
 * @var ?array $salairesAvant
 * @var float $resultat
 * @var float $resultatAvant
 * @var bool $voirMarges
 * @var bool $margesPermises
 * @var array<string, array> $jours
 * @var array<string, array> $parService
 * @var array<string, array> $parEtablissement
 * @var list<array> $rentables
 * @var bool $avecMarge
 * @var list<array> $moinsVendus
 * @var array $creances
 * @var ?array $fournisseurs
 * @var ?array $vedette
 * @var array $semaine
 * @var ?array $presence
 * @var array<int, array> $devises
 * @var array $liens
 * @var int $nbProduits
 * @var int $scans7j
 * @var array $tousEtablissements
 * @var array $devisesActives
 */

use App\Controllers\Gestion\DetteAffichage as D;
use App\Controllers\Gestion\RapportAffichage as R;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$enLocale = static fn (float $m): string => $locale ? A::montant($m, $locale) : '';
$enRef = static fn (float $m): string => $reference ? A::montant($m, $reference) : '';
$double = static fn (float $l, float $r, bool $approx = false): string => R::double($l, $r, $locale, $reference, $approx);
$depuisRef = static fn (float $r): string => R::double($r * $taux, $r, $locale, $reference, true);
$texteperiode = R::periode($bornes['du'], $bornes['au']);
// Écart avec la période précédente : badge avec le signe (la couleur dit si c'est bon ou mauvais, le texte aussi)
$ecart = static function (float $actuel, float $avant, bool $hausseBonne = true): string {
    $part = R::ecart($actuel, $avant);
    if ($part === null) {
        return '<span class="rap-ecart rap-ecart--neutre">' . e(t('rapports.sans_comparaison')) . '</span>';
    }
    $bon = $hausseBonne ? $part >= 0 : $part <= 0;
    $fleche = $part > 0.0005 ? '↑' : ($part < -0.0005 ? '↓' : '→');
    return '<span class="rap-ecart ' . ($bon ? 'rap-ecart--bon' : 'rap-ecart--mauvais') . '">'
        . '<span aria-hidden="true">' . $fleche . '</span> ' . e(R::pourcentSigne($part))
        . '<span class="visuellement-cache"> ' . e(t('rapports.vs_precedente')) . '</span></span>';
};
$garder = ['etablissement' => $etablissement];
$ca = $totaux['ca_locale'];
$depensesTotal = $depenses['reference'] + ($salaires['reference'] ?? 0.0);
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-rapports.css">

<div class="tre-entete rap-entete">
    <div>
        <p class="surtitre"><?= e(t('gestion.tableau')) ?></p>
        <h1><?= e(t('gestion.bonjour', ['nom' => $utilisateur['nom']])) ?></h1>
        <p class="aide"><?= e(t('rtableau.aide', ['periode' => $texteperiode])) ?></p>
    </div>
    <?= View::rendre('gestion/rapports/_exporter', ['titre' => t('gestion.tableau'), 'periode' => $texteperiode]) ?>
</div>

<section class="bloc tre-filtres rap-filtres" aria-label="<?= e(t('rapports.filtres')) ?>">
    <?= View::rendre('gestion/tresorerie/_periode', ['periode' => $periode, 'periodes' => $periodes, 'action' => '/gestion/tableau-de-bord', 'garder' => $garder]) ?>
    <?php if (count($etablissements) > 1): ?>
        <nav class="tre-puces" aria-label="<?= e(t('gestion.etablissement')) ?>">
            <a class="puce" href="<?= e(urlAvec(['etablissement' => null])) ?>"<?= !$etablissement ? ' aria-current="true"' : '' ?>><?= e(t('gventes.tous_etablissements')) ?></a>
            <?php foreach ($etablissements as $etab): ?>
                <a class="puce" href="<?= e(urlAvec(['etablissement' => (int) $etab['id']])) ?>"<?= $etablissement === (int) $etab['id'] ? ' aria-current="true"' : '' ?>><?= e($etab['nom']) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
    <p class="aide rap-journee"><?= e(t('rapports.journee_aide')) ?></p>
</section>

<section class="rap-kpis" aria-labelledby="rt-chiffres">
    <h2 class="visuellement-cache" id="rt-chiffres"><?= e(t('rtableau.chiffres')) ?></h2>
    <div class="rap-kpi" data-kpi="ca">
        <span class="rap-kpi__libelle"><?= e(t('rtableau.ca')) ?></span>
        <strong class="rap-kpi__valeur montant"><?= e($enLocale($ca)) ?></strong>
        <span class="rap-kpi__second montant"><?= e($enRef($totaux['ca_reference'])) ?></span>
        <span class="rap-kpi__pied"><?= $ecart($ca, $totauxAvant['ca_locale']) ?> <span><?= e(t('rtableau.tickets_panier', ['n' => $totaux['tickets'], 'panier' => $enLocale($totaux['panier_locale'])])) ?></span></span>
    </div>
    <div class="rap-kpi" data-kpi="encaisse">
        <span class="rap-kpi__libelle"><?= e(t('rtableau.encaisse')) ?></span>
        <strong class="rap-kpi__valeur montant"><?= e($enLocale($totaux['encaisse_locale'])) ?></strong>
        <span class="rap-kpi__second montant"><?= e($enRef($totaux['encaisse_part_reference'])) ?></span>
        <span class="rap-kpi__pied">
            <?php if ($ca > 0): ?><span class="badge badge--neutre"><?= e(t('rtableau.du_ca', ['part' => R::pourcent($totaux['encaisse_locale'] / $ca)])) ?></span><?php endif; ?>
            <span data-credit="<?= e((string) round($totaux['credit_locale'])) ?>"><?= e(t('rtableau.dont_credit', ['montant' => $enLocale($totaux['credit_locale'])])) ?></span>
        </span>
    </div>
    <?php if ($voirMarges): ?>
        <div class="rap-kpi" data-kpi="marge">
            <span class="rap-kpi__libelle"><?= e(t('rtableau.marge')) ?></span>
            <strong class="rap-kpi__valeur montant"><?= e($enRef($totaux['marge_reference'])) ?></strong>
            <span class="rap-kpi__second montant">≈ <?= e($enLocale($totaux['marge_reference'] * $taux)) ?></span>
            <span class="rap-kpi__pied">
                <?= $ecart($totaux['marge_reference'], $totauxAvant['marge_reference']) ?>
                <?php if ($totaux['ca_reference'] > 0): ?><span><?= e(t('rtableau.taux_marge', ['part' => R::pourcent($totaux['marge_reference'] / $totaux['ca_reference'])])) ?></span><?php endif; ?>
                <?php if ($totaux['couverture'] < 0.995 && $totaux['ca_reference'] > 0): ?><span class="rap-note"><?= e(t('rtableau.couverture', ['part' => R::pourcent($totaux['couverture'])])) ?></span><?php endif; ?>
            </span>
        </div>
    <?php elseif ($margesPermises): ?>
        <div class="rap-kpi rap-kpi--vide" data-kpi="marge">
            <span class="rap-kpi__libelle"><?= e(t('rtableau.marge')) ?></span>
            <strong class="rap-kpi__valeur">—</strong>
            <span class="rap-kpi__pied"><span class="rap-note"><?= e(t('rtableau.marge_sans_cout')) ?></span></span>
        </div>
    <?php endif; ?>
    <div class="rap-kpi" data-kpi="depenses">
        <span class="rap-kpi__libelle"><?= e($salaires !== null ? t('rtableau.depenses_salaires') : t('rtableau.depenses')) ?></span>
        <strong class="rap-kpi__valeur montant">≈ <?= e($enLocale($depensesTotal * $taux)) ?></strong>
        <span class="rap-kpi__second montant"><?= e($enRef($depensesTotal)) ?></span>
        <span class="rap-kpi__pied">
            <?= $ecart($depensesTotal, $depensesAvant['reference'] + ($salairesAvant['reference'] ?? 0.0), false) ?>
            <span><?= e(t('rtableau.n_depenses', ['n' => $depenses['nombre']])) ?><?php if ($salaires !== null): ?> · <?= e(t('rtableau.salaires_montant', ['montant' => $enRef($salaires['reference'])])) ?><?php endif; ?></span>
        </span>
    </div>
    <div class="rap-kpi rap-kpi--resultat<?= $resultat < 0 ? ' rap-kpi--negatif' : '' ?>" data-kpi="resultat">
        <span class="rap-kpi__libelle"><?= e(t('rtableau.resultat')) ?></span>
        <strong class="rap-kpi__valeur montant">≈ <?= e($enLocale($resultat * $taux)) ?></strong>
        <span class="rap-kpi__second montant" data-reference="<?= e((string) round($resultat, 2)) ?>"><?= e($enRef($resultat)) ?></span>
        <span class="rap-kpi__pied">
            <?= $ecart($resultat, $resultatAvant) ?>
            <span class="rap-note"><?= e($voirMarges ? t('rtableau.resultat_aide_marge') : t('rtableau.resultat_aide_ca')) ?></span>
        </span>
    </div>
</section>
<p class="aide rap-comparaison"><?= e(t('rtableau.comparaison', ['periode' => R::periode($precedente['du'], $precedente['au'])])) ?> <?= e(t('rtableau.taux_note')) ?></p>

<?php if ($totaux['tickets'] === 0 && $totaux['annulees'] === 0): ?>
    <p class="bloc aide" data-vide="ventes"><?= e(t('rtableau.aucune_vente')) ?></p>
<?php endif; ?>

<?php if (count($jours) > 1): ?>
    <section class="bloc" aria-labelledby="rt-jours">
        <h2 id="rt-jours"><?= e(t('rtableau.par_jour')) ?></h2>
        <?php
        $etiquettes = array_map([R::class, 'jourCourt'], array_keys($jours));
        $infos = [];
        foreach ($jours as $jour => $t) {
            $infos[] = R::jourCourt($jour) . ' · ' . t('rtableau.ca') . ' ' . $enLocale($t['ca_locale']) . ' · ' . t('rtableau.encaisse') . ' ' . $enLocale($t['encaisse_locale'])
                . ' · ' . t('rtableau.credit') . ' ' . $enLocale($t['credit_locale']) . ' · ' . t('rapports.n_tickets', ['n' => $t['tickets']]);
        }
        echo View::rendre('gestion/rapports/_barres', [
            'id' => 'graphique-jours',
            'titre' => t('rtableau.graphique_jours', ['code' => $locale['code'] ?? '']),
            'etiquettes' => $etiquettes,
            'etiquettesLongues' => array_map(static fn (string $j) => A::jour($j), array_keys($jours)),
            'series' => [
                ['nom' => t('rtableau.encaisse'), 'valeurs' => array_values(array_map(static fn (array $t) => $t['encaisse_locale'], $jours))],
                ['nom' => t('rtableau.credit'), 'valeurs' => array_values(array_map(static fn (array $t) => $t['credit_locale'], $jours))],
            ],
            'formater' => static fn (float $m): string => R::compact($m, $locale),
            'infos' => $infos,
        ]);
        ?>
    </section>
<?php endif; ?>

<div class="rap-grille">
    <?php if ($parService !== [] && !(count($parService) === 1 && array_key_first($parService) === '')): ?>
        <section class="bloc" aria-labelledby="rt-services">
            <h2 id="rt-services"><?= e(t('rtableau.par_service')) ?></h2>
            <p class="aide"><?= e(t('rtableau.par_service_aide')) ?></p>
            <div class="rap-defile">
                <table class="tableau rap-tableau" data-tableau="services">
                    <thead><tr><th scope="col"><?= e(t('rtableau.service')) ?></th><th scope="col" class="nombre"><?= e(t('rapports.col_tickets')) ?></th><th scope="col" class="nombre"><?= e(t('rtableau.ca')) ?></th><th scope="col"><?= e(t('rapports.col_part')) ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($parService as $nom => $t): ?>
                            <tr>
                                <th scope="row"><?= e($nom !== '' ? $nom : t('rtableau.hors_service')) ?></th>
                                <td class="nombre"><?= (int) $t['tickets'] ?></td>
                                <td class="nombre"><span class="montant"><?= e($enLocale($t['ca_locale'])) ?></span><span class="rap-sous montant"><?= e($enRef($t['ca_reference'])) ?></span></td>
                                <td class="rap-part"><?php $part = $ca > 0 ? $t['ca_locale'] / $ca : 0; ?><span class="rap-jauge" aria-hidden="true"><span style="width: <?= round($part * 100, 1) ?>%"></span></span><span class="nombre"><?= e(R::pourcent($part)) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <section class="bloc rap-vedette" aria-labelledby="rt-vedette">
        <h2 id="rt-vedette"><?= e(t('rtableau.vedette')) ?></h2>
        <p class="aide"><?= e(R::periode($semaine['du'], $semaine['au'])) ?></p>
        <?php if ($vedette === null): ?>
            <p class="aide"><?= e(t('rtableau.vedette_aucune')) ?></p>
        <?php else: $nom = $vedette['personne']['nom'] ?? t('gventes.vendeur_inconnu'); ?>
            <div class="rap-vedette__corps" data-vedette="<?= (int) $vedette['utilisateur_id'] ?>">
                <?= View::rendre('partials/_avatar', ['nom' => $nom, 'photo' => $vedette['personne']['avatar'] ?? null, 'classe' => 'avatar--grand']) ?>
                <div>
                    <p class="rap-vedette__nom"><?= e($nom) ?> <span class="badge badge--braise"><?= e(t('rtableau.vedette_badge')) ?></span></p>
                    <p class="rap-vedette__chiffre montant" data-cle="ca"><?= e($enLocale($vedette['retenu_locale'])) ?> <span class="rap-sous"><?= e($enRef($vedette['retenu_reference'])) ?></span></p>
                    <p class="aide"><?= e(t('rtableau.vedette_detail', ['n' => $vedette['tickets'], 'panier' => $enLocale($vedette['panier_retenu'])])) ?></p>
                    <p><?= $ecart($vedette['retenu_locale'], $vedette['avant']) ?>
                        <span class="aide"><?= e(t('rtableau.vedette_avant', ['montant' => $enLocale($vedette['avant'])])) ?></span></p>
                </div>
            </div>
            <p><a class="lien rap-lien" href="/gestion/rapports/personnel?periode=7j<?= $etablissement ? '&amp;etablissement=' . (int) $etablissement : '' ?>"><?= e(t('rtableau.voir_classement')) ?></a></p>
        <?php endif; ?>
    </section>
</div>

<?php if ($etablissement === null && count($etablissements) > 1): ?>
    <section class="bloc" aria-labelledby="rt-etablissements">
        <h2 id="rt-etablissements"><?= e(t('rtableau.par_etablissement')) ?></h2>
        <div class="rap-defile">
            <table class="tableau rap-tableau" data-tableau="etablissements">
                <thead><tr>
                    <th scope="col"><?= e(t('gestion.etablissement')) ?></th>
                    <th scope="col" class="nombre"><?= e(t('rtableau.ca')) ?></th>
                    <th scope="col" class="nombre"><?= e(t('rtableau.credit')) ?></th>
                    <th scope="col" class="nombre"><?= e(t('rtableau.depenses')) ?></th>
                    <th scope="col" class="nombre"><?= e(t('rtableau.resultat')) ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($etablissements as $etab): $id = (int) $etab['id']; $t = $parEtablissement[(string) $id] ?? App\Repositories\RapportRepository::completer(App\Repositories\RapportRepository::vide());
                        $dep = ($depenses['etablissements'][(string) $id] ?? 0.0) + ($salaires['etablissements'][(string) $id] ?? 0.0);
                        $res = ($voirMarges ? $t['marge_reference'] : $t['ca_reference']) - $dep; ?>
                        <tr data-etablissement="<?= $id ?>">
                            <th scope="row"><a href="<?= e(urlAvec(['etablissement' => $id])) ?>"><?= e($etab['nom']) ?></a></th>
                            <td class="nombre"><span class="montant" data-cle="ca"><?= e($enLocale($t['ca_locale'])) ?></span><span class="rap-sous montant"><?= e($enRef($t['ca_reference'])) ?></span></td>
                            <td class="nombre montant"><?= e($enLocale($t['credit_locale'])) ?></td>
                            <td class="nombre montant"><?= e($enRef($dep)) ?></td>
                            <td class="nombre montant<?= $res < 0 ? ' tre-negatif' : '' ?>"><?= e($enRef($res)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (isset($depenses['etablissements']['commun'])): ?>
                        <tr class="rap-ligne-douce">
                            <th scope="row"><?= e(t('rtableau.depenses_communes')) ?></th>
                            <td class="nombre">—</td><td class="nombre">—</td>
                            <td class="nombre montant"><?= e($enRef($depenses['etablissements']['commun'])) ?></td>
                            <td class="nombre montant tre-negatif"><?= e($enRef(-$depenses['etablissements']['commun'])) ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<div class="rap-grille">
    <section class="bloc" aria-labelledby="rt-rentables">
        <h2 id="rt-rentables"><?= e($avecMarge ? t('rtableau.plus_rentables') : t('rtableau.plus_vendus_ca')) ?></h2>
        <?php if (!$avecMarge): ?><p class="aide"><?= e($margesPermises ? t('rtableau.rentables_sans_cout') : t('rtableau.rentables_sans_droit')) ?></p><?php endif; ?>
        <?php if ($rentables === []): ?>
            <p class="aide"><?= e(t('rtableau.aucun_produit')) ?></p>
        <?php else: $maxP = max(array_map(static fn (array $p) => $avecMarge ? max(0.0, (float) $p['marge']) : $p['locale'], $rentables)) ?: 1; ?>
            <ol class="rap-classement" data-liste="rentables">
                <?php foreach ($rentables as $p): $valeur = $avecMarge ? (float) $p['marge'] : $p['locale']; ?>
                    <li data-produit="<?= (int) $p['produit_id'] ?>">
                        <span class="rap-classement__nom"><?= e($p['nom']) ?></span>
                        <span class="rap-jauge" aria-hidden="true"><span style="width: <?= round(max(0, $valeur) / $maxP * 100, 1) ?>%"></span></span>
                        <span class="rap-classement__chiffres">
                            <strong class="montant"><?= e($avecMarge ? $enRef($valeur) : $enLocale($valeur)) ?></strong>
                            <span class="rap-sous"><?= e(t('rapports.quantite', ['n' => R::nombre($p['quantite'])])) ?><?php if ($avecMarge && $p['reference'] > 0): ?> · <?= e(t('rtableau.taux_marge', ['part' => R::pourcent($valeur / $p['reference'])])) ?><?php endif; ?></span>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>
    <section class="bloc" aria-labelledby="rt-moins">
        <h2 id="rt-moins"><?= e(t('rtableau.moins_vendus')) ?></h2>
        <p class="aide"><?= e(t('rtableau.moins_vendus_aide')) ?></p>
        <?php if ($moinsVendus === []): ?>
            <p class="aide"><?= e(t('rtableau.aucun_produit')) ?></p>
        <?php else: ?>
            <ul class="rap-liste-simple" data-liste="moins">
                <?php foreach ($moinsVendus as $p): ?>
                    <li data-produit="<?= (int) $p['produit_id'] ?>">
                        <span><?= e($p['nom']) ?></span>
                        <span class="<?= $p['quantite'] <= 0 ? 'badge badge--neutre' : 'nombre' ?>" data-cle="quantite"><?= $p['quantite'] <= 0 ? e(t('rtableau.aucune_vente_produit')) : e(t('rapports.quantite', ['n' => R::nombre($p['quantite'])])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<div class="rap-grille">
    <section class="bloc" aria-labelledby="rt-creances">
        <h2 id="rt-creances"><?= e(t('rtableau.creances')) ?></h2>
        <?php $tc = $creances['total']; ?>
        <?php if ($creances['nb'] === 0): ?>
            <p class="aide"><?= e(t('rcreances.aucune')) ?></p>
        <?php else: ?>
            <p class="rap-gros montant" data-cle="creances"><?= e($depuisRef($tc['reference'])) ?></p>
            <p class="aide"><?= e(D::montants($tc['devises'], $devises)) ?> · <?= e(t('rtableau.n_dettes', ['n' => $creances['nb']])) ?></p>
            <dl class="rap-anciennete">
                <?php foreach (App\Repositories\GestionDetteRepository::TRANCHES as $tranche): $g = $creances['tranches'][$tranche]; $part = $tc['reference'] > 0 ? $g['reference'] / $tc['reference'] : 0; ?>
                    <div data-tranche="<?= e($tranche) ?>">
                        <dt><?= e(t('gdettes.tranche_' . $tranche)) ?></dt>
                        <dd><span class="rap-jauge" aria-hidden="true"><span style="width: <?= round($part * 100, 1) ?>%"></span></span><span class="montant"><?= e($enRef($g['reference'])) ?></span></dd>
                    </div>
                <?php endforeach; ?>
                <div class="rap-anciennete__retard"><dt><?= e(t('rtableau.en_retard')) ?></dt><dd><span class="montant"><?= e($enRef($creances['retard']['reference'])) ?></span></dd></div>
            </dl>
        <?php endif; ?>
        <?php if ($liens['creances']): ?><p><a class="lien rap-lien" href="/gestion/rapports/creances<?= $etablissement ? '?etablissement=' . (int) $etablissement : '' ?>"><?= e(t('rtableau.voir_creances')) ?></a></p><?php endif; ?>
    </section>

    <?php if ($fournisseurs !== null): ?>
        <section class="bloc" aria-labelledby="rt-fournisseurs" data-bloc="fournisseurs">
            <h2 id="rt-fournisseurs"><?= e(t('rtableau.dettes_fournisseurs')) ?></h2>
            <?php if ($fournisseurs['nombre'] === 0): ?>
                <p class="aide"><?= e(t('rtableau.fournisseurs_aucune')) ?></p>
            <?php else: ?>
                <p class="rap-gros montant"><?= e($depuisRef($fournisseurs['reference'])) ?></p>
                <p class="aide"><?= e(D::montants($fournisseurs['devises'], $devises)) ?></p>
                <p><?= e(t('rtableau.fournisseurs_detail', ['n' => $fournisseurs['nombre'], 'retard' => $fournisseurs['en_retard']])) ?></p>
            <?php endif; ?>
            <?php if ($liens['fournisseurs']): ?><p><a class="lien rap-lien" href="/gestion/achats/dettes"><?= e(t('rtableau.voir_fournisseurs')) ?></a></p><?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($presence !== null): ?>
        <section class="bloc" aria-labelledby="rt-presence" data-bloc="presence">
            <h2 id="rt-presence"><?= e(t('rtableau.presence')) ?></h2>
            <dl class="rap-infos">
                <div><dt><?= e(t('rtableau.presents_maintenant')) ?></dt><dd data-cle="maintenant"><?= (int) $presence['maintenant'] ?></dd></div>
                <div><dt><?= e(t('rtableau.personnes_venues')) ?></dt><dd><?= (int) $presence['personnes'] ?></dd></div>
                <div><dt><?= e(t('rtableau.heures')) ?></dt><dd><?= e(R::nombre($presence['heures'], 1)) ?> h</dd></div>
                <?php if ($presence['retards'] !== null): ?><div><dt><?= e(t('rtableau.retards')) ?></dt><dd><?= (int) $presence['retards'] ?></dd></div><?php endif; ?>
                <?php if ($presence['absences'] !== null): ?><div><dt><?= e(t('rtableau.absences')) ?></dt><dd><?= (int) $presence['absences'] ?></dd></div><?php endif; ?>
            </dl>
            <?php if ($liens['presence']): ?><p><a class="lien rap-lien" href="/gestion/personnel/presence"><?= e(t('rtableau.voir_presence')) ?></a></p><?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($depenses['categories'] !== []): ?>
        <section class="bloc" aria-labelledby="rt-depenses">
            <h2 id="rt-depenses"><?= e(t('rtableau.depenses_categories')) ?></h2>
            <?php $maxD = max(array_column($depenses['categories'], 'reference')) ?: 1; ?>
            <ul class="rap-liste-simple">
                <?php foreach (array_slice($depenses['categories'], 0, 6, true) as $c): ?>
                    <li><span><?= e($c['nom']) ?></span><span class="montant"><?= e($enRef($c['reference'])) ?></span></li>
                <?php endforeach; ?>
            </ul>
            <?php if ($liens['depenses']): ?><p><a class="lien rap-lien" href="/gestion/depenses?du=<?= e($bornes['du']) ?>&amp;au=<?= e($bornes['au']) ?>"><?= e(t('rtableau.voir_depenses')) ?></a></p><?php endif; ?>
        </section>
    <?php endif; ?>
</div>

<details class="bloc rap-bref">
    <summary><h2><?= e(t('rtableau.en_bref')) ?></h2></summary>
    <div class="chiffres">
        <div class="chiffre"><span class="chiffre__valeur"><?= count($tousEtablissements) ?></span><span class="chiffre__libelle"><?= e(t('gestion.etablissements')) ?></span></div>
        <div class="chiffre"><span class="chiffre__valeur"><?= $nbProduits ?></span><span class="chiffre__libelle"><?= e(t('gestion.produits')) ?></span></div>
        <div class="chiffre"><span class="chiffre__valeur"><?= $scans7j ?></span><span class="chiffre__libelle"><?= e(t('gestion.scans_7j')) ?></span></div>
    </div>
    <h3><?= e(t('gestion.etablissements')) ?></h3>
    <div class="rap-defile">
        <table class="tableau">
            <thead><tr><th><?= e(t('gestion.nom_colonne')) ?></th><th><?= e(t('gestion.type')) ?></th><th><?= e(t('gestion.page_publique')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($tousEtablissements as $etab): ?>
                <tr>
                    <td><?= e($etab['nom']) ?></td>
                    <td><?= e(t('type.' . $etab['type'])) ?></td>
                    <td><a href="/e/<?= e($etab['slug']) ?>" target="_blank" rel="noopener">/e/<?= e($etab['slug']) ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <h3><?= e(t('gestion.devises')) ?></h3>
    <div class="rap-defile">
        <table class="tableau">
            <thead><tr><th><?= e(t('gestion.devise')) ?></th><th><?= e(t('gestion.taux')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($devisesActives as $devise): ?>
                <tr>
                    <td><?= e($devise['code']) ?> · <?= e(champ($devise, 'nom')) ?>
                        <?php if ($devise['est_reference']): ?><span class="pastille"><?= e(t('gestion.reference')) ?></span><?php endif; ?></td>
                    <td class="nombre">
                        <?php if ($devise['est_reference'] || !$reference): ?>—
                        <?php elseif ($devise['taux'] === null): ?><span class="pastille pastille--alerte"><?= e(t('gestion.taux_manquant')) ?></span>
                        <?php else: ?>1 <?= e($reference['code']) ?> = <?= e(rtrim(rtrim(number_format((float) $devise['taux'], 6, '.', ''), '0'), '.')) ?> <?= e($devise['code']) ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="aide"><?= e(t('gestion.devises_aide')) ?></p>
</details>
