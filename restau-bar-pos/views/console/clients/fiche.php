<?php
/**
 * Fiche d'un client : installation guidée (tant que l'instance n'est pas installée), abonnement et échéancier,
 * paiements, instance (mise à jour, sauvegarde), utilisation (nombres seulement), statut à la main, sender ID,
 * assistance, contact, relances envoyées, journal.
 * @var array $client
 * @var string $url
 * @var list<array> $formules
 * @var list<array> $paiements
 * @var list<array> $messages
 * @var list<array> $journal
 * @var ?array $usage
 * @var ?string $assistance
 * @var ?array $motDePasse
 * @var array $erreurs
 * @var array $saisie
 * @var ?array $testRetenu
 * @var string $suggestion
 * @var string $prefixe
 * @var string $dbHoteDefaut
 * @var string $racinePublique
 * @var string $domaine
 * @var ?string $smsPlateforme
 */

use App\Console\Abonnement;
use App\Console\Affichage as A;
use App\Console\Clients;
use App\Console\SessionConsole;

$csrf = SessionConsole::jetonCsrf();
$id = (int) $client['id'];
$statut = $client['statut'];
$installe = $client['installe_le'] !== null;
$err = static function (string $groupe, string $cle) use ($erreurs): string {
    $message = $erreurs[$groupe][$cle] ?? null;
    return is_string($message) ? '<p class="champ__erreur" id="err-' . e($groupe . '-' . $cle) . '">' . e($message) . '</p>' : '';
};
$inv = static fn (string $groupe, string $cle): string => isset($erreurs[$groupe][$cle]) ? ' aria-invalid="true" aria-describedby="err-' . e($groupe . '-' . $cle) . '"' : '';
$abo = $saisie['abonnement'] ?? $client;
$pai = $saisie['paiement'] ?? [];
$contact = $saisie['contact'] ?? $client;
$base = $saisie['base'] ?? ($testRetenu ?? ['hote' => $dbHoteDefaut, 'port' => '3306', 'nom' => '', 'utilisateur' => '']);
$test = $saisie['test'] ?? null;
$dernierValable = null;
foreach ($paiements as $p) {
    if ($p['annule_le'] === null) {
        $dernierValable = (int) $p['id'];
        break;
    }
}
$prix = Abonnement::prix($client, $client['periode']);
$jalons = $client['echeance'] ? [
    ['code' => 'a_relancer', 'date' => Abonnement::ajouterJours($client['echeance'], -Abonnement::JOURS_RELANCE), 'libelle' => t('console.echeancier.relances')],
    ['code' => 'echeance', 'date' => $client['echeance'], 'libelle' => t('console.echeancier.echeance')],
    ['code' => 'grace', 'date' => $statut['grace_jusqua'], 'libelle' => t('console.echeancier.grace')],
    ['code' => 'lecture_seule', 'date' => $statut['lecture_seule_jusqua'], 'libelle' => t('console.echeancier.lecture_seule')],
    ['code' => 'suspendu', 'date' => Abonnement::ajouterJours((string) $statut['lecture_seule_jusqua'], 1), 'libelle' => t('console.echeancier.suspension')],
] : [];
?>
<p><a class="lien" href="/clients">← <?= e(t('console.clients.titre')) ?></a></p>
<div class="con-entete">
    <div>
        <h1><?= e($client['nom']) ?></h1>
        <p class="con-resume"><?= A::badge($client) ?>
            <a class="lien" href="<?= e($url) ?>/gestion" target="_blank" rel="noopener" data-url-instance><?= e(preg_replace('#^https?://#', '', $url)) ?></a>
            <?php if ($client['formule_nom']): ?><span class="aide"><?= e($client['formule_nom']) ?> · <?= e(A::montant($prix)) ?> / <?= e(t('console.periode.' . $client['periode'])) ?></span><?php endif; ?>
        </p>
    </div>
</div>

