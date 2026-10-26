<?php
/**
 * Page vivante du système de design : chaque exemple utilise les classes réelles de volcan.css.
 * @var array $utilisateur
 */
$couleurs = [
    // jeton, clé du nom, jeton de fond pour le contraste (null : pas de contraste affiché)
    ['--fond', 'fond', null],
    ['--surface', 'surface', null],
    ['--surface-2', 'surface_2', null],
    ['--ligne', 'ligne', null],
    ['--encre', 'encre', '--fond'],
    ['--doux', 'doux', '--surface'],
    ['--lave', 'lave', '--sur-lave'],
    ['--lave-texte', 'lave_texte', '--fond'],
    ['--braise', 'braise', '--surface'],
    ['--glace-texte', 'glace', '--fond'],
    ['--erreur', 'erreur', '--surface'],
    ['--succes', 'succes', '--surface'],
];
$nomsFonds = ['--fond' => t('design.couleur.fond'), '--surface' => t('design.couleur.surface'), '--sur-lave' => t('design.couleur.sur_lave')];
// Données d'exemple : nom de catégorie et prix au format de la langue
$anglais = App\Core\I18n::langue() === 'en';
$exBieres = $anglais ? 'Beers' : 'Bières';
$exDemi = $anglais ? '$3.50' : '$3,50';
?>
<p class="surtitre"><?= e(t('design.titre')) ?></p>
<h1><?= e(t('design.nom')) ?></h1>
<div class="design__intro">
    <p class="aide"><?= e(t('design.intro')) ?></p>
    <p class="aide"><?= e(t('design.intro_bascule')) ?></p>
    <p class="aide"><?= e(t('design.doc')) ?></p>
</div>

<section class="bloc">
    <h2><?= e(t('design.regles')) ?></h2>
    <div class="defile">
        <table class="tableau">
            <thead><tr><th><?= e(t('design.contexte')) ?></th><th><?= e(t('design.theme_defaut')) ?></th><th><?= e(t('design.bascule')) ?></th></tr></thead>
            <tbody>
                <tr><td><?= e(t('design.regle_vitrine_nuit')) ?></td><td><span class="badge badge--plein"><?= e(t('design.nuit')) ?></span></td><td><?= e(t('design.bascule_visiteur')) ?></td></tr>
                <tr><td><?= e(t('design.regle_vitrine_jour')) ?></td><td><span class="badge"><?= e(t('design.jour')) ?></span></td><td><?= e(t('design.bascule_visiteur')) ?></td></tr>
                <tr><td><?= e(t('design.regle_caisse')) ?></td><td><?= e(t('design.regle_caisse_theme')) ?></td><td><?= e(t('design.bascule_appareil')) ?></td></tr>
                <tr><td><?= e(t('design.regle_gestion')) ?></td><td><?= e(t('design.regle_gestion_theme')) ?></td><td><?= e(t('design.bascule_gestion')) ?></td></tr>
            </tbody>
        </table>
    </div>
    <p class="aide"><?= e(t('design.accent_etab')) ?></p>
</section>

