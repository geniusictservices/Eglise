// Tests de bout en bout : Stock et Achats, sur l'instance essaistock (base rbp_integ, jamais rbp_integ).
// La base est remise à l'état « après la démonstration » (apres-demo.sql) au début.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');

const B = 'http://integ.localhost:8250';
const DIR = __dirname;
const DB = 'rbp_integ';
const ADMIN = ['admin@demo.test', 'Essai-Integ-2026'];
const PATRICK = ['patrick@demo.test', 'Gerant-Goma-2026'];
const GRACE = ['grace@demo.test', 'Compta-Goma-2026'];
const RIZIKI = ['riziki@demo.test', 'Superviseur-2026'];
const MAG = ['magasin@essai.test', 'Magasin-Goma-2026'];

let ok = 0, ko = 0;
const echecs = [];
function verifier(cond, nom, detail) {
  if (cond) { ok++; } else { ko++; echecs.push(nom + (detail !== undefined ? ' — ' + JSON.stringify(detail) : '')); console.log('ÉCHEC', nom, detail ?? ''); }
}
function sql(q) {
  return execSync(`mysql --default-character-set=utf8mb4 -u root -N -B ${DB} -e ${JSON.stringify("SET time_zone = '+02:00'; " + q.replace(/\s+/g, ' '))}`, { encoding: 'utf8' })
    .trim().split('\n').filter(l => l !== '').map(l => l.split('\t'));
}
const un = (q) => sql(q)[0]?.[0];
const num = (q) => parseFloat(un(q) ?? '0');
const proche = (a, b, tol = 0.006) => Math.abs(a - b) < tol;
const outil = (action, p = {}) => execSync(`php ${DIR}/outil.php ${action} '${JSON.stringify(p)}'`, { encoding: 'utf8' }).trim();
const niveau = (lieu, produit) => num(`select coalesce((select quantite from stock_niveaux where lieu_id=${lieu} and produit_id=${produit}),0)`);
const somme = (lieu, produit) => num(`select coalesce(sum(quantite),0) from stock_mouvements where lieu_id=${lieu} and produit_id=${produit}`);
const lieu = (nom) => +un(`select id from lieux_stock where nom='${nom}'`);
const produit = (nom) => +un(`select id from produits where nom_fr='${nom}' order by id limit 1`);
const format = (p, nom) => +un(`select id from conditionnements where produit_id=${p} and nom_fr='${nom}'`);
const solde = (compte, devise) => num(`select coalesce(sum(if(sens='entree',montant,-montant)),0) from mouvements_tresorerie where compte_id=${compte} and devise_id=${devise}`);

async function connecter(navigateur, [email, mdp], options = {}) {
  const contexte = await navigateur.newContext({ viewport: { width: 1366, height: 860 }, ...options });
  const page = await contexte.newPage();
  page.erreurs = [];
  page.on('pageerror', e => page.erreurs.push(String(e)));
  page.on('console', m => { if (m.type() === 'error' && !m.text().startsWith('Failed to load resource')) page.erreurs.push(m.text()); });
  page.on('response', rep => { if (rep.request().resourceType() !== 'document' && rep.status() >= 400) page.erreurs.push(rep.status() + ' ' + rep.url()); });
  await page.goto(B + '/gestion/connexion?lang=fr');
  await page.fill('#email', email);
  await page.fill('#mot_de_passe', mdp);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  return page;
}
async function jeton(page) {
  if (!page.url().startsWith(B + '/gestion') || await page.locator('input[name=csrf]').count() === 0) await page.goto(B + '/gestion/compte');
  return page.locator('input[name=csrf]').first().getAttribute('value');
}
/** POST d'un formulaire (sans suivre la redirection) avec le jeton de la page. */
async function poster(page, url, donnees = {}, avecJeton = true) {
  const form = { ...donnees };
  if (avecJeton) form.csrf = await jeton(page);
  return page.request.post(B + url, { form, maxRedirects: 0 });
}
async function debordement(page) {
  return page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
}
async function clesBrutes(page) {
  const texte = await page.evaluate(() => document.body.innerText);
  return (texte.match(/\b(stock|achats|emballages|perm|ecran|module|tresorerie|depenses|gventes)\.[a-z_]{3,}/g) || []);
}
/** Cibles tactiles trop petites dans la page (liens, boutons, champs visibles de main). */
async function petitesCibles(page) {
  return page.evaluate(() => Array.from(document.querySelectorAll('main a, main button, main select, main input:not([type=hidden]):not([type=checkbox]):not([type=radio]), main summary'))
    .filter(el => { const r = el.getBoundingClientRect(); const s = getComputedStyle(el); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && !el.closest('.visuellement-cache') && el.getAttribute('aria-hidden') !== 'true'; })
    .filter(el => { const r = el.getBoundingClientRect(); return r.height < 43.5 && !(el.tagName === 'A' && el.closest('td, li, p, .tre-sous-texte, h1, h2')); })
    .map(el => (el.tagName + ' ' + (el.textContent || el.name || '').trim().slice(0, 30) + ' ' + Math.round(el.getBoundingClientRect().height))));
}