<?php if ($motDePasse !== null): ?>
    <section class="bloc con-secret" aria-labelledby="con-mdp-titre" data-mot-de-passe-proprietaire>
        <h2 id="con-mdp-titre"><?= e(t(($motDePasse['action'] ?? 'cree') === 'reinitialise' ? 'console.proprietaire.reinitialise_titre' : 'console.installation_instance.compte_cree')) ?></h2>
        <p><?= e(t('console.installation_instance.compte_aide')) ?></p>
        <dl class="con-dl">
            <dt><?= e(t('console.installation_instance.adresse')) ?></dt><dd><a class="lien" href="<?= e($url) ?>/gestion" target="_blank" rel="noopener"><?= e($url) ?>/gestion</a></dd>
            <dt><?= e(t('gestion.email')) ?></dt><dd><code><?= e($motDePasse['email']) ?></code></dd>
            <dt><?= e(t('console.installation_instance.mdp_temporaire')) ?></dt><dd><code class="con-mdp" data-mdp><?= e($motDePasse['mot_de_passe']) ?></code></dd>
        </dl>
        <p class="aide"><?= e(t('console.installation_instance.une_fois')) ?></p>
    </section>
<?php endif; ?>

<?php if (!$installe): ?>
    <section class="bloc" id="installation" aria-labelledby="con-inst-titre">
        <h2 id="con-inst-titre"><?= e(t('console.installation_instance.titre')) ?></h2>
        <p class="aide"><?= e(t('console.installation_instance.aide')) ?></p>
        <ol class="con-guide">
            <li>
                <h3><?= e(t('console.installation_instance.etape1')) ?></h3>
                <p><?= t('console.installation_instance.etape1_texte', [
                    'sous_domaine' => '<code>' . e($client['sous_domaine']) . '</code>', 'domaine' => '<code>' . e($domaine) . '</code>',
                    'racine' => '<code data-racine>' . e($racinePublique) . '</code>',
                ]) ?></p>
            </li>
            <li>
                <h3><?= e(t('console.installation_instance.etape2')) ?></h3>
                <p><?= t('console.installation_instance.etape2_texte', [
                    'base' => '<code>' . e($suggestion) . '</code>', 'prefixe' => '<code>' . e($prefixe !== '' ? $prefixe . '_' : t('console.installation_instance.prefixe_compte')) . '</code>',
                ]) ?></p>
            </li>
            <li>
                <h3><?= e(t('console.installation_instance.etape3')) ?></h3>
                <p><?= e(t('console.installation_instance.etape3_texte')) ?></p>
            </li>
            <li>
                <h3><?= e(t('console.installation_instance.etape4')) ?></h3>
                <p><?= e(t('console.installation_instance.etape4_texte', ['adresse' => $client['sous_domaine'] . '.' . $domaine])) ?></p>
            </li>
        </ol>
        <?php if (isset($erreurs['base']['general'])): ?>
            <p class="alerte alerte--erreur" role="alert" data-erreur-installation><?= e($erreurs['base']['general']) ?></p>
        <?php endif; ?>
        <?php if (!empty($saisie['contenuConfig'])): ?>
            <pre class="con-code"><?= e($saisie['contenuConfig']) ?></pre>
        <?php endif; ?>
        <?php if ($test !== null): ?>
            <p class="alerte<?= $test['ok'] ? '' : ' alerte--erreur' ?>" role="status" data-test="<?= $test['ok'] ? 'ok' : 'echec' ?>">
                <?= e($test['ok'] ? t('console.db.test_ok', ['version' => $test['version'], 'etat' => t('console.db.etat_' . $test['etat'], ['n' => $test['tables']])]) : $test['erreur']) ?>
            </p>
        <?php endif; ?>
        <form method="post" action="/clients/<?= $id ?>/installer" class="con-form" novalidate>
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <div class="con-grille">
                <div class="champ"><label for="db_hote"><?= e(t('console.db.hote')) ?></label><input id="db_hote" name="db_hote" value="<?= e((string) $base['hote']) ?>"<?= $inv('base', 'hote') ?>><?= $err('base', 'hote') ?></div>
                <div class="champ"><label for="db_port"><?= e(t('console.db.port')) ?></label><input id="db_port" name="db_port" inputmode="numeric" value="<?= e((string) $base['port']) ?>"<?= $inv('base', 'port') ?>><?= $err('base', 'port') ?></div>
                <div class="champ"><label for="db_nom"><?= e(t('console.db.nom')) ?></label><input id="db_nom" name="db_nom" value="<?= e((string) $base['nom']) ?>" placeholder="<?= e($suggestion) ?>" autocapitalize="none" spellcheck="false"<?= $inv('base', 'nom') ?>><?= $err('base', 'nom') ?></div>
                <div class="champ"><label for="db_utilisateur"><?= e(t('console.db.utilisateur')) ?></label><input id="db_utilisateur" name="db_utilisateur" value="<?= e((string) $base['utilisateur']) ?>" placeholder="<?= e($suggestion) ?>" autocapitalize="none" spellcheck="false"<?= $inv('base', 'utilisateur') ?>><?= $err('base', 'utilisateur') ?></div>
                <div class="champ"><label for="db_mot_de_passe"><?= e(t('console.db.mot_de_passe')) ?></label><input id="db_mot_de_passe" name="db_mot_de_passe" type="password" autocomplete="new-password"<?= $inv('base', 'mot_de_passe') ?>>
                    <?php if ($testRetenu !== null): ?><p class="champ__aide"><?= e(t('console.db.mot_de_passe_retenu')) ?></p><?php endif; ?><?= $err('base', 'mot_de_passe') ?></div>
            </div>
            <div class="con-actions">
                <button class="bouton bouton--secondaire" type="submit" formaction="/clients/<?= $id ?>/tester"><?= e(t('console.db.tester')) ?></button>
                <button class="bouton" type="submit"><?= e(t('console.installation_instance.installer')) ?></button>
            </div>
        </form>
    </section>
