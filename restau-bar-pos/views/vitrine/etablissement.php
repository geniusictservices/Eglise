<?php
/**
 * @var array $etab
 * @var ?array $zone
 * @var ?array $devise
 * @var array $devises
 * @var array $menu
 * @var array $horaires
 * @var array $etat
 * @var bool $autres
 */
$langue = App\Core\I18n::langue();
$autreLangue = $langue === 'fr' ? 'en' : 'fr';
$couverture = urlImageSure($etab['couverture']);
?>
<header class="entete<?= $couverture ? ' entete--photo' : '' ?>"
    <?php if ($couverture): ?>style="background-image: url('<?= e($couverture) ?>')"<?php endif; ?>>
    <div class="entete__contenu enveloppe">
        <?php if ($autres): ?>
            <a class="entete__retour" href="/"><?= e(t('vitrine.tous_les_etablissements')) ?></a>
        <?php endif; ?>
        <p class="entete__type"><?= e(t('type.' . $etab['type'])) ?><?= $etab['ville'] ? ' · ' . e($etab['ville']) : '' ?></p>
        <h1 class="entete__nom"><?= e($etab['nom']) ?></h1>
        <?php if (champ($etab, 'slogan') !== ''): ?>
            <p class="entete__slogan"><?= e(champ($etab, 'slogan')) ?></p>
        <?php endif; ?>
        <p class="etat <?= $etat['ouvert'] ? 'etat--ouvert' : 'etat--ferme' ?>">
            <span class="etat__point" aria-hidden="true"></span>
            <?php if ($etat['ouvert']): ?>
                <?= e(t('etat.ouvert', ['service' => champ($etat['service'], 'service'), 'heure' => $etat['jusqua']])) ?>
            <?php elseif ($etat['prochain']): ?>
                <?= e($etat['prochain']['aujourdhui']
                    ? t('etat.ferme_ouvre_aujourdhui', ['heure' => $etat['prochain']['heure']])
                    : t('etat.ferme_ouvre_jour', ['jour' => t('jour_long.' . $etat['prochain']['jour']), 'heure' => $etat['prochain']['heure']])) ?>
            <?php else: ?>
                <?= e(t('etat.ferme')) ?>
            <?php endif; ?>
        </p>
        <?php if ($couverture && !empty($etab['couverture_credit'])): ?>
            <p class="entete__credit"><?= e(creditPhoto($etab['couverture_credit'])) ?></p>
        <?php endif; ?>
    </div>
</header>

<div class="barre" id="barre">
    <div class="barre__ligne enveloppe">
        <?php if ($zone): ?>
            <span class="zone badge badge--contour"><?= e(champ($zone, 'nom')) ?></span>
        <?php endif; ?>
        <?php if (count($devises) > 1): ?>
            <nav class="choix" aria-label="<?= e(t('vitrine.devise')) ?>">
                <?php foreach ($devises as $d): ?>
                    <a href="<?= e(urlAvec(['devise' => $d['code']])) ?>"
                       class="choix__option<?= $devise && $d['id'] === $devise['id'] ? ' choix__option--actif' : '' ?>"
                       <?= $devise && $d['id'] === $devise['id'] ? 'aria-current="true"' : '' ?>><?= e($d['code']) ?></a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
        <a class="langue" href="<?= e(urlAvec(['lang' => $autreLangue])) ?>" hreflang="<?= e($autreLangue) ?>" lang="<?= e($autreLangue) ?>">
            <?= $autreLangue === 'en' ? 'English' : 'Français' ?>
        </a>
        <?= App\Core\View::rendre('layouts/_bouton-theme', ['classe' => 'barre__theme']) ?>
    </div>
    <?php if ($menu): ?>
        <nav class="categories enveloppe" aria-label="<?= e(t('vitrine.categories')) ?>">
            <?php foreach ($menu as $categorie): ?>
                <a class="categories__lien puce puce--accent" href="#cat-<?= (int) $categorie['id'] ?>"><?= e(champ($categorie, 'nom')) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
</div>