(async () => {
  // ---------- Base de départ ----------
  execSync('/home/user/Eglise/restau-bar-pos/outils/tests/restaurer.sh');
  const magId = +outil('utilisateur', { nom: 'Baraka Magasin', email: MAG[0], mdp: MAG[1], role: 'magasinier', etab: 1 });

  const L = { bar: lieu('Bar'), reserve: lieu('Réserve'), froid: lieu('Chambre froide'), cuisine: lieu('Cuisine'), vcReserve: lieu('Réserve du club'),
    vcBar: lieu('Bar principal'), vip: lieu('Bar VIP'), vcCuisine: lieu('Cuisine du club'), central: lieu('Dépôt central Goma') };
  const P = { pg: produit('Primus Grande'), pp: produit('Primus Petite'), mg: produit('Mützig Grande'), coca: produit('Coca-Cola'), eau: produit('Eau minérale'),
    heineken: produit('Heineken'), vin: produit('Vin rouge de la maison'), jw: produit('Johnnie Walker Black Label'), mojito: produit('Mojito'), frites: produit('Frites'),
    rhum: produit('Rhum blanc'), menthe: produit('Menthe fraîche'), citron: produit('Citron vert'), sucre: produit('Sucre de canne'), gazeuse: produit('Eau gazeuse'),
    pdt: produit('Pommes de terre'), huile: produit('Huile végétale'), vide72: produit('Bouteille vide 72 cl'), vide33: produit('Bouteille vide 33 cl'), moet: produit('Moët & Chandon Brut') };
  const C = { pgBouteille: format(P.pg, 'Bouteille'), pgCasier: format(P.pg, 'Casier'), ppBouteille: format(P.pp, 'Bouteille'), coca: format(P.coca, 'Canette'),
    cocaPack: format(P.coca, 'Pack'), vinBouteille: format(P.vin, 'Bouteille 75 cl'), vinVerre: format(P.vin, 'Verre 15 cl'), jwDose: format(P.jw, 'Dose 4 cl'),
    jwBouteille: format(P.jw, 'Bouteille 70 cl'), mojito: format(P.mojito, 'Verre'), frites: format(P.frites, 'Portion'), heineken: format(P.heineken, 'Bouteille') };
  const navigateur = await chromium.launch();

  // ---------- 1. Les sorties des ventes de démonstration sont dans le journal ----------
  verifier(+un(`select count(*) from stock_mouvements where type in ('vente','recette')`) > 3000, 'démo : sorties de vente rejouées');
  verifier(+un(`select count(*) from stock_niveaux n where abs(n.quantite - (select coalesce(sum(m.quantite),0) from stock_mouvements m where m.lieu_id=n.lieu_id and m.produit_id=n.produit_id)) > 0.001`) === 0, 'stock = somme du journal');
  verifier(+un(`select count(*) from inventaires where statut='valide'`) === 1 && +un(`select count(*) from inventaires where statut='compte'`) === 1, 'démo : un inventaire validé, un à valider');
  verifier(+un(`select count(*) from transferts_stock where statut='expedie'`) === 1, 'démo : un transfert en route');
  verifier(+un(`select count(*) from receptions`) >= 20, 'démo : réceptions');
  // bin/demo-stock-suite.php ajoute 2 fiches de préparations (sauce pili-pili, marinade) aux 8 de bin/demo-stock.php
  verifier(+un(`select count(*) from recettes r join produits p on p.id=r.produit_id join categories c on c.id=p.categorie_id where c.nom_fr<>'Préparations'`) === 8, 'démo : 8 fiches techniques (hors préparations)');

  // ---------- 2. Vente → stock (unités de base, tailles, fiches, routage, vides) ----------
  {
    const avant = { pg: niveau(L.bar, P.pg), pp: niveau(L.bar, P.pp), vin: niveau(L.bar, P.vin), rhum: niveau(L.bar, P.rhum), menthe: niveau(L.bar, P.menthe),
      pdt: niveau(L.cuisine, P.pdt), huile: niveau(L.cuisine, P.huile), vide72: niveau(L.bar, P.vide72), mojito: niveau(L.bar, P.mojito) };
    const vente = +outil('vente', { etab: 1, zone: 1, lignes: [[C.pgBouteille, 3], [C.ppBouteille, 2], [C.vinBouteille, 1], [C.vinVerre, 2], [C.mojito, 2], [C.frites, 1]] });
    verifier(vente > 0, 'vente enregistrée');
    verifier(proche(niveau(L.bar, P.pg), avant.pg - 3), 'Primus Grande −3 bouteilles au Bar', [avant.pg, niveau(L.bar, P.pg)]);
    verifier(proche(niveau(L.bar, P.pp), avant.pp - 2), 'taille Primus Petite : son propre stock −2');
    verifier(proche(niveau(L.bar, P.vin), avant.vin - 75 - 30), 'vin : 1 bouteille 75 cl + 2 verres 15 cl = −105 cl');
    verifier(proche(niveau(L.bar, P.rhum), avant.rhum - 10) && proche(niveau(L.bar, P.menthe), avant.menthe - 0.2), 'Mojito ×2 : rhum −10 cl, menthe −0,2 botte');
    verifier(proche(niveau(L.bar, P.mojito), avant.mojito), 'Mojito : pas de stock propre (fiche technique)');
    verifier(proche(niveau(L.cuisine, P.pdt), avant.pdt - 0.3 / 0.85, 0.002) && proche(niveau(L.cuisine, P.huile), avant.huile - 0.05), 'Frites routées vers la Cuisine : pommes de terre à 85 %, huile');
    verifier(proche(niveau(L.bar, P.vide72), avant.vide72 + 3), '3 bouteilles vides 72 cl récupérées au Bar');
    verifier(+un(`select count(*) from stock_mouvements where source_id=${vente} and type in ('vente','recette','vide_recupere')`) >= 9, 'mouvements de la vente');
    // Zone VIP → Bar VIP
    const vipAvant = niveau(L.vip, P.heineken);
    outil('vente', { etab: 2, zone: +un(`select id from zones where code='vip'`), lignes: [[C.heineken, 2]] });
    verifier(proche(niveau(L.vip, P.heineken), vipAvant - 2), 'zone Carré VIP → Bar VIP');
    // Annulation dans la gestion : le stock revient
    const admin = await connecter(navigateur, ADMIN);
    await admin.goto(B + '/gestion/ventes/' + vente);
    const r = await poster(admin, `/gestion/ventes/${vente}/annuler`, { motif: 'Test stock', confirme: '1' });
    verifier(r.status() === 302, 'annulation de la vente', r.status());
    verifier(proche(niveau(L.bar, P.pg), avant.pg) && proche(niveau(L.bar, P.rhum), avant.rhum) && proche(niveau(L.cuisine, P.pdt), avant.pdt, 0.002) && proche(niveau(L.bar, P.vide72), avant.vide72), 'annulation : tout revient');
    verifier(+un(`select count(*) from stock_mouvements where source_id=${vente} and type='annulation_vente'`) >= 9, 'mouvements d\'annulation');
    // Établissement sans lieu ni stock : la vente passe, comptoir « Bar » créé, stock négatif
    const vide = JSON.parse(outil('vide'));
    verifier(vide.vente > 0 && vide.lieux === 1 && vide.nom === 'Bar' && parseFloat(vide.quantite) === -2, 'établissement vide : vente sans blocage, stock à −2', vide);
    await admin.context().close();
  }

  // ---------- 3. Mode « bloquer » : épuisés à la caisse ----------
  {
    const patrick = await connecter(navigateur, PATRICK);
    verifier(outil('epuises', { etab: 1 }) === '[]', 'mode vendre : aucun épuisé');
    await patrick.goto(B + '/gestion/stock/lieux');
    let r = await poster(patrick, '/gestion/stock/lieux/reglages/1', { mode_negatif: 'bloquer', validation_separee: '1' });
    verifier(r.status() === 302 && un('select mode_negatif from stock_parametres where etablissement_id=1') === 'bloquer', 'réglage bloquer (Comptoir du Lac)');
    r = await poster(patrick, '/gestion/stock/lieux/reglages/2', { mode_negatif: 'bloquer' });
    verifier(r.status() === 403, 'réglage d\'un autre établissement refusé (portée)', r.status());
    // Coca à 0 au Bar (ajustement « nouveau stock »), menthe à 0
    r = await poster(patrick, '/gestion/stock/ajuster', { lieu_id: L.bar, conditionnement_id: C.coca, sens: 'compter', quantite: '0', type: 'ajustement', motif: 'Test épuisé' });
    verifier(r.status() === 302 && proche(niveau(L.bar, P.coca), 0), 'ajustement : Coca à 0', r.status());
    const menthe = format(P.menthe, 'Botte');
    await poster(patrick, '/gestion/stock/ajuster', { lieu_id: L.bar, conditionnement_id: menthe, sens: 'compter', quantite: '0', type: 'ajustement', motif: 'Test épuisé' });
    let ep = JSON.parse(outil('epuises', { etab: 1 }));
    verifier(ep.includes(C.coca) && ep.includes(C.mojito) && !ep.includes(C.pgBouteille), 'bloquer : Coca et Mojito (menthe) épuisés, Primus disponible', ep);
    // Casier de 12 alors que 7 bouteilles : le format casier n'est pas vendable, mais la bouteille seule l'est
    await patrick.goto(B + '/gestion/stock?lieu=' + L.bar);
    verifier(await patrick.locator('text=passe « épuisé » à la caisse').count() === 1, 'note du mode bloquer dans l\'état du stock');
    // La vente passe quand même côté serveur (la caisse bloque), le stock devient négatif
    outil('vente', { etab: 1, zone: 1, lignes: [[C.coca, 1]] });
    verifier(proche(niveau(L.bar, P.coca), -1), 'vente malgré tout : Coca −1');
    await poster(patrick, '/gestion/stock/lieux/reglages/1', { mode_negatif: 'vendre', validation_separee: '1' });
    verifier(outil('epuises', { etab: 1 }) === '[]', 'retour au mode vendre');
    await patrick.goto(B + '/gestion/stock?lieu=' + L.bar + '&alerte=negatif');
    verifier(await patrick.locator(`tr[data-produit="${P.coca}"][data-etat="negatif"]`).count() === 1, 'alerte stock négatif affichée');
    await patrick.context().close();
  }

  // ---------- 4. Droits et portée ----------
  {
    const riziki = await connecter(navigateur, RIZIKI);
    for (const u of ['/gestion/stock', '/gestion/stock/inventaires', '/gestion/achats/receptions', '/gestion/achats/fournisseurs']) {
      const r = await riziki.goto(B + u);
      verifier(r.status() === 403, 'sans stock/achats : 403 ' + u, r.status());
    }
    await riziki.goto(B + '/gestion');
    verifier(await riziki.locator('a[href="/gestion/stock"]').count() === 0, 'module Stock absent pour qui n\'a pas le droit');
    await riziki.context().close();

    const grace = await connecter(navigateur, GRACE);
    let r = await grace.goto(B + '/gestion/stock');
    verifier(r.status() === 200, 'comptable : voit le stock');
    verifier(await grace.locator('a[href^="/gestion/stock/ajuster"]').count() === 0, 'comptable : pas de bouton Ajuster');
    r = await grace.goto(B + '/gestion/stock/ajuster');
    verifier(r.status() === 403, 'comptable : ajuster interdit', r.status());
    r = await poster(grace, '/gestion/stock/pertes', { lieu_id: L.bar, conditionnement_id: C.pgBouteille, quantite: '1', motif: 'casse', commentaire: 'x' });
    verifier(r.status() === 403, 'comptable : déclarer une perte interdit', r.status());
    r = await grace.goto(B + '/gestion/achats/receptions/nouvelle');
    verifier(r.status() === 403, 'comptable : recevoir interdit', r.status());
    await grace.goto(B + '/gestion/achats/receptions');
    verifier(await grace.locator('a[href="/gestion/achats/receptions/nouvelle"]').count() === 0, 'comptable : pas de bouton Nouvelle réception');
    await grace.context().close();

    const patrick = await connecter(navigateur, PATRICK);
    await patrick.goto(B + '/gestion/stock');
    verifier(await patrick.locator(`.stk-lieux a[href$="lieu=${L.vcBar}"]`).count() === 0 && await patrick.locator(`.stk-lieux a[href$="lieu=${L.bar}"]`).count() === 1, 'portée : Patrick ne voit que le Comptoir du Lac (et le central)');
    r = await poster(patrick, '/gestion/stock/ajuster', { lieu_id: L.vcBar, conditionnement_id: C.pgBouteille, sens: 'ajouter', quantite: '5', type: 'ajustement', motif: 'Essai' });
    verifier(r.status() === 422 && proche(niveau(L.vcBar, P.pg), somme(L.vcBar, P.pg)), 'portée : ajuster un lieu du club refusé', r.status());
    r = await poster(patrick, '/gestion/stock/ajuster', { lieu_id: L.central, conditionnement_id: C.pgBouteille, sens: 'ajouter', quantite: '5', type: 'ajustement', motif: 'Essai' });
    verifier(r.status() === 422, 'portée : dépôt central non modifiable sans portée « tous »', r.status());
    const invVc = un(`select id from inventaires where lieu_id=${L.vcReserve}`);
    r = await patrick.goto(B + '/gestion/stock/inventaires/' + invVc);
    verifier(r.status() === 404, 'portée : inventaire du club introuvable pour Patrick', r.status());
    const recVc = un(`select id from receptions where lieu_id=${L.vcReserve} limit 1`);
    r = await patrick.goto(B + '/gestion/achats/receptions/' + recVc);
    verifier(r.status() === 404, 'portée : réception du club introuvable pour Patrick', r.status());
    r = await poster(patrick, '/gestion/stock/transferts/nouveau', { source: L.vcReserve, destination: L.vcBar, 'lignes[0][conditionnement_id]': C.pgCasier, 'lignes[0][quantite]': '1', action: 'expedier' });
    verifier(r.status() === 422, 'portée : transfert depuis le club refusé', r.status());
    // CSRF
    r = await poster(patrick, '/gestion/stock/ajuster', { lieu_id: L.bar, conditionnement_id: C.pgBouteille, sens: 'ajouter', quantite: '5', motif: 'x' }, false);
    verifier(r.status() === 419, 'CSRF : ajustement sans jeton refusé', r.status());
    r = await poster(patrick, '/gestion/achats/fournisseurs/nouveau', { nom: 'X', type: 'autre' }, false);
    verifier(r.status() === 419, 'CSRF : fournisseur sans jeton refusé', r.status());
    await patrick.context().close();
  }

  // ---------- 5. Transferts, validés des deux côtés ----------
  {
    const mag = await connecter(navigateur, MAG);
    const patrick = await connecter(navigateur, PATRICK);
    const res0 = niveau(L.reserve, P.pg), bar0 = niveau(L.bar, P.pg);
    await mag.goto(B + '/gestion/stock/transferts/nouveau');
    await mag.selectOption('#source', String(L.reserve));
    await mag.selectOption('#destination', String(L.bar));
    await mag.selectOption('#ligne-0-format', String(C.pgCasier));
    await mag.fill('#ligne-0-quantite', '2');
    verifier((await mag.locator('[data-ligne-base]').first().textContent()).includes('24'), 'transfert : « soit 24 bouteilles » et stock au départ en direct');
    await mag.click('[data-ajouter-ligne]');
    verifier(await mag.locator('[data-lignes] [data-ligne]').count() === 2, 'transfert : ligne ajoutée sans recharger');
    await mag.selectOption('[data-lignes] [data-ligne]:nth-child(2) select', String(C.cocaPack));
    await mag.fill('[data-lignes] [data-ligne]:nth-child(2) input[data-ligne-quantite]', '1');
    await Promise.all([mag.waitForNavigation(), mag.click('button[value=expedier]:not(.visuellement-cache)')]);
    if (await mag.locator('button[name=confirme]').count()) {
      verifier(await mag.locator('.tre-avertissement').count() === 1, 'avertissement stock insuffisant à la source');
      await Promise.all([mag.waitForNavigation(), mag.click('button[name=confirme]')]);
    }
    let id = +un(`select max(id) from transferts_stock`);
    verifier(un(`select statut from transferts_stock where id=${id}`) === 'expedie', 'transfert expédié');
    verifier(proche(niveau(L.reserve, P.pg), res0 - 24) && proche(niveau(L.bar, P.pg), bar0), 'sortie : la Réserve perd 24, le Bar ne reçoit pas encore');
    let r = await poster(mag, `/gestion/stock/transferts/${id}/expedier`);
    verifier(r.status() === 409, 'expédier deux fois refusé', r.status());
    // Réception par Patrick : écart sans note refusé, puis avec note
    await patrick.goto(B + '/gestion/stock/transferts/' + id);
    const ligne = un(`select id from transfert_stock_lignes where transfert_id=${id} and produit_id=${P.pg}`);
    await patrick.fill(`#recue-${ligne}`, '1,75');
    await Promise.all([patrick.waitForNavigation(), patrick.click('#recevoir button[type=submit]')]);
    verifier(await patrick.locator('#erreur-note').count() === 1, 'écart sans explication refusé');
    await patrick.fill(`#recue-${ligne}`, '1,75');
    await patrick.fill('#note_reception', '3 bouteilles cassées');
    await Promise.all([patrick.waitForNavigation(), patrick.click('#recevoir button[type=submit]')]);
    verifier(un(`select statut from transferts_stock where id=${id}`) === 'recu' && +un(`select recu_par from transferts_stock where id=${id}`) === 2, 'transfert reçu par Patrick');
    verifier(proche(niveau(L.bar, P.pg), bar0 + 21), 'Bar : +21 bouteilles (1,75 casier)', [bar0, niveau(L.bar, P.pg)]);
    verifier(await patrick.locator('[data-ecart="-3"]').count() === 1, 'écart −3 affiché');
    // Annulation d'un envoi : retour à la source
    // L'eau minérale est à 0 dans la Réserve de la démo (article sous son seuil, pour les suggestions) : on envoie un article
    // qui en a au moins 6 bouteilles (sans quoi la page demande de confirmer un stock insuffisant)
    const [pEnvoi, fEnvoi, baseEnvoi] = sql(`select n.produit_id, c.id, c.quantite_base from stock_niveaux n join conditionnements c on c.produit_id=n.produit_id and c.nom_fr like 'Bouteille%' where n.lieu_id=${L.reserve} and n.quantite >= 6 * c.quantite_base order by n.produit_id = ${P.eau} desc, n.produit_id limit 1`)[0].map(Number);
    const res1 = niveau(L.reserve, pEnvoi);
    r = await poster(mag, '/gestion/stock/transferts/nouveau', { source: L.reserve, destination: L.bar, 'lignes[0][conditionnement_id]': fEnvoi, 'lignes[0][quantite]': '6', action: 'expedier' });
    id = +un(`select max(id) from transferts_stock`);
    verifier(r.status() === 302 && proche(niveau(L.reserve, pEnvoi), res1 - 6 * baseEnvoi), 'second envoi (6 bouteilles)');
    r = await poster(mag, `/gestion/stock/transferts/${id}/annuler`);
    verifier(r.status() === 302 && proche(niveau(L.reserve, pEnvoi), res1) && un(`select statut from transferts_stock where id=${id}`) === 'annule', 'annulation de l\'envoi : retour à la Réserve');
    // Plus que le stock de la source : avertissement, puis confirmation
    r = await poster(mag, '/gestion/stock/transferts/nouveau', { source: L.reserve, destination: L.bar, 'lignes[0][conditionnement_id]': C.pgCasier, 'lignes[0][quantite]': '999', action: 'expedier' });
    verifier(r.status() === 200 && (await r.text()).includes('tre-avertissement'), 'avertissement : plus que le stock de la Réserve');
    // Validations
    r = await poster(mag, '/gestion/stock/transferts/nouveau', { source: L.reserve, destination: L.reserve, 'lignes[0][conditionnement_id]': C.pgCasier, 'lignes[0][quantite]': '1', action: 'expedier' });
    verifier(r.status() === 422, 'même lieu refusé');
    r = await poster(mag, '/gestion/stock/transferts/nouveau', { source: L.reserve, destination: L.bar, 'lignes[0][conditionnement_id]': C.pgCasier, 'lignes[0][quantite]': 'abc', action: 'expedier' });
    verifier(r.status() === 422 && (await r.text()).includes('pas une quantité'), 'quantité illisible refusée');
    // Le transfert central → club en route : Patrick ne peut pas le recevoir
    const enRoute = un(`select id from transferts_stock where statut='expedie' and lieu_source_id=${L.central}`);
    r = await poster(patrick, `/gestion/stock/transferts/${enRoute}/recevoir`, {});
    verifier(r.status() === 403, 'recevoir pour le club refusé à Patrick', r.status());
    const admin = await connecter(navigateur, ADMIN);
    const vc0 = niveau(L.vcReserve, P.pg);
    await admin.goto(B + '/gestion/stock/transferts');
    verifier(await admin.locator('.stk-alerte--bloc').count() === 1, 'admin : transfert à recevoir signalé');
    await admin.goto(B + '/gestion/stock/transferts/' + enRoute);
    await Promise.all([admin.waitForNavigation(), admin.click('#recevoir button[type=submit]')]);
    verifier(proche(niveau(L.vcReserve, P.pg), vc0 + 180), 'central → club reçu : +180');
    await admin.context().close(); await mag.context().close(); await patrick.context().close();
  }

  // ---------- 6. Inventaire, séparation des tâches ----------
  {
    const mag = await connecter(navigateur, MAG);
    const patrick = await connecter(navigateur, PATRICK);
    await mag.goto(B + '/gestion/stock/inventaires');
    await mag.selectOption('#lieu_id', String(L.bar));
    await mag.selectOption('#type', 'service');
    await Promise.all([mag.waitForNavigation(), mag.click('form[action="/gestion/stock/inventaires"] button[type=submit]')]);
    const inv = +un('select max(id) from inventaires');
    verifier(un(`select statut from inventaires where id=${inv}`) === 'en_cours', 'inventaire commencé (magasinier)');
    let r = await poster(mag, '/gestion/stock/inventaires', { lieu_id: L.bar, type: 'complet' });
    verifier(r.status() === 422, 'deux inventaires en cours sur un lieu refusés');
    const attPg = num(`select attendu from inventaire_lignes where inventaire_id=${inv} and produit_id=${P.pg}`);
    const attJw = num(`select attendu from inventaire_lignes where inventaire_id=${inv} and produit_id=${P.jw}`);
    verifier(proche(attPg, niveau(L.bar, P.pg)), 'attendu = stock du Bar figé');
    verifier(await mag.locator('.stk-ecarts').count() === 0, 'comptage à l\'aveugle : pas d\'attendu affiché');
    // Comptage : Primus Grande 2 casiers + 1 bouteille ; Johnnie Walker 1 bouteille + 35 cl
    const casiers = Math.floor(attPg / 12) - 1;
    await mag.fill(`#c-${P.pg}-f${C.pgCasier}`, String(casiers));
    await mag.fill(`#c-${P.pg}-f${C.pgBouteille}`, String(Math.round(attPg - casiers * 12) + 5));
    await mag.fill(`#c-${P.jw}-f${C.jwBouteille}`, '1');
    await mag.fill(`#c-${P.jw}-base`, '35');
    verifier((await mag.locator(`[data-produit="${P.jw}"] [data-total-compte]`).textContent()).includes('105'), 'total compté en direct (105 cl)');
    // Bouteille entamée en fraction
    await mag.fill(`#c-${P.vin}-f${C.vinBouteille}`, '2,5');
    await Promise.all([mag.waitForNavigation(), mag.click('button[value=terminer]')]);
    verifier(un(`select statut from inventaires where id=${inv}`) === 'compte', 'comptage terminé');
    verifier(proche(num(`select compte from inventaire_lignes where inventaire_id=${inv} and produit_id=${P.jw}`), 105), 'JW compté 105 cl');
    verifier(proche(num(`select compte from inventaire_lignes where inventaire_id=${inv} and produit_id=${P.vin}`), 187.5), 'vin : 2,5 bouteilles = 187,5 cl');
    r = await poster(mag, `/gestion/stock/inventaires/${inv}/valider`, {});
    verifier(r.status() === 403, 'magasinier : valider interdit (stock.valider)', r.status());
    // Une vente pendant ce temps : elle reste comptée après la validation
    outil('vente', { etab: 1, zone: 1, lignes: [[C.pgBouteille, 1]] });
    const avantVal = niveau(L.bar, P.pg);
    await patrick.goto(B + '/gestion/stock/inventaires/' + inv);
    verifier(await patrick.locator('.stk-ecarts').count() === 1 && await patrick.locator(`.stk-ecarts tr[data-produit="${P.pg}"][data-ecart="5"]`).count() === 1, 'écarts affichés au valideur (+5 Primus)');
    await patrick.fill(`#j-${P.pg}`, 'Casier oublié derrière le bar');
    await Promise.all([patrick.waitForNavigation(), patrick.click('form[action$="/valider"] button[type=submit]')]);
    verifier(un(`select statut from inventaires where id=${inv}`) === 'valide' && un(`select valide_seul from inventaires where id=${inv}`) === '0', 'validé par Patrick (autre personne)');
    verifier(proche(niveau(L.bar, P.pg), avantVal + 5), 'stock corrigé de l\'écart (+5), la vente faite entre-temps reste comptée');
    verifier(proche(niveau(L.bar, P.jw), 105 + (niveau(L.bar, P.jw) - 105)) && proche(num(`select sum(quantite) from stock_mouvements where type='inventaire' and source_id=${inv} and produit_id=${P.jw}`), 105 - attJw), 'écart JW enregistré');
    verifier(un(`select justification from inventaire_lignes where inventaire_id=${inv} and produit_id=${P.pg}`) === 'Casier oublié derrière le bar', 'justification gardée');
    // Patrick compte et valide seul : refusé sans la case, accepté avec (noté)
    r = await poster(patrick, '/gestion/stock/inventaires', { lieu_id: L.froid, type: 'complet' });
    const inv2 = +un('select max(id) from inventaires');
    r = await poster(patrick, `/gestion/stock/inventaires/${inv2}/compter`, { action: 'terminer', [`comptes[${P.pdt}][base]`]: '10' });
    const lignesInv2 = +un(`select count(*) from inventaire_lignes where inventaire_id=${inv2}`);
    r = await poster(patrick, `/gestion/stock/inventaires/${inv2}/valider`, {});
    verifier(r.status() === 422 && (await r.text()).includes('Je valide mon propre comptage'), 'séparation des tâches : validation seule refusée sans confirmation', r.status());
    r = await poster(patrick, `/gestion/stock/inventaires/${inv2}/valider`, { seul: '1' });
    verifier(r.status() === 302 && un(`select valide_seul from inventaires where id=${inv2}`) === '1', 'validation seule confirmée et notée');
    verifier(lignesInv2 > 1 && +un(`select count(*) from stock_mouvements where type='inventaire' and source_id=${inv2}`) <= 1, 'articles non comptés : pas corrigés');
    await mag.context().close(); await patrick.context().close();
  }

  // ---------- 7. Pertes ----------
  {
    const mag = await connecter(navigateur, MAG);
    await mag.goto(B + '/gestion/stock/pertes');
    await mag.selectOption('#lieu_id', String(L.bar));
    await mag.selectOption('#conditionnement_id', String(C.pgBouteille));
    await mag.fill('#quantite', '2');
    await mag.selectOption('#motif', 'casse');
    await Promise.all([mag.waitForNavigation(), mag.click('form[action="/gestion/stock/pertes"] button[type=submit]')]);
    verifier(await mag.locator('#erreur-commentaire').count() === 1, 'perte sans motif écrit refusée');
    const avant = niveau(L.bar, P.pg);
    await mag.fill('#commentaire', 'Deux bouteilles tombées');
    await Promise.all([mag.waitForNavigation(), mag.click('form[action="/gestion/stock/pertes"] button[type=submit]')]);
    verifier(proche(niveau(L.bar, P.pg), avant - 2) && un("select motif from pertes_stock order by id desc limit 1") === 'casse', 'perte enregistrée : −2');
    await mag.context().close();
  }

  // ---------- 8. Fiche technique ----------
  {
    const patrick = await connecter(navigateur, PATRICK);
    await patrick.goto(B + '/gestion/stock/recettes/' + P.mojito);
    const ligneRhum = await patrick.locator(`[data-ingredient] option[value="${P.rhum}"]:checked`).locator('xpath=ancestor::fieldset').first();
    await ligneRhum.locator('input[name$="[quantite]"]').fill('6');
    await Promise.all([patrick.waitForNavigation(), patrick.click('form[data-nom-lignes] .tre-actions button[type=submit]')]);
    verifier(proche(num(`select ri.quantite from recette_ingredients ri join recettes r on r.id=ri.recette_id where r.produit_id=${P.mojito} and ri.produit_id=${P.rhum}`), 6), 'fiche Mojito : rhum 6 cl enregistré');
    const rhum0 = niveau(L.bar, P.rhum);
    outil('vente', { etab: 1, zone: 1, lignes: [[C.mojito, 1]] });
    verifier(proche(niveau(L.bar, P.rhum), rhum0 - 6), 'vente d\'un Mojito : −6 cl de rhum');
    let r = await poster(patrick, '/gestion/stock/recettes/' + P.mojito, { rendement: '1', actif: '1', 'ingredients[0][produit_id]': P.rhum, 'ingredients[0][quantite]': '5', 'ingredients[1][produit_id]': P.rhum, 'ingredients[1][quantite]': '2' });
    verifier(r.status() === 422, 'ingrédient en double refusé');
    r = await poster(patrick, '/gestion/stock/recettes/' + P.mojito, { rendement: '1', actif: '1', 'ingredients[0][produit_id]': P.rhum, 'ingredients[0][quantite]': '5', 'ingredients[0][rendement_pct]': '150' });
    verifier(r.status() === 422, 'part utilisable > 100 % refusée');
    await patrick.goto(B + '/gestion/stock/recettes');
    verifier(await patrick.locator('.stk-marges').count() === 0, 'gérant sans rapports.marges : pas de marges');
    await patrick.context().close();
    const grace = await connecter(navigateur, GRACE);
    await grace.goto(B + '/gestion/stock/recettes');
    verifier(await grace.locator(`.stk-marges tr[data-marge="${C.mojito}"]`).count() === 1, 'comptable (rapports.marges) : marges par format');
    r = await poster(grace, '/gestion/stock/recettes/' + P.mojito, { rendement: '1', 'ingredients[0][produit_id]': P.rhum, 'ingredients[0][quantite]': '5' });
    verifier(r.status() === 403, 'comptable : modifier une fiche interdit', r.status());
    // Coût de revient : somme des ingrédients au CUMP
    const cout = num(`select sum(ri.quantite / r.rendement / (ri.rendement_pct/100) * c.cump) from recette_ingredients ri join recettes r on r.id=ri.recette_id join stock_couts c on c.produit_id=ri.produit_id where r.produit_id=${P.mojito}`);
    await grace.goto(B + '/gestion/stock/recettes/' + P.mojito);
    const texte = await grace.locator('.stk-total-cout').textContent();
    verifier(texte.includes('$' + cout.toFixed(2).replace('.', ',')) || texte.includes(cout.toFixed(2).replace('.', ',')), 'coût de revient du Mojito', [texte, cout]);
    await grace.context().close();
  }

  // ---------- 9. Lieux et routage ----------
  {
    const patrick = await connecter(navigateur, PATRICK);
    let r = await poster(patrick, '/gestion/stock/lieux', { etablissement_id: '1', type: 'comptoir', nom: 'Chicha' });
    const chicha = lieu('Chicha');
    verifier(r.status() === 302 && chicha > 0, 'comptoir Chicha créé');
    r = await poster(patrick, '/gestion/stock/lieux', { etablissement_id: '1', type: 'comptoir', nom: 'Chicha' });
    verifier(r.status() === 422, 'nom déjà pris refusé');
    r = await poster(patrick, '/gestion/stock/lieux', { etablissement_id: '2', type: 'comptoir', nom: 'X' });
    verifier(r.status() === 403, 'créer au club refusé (portée)');
    const cocktails = un("select id from categories where nom_fr='Cocktails'");
    r = await poster(patrick, '/gestion/stock/lieux/routage/1', { [`categories[${cocktails}]`]: chicha, [`categories[${un("select id from categories where nom_fr='Plats'")}]`]: L.cuisine, [`categories[${un("select id from categories where nom_fr='À grignoter'")}]`]: L.cuisine });
    verifier(r.status() === 302, 'routage enregistré');
    const rhumChicha = niveau(chicha, P.rhum);
    outil('vente', { etab: 1, zone: 1, lignes: [[C.mojito, 1]] });
    verifier(proche(niveau(chicha, P.rhum), rhumChicha - 6), 'Cocktails routés vers la Chicha (stock créé, négatif)');
    r = await poster(patrick, `/gestion/stock/lieux/${chicha}/supprimer`);
    verifier(r.status() === 302 && lieu('Chicha') === chicha, 'lieu avec mouvements : non supprimé');
    r = await poster(patrick, '/gestion/stock/lieux', { etablissement_id: '1', type: 'depot', nom: 'Cave' });
    r = await poster(patrick, `/gestion/stock/lieux/${lieu('Cave')}/supprimer`);
    verifier(!lieu('Cave'), 'lieu vide supprimé');
    r = await poster(patrick, `/gestion/stock/lieux/${chicha}/defaut`);
    verifier(un(`select par_defaut from lieux_stock where id=${chicha}`) === '1' && un(`select par_defaut from lieux_stock where id=${L.bar}`) === '0', 'Chicha comptoir par défaut');
    const eauChicha = niveau(chicha, P.eau);
    outil('vente', { etab: 1, zone: 1, lignes: [[format(P.eau, 'Bouteille'), 1]] });
    verifier(proche(niveau(chicha, P.eau), eauChicha - 1), 'vente non routée → comptoir par défaut');
    await poster(patrick, `/gestion/stock/lieux/${L.bar}/defaut`);
    await poster(patrick, '/gestion/stock/lieux/routage/1', { [`categories[${un("select id from categories where nom_fr='Plats'")}]`]: L.cuisine, [`categories[${un("select id from categories where nom_fr='À grignoter'")}]`]: L.cuisine });
    await patrick.context().close();
  }

  // ---------- 10. Achats : fournisseur, commande, réception, paiements, CUMP, emballages ----------
  {
    const admin = await connecter(navigateur, ADMIN);
    let r = await poster(admin, '/gestion/achats/fournisseurs/nouveau', { nom: '', type: 'distributeur' });
    verifier(r.status() === 422, 'fournisseur sans nom refusé');
    r = await poster(admin, '/gestion/achats/fournisseurs/nouveau', { nom: 'Bralima Goma', type: 'brasserie' });
    verifier(r.status() === 422, 'fournisseur en double refusé');
    r = await poster(admin, '/gestion/achats/fournisseurs/nouveau', { nom: 'Distri Test', type: 'distributeur', telephone: '+243 81 222 3333', delai_paiement: '10', devise_id: un("select id from devises where code='USD'"), email: 'pas-un-mail' });
    verifier(r.status() === 422, 'e-mail invalide refusé');
    r = await poster(admin, '/gestion/achats/fournisseurs/nouveau', { nom: 'Distri Test', type: 'distributeur', telephone: '+243 81 222 3333', delai_paiement: '10', devise_id: un("select id from devises where code='USD'") });
    const fid = +un("select id from fournisseurs where nom='Distri Test'");
    verifier(r.status() === 302 && un(`select telephone from fournisseurs where id=${fid}`) === '+243812223333', 'fournisseur créé');
    // Bon de commande dans l'interface
    await admin.goto(B + '/gestion/achats/commandes/nouvelle?fournisseur=' + fid);
    await admin.selectOption('#lieu_id', String(L.reserve));
    await admin.selectOption('#ligne-0-format', String(C.pgCasier));
    await admin.fill('#ligne-0-quantite', '10');
    await admin.fill('#ligne-0-prix', '12');
    verifier((await admin.locator('[data-total-lignes]').textContent()).includes('120'), 'commande : total en direct');
    await Promise.all([admin.waitForNavigation(), admin.click('.tre-actions button[type=submit]')]);
    const cid = +un('select max(id) from commandes_achat');
    verifier(un(`select statut from commandes_achat where id=${cid}`) === 'brouillon', 'commande en brouillon');
    await Promise.all([admin.waitForNavigation(), admin.click('form[action$="/envoyer"] button')]);
    verifier(un(`select statut from commandes_achat where id=${cid}`) === 'envoyee', 'commande envoyée');
    verifier((await admin.locator('#bon-texte').inputValue()).includes('10 casiers Primus Grande'), 'texte du bon à envoyer');
    // Réception de la commande : 8 casiers sur 10, payée en partie en francs (M-Pesa) depuis une facture en dollars
    const usd = +un("select id from devises where code='USD'"), cdf = +un("select id from devises where code='CDF'");
    const mpesa = +un("select id from comptes_tresorerie where nom='M-Pesa'");
    const soldeMpesa = solde(mpesa, cdf);
    const stockAvant = somme(L.reserve, P.pg);
    const totalPg = num(`select sum(quantite) from stock_niveaux where produit_id=${P.pg}`);
    const cumpAvant = num(`select cump from stock_couts where produit_id=${P.pg}`);
    const ret0 = num(`select coalesce(sum(quantite),0) from emballage_mouvements where fournisseur_id=${fid}`);
    const casiersVides0 = niveau(L.reserve, produit('Casier vide Bralima (12 grandes)'));
    await admin.goto(B + '/gestion/achats/receptions/nouvelle?commande=' + cid);
    verifier(await admin.locator('#ligne-0-quantite').inputValue() === '10', 'réception préremplie depuis la commande');
    await admin.fill('#ligne-0-quantite', '8');
    await admin.fill('#facture_numero', 'DT-001');
    await admin.fill(`#emb-${produit('Casier vide Bralima (12 grandes)')}-rendus`, '3');
    await admin.check('[data-payer]');
    await admin.selectOption('#paiement-0-compte', String(mpesa));
    await admin.selectOption('#paiement-0-devise', String(cdf));
    const taux = num(`select taux from taux_change where devise_id=${cdf} order by valable_depuis desc, id desc limit 1`);
    await admin.fill('#paiement-0-montant', String(50 * taux));
    await Promise.all([admin.waitForNavigation(), admin.click('form .tre-actions button[type=submit]')]);
    const rid = +un('select max(id) from receptions');
    verifier(admin.url().endsWith('/gestion/achats/receptions/' + rid), 'réception enregistrée', admin.url());
    verifier(proche(somme(L.reserve, P.pg), stockAvant + 96), 'stock : +96 bouteilles (8 casiers)');
    verifier(un(`select statut from commandes_achat where id=${cid}`) === 'partielle', 'commande reçue en partie');
    verifier(await admin.locator('[data-ecart="-2"]').count() === 1, 'écart commandé/reçu affiché (−2)');
    const cumpAttendu = (Math.max(0, totalPg) * cumpAvant + 96 * 1) / (Math.max(0, totalPg) + 96);
    verifier(proche(num(`select cump from stock_couts where produit_id=${P.pg}`), cumpAttendu, 0.0001), 'CUMP recalculé', [cumpAttendu, un(`select cump from stock_couts where produit_id=${P.pg}`)]);
    verifier(proche(solde(mpesa, cdf), soldeMpesa - 50 * taux), 'trésorerie : M-Pesa −50 $ en francs');
    verifier(+un(`select count(*) from mouvements_tresorerie where nature='achat' and compte_id=${mpesa} and source_id=(select max(id) from paiements_fournisseurs)`) === 1, 'mouvement de nature achat');
    verifier(proche(num(`select paye from (select sum(pl.contre_valeur) paye from paiements_fournisseurs pf join paiement_fournisseur_lignes pl on pl.paiement_id=pf.id where pf.reception_id=${rid}) x`), 50, 0.01), 'part couverte : 50 $');
    const casier12 = produit('Casier vide Bralima (12 grandes)');
    verifier(proche(num(`select coalesce(sum(quantite),0) from emballage_mouvements where fournisseur_id=${fid} and produit_id=${casier12}`), 8 - 3)
      && proche(num(`select coalesce(sum(quantite),0) from emballage_mouvements where fournisseur_id=${fid} and produit_id=${produit('Bouteille vide 72 cl')}`), 96),
      'emballages dus au fournisseur : 8 casiers reçus − 3 rendus, 96 bouteilles');
    verifier(proche(niveau(L.reserve, produit('Casier vide Bralima (12 grandes)')), casiersVides0 - 3), '3 casiers vides sortis de la Réserve');
    verifier(un(`select echeance from receptions where id=${rid}`) !== 'NULL', 'échéance calculée (délai 10 jours)');
    // Paiement trop grand refusé, découvert signalé, paiement du reste
    const reste = 96 - 50;
    r = await poster(admin, `/gestion/achats/receptions/${rid}/payer`, { 'paiements[0][compte_id]': un("select id from comptes_tresorerie where nom='Rawbank'"), 'paiements[0][devise_id]': usd, 'paiements[0][montant]': '500' });
    verifier(r.status() === 422 && (await r.text()).includes('plus que le reste'), 'trop payé refusé');
    const caisseCdl = +un("select id from comptes_tresorerie where nom='Caisse du Comptoir'");
    const soldeCaisse = solde(caisseCdl, usd);
    sql(`insert into mouvements_tresorerie (compte_id, sens, montant, devise_id, nature, date_operation, libelle) values (${caisseCdl}, 'sortie', ${soldeCaisse - 10}, ${usd}, 'ajustement', now(), 'test découvert')`);
    r = await poster(admin, `/gestion/achats/receptions/${rid}/payer`, { 'paiements[0][compte_id]': caisseCdl, 'paiements[0][devise_id]': usd, 'paiements[0][montant]': String(reste) });
    verifier(r.status() === 200 && (await r.text()).includes('tre-avertissement'), 'découvert signalé avant de payer');
    r = await poster(admin, `/gestion/achats/receptions/${rid}/payer`, { 'paiements[0][compte_id]': caisseCdl, 'paiements[0][devise_id]': usd, 'paiements[0][montant]': String(reste), confirme: '1' });
    verifier(r.status() === 302 && proche(solde(caisseCdl, usd), 10 - reste), 'payé quand même : caisse en découvert');
    await admin.goto(B + '/gestion/achats/receptions/' + rid);
    verifier(await admin.locator('[data-paiement="payee"]').count() >= 1, 'réception payée');
    // Annulation d'un paiement : ses mouvements disparaissent
    const pid = +un(`select max(id) from paiements_fournisseurs where reception_id=${rid}`);
    r = await poster(admin, `/gestion/achats/paiements/${pid}/supprimer`);
    verifier(r.status() === 302 && +un(`select count(*) from mouvements_tresorerie where nature='achat' and source_id=${pid}`) === 0 && proche(solde(caisseCdl, usd), 10), 'paiement annulé, caisse rétablie');
    // Dettes et prix
    await admin.goto(B + '/gestion/achats/dettes');
    verifier(await admin.locator(`[data-dette-fournisseur="${fid}"]`).count() === 1, 'dette du fournisseur listée');
    verifier(await admin.locator('[data-dette-fournisseur] .badge--erreur').count() >= 1, 'une facture en retard (démo) signalée');
    await admin.goto(B + '/gestion/achats/prix?produit=' + P.pg);
    verifier(await admin.locator('main tbody tr').count() >= 3 && await admin.locator('.stk-cump').count() === 1, 'historique des prix et CUMP');
    // Achat au marché sans facture, avec photo du ticket
    const photo = DIR + '/ticket.jpg';
    if (!fs.existsSync(photo)) execSync(`php -r '$i=imagecreatetruecolor(300,200);imagefill($i,0,0,imagecolorallocate($i,240,230,200));imagejpeg($i,"${photo}");'`);
    await admin.goto(B + '/gestion/achats/receptions/nouvelle');
    await admin.selectOption('#fournisseur_id', un("select id from fournisseurs where type='marche'"));
    await admin.selectOption('#lieu_id', String(L.froid));
    await admin.selectOption('#devise_id', String(cdf));
    await admin.selectOption('#ligne-0-format', String(format(P.pdt, 'Sac 25 kg')));
    await admin.fill('#ligne-0-quantite', '2');
    await admin.fill('#ligne-0-prix', '45000');
    await Promise.all([admin.waitForNavigation(), admin.click('form .tre-actions button[type=submit]')]);
    verifier(await admin.locator('#erreur-facture_numero').count() === 1, 'ni facture ni photo : refusé');
    await admin.check('input[name=sans_facture]');
    await admin.setInputFiles('#justificatif', photo);
    await admin.selectOption('#ligne-0-format', String(format(P.pdt, 'Sac 25 kg')));
    await admin.fill('#ligne-0-quantite', '2');
    await admin.fill('#ligne-0-prix', '45000');
    const froid0 = niveau(L.froid, P.pdt);
    await Promise.all([admin.waitForNavigation(), admin.click('form .tre-actions button[type=submit]')]);
    const rid2 = +un('select max(id) from receptions');
    const just = un(`select justificatif from receptions where id=${rid2}`);
    verifier(un(`select sans_facture from receptions where id=${rid2}`) === '1' && /^\/uploads\/integ\/achats\/rec-/.test(just), 'achat au marché avec photo', just);
    verifier(proche(niveau(L.froid, P.pdt), froid0 + 50), '+50 kg de pommes de terre');
    verifier(fs.existsSync('/home/user/Eglise/restau-bar-pos/public' + just), 'photo enregistrée');
    verifier(await admin.locator('img.stk-justificatif').count() === 1, 'photo affichée sur la fiche');
    await admin.context().close();
  }

  // ---------- 11. Suggestions, emballages, mouvements ----------
  {
    const admin = await connecter(navigateur, ADMIN);
    await admin.goto(B + '/gestion/stock/suggestions');
    const achat = admin.locator('[data-suggestion="achat"] form').filter({ has: admin.locator('button') }).first();
    verifier(await admin.locator('[data-suggestion]').count() >= 1, 'suggestions de commande');
    if (await achat.count()) {
      const avant = +un('select count(*) from commandes_achat');
      await Promise.all([admin.waitForNavigation(), achat.locator('button').click()]);
      verifier(+un('select count(*) from commandes_achat') === avant + 1 && /\/gestion\/achats\/commandes\/\d+$/.test(admin.url()), 'bon de commande créé depuis une suggestion');
    }
    let r = await poster(admin, '/gestion/stock/emballages', { nom_fr: '', valeur: '100', devise_id: un("select id from devises where code='CDF'") });
    verifier(r.status() === 422, 'emballage sans nom refusé');
    r = await poster(admin, '/gestion/stock/emballages', { nom_fr: 'Fût vide 30 l', nom_en: 'Empty 30 l keg', valeur: '50', devise_id: un("select id from devises where code='USD'") });
    const fut = produit('Fût vide 30 l');
    verifier(r.status() === 302 && fut > 0 && un(`select visible_public from produits where id=${fut}`) === '0', 'emballage créé (caché du menu)');
    const client = un('select id from clients limit 1');
    if (client) {
      r = await poster(admin, '/gestion/stock/emballages/echange', { tiers: 'client', client_id: client, emballage_id: fut, type: 'pret', quantite: '2' });
      verifier(r.status() === 422, 'prêt à un client sans lieu refusé');
      await poster(admin, '/gestion/stock/ajuster', { lieu_id: L.reserve, conditionnement_id: format(fut, 'Pièce'), sens: 'compter', quantite: '5', type: 'ouverture', motif: 'Fûts' });
      r = await poster(admin, '/gestion/stock/emballages/echange', { tiers: 'client', client_id: client, emballage_id: fut, type: 'pret', quantite: '2', lieu_id: L.reserve });
      verifier(r.status() === 302 && proche(niveau(L.reserve, fut), 3), 'prêt de 2 fûts à un client : sortis de la Réserve');
      await admin.goto(B + '/gestion/stock/emballages');
      verifier(await admin.locator(`[data-solde-client="${client}"]`).count() === 1, 'solde d\'emballages du client');
    }
    await admin.goto(B + '/gestion/stock/mouvements?periode=tout&type=perte');
    verifier(await admin.locator('tr[data-type="perte"]').count() >= 8 && await admin.locator('tr[data-type]:not([data-type="perte"])').count() === 0, 'journal filtré par type');
    await admin.goto(B + `/gestion/stock/mouvements?periode=tout&produit=${P.pg}&lieu=${L.bar}`);
    verifier(await admin.locator('tbody tr').count() > 10, 'journal par article et lieu');
    await admin.context().close();
  }

  // ---------- 12. Tous les écrans : FR/EN, jour/nuit, ordinateur/téléphone ----------
  const ecrans = ['/gestion/stock', `/gestion/stock?lieu=${L.bar}`, '/gestion/stock/mouvements', '/gestion/stock/transferts', '/gestion/stock/transferts/nouveau',
    `/gestion/stock/transferts/${un('select max(id) from transferts_stock where statut=\'recu\'')}`, '/gestion/stock/inventaires',
    `/gestion/stock/inventaires/${un("select id from inventaires where statut='valide' order by id limit 1")}`,
    `/gestion/stock/inventaires/${un("select id from inventaires where statut='compte' order by id limit 1")}`,
    '/gestion/stock/pertes', '/gestion/stock/recettes', `/gestion/stock/recettes/${P.mojito}`, '/gestion/stock/emballages', '/gestion/stock/lieux',
    '/gestion/stock/ajuster', '/gestion/stock/suggestions', '/gestion/achats/receptions', '/gestion/achats/receptions/nouvelle',
    `/gestion/achats/receptions/${un('select min(id) from receptions where fournisseur_id=1')}`, '/gestion/achats/commandes', '/gestion/achats/commandes/nouvelle',
    `/gestion/achats/commandes/${un('select min(id) from commandes_achat')}`, '/gestion/achats/fournisseurs', '/gestion/achats/fournisseurs/1',
    '/gestion/achats/fournisseurs/nouveau', '/gestion/achats/dettes', '/gestion/achats/prix'];
  for (const [nom, vp] of [['bureau', { width: 1366, height: 860 }], ['tel', { width: 390, height: 844 }]]) {
    for (const theme of ['jour', 'nuit']) {
      const page = await connecter(navigateur, ADMIN, { viewport: vp, colorScheme: theme === 'nuit' ? 'dark' : 'light' });
      for (const langue of theme === 'jour' ? ['fr', 'en'] : ['fr']) {
        await page.goto(B + '/gestion/stock?lang=' + langue);
        for (const u of ecrans) {
          const r = await page.goto(B + u);
          verifier(r.status() === 200, `${nom}/${theme}/${langue} 200 ${u}`, r.status());
          verifier(await debordement(page) <= 0, `${nom}/${theme}/${langue} pas de défilement horizontal ${u}`, await debordement(page));
          const cles = await clesBrutes(page);
          verifier(cles.length === 0, `${nom}/${langue} clés traduites ${u}`, cles);
          if (theme === 'jour' && langue === 'fr') {
            const petites = await petitesCibles(page);
            verifier(petites.length === 0, `${nom} cibles de 44 px ${u}`, petites.slice(0, 5));
          }
          if (langue === 'fr' && ['/gestion/stock', '/gestion/stock/lieux', '/gestion/achats/receptions/nouvelle', '/gestion/stock/transferts/nouveau', '/gestion/stock/recettes', '/gestion/achats/dettes'].includes(u)
              || (u.startsWith('/gestion/stock/inventaires/') && langue === 'fr') || (u.startsWith('/gestion/achats/receptions/') && /\d$/.test(u) && langue === 'fr')) {
            await page.screenshot({ path: `${DIR}/ecran-${u.replace(/^\/gestion\//, '').replace(/\/\d+$/, '-fiche').replace(/[^a-z0-9-]+/gi, '-')}-${nom}-${theme}.png`, fullPage: true });
          }
        }
        if (langue === 'en') {
          await page.goto(B + '/gestion/stock');
          verifier((await page.locator('h1').textContent()).includes('Stock levels'), 'anglais : titre « Stock levels »');
          await page.goto(B + '/gestion/stock?lang=fr');
        }
      }
      verifier(page.erreurs.length === 0, `${nom}/${theme} aucune erreur JavaScript ni ressource manquante`, page.erreurs.slice(0, 5));
      await page.context().close();
    }
  }
  // Modules : Stock et Achats ouverts (plus « Bientôt »)
  {
    const page = await connecter(navigateur, ADMIN);
    await page.goto(B + '/gestion');
    verifier(await page.locator('a[href="/gestion/stock"]').count() >= 1 && await page.locator('a[href="/gestion/achats/receptions"]').count() >= 1, 'écran des modules : Stock et Achats ouverts');
    await page.goto(B + '/gestion/stock');
    verifier(await page.locator('.lateral__lien--bientot').count() === 0 && await page.locator('.lateral__lien').count() === 10, 'barre latérale : 10 écrans du stock (productions et péremptions comprises)');
    await page.goto(B + '/gestion/utilisateurs/roles');
    const texte = await page.evaluate(() => document.body.innerText);
    await page.goto(B + '/gestion/utilisateurs/journal');
    const journal = await page.evaluate(() => document.body.innerText);
    verifier(!/journal\.action_/.test(journal), 'journal : actions du stock traduites');
    await page.context().close();
  }

  await navigateur.close();
  sql(`delete from utilisateur_roles where utilisateur_id=${magId}`);
  console.log(`\n${ok} réussis, ${ko} échoués`);
  if (echecs.length) console.log(echecs.join('\n'));
  process.exit(ko ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
