<?php
/**
 * Rapports > Personnel : serveuse ou serveur de la période (classement, critère, option crédit remboursé,
 * comparaison avec la période précédente, évolution jour par jour) et dettes des clients liées aux utilisateurs.
 * Sans rapports.voir, la personne ne voit que ses propres résultats et les dettes dont elle est responsable.
 * @var bool $toutVoir
 * @var array $periode
 * @var list<string> $periodes
 * @var array $bornes
 * @var array $precedente
 * @var ?int $etablissement
 * @var array $etablissements
 * @var string $critere
 * @var bool $creditRembourse
 * @var bool $enSalle
 * @var string $attribution
 * @var list<array> $classement
 * @var array<int, array> $avant
 * @var array<int, array> $credits
 * @var ?array $tables
 * @var list<string> $journees
 * @var array<int, array<string, float>> $evolution
 * @var ?int $choisi
 * @var array<int, array> $personnes
 * @var array $dettes clients, personnel
 * @var array $filtreDettes
 * @var array<int, array> $devises
 * @var ?array $locale
 * @var ?array $reference
 * @var int $moi
 * @var bool $lienVentes
 * @var bool $lienDettes
 */

use App\Controllers\Gestion\DetteAffichage as D;
use App\Controllers\Gestion\RapportAffichage as R;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;
use App\Repositories\GestionDetteRepository;

$enLocale = static fn (float $m): string => R::arrondi($m, $locale);
$enRef = static fn (float $m): string => $reference ? A::montant($m, $reference) : '';
$texteperiode = R::periode($bornes['du'], $bornes['au']);
$nom = static fn (?int $id): string => $id !== null && isset($personnes[$id]) ? $personnes[$id]['nom'] : t('gventes.vendeur_inconnu');
$valeurCritere = static function (array $t) use ($critere, $enLocale): string {
    return match ($critere) {
        'tickets' => (string) $t['tickets'],
        'panier' => $enLocale($t['panier_retenu']),
        default => $enLocale($t['retenu_locale']),
    };
};
$garder = ['etablissement' => $etablissement, 'critere' => $critere !== 'ca' ? $critere : null, 'credit_rembourse' => $creditRembourse ? '1' : null,
    'attribution' => $attribution !== 'serveur' ? $attribution : null];
$periodeLien = $periode['cle'] === 'perso' ? ['du' => $periode['du'], 'au' => $periode['au']] : ['periode' => $periode['cle']];
$mini = static function (array $valeurs): string {
    $max = max($valeurs ?: [0]) ?: 1;
    $n = count($valeurs);
    $points = [];
    foreach (array_values($valeurs) as $i => $v) {
        $x = $n > 1 ? $i / ($n - 1) * 100 : 50;
        $points[] = round($x, 2) . ',' . round(28 - $v / $max * 26, 2);
    }
    return '<svg class="rap-mini" viewBox="0 0 100 30" preserveAspectRatio="none" aria-hidden="true" focusable="false">'
        . '<line x1="0" y1="29" x2="100" y2="29" vector-effect="non-scaling-stroke"/>'
        . '<polyline points="' . implode(' ', $points) . '" vector-effect="non-scaling-stroke"/></svg>';
};
$critereLibelle = t('rpersonnel.critere_' . $critere);
$premier = $classement[0] ?? null;
$detail = null;
foreach ($classement as $ligne) {
    if ($choisi !== null && (int) $ligne['utilisateur_id'] === $choisi) {
        $detail = $ligne;
    }
}
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-rapports.css">

<div class="tre-entete rap-entete">
    <div>
        <h1><?= e(t('rpersonnel.titre')) ?></h1>
        <p class="aide"><?= e($toutVoir ? t('rpersonnel.aide', ['periode' => $texteperiode]) : t('rpersonnel.aide_moi', ['periode' => $texteperiode])) ?></p>
    </div>
    <?= View::rendre('gestion/rapports/_exporter', ['titre' => t('rpersonnel.titre'), 'periode' => $texteperiode]) ?>