<?php endif; ?>

<div class="con-colonnes">
    <section class="bloc" id="abonnement" aria-labelledby="con-abo-titre">
        <h2 id="con-abo-titre"><?= e(t('console.client.abonnement')) ?></h2>
        <?php if ($jalons !== []): ?>
            <ol class="con-echeancier" aria-label="<?= e(t('console.echeancier.titre')) ?>">
                <?php foreach ($jalons as $j): $actuel = $statut['code'] === $j['code'] || ($j['code'] === 'echeance' && in_array($statut['code'], ['actif', 'essai'], true)); ?>
                    <li class="con-jalon<?= $actuel ? ' con-jalon--actuel' : '' ?>"<?= $actuel ? ' aria-current="step"' : '' ?>><span class="con-jalon__date montant"><?= e(A::date($j['date'])) ?></span><span class="con-jalon__libelle"><?= e($j['libelle']) ?></span></li>
                <?php endforeach; ?>
            </ol>
            <p class="aide con-petit"><?= e(t('console.echeancier.aide', ['n' => Abonnement::JOURS_RELANCE])) ?> <?= e(A::relatif($statut['jours'])) ?></p>
        <?php endif; ?>
        <form method="post" action="/clients/<?= $id ?>/abonnement" class="con-form" novalidate>
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <div class="con-grille">
                <div class="champ"><label for="abo-formule"><?= e(t('console.client.formule')) ?></label>
                    <select id="abo-formule" name="formule_id"<?= $inv('abonnement', 'formule_id') ?>>
                        <?php foreach ($formules as $f): ?>
                            <option value="<?= (int) $f['id'] ?>"<?= (string) $abo['formule_id'] === (string) $f['id'] ? ' selected' : '' ?>><?= e($f['nom']) ?><?= $f['actif'] ? '' : ' (' . e(t('console.formule.archivee')) . ')' ?></option>
                        <?php endforeach; ?>
                    </select><?= $err('abonnement', 'formule_id') ?></div>
                <div class="champ"><label for="abo-periode"><?= e(t('console.client.periode')) ?></label>
                    <select id="abo-periode" name="periode"<?= $inv('abonnement', 'periode') ?>>
                        <?php foreach (array_keys(Abonnement::PERIODES) as $p): ?><option value="<?= e($p) ?>"<?= $abo['periode'] === $p ? ' selected' : '' ?>><?= e(t('console.periode.' . $p)) ?></option><?php endforeach; ?>
                    </select><?= $err('abonnement', 'periode') ?></div>
                <div class="champ"><label for="abo-debut"><?= e(t('console.client.debut')) ?></label><input id="abo-debut" name="debut" type="date" value="<?= e((string) $abo['debut']) ?>"<?= $inv('abonnement', 'debut') ?>><?= $err('abonnement', 'debut') ?></div>
                <div class="champ"><label for="abo-echeance"><?= e(t('console.client.echeance')) ?></label><input id="abo-echeance" name="echeance" type="date" value="<?= e((string) $abo['echeance']) ?>"<?= $inv('abonnement', 'echeance') ?>><?= $err('abonnement', 'echeance') ?></div>
                <div class="champ"><label for="abo-grace"><?= e(t('console.client.jours_grace')) ?></label><input id="abo-grace" name="jours_grace" inputmode="numeric" value="<?= e((string) $abo['jours_grace']) ?>"<?= $inv('abonnement', 'jours_grace') ?>><?= $err('abonnement', 'jours_grace') ?></div>
                <div class="champ"><label for="abo-lecture"><?= e(t('console.client.jours_lecture_seule')) ?></label><input id="abo-lecture" name="jours_lecture_seule" inputmode="numeric" value="<?= e((string) $abo['jours_lecture_seule']) ?>"<?= $inv('abonnement', 'jours_lecture_seule') ?>><?= $err('abonnement', 'jours_lecture_seule') ?></div>
            </div>
            <label class="coche"><input type="checkbox" name="essai" value="1"<?= !empty($abo['essai']) ? ' checked' : '' ?>> <?= e(t('console.client.en_essai')) ?></label>
            <div class="con-actions"><button class="bouton bouton--secondaire" type="submit"><?= e(t('console.enregistrer')) ?></button></div>
        </form>
    </section>

    <section class="bloc" id="paiements" aria-labelledby="con-pai-titre">
        <h2 id="con-pai-titre"><?= e(t('console.paiement.titre')) ?></h2>
        <form method="post" action="/clients/<?= $id ?>/paiements" class="con-form" novalidate data-form-paiement>
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <div class="con-grille">
                <div class="champ"><label for="pai-montant"><?= e(t('console.paiement.montant')) ?></label><input id="pai-montant" name="montant" inputmode="decimal" value="<?= e((string) ($pai['montant'] ?? ($prix !== null ? (string) (float) $prix : ''))) ?>"<?= $inv('paiement', 'montant') ?>><?= $err('paiement', 'montant') ?></div>
                <div class="champ"><label for="pai-devise"><?= e(t('console.paiement.devise')) ?></label>
                    <select id="pai-devise" name="devise"><?php foreach (Clients::DEVISES as $d): ?><option value="<?= e($d) ?>"<?= ($pai['devise'] ?? 'USD') === $d ? ' selected' : '' ?>><?= e($d) ?></option><?php endforeach; ?></select><?= $err('paiement', 'devise') ?></div>
                <div class="champ"><label for="pai-moyen"><?= e(t('console.paiement.moyen')) ?></label>
                    <select id="pai-moyen" name="moyen"<?= $inv('paiement', 'moyen') ?>><?php foreach (Clients::MOYENS as $m): ?><option value="<?= e($m) ?>"<?= ($pai['moyen'] ?? 'mobile_money') === $m ? ' selected' : '' ?>><?= e(t('console.moyen.' . $m)) ?></option><?php endforeach; ?></select><?= $err('paiement', 'moyen') ?></div>
                <div class="champ"><label for="pai-reference"><?= e(t('console.paiement.reference')) ?></label><input id="pai-reference" name="reference" value="<?= e((string) ($pai['reference'] ?? '')) ?>" placeholder="MP261026.1532.A12345"<?= $inv('paiement', 'reference') ?>><?= $err('paiement', 'reference') ?></div>
                <div class="champ"><label for="pai-date"><?= e(t('console.paiement.date')) ?></label><input id="pai-date" name="paye_le" type="date" max="<?= e(date('Y-m-d')) ?>" value="<?= e((string) ($pai['paye_le'] ?? date('Y-m-d'))) ?>"<?= $inv('paiement', 'paye_le') ?>><?= $err('paiement', 'paye_le') ?></div>
                <div class="champ"><label for="pai-periode"><?= e(t('console.client.periode')) ?></label>
                    <select id="pai-periode" name="periode"><?php foreach (array_keys(Abonnement::PERIODES) as $p): ?><option value="<?= e($p) ?>"<?= ($pai['periode'] ?? $client['periode']) === $p ? ' selected' : '' ?>><?= e(t('console.periode.' . $p)) ?></option><?php endforeach; ?></select></div>
                <div class="champ"><label for="pai-periodes"><?= e(t('console.paiement.periodes')) ?></label><input id="pai-periodes" name="periodes" inputmode="numeric" value="<?= e((string) ($pai['periodes'] ?? '1')) ?>"<?= $inv('paiement', 'periodes') ?>><?= $err('paiement', 'periodes') ?></div>
                <div class="champ"><label for="pai-echeance"><?= e(t('console.paiement.nouvelle_echeance')) ?></label><input id="pai-echeance" name="echeance" type="date" value="<?= e((string) ($pai['echeance'] ?? '')) ?>"<?= $inv('paiement', 'echeance') ?>><p class="champ__aide"><?= e(t('console.paiement.nouvelle_echeance_aide', ['date' => A::date(Abonnement::prolonger($client, $client['periode'], 1))])) ?></p><?= $err('paiement', 'echeance') ?></div>
            </div>
            <div class="champ"><label for="pai-note"><?= e(t('console.paiement.note')) ?></label><input id="pai-note" name="note" value="<?= e((string) ($pai['note'] ?? '')) ?>"></div>
            <div class="con-actions"><button class="bouton" type="submit"><?= e(t('console.paiement.enregistrer')) ?></button></div>
        </form>
    </section>
