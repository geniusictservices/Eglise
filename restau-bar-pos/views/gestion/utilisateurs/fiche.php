<?php
/**
 * Fiche d'une personne : identité, rôles avec leur portée, accès à la gestion, PIN de caisse.
 * @var ?array $personne null pour une nouvelle personne
 * @var array $valeurs
 * @var list<array{role_id: int, etablissement_id: ?int}> $rolesChoisis
 * @var list<array> $roles
 * @var list<array> $etablissements
 * @var array<string, string> $erreurs
 * @var ?string $pinMontre PIN tiré au hasard, montré une seule fois
 * @var bool $moiProprietaire
 * @var bool $aLaGestion
 * @var bool $modifiable la personne connectée peut-elle modifier cette fiche ?
 * @var ?string $flash
 */
use App\Core\CaissePartagee;
use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$action = $personne ? '/gestion/utilisateurs/' . (int) $personne['id'] : '/gestion/utilisateurs/nouveau';
$invalide = static fn (string $champ): string => isset($erreurs[$champ]) ? 'aria-invalid="true" aria-describedby="erreur-' . $champ . '"' : '';
$erreurChamp = static fn (string $champ): string => isset($erreurs[$champ])
    ? '<p class="champ__erreur" id="erreur-' . $champ . '">' . e($erreurs[$champ]) . '</p>'
    : '';
