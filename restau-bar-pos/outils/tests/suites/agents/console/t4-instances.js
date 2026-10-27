// Assistance avec accord (jeton à usage unique, journaux des deux côtés), mises à jour (migrations), sauvegarde SQL
const { chromium } = require('playwright');
const fs = require('fs');
const { execSync } = require('child_process');
const { v, q, A, D, connecterConsole, connecterInstance, bilan, surveiller } = require('./outils');
const C = 'rbp_console_essai', B = 'rbp_cnslbar', I = 'http://cnslbar.localhost:8180';
(async () => {
  const b = await chromium.launch();
  const erreurs = [];
  const { bar, neuf } = JSON.parse(fs.readFileSync(D + '/etat.json'));
  q(B, "DELETE FROM parametres WHERE cle IN ('assistance.jusqua','assistance.jeton','assistance.par')");
  const avant = Number(q(C, `SELECT COUNT(*) FROM journal WHERE action='assistance_ouverte'`));
  const c = await b.newContext(); const p = await c.newPage(); surveiller(p, erreurs);
  await connecterConsole(p);

  // --- Assistance ---
  await p.goto(A + `/clients/${bar}`);
  v(await p.locator('[data-assistance]').getAttribute('data-assistance'), 'non', 'sans accord : « Non autorisée », pas de bouton');
  const csrf = await p.locator('input[name=csrf]').first().inputValue();
  let r = await p.request.post(A + `/clients/${bar}/assistance`, { form: { csrf }, maxRedirects: 0 });
  v(r.headers().location, `/clients/${bar}#assistance`, 'sans accord : « Ouvrir l\'instance » refusé');
  v(q(C, `SELECT action FROM journal WHERE client_id=${bar} ORDER BY id DESC LIMIT 1`), 'assistance_refusee', 'refus inscrit au journal de la console');
  // Le propriétaire donne son accord
  const ci = await b.newContext(); const pi = await ci.newPage(); surveiller(pi, erreurs);
  await connecterInstance(pi, 'cnslbar', 'patron@cnslbar.test', 'Patron-Bar-2026');
  const noms = (await pi.goto(I + '/gestion/compte').then(() => pi.locator('.lateral__lien').allTextContents())).join('|');
  v(noms.includes('Assistance'), true, 'instance : écran Paramètres > Assistance (console présente)');
  await pi.goto(I + '/gestion/assistance');
  v(await pi.locator('[data-assistance]').getAttribute('data-assistance'), 'non', 'instance : assistance non autorisée au départ');
  await Promise.all([pi.waitForNavigation(), pi.click('form[action="/gestion/assistance"] button')]);
  v(await pi.locator('[data-assistance]').getAttribute('data-assistance'), 'autorisee', 'propriétaire : assistance autorisée pour 24 h');
  await pi.screenshot({ path: D + '/cap/instance-assistance.png', fullPage: true });
  const cg = await b.newContext(); const pg = await cg.newPage();
  await connecterInstance(pg, 'cnslbar', 'patrick@demo.test', 'Gerant-Goma-2026');
  r = await pg.goto(I + '/gestion/assistance');
  v(r.status(), 403, 'gérant sans « parametres.utilisateurs » : écran Assistance refusé');
  r = await pg.request.post(I + '/gestion/assistance', { form: { csrf: await pg.locator('input[name=csrf]').first().inputValue(), action: 'retirer' } });
  v(r.status(), 403, 'gérant : retirer l\'accord refusé (propriétaire seulement)');
  // La console ouvre l'instance
  await p.reload();
  v(await p.locator('[data-assistance]').getAttribute('data-assistance'), 'oui', 'console : accord visible, jusqu\'à quand');
  r = await p.request.post(A + `/clients/${bar}/assistance`, { form: { csrf }, maxRedirects: 0 });
  const lien = r.headers().location;
  v(lien, /^http:\/\/cnslbar\.localhost:8180\/gestion\/assistance\/entrer\/[0-9a-f]{48}$/, 'console : lien d\'entrée à usage unique vers l\'instance');
  v(q(B, "SELECT valeur FROM parametres WHERE cle='assistance.jeton'").includes(lien.split('/').pop()), false, 'seule l\'empreinte du jeton est écrite dans l\'instance');
  const cs = await b.newContext(); const ps = await cs.newPage(); surveiller(ps, erreurs);
  await ps.goto(lien);
  v(ps.url(), I + '/gestion', 'support : connecté à l\'instance');
  v(await ps.locator('.plf-bandeau--glace').textContent(), /Assistance/, 'bandeau « Assistance » dans l\'instance');
  await ps.screenshot({ path: D + '/cap/instance-bandeau-assistance.png' });
  v(q(B, "SELECT CONCAT(j.action,':',u.nom,':',j.cible) FROM journal j JOIN utilisateurs u ON u.id=j.utilisateur_id WHERE j.action='assistance_ouverte' ORDER BY j.id DESC LIMIT 1"), 'assistance_ouverte:Assistance restau-bar-pos:Super Admin', 'journal de l\'instance : session d\'assistance, avec le super admin');
  v(Number(q(C, `SELECT COUNT(*) FROM journal WHERE client_id=${bar} AND action='assistance_ouverte'`)) - avant, 1, 'journal de la console : assistance ouverte');
  const cs2 = await b.newContext(); const ps2 = await cs2.newPage();
  r = await ps2.goto(lien);
  v(r.status(), 403, 'le même lien ne sert qu\'une fois');
  r = await ps2.goto(I + '/gestion/assistance/entrer/' + 'a'.repeat(48));
  v(r.status(), 403, 'jeton inventé refusé');
  v(q(B, "SELECT COUNT(*) FROM journal WHERE action='assistance_refusee'") >= 2, true, 'entrées refusées inscrites au journal de l\'instance');
  await p.goto(A + `/clients/${bar}`);
  const actifs = Number(q(B, "SELECT COUNT(*) FROM utilisateurs WHERE actif = 1 AND id <> COALESCE((SELECT valeur FROM parametres WHERE cle = 'assistance.utilisateur_id'), 0)"));
  v(await p.locator('[data-usage="utilisateurs"]').textContent(), new RegExp('^' + actifs + ' '), 'le compte d\'assistance ne compte pas dans les personnes actives (' + actifs + ')');
  // Le support ne peut pas prolonger l'accord lui-même
  r = await ps.goto(I + '/gestion/assistance');
  v(await ps.locator('form[action="/gestion/assistance"]').count(), 0, 'le support ne peut pas changer l\'accord');
  // Le propriétaire retire son accord : la session du support s'arrête
  await pi.goto(I + '/gestion/assistance');
  await Promise.all([pi.waitForNavigation(), pi.click('form[action="/gestion/assistance"] button')]);
  await ps.goto(I + '/gestion/catalogue');
  v(ps.url(), I + '/gestion/connexion', 'accord retiré : le support est déconnecté à la requête suivante');
  v(q(B, "SELECT COUNT(*) FROM utilisateurs WHERE nom='Assistance restau-bar-pos' AND actif=1"), 0, 'compte d\'assistance désactivé');
  // Accord expiré
  await Promise.all([pi.waitForNavigation(), pi.click('form[action="/gestion/assistance"] button')]);
  r = await p.request.post(A + `/clients/${bar}/assistance`, { form: { csrf }, maxRedirects: 0 });
  await ps.goto(r.headers().location);
  v(ps.url(), I + '/gestion', 'nouvel accord : le support rentre');
  q(B, "UPDATE parametres SET valeur=DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE cle='assistance.jusqua'");
  await ps.goto(I + '/gestion');
  v(ps.url(), I + '/gestion/connexion', 'accord expiré (24 h) : le support est déconnecté');
  v(q(B, "SELECT COUNT(*) FROM journal WHERE action='assistance_expiree'") >= 1, true, 'expiration inscrite au journal');

  // --- Mises à jour (migrations) ---
  q('rbp_cnsl_neuf', "ALTER TABLE categories DROP COLUMN actif; DELETE FROM schema_migrations WHERE nom='003_categories_archivees'");
  await p.goto(A + `/clients/${neuf}`);
  v(await p.locator('[data-migrations]').getAttribute('data-migrations'), '1', 'migration en attente détectée');
  await Promise.all([p.waitForNavigation(), p.click('form[action$="/mettre-a-jour"] button')]);
  v(await p.locator('[data-flash]').textContent(), /003_categories_archivees/, 'mise à jour d\'une instance : migration appliquée');
  v(q('rbp_cnsl_neuf', "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='rbp_cnsl_neuf' AND table_name='categories' AND column_name='actif'"), 1, 'la colonne est revenue');
  v(q('rbp_cnsl_neuf', "SELECT action FROM journal ORDER BY id DESC LIMIT 1"), 'plateforme_mise_a_jour', 'mise à jour inscrite au journal de l\'instance');
  q('rbp_cnsl_neuf', "ALTER TABLE categories DROP COLUMN actif; DELETE FROM schema_migrations WHERE nom='003_categories_archivees'");
  await p.goto(A + '/clients');
  await Promise.all([p.waitForNavigation(), p.click('form[action="/clients/mettre-a-jour"] button')]);
  v(await p.locator('[data-flash]').textContent(), /1 instance/, '« Mettre à jour toutes les instances » : une à mettre à jour, l\'autre à jour');
  v(q('rbp_cnsl_neuf', "SELECT COUNT(*) FROM schema_migrations WHERE nom='003_categories_archivees'"), 1, 'migration appliquée par « toutes »');

  // --- Sauvegarde SQL ---
  await p.goto(A + `/clients/${bar}`);
  const [telechargement] = await Promise.all([p.waitForEvent('download'), p.click('form[action$="/sauvegarde"] button')]);
  const fichier = D + '/sauvegarde-cnslbar.sql';
  await telechargement.saveAs(fichier);
  v(telechargement.suggestedFilename(), /^rbp-cnslbar-\d{8}-\d{6}\.sql$/, 'sauvegarde : fichier rbp-cnslbar-AAAAMMJJ-HHMMSS.sql');
  const sql = fs.readFileSync(fichier, 'utf8');
  v(/CREATE TABLE `ventes`/.test(sql) && /INSERT INTO `ventes` VALUES/.test(sql), true, 'sauvegarde : structure et lignes');
  execSync("mysql -e 'DROP DATABASE IF EXISTS rbp_cnsl_restaure; CREATE DATABASE rbp_cnsl_restaure'");
  execSync(`mysql rbp_cnsl_restaure < ${fichier}`);
  const compter = base => q(base, "SELECT CONCAT((SELECT COUNT(*) FROM ventes),'/',(SELECT COUNT(*) FROM dettes),'/',(SELECT COUNT(*) FROM produits),'/',(SELECT COUNT(*) FROM mouvements_tresorerie),'/',(SELECT SUM(total) FROM ventes))");
  v(compter('rbp_cnsl_restaure'), compter(B), 'sauvegarde restaurée dans une base vide : mêmes ventes, dettes, produits, mouvements, totaux');
  execSync("mysql -e 'DROP DATABASE rbp_cnsl_restaure'");
  v(q(C, `SELECT action FROM journal WHERE client_id=${bar} ORDER BY id DESC LIMIT 1`), 'sauvegarde', 'sauvegarde inscrite au journal de la console');
  v(q(B, "SELECT CONCAT(action,':',cible) FROM journal ORDER BY id DESC LIMIT 1"), 'plateforme_sauvegarde:Super Admin', 'et au journal de l\'instance (le client le voit)');
  console.log(erreurs.length ? erreurs.join('\n') : 'aucune erreur JS');
  await b.close();
  process.exit(bilan() ? 1 : 0);
})();