</div>

<section class="bloc" id="historique-paiements" aria-labelledby="con-hist-titre">
    <h2 id="con-hist-titre"><?= e(t('console.paiement.historique')) ?></h2>
    <?php if ($paiements !== []): ?>
        <div class="defile">
            <table class="tableau con-paiements">
                <thead><tr><th><?= e(t('console.paiement.date')) ?></th><th class="nombre"><?= e(t('console.paiement.montant')) ?></th><th><?= e(t('console.paiement.moyen')) ?></th><th><?= e(t('console.client.echeance')) ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($paiements as $p): ?>
                    <tr<?= $p['annule_le'] ? ' class="con-annule"' : '' ?> data-paiement="<?= (int) $p['id'] ?>">
                        <td class="montant"><?= e(A::date($p['paye_le'])) ?></td>
                        <td class="nombre montant"><?= e(A::montant($p['montant'], $p['devise'])) ?></td>
                        <td><?= e(t('console.moyen.' . $p['moyen'])) ?><?= $p['reference'] ? '<br><span class="aide con-petit">' . e($p['reference']) . '</span>' : '' ?></td>
                        <td class="montant"><?= e(A::date($p['echeance_avant'])) ?> → <?= e(A::date($p['echeance_apres'])) ?><br><span class="aide con-petit"><?= (int) $p['periodes'] ?> × <?= e(t('console.periode.' . $p['periode'])) ?> · <?= e($p['saisi_par_nom'] ?? '') ?></span></td>
                        <td>
                            <?php if ($p['annule_le']): ?>
                                <span class="badge badge--neutre" title="<?= e((string) $p['annule_motif']) ?>"><?= e(t('console.paiement.annule')) ?></span>
                            <?php elseif ((int) $p['id'] === $dernierValable): ?>
                                <details class="con-details"><summary class="lien"><?= e(t('console.paiement.annuler')) ?></summary>
                                    <form method="post" action="/clients/<?= $id ?>/paiements/<?= (int) $p['id'] ?>/annuler" class="con-form">
                                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                        <div class="champ"><label for="motif-<?= (int) $p['id'] ?>"><?= e(t('console.motif')) ?></label><input id="motif-<?= (int) $p['id'] ?>" name="motif" required></div>
                                        <button class="bouton bouton--danger bouton--petit" type="submit"><?= e(t('console.paiement.confirmer_annulation')) ?></button>
                                    </form>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="aide"><?= e(t('console.paiement.aucun')) ?></p>
    <?php endif; ?>
