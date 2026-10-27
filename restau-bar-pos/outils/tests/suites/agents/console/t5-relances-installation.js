// Relances d'abonnement au propriétaire (SMS et e-mail), réglages de la plateforme, installation de la console par le navigateur
const { chromium } = require('playwright');
const fs = require('fs');
const { execSync } = require('child_process');
const { v, q, php, A, D, connecterConsole, bilan, surveiller } = require('./outils');
const C = 'rbp_console_essai', LOG = D + '/faux-services.log', MAILS = D + '/mails.log';
const relancer = date => {
  try { return execSync(`cd /home/user/Eglise/restau-bar-pos && RBP_CONSOLE_CONFIG=${D}/console.php php -d sendmail_path=${D}/sendmail.sh bin/console-relances.php --date=${date}`).toString(); }
  catch (e) { return 'CODE ' + e.status + ' ' + e.stdout; }
};
(async () => {
  const b = await chromium.launch();
  const erreurs = [];
  const { neuf, bar } = JSON.parse(fs.readFileSync(D + '/etat.json'));
  const c = await b.newContext(); const p = await c.newPage(); surveiller(p, erreurs);
  await connecterConsole(p);

  // --- Réglages de la plateforme ---
  await p.goto(A + '/reglages');
  await p.fill('#plateforme_nom', 'Goma POS'); await p.fill('#plateforme_contact_telephone', '0990 000 777');
  await p.fill('#plateforme_contact_email', 'contact@gomapos.test'); await p.fill('#email_expediteur', 'pas-un-email');
  await p.fill('#relances_etapes', 'abc');
  await p.click('main button[type=submit]');
  v(await p.locator('#err-email_expediteur').count() + await p.locator('#err-relances_etapes').count(), 2, 'réglages : e-mail et étapes invalides refusés');
  await p.fill('#email_expediteur', 'abonnements@gomapos.test'); await p.fill('#relances_etapes', '-7, -3,-1, 1');
  await p.fill('#installation_cpanel_prefixe', 'gomapos');
  await Promise.all([p.waitForNavigation(), p.click('main button[type=submit]')]);
  v(q(C, "SELECT GROUP_CONCAT(valeur ORDER BY cle SEPARATOR '|') FROM parametres WHERE cle IN ('plateforme.nom','plateforme.contact_telephone','relances.etapes')"), '+243990000777|Goma POS|-7,-3,-1,1', 'réglages enregistrés (téléphone normalisé, étapes triées)');
  v(await p.locator('[data-cron]').textContent(), /bin\/console-relances\.php[\s\S]*bin\/relances\.php --toutes/, 'réglages : lignes cron à copier');
  await p.screenshot({ path: D + '/cap/reglages-ordi.png', fullPage: true });
  // Le contact de la plateforme apparaît dans l'instance (bandeaux, pages de refus)
  // --- Relances d'abonnement ---
  q(C, `DELETE FROM messages WHERE client_id IS NOT NULL; UPDATE clients SET statut_force=NULL, essai=1, jours_grace=7, jours_lecture_seule=15, echeance='2026-12-01' WHERE id=${neuf}; UPDATE clients SET statut_force='actif' WHERE id<>${neuf}`);
  fs.writeFileSync(LOG, ''); fs.writeFileSync(MAILS, '');
  let sortie = relancer('2026-11-24');
  v(sortie, /2 envoyé/, 'J-7 : SMS et e-mail envoyés');
  v(q(C, `SELECT GROUP_CONCAT(CONCAT(canal,':',etape,':',statut) ORDER BY canal) FROM messages WHERE client_id=${neuf}`), 'sms:J-7:envoye,email:J-7:envoye', 'J-7 inscrit au journal des messages de la console');
  v(q(C, `SELECT texte FROM messages WHERE client_id=${neuf} AND canal='sms'`), "Goma POS : l'essai gratuit de Le Bar Neuf se termine le 01/12/2026. Pour continuer : 25 $ par mois. Contact : +243990000777", 'SMS d\'essai : nom de la plateforme, date, montant, contact');
  const sms = fs.readFileSync(LOG, 'utf8').trim().split('\n').map(JSON.parse);
  v(sms.length === 1 && /to=%2B243970000111/.test(sms[0].corps) && /from=RESTOBAR/.test(sms[0].corps), true, 'SMS au propriétaire par le compte de la plateforme (sender ID de la plateforme)');
  const mail = fs.readFileSync(MAILS, 'utf8');
  v(/To: jeanne@barneuf\.test/.test(mail) || /jeanne@barneuf\.test/.test(mail), true, 'e-mail au propriétaire');
  v(/From: .*<abonnements@gomapos\.test>/.test(mail) && /Content-Type: text\/plain; charset=UTF-8/.test(mail), true, 'e-mail : expéditeur réglé, texte UTF-8');
  sortie = relancer('2026-11-24');
  v(sortie, /0 envoyé.*2 déjà/, 'deuxième passage le même jour : rien de plus');
  sortie = relancer('2026-11-26');
  v(sortie, /0 envoyé\(s\), 0 inscrit/, 'J-5 : pas d\'étape');
  sortie = relancer('2026-11-29');
  v(q(C, `SELECT GROUP_CONCAT(DISTINCT etape) FROM messages WHERE client_id=${neuf} AND cree_le >= CURDATE() AND etape='J-3'`), 'J-3', 'J-2 : l\'étape J-3 manquée est rattrapée');
  relancer('2026-12-02');
  v(q(C, `SELECT texte FROM messages WHERE client_id=${neuf} AND canal='sms' AND etape='J+1'`), /dépassée. Le Bar Neuf fonctionne jusqu'au 08\/12\/2026, puis passe en lecture seule/, 'J+1 : échéance dépassée, fin de la grâce annoncée');
  relancer('2026-12-09');
  v(q(C, `SELECT COUNT(*) FROM messages WHERE client_id=${neuf} AND etape='lecture_seule'`), 2, 'J+8 : avis de passage en lecture seule (SMS et e-mail)');
  relancer('2026-12-24');
  v(q(C, `SELECT texte FROM messages WHERE client_id=${neuf} AND canal='sms' AND etape='suspendu'`), /suspendu. Vos données sont conservées/, 'J+23 : avis de suspension');
  v(q(C, `SELECT COUNT(*) FROM messages WHERE client_id<>${neuf}`), 0, 'statut décidé à la main : pas de relance');
  // Anglais
  q(C, `UPDATE clients SET langue='en' WHERE id=${neuf}`); q(C, `DELETE FROM messages WHERE client_id=${neuf}`);
  relancer('2026-11-24');
  v(q(C, `SELECT texte FROM messages WHERE client_id=${neuf} AND canal='sms'`), "Goma POS: the Le Bar Neuf free trial ends on Dec 1, 2026. To continue: $25 per month. Contact: +243990000777", 'relance dans la langue du client (anglais)');
  q(C, `UPDATE clients SET langue='fr' WHERE id=${neuf}`);
  // Sans SMS réglé : inscrit seulement
  q(C, `DELETE FROM messages WHERE client_id=${neuf}`); q(C, "UPDATE parametres SET valeur=NULL WHERE cle='sms.cle'");
  sortie = relancer('2026-11-24');
  v(q(C, `SELECT statut FROM messages WHERE client_id=${neuf} AND canal='sms'`), 'simule', 'sans clé Africa\'s Talking : SMS inscrit sans envoi');
  q(C, "UPDATE parametres SET valeur='atsk_cle_secrete_1234' WHERE cle='sms.cle'");
  // Échec d'envoi : code de sortie 2
  q(C, `DELETE FROM messages WHERE client_id=${neuf}`); q(C, "UPDATE parametres SET valeur='http://127.0.0.1:8199/sms-ko' WHERE cle='sms.url'");
  sortie = relancer('2026-11-24');
  v(sortie, /^CODE 2/, 'échec d\'envoi : code de sortie 2');
  v(q(C, `SELECT erreur FROM messages WHERE client_id=${neuf} AND canal='sms'`), /InsufficientBalance/, 'erreur d\'Africa\'s Talking gardée (solde insuffisant)');
  q(C, "UPDATE parametres SET valeur='http://127.0.0.1:8199/sms' WHERE cle='sms.url'");
  // Heure d'envoi
  q(C, "UPDATE parametres SET valeur='23' WHERE cle='relances.heure'");
  const h = Number(execSync("TZ=Africa/Lubumbashi date +%H").toString());
  sortie = execSync(`cd /home/user/Eglise/restau-bar-pos && RBP_CONSOLE_CONFIG=${D}/console.php php bin/console-relances.php`).toString();
  if (h < 23) v(sortie, /0 envoyé\(s\), 0 inscrit\(s\) sans envoi \(SMS ou e-mail non réglé\), 0 échec\(s\), 0 déjà/, 'avant l\'heure d\'envoi : rien');
  q(C, "UPDATE parametres SET valeur='9' WHERE cle='relances.heure'");
  q(C, `UPDATE clients SET statut_force=NULL WHERE id<>${neuf}; UPDATE clients SET essai=1, echeance=DATE_ADD(CURDATE(), INTERVAL 14 DAY) WHERE id=${neuf}; UPDATE clients SET echeance=DATE_ADD(CURDATE(), INTERVAL 20 DAY) WHERE id=${bar}`);
  await p.goto(A + `/clients/${neuf}`);
  v(await p.locator('#relances [data-message]').count() > 0, true, 'fiche du client : relances envoyées listées');
  await p.goto(A + '/journal?vue=messages');
  v(await p.locator('tbody tr').count() > 0, true, 'journal : messages de la console');

  // --- Installation de la console par le navigateur (sans SSH) ---
  const W = D + '/web'; execSync(`rm -rf ${W} && mkdir -p ${W}`);
  execSync(`mysql -e "DROP DATABASE IF EXISTS rbp_console_web; CREATE DATABASE rbp_console_web; GRANT ALL ON rbp_console_web.* TO 'rbp_cnsl'@'localhost'; GRANT ALL ON rbp_console_web.* TO 'rbp_cnsl'@'127.0.0.1'"`);
  execSync(`cd /home/user/Eglise/restau-bar-pos && rm -f storage/console-installation.code storage/installation-*.txt && (RBP_CONSOLE_CONFIG=${W}/console.php setsid nohup php -S 127.0.0.1:8182 -t public public/index.php > ${D}/serveur-web.log 2>&1 < /dev/null & echo $! > ${D}/serveur-web.pid) && sleep 0.7`);
  const W8 = 'http://admin.localhost:8182';
  const cw = await b.newContext(); const pw = await cw.newPage(); surveiller(pw, erreurs);
  await pw.goto(W8 + '/clients');
  v(pw.url(), W8 + '/installation', 'sans config/console.php : toute page mène à l\'installation');
  await pw.screenshot({ path: D + '/cap/installation-1.png', fullPage: true });
  const preuve = await pw.locator('[data-preuve]').textContent();
  v(preuve, /^installation-[a-z0-9]{12}\.txt$/, 'preuve demandée : fichier à créer dans storage/');
  await pw.fill('#db_nom', 'rbp_console_web'); await pw.fill('#db_utilisateur', 'rbp_cnsl'); await pw.fill('#db_mot_de_passe', 'Essai-Console-2026'); await pw.fill('#db_hote', '127.0.0.1');
  let r = await Promise.all([pw.waitForNavigation(), pw.click('main button[type=submit]')]).then(x => x[0]);
  v(r.status() + ' ' + /Preuve introuvable/.test(await pw.content()), '403 true', 'sans le fichier de preuve : refusé');
  v(fs.existsSync(W + '/console.php'), false, 'rien n\'est écrit sans preuve');
  fs.writeFileSync('/home/user/Eglise/restau-bar-pos/storage/' + preuve, '');
  await pw.fill('#db_nom', 'rbp_console_web'); await pw.fill('#db_utilisateur', 'rbp_cnsl'); await pw.fill('#db_mot_de_passe', 'faux'); await pw.fill('#db_hote', '127.0.0.1');
  await Promise.all([pw.waitForNavigation(), pw.click('main button[type=submit]')]);
  v(await pw.locator('#err-base').textContent(), /Accès refusé/, 'mauvais mot de passe MySQL : expliqué');
  await pw.fill('#db_mot_de_passe', 'Essai-Console-2026'); await pw.fill('#db_hote', '127.0.0.1'); await pw.fill('#db_nom', 'rbp_console_web'); await pw.fill('#db_utilisateur', 'rbp_cnsl');
  await Promise.all([pw.waitForNavigation(), pw.click('main button[type=submit]')]);
  v(fs.existsSync(W + '/console.php') && /rbp_console_web/.test(fs.readFileSync(W + '/console.php', 'utf8')), true, 'config/console.php écrit par la page');
  v(await pw.locator('.alerte').first().textContent(), /config\/console\.php est enregistré/, 'étape 1 faite');
  await pw.screenshot({ path: D + '/cap/installation-2.png', fullPage: true });
  await pw.fill('#nom', 'Première Admin'); await pw.fill('#email', 'premiere@gomapos.test'); await pw.fill('#mot_de_passe', 'court'); await pw.fill('#confirmation', 'court');
  await Promise.all([pw.waitForNavigation(), pw.click('main button[type=submit]')]);
  v(await pw.locator('#err-admin-mot_de_passe').textContent(), /12 caractères/, 'premier super admin : 12 caractères au moins');
  await pw.fill('#nom', 'Première Admin'); await pw.fill('#email', 'premiere@gomapos.test'); await pw.fill('#mot_de_passe', 'Premiere-Admin-2026'); await pw.fill('#confirmation', 'Premiere-Admin-2026');
  await Promise.all([pw.waitForNavigation(), pw.click('main button[type=submit]')]);
  v(pw.url(), W8 + '/connexion', 'installation terminée : page de connexion');
  v(q('rbp_console_web', 'SELECT COUNT(*) FROM formules') + '/' + q('rbp_console_web', 'SELECT email FROM super_admins'), '3/premiere@gomapos.test', 'tables de la console et premier super admin créés');
  v(fs.existsSync('/home/user/Eglise/restau-bar-pos/storage/' + preuve), false, 'fichier de preuve effacé');
  r = await pw.goto(W8 + '/installation');
  v(r.status(), 404, 'la page d\'installation n\'existe plus');
  const cw2 = await b.newContext(); const pw2 = await cw2.newPage();
  r = await pw2.request.post(W8 + '/installation', { form: { etape: 'admin', nom: 'Pirate', email: 'pirate@x.test', mot_de_passe: 'Pirate-Pirate-2026', confirmation: 'Pirate-Pirate-2026' } });
  v(r.status() === 404 && q('rbp_console_web', 'SELECT COUNT(*) FROM super_admins') === '1', true, 'impossible d\'ajouter un super admin par la page ensuite');
  await pw.goto(W8 + '/connexion');
  await pw.fill('#email', 'premiere@gomapos.test'); await pw.fill('#mot_de_passe', 'Premiere-Admin-2026');
  await Promise.all([pw.waitForNavigation(), pw.click('main button[type=submit]')]);
  v(pw.url(), W8 + '/', 'connexion avec le compte créé par la page');
  execSync(`kill $(cat ${D}/serveur-web.pid); mysql -e "DROP DATABASE rbp_console_web"`);
  console.log(erreurs.length ? erreurs.join('\n') : 'aucune erreur JS');
  await b.close();
  process.exit(bilan() ? 1 : 0);
})();
