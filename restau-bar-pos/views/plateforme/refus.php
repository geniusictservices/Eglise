<?php
/**
 * Page affichée par App\Core\Plateforme (ou Acces pour un module) quand l'abonnement empêche l'action :
 * suspension, lecture seule, caisse fermée à la prochaine ouverture, module non inclus, limite de la formule.
 * @var string $raison
 * @var array $etat
 * @var ?string $formule
 * @var array{nom: string, telephone: ?string, email: ?string} $contact
 * @var ?string $module
 */

use App\Core\Plateforme;
use App\Core\Session;

$valeurs = [
    'formule' => $formule ?? '—',
    'limite' => (string) ($etat['limite'] ?? ''),
    'module' => isset($module) ? t('module.' . $module . '.nom') : '',
    'plateforme' => $contact['nom'],
    'suspension' => !empty($etat['lecture_seule_jusqua']) ? Plateforme::date($etat['lecture_seule_jusqua']) : '',
];
$connecte = Session::utilisateur() !== null;
$caisse = str_starts_with($raison, 'caisse_');
?>
<link rel="stylesheet" href="/assets/css/plateforme.css">
<section class="plf-refus" aria-labelledby="plf-titre">
    <p class="surtitre"><?= e(t('plateforme.surtitre', ['plateforme' => $contact['nom']])) ?></p>
    <h1 id="plf-titre"><?= e(t('plateforme.' . $raison . '.titre')) ?></h1>
    <p><?= e(t('plateforme.' . $raison . '.texte', $valeurs)) ?></p>
    <?php if (in_array($raison, ['suspendu', 'lecture_seule', 'caisse_lecture_seule'], true)): ?>
        <p class="aide"><?= e(t('plateforme.donnees_gardees')) ?></p>
    <?php endif; ?>
    <div class="plf-contact">
        <p class="plf-contact__titre"><?= e(t('plateforme.contact_titre', ['plateforme' => $contact['nom']])) ?></p>
        <ul>
            <?php if (!empty($contact['telephone'])): ?>
                <li><a class="lien" href="tel:<?= e(preg_replace('/[^\d+]/', '', $contact['telephone'])) ?>"><?= e($contact['telephone']) ?></a></li>
                <li><a class="lien" href="<?= e(lienWhatsapp($contact['telephone'])) ?>" rel="noopener">WhatsApp</a></li>
            <?php endif; ?>
            <?php if (!empty($contact['email'])): ?>
                <li><a class="lien" href="mailto:<?= e($contact['email']) ?>"><?= e($contact['email']) ?></a></li>
            <?php endif; ?>
            <?php if (empty($contact['telephone']) && empty($contact['email'])): ?>
                <li><?= e($contact['nom']) ?></li>
            <?php endif; ?>
        </ul>
    </div>
    <div class="plf-actions">
        <?php if ($raison !== 'suspendu'): ?>
            <a class="bouton" href="/gestion"><?= e(t('modules.retour_titre')) ?></a>
        <?php endif; ?>
        <?php if ($caisse): ?>
            <a class="bouton bouton--secondaire" href="/caisse"><?= e(t('plateforme.reessayer')) ?></a>
        <?php endif; ?>
        <?php if ($connecte && $raison === 'suspendu'): ?>
            <form method="post" action="/gestion/deconnexion">
                <input type="hidden" name="csrf" value="<?= e(Session::jetonCsrf()) ?>">
                <button class="bouton bouton--secondaire" type="submit"><?= e(t('gestion.deconnexion')) ?></button>
            </form>
        <?php endif; ?>
    </div>
</section>
