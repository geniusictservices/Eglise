<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Database;
use App\Core\Journal;
use App\Core\Journee;
use App\Core\Session;
use App\Core\View;
use App\Repositories\ServiceRepository;

/**
 * Salle > Services horaires (cahier des charges, section 5) : pour chaque service des horaires de l'établissement
 * (Restaurant, Lounge, Club), sa grille de prix, les catégories vendues (la cuisine fermée disparaît de la
 * caisse) et ses modules (tables, cuisine, partage de l'addition, mode comptoir rapide) ; le happy hour
 * (une grille de prix sur une plage horaire) ; l'heure de fin de la journée d'exploitation.
 * Les heures des services se règlent dans Paramètres > Établissements > Horaires (elles sont aussi sur la page publique).
 * Voir : « salle.voir » ; modifier : « salle.services ».
 */
final class ServiceController
{
    private ServiceRepository $services;

    public function __construct()
    {
        $this->services = new ServiceRepository();
    }

    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $uid = (int) $utilisateur['id'];
        $liste = SalleAcces::etablissements($uid, 'salle.voir');
        $etab = SalleAcces::choisi($liste);
        if ($etab === null) {
            SalleAcces::interdit($utilisateur);
        }
        $categories = Database::tous('SELECT id, nom_fr, nom_en FROM categories WHERE parent_id IS NULL ORDER BY position, id');
        $grilles = Database::tous('SELECT id, nom_fr, nom_en FROM grilles_prix ORDER BY id');
        $maintenant = $this->services->grille($etab, null);
        View::afficher('gestion/salle/services', [
            'titre' => t('gsalle.services_titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'etablissements' => $liste,
            'etab' => $etab,
            'services' => $this->services->services($etab),
            'happyHours' => $this->services->happyHours((int) $etab['id']),
            'categories' => $categories,
            'grilles' => $grilles,
            'maintenant' => $maintenant,
            'defauts' => ServiceRepository::modulesParDefaut((string) $etab['type']),
            'journee' => Journee::aujourdhui($etab),
            'peutModifier' => Acces::a($uid, 'salle.services', (int) $etab['id']),
            'erreur' => $_SESSION['service_erreur'] ?? null,
        ], 'gestion');
        unset($_SESSION['service_erreur']);
    }

    /** POST : réglages d'un service (nom = celui des horaires). */
    public function enregistrer(): void
    {
        [$utilisateur, $etab] = $this->etablissement();
        $nom = SalleAcces::texte($_POST['nom_fr'] ?? '', 64);
        $noms = array_map(static fn (array $s) => $s['nom_fr'], $this->services->services($etab));
        if ($nom === '' || !in_array($nom, $noms, true)) {
            $this->retour($etab, null, t('gsalle.service_inconnu'));
        }
        $grille = SalleAcces::entier($_POST['grille'] ?? 0, 0, PHP_INT_MAX, 0);
        $grilles = array_map('intval', array_column(Database::tous('SELECT id FROM grilles_prix'), 'id'));
        $categories = array_values(array_intersect(
            array_map('intval', is_array($_POST['categories'] ?? null) ? $_POST['categories'] : []),
            array_map('intval', array_column(Database::tous('SELECT id FROM categories WHERE parent_id IS NULL'), 'id'))
        ));
        $modules = [];
        foreach (ServiceRepository::MODULES as $m) {
            $modules[$m] = !empty($_POST['modules'][$m]);
        }
        $this->services->enregistrer((int) $etab['id'], $nom, in_array($grille, $grilles, true) ? $grille : null, $modules, $categories);
        Journal::ecrire('service_regle', $nom, ['etablissement' => $etab['nom'], 'grille' => $grille ?: null, 'categories' => $categories, 'modules' => $modules], (int) $utilisateur['id']);
        $this->retour($etab, t('gsalle.service_enregistre', ['nom' => $nom]));
    }

    public function supprimer(string $id): void
    {
        [$utilisateur, $etab] = $this->etablissement();
        $this->services->supprimerReglage((int) $etab['id'], (int) $id);
        $this->retour($etab, t('gsalle.service_supprime'));
    }

