<?php
/**
 * Fiche d'une image de la médiathèque.
 * @var ?string $flash
 * @var array $media
 * @var array $valeurs nom, alt_fr, alt_en, credit
 * @var array $erreurs par champ
 * @var ?string $erreurSuppression
 * @var array $utilisations type, id, nom, lien, actif
 * @var bool $supprimable fichier dans public/uploads
 */
use App\Repositories\MediathequeRepository;

$id = (int) $media['id'];
$jeton = e(App\Core\Session::jetonCsrf());
$image = urlImageSure((string) $media['chemin']);
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$adresse = ($https ? 'https' : 'http') . '://' . preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) . $media['chemin'];
$date = strtotime((string) $media['cree_le']) ?: time();
$langue = App\Core\I18n::langue();
$champs = [
    'nom' => ['max' => 160, 'requis' => true],
    'alt_fr' => ['max' => 255, 'langue' => 'fr', 'aide' => t('mediatheque.alt_aide')],
    'alt_en' => ['max' => 255, 'langue' => 'en'],
    'credit' => ['max' => 255, 'aide' => t('mediatheque.credit_aide'), 'exemple' => t('catalogue.photo_credit_exemple')],
];
?>
<link rel="stylesheet" href="/assets/css/gestion-mediatheque.css">

<p class="med-retour"><a class="lien" href="/gestion/mediatheque#media-<?= $id ?>">← <?= e(t('mediatheque.titre')) ?></a></p>
<h1 class="med-titre"><?= e($media['nom']) ?></h1>

<?php if ($flash): ?>
    <p class="alerte" role="status"><?= e($flash) ?></p>
<?php endif; ?>

<div class="med-fiche">
    <figure class="med-fiche__apercu">
        <?php if ($image): ?>
            <img src="<?= e($image) ?>" alt="<?= e(champ($media, 'alt')) ?>">
        <?php else: ?>
            <span class="aide"><?= e(t('mediatheque.apercu_impossible')) ?></span>
        <?php endif; ?>
    </figure>

    <div class="med-fiche__infos">
        <dl class="med-details">
            <div><dt><?= e(t('mediatheque.dimensions_libelle')) ?></dt><dd class="nombre"><?= e(MediathequeRepository::dimensions($media)) ?></dd></div>
            <div><dt><?= e(t('mediatheque.poids')) ?></dt><dd class="nombre"><?= e(MediathequeRepository::poidsLisible($media['octets'] === null ? null : (int) $media['octets'])) ?></dd></div>
            <div><dt><?= e(t('mediatheque.type')) ?></dt><dd><?= e($media['type_mime'] ? strtoupper((string) preg_replace('#^image/|\+xml$#', '', (string) $media['type_mime'])) : '—') ?></dd></div>
            <div><dt><?= e(t('mediatheque.ajoutee_le')) ?></dt><dd><?= e(t('mediatheque.date', [
                'date' => date($langue === 'fr' ? 'd/m/Y' : 'Y-m-d', $date), 'heure' => date('H:i', $date),
            ])) ?><?= !empty($media['auteur']) ? ' · ' . e(t('mediatheque.par', ['nom' => $media['auteur']])) : '' ?></dd></div>
            <div><dt><?= e(t('mediatheque.utilisation')) ?></dt><dd><span class="badge <?= (int) $media['utilisations'] > 0 ? 'badge--succes' : 'badge--neutre' ?>"><?= e(MediathequeRepository::texteUtilisations((int) $media['utilisations'])) ?></span></dd></div>
        </dl>

        <div class="champ med-adresse">
            <label for="med-adresse"><?= e(t('mediatheque.adresse')) ?></label>
            <div class="med-adresse__ligne">
                <input id="med-adresse" type="text" value="<?= e($adresse) ?>" readonly data-adresse>
                <button class="bouton bouton--secondaire" type="button" data-copier hidden
                        data-ok="<?= e(t('mediatheque.copie')) ?>" data-echec="<?= e(t('mediatheque.copie_echec')) ?>"><?= e(t('mediatheque.copier')) ?></button>
            </div>
            <p class="champ__aide" data-copie role="status" aria-live="polite"></p>
        </div>
        <?php if ($image): ?>
            <p><a class="lien" href="<?= e($image) ?>" target="_blank" rel="noopener"><?= e(t('mediatheque.ouvrir')) ?> ↗</a></p>
        <?php endif; ?>
    </div>
</div>

