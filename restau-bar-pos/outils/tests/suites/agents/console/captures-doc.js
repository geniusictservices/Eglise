// Captures pour la documentation (docs/captures/console/*.jpg) : écrans de la console et de l'instance
const { chromium } = require('playwright');
const fs = require('fs');
const { q, A, D, connecterConsole, connecterInstance } = require('./outils');
const OUT = '/home/user/Eglise/restau-bar-pos/docs/captures/console';
fs.mkdirSync(OUT, { recursive: true });
(async () => {
  const b = await chromium.launch();
  const { neuf, bar } = JSON.parse(fs.readFileSync(D + '/etat.json'));
  const ordi = await b.newContext({ viewport: { width: 1366, height: 860 }, locale: 'fr-FR', colorScheme: 'light' });
  const p = await ordi.newPage();
  const prendre = async (nom, options = {}) => { await p.screenshot({ path: `${OUT}/${nom}.jpg`, type: 'jpeg', quality: 78, ...options }); };
  const section = async (nom, selecteur) => { await p.locator(selecteur).first().screenshot({ path: `${OUT}/${nom}.jpg`, type: 'jpeg', quality: 80 }); };
  await p.goto(A + '/connexion'); await prendre('connexion');
  await connecterConsole(p);
  await p.goto(A + '/'); await prendre('tableau-de-bord', { fullPage: true });
  await p.goto(A + '/clients'); await prendre('clients', { fullPage: true });
  await p.goto(A + '/clients/nouveau'); await prendre('nouveau-client', { fullPage: true });
  await p.goto(A + `/clients/${bar}`); await prendre('fiche-client', { fullPage: true });
  await section('fiche-abonnement', '#abonnement'); await section('fiche-paiements', '#paiements');
  await section('fiche-instance', '#instance'); await section('fiche-statut', '#statut'); await section('fiche-assistance', '#assistance');
  await p.goto(A + '/formules'); await prendre('formules', { fullPage: true });
  await p.goto(A + '/formules/1'); await prendre('formule', { fullPage: true });
  await p.goto(A + '/messagerie'); await prendre('messagerie', { fullPage: true });
  await section('messagerie-africastalking', '#con-at >> xpath=..'); await section('messagerie-whatsapp', '#con-wa >> xpath=..');
  await section('messagerie-essai', '#essai'); await section('messagerie-usage', '#expediteurs');
  await p.goto(A + '/reglages'); await prendre('reglages', { fullPage: true });
  await p.goto(A + '/journal'); await prendre('journal');
  // Installation guidée d'une instance : client « à installer » fictif
  q('rbp_console_essai', "INSERT IGNORE INTO clients (nom, sous_domaine, contact_nom, contact_email, formule_id, periode, debut, echeance, essai) VALUES ('Bar du Port','barduport','Didier Kambale','didier@barduport.test',1,'mois',CURDATE(),DATE_ADD(CURDATE(), INTERVAL 14 DAY),1)");
  const idPort = q('rbp_console_essai', "SELECT id FROM clients WHERE sous_domaine='barduport'");
  await p.goto(A + `/clients/${idPort}`); await section('installation-instance', '#installation');
  // Téléphone
  const tel = await b.newContext({ viewport: { width: 390, height: 844 }, locale: 'fr-FR', deviceScaleFactor: 2 });
  const pt = await tel.newPage();
  await connecterConsole(pt);
  await pt.goto(A + '/'); await pt.screenshot({ path: `${OUT}/tableau-de-bord-telephone.jpg`, type: 'jpeg', quality: 75 });
  await pt.goto(A + `/clients/${bar}`); await pt.screenshot({ path: `${OUT}/fiche-client-telephone.jpg`, type: 'jpeg', quality: 75 });
  // Instance : bandeau, caisse fermée, assistance
  const pi = await (await b.newContext({ viewport: { width: 1366, height: 860 } })).newPage();
  q('rbp_console_essai', `UPDATE clients SET echeance=DATE_SUB(CURDATE(), INTERVAL 10 DAY) WHERE id=${bar}`); q('rbp_cnslbar', "DELETE FROM parametres WHERE cle='licence.verifiee_le'");
  await connecterInstance(pi, 'cnslbar', 'patron@cnslbar.test', 'Patron-Bar-2026');
  await pi.goto('http://cnslbar.localhost:8180/gestion'); await pi.screenshot({ path: `${OUT}/instance-lecture-seule.jpg`, type: 'jpeg', quality: 78 });
  await pi.goto('http://cnslbar.localhost:8180/caisse'); await pi.screenshot({ path: `${OUT}/instance-caisse-fermee.jpg`, type: 'jpeg', quality: 78 });
  q('rbp_console_essai', `UPDATE clients SET echeance=DATE_ADD(CURDATE(), INTERVAL 20 DAY) WHERE id=${bar}`); q('rbp_cnslbar', "DELETE FROM parametres WHERE cle='licence.verifiee_le'");
  await pi.goto('http://cnslbar.localhost:8180/gestion/assistance'); await pi.screenshot({ path: `${OUT}/instance-assistance.jpg`, type: 'jpeg', quality: 78 });
  q('rbp_console_essai', `DELETE FROM clients WHERE id=${idPort}`);
  await b.close();
  console.log(fs.readdirSync(OUT).join(' '));
})();
