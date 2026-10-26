<?php
/**
 * Messages envoyés (SMS, WhatsApp) : date, destinataire, événement et étape, canal, statut, texte, erreur.
 * « simule » : inscrit au journal sans être envoyé, parce que la messagerie n'est pas réglée.
 * @var list<array> $messages lignes de la table messages, avec client_nom et utilisateur_nom
 * @var bool $avecDette lien vers la dette de chaque message
 */

use App\Controllers\Gestion\DetteAffichage as D;
use App\Controllers\Gestion\TresorerieAffichage as A;
?>
<ul class="det-messages">
    <?php foreach ($messages as $m): ?>
        <li class="det-message" data-message="<?= (int) $m['id'] ?>" data-statut="<?= e($m['statut']) ?>">
            <div class="det-message__tete">
                <span class="badge <?= D::badgeMessage($m['statut']) ?>"><?= e(t('messagerie.statut_' . $m['statut'])) ?></span>
                <span class="badge badge--contour"><?= e(t('messagerie.canal_' . $m['canal'])) ?></span>
                <strong><?= e(t('messagerie.evenement_' . $m['evenement'])) ?><?= $m['etape'] && $m['etape'] !== 'demande' ? ' · ' . e($m['etape']) : '' ?></strong>
                <span class="det-message__date"><?= e(A::dateHeure($m['cree_le'])) ?></span>
            </div>
            <p class="det-message__destinataire">
                <?= e(t('messagerie.a', ['nom' => $m['utilisateur_nom'] ?? $m['client_nom'] ?? '—'])) ?>
                <span class="montant"><?= e(afficherTelephone($m['destinataire'])) ?></span>
                <?php if (!empty($avecDette) && $m['dette_id']): ?>
                    · <a class="lien" href="/gestion/dettes/<?= (int) $m['dette_id'] ?>"><?= e(t('gdettes.fiche_titre', ['id' => $m['dette_id']])) ?></a>
                <?php endif; ?>
            </p>
            <p class="det-message__texte"><?= e($m['texte']) ?></p>
            <?php if ($m['statut'] === 'echec' && $m['erreur']): ?>
                <p class="det-message__erreur"><?= e(t('messagerie.erreur_envoi', ['erreur' => $m['erreur']])) ?></p>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ul>