<section class="bloc med-section" id="textes">
    <h2><?= e(t('mediatheque.section_textes')) ?></h2>
    <?php if ($erreurs !== []): ?>
        <div class="alerte alerte--erreur" role="alert">
            <p><?= e(t('mediatheque.corrigez')) ?></p>
            <ul><?php foreach ($erreurs as $erreur): ?><li><?= e($erreur) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>
    <form class="formulaire med-formulaire" method="post" action="/gestion/mediatheque/<?= $id ?>#textes">
        <input type="hidden" name="csrf" value="<?= $jeton ?>">
        <?php foreach ($champs as $champ => $options):
            $idChamp = 'med-' . str_replace('_', '-', $champ);
            $erreur = $erreurs[$champ] ?? null;
            $decrit = trim(($erreur ? $idChamp . '-erreur ' : '') . (!empty($options['aide']) ? $idChamp . '-aide' : ''));
            ?>
            <div class="champ">
                <label for="<?= $idChamp ?>"><?= e(t('mediatheque.' . $champ)) ?><?php if (empty($options['requis'])): ?> <span class="med-facultatif"><?= e(t('mediatheque.facultatif')) ?></span><?php endif; ?></label>
                <input id="<?= $idChamp ?>" name="<?= $champ ?>" value="<?= e($valeurs[$champ] ?? '') ?>" maxlength="<?= (int) $options['max'] ?>"
                    <?= !empty($options['requis']) ? 'required' : '' ?>
                    <?= !empty($options['langue']) ? 'lang="' . $options['langue'] . '"' : '' ?>
                    <?= !empty($options['exemple']) ? 'placeholder="' . e($options['exemple']) . '"' : '' ?>
                    <?= $erreur ? 'aria-invalid="true"' : '' ?>
                    <?= $decrit !== '' ? 'aria-describedby="' . $decrit . '"' : '' ?>>
                <?php if ($erreur): ?><p class="champ__erreur" id="<?= $idChamp ?>-erreur"><?= e($erreur) ?></p><?php endif; ?>
                <?php if (!empty($options['aide'])): ?><p class="aide" id="<?= $idChamp ?>-aide"><?= e($options['aide']) ?></p><?php endif; ?>
            </div>
        <?php endforeach; ?>
        <div class="med-actions">
            <button class="bouton" type="submit"><?= e(t('mediatheque.enregistrer')) ?></button>
        </div>
    </form>
</section>

<section class="bloc med-section" id="utilisations">
    <h2><?= e(t('mediatheque.section_utilisations')) ?></h2>
    <?php if ($utilisations === []): ?>
        <p class="aide"><?= e(t('mediatheque.utilisee_nulle_part')) ?></p>
    <?php else: ?>
        <ul class="med-utilisations">
            <?php foreach ($utilisations as $u): ?>
                <li>
                    <span class="badge badge--glace"><?= e(t('mediatheque.usage_' . $u['type'])) ?></span>
                    <a href="<?= e($u['lien']) ?>"><?= e($u['nom']) ?></a>
                    <?php if (!$u['actif']): ?><span class="badge badge--neutre"><?= e(t('mediatheque.desactive')) ?></span><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="bloc med-section" id="supprimer">
    <h2><?= e(t('mediatheque.section_supprimer')) ?></h2>
    <?php if ($erreurSuppression): ?>
        <p class="alerte alerte--erreur" role="alert"><?= e($erreurSuppression) ?></p>
    <?php endif; ?>
    <?php if ($utilisations !== []): ?>
        <p class="aide"><?= e(t('mediatheque.suppression_utilisee', ['n' => MediathequeRepository::texteUtilisationsMinuscule(count($utilisations))])) ?></p>
        <ul class="med-utilisations med-utilisations--simple">
            <?php foreach ($utilisations as $u): ?>
                <li><a href="<?= e($u['lien']) ?>"><?= e(t('mediatheque.usage_' . $u['type']) . ' · ' . $u['nom']) ?></a></li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p class="aide"><?= e($supprimable ? t('mediatheque.suppression_aide') : t('mediatheque.suppression_aide_assets')) ?></p>
        <details class="med-confirmer" id="confirmer-suppression">
            <summary class="bouton bouton--secondaire"><?= e(t('mediatheque.supprimer')) ?></summary>
            <div class="med-confirmer__corps">
                <p><?= e(t('mediatheque.supprimer_confirmer', ['nom' => $media['nom']])) ?></p>
                <form method="post" action="/gestion/mediatheque/<?= $id ?>/supprimer">
                    <input type="hidden" name="csrf" value="<?= $jeton ?>">
                    <button class="bouton bouton--danger" type="submit"><?= e(t('mediatheque.supprimer_oui')) ?></button>
                </form>
            </div>
        </details>
    <?php endif; ?>
</section>

<script src="/assets/js/gestion-mediatheque.js" defer></script>