// Une ligne vide en plus pour ajouter un rôle sans JavaScript
$lignes = $rolesChoisis;
$lignes[] = ['role_id' => 0, 'etablissement_id' => null];
$ligneRole = static function (int $i, array $choix) use ($roles, $etablissements, $moiProprietaire): string {
    ob_start(); ?>
    <div class="uti-role" data-ligne-role>
        <div class="champ">
            <label for="role-<?= $i ?>"><?= e(t('utilisateurs.role')) ?></label>
            <select id="role-<?= $i ?>" name="role[]">
                <option value=""><?= e(t('utilisateurs.role_aucun')) ?></option>
                <?php foreach ($roles as $role): ?>
                    <option value="<?= (int) $role['id'] ?>" <?= (int) $role['id'] === $choix['role_id'] ? 'selected' : '' ?> <?= $role['tout'] && !$moiProprietaire ? 'disabled' : '' ?>><?= e(champ($role, 'nom')) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="champ">
            <label for="portee-<?= $i ?>"><?= e(t('utilisateurs.portee')) ?></label>
            <select id="portee-<?= $i ?>" name="portee[]">
                <option value="0"><?= e(t('utilisateurs.portee_tous')) ?></option>
                <?php foreach ($etablissements as $etab): ?>
                    <option value="<?= (int) $etab['id'] ?>" <?= (int) $etab['id'] === (int) $choix['etablissement_id'] ? 'selected' : '' ?>><?= e($etab['nom']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="button" class="bouton-icone uti-role__retirer" data-retirer-role title="<?= e(t('utilisateurs.role_retirer')) ?>" hidden>✕<span class="visuellement-cache"><?= e(t('utilisateurs.role_retirer')) ?></span></button>
    </div>
    <?php return (string) ob_get_clean();
};
?>
<link rel="stylesheet" href="/assets/css/gestion-utilisateurs.css">
<p class="uti-retour"><a class="lien" href="/gestion/utilisateurs">← <?= e(t('utilisateurs.titre')) ?></a></p>

<div class="uti-tete">
    <div class="uti-tete__nom">
        <?php if ($personne): ?><?= View::rendre('gestion/utilisateurs/_initiales', ['nom' => $personne['nom']]) ?><?php endif; ?>
        <div>
            <h1><?= e($personne ? $personne['nom'] : t('utilisateurs.nouveau')) ?></h1>
            <?php if ($personne): ?>
                <p class="aide">
                    <?= e($personne['derniere_connexion'] ? t('utilisateurs.derniere_connexion', ['date' => date('d/m/Y H:i', strtotime($personne['derniere_connexion']))]) : t('utilisateurs.jamais_connecte')) ?>
                    · <a class="lien" href="/gestion/utilisateurs/journal?personne=<?= (int) $personne['id'] ?>"><?= e(t('utilisateurs.voir_journal')) ?></a>
                </p>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($personne && !$personne['actif']): ?><span class="badge badge--neutre"><?= e(t('utilisateurs.inactif')) ?></span><?php endif; ?>
</div>

<?php if ($flash): ?>
    <p class="alerte" role="status"><?= e($flash) ?></p>
<?php endif; ?>
<?php if (isset($erreurs['general'])): ?>
    <p class="alerte alerte--erreur" role="alert"><?= e($erreurs['general']) ?></p>
<?php elseif ($erreurs !== []): ?>
    <p class="alerte alerte--erreur" role="alert"><?= e(t('utilisateurs.corrigez')) ?></p>
<?php endif; ?>
<?php if ($pinMontre !== null): ?>
    <div class="alerte uti-pin-montre" role="status" id="pin-montre">
        <p><?= e(t('utilisateurs.pin_genere', ['nom' => $personne['nom']])) ?></p>
        <p class="uti-pin-montre__valeur"><?= e(implode(' ', str_split($pinMontre, 3))) ?></p>
        <p class="aide"><?= e(t('utilisateurs.pin_genere_aide')) ?></p>
    </div>
<?php endif; ?>
<?php if ($personne && $modifiable && $personne['pin_bloque_le'] !== null): ?>
    <form method="post" action="/gestion/utilisateurs/<?= (int) $personne['id'] ?>/debloquer" class="alerte alerte--erreur uti-bloque">
        <input type="hidden" name="csrf" value="<?= $jeton ?>">
        <span><?= e(t('utilisateurs.pin_bloque_texte', ['date' => date('d/m/Y H:i', strtotime($personne['pin_bloque_le']))])) ?></span>
        <button class="bouton bouton--petit" type="submit"><?= e(t('utilisateurs.debloquer')) ?></button>
    </form>
<?php endif; ?>

<?php if (!$modifiable): ?>
    <p class="alerte uti-attention"><?= e(t('utilisateurs.lecture_seule')) ?></p>
<?php endif; ?>
<form method="post" action="<?= e($action) ?>" class="uti-formulaire" id="formulaire-utilisateur" novalidate>
    <input type="hidden" name="csrf" value="<?= $jeton ?>">
    <fieldset class="uti-fige" <?= $modifiable ? '' : 'disabled' ?>>

    <section class="bloc">
        <h2><?= e(t('utilisateurs.section_identite')) ?></h2>
        <div class="uti-grille">
            <div class="champ">
                <label for="nom"><?= e(t('utilisateurs.nom')) ?></label>
                <input id="nom" name="nom" required maxlength="128" value="<?= e($valeurs['nom'] ?? '') ?>" autocomplete="off" <?= $invalide('nom') ?>>
                <?= $erreurChamp('nom') ?>
            </div>
            <div class="champ">
                <label for="poste"><?= e(t('utilisateurs.poste')) ?> <span class="uti-facultatif"><?= e(t('utilisateurs.facultatif')) ?></span></label>
                <input id="poste" name="poste" maxlength="80" value="<?= e($valeurs['poste'] ?? '') ?>" placeholder="<?= e(t('utilisateurs.poste_exemple')) ?>">
            </div>
            <div class="champ">
                <label for="telephone"><?= e(t('utilisateurs.telephone')) ?> <span class="uti-facultatif"><?= e(t('utilisateurs.facultatif')) ?></span></label>
                <input id="telephone" name="telephone" type="tel" maxlength="32" value="<?= e($valeurs['telephone_saisi'] ?? afficherTelephone($valeurs['telephone'] ?? '')) ?>" placeholder="+243 81 000 0000" autocomplete="off" <?= $invalide('telephone') ?>>
                <?= $erreurChamp('telephone') ?>
            </div>
            <div class="champ">
                <label for="email"><?= e(t('utilisateurs.email')) ?> <span class="uti-facultatif"><?= e(t('utilisateurs.facultatif')) ?></span></label>
                <input id="email" name="email" type="email" maxlength="128" value="<?= e($valeurs['email'] ?? '') ?>" autocomplete="off" <?= $invalide('email') ?>>
                <?= $erreurChamp('email') ?>
            </div>
            <div class="champ">
                <label for="langue"><?= e(t('utilisateurs.langue')) ?></label>
                <select id="langue" name="langue">
                    <option value="fr" <?= ($valeurs['langue'] ?? 'fr') === 'fr' ? 'selected' : '' ?>>Français</option>
                    <option value="en" <?= ($valeurs['langue'] ?? '') === 'en' ? 'selected' : '' ?>>English</option>
                </select>
            </div>
        </div>
    </section>

    <section class="bloc" id="roles">
        <h2><?= e(t('utilisateurs.section_roles')) ?></h2>
        <p class="aide"><?= e(t('utilisateurs.roles_aide')) ?></p>
        <?= $erreurChamp('roles') ?>
        <div class="uti-roles" id="liste-roles">
            <?php foreach ($lignes as $i => $choix): ?>
                <?= $ligneRole($i, $choix) ?>
            <?php endforeach; ?>
        </div>
        <template id="modele-role"><?= $ligneRole(999, ['role_id' => 0, 'etablissement_id' => null]) ?></template>
        <button type="button" class="bouton bouton--secondaire bouton--petit" id="ajouter-role" hidden>+ <?= e(t('utilisateurs.role_ajouter')) ?></button>
        <p class="aide"><a class="lien" href="/gestion/utilisateurs/roles"><?= e(t('utilisateurs.voir_roles')) ?></a></p>
    </section>

    <section class="bloc">
        <h2><?= e(t('utilisateurs.section_gestion')) ?></h2>
        <p class="aide"><?= e(t('utilisateurs.gestion_aide')) ?></p>
        <?php if ($personne && $personne['a_mot_de_passe']): ?>
            <p><span class="badge badge--braise"><?= e(t('utilisateurs.a_mot_de_passe')) ?></span></p>
        <?php elseif ($personne && $aLaGestion): ?>
            <p class="alerte uti-attention"><?= e(t('utilisateurs.sans_mot_de_passe')) ?></p>
        <?php endif; ?>
        <div class="champ">
            <label for="mot_de_passe"><?= e($personne && $personne['a_mot_de_passe'] ? t('utilisateurs.mot_de_passe_nouveau') : t('utilisateurs.mot_de_passe')) ?> <span class="uti-facultatif"><?= e(t('utilisateurs.facultatif')) ?></span></label>
            <span class="mdp"><input id="mot_de_passe" name="mot_de_passe" type="password" autocomplete="new-password" maxlength="72" <?= $invalide('mot_de_passe') ?>></span>
            <p class="champ__aide"><?= e(t('utilisateurs.mot_de_passe_aide')) ?></p>
            <?= $erreurChamp('mot_de_passe') ?>
        </div>
    </section>

    <?php if (!$personne): ?>
        <section class="bloc">
            <h2><?= e(t('utilisateurs.section_pin')) ?></h2>
            <p class="aide"><?= e(t('utilisateurs.pin_aide', ['n' => CaissePartagee::LONGUEUR_PIN])) ?></p>
            <div class="champ uti-champ-pin">
                <label for="pin"><?= e(t('utilisateurs.pin')) ?> <span class="uti-facultatif"><?= e(t('utilisateurs.facultatif')) ?></span></label>
                <input id="pin" name="pin" type="password" inputmode="numeric" maxlength="<?= CaissePartagee::LONGUEUR_PIN ?>" pattern="\d{<?= CaissePartagee::LONGUEUR_PIN ?>}" autocomplete="off" <?= $invalide('pin') ?>>
                <?= $erreurChamp('pin') ?>
            </div>
        </section>
    <?php endif; ?>

    <div class="uti-actions">
        <button class="bouton" type="submit"><?= e($personne ? t('utilisateurs.enregistrer') : t('utilisateurs.creer')) ?></button>
        <a class="bouton bouton--fantome" href="/gestion/utilisateurs"><?= e(t('utilisateurs.annuler')) ?></a>
    </div>
    </fieldset>
</form>

<?php if ($personne && $modifiable): ?>
    <section class="bloc" id="section-pin">
        <h2><?= e(t('utilisateurs.section_pin')) ?></h2>
        <p class="aide"><?= e(t('utilisateurs.pin_aide', ['n' => CaissePartagee::LONGUEUR_PIN])) ?></p>
        <p><span class="badge <?= $personne['a_pin'] ? 'badge--glace' : 'badge--neutre' ?>"><?= e($personne['a_pin'] ? t('utilisateurs.a_pin') : t('utilisateurs.sans_pin')) ?></span></p>
        <form method="post" action="/gestion/utilisateurs/<?= (int) $personne['id'] ?>/pin" class="uti-pin">
            <input type="hidden" name="csrf" value="<?= $jeton ?>">
            <div class="champ uti-champ-pin">
                <label for="pin"><?= e(t('utilisateurs.pin_nouveau')) ?></label>
                <input id="pin" name="pin" type="password" inputmode="numeric" maxlength="<?= CaissePartagee::LONGUEUR_PIN ?>" pattern="\d{<?= CaissePartagee::LONGUEUR_PIN ?>}" autocomplete="off" <?= $invalide('pin') ?>>
                <?= $erreurChamp('pin') ?>
            </div>
            <div class="uti-pin__boutons">
                <button class="bouton bouton--secondaire" type="submit"><?= e(t('utilisateurs.pin_enregistrer')) ?></button>
                <button class="bouton bouton--secondaire" type="submit" name="action" value="generer" formnovalidate><?= e(t('utilisateurs.pin_generer')) ?></button>
                <?php if ($personne['a_pin']): ?>
                    <button class="bouton bouton--fantome" type="submit" name="action" value="retirer" formnovalidate><?= e(t('utilisateurs.pin_retirer')) ?></button>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="bloc">
        <h2><?= e(t('utilisateurs.section_etat')) ?></h2>
        <form method="post" action="/gestion/utilisateurs/<?= (int) $personne['id'] ?>/<?= $personne['actif'] ? 'desactiver' : 'activer' ?>">
            <input type="hidden" name="csrf" value="<?= $jeton ?>">
            <p class="aide"><?= e(t($personne['actif'] ? 'utilisateurs.desactiver_aide' : 'utilisateurs.activer_aide')) ?></p>
            <button class="bouton <?= $personne['actif'] ? 'bouton--danger' : 'bouton--secondaire' ?>" type="submit"><?= e(t($personne['actif'] ? 'utilisateurs.desactiver' : 'utilisateurs.activer')) ?></button>
        </form>
    </section>
<?php endif; ?>
<script src="/assets/js/gestion-utilisateurs.js" defer></script>