</div>

<section class="bloc tre-filtres rap-filtres" aria-label="<?= e(t('rapports.filtres')) ?>">
    <?= View::rendre('gestion/tresorerie/_periode', ['periode' => $periode, 'periodes' => $periodes, 'action' => '/gestion/rapports/personnel', 'garder' => $garder]) ?>
    <?php if (count($etablissements) > 1): ?>
        <nav class="tre-puces" aria-label="<?= e(t('gestion.etablissement')) ?>">
            <a class="puce" href="<?= e(urlAvec(['etablissement' => null, 'utilisateur' => null])) ?>"<?= !$etablissement ? ' aria-current="true"' : '' ?>><?= e(t('gventes.tous_etablissements')) ?></a>
            <?php foreach ($etablissements as $etab): ?>
                <a class="puce" href="<?= e(urlAvec(['etablissement' => (int) $etab['id']])) ?>"<?= $etablissement === (int) $etab['id'] ? ' aria-current="true"' : '' ?>><?= e($etab['nom']) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
    <form class="rap-options" method="get" action="/gestion/rapports/personnel">
        <?php foreach (array_filter(['etablissement' => $etablissement] + $periodeLien + ['utilisateur' => $toutVoir ? $choisi : null]) as $cle => $valeur): ?>
            <input type="hidden" name="<?= e($cle) ?>" value="<?= e((string) $valeur) ?>">
        <?php endforeach; ?>
        <div class="champ">
            <label for="rp-critere"><?= e(t('rpersonnel.critere')) ?></label>
            <select id="rp-critere" name="critere">
                <?php foreach (App\Controllers\Gestion\RapportServeursController::CRITERES as $c): ?>
                    <option value="<?= e($c) ?>"<?= $critere === $c ? ' selected' : '' ?>><?= e(t('rpersonnel.critere_' . $c)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($enSalle): ?>
            <div class="champ">
                <label for="rp-attribution"><?= e(t('rpersonnel.attribution')) ?></label>
                <select id="rp-attribution" name="attribution">
                    <option value="serveur"<?= $attribution === 'serveur' ? ' selected' : '' ?>><?= e(t('rpersonnel.attribution_serveur')) ?></option>
                    <option value="caisse"<?= $attribution === 'caisse' ? ' selected' : '' ?>><?= e(t('rpersonnel.attribution_caisse')) ?></option>
                </select>
            </div>
        <?php endif; ?>
        <label class="coche"><input type="checkbox" name="credit_rembourse" value="1"<?= $creditRembourse ? ' checked' : '' ?>> <?= e(t('rpersonnel.option_credit')) ?></label>
        <button class="bouton bouton--secondaire" type="submit"><?= e(t('rpersonnel.appliquer')) ?></button>
    </form>
    <p class="aide rap-journee"><?= e(t('rapports.journee_aide')) ?></p>
</section>

<?php if (!$toutVoir): ?>
    <p class="alerte" role="status" data-portee="moi"><?= e(t('rpersonnel.seulement_moi')) ?></p>
<?php endif; ?>

<section class="bloc" aria-labelledby="rp-classement">
    <h2 id="rp-classement"><?= e($toutVoir ? t('rpersonnel.classement') : t('rpersonnel.mes_resultats')) ?></h2>
    <p class="aide"><?= e(t('rpersonnel.classement_aide', ['critere' => $critereLibelle, 'precedente' => R::periode($precedente['du'], $precedente['au'])])) ?>
        <?php if ($creditRembourse): ?> <?= e(t('rpersonnel.option_active')) ?><?php endif; ?></p>
    <?php if ($classement === []): ?>
        <p class="aide" data-vide="classement"><?= e(t('rpersonnel.aucune_vente')) ?></p>
    <?php else: ?>
        <?php if ($toutVoir && $premier !== null && $premier['utilisateur_id'] !== null && count($classement) > 1): ?>
            <p class="rap-vedette-ligne" data-premier="<?= (int) $premier['utilisateur_id'] ?>">
                <span class="badge badge--braise"><?= e(t('rpersonnel.de_la_periode')) ?></span>
                <strong><?= e($nom($premier['utilisateur_id'])) ?></strong> · <?= e($valeurCritere($premier)) ?>
                <?php $p = $avant[(int) $premier['utilisateur_id']] ?? null; if ($p !== null): ?>
                    <span class="aide">(<?= e(t('rpersonnel.avant_court', ['valeur' => $valeurCritere($p)])) ?>)</span>
                <?php endif; ?>
            </p>
        <?php endif; ?>
        <div class="rap-defile">
            <table class="tableau rap-tableau" data-tableau="classement">
                <thead><tr>
                    <th scope="col" class="nombre">#</th>
                    <th scope="col"><?= e(t('rpersonnel.col_personne')) ?></th>
                    <th scope="col" class="nombre"><?= e($creditRembourse ? t('rpersonnel.col_ca_retenu') : t('rpersonnel.col_ca')) ?></th>
                    <th scope="col"><?= e(t('rpersonnel.col_evolution')) ?></th>
                    <th scope="col" class="nombre"><?= e(t('rapports.col_tickets')) ?></th>
                    <th scope="col" class="nombre"><?= e(t('rpersonnel.col_panier')) ?></th>
                    <th scope="col" class="nombre"><?= e(t('rpersonnel.col_credit')) ?></th>
                    <th scope="col" class="nombre"><?= e(t('rapports.col_annulees')) ?></th>
                    <?php if ($tables !== null): ?><th scope="col" class="nombre"><?= e(t('rpersonnel.col_tables')) ?></th><?php endif; ?>
                    <th scope="col"><?= e(t('rpersonnel.col_jours')) ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($classement as $t): $id = $t['utilisateur_id']; $p = $id !== null ? ($avant[$id] ?? null) : null;
                        $c = $credits[(int) $id] ?? null;
                        $valeurAvant = $p !== null ? App\Repositories\RapportRepository::valeurCritere($p, $critere) : 0.0;
                        $jours = array_map(static fn (string $j) => $evolution[(int) $id][$j] ?? 0.0, $journees); ?>
                        <tr class="<?= trim(($t['rang'] === 1 ? 'rap-premier' : '') . ((int) $id === $moi ? ' rap-moi' : '')) ?>" data-utilisateur="<?= (int) $id ?>">
                            <td class="nombre rap-rang"><?= (int) $t['rang'] ?></td>
                            <th scope="row">
                                <span class="rap-personne">
                                    <?= View::rendre('partials/_avatar', ['nom' => $nom($id), 'photo' => $personnes[(int) $id]['avatar'] ?? null, 'classe' => 'avatar--petit']) ?>
                                    <?php if ($toutVoir && $id !== null): ?>
                                        <a href="<?= e(urlAvec(['utilisateur' => $id])) ?>#rp-detail"><?= e($nom($id)) ?></a>
                                    <?php else: ?><span><?= e($nom($id)) ?></span><?php endif; ?>
                                </span>
                            </th>
                            <td class="nombre" data-cle="retenu"><strong class="montant"><?= e($enLocale($t['retenu_locale'])) ?></strong><span class="rap-sous montant"><?= e($enRef($t['retenu_reference'])) ?></span></td>
                            <td class="rap-evolution" data-cle="evolution">
                                <?php $ecart = R::ecart(App\Repositories\RapportRepository::valeurCritere($t, $critere), $valeurAvant); ?>
                                <?php if ($ecart === null): ?><span class="rap-ecart rap-ecart--neutre"><?= e(t('rpersonnel.nouveau')) ?></span>
                                <?php else: ?><span class="rap-ecart <?= $ecart >= 0 ? 'rap-ecart--bon' : 'rap-ecart--mauvais' ?>"><span aria-hidden="true"><?= $ecart >= 0 ? '↑' : '↓' ?></span> <?= e(R::pourcentSigne($ecart)) ?></span><?php endif; ?>
                                <?php if ($p !== null && $toutVoir): ?><span class="rap-sous"><?= e(t('rpersonnel.rang_avant', ['rang' => $p['rang']])) ?></span><?php endif; ?>
                            </td>
                            <td class="nombre"><span data-cle="tickets"><?= (int) $t['tickets'] ?></span><span class="rap-sous" data-cle="articles"><?= e(t('rpersonnel.n_articles', ['n' => R::nombre($t['articles'])])) ?></span></td>
                            <td class="nombre montant" data-cle="panier"><?= e($enLocale($t['panier_retenu'])) ?></td>
                            <td class="nombre" data-cle="credit"><span class="montant"><?= e($enLocale($t['credit_locale'])) ?></span>
                                <?php if ($t['non_rembourse_locale'] > 0.5): ?><span class="rap-sous"><?= e(t('rpersonnel.non_rembourse', ['montant' => $enLocale($t['non_rembourse_locale'])])) ?></span><?php endif; ?>
                                <span class="rap-sous" data-cle="credits-accordes" data-nombre="<?= (int) ($c['nombre'] ?? 0) ?>"><?= !empty($c['nombre']) ? e(t('rpersonnel.n_accordes', ['n' => (int) $c['nombre'], 'montant' => $enRef($c['reference'])])) : '' ?></span></td>
                            <td class="nombre" data-cle="annulees"><?= (int) $t['annulees'] ?><?php if ($t['annulees'] > 0): ?><span class="rap-sous montant"><?= e($enLocale($t['annulees_locale'])) ?></span><?php endif; ?></td>
                            <?php if ($tables !== null): ?><td class="nombre"><?= (int) ($tables[(int) $id] ?? 0) ?></td><?php endif; ?>
                            <td><?= $mini($jours) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if ($choisi !== null): ?>
    <section class="bloc" id="rp-detail" aria-labelledby="rp-detail-titre">
        <h2 id="rp-detail-titre"><?= e(t('rpersonnel.detail', ['nom' => $nom($choisi)])) ?></h2>
        <?php if ($detail === null): ?>
            <p class="aide"><?= e(t('rpersonnel.aucune_vente_personne')) ?></p>
        <?php else: $p = $avant[$choisi] ?? null; ?>
            <div class="rap-kpis">
                <div class="rap-kpi"><span class="rap-kpi__libelle"><?= e($creditRembourse ? t('rpersonnel.col_ca_retenu') : t('rpersonnel.col_ca')) ?></span>
                    <strong class="rap-kpi__valeur montant"><?= e($enLocale($detail['retenu_locale'])) ?></strong>
                    <span class="rap-kpi__second"><?= e(t('rpersonnel.avant_court', ['valeur' => $enLocale($p['retenu_locale'] ?? 0.0)])) ?></span></div>
                <div class="rap-kpi"><span class="rap-kpi__libelle"><?= e(t('rapports.col_tickets')) ?></span>
                    <strong class="rap-kpi__valeur"><?= (int) $detail['tickets'] ?></strong>
                    <span class="rap-kpi__second"><?= e(t('rpersonnel.avant_court', ['valeur' => (string) ($p['tickets'] ?? 0)])) ?></span></div>
                <div class="rap-kpi"><span class="rap-kpi__libelle"><?= e(t('rpersonnel.col_panier')) ?></span>
                    <strong class="rap-kpi__valeur montant"><?= e($enLocale($detail['panier_retenu'])) ?></strong>
                    <span class="rap-kpi__second"><?= e(t('rpersonnel.avant_court', ['valeur' => $enLocale($p['panier_retenu'] ?? 0.0)])) ?></span></div>
            </div>
            <?php if (count($journees) > 1):
                echo View::rendre('gestion/rapports/_barres', [
                    'id' => 'graphique-personne',
                    'titre' => t('rpersonnel.graphique', ['nom' => $nom($choisi), 'code' => $locale['code'] ?? '']),
                    'etiquettes' => array_map([R::class, 'jourCourt'], $journees),
                    'etiquettesLongues' => array_map(static fn (string $j) => A::jour($j), $journees),
                    'series' => [['nom' => $creditRembourse ? t('rpersonnel.col_ca_retenu') : t('rpersonnel.col_ca'), 'valeurs' => array_map(static fn (string $j) => $evolution[$choisi][$j] ?? 0.0, $journees)]],
                    'formater' => static fn (float $m): string => R::compact($m, $locale),
                ]);
            endif; ?>
            <?php if ($lienVentes): ?>
                <p><a class="lien rap-lien" href="/gestion/ventes?<?= e(http_build_query(array_filter(['vendeur' => $choisi, 'etablissement' => $etablissement, 'statut' => 'payee'] + ['du' => $bornes['du'], 'au' => $bornes['au']]))) ?>"><?= e(t('rpersonnel.voir_tickets')) ?></a></p>
            <?php endif; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php // Présence (module Personnel et paie) : seulement si ses tables sont installées ?>
<?php if (is_file(RACINE . '/views/gestion/rapports/_personnel-presence.php') && App\Repositories\RapportRepository::tableExiste('employes')): ?>
    <?= View::rendre('gestion/rapports/_personnel-presence', ['bornes' => $bornes, 'etablissement' => $etablissement, 'toutVoir' => $toutVoir, 'choisi' => $choisi, 'moi' => $moi]) ?>
<?php endif; ?>

<section class="bloc" id="rp-dettes" aria-labelledby="rp-dettes-titre">
    <div class="tre-entete">
        <div>
            <h2 id="rp-dettes-titre"><?= e($toutVoir ? t('rpersonnel.dettes') : t('rpersonnel.mes_dettes')) ?></h2>
            <p class="aide"><?= e(t('rpersonnel.dettes_aide', ['periode' => $texteperiode])) ?></p>
        </div>
        <a class="bouton bouton--secondaire bouton--petit rap-export__bouton" href="<?= e(urlAvec(['export' => 'csv', 'tableau' => 'dettes'])) ?>" download data-export="csv-dettes"><?= e(t('rpersonnel.csv_dettes')) ?></a>
    </div>
    <nav class="tre-puces rap-filtres" aria-label="<?= e(t('rpersonnel.filtres_dettes')) ?>">
        <a class="puce" href="<?= e(urlAvec(['devise' => null])) ?>#rp-dettes"<?= !$filtreDettes['devise'] ? ' aria-current="true"' : '' ?>><?= e(t('rpersonnel.toutes_devises')) ?></a>
        <?php foreach ($devises as $did => $d): if (!$d['active']) { continue; } ?>
            <a class="puce" href="<?= e(urlAvec(['devise' => $did])) ?>#rp-dettes"<?= $filtreDettes['devise'] === (int) $did ? ' aria-current="true"' : '' ?>><?= e($d['code']) ?></a>
        <?php endforeach; ?>
        <a class="puce" href="<?= e(urlAvec(['retard' => $filtreDettes['retard'] ? null : '1'])) ?>#rp-dettes" aria-pressed="<?= $filtreDettes['retard'] ? 'true' : 'false' ?>"<?= $filtreDettes['retard'] ? ' aria-current="true"' : '' ?>><?= e(t('rpersonnel.en_retard_seulement')) ?></a>
    </nav>
    <?php if ($dettes['clients'] === []): ?>
        <p class="aide" data-vide="dettes"><?= e(t('rpersonnel.aucune_dette')) ?></p>
    <?php else: ?>
        <div class="rap-defile">
            <table class="tableau rap-tableau" data-tableau="dettes">
                <thead><tr>
                    <th scope="col"><?= e(t('rpersonnel.col_responsable')) ?></th>
                    <th scope="col" class="nombre"><?= e(t('rpersonnel.col_reste')) ?></th>
                    <th scope="col" class="nombre"><?= e(t('rpersonnel.col_clients')) ?></th>
                    <th scope="col" class="nombre"><?= e(t('rtableau.en_retard')) ?></th>
                    <?php foreach (GestionDetteRepository::TRANCHES as $tr): ?><th scope="col" class="nombre"><?= e(t('gdettes.tranche_' . $tr)) ?></th><?php endforeach; ?>
                    <th scope="col" class="nombre"><?= e(t('rpersonnel.col_rembourse')) ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($dettes['clients'] as $id => $g): ?>
                        <tr data-responsable="<?= (int) $id ?>"<?= (int) $id === $moi ? ' class="rap-moi"' : '' ?>>
                            <th scope="row"><span class="rap-personne"><?= View::rendre('partials/_avatar', ['nom' => $id ? $nom((int) $id) : '?', 'photo' => $personnes[(int) $id]['avatar'] ?? null, 'classe' => 'avatar--petit']) ?><span><?= e($id ? $nom((int) $id) : t('rpersonnel.sans_responsable')) ?></span></span></th>
                            <td class="nombre" data-cle="reste"><strong class="montant"><?= e($enRef($g['reference'])) ?></strong><span class="rap-sous"><?= e(D::montants($g['devises'], $devises)) ?></span></td>
                            <td class="nombre" data-cle="clients"><?= (int) $g['nb_clients'] ?><span class="rap-sous"><?= e(t('rpersonnel.n_dettes', ['n' => $g['nb']])) ?></span></td>
                            <td class="nombre montant<?= $g['retard'] > 0 ? ' tre-negatif' : '' ?>" data-cle="retard"><?= e($enRef($g['retard'])) ?></td>
                            <?php foreach (GestionDetteRepository::TRANCHES as $tr): ?><td class="nombre montant" data-cle="<?= e($tr) ?>"><?= e($enRef($g['tranches'][$tr])) ?></td><?php endforeach; ?>
                            <td class="nombre" data-cle="rembourse"><span class="montant"><?= e($enRef($g['rembourse_reference'])) ?></span><?php if ($g['rembourse'] !== []): ?><span class="rap-sous"><?= e(D::montants($g['rembourse'], $devises)) ?></span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php foreach ($dettes['clients'] as $id => $g): if ($g['dettes'] === []) { continue; } ?>
            <details class="rap-donnees" data-detail-responsable="<?= (int) $id ?>"<?= $choisi !== null && (int) $id === $choisi ? ' open' : '' ?>>
                <summary><?= e(t('rpersonnel.detail_dettes', ['nom' => $id ? $nom((int) $id) : t('rpersonnel.sans_responsable'), 'n' => $g['nb']])) ?></summary>
                <?= View::rendre('gestion/rapports/_dettes-liste', ['dettes' => $g['dettes'], 'devises' => $devises, 'lienDettes' => $lienDettes]) ?>
            </details>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<?php if ($dettes['personnel'] !== []): ?>
    <section class="bloc" aria-labelledby="rp-dettes-personnel">
        <h2 id="rp-dettes-personnel"><?= e(t('rpersonnel.dettes_personnel')) ?></h2>
        <p class="aide"><?= e(t('rpersonnel.dettes_personnel_aide')) ?></p>
        <?php foreach ($dettes['personnel'] as $id => $g): ?>
            <details class="rap-donnees" data-dette-personnel="<?= (int) $id ?>">
                <summary><?= e($g['nom'] ?? $nom((int) $id)) ?> · <?= e(D::montants($g['devises'], $devises)) ?><?= $g['retard'] > 0 ? ' · ' . e(t('rpersonnel.dont_retard', ['montant' => $enRef($g['retard'])])) : '' ?></summary>
                <?= View::rendre('gestion/rapports/_dettes-liste', ['dettes' => $g['dettes'], 'devises' => $devises, 'lienDettes' => $lienDettes]) ?>
            </details>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