<main class="enveloppe menu">
    <?php if (!$menu): ?>
        <p class="vide"><?= e(t('vitrine.menu_vide')) ?></p>
    <?php else: ?>
        <div class="recherche">
            <label class="recherche__label" for="recherche"><?= e(t('vitrine.rechercher')) ?></label>
            <input class="recherche__champ" id="recherche" type="search" autocomplete="off"
                   placeholder="<?= e(t('vitrine.rechercher_exemple')) ?>">
            <p class="recherche__aucun" id="recherche-aucun" hidden><?= e(t('vitrine.aucun_resultat')) ?></p>
        </div>

        <?php foreach ($menu as $categorie): ?>
            <section class="categorie" id="cat-<?= (int) $categorie['id'] ?>" data-categorie>
                <h2 class="categorie__titre"><?= e(champ($categorie, 'nom')) ?></h2>
                <?php if ($categorie['produits'] !== []): ?>
                    <ul class="produits">
                        <?php foreach ($categorie['produits'] as $produit): ?>
                            <?= App\Core\View::rendre('vitrine/_produit', ['produit' => $produit, 'balise' => 'h3']) ?>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php foreach ($categorie['sous_categories'] as $sous): ?>
                    <div class="sous-categorie" id="cat-<?= (int) $sous['id'] ?>" data-sous-categorie>
                        <h3 class="sous-categorie__titre"><?= e(champ($sous, 'nom')) ?></h3>
                        <ul class="produits">
                            <?php foreach ($sous['produits'] as $produit): ?>
                                <?= App\Core\View::rendre('vitrine/_produit', ['produit' => $produit, 'balise' => 'h4']) ?>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>

        <?php if ($devise && !$devise['est_reference']): ?>
            <p class="note-taux">
                <?= e(t('vitrine.note_taux', ['devise' => champ($devise, 'nom')])) ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>
</main>

<footer class="infos">
    <div class="enveloppe infos__grille">
        <?php if ($horaires): ?>
            <section>
                <h2 class="infos__titre"><?= e(t('vitrine.horaires')) ?></h2>
                <dl class="horaires">
                    <?php foreach ($horaires as $service): ?>
                        <div class="horaires__service">
                            <dt><?= e(champ($service['service'], 'service')) ?></dt>
                            <?php foreach ($service['plages'] as $plage): ?>
                                <dd><span><?= e(formatJours($plage['jours'])) ?></span>
                                    <span class="horaires__heures"><?= e($plage['ouverture']) ?> – <?= e($plage['fermeture']) ?></span></dd>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </dl>
            </section>
        <?php endif; ?>

        <section>
            <h2 class="infos__titre"><?= e(t('vitrine.nous_trouver')) ?></h2>
            <?php if (champ($etab, 'description') !== ''): ?>
                <p class="infos__description"><?= e(champ($etab, 'description')) ?></p>
            <?php endif; ?>
            <ul class="contacts">
                <?php if ($etab['adresse']): ?>
                    <li><?= e($etab['adresse']) ?><?= $etab['ville'] ? ', ' . e($etab['ville']) : '' ?>
                        <?php if ($etab['lien_carte']): ?>
                            · <a href="<?= e($etab['lien_carte']) ?>" rel="noopener" target="_blank"><?= e(t('vitrine.voir_carte')) ?></a>
                        <?php endif; ?>
                    </li>
                <?php endif; ?>
                <?php if ($etab['telephone']): ?>
                    <li><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $etab['telephone'])) ?>"><?= e($etab['telephone']) ?></a></li>
                <?php endif; ?>
                <?php if ($etab['whatsapp']): ?>
                    <li><a href="<?= e(lienWhatsapp($etab['whatsapp'])) ?>" rel="noopener" target="_blank">WhatsApp</a></li>
                <?php endif; ?>
                <?php if ($etab['email']): ?>
                    <li><a href="mailto:<?= e($etab['email']) ?>"><?= e($etab['email']) ?></a></li>
                <?php endif; ?>
                <?php foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok'] as $reseau => $nomReseau): ?>
                    <?php if ($etab[$reseau]): ?>
                        <li><a href="<?= e($etab[$reseau]) ?>" rel="noopener" target="_blank"><?= $nomReseau ?></a></li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </section>
    </div>
</footer>
