<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Acces;
use App\Core\Avatars;
use App\Core\CaissePartagee;
use App\Core\Database;
use App\Core\Journal;
use App\Core\Reglement;
use App\Core\Session;
use App\Core\View;
use App\Repositories\CatalogueRepository;
use App\Repositories\DeviseRepository;
use App\Repositories\EtablissementRepository;
use App\Repositories\GestionTresorerieRepository;
use App\Repositories\VenteRepository;

/**
 * Écran de caisse : produits en grille et ticket en cours.
 * L'écran s'adapte au type d'établissement (mode comptoir rapide et thème sombre pour un nightclub).
 * L'enregistrement des ventes et l'encaissement arrivent en phase 3.
 */
final class CaisseController
{
    public function index(): void
    {
        $etablissements = new EtablissementRepository();
        $appareil = CaissePartagee::appareil();

        if ($appareil !== null) {
            // Caisse partagée : l'établissement est celui de l'appareil ; chacun entre avec son PIN
            $etab = $etablissements->parSlug($appareil['slug']);
            if ($etab === null) {
                pageIntrouvable();
            }
            $caissier = CaissePartagee::caissier();
            if ($caissier !== null && !Acces::a($caissier['id'], 'caisse.vendre', (int) $etab['id'])) {
                CaissePartagee::verrouiller();
                $caissier = null;
            }
            if ($caissier === null) {
                // ?personne=<id> : pavé du PIN de cette personne (sans JavaScript)
                $choisi = (int) ($_GET['personne'] ?? 0);
                $this->afficherQui($appareil, $etab, null, $choisi > 0 ? $choisi : null);
                return;
            }
            $personne = $caissier;
            $liste = [$etab];
        } else {
            // Appareil personnel : il faut être connecté à la gestion avec le droit de vendre
            $utilisateur = Session::exigerConnexion();
            $permis = Acces::etablissements((int) $utilisateur['id'], 'caisse.vendre');
            $liste = array_values(array_filter(
                $etablissements->actifs(),
                static fn (array $e) => $permis === null || in_array((int) $e['id'], $permis, true)
            ));
            if ($liste === []) {
                http_response_code(403);
                View::afficher('gestion/interdit', ['titre' => t('acces.interdit_titre'), 'utilisateur' => $utilisateur], 'gestion');
                return;
            }
            $etab = $liste[0];
            if (isset($_GET['e']) && is_string($_GET['e'])) {
                foreach ($liste as $candidat) {
                    if ($candidat['slug'] === $_GET['e']) {
                        $etab = $candidat;
                    }
                }
            }
            $personne = ['id' => (int) $utilisateur['id'], 'nom' => $utilisateur['nom']];
        }
        $id = (int) $etab['id'];
        $zones = $etablissements->zones($id);
        $contexte = $this->contexte($etab, is_string($_GET['zone'] ?? null) ? $_GET['zone'] : null);
        $zone = $contexte['zone'];
        $donnees = $contexte['donnees'];
        // Comptes de trésorerie où peuvent entrer les paiements de cet établissement, par moyen et devise
        $donnees['comptes'] = $this->comptesCaisse($id, $contexte['acceptees']);
        $donnees['encaisser'] = Acces::a($personne['id'], 'caisse.encaisser', $id);
        $donnees['etab'] = $etab['slug'];
        $donnees['zone'] = $zone['code'] ?? '';
        $personne['avatar'] = Database::un('SELECT avatar FROM utilisateurs WHERE id = ?', [$personne['id']])['avatar'] ?? null;
        Session::demarrer();
        $messagePhoto = $_SESSION['caisse_photo'] ?? null;
        unset($_SESSION['caisse_photo']);
        View::afficher('caisse/index', [
            'titre' => t('caisse.titre'),
            'utilisateur' => $personne,
            'partagee' => $appareil !== null,
            'appareil' => $appareil,
            // « Revenir aux modules » seulement pour qui a des modules de gestion
            'aLaGestion' => Acces::aLaGestion($personne['id']),
            'peutAutoriser' => $appareil === null && Acces::a($personne['id'], 'caisse.appareils', $id),
            'photoPermise' => (bool) $etab['photo_a_la_caisse'],
            'scripts' => ['/assets/js/caisse.js', '/assets/js/photo-profil.js'],
            'messagePhoto' => $messagePhoto,
            'etab' => $etab,
            'etablissements' => $liste,
            'zones' => $zones,
            'zone' => $zone,
            // Ticket gardé par personne : un serveur ne retrouve pas le ticket du précédent
            'regle' => $appareil !== null ? CaissePartagee::regle($etab) : null,
            // Verrouillage choisi par l'administrateur (caisses partagées seulement)
            'donnees' => $donnees + ['cleTicket' => $etab['slug'] . '.' . $personne['id'], 'verrou' => [
                'minutes' => $appareil !== null ? (int) $etab['verrou_minutes'] : 0,
                'apresEncaissement' => $appareil !== null && (bool) $etab['verrou_apres_encaissement'],
            ]],
        ], 'caisse');
    }

