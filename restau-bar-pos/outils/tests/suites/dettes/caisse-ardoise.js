// Ardoises à la caisse : crédit (partiel, total, création rapide), plafond et retard (forcer), garant,
// remboursement, droits, annulation d'une vente à crédit. Instance isolée « essaicaisse » (base rbp_integ).
const { chromium } = require('playwright'); const fs = require('fs'); const { execSync } = require('child_process');
const B = 'http://integ.localhost:8250'; const MDP = fs.readFileSync(__dirname + '/../instance.txt', 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
const sql = q => execSync(`mysql -N --default-character-set=utf8mb4 rbp_integ -e ${JSON.stringify("SET time_zone = '+02:00'; " + q)}`).toString().trim();
let nb = 0; const ko = []; const v = (c, m) => { nb++; if (!c) ko.push(m); console.log((c ? 'ok    ' : 'ÉCHEC ') + m); };
execSync('/home/user/Eglise/restau-bar-pos/outils/tests/restaurer.sh sans-garant');
sql(`INSERT INTO clients (nom, telephone, langue, canal) VALUES ('Testa Furaha', '+243970000011', 'fr', 'sms'), ('Bahati Plafond', '+243970000012', 'fr', 'sms'), ('Kalume Retard', '+243970000013', 'fr', 'whatsapp')`);
sql(`UPDATE clients SET plafond = 10000, plafond_devise_id = 2 WHERE telephone = '+243970000012'`);
sql(`INSERT INTO dettes (client_id, etablissement_id, devise_id, montant, reste, echeance, servi_par, accorde_par, responsable_id, cree_le)
     SELECT id, 1, 2, 5000, 5000, DATE_SUB(CURDATE(), INTERVAL 10 DAY), 4, 2, 4, DATE_SUB(NOW(), INTERVAL 20 DAY) FROM clients WHERE telephone = '+243970000013'`);
sql(`UPDATE dettes SET garant_statut = 'refuse' WHERE garant_statut = 'en_attente'`);   // garanties de la démo : hors du test
const cid = tel => sql(`SELECT id FROM clients WHERE telephone='${tel}'`);
async function connecter(ctx, id, mdp) { const p = await ctx.newPage(); await p.goto(B + '/gestion/connexion'); await p.fill('#email', id); await p.fill('#mot_de_passe', mdp); await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]); return p; }
async function ajouter(p, noms) {
  for (const n of noms) {
    const [nom, taille] = n.split('|');
    await p.locator('#grille .tuile', { has: p.locator(`.tuile__nom:text-is("${nom}")`) }).click(); await p.waitForTimeout(120);
    if (await p.locator('#formats[open]').count()) { await p.locator('#formats-liste .format', { hasText: taille }).click(); await p.waitForTimeout(120); }
  }
}
async function chercherClient(p, q, nom) { await p.fill('#ardoise-recherche', q); await p.locator('.ardoise-client', { hasText: nom }).click(); await p.waitForTimeout(150); }
const derniere = () => sql('SELECT id FROM ventes ORDER BY id DESC LIMIT 1');
(async () => {
  const b = await chromium.launch(); const err = [];
  const ctx = await b.newContext({ viewport: { width: 1366, height: 860 } });
  const p = await connecter(ctx, 'admin@demo.test', MDP); p.on('pageerror', x => err.push(x.message)); p.on('console', m => m.type() === 'error' && !m.text().includes('Failed to load resource') && err.push(m.text()));
  await p.goto(B + '/caisse?e=comptoir-du-lac'); await p.evaluate(() => sessionStorage.clear()); await p.reload();

  // 1. Une partie payée, le reste à l'ardoise, servi par Aline, garant Patrick
  await ajouter(p, ['Primus|Grande', 'Primus|Grande', 'Kivu Sunset']);   // 33 800 FC
  await p.click('#encaisser');
  await p.fill('#recu-CDF', '10000');
  await p.click('#ajouter-ardoise');
  v(await p.locator('#ardoise').count() === 1 && await p.inputValue('#recu-CDF') === '10000', 'ardoise ajoutée : le montant payé reste');
  v(await p.locator('#paiement-valider').isDisabled() && (await p.textContent('#paiement-bilan')).includes('Choisissez le client'), 'sans client : validation impossible');
  await chercherClient(p, 'Testa F', 'Testa Furaha');
  v(await p.inputValue('#ardoise-montant') === '23800', 'l\'ardoise prend le reste : 23 800 FC');
  await p.fill('#recu-CDF', '15000');
  v(await p.inputValue('#ardoise-montant') === '18800', 'payé 15 000 : l\'ardoise passe à 18 800 FC');
  await p.click('#ardoise-servi [data-personne="4"]');
  await p.selectOption('#ardoise-garant', '2');
  v((await p.textContent('#ardoise')).includes('confirmera plus tard'), 'garant choisi : « il confirmera plus tard »');
  const ech = await p.inputValue('#ardoise-echeance');
  v(ech === new Date(Date.now() + 7 * 86400000).toLocaleDateString('en-CA', { timeZone: 'Africa/Lubumbashi' }), 'échéance proposée : dans 7 jours (délai de l\'établissement)');
  await p.click('#paiement-valider'); await p.waitForSelector('#paiement-fin:not([hidden])');
  let id = derniere();
  let d = sql(`SELECT CONCAT(montant,'|',reste,'|',devise_id,'|',servi_par,'|',accorde_par,'|',garant_id,'|',garant_statut,'|',responsable_id,'|',echeance) FROM dettes WHERE vente_id=${id}`);
  v(d === `18800.000|18800.000|2|4|1|2|en_attente|4|${ech}`, 'dette : 18 800 FC, servi Aline (responsable), accordée par l\'admin, garant Patrick en attente');
  v(sql(`SELECT GROUP_CONCAT(recu) FROM vente_paiements WHERE vente_id=${id}`) === '15000.000', 'seuls 15 000 FC sont payés');
  v(sql(`SELECT COALESCE(SUM(montant),0) FROM mouvements_tresorerie WHERE nature='vente' AND source_id=${id}`) === '15000.000', 'trésorerie : 15 000 FC seulement (l\'ardoise n\'entre pas en caisse)');
  v((await p.textContent('#paiement-recu')).includes('À l\'ardoise') && (await p.textContent('#paiement-recu')).includes('Testa Furaha'), 'reçu : « À l\'ardoise », nom du client');
  await p.click('#paiement-suivant');

  // 2. Tout à l'ardoise par le raccourci, client créé sur place
  await ajouter(p, ['Kivu Sunset']);   // 22 800 FC
  await p.click('.paiements [data-ardoise]');
  v(await p.locator('.paiement-ligne__montant[id^="recu-"]').count() === 0, 'raccourci « Crédit client » : rien à payer maintenant, tout à l\'ardoise');
  await p.fill('#ardoise-recherche', '0997 123 456');
  await p.click('.ardoise-nouveau > summary'); await p.waitForTimeout(150);
  v(await p.inputValue('#ardoise-telephone') === '0997 123 456', 'nouveau client : le numéro tapé est repris');
  await p.click('#ardoise-creer');
  await p.waitForTimeout(300);
  v((await p.textContent('#paiement-erreur, .ardoise-nouveau')).includes('nom'), 'sans nom : refusé');
  await p.fill('#ardoise-nom', 'Testo Kasongo'); await p.check('#ardoise-whatsapp'); await p.click('#ardoise-creer');
  await p.waitForSelector('.ardoise-carte');
  v(sql(`SELECT CONCAT(nom,'|',telephone,'|',canal) FROM clients WHERE telephone='+243997123456'`) === 'Testo Kasongo|+243997123456|whatsapp', 'client créé : +243997123456, rappels WhatsApp');
  v(await p.inputValue('#ardoise-montant') === '22800', 'ardoise : tout le ticket (22 800 FC)');
  await p.click('#paiement-valider'); await p.waitForSelector('#paiement-fin:not([hidden])'); id = derniere();
  v(sql(`SELECT COUNT(*) FROM vente_paiements WHERE vente_id=${id}`) === '0' && sql(`SELECT CONCAT(montant,'|',servi_par,'|',responsable_id) FROM dettes WHERE vente_id=${id}`) === '22800.000|1|1', 'vente entièrement à l\'ardoise, servie par l\'admin');
  await p.click('#paiement-suivant');

  // 3. Ardoise en dollars
  await ajouter(p, ['Kivu Sunset']); await p.click('.paiements [data-ardoise]');
  await chercherClient(p, 'Testo K', 'Testo Kasongo');
  await p.locator('.paiement-ardoise__devises button', { hasText: '$' }).click();
  v(await p.inputValue('#ardoise-montant') === '8', 'ardoise en $ : 8 $');
  await p.click('#paiement-valider'); await p.waitForSelector('#paiement-fin:not([hidden])'); id = derniere();
  v(sql(`SELECT CONCAT(montant,'|',devise_id) FROM dettes WHERE vente_id=${id}`) === '8.000|1', 'dette enregistrée en dollars');
  await p.click('#paiement-suivant');

  // 4. Plafond dépassé : l'admin (dettes.gerer) peut forcer
  await ajouter(p, ['Kivu Sunset']); await p.click('.paiements [data-ardoise]');
  await chercherClient(p, 'Bahati', 'Bahati Plafond');
  await p.click('#paiement-valider'); await p.waitForSelector('#paiement-erreur:not([hidden])');
  v((await p.textContent('#paiement-erreur')).includes('Plafond') && await p.locator('#ardoise-forcer').count() === 1, 'plafond (10 000 FC) dépassé : refusé, case « Forcer » proposée au gérant');
  await p.check('#ardoise-forcer'); await p.click('#paiement-valider'); await p.waitForSelector('#paiement-fin:not([hidden])'); id = derniere();
  v(sql(`SELECT montant FROM dettes WHERE vente_id=${id}`) === '22800.000' && sql(`SELECT COUNT(*) FROM journal WHERE action='credit_force'`) === '1', 'forcé : dette enregistrée, inscrit au journal');
  await p.click('#paiement-suivant');

  // 5. L'ardoise ne peut pas dépasser le reste
  await ajouter(p, ['Kivu Sunset']); await p.click('#encaisser'); await p.fill('#recu-CDF', '10000'); await p.click('#ajouter-ardoise');
  await chercherClient(p, 'Testa F', 'Testa Furaha'); await p.fill('#ardoise-montant', '30000');
  v(await p.locator('#paiement-valider').isDisabled() && (await p.textContent('#paiement-bilan')).includes('dépasse'), 'ardoise plus grande que le reste : validation impossible');
  await p.click('#retirer-ardoise');
  v(await p.locator('#ardoise').count() === 0 && await p.locator('#ajouter-ardoise').count() === 1, '« Retirer l\'ardoise » : retour au paiement normal');
  await p.click('[data-fermer-paiement]'); await p.click('#vider');

  // 6. Superviseure du Club : retard du client, pas le droit de forcer
  const ctxR = await b.newContext({ viewport: { width: 1280, height: 800 } });
  const r = await connecter(ctxR, 'riziki@demo.test', 'Superviseur-2026'); r.on('pageerror', x => err.push(x.message));
  await r.goto(B + '/caisse?e=volcano-club');
  await ajouter(r, ['Mojito']); await r.click('.paiements [data-ardoise]');
  await chercherClient(r, 'Kalume', 'Kalume Retard');
  v((await r.textContent('.ardoise-carte')).includes('En retard depuis'), 'client en retard : signalé dès le choix');
  await r.click('#paiement-valider'); await r.waitForSelector('#paiement-erreur:not([hidden])');
  v((await r.textContent('#paiement-erreur')).includes('Appelez le gérant') && await r.locator('#ardoise-forcer').count() === 0, 'superviseure : refusé, « Appelez le gérant », pas de case Forcer');
  const forceR = await r.evaluate(async () => {
    const D = JSON.parse(document.getElementById('donnees-caisse').textContent);
    const f = new FormData(); f.append('csrf', D.csrf);
    f.append('vente', JSON.stringify({ cle: crypto.randomUUID(), etab: 'volcano-club', lignes: [{ id: D.produits.find(x => x.nom === 'Mojito').formats[0].id, qte: 1 }], paiements: [],
      credit: { client: +document.querySelector('.ardoise-carte') && 0, devise: 'CDF', montant: 1, servi: D.moi, garant: null, echeance: D.aujourdhui, forcer: true } }));
    return (await fetch('/caisse/encaisser', { method: 'POST', body: f })).status;
  });
  v(forceR === 422, 'forcer par l\'API sans le droit : refusé');
  await r.click('[data-fermer-paiement]');

  // 7. Garant : Patrick confirme à la caisse
  const ctxP = await b.newContext({ viewport: { width: 1280, height: 800 } });
  const pa = await connecter(ctxP, 'patrick@demo.test', 'Gerant-Goma-2026'); pa.on('pageerror', x => err.push(x.message));
  await pa.goto(B + '/caisse?e=comptoir-du-lac');
  v(await pa.locator('#fenetre-garanties[open]').count() === 1 && (await pa.textContent('#garanties-liste')).includes('Aline Mukeba vous désigne garant'), 'Patrick : la demande de garantie s\'ouvre à son arrivée');
  await pa.click('#garanties-liste [data-reponse="accepte"]'); await pa.waitForTimeout(400);
  v(sql(`SELECT CONCAT(garant_statut,'|',responsable_id) FROM dettes WHERE garant_id=2 ORDER BY id DESC LIMIT 1`) === 'accepte|2' && (await pa.textContent('#garanties-liste')).includes('Plus rien'), 'accepté : Patrick devient responsable ; plus rien à confirmer');
  await pa.keyboard.press('Escape'); await pa.reload();
  v(await pa.locator('#fenetre-garanties').count() === 0 && await pa.locator('#garanties').count() === 0, 'rechargé : plus de demande');

  // 8. Remboursement à la caisse : Furaha paie tout en dollars sur la caisse du Comptoir
  await p.click('#ardoises'); await p.fill('#ardoises-recherche', 'Testa F'); await p.locator('.ardoise-client', { hasText: 'Testa Furaha' }).click();
  await p.waitForSelector('.ardoises-dettes');
  v(/18\s800\sFC/.test(await p.textContent('.ardoises-dettes')), 'ardoises ouvertes de Furaha : 18 800 FC');
  await p.locator('#fenetre-ardoises .segments button', { hasText: '$' }).click();
  const tout = await p.textContent('#ardoises-tout');
  v(/6[,.]6\d/.test(tout), '« Tout » en dollars : ' + tout.trim());
  await p.click('#ardoises-tout'); await p.locator('#fenetre-ardoises .puce', { hasText: 'Caisse du Comptoir' }).click();
  await p.click('#ardoises-valider'); await p.waitForSelector('#fenetre-ardoises .recu');
  const recu = await p.textContent('#fenetre-ardoises .recu');
  v(recu.includes('Remboursement') && recu.includes('Plus rien à payer'), 'reçu de remboursement : « Plus rien à payer »');
  const fura = cid('+243970000011');
  v(sql(`SELECT GROUP_CONCAT(statut) FROM dettes WHERE client_id=${fura}`) === 'soldee' && sql(`SELECT CONCAT(moyen,'|',devise_id) FROM remboursements WHERE client_id=${fura}`) === 'caisse|1', 'dette soldée, remboursement en $ dans la caisse');
  v(sql(`SELECT COUNT(*) FROM mouvements_tresorerie WHERE nature='remboursement' AND compte_id=1 AND devise_id=1 AND source_id=(SELECT id FROM remboursements WHERE client_id=${fura})`) === '1', 'trésorerie : entrée « remboursement » en $ dans la caisse du Comptoir');
  // Trop payé
  await p.click('#ardoises-autre'); await p.fill('#ardoises-recherche', 'Testo K'); await p.locator('.ardoise-client', { hasText: 'Testo Kasongo' }).click(); await p.waitForSelector('#ardoises-montant');
  await p.fill('#ardoises-montant', '500000'); await p.click('#ardoises-valider'); await p.waitForSelector('#ardoises-erreur:not([hidden])');
  v((await p.textContent('#ardoises-erreur')).includes('au plus'), 'plus que la dette : refusé avec le maximum');
  await p.click('[data-fermer-ardoises]');

  // 9. Annulation en gestion : vente à crédit remboursée → refusée ; non remboursée → la dette s'annule
  const venteFura = sql(`SELECT vente_id FROM dettes WHERE client_id=${fura} LIMIT 1`);
  await p.goto(B + '/gestion/ventes/' + venteFura);
  if (await p.locator('textarea[name=motif]').count()) {
    await p.fill('textarea[name=motif]', 'Erreur de ticket'); await p.check('input[name=confirme]'); await p.locator('form:has(textarea[name=motif]) button[type=submit]').click(); await p.waitForLoadState('load');
    v((await p.textContent('body')).includes('déjà remboursé'), 'annuler une vente dont l\'ardoise est remboursée : refusé');
    const venteKas = sql(`SELECT vente_id FROM dettes WHERE devise_id=1 AND client_id=${cid('+243997123456')}`);
    await p.goto(B + '/gestion/ventes/' + venteKas);
    await p.fill('textarea[name=motif]', 'Erreur de ticket'); await p.check('input[name=confirme]'); await p.locator('form:has(textarea[name=motif]) button[type=submit]').click(); await p.waitForLoadState('load');
    v(sql(`SELECT CONCAT(v.statut,'|',d.statut) FROM ventes v JOIN dettes d ON d.vente_id=v.id WHERE v.id=${venteKas}`) === 'annulee|annulee', 'vente à crédit annulée : sa dette aussi');
  } else { v(false, 'formulaire d\'annulation introuvable'); }

  // 10. Serveuse (caisse partagée) : ni crédit ni ardoises ; API refusée
  const ctxS = await b.newContext({ viewport: { width: 1280, height: 800 } });
  const s = await connecter(ctxS, 'admin@demo.test', MDP);
  await s.goto(B + '/caisse?e=comptoir-du-lac'); await s.click('#quitter'); await s.click('.partager > summary:has-text("caisse partagée")');
  await Promise.all([s.waitForNavigation(), s.click('.partager__form button[type=submit]')]);
  await s.click('.verrou__personne[data-nom="Aline Mukeba"]'); for (const c of '271828') await s.click(`#pave-touches [data-chiffre="${c}"]`); await s.waitForLoadState('load'); await s.waitForTimeout(300);
  v(await s.locator('.paiements [data-ardoise]').count() === 0 && await s.locator('#ardoises').count() === 0, 'serveuse : pas de « Crédit client » ni d\'« Ardoises »');
  const api = await s.evaluate(async () => (await fetch('/caisse/clients?etab=comptoir-du-lac&q=Furaha')).status);
  v(api === 403, 'serveuse : recherche de clients refusée (403)');
  const sansJeton = await s.evaluate(async () => { const f = new FormData(); f.append('nom', 'X'); return (await fetch('/caisse/clients', { method: 'POST', body: f })).status; });
  v(sansJeton === 419, 'création sans jeton : 419');

  // 11. Téléphone : fenêtre d'ardoise sans défilement horizontal
  const tel = await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
  const tp = await connecter(tel, 'admin@demo.test', MDP); tp.on('pageerror', x => err.push(x.message));
  await tp.goto(B + '/caisse?e=comptoir-du-lac'); await tp.evaluate(() => sessionStorage.clear()); await tp.reload();
  await ajouter(tp, ['Kivu Sunset']); await tp.click('#resume'); await tp.waitForTimeout(300); await tp.click('.paiements [data-ardoise]');
  await tp.fill('#ardoise-recherche', 'Testo K'); await tp.locator('.ardoise-client', { hasText: 'Testo Kasongo' }).click(); await tp.waitForTimeout(200);
  v(await tp.evaluate(() => { const d = document.getElementById('paiement'); return d.scrollWidth <= d.clientWidth + 1 && document.documentElement.scrollWidth <= innerWidth; }), 'téléphone : ardoise sans défilement horizontal');
  await tp.screenshot({ path: __dirname + '/ardoise-tel.png', fullPage: false });
  await tp.locator('#paiement').evaluate(e => e.scrollTo(0, 9999)); await tp.screenshot({ path: __dirname + '/ardoise-tel-bas.png' });
  await p.goto(B + '/caisse?e=comptoir-du-lac'); await ajouter(p, ['Kivu Sunset']); await p.click('#encaisser'); await p.fill('#recu-CDF', '5000'); await p.click('#ajouter-ardoise');
  await chercherClient(p, 'Testo K', 'Testo Kasongo'); await p.selectOption('#ardoise-garant', '2');
  await p.screenshot({ path: __dirname + '/ardoise-ordi.png' });
  await p.click('[data-fermer-paiement]'); await p.click('#ardoises'); await p.fill('#ardoises-recherche', 'Testo K'); await p.locator('.ardoise-client', { hasText: 'Testo Kasongo' }).click(); await p.waitForTimeout(300);
  await p.screenshot({ path: __dirname + '/ardoises-ordi.png' });
  v(err.length === 0, 'aucune erreur JS ' + err.join(' | '));
  console.log(`\n${nb - ko.length}/${nb}`); await b.close();
})();
