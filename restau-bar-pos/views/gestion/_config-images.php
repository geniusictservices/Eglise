<?php
/**
 * Attribut data-config-images lu par public/assets/js/champ-image.js : adresses, jeton CSRF,
 * limites et textes traduits pour l'envoi d'images et la fenêtre « Choisir dans la médiathèque ».
 * @var ?int $octetsMax
 */
use App\Core\Images;
use App\Core\Session;

$octetsMax ??= Images::octetsMax();
$tailleMax = Images::tailleLisible($octetsMax);
$cles = [
    'expire', 'connexion', 'envoi', 'ajoutee', 'ajoutee_court', 'ajoutee_choisie', 'choisie', 'retiree',
    'dialogue_titre', 'fermer', 'rechercher', 'rechercher_exemple', 'aucune', 'aucune_recherche', 'plus', 'chargement',
    'erreur_liste', 'choisir_nom', 'glisser_ici', 'choisir_fichier', 'depot_aide', 'zone_label',
    'actuelle', 'refusee', 'en_attente', 'trop_de_fichiers',
];
$textes = [
    'trop_lourde' => t('image.trop_lourde', ['max' => $tailleMax]),
    'type_refuse' => t('image.type_refuse'),
    'echec' => t('image.envoi_echoue'),
];
foreach ($cles as $cle) {
    $textes[$cle] = t('champ_image.' . $cle, ['max' => $tailleMax]);
}
$config = [
    'urlEnvoi' => '/gestion/mediatheque/envoyer',
    'urlListe' => '/gestion/mediatheque/liste',
    'csrf' => Session::jetonCsrf(),
    'octetsMax' => $octetsMax,
    'types' => ['image/jpeg', 'image/png', 'image/webp'],
    'textes' => $textes,
];
?> data-config-images="<?= e(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"