    /**
     * Zone, grille de prix, devises acceptées, devise du ticket et catalogue de la caisse pour un établissement.
     * @return array{zone: ?array, grille: int, grilleDefaut: int, acceptees: array<int, array>, principale: array, donnees: array}
     */
    private function contexte(array $etab, ?string $codeZone): array
    {
        $etablissements = new EtablissementRepository();
        $devises = new DeviseRepository();
        $id = (int) $etab['id'];
        $zone = $codeZone !== null && $codeZone !== '' ? $etablissements->zoneParCode($id, $codeZone) : null;
        $grilleDefaut = (int) $etab['grille_prix_id'];
        $grille = $zone && $zone['grille_prix_id'] ? (int) $zone['grille_prix_id'] : $grilleDefaut;
        $acceptees = $devises->accepteesParmi($etablissements->deviseIds($id));
        // Tickets en devise locale, le dollar en deuxième position (ordre de DeviseRepository::actives())
        $locale = $devises->locale();
        $principale = ($locale ? ($acceptees[(int) $locale['id']] ?? null) : null)
            ?? $acceptees[(int) $etab['devise_affichage_id']] ?? $devises->reference() ?? reset($acceptees);
        return [
            'zone' => $zone, 'grille' => $grille, 'grilleDefaut' => $grilleDefaut, 'acceptees' => $acceptees, 'principale' => $principale,
            'donnees' => $this->donnees($id, $grille, $grilleDefaut, $acceptees, $principale, $etab['type']),
        ];
    }

    /** @return list<array{id: int, nom: string, moyen: string, devise: string}> comptes actifs de l'établissement (ou de tous) dans une devise acceptée */
    private function comptesCaisse(int $etablissementId, array $acceptees): array
    {
        $codes = array_column($acceptees, 'code', 'id');
        $comptes = [];
        foreach ((new GestionTresorerieRepository())->comptesActifs() as $compte) {
            $portee = $compte['etablissement_id'] === null ? null : (int) $compte['etablissement_id'];
            if (($portee === null || $portee === $etablissementId) && isset($codes[(int) $compte['devise_id']])) {
                $comptes[] = ['id' => (int) $compte['id'], 'nom' => $compte['nom'], 'moyen' => $compte['type'], 'devise' => $codes[(int) $compte['devise_id']]];
            }
        }
        return $comptes;
    }

    /**
     * Personne qui vend sur ce navigateur et son établissement : la personne connectée par PIN
     * (caisse partagée), ou le compte de gestion (appareil personnel, établissement « e »).
     * @return array{personne: array, etab: array, appareil: ?array}|null
     */
    private function vendeur(?string $slug): ?array
    {
        $etablissements = new EtablissementRepository();
        $appareil = CaissePartagee::appareil();
        if ($appareil !== null) {
            $caissier = CaissePartagee::caissier();
            $etab = $etablissements->parSlug($appareil['slug']);
            return $caissier && $etab ? ['personne' => $caissier, 'etab' => $etab, 'appareil' => $appareil] : null;
        }
        $utilisateur = Session::utilisateur();
        $etab = $slug !== null ? $etablissements->parSlug($slug) : null;
        return $utilisateur && $etab ? ['personne' => ['id' => (int) $utilisateur['id'], 'nom' => $utilisateur['nom']], 'etab' => $etab, 'appareil' => null] : null;
    }

