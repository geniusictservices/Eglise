// API de messagerie de la plateforme (console) et Messagerie de l'instance qui les lit ; relances des ardoises
// sans réglages (inscrites), avec réglages (faux Africa's Talking et faux WhatsApp), sender ID du client,
// console injoignable, usage du mois
const { chromium } = require('playwright');
const fs = require('fs');
const { execSync } = require('child_process');
const { v, q, A, D, connecterConsole, connecterInstance, bilan, surveiller } = require('./outils');
const C = 'rbp_console_essai', B = 'rbp_cnslbar', LOG = D + '/faux-services.log';
const relances = (config = D + '/console.php') => {
  try { return execSync(`cd /home/user/Eglise/restau-bar-pos && RBP_CONSOLE_CONFIG=${config} php bin/relances.php cnslbar --date=2026-11-10`).toString(); }
  catch (e) { return 'CODE ' + e.status + ' ' + e.stdout; }
};
const requetes = (chemin) => fs.readFileSync(LOG, 'utf8').split('\n').filter(l => l.includes(`"chemin":"${chemin}"`));
const nos = "('+243970000001','+243970000002')";
(async () => {
  const b = await chromium.launch();
  const erreurs = [];
  const etat = JSON.parse(fs.readFileSync(D + '/etat.json'));
  // Données de relance : deux clients du bar, une dette échue demain (SMS), une en retard de 8 jours (WhatsApp)
  q(B, `DELETE FROM messages; DELETE d FROM dettes d JOIN clients c ON c.id=d.client_id WHERE c.telephone IN ${nos}; DELETE FROM clients WHERE telephone IN ${nos};
    UPDATE dettes SET statut='annulee' WHERE statut <> 'soldee';
    INSERT INTO clients (nom, telephone, langue, canal) VALUES ('Furaha Neema', '+243970000001', 'fr', 'sms'), ('John Doe', '+243970000002', 'en', 'whatsapp');
    INSERT INTO dettes (client_id, etablissement_id, devise_id, montant, reste, echeance, servi_par, accorde_par, responsable_id)
      SELECT id, 1, 2, 45000, 45000, '2026-11-11', 4, 2, 4 FROM clients WHERE telephone='+243970000001';
    INSERT INTO dettes (client_id, etablissement_id, devise_id, montant, reste, echeance, servi_par, accorde_par, responsable_id)
      SELECT id, 2, 1, 12.5, 12.5, '2026-11-02', 6, 2, 6 FROM clients WHERE telephone='+243970000002';`);
  q(C, "DELETE FROM messages WHERE evenement='test'");
  q(C, "DELETE FROM parametres WHERE cle LIKE 'sms.%' OR (cle LIKE 'whatsapp.%' AND cle NOT IN ('whatsapp.version','whatsapp.modele_rappel_client','whatsapp.modele_rappel_responsable','whatsapp.modele_garant_demande','whatsapp.langue_fr','whatsapp.langue_en'))");

  // 1. Sans réglages de la plateforme : messages seulement inscrits
  fs.writeFileSync(LOG, '');
  let sortie = relances();
  v(q(B, `SELECT COUNT(*) FROM messages WHERE destinataire IN ${nos} AND statut='simule'`), 2, 'sans API réglées : les 2 relances clients sont inscrites sans envoi');
  v(requetes('/sms').length + requetes('/wa').length, 0, 'sans API réglées : aucune requête vers les services');
  v(q(B, "SELECT texte FROM messages WHERE destinataire='+243970000001'"), /^Le Comptoir du Lac : bonjour Furaha/, 'chaque message commence par le nom du bar');
  // Sans console du tout (pas de config/console.php) : pareil
  q(B, 'DELETE FROM messages');
  relances(D + '/absent.php');
  v(q(B, `SELECT COUNT(*) FROM messages WHERE destinataire IN ${nos} AND statut='simule'`), 2, 'sans console : relances inscrites, comme avant');

  // 2. Réglages dans la console
  const c = await b.newContext(); const p = await c.newPage(); surveiller(p, erreurs);
  await connecterConsole(p);
  await p.goto(A + '/messagerie');
  v(await p.locator('[data-etat-sms]').getAttribute('data-etat-sms'), 'non', 'console : SMS « non réglé »');
  await p.fill('#sms_utilisateur', 'sandbox'); await p.fill('#sms_cle', 'atsk_cle_secrete_1234'); await p.fill('#sms_expediteur', 'RESTO BAR');
  await p.fill('#whatsapp_numero_id', '1098765'); await p.fill('#whatsapp_waba_id', '5550001'); await p.fill('#whatsapp_jeton', 'EAAG_jeton_permanent_9876');
  await p.fill('#whatsapp_modele_rappel_client', 'rappel_client'); await p.fill('#whatsapp_langue_en', 'en_US');
  await p.locator('details.con-avance summary').click();
  await p.fill('#sms_url', 'http://127.0.0.1:8199/sms'); await p.fill('#whatsapp_url', 'http://127.0.0.1:8199/wa');
  await p.click('main form[action="/messagerie"] button[type=submit]');
  v(await p.locator('#err-sms_expediteur').count(), 1, 'sender ID avec espace refusé');
  await p.fill('#sms_cle', 'atsk_cle_secrete_1234'); await p.fill('#whatsapp_jeton', 'EAAG_jeton_permanent_9876');
  await p.fill('#sms_expediteur', 'RESTOBAR');
  await Promise.all([p.waitForNavigation(), p.click('main form[action="/messagerie"] button[type=submit]')]);
  v(await p.locator('[data-etat-sms]').getAttribute('data-etat-sms') + ' ' + await p.locator('[data-etat-whatsapp]').getAttribute('data-etat-whatsapp'), 'pret pret', 'console : SMS et WhatsApp prêts');
  v(await p.locator('#sms_cle').getAttribute('placeholder'), '•••• 1234', 'clé Africa\'s Talking masquée (•••• 1234)');
  v(await p.locator('#whatsapp_jeton').getAttribute('placeholder'), '•••• 9876', 'jeton WhatsApp masqué');
  const html = await p.content();
  v(html.includes('atsk_cle_secrete_1234') || html.includes('EAAG_jeton_permanent_9876'), false, 'les secrets ne sont jamais réaffichés dans la page');
  v(await p.locator('#sms_cle').inputValue(), '', 'champ du secret vide');
  await p.screenshot({ path: D + '/cap/messagerie-ordi.png', fullPage: true });
  // Enregistrer sans retaper : les secrets sont gardés
  await p.fill('#sms_expediteur', 'RESTOBAR');
  await Promise.all([p.waitForNavigation(), p.click('main form[action="/messagerie"] button[type=submit]')]);
  v(q(C, "SELECT valeur FROM parametres WHERE cle='sms.cle'"), 'atsk_cle_secrete_1234', 'champ vide = secret gardé');
  // Sender ID propre au bar d'essai
  const ligne = p.locator(`[data-usage-client="cnslbar"]`);
  await ligne.locator('input[name=expediteur_sms]').fill('VOLCANO');
  await Promise.all([p.waitForNavigation(), ligne.locator('button').click()]);
  v(q(C, "SELECT expediteur_sms FROM clients WHERE sous_domaine='cnslbar'"), 'VOLCANO', 'sender ID propre au client enregistré');

  // Message d'essai depuis la console
  fs.writeFileSync(LOG, '');
  await p.fill('#essai-tel', '0970 000 009');
  await Promise.all([p.waitForNavigation(), p.click('#essai button[type=submit]')]);
  v(await p.locator('[data-flash]').textContent(), /SMS envoyé à \+243970000009/, 'essai SMS : envoyé');
  const essai = JSON.parse(requetes('/sms')[0]);
  v(essai.entetes.apiKey || essai.entetes.Apikey || essai.entetes.apikey, 'atsk_cle_secrete_1234', 'essai : clé dans l\'en-tête apiKey');
  v(/username=sandbox/.test(essai.corps) && /from=RESTOBAR/.test(essai.corps) && /to=%2B243970000009/.test(essai.corps), true, 'essai : username, to, from (sender ID de la plateforme)');
  await p.selectOption('#essai-canal', 'whatsapp'); await p.fill('#essai-tel', '+243970000009');
  await Promise.all([p.waitForNavigation(), p.click('#essai button[type=submit]')]);
  v(await p.locator('[data-flash]').textContent(), /WHATSAPP envoyé/, 'essai WhatsApp : envoyé (texte libre)');
  v(q(C, "SELECT COUNT(*) FROM messages WHERE evenement='test' AND statut='envoye'"), 2, 'essais inscrits au journal des messages de la console');

  // 3. L'instance lit les réglages de la plateforme
  q(B, 'DELETE FROM messages'); fs.writeFileSync(LOG, '');
  sortie = relances();
  v(q(B, "SELECT CONCAT(canal,':',statut) FROM messages WHERE destinataire='+243970000001'"), 'sms:envoye', 'instance : relance SMS envoyée par le compte de la plateforme');
  v(q(B, "SELECT CONCAT(canal,':',statut,':',LEFT(fournisseur_id,5)) FROM messages WHERE destinataire='+243970000002'"), 'whatsapp:envoye:wamid', 'instance : relance WhatsApp envoyée (modèle rappel_client)');
  const sms = requetes('/sms');
  v(sms.length > 0 && sms.every(l => /from=VOLCANO/.test(l)), true, 'instance : sender ID propre au bar (VOLCANO) au lieu de celui de la plateforme');
  v(sms.every(l => /atsk_cle_secrete_1234/.test(l)), true, 'instance : clé de la plateforme dans l\'en-tête');
  const wa = requetes('/wa');
  const w = JSON.parse(wa[0]); const corps = JSON.parse(w.corps);
  v(wa.length === 1 && corps.template.name === 'rappel_client' && corps.template.language.code === 'en_US' && w.entetes.Authorization === 'Bearer EAAG_jeton_permanent_9876' && corps.to === '243970000002', true, 'instance : WhatsApp avec modèle, langue en_US, jeton, numéro sans +');
  v(corps.template.components[0].parameters.map(x => x.text).join('|'), 'John|Volcano Club|$12.50|Nov 2, 2026', 'WhatsApp : variables {{1}} prénom, {{2}} établissement, {{3}} reste, {{4}} échéance');
  v(q(B, "SELECT COUNT(*) FROM parametres WHERE cle LIKE 'sms.%' OR cle LIKE 'whatsapp.%'"), 0, 'aucune clé gardée dans la base du bar');
  // Responsables : pas de modèle WhatsApp réglé pour eux → SMS
  v(q(B, "SELECT GROUP_CONCAT(DISTINCT canal) FROM messages WHERE evenement='rappel_responsable'"), 'sms', 'responsables : SMS (pas de modèle WhatsApp réglé pour eux)');
  // Les relances restent réglées dans l'instance (relances.*)
  q(B, "REPLACE INTO parametres (cle, valeur) VALUES ('relances.canal','sms')"); q(B, 'DELETE FROM messages');
  relances();
  v(q(B, "SELECT canal FROM messages WHERE destinataire='+243970000002'"), 'sms', 'réglage de l\'instance relances.canal=sms respecté');
  q(B, "DELETE FROM parametres WHERE cle='relances.canal'");
  // WhatsApp refusé : SMS de secours ; console injoignable : échec explicite
  q(C, "UPDATE parametres SET valeur='http://127.0.0.1:8199/wa-ko' WHERE cle='whatsapp.url'"); q(B, 'DELETE FROM messages');
  relances();
  v(q(B, "SELECT CONCAT(canal,':',statut) FROM messages WHERE destinataire='+243970000002'"), 'sms:envoye', 'WhatsApp refusé (132001) : SMS de secours');
  q(C, "UPDATE parametres SET valeur='http://127.0.0.1:8199/wa' WHERE cle='whatsapp.url'"); q(B, 'DELETE FROM messages');
  sortie = relances(D + '/console-ko.php');
  v(q(B, "SELECT CONCAT(statut,':',erreur) FROM messages WHERE destinataire='+243970000001'"), 'echec:plateforme de messagerie injoignable', 'console injoignable : relance en échec, raison inscrite');
  v(/^CODE 2/.test(sortie), true, 'console injoignable : le script sort avec le code 2 (alerte du planificateur)');
  q(B, 'DELETE FROM messages'); relances();

  // 4. Usage du mois dans la console (compte seulement)
  const envoyes = q(B, "SELECT COUNT(*) FROM messages WHERE canal='sms' AND statut='envoye'");
  await p.goto(A + '/messagerie?mois=' + q(B, "SELECT DATE_FORMAT(NOW(), '%Y-%m')"));
  v(await p.locator('[data-usage-client="cnslbar"] [data-sms]').textContent(), envoyes, 'usage du mois : nombre de SMS envoyés par le bar');
  // Effacer un secret
  await p.check('input[name=sms_cle_effacer]');
  await Promise.all([p.waitForNavigation(), p.click('main form[action="/messagerie"] button[type=submit]')]);
  v(q(C, "SELECT COUNT(*) FROM parametres WHERE cle='sms.cle' AND valeur IS NOT NULL"), 0, 'case « Effacer » : clé retirée');
  v(await p.locator('[data-etat-sms]').getAttribute('data-etat-sms'), 'non', 'SMS de nouveau « non réglé »');
  q(C, "UPDATE parametres SET valeur='atsk_cle_secrete_1234' WHERE cle='sms.cle'");

  // 5. Écran de l'instance « Paramètres > Messagerie » (autre agent) : il s'affiche sans clé
  const ci = await b.newContext(); const pi = await ci.newPage(); surveiller(pi, erreurs);
  await connecterInstance(pi, 'cnslbar', 'patron@cnslbar.test', 'Patron-Bar-2026');
  const r = await pi.goto('http://cnslbar.localhost:8180/gestion/messagerie');
  const page = await pi.content();
  v(r.status() === 200 && !page.includes('atsk_cle') && !page.includes('EAAG_'), true, 'instance : écran Messagerie sans aucune clé');
  console.log(erreurs.length ? erreurs.join('\n') : 'aucune erreur JS');
  await b.close();
  process.exit(bilan() ? 1 : 0);
})();
