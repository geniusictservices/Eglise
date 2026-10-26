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
    'verrouBientot' => t('verrou.bientot'),
];
$json = json_encode($donnees + ['textes' => $textes, 'cle' => $etab['slug']], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
?>
<div class="caisse" data-mode="<?= e($donnees['mode']) ?>" id="caisse">
    <header class="haut">
        <form class="haut__lieu" method="get" action="/caisse" data-auto-envoi>
            <?php if (count($etablissements) > 1): ?>
                <label class="visuellement-cache" for="e"><?= e(t('gestion.etablissement')) ?></label>
                <select id="e" name="e">
                    <?php foreach ($etablissements as $option): ?>
                        <option value="<?= e($option['slug']) ?>" <?= $option['id'] === $etab['id'] ? 'selected' : '' ?>><?= e($option['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <span class="haut__etab"><?= e($etab['nom']) ?></span>
            <?php endif; ?>
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
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
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
            <button type="button" class="personne" id="quitter" aria-haspopup="dialog" title="<?= e(t('caisse.session_titre')) ?>">
                <?= App\Core\View::rendre('partials/_avatar', ['nom' => $utilisateur['nom'], 'photo' => $utilisateur['avatar'] ?? null, 'classe' => 'avatar--petit']) ?>
                <span class="personne__nom"><?= e($utilisateur['nom']) ?></span>
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
            <div class="paiements" role="group" aria-label="<?= e(t('caisse.mode_paiement')) ?>">
                <button type="button" class="paiement" disabled aria-describedby="note-encaisser"><?= e(t('caisse.especes')) ?></button>
                <button type="button" class="paiement" disabled aria-describedby="note-encaisser"><?= e(t('caisse.mobile_money')) ?></button>
                <button type="button" class="paiement" disabled aria-describedby="note-encaisser"><?= e(t('caisse.banque')) ?></button>
                <button type="button" class="paiement" disabled aria-describedby="note-encaisser"><?= e(t('caisse.credit_client')) ?></button>
            </div>
            <button type="button" class="encaisser bouton bouton--large" disabled aria-describedby="note-encaisser"><?= e(t('caisse.encaisser')) ?> <span id="encaisser-total"></span></button>
            <p class="note" id="note-encaisser"><?= e(t('caisse.note_phase3')) ?></p>
        </aside>
    </div>

    <button type="button" class="resume" id="resume" hidden>
        <span id="resume-texte"></span>
        <strong id="resume-total"></strong>
    </button>

    <dialog class="formats" id="fenetre-quitter" aria-labelledby="quitter-titre">
        <div class="session__tete">
            <?= App\Core\View::rendre('partials/_avatar', ['nom' => $utilisateur['nom'], 'photo' => $utilisateur['avatar'] ?? null, 'classe' => 'avatar--moyen']) ?>
            <h2 id="quitter-titre"><?= e($utilisateur['nom']) ?></h2>
        </div>
        <?php if ($messagePhoto): ?><p class="quitter__texte session__message" role="status"><?= e($messagePhoto) ?></p><?php endif; ?>
        <p class="quitter__texte"><?= e(t($partagee ? 'caisse.session_partagee' : 'caisse.session_personnelle', ['appareil' => $appareil['nom'] ?? ''])) ?></p>
        <?php if ($regle): ?><p class="quitter__texte"><?= e($regle) ?></p><?php endif; ?>
        <div class="formats__liste">
            <?php if ($partagee): ?>
                <form method="post" action="/caisse/verrouiller" id="form-verrouiller">
                    <input type="hidden" name="csrf" value="<?= e(App\Core\Session::jetonCsrf()) ?>">
                    <button class="bouton" type="submit" id="verrouiller"><?= e(t('caisse.verrouiller')) ?></button>
                </form>
            <?php endif; ?>
            <?php if ($aLaGestion): ?>
                <a class="bouton<?= $partagee ? ' bouton--secondaire' : '' ?>" href="/gestion"><?= e(t('caisse.quitter_oui')) ?></a>
            <?php endif; ?>
            <?php if ($peutAutoriser): ?>
                <details class="partager">
                    <summary class="bouton bouton--secondaire"><?= e(t('caisse.partager')) ?></summary>
                    <form method="post" action="/caisse/appareil" class="partager__form">
                        <input type="hidden" name="csrf" value="<?= e(App\Core\Session::jetonCsrf()) ?>">
                        <input type="hidden" name="e" value="<?= e($etab['slug']) ?>">
                        <p class="quitter__texte"><?= e(t('caisse.partager_texte', ['etablissement' => $etab['nom']])) ?></p>
                        <label for="nom-appareil"><?= e(t('caisse.partager_nom')) ?></label>
                        <input id="nom-appareil" name="nom" maxlength="80" placeholder="<?= e(t('caisse.partager_exemple')) ?>">
                        <button class="bouton" type="submit"><?= e(t('caisse.partager_oui')) ?></button>
                    </form>
                </details>
            <?php endif; ?>
            <?php if ($photoPermise): ?>
                <details class="partager" <?= $messagePhoto ? 'open' : '' ?>>
                    <summary class="bouton bouton--secondaire"><?= e(t('profil.ma_photo')) ?></summary>
                    <div class="partager__form">
                        <?= App\Core\View::rendre('partials/_photo-form', ['action' => '/caisse/photo' . ($partagee ? '' : '?e=' . $etab['slug']), 'nom' => $utilisateur['nom'], 'photo' => $utilisateur['avatar'] ?? null, 'selfie' => true, 'erreur' => null]) ?>
                    </div>
                </details>
            <?php endif; ?>
            <form method="dialog"><button class="bouton bouton--secondaire" value="rester"><?= e(t('caisse.quitter_non')) ?></button></form>
        </div>
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
