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
    'types' => ['caisse' => t('tresorerie.type_caisse'), 'mobile_money' => t('tresorerie.type_mobile_money'), 'banque' => t('tresorerie.type_banque')],
    'lesDeux' => t('paiement.les_deux'),
    'recu' => t('paiement.recu'),
    'resteAPayer' => t('paiement.reste'),
    'regle' => t('paiement.regle'),
    'aRendre' => t('paiement.a_rendre'),
    'montantExact' => t('paiement.exact'),
    'compte' => t('paiement.compte'),
    'aucunCompte' => t('paiement.aucun_compte'),
    'envoi' => t('paiement.envoi'),
    'erreurReseau' => t('paiement.erreur_reseau'),
    'terminer' => t('paiement.terminer'),
    'verrouillee' => t('paiement.verrouillee'),
    'imprimerRecu' => t('paiement.imprimer'),
    'payerEn' => t('paiement.payer_en'),
];
// Textes de l'ardoise (caisse-ardoise.js) : « ardoise.* », « garantie.* », « rembourser.* » de lang/<langue>/dettes.php
foreach (['ardoise' => ['mettre', 'titre', 'retirer', 'chercher', 'nouveau', 'nom', 'telephone', 'whatsapp', 'creer', 'aucun', 'changer',
                        'doit', 'rien_du', 'en_retard', 'bloque', 'montant', 'servi_par', 'servi_aide', 'garant', 'sans_garant',
                        'garant_aide', 'echeance', 'forcer', 'choisir_client', 'trop_ardoise'],
          'garantie' => ['une', 'plusieurs', 'texte', 'accepter', 'refuser', 'aucune'],
          'rembourser' => ['dettes', 'du_le', 'echeance', 'tout', 'ordre', 'valider', 'autre']] as $groupe => $cles) {
    foreach ($cles as $cle) {
        $textes[$groupe][$cle] = t($groupe . '.' . $cle);
    }
}
// Raccourcis du panneau : le premier compte de chaque type (celui de l'établissement avant les communs)
$raccourcis = [];
foreach ($donnees['comptes'] as $compte) {
    $raccourcis[$compte['type']] ??= $compte;
}
$json = json_encode($donnees + ['textes' => $textes, 'cle' => $etab['slug'], 'csrf' => App\Core\Session::jetonCsrf()], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
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
            <?php if ($donnees['garanties'] !== []): ?>
                <button type="button" class="haut__garanties" id="garanties" aria-haspopup="dialog"><span></span></button>
            <?php endif; ?>
            <?php if ($donnees['rembourser']): ?>
                <button type="button" class="haut__ardoises" id="ardoises" aria-haspopup="dialog" title="<?= e(t('rembourser.titre')) ?>">
                    <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>
                    <span><?= e(t('rembourser.bouton')) ?></span>
                </button>
            <?php endif; ?>
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
                <?php foreach ($raccourcis as $compte): ?>
                    <button type="button" class="paiement" data-compte="<?= (int) $compte['id'] ?>" disabled>
                        <span class="paiement__nom"><?= e($compte['nom']) ?></span>
                        <span class="paiement__type"><?= e(t('tresorerie.type_' . $compte['type'])) ?></span>
                    </button>
                <?php endforeach; ?>
                <?php if ($donnees['credit']): ?>
                    <button type="button" class="paiement" data-ardoise disabled>
                        <span class="paiement__nom"><?= e(t('caisse.credit_client')) ?></span>
                        <span class="paiement__type"><?= e(t('ardoise.montant')) ?></span>
                    </button>
                <?php endif; ?>
            </div>
            <button type="button" class="encaisser bouton bouton--large" id="encaisser" disabled><?= e(t('caisse.encaisser')) ?> <span id="encaisser-total"></span></button>
            <?php if (!$donnees['encaisser']): ?>
                <p class="note" id="note-encaisser"><?= e(t('paiement.pas_le_droit')) ?></p>
            <?php endif; ?>
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

    <dialog class="paiement-fenetre" id="paiement" aria-labelledby="paiement-titre">
        <div class="paiement-fenetre__corps" id="paiement-corps">
            <div class="paiement-fenetre__tete">
                <h2 id="paiement-titre"><?= e(t('paiement.titre')) ?></h2>
                <button type="button" class="icone" data-fermer-paiement title="<?= e(t('caisse.annuler')) ?>">
                    <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M6.4 5 5 6.4 10.6 12 5 17.6 6.4 19l5.6-5.6 5.6 5.6 1.4-1.4-5.6-5.6L19 6.4 17.6 5 12 10.6z"/></svg>
                    <span class="visuellement-cache"><?= e(t('caisse.annuler')) ?></span>
                </button>
            </div>
            <div class="paiement-fenetre__total">
                <strong id="paiement-total"></strong>
                <span id="paiement-autres"></span>
            </div>
            <div class="paiement-fenetre__devises" role="group" aria-label="<?= e(t('paiement.payer_en')) ?>">
                <span class="paiement-fenetre__etiquette"><?= e(t('paiement.payer_en')) ?></span>
                <div class="segments segments--large" id="paiement-devises"></div>
            </div>
            <div id="paiement-lignes" class="paiement-lignes"></div>
            <div class="paiement-fenetre__bilan" id="paiement-bilan" aria-live="polite"></div>
            <div class="paiement-fenetre__rendu" id="paiement-rendu" hidden>
                <span class="paiement-fenetre__etiquette"><?= e(t('paiement.rendre_en')) ?></span>
                <div class="segments" id="paiement-rendu-devises"></div>
            </div>
            <p class="paiement-fenetre__erreur" id="paiement-erreur" role="alert" hidden></p>
            <button type="button" class="bouton bouton--large paiement-fenetre__valider" id="paiement-valider" disabled><?= e(t('paiement.valider')) ?></button>
        </div>
        <div class="paiement-fenetre__fin" id="paiement-fin" hidden>
            <div id="paiement-recu"></div>
            <div class="paiement-fenetre__actions">
                <button type="button" class="bouton bouton--secondaire" id="paiement-imprimer"><?= e(t('paiement.imprimer')) ?></button>
                <button type="button" class="bouton" id="paiement-suivant"><?= e(t('paiement.nouvelle_vente')) ?></button>
            </div>
        </div>
    </dialog>
    <div id="zone-impression" aria-hidden="true"></div>

    <?php if ($donnees['garanties'] !== []): ?>
        <dialog class="formats garanties-fenetre" id="fenetre-garanties" aria-labelledby="garanties-titre">
            <h2 id="garanties-titre"><?= e(t('garantie.titre')) ?></h2>
            <p class="quitter__texte"><?= e(t('garantie.aide')) ?></p>
            <div class="garanties-liste" id="garanties-liste"></div>
            <form method="dialog"><button class="bouton bouton--secondaire" value="plus-tard"><?= e(t('garantie.plus_tard')) ?></button></form>
        </dialog>
    <?php endif; ?>

    <?php if ($donnees['rembourser']): ?>
        <dialog class="paiement-fenetre" id="fenetre-ardoises" aria-labelledby="ardoises-titre">
            <div class="paiement-fenetre__corps">
                <div class="paiement-fenetre__tete">
                    <h2 id="ardoises-titre"><?= e(t('rembourser.titre')) ?></h2>
                    <button type="button" class="icone" data-fermer-ardoises title="<?= e(t('rembourser.fermer')) ?>">
                        <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M6.4 5 5 6.4 10.6 12 5 17.6 6.4 19l5.6-5.6 5.6 5.6 1.4-1.4-5.6-5.6L19 6.4 17.6 5 12 10.6z"/></svg>
                        <span class="visuellement-cache"><?= e(t('rembourser.fermer')) ?></span>
                    </button>
                </div>
                <div id="ardoises-corps" class="ardoises-corps"></div>
            </div>
        </dialog>
    <?php endif; ?>

    <dialog class="formats" id="formats">
        <form method="dialog">
            <h2 id="formats-titre"></h2>
            <div class="formats__liste" id="formats-liste"></div>
            <button class="lien" value="annuler"><?= e(t('caisse.annuler')) ?></button>
        </form>
    </dialog>
</div>
<script type="application/json" id="donnees-caisse"><?= $json ?></script>
