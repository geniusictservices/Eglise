// 1. Connexion, blocage, CSRF, cookie propre ; 2. création guidée d'un client avec une vraie base
const { chromium } = require('playwright');
const { v, q, php, A, D, connecterConsole, connecterInstance, bilan, surveiller } = require('./outils');
(async () => {
  const b = await chromium.launch();
  const erreurs = [];
  // --- Blocage après 5 essais, par compte ---
  php("bin/console-admin.php admin --email=bloque@plateforme.test --nom=Bloque --mot-de-passe='Bloque-Console-2026'");
  let c = await b.newContext(); let p = await c.newPage(); surveiller(p, erreurs);
  for (let i = 0; i < 5; i++) await connecterConsole(p, 'bloque@plateforme.test', 'mauvais-mot-de-passe');
  v(await p.locator('.alerte--erreur').textContent(), /incorrect/, 'connexion : mot de passe faux refusé');
  await connecterConsole(p, 'bloque@plateforme.test', 'Bloque-Console-2026');
  v(await p.locator('.alerte--erreur').textContent(), /Trop d'essais/, 'après 5 essais : bloqué même avec le bon mot de passe');
  await c.close();
  c = await b.newContext(); p = await c.newPage();
  await connecterConsole(p, 'bloque@plateforme.test', 'Bloque-Console-2026');
  v(await p.locator('.alerte--erreur').count(), 1, 'autre navigateur : toujours bloqué (blocage par compte, en base)');
  v(q('rbp_console_essai', "SELECT connexion_bloquee_jusqua > NOW() FROM super_admins WHERE email='bloque@plateforme.test'"), 1, 'blocage de 5 minutes inscrit en base');
  await c.close();
  v(php("bin/console-admin.php admin --email=bloque@plateforme.test --mot-de-passe=court"), /12 caractères/, 'CLI : mot de passe de moins de 12 caractères refusé');

  // --- Connexion, cookie, CSRF ---
  c = await b.newContext(); p = await c.newPage(); surveiller(p, erreurs);
  let r = await p.goto(A + '/clients');
  v(p.url(), A + '/connexion', 'sans connexion : /clients renvoie à la connexion');
  await connecterConsole(p);
  v(p.url(), A + '/', 'connexion réussie : tableau de bord');
  const cookies = (await c.cookies()).map(k => k.name);
  v(cookies.includes('rbp_console') && !cookies.includes('rbp_session'), true, 'cookie de session propre à la console (rbp_console)');
  r = await p.request.post(A + '/clients/nouveau', { form: { csrf: 'faux', nom: 'X' } });
  v(r.status(), 419, 'CSRF : formulaire sans bon jeton refusé (419)');
  r = await p.request.post(A + '/messagerie', { form: { sms_utilisateur: 'pirate' } });
  v(r.status(), 419, 'CSRF : réglages de messagerie sans jeton refusés');
  v(q('rbp_console_essai', "SELECT COUNT(*) FROM parametres WHERE valeur='pirate'"), 0, 'rien n\'a été enregistré');
  r = await p.goto(A + '/installation');
  v(r.status(), 404, 'console installée : /installation n\'existe plus');

  // --- Création guidée ---
  await p.goto(A + '/clients/nouveau');
  await p.fill('#nom', 'Le Bar Neuf');
  await p.fill('#sous_domaine', 'admin');
  await p.fill('#contact_email', 'pas-un-email');
  await p.click('main button[type=submit]');
  v(await p.locator('#err-sous_domaine').textContent(), /réservé/, 'sous-domaine réservé (admin) refusé');
  v(await p.locator('#err-contact_email').count(), 1, 'e-mail du propriétaire obligatoire');
  v(await p.locator('#err-formule_id').count(), 1, 'formule obligatoire');
  await p.fill('#sous_domaine', 'cnslneuf');
  await p.fill('#contact_nom', 'Jeanne Neema');
  await p.fill('#contact_email', 'jeanne@barneuf.test');
  await p.fill('#contact_telephone', '0970 000 111');
  await p.selectOption('#formule_id', q('rbp_console_essai', "SELECT id FROM formules WHERE nom='Essentiel'"));
  await p.fill('#essai_jours', '14');
  await Promise.all([p.waitForNavigation(), p.click('main button[type=submit]')]);
  const id = p.url().match(/clients\/(\d+)/)[1];
  v(await p.locator('[data-statut]').first().getAttribute('data-statut'), 'a_installer', 'client créé : « À installer »');
  v(q('rbp_console_essai', "SELECT CONCAT(contact_telephone,' ',essai,' ',DATEDIFF(echeance, debut)) FROM clients WHERE sous_domaine='cnslneuf'"), '+243970000111 1 14', 'téléphone normalisé, essai de 14 jours');
  v(await p.locator('[data-racine]').textContent(), '/home/user/Eglise/restau-bar-pos/public', 'étapes cPanel : racine du sous-domaine = dossier public/');
  await p.screenshot({ path: D + '/cap/creation-etapes-ordi.png', fullPage: true });
  // Mauvais mot de passe
  await p.fill('#db_nom', 'rbp_cnsl_neuf'); await p.fill('#db_utilisateur', 'rbp_cnsl'); await p.fill('#db_mot_de_passe', 'faux');
  await p.click('button[formaction$="/tester"]');
  v(await p.locator('[data-test]').getAttribute('data-test'), 'echec', 'tester : mauvais mot de passe → échec');
  v(await p.locator('[data-test]').textContent(), /Accès refusé/, 'message clair (accès refusé)');
  v(await p.locator('#db_mot_de_passe').inputValue(), '', 'le mot de passe saisi n\'est jamais réaffiché');
  // Bon mot de passe
  await p.fill('#db_mot_de_passe', 'Essai-Console-2026');
  await p.click('button[formaction$="/tester"]');
  v(await p.locator('[data-test]').textContent(), /Connexion réussie.*base vide/, 'tester : connexion réussie, base vide');
  v(await p.locator('#db_mot_de_passe').inputValue(), '', 'après le test : champ du mot de passe vide');
  await p.screenshot({ path: D + '/cap/creation-test-ok-ordi.png', fullPage: true });
  // Installer sans retaper le mot de passe (gardé dans la session après le test)
  await Promise.all([p.waitForNavigation(), p.click('form[action$="/installer"] button[type=submit]:not([formaction])')]);
  v(await p.locator('[data-flash]').textContent(), /Instance installée/, 'installation réussie');
  const mdp = await p.locator('[data-mdp]').textContent();
  v(mdp.length, 16, 'mot de passe temporaire du propriétaire affiché (16 caractères)');
  await p.screenshot({ path: D + '/cap/creation-terminee-ordi.png', fullPage: false });
  await p.reload();
  v(await p.locator('[data-mdp]').count(), 0, 'mot de passe temporaire affiché une seule fois');
  v(await p.locator('[data-statut]').first().getAttribute('data-statut'), 'essai', 'après installation : statut « Essai »');
  v(await p.locator('[data-migrations]').getAttribute('data-migrations'), '0', 'toutes les migrations appliquées');
  v(await p.locator('#instance').textContent(), /Jamais réaffiché/, 'fiche : mot de passe de la base jamais réaffiché');
  v((await p.content()).includes('Essai-Console-2026'), false, 'le mot de passe de la base n\'apparaît nulle part dans la page');
  const fichier = require('fs').readFileSync('/home/user/Eglise/restau-bar-pos/config/instances/cnslneuf.php', 'utf8');
  v(/'nom' => 'rbp_cnsl_neuf'/.test(fichier) && /Essai-Console-2026/.test(fichier), true, 'config/instances/cnslneuf.php écrit');
  v(q('rbp_cnsl_neuf', 'SELECT COUNT(*) FROM schema_migrations'), require('fs').readdirSync('/home/user/Eglise/restau-bar-pos/database/migrations').filter(f => f.endsWith('.sql')).length, 'instance : schéma et toutes les migrations');
  v(q('rbp_cnsl_neuf', "SELECT CONCAT(u.email,' ',u.telephone,' ',r.code) FROM utilisateurs u JOIN utilisateur_roles ur ON ur.utilisateur_id=u.id JOIN roles r ON r.id=ur.role_id"), 'jeanne@barneuf.test +243970000111 proprietaire', 'compte propriétaire (e-mail, téléphone, rôle)');
  v(q('rbp_cnsl_neuf', "SELECT valeur FROM parametres WHERE cle='nom_client'"), 'Le Bar Neuf', 'nom du client dans l\'instance');
  v(q('rbp_cnsl_neuf', "SELECT COUNT(*) FROM produits"), 0, 'pas de données de démonstration');
  // Recommencer l'installation : refusé
  r = await p.request.post(A + `/clients/${id}/installer`, { form: { csrf: await p.locator('input[name=csrf]').first().inputValue(), db_nom: 'rbp_cnsl_neuf', db_utilisateur: 'rbp_cnsl', db_mot_de_passe: 'Essai-Console-2026' } });
  v(q('rbp_cnsl_neuf', 'SELECT COUNT(*) FROM utilisateurs'), 1, 'deuxième installation : rien de plus');

  // --- Première connexion du propriétaire : mot de passe temporaire à changer ---
  const ci = await b.newContext(); const pi = await ci.newPage(); surveiller(pi, erreurs);
  await connecterInstance(pi, 'cnslneuf', 'jeanne@barneuf.test', mdp);
  v(pi.url(), /\/gestion\/compte/, 'propriétaire : envoyé sur Mon compte');
  v(await pi.locator('.alerte').first().textContent(), /mot de passe temporaire/, 'message : choisir son propre mot de passe');
  await pi.goto('http://cnslneuf.localhost:8180/gestion/etablissements');
  v(pi.url(), /\/gestion\/compte/, 'autres écrans fermés tant que le mot de passe est temporaire');
  await pi.fill('#actuel', mdp); await pi.fill('#nouveau', 'Jeanne-Bar-Neuf-2026'); await pi.fill('#confirmation', 'Jeanne-Bar-Neuf-2026');
  await Promise.all([pi.waitForNavigation(), pi.click('#mot-de-passe button[type=submit]')]);
  await pi.goto('http://cnslneuf.localhost:8180/gestion/etablissements');
  v(pi.url(), 'http://cnslneuf.localhost:8180/gestion/etablissements', 'mot de passe changé : la gestion s\'ouvre');
  await ci.close();
  require('fs').writeFileSync(D + '/etat.json', JSON.stringify({ neuf: id }));
  console.log(erreurs.length ? erreurs.join('\n') : 'aucune erreur JS');
  await b.close();
  process.exit(bilan() ? 1 : 0);
})();
