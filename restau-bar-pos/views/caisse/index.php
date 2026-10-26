<?php
/**
 * @var array $utilisateur
 * @var array $etab
 * @var array $etablissements
 * @var array $zones
 * @var ?array $zone
 * @var array $donnees
 */
$textes = [
    'tout' => t('caisse.tout'),
    'formats' => t('caisse.formats'),
    'tailles' => t('caisse.tailles'),
    'toutes' => t('caisse.toutes_sous_categories'),
    'epuise' => t('badge.epuise'),
    'article' => t('caisse.article'),
    'articles' => t('caisse.articles'),
    'voirTicket' => t('caisse.voir_ticket'),
    'aucunResultat' => t('caisse.aucun_resultat'),
    'retirer' => t('caisse.retirer'),
    'ajouter' => t('caisse.ajouter'),
    'quantite' => t('caisse.quantite'),
];
$json = json_encode($donnees + ['textes' => $textes, 'cle' => $etab['slug']], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
?>
<div class="caisse" data-mode="<?= e($donnees['mode']) ?>" id="caisse">
    <header class="haut">
        <form class="haut__lieu" method="get" action="/caisse" data-auto-envoi>
            <label class="visuellement-cache" for="e"><?= e(t('gestion.etablissement')) ?></label>
            <select id="e" name="e">
                <?php foreach ($etablissements as $option): ?>
                    <option value="<?= e($option['slug']) ?>" <?= $option['id'] === $etab['id'] ? 'selected' : '' ?>><?= e($option['nom']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($zones): ?>
                <label class="visuellement-cache" for="zone"><?= e(t('gestion.zone')) ?></label>
                <select id="zone" name="zone">
                    <option value=""><?= e(t('caisse.toutes_zones')) ?></option>
                    <?php foreach ($zones as $option): ?>
                        <option value="<?= e($option['code']) ?>" <?= $zone && $option['id'] === $zone['id'] ? 'selected' : '' ?>><?= e(champ($option, 'nom')) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <noscript><button type="submit"><?= e(t('caisse.changer')) ?></button></noscript>
        </form>

        <div class="haut__recherche">
            <label class="visuellement-cache" for="recherche"><?= e(t('caisse.rechercher')) ?></label>
            <input id="recherche" type="search" autocomplete="off" placeholder="<?= e(t('caisse.rechercher')) ?>" enterkeyhint="done">
        </div>

        <div class="haut__reglages">
            <div class="segments" role="group" aria-label="<?= e(t('caisse.taille_tuiles')) ?>">
                <button type="button" data-taille="petite" aria-pressed="false" title="<?= e(t('caisse.taille_petite')) ?>">S</button>
                <button type="button" data-taille="moyenne" aria-pressed="false" title="<?= e(t('caisse.taille_moyenne')) ?>">M</button>
                <button type="button" data-taille="grande" aria-pressed="false" title="<?= e(t('caisse.taille_grande')) ?>">L</button>
            </div>
            <button type="button" class="icone" id="theme" data-bascule-theme aria-pressed="false" title="<?= e(t('theme.nuit')) ?>">
                <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 2v16a8 8 0 0 1 0-16Z"/></svg>
                <span class="visuellement-cache"><?= e(t('theme.nuit')) ?></span>
            </button>
            <button type="button" class="icone" id="quitter" aria-haspopup="dialog" title="<?= e(t('caisse.quitter')) ?>">
                <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/></svg>
                <span class="visuellement-cache"><?= e(t('caisse.quitter')) ?></span>
            </button>
        </div>
    </header>

    <div class="corps">
        <section class="catalogue" aria-label="<?= e(t('caisse.produits')) ?>">
            <div class="catalogue__tete">
                <nav class="onglets" id="onglets" aria-label="<?= e(t('vitrine.categories')) ?>"></nav>
                <nav class="sous-onglets" id="sous-onglets" hidden></nav>
            </div>
            <div class="grille" id="grille" aria-live="off"></div>
            <p class="vide" id="aucun" hidden><?= e(t('caisse.aucun_resultat')) ?></p>
        </section>

        <aside class="ticket" id="ticket" aria-label="<?= e(t('caisse.ticket')) ?>">
            <div class="ticket__tete">
                <h2><?= e(t('caisse.ticket')) ?><?= $zone ? ' · ' . e(champ($zone, 'nom')) : '' ?></h2>
                <button type="button" class="lien" id="vider" disabled><?= e(t('caisse.vider')) ?></button>
                <button type="button" class="icone ticket__fermer" id="fermer-ticket" title="<?= e(t('caisse.fermer')) ?>">
                    <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M6.4 5 5 6.4 10.6 12 5 17.6 6.4 19l5.6-5.6 5.6 5.6 1.4-1.4-5.6-5.6L19 6.4 17.6 5 12 10.6z"/></svg>
                    <span class="visuellement-cache"><?= e(t('caisse.fermer')) ?></span>
                </button>
            </div>
            <ul class="lignes" id="lignes"></ul>
            <p class="ticket__vide" id="ticket-vide"><?= e(t('caisse.ticket_vide')) ?></p>
            <div class="totaux">
                <div class="totaux__principal"><span><?= e(t('caisse.total')) ?></span><strong id="total">—</strong></div>
                <p class="totaux__autres" id="autres-devises"></p>
            </div>
            <button type="button" class="encaisser bouton bouton--large" disabled aria-describedby="note-encaisser"><?= e(t('caisse.encaisser')) ?></button>
            <p class="note" id="note-encaisser"><?= e(t('caisse.note_phase3')) ?></p>
        </aside>
    </div>

    <button type="button" class="resume" id="resume" hidden>
        <span id="resume-texte"></span>
        <strong id="resume-total"></strong>
    </button>

    <dialog class="formats" id="fenetre-quitter" aria-labelledby="quitter-titre">
        <form method="dialog">
            <h2 id="quitter-titre"><?= e(t('caisse.quitter_titre')) ?></h2>
            <p class="quitter__texte"><?= e(t('caisse.quitter_texte', ['nom' => $utilisateur['nom']])) ?></p>
            <div class="formats__liste">
                <a class="bouton" href="/gestion"><?= e(t('caisse.quitter_oui')) ?></a>
                <button class="bouton bouton--secondaire" value="rester"><?= e(t('caisse.quitter_non')) ?></button>
            </div>
        </form>
    </dialog>

    <dialog class="formats" id="formats">
        <form method="dialog">
            <h2 id="formats-titre"></h2>
            <div class="formats__liste" id="formats-liste"></div>
            <button class="lien" value="annuler"><?= e(t('caisse.annuler')) ?></button>
        </form>
    </dialog>
</div>
<script type="application/json" id="donnees-caisse"><?= $json ?></script>
