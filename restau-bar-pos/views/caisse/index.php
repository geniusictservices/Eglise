<?php
/**
 * @var array $utilisateur
 * @var array $etab
 * @var array $etablissements
 * @var array $zones
 * @var ?array $zone
 * @var array $donnees
 * @var string $poste caisse (le bar), entree (billetterie, contrôle, invités) ou vestiaire
 * @var list<string> $postes écrans de caisse permis à la personne
 */
$poste ??= 'caisse';
$postes ??= ['caisse'];
$nuit = $donnees['nuit'] ?? null;
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
    // Précisions d'une ligne (salle et cuisine)
    'precisions' => t('note.options'),
    'allergie' => t('note.allergie_badge'),
    'aLancer' => t('note.a_lancer'),
];
$salle = $donnees['salle'] ?? null;
$tables = $salle !== null && $salle['modules']['tables'];
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
<link rel="stylesheet" href="/assets/css/caisse-nuit.css">
<div class="caisse" data-mode="<?= e($donnees['mode']) ?>" data-poste="<?= e($poste) ?>" id="caisse">
    <header class="haut">
        <form class="haut__lieu" method="get" action="<?= e($chemin ?? '/caisse') ?>" data-auto-envoi>
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
        <?php if ($salle !== null && ($salle['service'] || $salle['happy'])): ?>
            <?php // Service en cours (prix, carte et modules du moment) et happy hour ?>
            <span class="haut__service" id="service">
                <?php if ($salle['service']): ?><span><?= e($salle['service']['nom']) ?></span><?php endif; ?>
                <?php if ($salle['happy']): ?><span class="haut__happy"><?= e(t('note.happy_jusqu', ['nom' => $salle['happy']['nom'], 'heure' => $salle['happy']['fin']])) ?></span><?php endif; ?>
            </span>
        <?php endif; ?>

        <div class="haut__recherche">
            <label class="visuellement-cache" for="recherche"><?= e(t('caisse.rechercher')) ?></label>
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input id="recherche" type="search" autocomplete="off" placeholder="<?= e(t('caisse.rechercher')) ?>" enterkeyhint="done">
        </div>

        <div class="haut__reglages">
            <?php if (!empty($horsLigne) && $poste === 'caisse'): ?>
                <?php // Réseau : en ligne ou hors ligne, et ventes en attente sur l'appareil (caisse-hors-ligne.js) ; la caisse du bar
                      // seulement (l'entrée et le vestiaire demandent le réseau) ?>
                <button type="button" class="hl-etat" id="hl-etat" aria-haspopup="dialog" hidden>
                    <span class="hl-etat__point" aria-hidden="true"></span>
                    <span class="hl-etat__texte" id="hl-etat-texte"><?= e(t('horsligne.en_ligne')) ?></span>
                    <span class="hl-etat__nombre" id="hl-etat-nombre" hidden></span>
                </button>
            <?php endif; ?>
            <?php if ($poste !== 'caisse'): ?>
                <span class="haut__poste"><?= e(t('nuit.poste_' . $poste)) ?></span>
            <?php endif; ?>
            <?php if (!empty($nuit['entree']['controler'])): ?>
                <button type="button" class="haut__nuit" id="nuit-controle" aria-haspopup="dialog"><?= App\Core\Icones::svg('qr', 18) ?><span><?= e(t('nuit.c_controle')) ?></span></button>
            <?php endif; ?>
            <?php if (!empty($nuit['entree']['invites'])): ?>
                <button type="button" class="haut__nuit" id="nuit-invites" aria-haspopup="dialog"><?= App\Core\Icones::svg('personnel', 18) ?><span><?= e(t('nuit.c_invites')) ?></span></button>
            <?php endif; ?>
            <?php if (!empty($nuit['entree'])): ?>
                <span class="haut__compteurs" id="nuit-compteurs" aria-live="polite"></span>
            <?php endif; ?>
            <?php if (!empty($nuit['vestiaire'])): ?>
                <?php if (!$nuit['vestiaire']['payant']): ?>
                    <button type="button" class="haut__nuit" id="nuit-deposer" aria-haspopup="dialog"><span><?= e(t('nuit.c_vestiaire_deposer')) ?></span></button>
                <?php endif; ?>
                <button type="button" class="haut__nuit" id="nuit-rendre" aria-haspopup="dialog"><span><?= e(t('nuit.c_vestiaire_rendre')) ?></span></button>
                <button type="button" class="haut__nuit" id="nuit-restants" aria-haspopup="dialog"><span><?= e(t('nuit.c_vestiaire_restants')) ?></span> <span class="haut__salle-prets" id="nuit-restants-n"><?= (int) $nuit['vestiaire']['restants'] ?></span></button>
            <?php endif; ?>
            <?php if (!empty($nuit['cashless']) && ($nuit['cashless']['recharger'] || $nuit['cashless']['gerer'])): ?>
                <button type="button" class="haut__nuit" id="nuit-cashless" aria-haspopup="dialog"><?= App\Core\Icones::svg('finance', 18) ?><span><?= e(t('nuit.c_carte')) ?></span></button>
            <?php endif; ?>
            <?php if ($tables): ?>
                <?php // Plan de salle : tables et notes ouvertes (pastille : plats prêts pour moi) ?>
                <button type="button" class="haut__salle" id="salle" aria-haspopup="dialog" title="<?= e(t('note.salle')) ?>">
                    <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="9" width="14" height="6" rx="1.5"/><path d="M8 15v5M16 15v5M3 6h4M17 6h4M5 6v3M19 6v3"/></svg>
                    <span class="haut__salle-texte"><?= e(t('note.salle')) ?></span>
                    <span class="haut__salle-prets" id="salle-prets" hidden></span>
                </button>
            <?php endif; ?>
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
            <?php if ($salle !== null): ?>
                <?php // Note choisie : table ou nom, serveur, durée ; transférer, fusionner, revenir au comptoir ?>
                <div class="destination" id="destination" hidden></div>
            <?php endif; ?>
            <div class="ticket__corps" id="ticket-corps">
                <?php if ($salle !== null): ?>
                    <ul class="note-lignes" id="note-lignes" hidden aria-label="<?= e(t('note.deja_envoye')) ?>"></ul>
                    <p class="ticket__intertitre" id="nouveau-titre" hidden><?= e(t('note.nouveau')) ?></p>
                <?php endif; ?>
                <ul class="lignes" id="lignes"></ul>
                <p class="ticket__vide" id="ticket-vide"><?= e(t('caisse.ticket_vide')) ?></p>
            </div>
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
            <?php if ($salle !== null): ?>
                <?php // Envoyer à la table (sans payer), addition, partage, plats à lancer : dessinés par caisse-salle.js ?>
                <div class="actions-note" id="actions-note" hidden></div>
            <?php endif; ?>
            <?php if ($donnees['sessions'] && !$donnees['session']): ?>
                <button type="button" class="encaisser bouton bouton--large" id="ouvrir-session" aria-haspopup="dialog"><?= e(t('session.ouvrir')) ?></button>
            <?php endif; ?>
            <button type="button" class="encaisser bouton bouton--large" id="encaisser" disabled<?= $donnees['sessions'] && !$donnees['session'] ? ' hidden' : '' ?>><?= e(t('caisse.encaisser')) ?> <span id="encaisser-total"></span></button>
            <?php if ($donnees['session']): ?>
                <p class="note session-note"><?= e(t('session.ouverte_depuis', ['heure' => $donnees['session']['depuis']])) ?></p>
            <?php endif; ?>
            <?php if (!$donnees['encaisser']): ?>
                <p class="note" id="note-encaisser"><?= e(t('paiement.pas_le_droit')) ?></p>
            <?php endif; ?>
            <?= App\Core\View::rendre('caisse/_pointage-note', ['etab' => $etab, 'personne' => $utilisateur]) ?>
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
            <?php if ($donnees['session']): ?>
                <button class="bouton bouton--secondaire" type="button" id="cloturer-session" aria-haspopup="dialog"><?= e(t('session.cloturer')) ?></button>
            <?php endif; ?>
            <?php foreach ($postes as $autre): if ($autre === $poste) { continue; } ?>
                <a class="bouton bouton--secondaire" data-poste-lien="<?= e($autre) ?>" href="<?= e(($autre === 'caisse' ? '/caisse' : '/caisse/' . $autre) . ($partagee ? '' : '?e=' . $etab['slug'])) ?>"><?= e(t('nuit.aller_poste', ['poste' => t('nuit.poste_' . $autre)])) ?></a>
            <?php endforeach; ?>
            <?php if ($partagee): ?>
                <form method="post" action="/caisse/verrouiller" id="form-verrouiller">
                    <input type="hidden" name="csrf" value="<?= e(App\Core\Session::jetonCsrf()) ?>">
                    <input type="hidden" name="ecran" value="<?= e($poste) ?>">
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
            <?= App\Core\View::rendre('caisse/_pointage', ['etab' => $etab, 'personne' => $utilisateur]) ?>
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
            <?php // Nuit : remise ou offert, minimum de consommation, fidélité, carte cashless (caisse-nuit.js) ?>
            <div id="paiement-nuit" class="paiement-nuit"></div>
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

    <?php if ($donnees['sessions']): ?>
        <?php foreach ($donnees['session'] ? ['cloture'] : ['ouverture'] as $sens): ?>
            <dialog class="formats session-fenetre" id="fenetre-<?= $sens ?>" aria-labelledby="titre-<?= $sens ?>">
                <form class="session-form" data-session="<?= $sens ?>" novalidate>
                    <h2 id="titre-<?= $sens ?>"><?= e(t($sens === 'ouverture' ? 'session.ouvrir' : 'session.cloturer')) ?></h2>
                    <p class="quitter__texte"><?= e(t($sens === 'ouverture' ? 'session.ouvrir_aide' : 'session.cloturer_aide')) ?></p>
                    <div class="session-caisses">
                        <?php foreach ($donnees['caissesACompter'] as $c): ?>
                            <div class="ardoise-champ">
                                <label class="paiement-fenetre__etiquette" for="<?= $sens ?>-<?= $c['compte_id'] ?>-<?= $c['devise_id'] ?>"><?= e($c['nom']) ?> · <?= e($c['code']) ?></label>
                                <input id="<?= $sens ?>-<?= $c['compte_id'] ?>-<?= $c['devise_id'] ?>" inputmode="decimal" autocomplete="off"
                                       data-compte="<?= $c['compte_id'] ?>" data-devise="<?= $c['devise_id'] ?>" placeholder="0">
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($sens === 'cloture'): ?>
                        <div class="ardoise-champ">
                            <label class="paiement-fenetre__etiquette" for="cloture-note"><?= e(t('session.note')) ?></label>
                            <input id="cloture-note" name="note" maxlength="255" autocomplete="off">
                        </div>
                    <?php endif; ?>
                    <p class="paiement-fenetre__erreur session-erreur" role="alert" hidden></p>
                    <div class="paiement-fenetre__actions">
                        <button type="button" class="bouton bouton--secondaire" data-fermer><?= e(t('caisse.annuler')) ?></button>
                        <button type="submit" class="bouton"><?= e(t($sens === 'ouverture' ? 'session.valider_ouverture' : 'session.valider_cloture')) ?></button>
                    </div>
                </form>
                <div class="session-rapport" hidden>
                    <div class="session-rapport__recu"></div>
                    <div class="paiement-fenetre__actions">
                        <button type="button" class="bouton bouton--secondaire" data-imprimer><?= e(t('paiement.imprimer')) ?></button>
                        <button type="button" class="bouton" data-terminer><?= e(t('session.terminer')) ?></button>
                    </div>
                </div>
            </dialog>
        <?php endforeach; ?>
    <?php endif; ?>

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

    <?php if ($salle !== null): ?>
        <?= App\Core\View::rendre('caisse/_salle', ['salle' => $salle, 'etab' => $etab, 'zones' => $zones]) ?>
    <?php endif; ?>

    <?php // Nuit : formule, remise, carte cashless, contrôle, invités, vestiaire, fidélité (contenu dessiné par caisse-nuit.js) ?>
    <dialog class="paiement-fenetre nuit-fenetre" id="nuit-fenetre" aria-labelledby="nuit-titre">
        <div class="paiement-fenetre__corps">
            <div class="paiement-fenetre__tete">
                <h2 id="nuit-titre"></h2>
                <button type="button" class="icone" data-fermer-nuit title="<?= e(t('nuit.c_fermer')) ?>">
                    <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M6.4 5 5 6.4 10.6 12 5 17.6 6.4 19l5.6-5.6 5.6 5.6 1.4-1.4-5.6-5.6L19 6.4 17.6 5 12 10.6z"/></svg>
                    <span class="visuellement-cache"><?= e(t('nuit.c_fermer')) ?></span>
                </button>
            </div>
            <div id="nuit-corps" class="nuit-corps"></div>
        </div>
    </dialog>
    <p class="salle-toast nuit-toast" id="nuit-toast" role="status" hidden></p>

    <dialog class="formats" id="formats">
        <form method="dialog">
            <h2 id="formats-titre"></h2>
            <div class="formats__liste" id="formats-liste"></div>
            <button class="lien" value="annuler"><?= e(t('caisse.annuler')) ?></button>
        </form>
    </dialog>
</div>
<script type="application/json" id="donnees-caisse"><?= $json ?></script>
<?php if (!empty($horsLigne)): ?><?= App\Core\View::rendre('caisse/_hors-ligne', ['horsLigne' => $horsLigne]) ?><?php endif; ?>
