/* Champ image (views/gestion/_champ-image.php) et envoi d'images vers la médiathèque.
   - Glisser-déposer un fichier sur le champ, toucher pour choisir, coller une image (Ctrl+V),
     ou « Choisir dans la médiathèque » (fenêtre <dialog> avec recherche et zone de dépôt).
   - Une image envoyée va dans la médiathèque (POST /gestion/mediatheque/envoyer, réponse JSON),
     puis son chemin est placé dans le champ caché du formulaire ; il reste à enregistrer le formulaire.
   - Sans JavaScript, l'ancien <input type="file"> fonctionne comme avant.
   Les outils d'envoi sont aussi utilisés par la page de la médiathèque (window.EnvoiImages). */
(function () {
  'use strict';

  var source = document.querySelector('[data-config-images]');
  if (!source || !window.FormData || !window.XMLHttpRequest) { return; }
  var config;
  try { config = JSON.parse(source.getAttribute('data-config-images')); } catch (e) { return; }
  var textes = config.textes || {};

  // « Envoi de « {nom} »… » → texte avec ses valeurs
  function texte(cle, valeurs) {
    var t = textes[cle] || '';
    Object.keys(valeurs || {}).forEach(function (nom) { t = t.split('{' + nom + '}').join(String(valeurs[nom])); });
    return t;
  }

  function element(balise, attributs, enfants) {
    var el = document.createElement(balise);
    Object.keys(attributs || {}).forEach(function (nom) {
      if (nom === 'texte') { el.textContent = attributs[nom]; } else if (attributs[nom] !== null && attributs[nom] !== false) { el.setAttribute(nom, attributs[nom] === true ? '' : attributs[nom]); }
    });
    (enfants || []).forEach(function (enfant) { if (enfant) { el.appendChild(enfant); } });
    return el;
  }

  // Contrôle avant l'envoi : type et poids (le serveur contrôle aussi)
  function verifier(fichier) {
    if ((config.types || []).indexOf(fichier.type) === -1) { return textes.type_refuse; }
    if (config.octetsMax && fichier.size > config.octetsMax) { return textes.trop_lourde; }
    return null;
  }

  // Envoi d'un fichier avec sa progression ; rend une promesse avec l'image enregistrée
  function envoyer(fichier, surProgression) {
    return new Promise(function (resoudre, rejeter) {
      var xhr = new XMLHttpRequest();
      xhr.open('POST', config.urlEnvoi);
      xhr.setRequestHeader('Accept', 'application/json');
      if (xhr.upload && surProgression) {
        xhr.upload.addEventListener('progress', function (evt) {
          if (evt.lengthComputable) { surProgression(Math.min(99, Math.round(evt.loaded / evt.total * 100))); }
        });
      }
      xhr.addEventListener('load', function () {
        var donnees = null;
        try { donnees = JSON.parse(xhr.responseText); } catch (e) { donnees = null; }
        if (xhr.status === 200 && donnees && donnees.medias && donnees.medias[0]) {
          if (surProgression) { surProgression(100); }
          resoudre(donnees.medias[0]);
        } else if (donnees && donnees.erreurs && donnees.erreurs[0]) {
          rejeter(new Error(donnees.erreurs[0].message));
        } else if (xhr.status === 419) {
          rejeter(new Error(textes.expire));
        } else if (xhr.status === 413) {
          rejeter(new Error(textes.trop_lourde));
        } else if (xhr.responseURL && xhr.responseURL.indexOf('/gestion/connexion') !== -1) {
          rejeter(new Error(textes.connexion));
        } else {
          rejeter(new Error(textes.echec));
        }
      });
      xhr.addEventListener('error', function () { rejeter(new Error(textes.echec)); });
      var donnees = new FormData();
      donnees.append('csrf', config.csrf);
      donnees.append('image', fichier, fichier.name || 'image.png');
      xhr.send(donnees);
    });
  }

  // Fichiers d'un glisser-déposer ou d'un collage
  function fichiersDe(transfert) {
    var liste = [];
    if (!transfert) { return liste; }
    if (transfert.files && transfert.files.length) {
      Array.prototype.forEach.call(transfert.files, function (f) { liste.push(f); });
    } else if (transfert.items) {
      Array.prototype.forEach.call(transfert.items, function (item) {
        if (item.kind === 'file') { var f = item.getAsFile(); if (f) { liste.push(f); } }
      });
    }
    return liste;
  }

  function avecFichiers(evt) {
    var types = evt.dataTransfer && evt.dataTransfer.types;
    return !!types && Array.prototype.indexOf.call(types, 'Files') !== -1;
  }

  // Zone de dépôt : surbrillance pendant le survol, fichiers transmis au dépôt
  function brancherDepot(cible, surFichiers, surligne, classe) {
    var profondeur = 0;
    var eteindre = function () { profondeur = 0; surligne.classList.remove(classe); };
    cible.addEventListener('dragenter', function (evt) {
      if (!avecFichiers(evt)) { return; }
      evt.preventDefault();
      profondeur++;
      surligne.classList.add(classe);
    });
    cible.addEventListener('dragover', function (evt) {
      if (!avecFichiers(evt)) { return; }
      evt.preventDefault();
      evt.dataTransfer.dropEffect = 'copy';
      surligne.classList.add(classe);
    });
    cible.addEventListener('dragleave', function () {
      profondeur = Math.max(0, profondeur - 1);
      if (profondeur === 0) { surligne.classList.remove(classe); }
    });
    cible.addEventListener('drop', function (evt) {
      if (!avecFichiers(evt)) { return; }
      evt.preventDefault();
      evt.stopPropagation();
      eteindre();
      var fichiers = fichiersDe(evt.dataTransfer);
      if (fichiers.length) { surFichiers(fichiers); }
    });
  }

  // Un fichier lâché à côté d'une zone ne doit pas ouvrir l'image à la place de la page
  window.addEventListener('dragover', function (evt) { if (avecFichiers(evt)) { evt.preventDefault(); } });
  window.addEventListener('drop', function (evt) { if (avecFichiers(evt)) { evt.preventDefault(); } });

  function estEditable(el) {
    return !!el && (el.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName));
  }

  window.EnvoiImages = {
    config: config, texte: texte, element: element, verifier: verifier, envoyer: envoyer,
    fichiersDe: fichiersDe, brancherDepot: brancherDepot, estEditable: estEditable
  };

  /* ---------- Fenêtre « Choisir dans la médiathèque » (une seule pour la page) ---------- */

  var choix = null;

  function fenetreChoix() {
    if (choix) { return choix; }
    var titre = element('h2', { id: 'choix-media-titre', 'class': 'choix-media__titre', texte: textes.dialogue_titre });
    var fermer = element('button', { type: 'button', 'class': 'bouton-icone', title: textes.fermer, 'data-fermer': true }, [
      element('span', { 'aria-hidden': 'true', texte: '×', 'class': 'choix-media__croix' }),
      element('span', { 'class': 'visuellement-cache', texte: textes.fermer })
    ]);
    var entree = element('input', { type: 'file', accept: (config.types || []).join(','), hidden: true, tabindex: '-1' });
    var parcourir = element('button', { type: 'button', 'class': 'bouton bouton--secondaire bouton--petit', texte: textes.choisir_fichier });
    var depot = element('div', { 'class': 'depot depot--compact' }, [
      element('p', { 'class': 'depot__titre depot__titre--glisser', texte: textes.glisser_ici }),
      parcourir,
      element('p', { 'class': 'depot__aide', texte: textes.depot_aide }),
      element('div', { 'class': 'depot__survol', 'aria-hidden': 'true', texte: textes.glisser_ici }),
      entree
    ]);
    var envois = element('ul', { 'class': 'envois envois--compact', hidden: true });
    var recherche = element('input', { id: 'choix-media-q', type: 'search', placeholder: textes.rechercher_exemple, autocomplete: 'off' });
    var etat = element('p', { 'class': 'choix-media__etat', role: 'status', 'aria-live': 'polite' });
    var grille = element('ul', { 'class': 'choix-media__grille' });
    var plus = element('button', { type: 'button', 'class': 'bouton bouton--secondaire bouton--petit choix-media__plus', hidden: true, texte: textes.plus });
    var corps = element('div', { 'class': 'choix-media__corps' }, [
      depot, envois,
      element('div', { 'class': 'champ choix-media__recherche' }, [element('label', { 'for': 'choix-media-q', texte: textes.rechercher }), recherche]),
      etat, grille, plus
    ]);
    var dialogue = element('dialog', { 'class': 'choix-media', 'aria-labelledby': 'choix-media-titre' }, [
      element('div', { 'class': 'choix-media__boite' }, [element('div', { 'class': 'choix-media__entete' }, [titre, fermer]), corps])
    ]);
    document.body.appendChild(dialogue);

    choix = { dialogue: dialogue, champ: null, page: 1, requete: 0, envoiEnCours: false };

    function charger(page) {
      var numero = ++choix.requete;
      if (page === 1) { grille.textContent = ''; }
      etat.textContent = textes.chargement;
      plus.hidden = true;
      var url = config.urlListe + '?q=' + encodeURIComponent(recherche.value.trim()) + '&page=' + page;
      fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (reponse) { if (!reponse.ok) { throw new Error(String(reponse.status)); } return reponse.json(); })
        .then(function (donnees) {
          if (numero !== choix.requete) { return; }
          donnees.medias.forEach(function (media) { grille.appendChild(carte(media)); });
          choix.page = page;
          plus.hidden = !donnees.suivante;
          etat.textContent = grille.children.length ? '' : (recherche.value.trim() ? textes.aucune_recherche : textes.aucune);
        })
        .catch(function () { if (numero === choix.requete) { etat.textContent = textes.erreur_liste; } });
    }

    function carte(media) {
      var actuelle = choix.champ && choix.champ.valeur() === media.chemin;
      var bouton = element('button', {
        type: 'button', 'class': 'choix-media__carte', 'aria-label': texte('choisir_nom', { nom: media.nom }),
        'aria-current': actuelle ? 'true' : null
      }, [
        element('span', { 'class': 'choix-media__vignette' }, [element('img', { src: media.chemin, alt: '', loading: 'lazy', width: '160', height: '160' })]),
        element('span', { 'class': 'choix-media__nom', texte: media.nom }),
        element('span', { 'class': 'choix-media__meta nombre', texte: media.dimensions + ' · ' + media.poids }),
        actuelle ? element('span', { 'class': 'badge badge--lave', texte: textes.actuelle }) : null
      ]);
      bouton.addEventListener('click', function () {
        if (choix.champ) { choix.champ.choisir(media, 'choisie'); }
        dialogue.close();
      });
      return element('li', {}, [bouton]);
    }

    // Nouvelle image envoyée depuis la fenêtre : elle est choisie aussitôt
    function envoyerDepuisFenetre(fichiers) {
      var fichier = fichiers[0];
      if (!fichier || choix.envoiEnCours) { return; }
      envois.hidden = false;
      var ligne = ligneEnvoi(fichier);
      envois.insertBefore(ligne.li, envois.firstChild);
      var erreur = verifier(fichier);
      if (erreur) { ligne.refuser(erreur); etat.textContent = fichier.name + ' : ' + erreur; return; }
      choix.envoiEnCours = true;
      envoyer(fichier, ligne.progression).then(function (media) {
        ligne.reussir(texte('ajoutee', { nom: media.nom }));
        if (choix.champ) { choix.champ.choisir(media, 'ajoutee_choisie'); }
        dialogue.close();
      }, function (err) {
        ligne.refuser(err.message);
        etat.textContent = fichier.name + ' : ' + err.message;
      }).then(function () { choix.envoiEnCours = false; });
    }

    var minuterie = null;
    recherche.addEventListener('input', function () {
      clearTimeout(minuterie);
      minuterie = setTimeout(function () { charger(1); }, 250);
    });
    recherche.addEventListener('keydown', function (evt) { if (evt.key === 'Enter') { evt.preventDefault(); clearTimeout(minuterie); charger(1); } });
    plus.addEventListener('click', function () { charger(choix.page + 1); });
    fermer.addEventListener('click', function () { dialogue.close(); });
    dialogue.addEventListener('click', function (evt) { if (evt.target === dialogue) { dialogue.close(); } });
    dialogue.addEventListener('close', function () {
      if (choix.champ) { choix.champ.rendreFocus(); }
    });
    parcourir.addEventListener('click', function () { entree.click(); });
    depot.addEventListener('click', function (evt) { if (evt.target === depot || evt.target.classList.contains('depot__titre')) { entree.click(); } });
    entree.addEventListener('change', function () {
      var fichiers = fichiersDe(entree);
      entree.value = '';
      if (fichiers.length) { envoyerDepuisFenetre(fichiers); }
    });
    brancherDepot(depot, envoyerDepuisFenetre, depot, 'depot--survol');
    dialogue.addEventListener('paste', function (evt) {
      var fichiers = fichiersDe(evt.clipboardData);
      if (!fichiers.length) { return; }
      evt.preventDefault();
      envoyerDepuisFenetre(fichiers);
    });

    choix.ouvrir = function (champ) {
      choix.champ = champ;
      envois.textContent = '';
      envois.hidden = true;
      recherche.value = '';
      dialogue.showModal();
      recherche.focus();
      charger(1);
    };
    return choix;
  }

  // Ligne de suivi d'un envoi : aperçu, nom, barre de progression, état
  function ligneEnvoi(fichier) {
    var vignette = element('span', { 'class': 'envoi__vignette', 'aria-hidden': 'true' });
    if (/^image\//.test(fichier.type) && window.URL && URL.createObjectURL) {
      vignette.appendChild(element('img', { src: URL.createObjectURL(fichier), alt: '' }));
    }
    var barre = element('progress', { 'class': 'envoi__barre', max: '100', value: '0', 'aria-label': texte('envoi', { nom: fichier.name || '' }) });
    var etat = element('span', { 'class': 'envoi__etat', texte: textes.en_attente });
    var li = element('li', { 'class': 'envoi' }, [
      vignette,
      element('span', { 'class': 'envoi__texte' }, [element('span', { 'class': 'envoi__nom', texte: fichier.name || '' }), barre, etat])
    ]);
    return {
      li: li, barre: barre, etat: etat,
      progression: function (pct) { barre.value = pct; etat.textContent = texte('envoi', { nom: fichier.name || '' }) + ' ' + pct + ' %'; },
      reussir: function (message, lien) {
        barre.value = 100;
        li.classList.add('envoi--ok');
        etat.textContent = message;
        if (lien) { etat.appendChild(document.createTextNode(' ')); etat.appendChild(lien); }
      },
      refuser: function (message) {
        li.classList.add('envoi--erreur');
        barre.hidden = true;
        etat.textContent = textes.refusee + ' ' + message;
      }
    };
  }
  window.EnvoiImages.ligneEnvoi = ligneEnvoi;

  /* ---------- Champ image ---------- */

  var champs = [];

  function ChampImage(racine) {
    var zone = racine.querySelector('[data-zone]');
    var apercu = racine.querySelector('[data-apercu]');
    var vide = racine.querySelector('[data-vide]');
    var progression = racine.querySelector('[data-progression]');
    var barre = progression.querySelector('progress');
    var pourcentage = progression.querySelector('[data-pourcentage]');
    var entree = racine.querySelector('[data-fichier]');
    var valeur = racine.querySelector('[data-valeur]');
    var statut = racine.querySelector('[data-statut]');
    var erreur = racine.querySelector('[data-erreur]');
    var retirer = racine.querySelector('[data-retirer]');
    var ouvrir = racine.querySelector('[data-ouvrir-mediatheque]');
    var credit = racine.dataset.credit ? document.getElementById(racine.dataset.credit) : null;
    var creditAuto = credit ? credit.value : '';
    var envoiEnCours = false;
    var dernierFocus = null;

    // Amélioration : actions visibles, ancien champ fichier masqué (il sert encore à choisir le fichier)
    racine.classList.add('champ-image--actif');
    racine.querySelector('[data-actions]').hidden = false;
    racine.querySelector('[data-invite]').hidden = false;
    racine.querySelectorAll('[data-repli]').forEach(function (el) { el.hidden = true; });
    zone.setAttribute('role', 'button');
    zone.setAttribute('tabindex', '0');
    zone.setAttribute('aria-label', textes.zone_label);

    function image() { return apercu.querySelector('img'); }

    function montrer(src, alt) {
      var img = image();
      if (!img) {
        img = element('img', { 'data-image': true });
        apercu.insertBefore(img, apercu.firstChild);
      }
      img.src = src;
      img.alt = alt || '';
      vide.hidden = true;
    }

    function montrerVide() {
      var img = image();
      if (img) { img.remove(); }
      vide.hidden = false;
    }

    function annoncer(message) {
      erreur.hidden = true;
      erreur.textContent = '';
      statut.textContent = message;
    }

    function signalerErreur(message) {
      statut.textContent = '';
      erreur.textContent = message;
      erreur.hidden = false;
    }

    function signalerChangement() {
      valeur.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function majCredit(media) {
      if (!credit) { return; }
      if (media && media.credit) {
        credit.value = media.credit;
        creditAuto = media.credit;
      } else if (credit.value === creditAuto) {
        credit.value = '';
        creditAuto = '';
      }
    }

    var champ = {
      racine: racine,
      valeur: function () { return valeur.value; },
      choisir: function (media, cle) {
        valeur.value = media.chemin;
        montrer(media.chemin, media.alt);
        retirer.hidden = false;
        majCredit(media);
        annoncer(texte(cle || 'choisie', { nom: media.nom }));
        signalerChangement();
      },
      rendreFocus: function () { (dernierFocus || zone).focus(); }
    };

    function parcourir() { if (!envoiEnCours) { entree.click(); } }

    function traiter(fichiers) {
      var fichier = fichiers[0];
      if (!fichier || envoiEnCours) { return; }
      var message = verifier(fichier);
      if (message) { signalerErreur(message); return; }

      // Aperçu immédiat pendant l'envoi ; en cas d'échec, l'image précédente revient
      var avant = image() ? { src: image().src, alt: image().alt } : null;
      if (window.URL && URL.createObjectURL) { montrer(URL.createObjectURL(fichier), ''); }
      racine.classList.add('champ-image--envoi');
      progression.hidden = false;
      barre.value = 0;
      pourcentage.textContent = '0 %';
      envoiEnCours = true;
      annoncer(texte('envoi', { nom: fichier.name || '' }));
      envoyer(fichier, function (pct) { barre.value = pct; pourcentage.textContent = pct + ' %'; })
        .then(function (media) {
          champ.choisir(media, 'ajoutee_choisie');
        }, function (err) {
          if (avant) { montrer(avant.src, avant.alt); } else { montrerVide(); }
          signalerErreur(err.message);
        })
        .then(function () {
          envoiEnCours = false;
          racine.classList.remove('champ-image--envoi');
          progression.hidden = true;
        });
    }

    zone.addEventListener('click', parcourir);
    zone.addEventListener('keydown', function (evt) {
      if (evt.key === 'Enter' || evt.key === ' ' || evt.key === 'Spacebar') { evt.preventDefault(); parcourir(); }
    });
    racine.querySelector('[data-parcourir]').addEventListener('click', function () { dernierFocus = this; parcourir(); });
    entree.addEventListener('change', function () {
      var fichiers = fichiersDe(entree);
      // Le fichier part dans la médiathèque : le formulaire n'envoie que le chemin choisi
      entree.value = '';
      if (fichiers.length) { traiter(fichiers); }
    });
    brancherDepot(racine, traiter, racine, 'champ-image--survol');
    racine.addEventListener('paste', function (evt) {
      var fichiers = fichiersDe(evt.clipboardData);
      if (!fichiers.length) { return; }
      evt.preventDefault();
      traiter(fichiers);
    });
    retirer.addEventListener('click', function () {
      valeur.value = '';
      montrerVide();
      retirer.hidden = true;
      majCredit(null);
      annoncer(textes.retiree);
      signalerChangement();
      zone.focus();
    });
    ouvrir.addEventListener('click', function () {
      dernierFocus = ouvrir;
      if (typeof HTMLDialogElement === 'function') { fenetreChoix().ouvrir(champ); }
    });

    champ.traiter = traiter;
    return champ;
  }

  document.querySelectorAll('[data-champ-image]').forEach(function (racine) { champs.push(new ChampImage(racine)); });

  // Image collée hors d'un champ de saisie : elle va au champ image s'il n'y en a qu'un dans la page
  if (champs.length === 1) {
    document.addEventListener('paste', function (evt) {
      if (evt.defaultPrevented || estEditable(evt.target) || (choix && choix.dialogue.open)) { return; }
      var fichiers = fichiersDe(evt.clipboardData);
      if (!fichiers.length) { return; }
      evt.preventDefault();
      champs[0].traiter(fichiers);
    });
  }
})();