<div class="design__themes">
    <?php foreach (['jour', 'nuit'] as $theme): ?>
        <div class="design__theme" data-theme="<?= $theme ?>" data-design-theme>
            <header class="design__entete">
                <h2><?= e(t('design.' . $theme)) ?></h2>
                <code class="surtitre">data-theme="<?= $theme ?>"</code>
            </header>

            <section>
                <h3><?= e(t('design.couleurs')) ?></h3>
                <div class="nuancier">
                    <?php foreach ($couleurs as [$jeton, $cle, $contre]): ?>
                        <div class="nuance" data-jeton="<?= e($jeton) ?>"<?= $contre ? ' data-contre="' . e($contre) . '"' : '' ?>
                             <?= $contre ? 'data-libelle="' . e(t('design.contraste', ['fond' => mb_strtolower($nomsFonds[$contre])])) . '"' : '' ?>>
                            <span class="nuance__pastille" style="background: var(<?= e($jeton) ?>)"></span>
                            <span class="nuance__nom"><?= e(t('design.couleur.' . $cle)) ?></span>
                            <span class="nuance__valeur"><code><?= e($jeton) ?></code> <span data-valeur></span></span>
                            <?php if ($contre): ?><span class="nuance__contraste" data-contraste></span><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <h3><?= e(t('design.categories')) ?></h3>
                <div class="nuancier nuancier--cat">
                    <?php for ($n = 1; $n <= 8; $n++): ?>
                        <div class="nuance" data-jeton="--cat-<?= $n ?>">
                            <span class="nuance__pastille" style="background: var(--cat-<?= $n ?>)">12</span>
                            <span class="nuance__nom"><?= e(t('design.cat.' . $n)) ?></span>
                            <span class="nuance__valeur" data-valeur></span>
                        </div>
                    <?php endfor; ?>
                </div>
            </section>

            <section>
                <h3><?= e(t('design.typographie')) ?></h3>
                <div class="specimen"><span class="specimen__legende"><?= e(t('design.titre_1')) ?></span><p class="titre-1"><?= e(t('design.nom')) ?></p></div>
                <div class="specimen"><span class="specimen__legende"><?= e(t('design.titre_2')) ?></span><p class="titre-2"><?= e(t('design.exemple_titre')) ?></p></div>
                <div class="specimen"><span class="specimen__legende"><?= e(t('design.titre_3')) ?></span><p class="titre-3"><?= e(t('design.exemple_titre')) ?></p></div>
                <div class="specimen"><span class="specimen__legende"><?= e(t('design.texte')) ?></span><p style="margin: 0"><?= e(t('design.exemple_texte')) ?></p></div>
                <div class="specimen"><span class="specimen__legende"><?= e(t('design.texte_fort')) ?></span><p style="margin: 0; font-weight: 700"><?= e(t('design.exemple_fort')) ?> <span class="prix-volcan">$6,50</span></p></div>
                <p class="surtitre"><?= e(t('type.nightclub')) ?> · Goma</p>
            </section>

            <section>
                <h3><?= e(t('design.boutons')) ?></h3>
                <div class="design__rangee">
                    <button type="button" class="bouton"><?= e(t('design.encaisser')) ?></button>
                    <button type="button" class="bouton bouton--secondaire"><?= e(t('design.voir_menu')) ?></button>
                    <button type="button" class="bouton bouton--fantome"><?= e(t('design.annuler')) ?></button>
                    <button type="button" class="bouton bouton--danger"><?= e(t('design.supprimer')) ?></button>
                </div>
                <div class="design__rangee">
                    <button type="button" class="bouton bouton--petit"><?= e(t('design.petit')) ?></button>
                    <button type="button" class="bouton" disabled><?= e(t('design.desactive')) ?></button>
                    <button type="button" class="bouton-icone" aria-pressed="false" title="<?= e(t('theme.nuit')) ?>">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 2v16a8 8 0 0 1 0-16Z"/></svg>
                        <span class="visuellement-cache"><?= e(t('theme.nuit')) ?></span>
                    </button>
                    <button type="button" class="bouton-icone" aria-pressed="true" title="<?= e(t('theme.nuit')) ?>">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 2v16a8 8 0 0 1 0-16Z"/></svg>
                        <span class="visuellement-cache"><?= e(t('theme.nuit')) ?></span>
                    </button>
                    <button type="button" class="lien"><?= e(t('design.lien')) ?></button>
                </div>
                <button type="button" class="bouton bouton--large"><?= e(t('design.encaisser')) ?> · $156</button>
            </section>

            <section>
                <h3><?= e(t('design.badges')) ?></h3>
                <div class="design__rangee">
                    <span class="badge badge--populaire"><?= e(t('badge.populaire')) ?></span>
                    <span class="badge badge--nouveau"><?= e(t('badge.nouveau')) ?></span>
                    <span class="badge badge--epice"><?= e(t('badge.epice')) ?></span>
                    <span class="badge badge--epuise"><?= e(t('badge.epuise')) ?></span>
                    <span class="badge badge--lave">VIP</span>
                    <span class="badge badge--succes"><?= e(t('etat.ouvert_court')) ?></span>
                    <span class="badge badge--contour"><?= e(t('design.exemple_titre')) ?></span>
                    <span class="pastille"><?= e(t('gestion.reference')) ?></span>
                    <span class="pastille pastille--alerte"><?= e(t('gestion.taux_manquant')) ?></span>
                </div>
            </section>

            <section>
                <h3><?= e(t('design.puces')) ?></h3>
                <div class="design__rangee">
                    <button type="button" class="puce" aria-pressed="true"><?= e(t('caisse.tout')) ?></button>
                    <button type="button" class="puce" aria-pressed="false"><?= e($exBieres) ?></button>
                    <button type="button" class="puce" aria-pressed="false">Cocktails</button>
                    <a class="puce puce--accent puce--active" href="#">Bouteilles</a>
                </div>
            </section>

            <section>
                <h3><?= e(t('design.champs')) ?></h3>
                <div class="champ">
                    <label for="design-nom-<?= $theme ?>"><?= e(t('design.champ_nom')) ?></label>
                    <input id="design-nom-<?= $theme ?>" value="Primus" aria-describedby="design-nom-aide-<?= $theme ?>">
                    <p class="champ__aide" id="design-nom-aide-<?= $theme ?>"><?= e(t('design.champ_nom_aide')) ?></p>
                </div>
                <div class="champ champ--erreur">
                    <label for="design-prix-<?= $theme ?>"><?= e(t('design.champ_prix')) ?></label>
                    <input id="design-prix-<?= $theme ?>" inputmode="decimal" value="0" aria-invalid="true" aria-describedby="design-prix-erreur-<?= $theme ?>">
                    <p class="champ__erreur" id="design-prix-erreur-<?= $theme ?>"><?= e(t('design.champ_prix_erreur')) ?></p>
                </div>
                <div class="champ">
                    <label for="design-cat-<?= $theme ?>"><?= e(t('design.champ_categorie')) ?></label>
                    <select id="design-cat-<?= $theme ?>"><option><?= e($exBieres) ?></option><option>Cocktails</option></select>
                </div>
                <label class="coche"><input type="checkbox" checked> <?= e(t('design.champ_visible')) ?></label>
            </section>

            <section>
                <h3><?= e(t('design.tableau')) ?></h3>
                <div class="defile">
                    <table class="tableau">
                        <thead><tr><th><?= e(t('design.colonne_produit')) ?></th><th><?= e(t('design.colonne_categorie')) ?></th><th class="nombre"><?= e(t('design.colonne_prix')) ?></th><th><?= e(t('design.colonne_etat')) ?></th></tr></thead>
                        <tbody>
                            <tr><td>Primus</td><td><?= e($exBieres) ?></td><td class="nombre">$2</td><td><span class="badge badge--succes"><?= e(t('etat.ouvert_court')) ?></span></td></tr>
                            <tr><td>Kivu Sunset</td><td>Cocktails</td><td class="nombre">$8</td><td><span class="badge badge--nouveau"><?= e(t('badge.nouveau')) ?></span></td></tr>
                            <tr><td>Guinness</td><td><?= e($exBieres) ?></td><td class="nombre"><?= e($exDemi) ?></td><td><span class="badge badge--epuise"><?= e(t('badge.epuise')) ?></span></td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section>
                <h3><?= e(t('design.carte')) ?></h3>
                <div class="carte carte--accent design__carte">
                    <p class="surtitre"><?= e(t('type.nightclub')) ?> · Goma</p>
                    <p class="titre-2">Volcano Club</p>
                    <div class="design__menu">
                        <div><p style="font-weight: 700">Hennessy VS <span class="badge badge--populaire"><?= e(t('badge.populaire')) ?></span></p>
                            <p class="aide">Cognac, 70 cl</p></div>
                        <span class="prix-volcan">$110</span>
                    </div>
                    <div class="design__menu">
                        <div><p style="font-weight: 700">Primus</p><p class="aide">72 cl</p></div>
                        <span class="prix-volcan">$3</span>
                    </div>
                </div>
            </section>

            <section>
                <h3><?= e(t('design.tuiles')) ?></h3>
                <div class="design__tuiles">
                    <button type="button" class="tuile tuile--choisie" style="--cat: var(--cat-1)"><span class="tuile__nom">Primus</span><span class="tuile__bas"><span class="tuile__format"></span><span class="tuile__prix">$3</span></span><span class="tuile__qte">2</span></button>
                    <button type="button" class="tuile" style="--cat: var(--cat-1)"><span class="tuile__nom">Mützig</span><span class="tuile__bas"><span class="tuile__format"></span><span class="tuile__prix"><?= e($exDemi) ?></span></span><span class="tuile__qte"></span></button>
                    <button type="button" class="tuile" style="--cat: var(--cat-3)"><span class="tuile__nom">Kivu Sunset</span><span class="tuile__bas"><span class="tuile__format"></span><span class="tuile__prix">$10</span></span><span class="tuile__qte"></span></button>
                    <button type="button" class="tuile tuile--choisie" style="--cat: var(--cat-2)"><span class="tuile__nom">Moët &amp; Chandon</span><span class="tuile__bas"><span class="tuile__format"></span><span class="tuile__prix">$150</span></span><span class="tuile__qte">1</span></button>
                    <button type="button" class="tuile" style="--cat: var(--cat-2)"><span class="tuile__nom">Hennessy VS</span><span class="tuile__bas"><span class="tuile__format">2 <?= e(t('caisse.formats')) ?></span><span class="tuile__prix">$6+</span></span><span class="tuile__qte"></span></button>
                    <button type="button" class="tuile tuile--epuise" style="--cat: var(--cat-1)" disabled><span class="tuile__nom">Guinness</span><span class="tuile__bas"><span class="tuile__format"><?= e(t('badge.epuise')) ?></span><span class="tuile__prix"><?= e($exDemi) ?></span></span><span class="tuile__qte"></span></button>
                </div>
            </section>

            <section>
                <h3><?= e(t('design.alertes')) ?></h3>
                <p class="alerte" style="margin: 0"><?= e(t('design.alerte_ok')) ?></p>
                <p class="alerte alerte--erreur" style="margin: 0"><?= e(t('design.alerte_erreur')) ?></p>
            </section>
        </div>
    <?php endforeach; ?>