    /** POST : heure de fin de la journée d'exploitation. */
    public function journee(): void
    {
        [$utilisateur, $etab] = $this->etablissement();
        $heure = SalleAcces::heure($_POST['fin_journee'] ?? null);
        if ($heure === null || $heure > '12:00') {
            $this->retour($etab, null, t('gsalle.journee_erreur'));
        }
        Database::executer('UPDATE etablissements SET fin_journee = ? WHERE id = ?', [$heure . ':00', (int) $etab['id']]);
        Journal::ecrire('fin_journee', $etab['nom'], ['heure' => $heure], (int) $utilisateur['id']);
        $this->retour($etab, t('gsalle.journee_enregistree', ['heure' => $heure]));
    }

    /** POST : ajouter ou modifier un happy hour. */
    public function happyHour(?string $id = null): void
    {
        [$utilisateur, $etab] = $this->etablissement();
        $nomFr = SalleAcces::texte($_POST['nom_fr'] ?? '', 64);
        $nomEn = SalleAcces::texte($_POST['nom_en'] ?? '', 64);
        $debut = SalleAcces::heure($_POST['debut'] ?? null);
        $fin = SalleAcces::heure($_POST['fin'] ?? null);
        $jours = array_values(array_unique(array_filter(array_map('intval', is_array($_POST['jours'] ?? null) ? $_POST['jours'] : []), static fn (int $j) => $j >= 1 && $j <= 7)));
        sort($jours);
        $grille = SalleAcces::entier($_POST['grille'] ?? 0, 0, PHP_INT_MAX, 0);
        if ($nomFr === '' || $debut === null || $fin === null || $debut === $fin || $jours === []
            || Database::un('SELECT id FROM grilles_prix WHERE id = ?', [$grille]) === null) {
            $this->retour($etab, null, t('gsalle.happy_erreur'));
        }
        $valeurs = [$nomFr, $nomEn !== '' ? $nomEn : $nomFr, implode(',', $jours), $debut . ':00', $fin . ':00', $grille, !empty($_POST['actif']) || $id === null ? 1 : 0];
        if ($id !== null) {
            Database::executer('UPDATE happy_hours SET nom_fr = ?, nom_en = ?, jours = ?, debut = ?, fin = ?, grille_prix_id = ?, actif = ? WHERE id = ? AND etablissement_id = ?',
                array_merge($valeurs, [(int) $id, (int) $etab['id']]));
        } else {
            Database::executer('INSERT INTO happy_hours (nom_fr, nom_en, jours, debut, fin, grille_prix_id, actif, etablissement_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                array_merge($valeurs, [(int) $etab['id']]));
        }
        Journal::ecrire('happy_hour', $nomFr, ['etablissement' => $etab['nom'], 'jours' => $jours, 'debut' => $debut, 'fin' => $fin, 'grille' => $grille], (int) $utilisateur['id']);
        $this->retour($etab, t('gsalle.happy_enregistre', ['nom' => $nomFr]));
    }

    public function supprimerHappyHour(string $id): void
    {
        [$utilisateur, $etab] = $this->etablissement();
        Database::executer('DELETE FROM happy_hours WHERE id = ? AND etablissement_id = ?', [(int) $id, (int) $etab['id']]);
        $this->retour($etab, t('gsalle.happy_supprime'));
    }

    /** @return array{0: array, 1: array} personne et établissement (droit « salle.services ») */
    private function etablissement(): array
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        return [$utilisateur, SalleAcces::exiger($utilisateur, 'salle.services', SalleAcces::entier($_POST['etablissement'] ?? null, 0, PHP_INT_MAX, 0))];
    }

    private function retour(array $etab, ?string $flash, ?string $erreur = null): never
    {
        if ($flash !== null) {
            Session::flash($flash);
        }
        if ($erreur !== null) {
            $_SESSION['service_erreur'] = $erreur;
        }
        rediriger('/gestion/salle/services?etablissement=' . (int) $etab['id']);
    }
}