    /**
     * Encaisser le ticket : la caisse envoie les lignes (format, quantité) et les paiements (devise, moyen, compte, montant reçu).
     * Le serveur recalcule les prix, les totaux et le règlement ; il refuse tout ce qui ne colle pas.
     * Réponse JSON : numéro, reçu (HTML), et si la caisse est verrouillée (réglage de l'établissement).
     */
    public function encaisser(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        Session::demarrer();
        $jeton = $_POST['csrf'] ?? '';
        if (!is_string($jeton) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $jeton)) {
            $this->repondre(419, ['erreur' => t('encaisser.expire')]);
        }
        $demande = json_decode(is_string($_POST['vente'] ?? null) ? $_POST['vente'] : '', true);
        if (!is_array($demande)) {
            $this->repondre(422, ['erreur' => t('encaisser.illisible')]);
        }
        $vendeur = $this->vendeur(is_string($demande['etab'] ?? null) ? $demande['etab'] : null);
        if ($vendeur === null) {
            $this->repondre(401, ['erreur' => t('encaisser.connexion')]);
        }
        ['personne' => $personne, 'etab' => $etab, 'appareil' => $appareil] = $vendeur;
        $etabId = (int) $etab['id'];
        if (!Acces::a($personne['id'], 'caisse.encaisser', $etabId)) {
            $this->repondre(403, ['erreur' => t('encaisser.droit')]);
        }
        $cle = is_string($demande['cle'] ?? null) ? $demande['cle'] : '';
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $cle)) {
            $this->repondre(422, ['erreur' => t('encaisser.illisible')]);
        }
        $ventes = new VenteRepository();
        if (($deja = $ventes->parCle($cle)) !== null) {
            // Double envoi : la même vente, sans rien refaire
            $this->repondreVente((int) $deja['id'], $etab, $appareil);
        }

        $contexte = $this->contexte($etab, is_string($demande['zone'] ?? null) ? $demande['zone'] : null);
        $acceptees = array_column($contexte['acceptees'], null, 'code');
        $principale = $contexte['principale'];
        $formats = [];
        foreach ($contexte['donnees']['produits'] as $produit) {
            foreach ($produit['formats'] as $format) {
                $formats[$format['id']] = ['format' => $format, 'produit' => $produit];
            }
        }

        // Lignes : formats existants, disponibles, avec un prix dans la devise du ticket
        $lignes = [];
        $totaux = array_fill_keys(array_keys($acceptees), 0.0);
        foreach (is_array($demande['lignes'] ?? null) ? $demande['lignes'] : [] as $ligne) {
            $formatId = (int) ($ligne['id'] ?? 0);
            $quantite = (int) ($ligne['qte'] ?? 0);
            $f = $formats[$formatId] ?? null;
            if ($f === null || $quantite < 1 || $quantite > 999 || !$f['format']['disponible'] || ($f['format']['prix'][$principale['code']] ?? null) === null) {
                $this->repondre(422, ['erreur' => t('encaisser.ligne_invalide')]);
            }
            foreach (array_keys($totaux) as $code) {
                $prix = $f['format']['prix'][$code] ?? null;
                // Une devise où un article n'a pas de prix ne peut pas servir à payer ce ticket
                $totaux[$code] = $prix === null || $totaux[$code] === null ? null : $totaux[$code] + (float) $prix * $quantite;
            }
            $nom = $f['format']['taille'] !== '' ? $f['produit']['nom'] . ' ' . $f['format']['taille'] : $f['produit']['nom'];
            $reference = $this->devisesRepo()->reference();
            $lignes[] = [
                'produit_id' => (int) $f['format']['tailleId'], 'conditionnement_id' => $formatId,
                'libelle' => $nom, 'format' => (!$f['format']['unite'] || $f['format']['freres'] > 1) ? $f['format']['nom'] : null,
                'quantite' => $quantite, 'prix' => (float) $f['format']['prix'][$principale['code']],
                'prix_reference' => $reference && isset($f['format']['prix'][$reference['code']]) ? (float) $f['format']['prix'][$reference['code']] : null,
            ];
        }
        if ($lignes === []) {
            $this->repondre(422, ['erreur' => t('encaisser.vide')]);
        }
        $totaux = array_filter($totaux, static fn ($t) => $t !== null);

        // Paiements : devise du ticket, moyen et compte de cet établissement, montant positif
        $comptes = array_column($this->comptesCaisse($etabId, $contexte['acceptees']), null, 'id');
        $tresorerie = new GestionTresorerieRepository();
        $tous = $tresorerie->comptesActifs();
        $paiements = [];
        foreach (is_array($demande['paiements'] ?? null) ? $demande['paiements'] : [] as $p) {
            $code = is_string($p['devise'] ?? null) ? $p['devise'] : '';
            $moyen = is_string($p['moyen'] ?? null) ? $p['moyen'] : '';
            $compte = $comptes[(int) ($p['compte'] ?? 0)] ?? null;
            $recu = is_numeric($p['recu'] ?? null) ? round((float) $p['recu'], (int) ($acceptees[$code]['decimales'] ?? 2)) : 0.0;
            if (!isset($totaux[$code]) || $compte === null || $compte['moyen'] !== $moyen || $compte['devise'] !== $code || $recu <= 0 || $recu > 1e12) {
                $this->repondre(422, ['erreur' => t('encaisser.paiement_invalide')]);
            }
            $paiements[] = ['moyen' => $moyen, 'compte' => $tous[$compte['id']], 'devise' => $acceptees[$code], 'recu' => $recu, 'taux' => $this->taux($acceptees[$code])];
        }
        if ($paiements === []) {
            $this->repondre(422, ['erreur' => t('encaisser.sans_paiement')]);
        }
        $reglement = Reglement::calculer(
            $totaux, $acceptees,
            array_map(static fn (array $p) => ['devise' => $p['devise']['code'], 'recu' => $p['recu']], $paiements),
            $principale['code']
        );
        if (!$reglement['regle']) {
            $this->repondre(422, ['erreur' => t('encaisser.reste', ['reste' => $this->devisesRepo()->formater($reglement['reste'][$principale['code']], $principale)])]);
        }

        // Monnaie rendue en espèces, dans la devise choisie, depuis la caisse de cette devise
        $rendu = null;
        if (array_sum($reglement['rendu']) > 0) {
            $codeRendu = is_string($demande['rendu'] ?? null) && isset($reglement['rendu'][$demande['rendu']]) ? $demande['rendu'] : $principale['code'];
            $caisseRendu = null;
            foreach ($comptes as $compte) {
                if ($compte['moyen'] === 'especes' && $compte['devise'] === $codeRendu) {
                    $caisseRendu = $tous[$compte['id']];
                    break;
                }
            }
            if ($caisseRendu === null) {
                $this->repondre(422, ['erreur' => t('encaisser.rendu_impossible', ['devise' => $codeRendu])]);
            }
            if ($reglement['rendu'][$codeRendu] > 0) {
                $rendu = ['montant' => $reglement['rendu'][$codeRendu], 'devise' => $acceptees[$codeRendu], 'compte' => $caisseRendu, 'taux' => $this->taux($acceptees[$codeRendu])];
            }
        }

        $reference = $this->devisesRepo()->reference();
        $id = $ventes->enregistrer([
            'etablissement' => $etab, 'cle' => $cle, 'zone_id' => $contexte['zone'] ? (int) $contexte['zone']['id'] : null,
            'grille_id' => $contexte['grille'], 'utilisateur_id' => $personne['id'], 'appareil_id' => $appareil ? (int) $appareil['id'] : null,
            'devise' => $principale, 'total' => $totaux[$principale['code']],
            'reference' => $reference, 'total_reference' => $reference ? ($totaux[$reference['code']] ?? null) : null,
            'totaux' => array_map(fn (string $code) => ['devise' => $acceptees[$code], 'total' => $totaux[$code], 'taux' => $this->taux($acceptees[$code])], array_keys($totaux)),
            'lignes' => $lignes, 'paiements' => $paiements, 'rendu' => $rendu,
        ]);
        $this->repondreVente($id, $etab, $appareil);
    }

    /** Réponse d'une vente enregistrée ; la caisse partagée se verrouille si l'établissement l'exige. */
    private function repondreVente(int $id, array $etab, ?array $appareil): never
    {
        $verrouille = false;
        if ($appareil !== null && $etab['verrou_apres_encaissement']) {
            CaissePartagee::verrouiller();
            $verrouille = true;
        }
        $recu = (new VenteRepository())->recu($id);
        $this->repondre(200, [
            'numero' => $recu['vente']['numero'],
            'recu' => View::rendre('caisse/_recu', $recu),
            'verrouille' => $verrouille,
            'csrf' => Session::jetonCsrf(),
        ]);
    }

    private function repondre(int $code, array $donnees): never
    {
        http_response_code($code);
        echo json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    private ?DeviseRepository $devisesRepo = null;

    private function devisesRepo(): DeviseRepository
    {
        return $this->devisesRepo ??= new DeviseRepository();
    }

    /** Taux en vigueur (unités de la devise pour 1 unité de référence), gardé avec chaque paiement. */
    private function taux(array $devise): ?float
    {
        $taux = $this->devisesRepo()->actives()[(int) $devise['id']]['taux'] ?? null;
        return $taux !== null && (float) $taux > 0 ? (float) $taux : null;
    }

    /** Connexion par PIN sur une caisse partagée. */
    public function pin(): void
    {
        Session::verifierCsrf();
        $appareil = CaissePartagee::appareil();
        if ($appareil === null) {
            http_response_code(403);
            exit(t('caisse_pin.appareil_requis'));
        }
        $etab = (new EtablissementRepository())->parSlug($appareil['slug']);
        if ($etab === null) {
            pageIntrouvable();
        }
        $appareilId = (int) $appareil['id'];
        if (CaissePartagee::appareilSature($appareilId)) {
            $this->afficherQui($appareil, $etab, t('caisse_pin.appareil_sature'), null, 429);
            return;
        }
        $utilisateurId = (int) ($_POST['utilisateur_id'] ?? 0);
        $pin = (string) ($_POST['pin'] ?? '');
        $personne = $utilisateurId > 0
            ? Database::un('SELECT * FROM utilisateurs WHERE id = ? AND actif = 1 AND pin IS NOT NULL', [$utilisateurId])
            : null;
        if ($personne === null || !Acces::a($utilisateurId, 'caisse.vendre', (int) $etab['id'])) {
            $this->afficherQui($appareil, $etab, t('caisse_pin.choisir'), null, 422);
            return;
        }
        if ($personne['pin_bloque_le'] !== null) {
            $this->afficherQui($appareil, $etab, t('caisse_pin.bloque', ['nom' => $personne['nom']]), $utilisateurId, 423);
            return;
        }
        if (!CaissePartagee::pinValide($pin) || !password_verify($pin, $personne['pin'])) {
            $essais = (int) $personne['pin_essais'] + 1;
            $bloque = $essais >= CaissePartagee::ESSAIS_PIN;
            Database::executer(
                'UPDATE utilisateurs SET pin_essais = ?, pin_bloque_le = ? WHERE id = ?',
                [$bloque ? 0 : $essais, $bloque ? date('Y-m-d H:i:s') : null, $utilisateurId]
            );
            Database::executer('UPDATE appareils_caisse SET pin_echecs = pin_echecs + 1 WHERE id = ?', [$appareilId]);
            Journal::ecrire($bloque ? 'pin_bloque' : 'pin_echec', $personne['nom'], [], $utilisateurId, $appareilId);
            if ($bloque) {
                // Compte aussi comme un échec pour l'appareil
                Journal::ecrire('pin_echec', $personne['nom'], [], $utilisateurId, $appareilId);
            }
            $message = $bloque
                ? t('caisse_pin.bloque', ['nom' => $personne['nom']])
                : t('caisse_pin.incorrect', ['reste' => CaissePartagee::ESSAIS_PIN - $essais]);
            $this->afficherQui($appareil, $etab, $message, $bloque ? null : $utilisateurId, 422);
            return;
        }
        Database::executer('UPDATE utilisateurs SET pin_essais = 0 WHERE id = ?', [$utilisateurId]);
        CaissePartagee::connecter($personne, $appareil);
        Journal::ecrire('pin_connexion', $appareil['nom'], [], $utilisateurId, $appareilId);
        $zone = is_string($_POST['zone'] ?? null) && preg_match('/^[a-z0-9-]+$/', $_POST['zone']) ? $_POST['zone'] : '';
        rediriger('/caisse' . ($zone !== '' ? '?zone=' . $zone : ''));
    }

    /** Photo de profil prise sur la caisse (selfie), si l'établissement le permet. */
    public function photo(): void
    {
        Session::verifierCsrf();
        $appareil = CaissePartagee::appareil();
        if ($appareil !== null) {
            $personne = CaissePartagee::caissier();
            $etab = (new EtablissementRepository())->parSlug($appareil['slug']);
        } else {
            $utilisateur = Session::exigerConnexion();
            $personne = ['id' => (int) $utilisateur['id'], 'nom' => $utilisateur['nom']];
            $etab = (new EtablissementRepository())->parSlug(is_string($_GET['e'] ?? null) ? $_GET['e'] : '');
        }
        if ($personne === null || $etab === null || !$etab['photo_a_la_caisse'] || !Acces::a($personne['id'], 'caisse.vendre', (int) $etab['id'])) {
            http_response_code(403);
            exit(t('acces.interdit_titre'));
        }
        $erreur = Avatars::traiterFormulaire($personne['id'], $personne['nom']);
        $_SESSION['caisse_photo'] = $erreur ?? t('profil.photo_enregistree_moi');
        rediriger('/caisse' . ($appareil === null ? '?e=' . $etab['slug'] : ''));
    }

    /** Verrouille la caisse partagée : retour à « Qui êtes-vous ? » (après chaque encaissement, ou à la demande). */
    public function verrouiller(): void
    {
        Session::verifierCsrf();
        $caissier = CaissePartagee::caissier();
        if ($caissier !== null) {
            Journal::ecrire('caisse_verrouillee', null, [], $caissier['id'], $caissier['appareil_id']);
        }
        CaissePartagee::verrouiller();
        rediriger('/caisse');
    }

    /** Un gérant fait de cet appareil une caisse partagée de l'établissement. */
    public function autoriserAppareil(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $etab = (new EtablissementRepository())->parSlug((string) ($_POST['e'] ?? ''));
        if ($etab === null || !Acces::a((int) $utilisateur['id'], 'caisse.appareils', (int) $etab['id'])) {
            http_response_code(403);
            View::afficher('gestion/interdit', ['titre' => t('acces.interdit_titre'), 'utilisateur' => $utilisateur], 'gestion');
            return;
        }
        $nom = trim((string) ($_POST['nom'] ?? ''));
        $nom = $nom === '' ? t('caisse_pin.nom_defaut', ['etablissement' => $etab['nom']]) : mb_substr($nom, 0, 80);
        $id = CaissePartagee::autoriser($nom, (int) $etab['id'], (int) $utilisateur['id']);
        Journal::ecrire('appareil_autorise', $nom, ['etablissement' => $etab['nom']], (int) $utilisateur['id'], $id);
        // Le gérant est déconnecté : la tablette attend maintenant le PIN de chacun
        Session::deconnecter();
        rediriger('/caisse');
    }

    /** Écran « Qui êtes-vous ? » : les personnes qui peuvent vendre ici, puis le pavé du PIN. */
    private function afficherQui(array $appareil, array $etab, ?string $erreur = null, ?int $choisi = null, int $code = 200): void
    {
        http_response_code($code);
        $personnes = [];
        foreach (Database::tous('SELECT id, nom, poste, avatar, pin_bloque_le FROM utilisateurs WHERE actif = 1 AND pin IS NOT NULL ORDER BY nom') as $ligne) {
            if (Acces::a((int) $ligne['id'], 'caisse.vendre', (int) $etab['id'])) {
                $personnes[] = $ligne;
            }
        }
        View::afficher('caisse/qui', [
            'titre' => t('caisse_pin.titre'),
            'etab' => $etab,
            'appareil' => $appareil,
            'personnes' => $personnes,
            'erreur' => $erreur,
            'choisi' => $choisi,
            'zone' => is_string($_GET['zone'] ?? null) ? $_GET['zone'] : (is_string($_POST['zone'] ?? null) ? $_POST['zone'] : ''),
            'scripts' => ['/assets/js/caisse-pin.js'],
        ], 'caisse');
    }

    /**
     * Catalogue de la caisse, avec le prix de chaque format dans chaque devise acceptée.
     * Les prix sont calculés côté serveur pour respecter les prix fixes et les arrondis de chaque devise.
     *
     * Onglets : catégories principales, chacune avec ses sous-catégories (« sous »).
     * Un produit à tailles est une seule tuile : ses formats sont ceux de toutes ses tailles,
     * chacun avec le libellé de sa taille (« Grande 72 cl ») et sa disponibilité.
     */
    private function donnees(int $etablissementId, int $grille, int $grilleDefaut, array $acceptees, array $principale, string $type): array
    {
        $catalogue = new CatalogueRepository(new DeviseRepository());
        $categories = [];
        $produits = [];

        foreach ($acceptees as $devise) {
            foreach ($catalogue->menu($etablissementId, $grille, $grilleDefaut, $devise, false) as $categorie) {
                $categories[$categorie['id']] ??= ['id' => $categorie['id'], 'nom' => champ($categorie, 'nom'), 'sous' => []];
                $groupes = [[$categorie, 0]];
                foreach ($categorie['sous_categories'] as $sous) {
                    $categories[$categorie['id']]['sous'][$sous['id']] ??= ['id' => $sous['id'], 'nom' => champ($sous, 'nom')];
                    $groupes[] = [$sous, (int) $sous['id']];
                }
                foreach ($groupes as [$groupe, $sousId]) {
                    foreach ($groupe['produits'] as $produit) {
                        $this->ajouterProduit($produits, $produit, (int) $categorie['id'], $sousId, $devise);
                    }
                }
            }
        }

        foreach ($produits as &$produit) {
            $produit['formats'] = array_values($produit['formats']);
        }
        unset($produit);
        foreach ($categories as &$categorie) {
            $categorie['sous'] = array_values($categorie['sous']);
        }
        unset($categorie);

        return [
            'mode' => $type === 'nightclub' ? 'rapide' : 'standard',
            'langue' => \App\Core\I18n::langue(),
            'principale' => $principale['code'],
            'devises' => array_values(array_map(static fn (array $d) => [
                'code' => $d['code'],
                'symbole' => $d['symbole'],
                'decimales' => (int) $d['decimales'],
                'symboleAvant' => (bool) $d['symbole_avant'],
                'arrondi' => (float) $d['arrondi'],
            ], $acceptees)),
            'categories' => array_values($categories),
            'produits' => array_values($produits),
        ];
    }

    /** Ajoute un produit (et ses tailles) au catalogue de la caisse, avec ses prix dans une devise. */
    private function ajouterProduit(array &$produits, array $produit, int $categorieId, int $sousId, array $devise): void
    {
        $id = (int) $produit['id'];
        $recherche = $produit['nom_fr'] . ' ' . $produit['nom_en'];
        foreach ($produit['tailles'] as $taille) {
            $recherche .= ' ' . $taille['taille_fr'] . ' ' . $taille['taille_en'] . ' ' . $taille['contenance'];
        }
        $produits[$id] ??= [
            'id' => $id,
            'categorie' => $categorieId,
            'sous' => $sousId,
            'nom' => champ($produit, 'nom'),
            'recherche' => mb_strtolower($recherche),
            'disponible' => (bool) $produit['disponible'],
            'photo' => urlImageSure($produit['photo']),
            'tailles' => count($produit['tailles']),
            'formats' => [],
        ];
        // Formats du produit lui-même, puis ceux de chaque taille
        $sources = [[$produit, '', (bool) $produit['disponible']]];
        foreach ($produit['tailles'] as $taille) {
            $sources[] = [$taille, $taille['libelle_taille'], (bool) $taille['disponible']];
        }
        foreach ($sources as [$source, $libelleTaille, $disponible]) {
            foreach ($source['conditionnements'] as $cond) {
                $produits[$id]['formats'][$cond['id']] ??= [
                    'id' => (int) $cond['id'],
                    'nom' => champ($cond, 'nom'),
                    'unite' => (float) $cond['quantite_base'] === 1.0,
                    'taille' => $libelleTaille,
                    'tailleId' => (int) $source['id'],
                    'freres' => count($source['conditionnements']),
                    'disponible' => $disponible,
                    'prix' => [],
                ];
                $produits[$id]['formats'][$cond['id']]['prix'][$devise['code']] = $cond['montant'];
            }
        }
    }
}
