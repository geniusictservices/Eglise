// Sessions de caisse : réglage, ouverture avec fond, ventes et remboursement dans la session, clôture avec manquant,
// rapport, gestion (liste, fiche, clôture d'une session oubliée par le gérant). Instance isolée « essaicaisse ».
const { chromium } = require('playwright'); const fs = require('fs'); const { execSync } = require('child_process');
const B = 'http://integ.localhost:8250'; const MDP = fs.readFileSync(__dirname + '/../instance.txt', 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
const sql = q => execSync(`mysql -N --default-character-set=utf8mb4 rbp_integ -e ${JSON.stringify("SET time_zone = '+02:00'; " + q)}`).toString().trim();
let nb = 0; const ko = []; const v = (c, m) => { nb++; if (!c) ko.push(m); console.log((c ? 'ok    ' : 'ÉCHEC ') + m); };
execSync('/home/user/Eglise/restau-bar-pos/outils/tests/restaurer.sh sans-garant');
async function connecter(ctx, id, mdp) { const p = await ctx.newPage(); await p.goto(B + '/gestion/connexion'); await p.fill('#email', id); await p.fill('#mot_de_passe', mdp); await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]); return p; }
async function ajouter(p, noms) {
  for (const n of noms) {
    const [nom, taille] = n.split('|');
    await p.locator('#grille .tuile', { has: p.locator(`.tuile__nom:text-is("${nom}")`) }).click(); await p.waitForTimeout(120);
    if (await p.locator('#formats[open]').count()) { await p.locator('#formats-liste .format', { hasText: taille }).click(); await p.waitForTimeout(120); }
  }
}
(async () => {
  const b = await chromium.launch(); const err = [];
  const ctx = await b.newContext({ viewport: { width: 1366, height: 860 } });
  const p = await connecter(ctx, 'admin@demo.test', MDP); p.on('pageerror', x => err.push(x.message)); p.on('console', m => m.type() === 'error' && !m.text().includes('Failed to load resource') && err.push(m.text()));
  // 1. Réglage
  await p.goto(B + '/gestion/utilisateurs/appareils');
  await p.check('#reglages-1 input[name=sessions_caisse]'); await Promise.all([p.waitForNavigation(), p.click('#reglages-1 button[type=submit]')]);
  v(sql('SELECT sessions_caisse FROM etablissements WHERE id=1') === '1' && sql('SELECT sessions_caisse FROM etablissements WHERE id=2') === '0', 'réglage : sessions demandées au Comptoir seulement');
  // 2. Caisse fermée
  await p.goto(B + '/caisse?e=comptoir-du-lac'); await p.evaluate(() => sessionStorage.clear()); await p.reload();
  v(await p.locator('#ouvrir-session').isVisible() && !(await p.locator('#encaisser').isVisible()), 'sans session : « Ouvrir ma caisse » à la place d\'Encaisser');
  await ajouter(p, ['Kivu Sunset']);
  v(await p.locator('.paiements [data-compte]:not([disabled])').count() === 0, 'sans session : raccourcis de paiement inactifs');
  const refus = await p.evaluate(async () => {
    const D = JSON.parse(document.getElementById('donnees-caisse').textContent); const f = new FormData(); f.append('csrf', D.csrf);
    f.append('vente', JSON.stringify({ cle: crypto.randomUUID(), etab: 'comptoir-du-lac', lignes: [{ id: D.produits.find(x => x.nom === 'Kivu Sunset').formats[0].id, qte: 1 }], paiements: [{ devise: 'CDF', compte: 1, recu: 22800 }] }));
    const r = await fetch('/caisse/encaisser', { method: 'POST', body: f }); return [r.status, (await r.json()).code];
  });
  v(refus[0] === 409 && refus[1] === 'session_fermee', 'encaisser sans session par l\'API : 409');
  // 3. Ouvrir avec 50 000 FC et 20 $
  await p.click('#ouvrir-session');
  await p.fill('#ouverture-1-2', '50000'); await p.fill('#ouverture-1-1', '20');
  await Promise.all([p.waitForNavigation(), p.click('#fenetre-ouverture button[type=submit]')]);
  const sid = sql("SELECT id FROM sessions_caisse WHERE statut='ouverte' AND utilisateur_id=1 AND etablissement_id=1");
  v(sid && sql(`SELECT GROUP_CONCAT(CONCAT(devise_id,':',fond) ORDER BY devise_id) FROM session_caisse_comptes WHERE session_id=${sid} AND compte_id=1`) === '1:20.000,2:50000.000', 'session ouverte : fond 20 $ et 50 000 FC');
  v((await p.textContent('.session-note')).includes('Caisse ouverte depuis') && await p.locator('#encaisser').isVisible(), 'caisse ouverte : Encaisser revient, « ouverte depuis »');
  // 4. Ventes : 22 800 FC en espèces ; 12 $ … payé 20 $, monnaie en FC
  await ajouter(p, ['Kivu Sunset']); await p.click('#encaisser'); await p.click('#paiement-valider'); await p.waitForSelector('#paiement-fin:not([hidden])'); await p.click('#paiement-suivant');
  await ajouter(p, ['Primus|Grande', 'Primus|Grande', 'Kivu Sunset']); await p.click('#encaisser'); await p.click('#paiement-devises button:text-is("$")');
  await p.locator('.paiement-rapide', { hasText: '$20' }).click(); await p.locator('#paiement-rendu-devises button', { hasText: 'FC' }).click();
  await p.click('#paiement-valider'); await p.waitForSelector('#paiement-fin:not([hidden])'); await p.click('#paiement-suivant');
  v(sql(`SELECT COUNT(*) FROM ventes WHERE session_id=${sid}`) === '2', 'deux ventes rattachées à la session');
  // Ardoise puis remboursement en FC dans la session
  sql(`INSERT INTO clients (nom, telephone) VALUES ('Session Test', '+243970009999')`);
  await ajouter(p, ['Kivu Sunset']); await p.click('.paiements [data-ardoise]'); await p.fill('#ardoise-recherche', 'Session'); await p.locator('.ardoise-client', { hasText: 'Session Test' }).click(); await p.waitForTimeout(200);
  await p.click('#paiement-valider'); await p.waitForSelector('#paiement-fin:not([hidden])'); await p.click('#paiement-suivant');
  await p.click('#ardoises'); await p.fill('#ardoises-recherche', 'Session'); await p.locator('.ardoise-client', { hasText: 'Session Test' }).click(); await p.waitForSelector('#ardoises-montant');
  await p.fill('#ardoises-montant', '10000'); await p.click('#ardoises-valider'); await p.waitForSelector('#fenetre-ardoises .recu'); await p.click('[data-fermer-ardoises]');
  v(sql(`SELECT COUNT(*) FROM remboursements WHERE session_id=${sid}`) === '1', 'remboursement à la caisse rattaché à la session');
  // 5. Clôture avec 1 000 FC de moins
  const attendu = Number(sql(`SELECT 50000 + COALESCE(SUM(IF(m.sens='entree',m.montant,-m.montant)),0) FROM mouvements_tresorerie m WHERE m.compte_id=1 AND m.devise_id=2 AND ((m.nature='vente' AND m.source_id IN (SELECT id FROM ventes WHERE session_id=${sid})) OR (m.nature='remboursement' AND m.source_id IN (SELECT id FROM remboursements WHERE session_id=${sid})))`));
  v(attendu === 50000 + Number(sql(`SELECT total FROM ventes WHERE session_id=${sid} AND rendu=0 AND id NOT IN (SELECT vente_id FROM dettes WHERE vente_id IS NOT NULL)`)) - Number(sql(`SELECT rendu FROM ventes WHERE session_id=${sid} AND rendu>0`)) + 10000, 'attendu FC = fond + ventes en FC − monnaie rendue + 10 000 remboursés : ' + attendu);
  await p.click('#quitter'); await p.click('#cloturer-session');
  await p.fill('#cloture-1-2', String(attendu - 1000)); await p.fill('#cloture-1-1', '40'); await p.fill('#cloture-note', 'Billet déchiré');
  await p.click('#fenetre-cloture button[type=submit]'); await p.waitForSelector('#fenetre-cloture .session-rapport:not([hidden])');
  const rapport = await p.textContent('#fenetre-cloture .recu');
  v(rapport.includes('Manquant') && rapport.includes('Clôture de caisse') && rapport.includes('Billet déchiré'), 'rapport de clôture : manquant, remarque');
  v(sql(`SELECT CONCAT(statut,'|',fermee_par) FROM sessions_caisse WHERE id=${sid}`) === 'fermee|1' && sql(`SELECT ecart FROM session_caisse_comptes WHERE session_id=${sid} AND compte_id=1 AND devise_id=2`) === '-1000.000' && sql(`SELECT ecart FROM session_caisse_comptes WHERE session_id=${sid} AND compte_id=1 AND devise_id=1`) === '0.000', 'session fermée : −1 000 FC, 0 $ (20 $ fond + 20 $ reçus)');
  v(sql(`SELECT CONCAT(sens,'|',montant) FROM mouvements_tresorerie WHERE nature='ajustement' AND source_id=${sid}`) === 'sortie|1000.000', 'trésorerie : manquant de 1 000 FC sorti de la caisse (ajustement)');
  await p.click('#fenetre-cloture [data-terminer]'); await p.waitForLoadState('load');
  v(await p.locator('#ouvrir-session').isVisible(), 'après la clôture : « Ouvrir ma caisse »');
  // 6. Autre établissement sans sessions : rien ne change
  await p.goto(B + '/caisse?e=volcano-club');
  v(await p.locator('#ouvrir-session').count() === 0 && await p.locator('#encaisser').isVisible(), 'Volcano Club (sans sessions) : caisse normale');
  // 7. Gestion : Patrick ouvre une session (caisse), l'oublie ; il la clôture depuis la gestion
  const ctxP = await b.newContext({ viewport: { width: 1280, height: 800 } });
  const pa = await connecter(ctxP, 'patrick@demo.test', 'Gerant-Goma-2026'); pa.on('pageerror', x => err.push(x.message));
  await pa.goto(B + '/caisse?e=comptoir-du-lac'); await pa.click('#ouvrir-session'); await Promise.all([pa.waitForNavigation(), pa.click('#fenetre-ouverture button[type=submit]')]);
  const sid2 = sql("SELECT id FROM sessions_caisse WHERE statut='ouverte' AND utilisateur_id=2");
  await pa.goto(B + '/gestion/sessions-caisse');
  const liste = await pa.textContent('.ven-liste');
  v(liste.includes('Administrateur') && /−\s?1\s000\sFC/.test(liste) && liste.includes('Ouverte'), 'gestion : liste avec l\'écart (−1 000 FC) et la session ouverte');
  await pa.goto(B + '/gestion/sessions-caisse/' + sid2);
  for (const f of await pa.locator('form[action$="/cloturer"] input[name^=compte]').all()) await f.fill('0');
  await Promise.all([pa.waitForNavigation(), pa.click('form[action$="/cloturer"] button[type=submit]')]);
  v(sql(`SELECT CONCAT(statut,'|',fermee_par) FROM sessions_caisse WHERE id=${sid2}`) === 'fermee|2', 'le gérant clôture une session oubliée depuis la gestion');
  // Comptable : voit, ne clôture pas
  const ctxG = await b.newContext(); const g = await connecter(ctxG, 'grace@demo.test', 'Compta-Goma-2026');
  await g.goto(B + '/gestion/sessions-caisse/' + sid);
  v((await g.textContent('main, body')).includes('Clôture de caisse') && await g.locator('form[action$="/cloturer"]').count() === 0, 'comptable : voit le rapport, pas de clôture');
  await p.goto(B + '/gestion/sessions-caisse/' + sid); await p.screenshot({ path: __dirname + '/session-gestion.png', fullPage: true });
  const tel = await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  const tp = await connecter(tel, 'admin@demo.test', MDP); await tp.goto(B + '/caisse?e=comptoir-du-lac'); await tp.click('#resume').catch(() => {});
  await tp.evaluate(() => document.getElementById('ouvrir-session').click()); await tp.waitForTimeout(300);
  v(await tp.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'téléphone : ouverture sans défilement horizontal');
  await tp.screenshot({ path: __dirname + '/session-ouvrir-tel.png' });
  v(err.length === 0, 'aucune erreur JS ' + err.join(' | '));
  console.log(`\n${nb - ko.length}/${nb}`); await b.close();
})();