</div>

<script>
// Valeurs lues dans les feuilles de style et contrastes calculés en direct (WCAG 2)
(function () {
  'use strict';
  function luminance(hex) {
    var m = /^#([0-9a-f]{6})$/i.exec(hex.trim());
    if (!m) { return null; }
    return [0, 2, 4].map(function (i) {
      var v = parseInt(m[1].substr(i, 2), 16) / 255;
      return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
    }).reduce(function (s, v, i) { return s + v * [0.2126, 0.7152, 0.0722][i]; }, 0);
  }
  var francais = document.documentElement.lang === 'fr';
  document.querySelectorAll('[data-design-theme]').forEach(function (bloc) {
    var styles = getComputedStyle(bloc);
    bloc.querySelectorAll('[data-jeton]').forEach(function (nuance) {
      var valeur = styles.getPropertyValue(nuance.dataset.jeton).trim().toUpperCase();
      var cible = nuance.querySelector('[data-valeur]');
      if (cible) { cible.textContent = valeur; }
      var sortie = nuance.querySelector('[data-contraste]');
      if (!sortie) { return; }
      var a = luminance(valeur);
      var b = luminance(styles.getPropertyValue(nuance.dataset.contre));
      if (a === null || b === null) { return; }
      var ratio = (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
      var texte = ratio.toFixed(1);
      sortie.textContent = nuance.dataset.libelle.replace('{ratio}', francais ? texte.replace('.', ',') : texte) +
        (ratio >= 4.5 ? ' · AA' : ratio >= 3 ? ' · AA 18 px+' : '');
    });
  });
})();
</script>