</section>

<?php if ($installe): ?>
<div class="con-colonnes">
    <section class="bloc" id="instance" aria-labelledby="con-instance-titre">
        <h2 id="con-instance-titre"><?= e(t('console.instance.titre')) ?></h2>
        <dl class="con-dl">
            <dt><?= e(t('console.instance.adresse')) ?></dt><dd><a class="lien" href="<?= e($url) ?>" target="_blank" rel="noopener"><?= e($url) ?></a></dd>
            <dt><?= e(t('console.db.hote')) ?></dt><dd><code><?= e((string) $client['db_hote']) ?>:<?= (int) $client['db_port'] ?></code></dd>
            <dt><?= e(t('console.db.nom')) ?></dt><dd><code><?= e((string) $client['db_nom']) ?></code></dd>
            <dt><?= e(t('console.db.utilisateur')) ?></dt><dd><code><?= e((string) $client['db_utilisateur']) ?></code></dd>
            <dt><?= e(t('console.db.mot_de_passe')) ?></dt><dd class="aide"><?= e(t('console.db.jamais_affiche')) ?></dd>
            <dt><?= e(t('console.instance.installee_le')) ?></dt><dd><?= e(A::dateHeure($client['installe_le'])) ?></dd>
            <dt><?= e(t('console.instance.connexion')) ?></dt><dd data-connexion="<?= $usage && $usage['ok'] ? 'ok' : 'echec' ?>"><?= $usage && $usage['ok'] ? '<span class="badge badge--succes">' . e(t('console.instance.connexion_ok')) . '</span>' : '<span class="badge badge--erreur">' . e(t('console.instance.connexion_echec')) . '</span> ' . e((string) ($usage['erreur'] ?? '')) ?></dd>
            <dt><?= e(t('console.instance.version')) ?></dt><dd data-migrations="<?= count($usage['migrations'] ?? []) ?>"><?= !empty($usage['migrations']) ? e(t('console.instance.en_attente', ['n' => count($usage['migrations']), 'liste' => implode(', ', $usage['migrations'])])) : e(t('console.instance.a_jour')) ?></dd>
        </dl>
        <div class="con-actions">
            <form method="post" action="/clients/<?= $id ?>/mettre-a-jour"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="bouton<?= empty($usage['migrations']) ? ' bouton--secondaire' : '' ?>" type="submit"><?= e(t('console.instance.mettre_a_jour')) ?></button></form>
            <form method="post" action="/clients/<?= $id ?>/sauvegarde"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="bouton bouton--secondaire" type="submit"><?= e(t('console.instance.sauvegarde')) ?></button></form>
        </div>
        <p class="aide con-petit"><?= e(t('console.instance.sauvegarde_aide')) ?></p>
    </section>

    <section class="bloc" id="usage" aria-labelledby="con-usage-titre">
        <h2 id="con-usage-titre"><?= e(t('console.usage.titre')) ?></h2>
        <p class="aide con-petit"><?= e(t('console.usage.aide')) ?></p>
        <?php if ($usage && $usage['ok']): ?>
            <div class="chiffres con-chiffres-petits">
                <div class="chiffre"><span class="chiffre__valeur" data-usage="utilisateurs"><?= (int) $usage['utilisateurs'] ?><?= $client['max_utilisateurs'] ? '<small> / ' . (int) $client['max_utilisateurs'] . '</small>' : '' ?></span><span class="chiffre__libelle"><?= e(t('console.usage.utilisateurs')) ?></span></div>
                <div class="chiffre"><span class="chiffre__valeur" data-usage="etablissements"><?= (int) $usage['etablissements'] ?><?= $client['max_etablissements'] ? '<small> / ' . (int) $client['max_etablissements'] . '</small>' : '' ?></span><span class="chiffre__libelle"><?= e(t('console.usage.etablissements')) ?></span></div>
                <div class="chiffre"><span class="chiffre__valeur" data-usage="sms"><?= (int) $usage['sms'] ?><small> / <?= (int) $client['sms_inclus'] ?></small></span><span class="chiffre__libelle"><?= e(t('console.usage.sms')) ?></span></div>
                <div class="chiffre"><span class="chiffre__valeur" data-usage="whatsapp"><?= (int) $usage['whatsapp'] ?></span><span class="chiffre__libelle"><?= e(t('console.usage.whatsapp')) ?></span></div>
            </div>
            <p class="aide con-petit"><?= e(t('console.usage.derniere_connexion', ['date' => A::dateHeure($usage['derniere_connexion'])])) ?>
                <?php if ((int) $usage['sms'] > (int) $client['sms_inclus']): ?> · <strong><?= e(t('console.usage.depassement', ['n' => (int) $usage['sms'] - (int) $client['sms_inclus']])) ?></strong><?php endif; ?></p>
        <?php else: ?>
            <p class="aide"><?= e(t('console.instance.connexion_echec')) ?></p>
        <?php endif; ?>
    </section>
