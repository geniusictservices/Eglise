// Mode hors ligne, appareil personnel (compte de gestion) : cache, ventes en attente, reçus provisoires, envoi,
// trésorerie, écart de prix, double envoi, session expirée (419), heure bornée, signature, back-office, FR/EN, téléphone
const { chromium } = require('playwright');
const c = require('./commun');
const { v, sql } = c;
const CAP = c.S;

async function attendreFileVide(p, ms = 40000) {
  const fin = Date.now() + ms;
  while (Date.now() < fin) {
    const f = await c.fileHl(p).catch(() => null);
    if (f && f.length === 0) { return true; }
    await p.waitForTimeout(500);
  }
  return false;
}
async function attendreUrlStable(p) { await p.waitForLoadState('load'); await p.waitForSelector('#caisse'); await p.waitForTimeout(600); }
const nbVentes = () => +sql('SELECT COUNT(*) FROM ventes');

(async () => {
  c.restaurer();
  const b = await chromium.launch();
  const err = [];
  const ctx = await b.newContext({ viewport: { width: 1366, height: 860 } });
  const p = await c.connecter(ctx, 'admin@demo.test', c.MDP, err);
  await p.goto(c.B + '/caisse?e=comptoir-du-lac');
  await p.evaluate(() => sessionStorage.clear());
  v(await c.swPret(p), 'service worker actif, il contrôle la caisse (portée /caisse)');
  await p.reload(); await attendreUrlStable(p);
  await p.waitForTimeout(1200);
  v((await p.textContent('#hl-etat-texte')).trim() === 'En ligne' && await p.isVisible('#hl-etat'), 'indicateur : « En ligne »');
  const scope = await p.evaluate(() => navigator.serviceWorker.getRegistration().then(r => r.scope));
  v(scope === c.B + '/caisse', 'portée du service worker : /caisse (' + scope + ')');
  const pages = await p.evaluate(async () => { const r = []; for (const n of await caches.keys()) { if (n.indexOf('pages') > 0) { for (const k of await (await caches.open(n)).keys()) { r.push(k.url.replace(location.origin, '')); } } } return r; });
  v(pages.length === 1 && pages[0] === '/caisse/__hl/page?e=comptoir-du-lac&zone=&p=1', 'une seule page gardée : la caisse de cette personne (' + pages.join(', ') + ')');
  const cles = await p.evaluate(() => window.RbpHorsLigne.tous('cles').then(l => l.map(x => x.uid)));
  v(cles.length === 1 && cles[0] === 1, 'clé hors ligne de la personne reçue (signature des ventes)');
  v(sql('SELECT COUNT(*) FROM caisse_cles_hors_ligne WHERE utilisateur_id = 1 AND appareil_id IS NULL AND revoquee_le IS NULL') === '1', 'clé hors ligne enregistrée côté serveur (appareil personnel)');
  // La gestion n'est jamais gardée
  await p.goto(c.B + '/gestion/ventes');
  v(!(await p.evaluate(() => !!navigator.serviceWorker.controller)), 'la gestion n\'est pas sous le service worker');
  await p.goto(c.B + '/caisse?e=comptoir-du-lac'); await attendreUrlStable(p);

  // ---------- Hors ligne ----------
  await ctx.setOffline(true);
  await p.reload(); await attendreUrlStable(p);
  v(await p.evaluate(() => !!document.querySelector('meta[name="rbp-hors-ligne"]')), 'sans réseau : la caisse se recharge depuis l\'appareil');
  await p.waitForTimeout(600);
  v((await p.textContent('#hl-etat-texte')).trim() === 'Hors ligne' && (await p.getAttribute('#hl-etat', 'data-etat')) === 'hors-ligne', 'indicateur : « Hors ligne »');
  v(await p.isVisible('#hl-note') && (await p.textContent('#hl-note')).includes('réseau'), 'explication courte dans le ticket');
  for (const sel of ['#ardoises', '#salle']) {
    if (await p.locator(sel).count()) { v(await p.locator(sel).isDisabled() && (await p.getAttribute(sel, 'title')) === 'Réseau nécessaire', sel + ' grisé sans réseau (« Réseau nécessaire »)'); }
  }
  await c.ajouter(p, ['Primus|Grande']);
  v(await p.locator('.paiements [data-ardoise]').isDisabled(), 'raccourci « Crédit client » grisé');
  v(!(await p.locator('.paiements [data-compte]').first().isDisabled()), 'raccourcis des comptes actifs');
  const avant = nbVentes();
  // 1. FC, montant exact
  await c.ajouter(p, ['Kivu Sunset']);
  await p.click('#encaisser'); await p.waitForSelector('#paiement[open]');
  if (await p.locator('#ajouter-ardoise').count()) { v(await p.locator('#ajouter-ardoise').isDisabled(), 'fenêtre de paiement : « Mettre à l\'ardoise » grisé'); }
  if (await p.locator('#paiement-fidelite').count()) { v(await p.locator('#paiement-fidelite').isDisabled(), 'fenêtre de paiement : fidélité grisée'); }
  const t0 = Date.now();
  await p.click('#paiement-valider'); await p.waitForSelector('#paiement-fin:not([hidden])');
  v(Date.now() - t0 < 3000, 'vente sans réseau en moins de 3 s (' + (Date.now() - t0) + ' ms)');
  let recu = await p.textContent('#paiement-recu');
  // Numéro provisoire d'un appareil personnel : HL-P<personne>-<marque du navigateur>-<n>
  const TAG = await p.evaluate(() => window.RbpHorsLigne.etat('marque'));
  const num = k => 'HL-P1-' + TAG + '-000' + k;
  v(/^[A-Z0-9]{4}$/.test(TAG || ''), 'marque du navigateur dans le numéro provisoire : ' + TAG);
  v(/En attente d.envoi/.test(recu) && recu.includes(num(1)) && recu.replace(/\s/g, '').includes('28300'), 'reçu provisoire : « En attente d\'envoi », HL-P1-0001, 28 300 FC');
  await p.screenshot({ path: CAP + 'ordi-recu-provisoire.png' });
  // Imprimer le reçu provisoire (80 mm) : seul le reçu, avec « En attente d'envoi »
  await p.evaluate(() => { window.print = () => {}; });
  await p.click('#paiement-imprimer');
  await p.emulateMedia({ media: 'print' });
  v(await p.locator('#zone-impression .recu--provisoire').isVisible() && !(await p.locator('#grille').isVisible()), 'impression : seulement le reçu provisoire');
  await p.pdf({ path: CAP + 'recu-provisoire-80mm.pdf', width: '80mm' });
  await p.emulateMedia({ media: 'screen' });
  await p.click('#paiement-suivant');
  v((await p.textContent('#hl-etat-nombre')).trim() === '1', 'indicateur : 1 vente en attente');
  v(await p.locator('#lignes .ligne').count() === 0, 'le ticket est vidé, la caisse continue');
  // 2. En dollars
  await c.ajouter(p, ['Primus|Grande', 'Primus|Grande']);
  await c.payer(p, { devise: 'USD' });
  recu = await p.textContent('#paiement-recu');
  v(recu.includes(num(2)) && /\$/.test(recu), 'vente payée en $ : reçu provisoire HL-P1-0002');
  await p.click('#paiement-suivant');
  // 3. Avec monnaie : 50 000 FC pour 33 800 FC
  await c.ajouter(p, ['Primus|Grande', 'Primus|Grande', 'Kivu Sunset']);
  await p.click('#encaisser'); await p.waitForSelector('#paiement[open]');
  await p.fill('#recu-CDF', '50000'); await p.dispatchEvent('#recu-CDF', 'input');
  v((await p.textContent('#paiement-bilan')).replace(/\s/g, '').includes('16200'), 'monnaie à rendre : 16 200 FC');
  await p.click('#paiement-valider'); await p.waitForSelector('#paiement-fin:not([hidden])');
  recu = await p.textContent('#paiement-recu');
  v(recu.includes(num(3)) && recu.replace(/\s/g, '').includes('16200'), 'reçu provisoire avec la monnaie rendue (16 200 FC)');
  await p.click('#paiement-suivant');
  v((await p.textContent('#hl-etat-nombre')).trim() === '3', 'indicateur : 3 ventes en attente');
  v(nbVentes() === avant, 'rien n\'est arrivé au serveur pendant la coupure');
  const file = await c.fileHl(p);
  v(file.length === 3 && file.every(i => i.signature && /^[0-9a-f]{64}$/.test(i.signature)), 'file : 3 ventes signées (HMAC)');
  const envoi1 = JSON.parse(file.find(i => i.numero === num(1)).texte);
  v(envoi1.hors_ligne && envoi1.hors_ligne.vendeur === 1 && /^\d{4}-\d\d-\d\dT/.test(envoi1.hors_ligne.heure) && envoi1.cle && envoi1.lignes.length === 2,
    'vente en attente : même texte que l\'encaissement, avec sa clé, qui a vendu et l\'heure de l\'appareil');
  // Panneau des ventes en attente
  await p.click('#hl-etat'); await p.waitForSelector('#hl-fenetre[open]');
  v(await p.locator('#hl-liste .hl-vente').count() === 3, 'fenêtre : les 3 ventes en attente');
  await p.screenshot({ path: CAP + 'ordi-attente.png' });
  await p.click('[data-fermer-hl]');
  // Le prix du Kivu Sunset change pendant la coupure (8 $ → 9 $) : écart sur les ventes 1 et 3
  sql('UPDATE prix SET montant = 9.00 WHERE id = 10');

  // ---------- Retour du réseau ----------
  const debutEnvoi = sql('SELECT NOW()');
  await ctx.setOffline(false);
  v(await attendreFileVide(p), 'retour du réseau : la file se vide toute seule');
  await p.waitForTimeout(2500);
  await attendreUrlStable(p);
  v(!(await p.evaluate(() => !!document.querySelector('meta[name="rbp-hors-ligne"]'))), 'la caisse servie depuis l\'appareil s\'est rechargée depuis le serveur');
  const ventes = sql("SELECT id, numero_provisoire, utilisateur_id, hors_ligne, cree_le, heure_appareil, synchronisee_le, COALESCE(ecart_hors_ligne, ''), COALESCE(notes_hors_ligne, ''), total, rendu FROM ventes WHERE hors_ligne = 1 ORDER BY id").split('\n').map(l => l.split('\t'));
  v(ventes.length === 3 && ventes.map(x => x[1]).join(',') === [num(1), num(2), num(3)].join(','), 'exactement une vente par ticket, dans l\'ordre');
  v(ventes.every(x => x[2] === '1' && x[4] === x[5]), 'vendeur (admin) et heure de la vente = heure de l\'appareil');
  v(ventes.every(x => x[6] >= debutEnvoi && x[4] <= debutEnvoi), 'heure de la vente avant la coupure, reçue au retour du réseau');
  const [v1, v2, v3] = ventes;
  v(+v1[7] < 0 && Math.abs(+v1[7] - (28300 - +v1[9])) < 0.001 && v1[8] === 'prix' && +v1[9] > 28300, 'écart de prix gardé : ' + v1[7] + ' FC (Kivu Sunset passé à 9 $), total du serveur ' + v1[9] + ' FC');
  v(v2[7] === '' && v2[8] === '', 'vente en $ sans écart');
  v(v3[10] === '16200.000', 'monnaie rendue gardée : 16 200 FC (celle de la caisse)');
  const mv = sql(`SELECT m.sens, m.montant, m.date_operation = v.cree_le FROM mouvements_tresorerie m JOIN ventes v ON v.id = m.source_id WHERE m.nature = 'vente' AND v.id = ${v3[0]} ORDER BY m.id`).split('\n');
  v(mv.length === 2 && mv[0] === 'entree\t50000.000\t1' && mv[1] === 'sortie\t16200.000\t1', 'trésorerie : entrée 50 000 FC et sortie de la monnaie 16 200 FC, à l\'heure de la vente');
  const pay2 = sql(`SELECT d.code, p.recu, c.type FROM vente_paiements p JOIN devises d ON d.id = p.devise_id JOIN comptes_tresorerie c ON c.id = p.compte_id WHERE p.vente_id = ${v2[0]}`);
  v(/^USD\t\d/.test(pay2) && pay2.endsWith('caisse'), 'paiement en $ dans une caisse : ' + pay2.replace(/\t/g, ' '));
  const j1 = sql(`SELECT details FROM journal WHERE action = 'vente_hors_ligne' ORDER BY id LIMIT 1`);
  v(j1.includes('"provisoire":"' + num(1) + '"') && j1.includes('"ecart":-') && j1.includes('"notes":["prix"]'), 'journal : vente hors ligne, reçu provisoire, écart et ce qui aurait été refusé');
  v(sql(`SELECT COUNT(*) FROM stock_mouvements WHERE source_id IN (${ventes.map(x => x[0]).join(',')}) AND type IN ('vente','recette')`) > 0, 'sorties de stock des ventes envoyées');

  // Double envoi : même clé, même texte
  const r = await p.request.post(c.B + '/caisse/encaisser', { form: { csrf: (await c.donnees(p)).csrf, vente: file[0].texte, signature: file[0].signature } });
  const jr = await r.json();
  v(r.status() === 200 && jr.deja === true && jr.numero === sql(`SELECT numero FROM ventes WHERE cle = '${file[0].cle}'`), 'renvoi de la même vente : « déjà reçue », même numéro');
  await p.evaluate(item => window.RbpHorsLigne.ecrire('file', item), Object.assign({}, file[1], { statut: 'attente' }));
  await p.evaluate(() => window.RbpCaisseHorsLigne.synchroniser(true));
  await attendreFileVide(p, 10000);
  v(sql('SELECT COUNT(*) FROM ventes WHERE hors_ligne = 1') === '3', 'vente remise dans la file et renvoyée : toujours 3 ventes');

  // ---------- Back-office ----------
  await p.goto(c.B + '/gestion/ventes?hors_ligne=1');
  const lignes = await p.locator('.ven-ligne').count();
  v(lignes === 3 && await p.locator('.ven-ligne .badge:text-is("Hors ligne")').count() === 3, 'Ventes : filtre « Ventes hors ligne » et badge sur les 3 tickets');
  v(await p.locator('.ven-ligne .badge', { hasText: 'Écart' }).count() === 2, 'Ventes : écart de prix montré au gérant');
  await p.screenshot({ path: CAP + 'gestion-ventes-hors-ligne.png', fullPage: true });
  await p.goto(c.B + '/gestion/ventes/' + v1[0]);
  const fiche = await p.textContent('#ven-hl-titre').catch(() => '');
  v(fiche.includes('sans réseau') && (await p.textContent('main')).includes(num(1)) && (await p.textContent('main')).includes('Prix différents'), 'fiche : vente faite sans réseau, reçu provisoire, écart, prix différents');
  await p.screenshot({ path: CAP + 'gestion-fiche-hors-ligne.png', fullPage: true });
  await p.goto(c.B + '/gestion/utilisateurs/appareils');
  v((await p.textContent('main')).includes('Sans réseau'), 'Paramètres > Appareils : note sur le mode hors ligne');

  // ---------- Session expirée (419) ----------
  await p.goto(c.B + '/caisse?e=comptoir-du-lac'); await attendreUrlStable(p);
  await ctx.setOffline(true);
  await c.ajouter(p, ['Primus|Grande']);
  await c.payer(p);
  await p.click('#paiement-suivant');
  // La session PHP disparaît (expirée) ; l'appareil garde sa file
  const cookies = (await ctx.cookies()).filter(k => k.name !== 'rbp_session');
  await ctx.clearCookies(); await ctx.addCookies(cookies);
  const avant419 = sql('SELECT COUNT(*) FROM ventes');
  await ctx.setOffline(false);
  await p.waitForTimeout(8000);
  // La caisse demande de se reconnecter : bandeau, ou (la salle voit la session perdue) la page de connexion
  const surCaisse = await p.locator('#hl-bandeau').count() > 0;
  v(surCaisse ? (await p.isVisible('#hl-bandeau') && (await p.textContent('#hl-bandeau')).includes('reconnectez-vous')) : p.url().includes('/gestion/connexion'),
    'session expirée : la caisse demande de se reconnecter (' + (surCaisse ? 'bandeau' : 'page de connexion') + ')');
  v(sql('SELECT COUNT(*) FROM ventes') === avant419, 'envoi refusé (419) : rien d\'enregistré, la vente reste sur l\'appareil');
  const enAttente = await p.evaluate(() => new Promise(ok => { const r = indexedDB.open('rbp-caisse'); r.onsuccess = () => { const q = r.result.transaction('file').objectStore('file').getAll(); q.onsuccess = () => ok(q.result.map(i => i.statut)); }; }));
  v(enAttente.length === 1 && enAttente[0] === 'connexion', 'file : la vente attend, « session à refaire »');
  await p.screenshot({ path: CAP + 'ordi-session-expiree.png' });
  const p2 = await c.connecter(ctx, 'admin@demo.test', c.MDP, err);
  await p2.goto(c.B + '/caisse?e=comptoir-du-lac'); await attendreUrlStable(p2);
  v(await attendreFileVide(p2, 20000), 'après la reconnexion, la vente part');
  v(sql('SELECT COUNT(*) FROM ventes WHERE hors_ligne = 1') === '4', '4e vente hors ligne enregistrée une fois');
  await p.close();

  // ---------- Heure bornée, signature (API) ----------
  const D = await c.donnees(p2);
  const fmt = (await p2.evaluate(() => JSON.parse(document.getElementById('donnees-caisse').textContent).produits.find(x => x.nom === 'Primus').formats.find(f => f.taille.indexOf('Grande') === 0)));
  const caisse = 1;
  const envoiApi = async (h, extra = {}) => {
    const vente = { cle: require('crypto').randomUUID(), etab: 'comptoir-du-lac', zone: '', poste: 'caisse', moi: 1,
      lignes: [{ id: fmt.id, qte: 1, pu: fmt.prix, nom: 'Primus Grande' }], paiements: [{ devise: 'CDF', compte: caisse, recu: fmt.prix.CDF }], rendu: 'CDF',
      hors_ligne: Object.assign({ vendeur: 1, numero: 'HL-P1-0099', total: { CDF: fmt.prix.CDF }, rendu: { devise: 'CDF', montant: 0 } }, h), ...extra };
    const rr = await p2.request.post(c.B + '/caisse/encaisser', { form: { csrf: D.csrf, vente: JSON.stringify(vente) } });
    let j = {}; try { j = await rr.json(); } catch (e) { /* */ }
    return { s: rr.status(), j, cle: vente.cle };
  };
  let a = await envoiApi({ heure: new Date(Date.now() - 4 * 86400000).toISOString() });
  v(a.s === 200 && a.j.notes.includes('heure') && sql(`SELECT TIMESTAMPDIFF(MINUTE, cree_le, NOW()) < 2 FROM ventes WHERE cle = '${a.cle}'`) === '1', 'heure de plus de 72 h : enregistrée à l\'arrivée, notée « heure »');
  a = await envoiApi({ heure: new Date(Date.now() + 86400000).toISOString() });
  v(a.s === 200 && a.j.notes.includes('heure') && sql(`SELECT cree_le <= NOW() FROM ventes WHERE cle = '${a.cle}'`) === '1', 'heure dans le futur : jamais dans le futur, notée');
  a = await envoiApi({ heure: new Date(Date.now() - 3600000).toISOString(), vendeur: 5 });
  v(a.s === 403 && a.j.code === 'signature', 'vente d\'une autre personne sans signature : refusée (403 signature), elle reste sur l\'appareil');
  a = await envoiApi({ heure: new Date(Date.now() - 3600000).toISOString() }, { credit: { client: 1, devise: 'CDF', montant: 1 } });
  v(a.s === 422 && a.j.code === 'hors_ligne_reseau', 'une ardoise n\'est jamais acceptée hors ligne (réseau requis)');
  a = await envoiApi({ heure: new Date(Date.now() - 2 * 3600000).toISOString() });
  v(a.s === 200 && sql(`SELECT TIMESTAMPDIFF(MINUTE, cree_le, NOW()) BETWEEN 119 AND 121 FROM ventes WHERE cle = '${a.cle}'`) === '1', 'heure d\'il y a 2 h : la vente est datée de 2 h');
  // Vente en ligne au nom d'une autre personne (page restée ouverte) : refusée
  const rr = await p2.request.post(c.B + '/caisse/encaisser', { form: { csrf: D.csrf, vente: JSON.stringify({ cle: require('crypto').randomUUID(), etab: 'comptoir-du-lac', poste: 'caisse', moi: 5, lignes: [{ id: fmt.id, qte: 1 }], paiements: [{ devise: 'CDF', compte: 1, recu: fmt.prix.CDF }] }) } });
  v(rr.status() === 409, 'en ligne, une page d\'une autre personne ne vend pas sous ce nom (409)');
  await p2.close();

  // ---------- Anglais et téléphone ----------
  const ctxEn = await b.newContext({ viewport: { width: 390, height: 844 }, locale: 'en-GB', isMobile: true, hasTouch: true });
  const pe = await c.connecter(ctxEn, 'admin@demo.test', c.MDP, err);
  await pe.goto(c.B + '/caisse?e=comptoir-du-lac&lang=en'); await c.swPret(pe); await pe.reload(); await attendreUrlStable(pe);
  await pe.waitForTimeout(1000);
  v((await pe.getAttribute('#hl-etat', 'aria-label')).startsWith('Online'), 'anglais : « Online »');
  await ctxEn.setOffline(true);
  await pe.reload(); await attendreUrlStable(pe);
  await pe.waitForTimeout(600);
  v((await pe.textContent('#hl-etat-texte')).trim() === 'Offline', 'anglais : « Offline »');
  v(await pe.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'téléphone : pas de défilement horizontal avec l\'indicateur');
  const box = await pe.locator('#hl-etat').boundingBox();
  v(box && box.height >= 44 && box.width >= 44, 'téléphone : indicateur de 44 px au moins (' + Math.round(box.width) + '×' + Math.round(box.height) + ')');
  await pe.screenshot({ path: CAP + 'tel-hors-ligne.png' });
  await c.ajouter(pe, ['Primus|72']);
  await pe.click('#resume'); await pe.waitForTimeout(300);
  await pe.screenshot({ path: CAP + 'tel-ticket-hors-ligne.png' });
  await c.payer(pe);
  recu = await pe.textContent('#paiement-recu');
  v(recu.includes('Waiting to be sent') && recu.includes('Provisional receipt'), 'anglais : reçu provisoire « Waiting to be sent »');
  await pe.screenshot({ path: CAP + 'tel-recu-provisoire-en.png' });
  await pe.click('#paiement-suivant');
  await pe.click('#hl-etat'); await pe.waitForSelector('#hl-fenetre[open]');
  v(await pe.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'téléphone : fenêtre des ventes en attente sans défilement horizontal');
  await pe.screenshot({ path: CAP + 'tel-attente-en.png' });
  await pe.click('[data-fermer-hl]');
  await ctxEn.setOffline(false);
  v(await attendreFileVide(pe), 'anglais, téléphone : la vente part au retour du réseau');

  v(err.length === 0, 'aucune erreur JavaScript' + (err.length ? ' : ' + err.join(' | ') : ''));
  c.bilan();
  await b.close();
})().catch(e => { console.error(e); c.bilan(); process.exit(1); });