</div>
<?php endif; ?>

<div class="con-colonnes">
    <section class="bloc" id="statut" aria-labelledby="con-statut-titre">
        <h2 id="con-statut-titre"><?= e(t('console.client.statut_main')) ?></h2>
        <p><?= A::badge($client) ?> <?= $statut['force'] ? e(t('console.client.force_depuis', ['date' => A::dateHeure($client['statut_force_le']), 'motif' => (string) $client['statut_force_motif']])) : e(t('console.client.selon_dates')) ?></p>
        <p class="aide con-petit"><?= e(t('console.client.jamais_service')) ?></p>
        <form method="post" action="/clients/<?= $id ?>/statut" class="con-form" novalidate>
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <div class="con-grille">
                <div class="champ"><label for="statut-action"><?= e(t('console.client.action')) ?></label>
                    <select id="statut-action" name="action">
                        <?php foreach (['suspendre', 'lecture_seule', 'actif', 'auto'] as $a): ?>
                            <option value="<?= e($a) ?>"<?= ($saisie['statut']['action'] ?? ($statut['force'] ? 'auto' : 'suspendre')) === $a ? ' selected' : '' ?>><?= e(t('console.client.action_' . $a)) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="champ"><label for="statut-motif"><?= e(t('console.motif')) ?></label><input id="statut-motif" name="motif"<?= $inv('statut', 'motif') ?>><?= $err('statut', 'motif') ?></div>
            </div>
            <div class="con-actions"><button class="bouton bouton--secondaire" type="submit"><?= e(t('console.appliquer')) ?></button></div>
        </form>
    </section>

    <section class="bloc" id="proprietaire" aria-labelledby="con-proprio-titre">
        <h2 id="con-proprio-titre"><?= e(t('console.proprietaire.titre')) ?></h2>
        <p class="aide"><?= e(t('console.proprietaire.aide', ['email' => (string) $client['contact_email']])) ?></p>
        <?php if (!$installe): ?>
            <p class="aide"><?= e(t('console.proprietaire.pas_installee')) ?></p>
        <?php else: ?>
            <form method="post" action="/clients/<?= $id ?>/proprietaire" onsubmit="return confirm(this.dataset.confirmer)" data-confirmer="<?= e(t('console.proprietaire.confirmer')) ?>">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <button class="bouton bouton--secondaire" type="submit"><?= e(t('console.proprietaire.bouton')) ?></button>
            </form>
        <?php endif; ?>
    </section>

    <section class="bloc" id="assistance" aria-labelledby="con-assist-titre">
        <h2 id="con-assist-titre"><?= e(t('console.assistance.titre')) ?></h2>
        <p class="aide"><?= e(t('console.assistance.aide')) ?></p>
        <?php if (!$installe): ?>
            <p class="aide"><?= e(t('console.assistance.pas_installee')) ?></p>
        <?php elseif ($assistance !== null): ?>
            <p data-assistance="oui"><span class="badge badge--glace"><?= e(t('console.assistance.autorisee')) ?></span> <?= e(t('console.assistance.jusqua', ['date' => A::dateHeure($assistance)])) ?></p>
            <form method="post" action="/clients/<?= $id ?>/assistance" target="_blank"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="bouton" type="submit"><?= e(t('console.assistance.ouvrir')) ?></button></form>
        <?php else: ?>
            <p data-assistance="non"><span class="badge badge--neutre"><?= e(t('console.assistance.non_autorisee')) ?></span> <?= e(t('console.assistance.demander')) ?></p>
        <?php endif; ?>
    </section>
</div>

<div class="con-colonnes">
    <section class="bloc" id="contact" aria-labelledby="con-contact-titre">
        <h2 id="con-contact-titre"><?= e(t('console.client.contact')) ?></h2>
        <form method="post" action="/clients/<?= $id ?>" class="con-form" novalidate>
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <div class="con-grille">
                <div class="champ"><label for="c-nom"><?= e(t('console.client.nom')) ?></label><input id="c-nom" name="nom" value="<?= e((string) $contact['nom']) ?>"<?= $inv('contact', 'nom') ?>><?= $err('contact', 'nom') ?></div>
                <div class="champ"><label for="c-contact"><?= e(t('console.client.contact_nom')) ?></label><input id="c-contact" name="contact_nom" value="<?= e((string) $contact['contact_nom']) ?>"></div>
                <div class="champ"><label for="c-email"><?= e(t('console.client.contact_email')) ?></label><input id="c-email" name="contact_email" type="email" value="<?= e((string) $contact['contact_email']) ?>"<?= $inv('contact', 'contact_email') ?>><?= $err('contact', 'contact_email') ?></div>
                <div class="champ"><label for="c-tel"><?= e(t('console.client.contact_telephone')) ?></label><input id="c-tel" name="contact_telephone" type="tel" value="<?= e((string) $contact['contact_telephone']) ?>"<?= $inv('contact', 'contact_telephone') ?>><?= $err('contact', 'contact_telephone') ?></div>
                <div class="champ"><label for="c-langue"><?= e(t('console.client.langue')) ?></label><select id="c-langue" name="langue"><option value="fr"<?= $contact['langue'] === 'fr' ? ' selected' : '' ?>>Français</option><option value="en"<?= $contact['langue'] === 'en' ? ' selected' : '' ?>>English</option></select></div>
            </div>
            <div class="champ"><label for="c-notes"><?= e(t('console.client.notes')) ?></label><textarea id="c-notes" name="notes" rows="2"><?= e((string) $contact['notes']) ?></textarea></div>
            <div class="con-actions"><button class="bouton bouton--secondaire" type="submit"><?= e(t('console.enregistrer')) ?></button></div>
        </form>
    </section>

    <section class="bloc" id="messagerie" aria-labelledby="con-msg-titre">
        <h2 id="con-msg-titre"><?= e(t('console.messagerie.expediteur_client')) ?></h2>
        <p class="aide"><?= e(t('console.messagerie.expediteur_client_aide', ['defaut' => $smsPlateforme ?? '—'])) ?></p>
        <form method="post" action="/clients/<?= $id ?>/expediteur" class="con-form con-ligne" novalidate>
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <div class="champ"><label for="expediteur"><?= e(t('console.messagerie.sender_id')) ?></label><input id="expediteur" name="expediteur_sms" maxlength="11" value="<?= e((string) ($saisie['expediteur'] ?? $client['expediteur_sms'])) ?>" placeholder="<?= e($smsPlateforme ?? '') ?>"<?= isset($erreurs['expediteur']) ? ' aria-invalid="true"' : '' ?>>
                <?= isset($erreurs['expediteur']) ? '<p class="champ__erreur">' . e($erreurs['expediteur']) . '</p>' : '' ?></div>
            <button class="bouton bouton--secondaire" type="submit"><?= e(t('console.enregistrer')) ?></button>
        </form>
    </section>
</div>

<section class="bloc" id="relances" aria-labelledby="con-rel-titre">
    <h2 id="con-rel-titre"><?= e(t('console.relances.titre')) ?></h2>
    <?php if ($messages === []): ?>
        <p class="aide"><?= e(t('console.relances.aucune')) ?></p>
    <?php else: ?>
        <div class="defile"><table class="tableau">
            <thead><tr><th><?= e(t('console.date')) ?></th><th><?= e(t('console.relances.etape')) ?></th><th><?= e(t('console.relances.canal')) ?></th><th><?= e(t('console.relances.statut')) ?></th></tr></thead>
            <tbody><?php foreach ($messages as $m): ?>
                <tr data-message="<?= e($m['canal'] . ':' . $m['statut']) ?>"><td class="montant"><?= e(A::dateHeure($m['cree_le'])) ?></td><td><?= e((string) $m['etape']) ?> <span class="aide con-petit">(<?= e(A::date($m['echeance'])) ?>)</span></td><td><?= e(t('console.canal.' . $m['canal'])) ?><br><span class="aide con-petit"><?= e($m['destinataire']) ?></span></td>
                    <td><span class="badge badge--<?= $m['statut'] === 'envoye' ? 'succes' : ($m['statut'] === 'echec' ? 'erreur' : 'neutre') ?>"><?= e(t('console.message.' . $m['statut'])) ?></span><?= $m['erreur'] ? '<br><span class="aide con-petit">' . e($m['erreur']) . '</span>' : '' ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>

<section class="bloc" aria-labelledby="con-jrn-titre">
    <h2 id="con-jrn-titre"><?= e(t('console.journal.titre')) ?></h2>
    <?= App\Core\View::rendre('console/_journal', ['journal' => $journal, 'avecClient' => false]) ?>
</section>
