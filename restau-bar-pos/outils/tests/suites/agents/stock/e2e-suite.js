// Tests de bout en bout : Stock, suite (préparations et productions, péremptions et lots, caisse du marché, vente en
// gros, coûts et rentabilité), sur l'instance essaistock (base rbp_integ, jamais rbp_integ).
// Départ : état « après bin/demo-stock-suite.php » (essai-apres-demo-suite.sql).
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
const CHEF = ['chef@essai.test', 'Chef-Goma-2026'];
const GERANTVC = ['gerantvc@essai.test', 'Gerant-Club-2026'];
const MAGVC = ['magvc@essai.test', 'Magasin-Club-2026'];

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
const lots = (lieu, produit) => num(`select coalesce(sum(quantite_restante),0) from stock_lots where lieu_id=${lieu} and produit_id=${produit}`);
const lieu = (nom) => +un(`select id from lieux_stock where nom='${nom}'`);
const produit = (nom) => +un(`select id from produits where nom_fr='${nom.replace(/'/g, "''")}' order by id limit 1`);
const format = (p, nom) => +un(`select id from conditionnements where produit_id=${p} and nom_fr='${nom}'`);
const solde = (compte, devise) => num(`select coalesce(sum(if(sens='entree',montant,-montant)),0) from mouvements_tresorerie where compte_id=${compte} and devise_id=${devise}`);
const cump = (p) => num(`select coalesce((select cump from stock_couts where produit_id=${p}),0)`);
const embClient = (client, emb, consigne = false) => num(`select coalesce(sum(quantite),0) from emballage_mouvements where client_id=${client} and produit_id=${emb} and type ${consigne ? '=' : '<>'} 'consigne_facturee'`);

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
async function poster(page, url, donnees = {}, avecJeton = true) {
  const form = { ...donnees };
  if (avecJeton) form.csrf = await jeton(page);
  return page.request.post(B + url, { form, maxRedirects: 0 });
}
async function posterFichier(page, url, donnees = {}) {
  const multipart = { ...donnees, csrf: await jeton(page) };
  return page.request.post(B + url, { multipart, maxRedirects: 0 });
}
async function debordement(page) {
  return page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
}
async function clesBrutes(page) {
  const texte = await page.evaluate(() => document.body.innerText);
  return (texte.match(/\b(stock|achats|emballages|perm|ecran|gros|couts|tresorerie|depenses|gventes|etab)\.[a-z_]{3,}/g) || []);
}
async function petitesCibles(page) {
  return page.evaluate(() => Array.from(document.querySelectorAll('main a, main button, main select, main input:not([type=hidden]):not([type=checkbox]):not([type=radio]), main summary'))
    .filter(el => { const r = el.getBoundingClientRect(); const s = getComputedStyle(el); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && !el.closest('.visuellement-cache') && el.getAttribute('aria-hidden') !== 'true'; })
    .filter(el => { const r = el.getBoundingClientRect(); return r.height < 43.5 && !(el.tagName === 'A' && el.closest('td, li, p, .tre-sous-texte, h1, h2, th')); })
    .map(el => (el.tagName + ' ' + (el.textContent || el.name || '').trim().slice(0, 30) + ' ' + Math.round(el.getBoundingClientRect().height))));
}

(async () => {
  // ---------- Base de départ ----------
  execSync('/home/user/Eglise/restau-bar-pos/outils/tests/restaurer.sh');
  // Les photos des achats au marché de la démo sont dans public/uploads/integ/achats (copie remise par restaurer.sh)
  const chefId = +outil('utilisateur', { nom: 'Chef Amani', email: CHEF[0], mdp: CHEF[1], role: 'chef_cuisine', etab: 1 });
  const gerantVcId = +outil('utilisateur', { nom: 'Gérant Club', email: GERANTVC[0], mdp: GERANTVC[1], role: 'gerant', etab: 2 });
  const magVcId = +outil('utilisateur', { nom: 'Magasin Club', email: MAGVC[0], mdp: MAGVC[1], role: 'magasinier', etab: 2 });

  const L = { cuisine: lieu('Cuisine'), vcCuisine: lieu('Cuisine du club'), vcReserve: lieu('Réserve du club'), reserve: lieu('Réserve'), bar: lieu('Bar'), froid: lieu('Chambre froide') };
  const P = { sauce: produit('Sauce pili-pili maison'), marinade: produit('Marinade des brochettes'), legumes: produit('Légumes (oignons, tomates, piment)'),
    huile: produit('Huile végétale'), tilapia: produit('Tilapia frais'), tilapiaPlat: produit('Tilapia grillé du lac'), ailes: produit('Ailes de poulet'),
    creme: produit('Crème fraîche'), lait: produit('Lait frais'), pg: produit('Primus Grande'), pp: produit('Primus Petite'), mg: produit('Mützig Grande'),
    casier12: produit('Casier vide Bralima (12 grandes)'), casier24: produit('Casier vide Bralima (24 petites)'), b72: produit('Bouteille vide 72 cl'), b33: produit('Bouteille vide 33 cl'),
    poulet: produit('Poulet entier'), citron: produit('Citron vert') };
  const C = { tilapiaPlat: format(P.tilapiaPlat, 'Portion') || +un(`select id from conditionnements where produit_id=${P.tilapiaPlat} and vendable=1 limit 1`),
    pgCasier: format(P.pg, 'Casier'), ppCasier: format(P.pp, 'Casier'), mgCasier: format(P.mg, 'Casier'), legumesKg: format(P.legumes, 'Kilo'), tilapiaKg: format(P.tilapia, 'Kilo'), cremePot: format(P.creme, 'Pot 20 cl') };
  const fonds = +un(`select id from comptes_tresorerie where nom='Caisse cuisine (chef)'`);
  const cdf = +un(`select id from devises where code='CDF'`), usd = +un(`select id from devises where code='USD'`);
  const bahati = +un(`select id from clients where nom='Patient Bahati'`), furaha = +un(`select id from clients where nom='Dépôt Maman Furaha'`);
  const caisseClub = +un(`select id from comptes_tresorerie where nom='Caisse du Club'`);
  const navigateur = await chromium.launch();

  // ---------- 1. Démonstration ----------
  verifier(un(`select mode_negatif from stock_parametres where etablissement_id=2`) === 'bloquer', 'démo : Volcano Club en mode « bloquer »');
  verifier(+un(`select count(*) from recettes where preparation=1`) === 2, 'démo : 2 fiches de préparation');
  verifier(+un(`select count(*) from recette_ingredients ri join recettes r on r.id=ri.recette_id where ri.produit_id in (${P.sauce},${P.marinade})`) === 3, 'démo : 3 plats utilisent une préparation');
  verifier(+un(`select count(*) from productions`) >= 15, 'démo : productions');
  verifier(+un(`select count(*) from stock_lots where peremption < curdate() and quantite_restante > 0`) >= 2, 'démo : lots périmés à déclarer');
  verifier(+un(`select count(*) from stock_lots where peremption between curdate() and curdate() + interval 3 day and quantite_restante > 0`) >= 2, 'démo : lots qui périment bientôt');
  verifier(+un(`select compte_marche_id from stock_parametres where etablissement_id=1`) === fonds && fonds > 0, 'démo : caisse du marché du Comptoir du Lac');
  verifier(+un(`select count(*) from receptions r join paiements_fournisseurs pf on pf.reception_id=r.id join paiement_fournisseur_lignes pl on pl.paiement_id=pf.id where pl.compte_id=${fonds} and r.sans_facture=1 and r.justificatif like '/uploads/integ/achats/mar-%'`) === 5, 'démo : 5 achats au marché payés par le fonds, avec photo');
  verifier(+un(`select vente_gros from lieux_stock where id=${L.vcReserve}`) === 1 && +un(`select count(*) from ventes_gros`) === 3, 'démo : vente en gros à la Réserve du club, 3 ventes');
  verifier(+un(`select count(*) from dettes where vente_id in (select vente_id from ventes_gros)`) === 1, 'démo : une vente en gros à l\'ardoise');
  verifier(embClient(bahati, P.casier12) === 6 && embClient(bahati, P.b72) === 72, 'démo : Bahati doit 6 casiers et 72 bouteilles (5 rapportés)', [embClient(bahati, P.casier12), embClient(bahati, P.b72)]);
  verifier(embClient(furaha, P.casier24, true) === 2, 'démo : Furaha a 2 casiers consignés');
  verifier(+un(`select count(*) from stock_niveaux n where abs(n.quantite - (select coalesce(sum(m.quantite),0) from stock_mouvements m where m.lieu_id=n.lieu_id and m.produit_id=n.produit_id)) > 0.001`) === 0, 'stock = somme du journal');
  verifier(proche(niveau(L.cuisine, P.sauce), lots(L.cuisine, P.sauce)) && proche(niveau(L.vcCuisine, P.sauce), lots(L.vcCuisine, P.sauce)), 'préparation : stock = somme des lots restants');
  verifier(+un(`select count(*) from stock_lots where quantite_restante < -0.0005 or quantite_restante > quantite_initiale + 0.0005`) === 0, 'lots : restes entre 0 et la quantité initiale');
  verifier(+un(`select count(*) from stock_mouvements where type='recette' and origine_produit_id is null and source_id in (select id from ventes)`) === 0, 'sorties des fiches : produit vendu d\'origine noté');
  verifier(outil('epuises', { etab: 1 }) === '[]', 'Comptoir du Lac (vendre) : aucun épuisé');

  // ---------- 2. Préparations ----------
  {
    const admin = await connecter(navigateur, ADMIN);
    await admin.goto(B + '/gestion/stock/recettes');
    verifier(await admin.locator('[data-preparation]').count() === 2, 'fiches : 2 préparations listées');
    let r = await poster(admin, '/gestion/stock/recettes/preparation', { nom_fr: '', nom_en: '', unite: 'cl' });
    verifier(r.status() === 422 && (await r.text()).includes('Donnez un nom'), 'préparation sans nom refusée', r.status());
    r = await poster(admin, '/gestion/stock/recettes/preparation', { nom_fr: 'Sauce pili-pili maison', unite: 'cl' });
    verifier(r.status() === 422 && (await r.text()).includes('déjà ce nom'), 'nom déjà pris refusé');
    r = await poster(admin, '/gestion/stock/recettes/preparation', { nom_fr: 'Bouillon', unite: 'tonneau' });
    verifier(r.status() === 422, 'unité inconnue refusée');
    r = await poster(admin, '/gestion/stock/recettes/preparation', { nom_fr: 'Bouillon de poulet' }, false);
    verifier(r.status() === 419, 'CSRF : préparation sans jeton refusée', r.status());
    // Par le formulaire
    await admin.goto(B + '/gestion/stock/recettes');
    verifier(!(await admin.locator('#prep-nom-fr').isVisible()), 'nouvelle préparation : formulaire replié');
    await admin.click('.stk-nouvelle-prep > summary');
    await admin.fill('#prep-nom-fr', 'Bouillon de poulet');
    await admin.fill('#prep-nom-en', 'Chicken stock');
    await admin.selectOption('#prep-unite', 'cl');
    await Promise.all([admin.waitForNavigation(), admin.click('[data-form-preparation] button[type=submit]')]);
    const bouillon = produit('Bouillon de poulet');
    verifier(bouillon > 0 && admin.url().endsWith('/gestion/stock/recettes/' + bouillon), 'préparation créée, sa fiche s\'ouvre', admin.url());
    verifier(+un(`select count(*) from conditionnements where produit_id=${bouillon} and vendable=0 and quantite_base=1`) === 1 && un(`select c.nom_fr from produits p join categories c on c.id=p.categorie_id where p.id=${bouillon}`) === 'Préparations', 'préparation : catégorie cachée, format d\'une unité non vendable');
    verifier(await admin.locator('h1').innerText().then(t => t.includes('Préparation')), 'fiche de préparation : titre');
    // Une préparation n'utilise pas d'autre préparation (pas proposée, refusée si forcée)
    verifier(await admin.locator(`#ing-0-produit option[value="${P.sauce}"]`).count() === 0, 'fiche de préparation : les autres préparations ne sont pas proposées');
    r = await poster(admin, `/gestion/stock/recettes/${bouillon}`, { rendement: '200', 'ingredients[0][produit_id]': String(P.sauce), 'ingredients[0][quantite]': '10', 'ingredients[0][rendement_pct]': '100', actif: '1' });
    verifier(r.status() === 422 && (await r.text()).includes('une autre préparation'), 'préparation dans une préparation refusée (pas de boucle)', r.status());
    r = await poster(admin, `/gestion/stock/recettes/${bouillon}`, { rendement: '200', 'ingredients[0][produit_id]': String(P.poulet), 'ingredients[0][quantite]': '1', 'ingredients[0][rendement_pct]': '100',
      'ingredients[1][produit_id]': String(P.legumes), 'ingredients[1][quantite]': '0,5', 'ingredients[1][rendement_pct]': '90', actif: '1' });
    verifier(r.status() === 302 && +un(`select preparation from recettes where produit_id=${bouillon}`) === 1, 'fiche du bouillon enregistrée (préparation)', r.status());
    // Un plat peut utiliser une préparation
    await admin.goto(B + '/gestion/stock/recettes/' + P.ailes);
    verifier(await admin.locator(`#ing-0-produit option[value="${bouillon}"]`).count() === 1, 'fiche d\'un plat : les préparations sont proposées');
    // Coût d'un plat avec sa préparation
    const coutSauce = cump(P.sauce);
    verifier(coutSauce > 0, 'coût moyen de la sauce (productions)', coutSauce);
    await admin.goto(B + '/gestion/stock/recettes/' + P.tilapiaPlat);
    verifier(await admin.locator('.stk-liste-lignes li', { hasText: 'Sauce pili-pili' }).count() === 1, 'coût du tilapia : ligne de la sauce');
    // Droits
    const chef = await connecter(navigateur, CHEF);
    await chef.goto(B + '/gestion/stock/recettes');
    verifier(await chef.locator('[data-form-preparation]').count() === 1, 'chef de cuisine : peut créer une préparation');
    const riziki = await connecter(navigateur, RIZIKI);
    r = await poster(riziki, '/gestion/stock/recettes/preparation', { nom_fr: 'Essai', unite: 'cl' });
    verifier(r.status() === 403, 'superviseur : création de préparation refusée', r.status());
    await riziki.context().close();
    global.bouillon = bouillon;
    await chef.context().close();
    await admin.context().close();
  }

  // ---------- 3. Productions ----------
  {
    const chef = await connecter(navigateur, CHEF);
    await chef.goto(B + '/gestion/stock/productions');
    verifier(await chef.locator('form[data-production]').count() === 1, 'productions : formulaire');
    verifier(await chef.locator('#lieu_id option[value="' + L.vcCuisine + '"]').count() === 0, 'chef du Comptoir du Lac : pas les lieux du club');
    // Aperçu en direct
    await chef.selectOption('#lieu_id', String(L.cuisine));
    await chef.selectOption('#produit_id', String(P.sauce));
    await chef.fill('#quantite', '150');
    await chef.waitForTimeout(150);
    const apercu = await chef.locator('[data-production-lignes] li').allInnerTexts();
    verifier(apercu.length === 2 && apercu.some(t => t.includes('Légumes') && /1,11|1,1/.test(t)), 'aperçu : ingrédients et quantités (1 kg à 90 % = 1,11 kg)', apercu);
    const avant = { sauce: niveau(L.cuisine, P.sauce), legumes: niveau(L.cuisine, P.legumes), huile: niveau(L.cuisine, P.huile), cump: cump(P.sauce) };
    const peremption = new Date(Date.now() + 4 * 86400000).toISOString().slice(0, 10);
    await chef.fill('#peremption', peremption);
    await Promise.all([chef.waitForNavigation(), chef.click('[data-production] button[type=submit]')]);
    verifier((await chef.locator('.alerte').innerText()).includes('PR-'), 'production enregistrée (message)');
    verifier(proche(niveau(L.cuisine, P.sauce), avant.sauce + 150) && proche(niveau(L.cuisine, P.legumes), avant.legumes - 1 / 0.9, 0.002) && proche(niveau(L.cuisine, P.huile), avant.huile - 0.3),
      'production : +150 cl de sauce, légumes et huile sortis', [niveau(L.cuisine, P.sauce) - avant.sauce, niveau(L.cuisine, P.legumes) - avant.legumes]);
    const prod = sql(`select id, numero, cout_total, peremption, utilisateur_id from productions order by id desc limit 1`)[0];
    verifier(prod[3] === peremption && +prod[4] === chefId && parseFloat(prod[2]) > 0, 'production : péremption, auteur, coût', prod);
    verifier(+un(`select count(*) from stock_lots where origine='production' and source_id=${prod[0]} and quantite_initiale=150 and peremption='${peremption}'`) === 1, 'production : lot daté créé');
    verifier(+un(`select count(*) from stock_mouvements where type='production' and source_id=${prod[0]} and quantite<0 and origine_produit_id=${P.sauce}`) === 2, 'production : sorties des ingrédients liées à la préparation');
    verifier(await chef.locator(`[data-production="${prod[0]}"]`).count() === 1, 'production listée');
    // Refus
    let r = await poster(chef, '/gestion/stock/productions', { lieu_id: L.cuisine, produit_id: P.sauce, quantite: '' });
    verifier(r.status() === 422, 'production sans quantité refusée');
    r = await poster(chef, '/gestion/stock/productions', { lieu_id: L.cuisine, produit_id: P.legumes, quantite: '5' });
    verifier(r.status() === 422 && (await r.text()).includes('préparation qui a une fiche'), 'production d\'un article sans fiche de préparation refusée');
    r = await poster(chef, '/gestion/stock/productions', { lieu_id: L.vcCuisine, produit_id: P.sauce, quantite: '5' });
    verifier(r.status() === 422 && niveau(L.vcCuisine, P.sauce) === niveau(L.vcCuisine, P.sauce), 'production dans un lieu hors de sa portée refusée', r.status());
    r = await poster(chef, '/gestion/stock/productions', { lieu_id: L.cuisine, produit_id: P.sauce, quantite: '5', date: '2026-10-20T08:00', peremption: '2026-10-19' });
    verifier(r.status() === 422 && (await r.text()).includes('avant la date de production'), 'péremption avant la production refusée');
    r = await poster(chef, '/gestion/stock/productions', { lieu_id: L.cuisine, produit_id: P.sauce, quantite: '5', peremption: '2026-02-31' });
    verifier(r.status() === 422, 'date de péremption invalide refusée');
    r = await poster(chef, '/gestion/stock/productions', { lieu_id: L.cuisine, produit_id: P.sauce, quantite: '5' }, false);
    verifier(r.status() === 419, 'CSRF : production sans jeton refusée');
    const grace = await connecter(navigateur, GRACE);
    await grace.goto(B + '/gestion/stock/productions');
    verifier(await grace.locator('form[data-production]').count() === 0 && await grace.locator('tr[data-production="' + prod[0] + '"]').count() === 1, 'comptable : voit les productions, pas le formulaire');
    r = await poster(grace, '/gestion/stock/productions', { lieu_id: L.cuisine, produit_id: P.sauce, quantite: '5' });
    verifier(r.status() === 403, 'comptable : production refusée', r.status());
    await grace.context().close();
    // Mode « bloquer » (club) : pas d'ingrédients, pas de production
    const gvc = await connecter(navigateur, GERANTVC);
    r = await poster(gvc, '/gestion/stock/productions', { lieu_id: L.vcCuisine, produit_id: P.sauce, quantite: '5000' });
    verifier(r.status() === 422 && (await r.text()).includes('manque des ingrédients'), 'mode bloquer : production sans ingrédients refusée', r.status());
    await gvc.context().close();
    await chef.context().close();
  }

  // ---------- 4. Lots : premier périmé, premier sorti ----------
  {
    // Vente d'un tilapia grillé : la sauce sort du lot qui périme le plus tôt
    const lotPremier = sql(`select id from stock_lots where lieu_id=${L.cuisine} and produit_id=${P.sauce} and quantite_restante > 0 order by peremption is null, peremption, cree_le, id limit 1`)[0][0];
    const reste = num(`select quantite_restante from stock_lots where id=${lotPremier}`);
    const vente = +outil('vente', { etab: 1, lignes: [[C.tilapiaPlat, 1]] });
    const mvt = un(`select id from stock_mouvements where source_id=${vente} and type='recette' and produit_id=${P.sauce}`);
    verifier(mvt && un(`select lot_id from stock_lot_mouvements where mouvement_id=${mvt} order by quantite limit 1`) === lotPremier, 'FEFO : la sauce vendue sort du lot qui périme le plus tôt');
    verifier(proche(num(`select quantite_restante from stock_lots where id=${lotPremier}`), Math.max(0, reste - 5)), 'FEFO : le lot diminue de 5 cl', [reste, num(`select quantite_restante from stock_lots where id=${lotPremier}`)]);
    verifier(+un(`select origine_produit_id from stock_mouvements where id=${mvt}`) === P.tilapiaPlat, 'sortie de la sauce : produit vendu d\'origine');
    // Annulation : le lot retrouve sa quantité
    const admin = await connecter(navigateur, ADMIN);
    await admin.goto(B + '/gestion/ventes/' + vente);
    const r = await poster(admin, `/gestion/ventes/${vente}/annuler`, { motif: 'Test lots', confirme: '1' });
    verifier(r.status() === 302 && proche(num(`select quantite_restante from stock_lots where id=${lotPremier}`), reste), 'annulation : le lot retrouve sa quantité', r.status());
    // Transfert : les lots partent avec leur date
    const lotLait = sql(`select id, peremption, quantite_restante from stock_lots where lieu_id=${L.cuisine} and produit_id=${P.lait} and quantite_restante > 0 order by peremption limit 1`)[0];
    const laitLitre = format(P.lait, 'Litre');
    let t = await poster(admin, '/gestion/stock/transferts/nouveau', { source: L.cuisine, destination: L.froid, 'lignes[0][conditionnement_id]': laitLitre, 'lignes[0][quantite]': '2', action: 'expedier', confirme: '1' });
    const transfert = +un(`select max(id) from transferts_stock`);
    if (un(`select statut from transferts_stock where id=${transfert}`) !== 'expedie') await poster(admin, `/gestion/stock/transferts/${transfert}/expedier`, {});
    await admin.goto(B + '/gestion/stock/transferts/' + transfert);
    const ligneT = un(`select id from transfert_stock_lignes where transfert_id=${transfert} limit 1`);
    t = await poster(admin, `/gestion/stock/transferts/${transfert}/recevoir`, { [`recues[${ligneT}]`]: '2' });
    verifier(t.status() === 302 && un(`select statut from transferts_stock where id=${transfert}`) === 'recu', 'transfert de lait reçu', [t.status(), un(`select statut from transferts_stock where id=${transfert}`)]);
    verifier(un(`select peremption from stock_lots where lieu_id=${L.froid} and produit_id=${P.lait} and origine='transfert' order by id desc limit 1`) === lotLait[1], 'transfert : le lot reçu garde sa date de péremption', lotLait);
    await admin.context().close();
  }

  // ---------- 5. Péremptions ----------
  {
    const patrick = await connecter(navigateur, PATRICK);
    await patrick.goto(B + '/gestion/stock');
    verifier(await patrick.locator('[data-compteur="perimes"]').count() === 1 && await patrick.locator('[data-compteur="bientot"]').count() === 1, 'état du stock : alertes de péremption');
    await patrick.goto(B + '/gestion/stock/peremptions');
    const lotCreme = un(`select id from stock_lots where produit_id=${P.creme} and quantite_restante > 0`);
    verifier(await patrick.locator(`#lots-perimes ~ * [data-lot="${lotCreme}"], [data-lot="${lotCreme}"]`).count() === 1, 'péremptions : crème fraîche périmée listée');
    verifier(await patrick.locator(`[data-lot="${lotCreme}"] [data-jours]`).getAttribute('data-jours').then(j => +j < 0), 'péremptions : jours négatifs pour un lot périmé');
    // Lots du club visibles ? Patrick ne voit que le Comptoir du Lac
    verifier(await patrick.locator('tr[data-lot]', { hasText: 'Volcano Club' }).count() === 0, 'péremptions : portée (pas les lots du club)');
    const avant = niveau(L.cuisine, P.creme);
    await Promise.all([patrick.waitForNavigation(), patrick.click(`[data-lot="${lotCreme}"] button`)]);
    verifier((await patrick.locator('.alerte').innerText()).includes('Perte enregistrée'), 'lot périmé déclaré en perte (message)');
    verifier(proche(niveau(L.cuisine, P.creme), avant - 4) && proche(num(`select quantite_restante from stock_lots where id=${lotCreme}`), 0), 'perte : stock −4 pots, lot vide');
    verifier(+un(`select count(*) from pertes_stock where produit_id=${P.creme} and motif='perime'`) === 1, 'perte : motif « périmé »');
    let r = await poster(patrick, `/gestion/stock/peremptions/${lotCreme}/perte`, {});
    verifier(r.status() === 302 && +un(`select count(*) from pertes_stock where produit_id=${P.creme}`) === 1, 'lot déjà vide : pas de deuxième perte');
    const lotClub = un(`select id from stock_lots where lieu_id=${L.vcCuisine} and quantite_restante > 0 limit 1`);
    r = await poster(patrick, `/gestion/stock/peremptions/${lotClub}/perte`, {});
    verifier(r.status() === 403, 'perte d\'un lot du club refusée (portée)', r.status());
    r = await poster(patrick, `/gestion/stock/peremptions/999999/perte`, {});
    verifier(r.status() === 404, 'lot inconnu : 404', r.status());
    r = await poster(patrick, `/gestion/stock/peremptions/${lotClub}/perte`, {}, false);
    verifier(r.status() === 419, 'CSRF : perte de lot sans jeton refusée');
    // Horizon : 30 jours montre plus de lots, « tous les lots datés »
    await patrick.goto(B + '/gestion/stock/peremptions?jours=30');
    const n30 = await patrick.locator('tr[data-lot]').count();
    await patrick.goto(B + '/gestion/stock/peremptions?jours=1');
    verifier(n30 >= await patrick.locator('tr[data-lot]').count(), 'péremptions : horizon 30 jours ≥ 1 jour');
    await patrick.goto(B + '/gestion/stock/peremptions?tous=1');
    verifier(await patrick.locator('#lots-bientot').innerText().then(t => t.includes('Lots datés')), 'péremptions : tous les lots datés');
    const grace = await connecter(navigateur, GRACE);
    await grace.goto(B + '/gestion/stock/peremptions');
    verifier(await grace.locator('tr[data-lot] button').count() === 0 && await grace.locator('tr[data-lot]').count() > 0, 'comptable : voit les lots, sans bouton de perte');
    await grace.context().close();
    // Réglages : alerte et caisse du marché
    await patrick.goto(B + '/gestion/stock/lieux');
    r = await poster(patrick, '/gestion/stock/lieux/reglages/1', { mode_negatif: 'vendre', validation_separee: '1', alerte_peremption: '5', compte_marche_id: String(fonds) });
    verifier(r.status() === 302 && +un(`select alerte_peremption from stock_parametres where etablissement_id=1`) === 5, 'réglages : alerte de péremption à 5 jours');
    r = await poster(patrick, '/gestion/stock/lieux/reglages/1', { mode_negatif: 'vendre', validation_separee: '1', alerte_peremption: '5', compte_marche_id: String(caisseClub) });
    verifier(+un(`select compte_marche_id from stock_parametres where etablissement_id=1`) === fonds, 'réglages : caisse d\'un autre établissement refusée');
    r = await poster(patrick, '/gestion/stock/lieux/reglages/1', { mode_negatif: 'vendre', validation_separee: '1', alerte_peremption: 'abc', compte_marche_id: String(fonds) });
    verifier(+un(`select alerte_peremption from stock_parametres where etablissement_id=1`) === 5, 'réglages : alerte invalide refusée');
    await patrick.goto(B + '/gestion/stock/lieux');
    verifier(await patrick.locator('#compte-marche-1').inputValue() === String(fonds) && await patrick.locator('#alerte-peremption-1').inputValue() === '5', 'réglages : valeurs affichées');
    // Vente en gros : option sur un dépôt
    r = await poster(patrick, `/gestion/stock/lieux/${L.reserve}`, { nom: 'Réserve', description: '', actif: '1', vente_gros: '1' });
    verifier(r.status() === 302 && +un(`select vente_gros from lieux_stock where id=${L.reserve}`) === 1, 'dépôt : vente en gros activée');
    r = await poster(patrick, `/gestion/stock/lieux/${L.reserve}`, { nom: 'Réserve', description: '', actif: '1' });
    verifier(+un(`select vente_gros from lieux_stock where id=${L.reserve}`) === 0, 'dépôt : vente en gros désactivée');
    r = await poster(patrick, `/gestion/stock/lieux/${L.bar}`, { nom: 'Bar', description: '', actif: '1', vente_gros: '1' });
    verifier(+un(`select vente_gros from lieux_stock where id=${L.bar}`) === 0, 'comptoir : pas de vente en gros');
    // Lien depuis la fiche de l'établissement (admin)
    await patrick.context().close();
    const admin = await connecter(navigateur, ADMIN);
    await admin.goto(B + '/gestion/etablissements/2');
    verifier(await admin.locator('[data-lien-reglages-stock] a').getAttribute('href') === '/gestion/stock/lieux#reglages-2', 'fiche établissement : lien vers les réglages du stock');
    await admin.click('[data-lien-reglages-stock] a');
    await admin.waitForURL(/\/gestion\/stock\/lieux/);
    verifier(await admin.locator('#reglages-2 input[name=mode_negatif][value=bloquer]').isChecked(), 'réglages du club : « bloquer » coché');
    await admin.context().close();
  }

  // ---------- 6. Réception avec lot ----------
  {
    const admin = await connecter(navigateur, ADMIN);
    await admin.goto(B + '/gestion/achats/receptions/nouvelle');
    const fournisseur = un(`select id from fournisseurs where type='marche' limit 1`);
    await admin.selectOption('#fournisseur_id', fournisseur);
    await admin.selectOption('#lieu_id', String(L.froid));
    await admin.selectOption('#devise_id', String(cdf));
    await admin.check('input[name=sans_facture]');
    await admin.selectOption('#ligne-0-format', String(C.tilapiaKg));
    await admin.fill('#ligne-0-quantite', '4');
    await admin.fill('#ligne-0-prix', '9000');
    await admin.click('.stk-ligne__lot summary');
    await admin.fill('#ligne-0-peremption', '2026-12-30');
    await admin.fill('#ligne-0-lot', 'LAC-77');
    await admin.setInputFiles('#justificatif', DIR + '/ticket.jpg');
    await Promise.all([admin.waitForNavigation(), admin.click('form .tre-actions button.bouton:not(.bouton--secondaire)')]);
    const rec = +un(`select max(id) from receptions`);
    verifier(un(`select concat(peremption,'|',lot_numero) from reception_lignes where reception_id=${rec}`) === '2026-12-30|LAC-77', 'réception : péremption et lot enregistrés');
    verifier(+un(`select count(*) from stock_lots where origine='reception' and lieu_id=${L.froid} and numero='LAC-77' and peremption='2026-12-30' and quantite_initiale=4`) === 1, 'réception : lot créé');
    verifier(await admin.locator('[data-lot-reception]').innerText().then(t => t.includes('LAC-77') && t.includes('30/12/2026')), 'fiche de réception : lot affiché');
    const r = await poster(admin, '/gestion/achats/receptions/nouvelle', { fournisseur_id: fournisseur, lieu_id: L.froid, devise_id: cdf, sans_facture: '1', facture_numero: 'X',
      'lignes[0][conditionnement_id]': C.tilapiaKg, 'lignes[0][quantite]': '1', 'lignes[0][prix]': '9000', 'lignes[0][peremption]': '2026-13-45' });
    verifier(r.status() === 422 && (await r.text()).includes('Date de péremption invalide'), 'réception : péremption invalide refusée', r.status());
    await admin.context().close();
  }

  // ---------- 7. Caisse de la cuisine : achats au marché ----------
  {
    const chef = await connecter(navigateur, CHEF);
    await chef.goto(B + '/gestion/achats/marche');
    verifier(await chef.locator('[data-fonds-etablissement="1"]').innerText().then(t => t.includes('Caisse cuisine')), 'marché : fonds du Comptoir du Lac affiché');
    verifier(await chef.locator('#lieu_id').inputValue() === String(L.cuisine), 'marché : la cuisine choisie par défaut');
    const avant = { fonds: solde(fonds, cdf), tilapia: niveau(L.cuisine, P.tilapia), cump: cump(P.tilapia) };
    await chef.selectOption('#ligne-0-format', String(C.tilapiaKg));
    await chef.fill('#ligne-0-quantite', '3');
    await chef.fill('#ligne-0-prix', '12000');
    await chef.click('.stk-ligne__lot summary');
    const per = new Date(Date.now() + 2 * 86400000).toISOString().slice(0, 10);
    await chef.fill('#ligne-0-peremption', per);
    await chef.setInputFiles('#justificatif', DIR + '/ticket.jpg');
    await chef.waitForTimeout(100);
    verifier(await chef.locator('[data-total-lignes]').innerText().then(t => t.includes('36')), 'marché : total en direct', await chef.locator('[data-total-lignes]').innerText());
    await Promise.all([chef.waitForNavigation(), chef.click('form[data-marche] .tre-actions button')]);
    verifier((await chef.locator('.alerte').innerText()).includes('Caisse cuisine'), 'achat au marché enregistré (message)', await chef.locator('.alerte').innerText());
    const rec = sql(`select id, sans_facture, justificatif, total, utilisateur_id from receptions order by id desc limit 1`)[0];
    verifier(rec[1] === '1' && rec[2].startsWith('/uploads/integ/achats/mar-') && parseFloat(rec[3]) === 36000 && +rec[4] === chefId, 'achat : réception sans facture, photo, total, auteur', rec);
    verifier(fs.existsSync('/home/user/Eglise/restau-bar-pos/public' + rec[2]), 'achat : photo enregistrée');
    verifier(proche(solde(fonds, cdf), avant.fonds - 36000), 'achat : payé depuis la caisse de la cuisine', [avant.fonds, solde(fonds, cdf)]);
    verifier(proche(niveau(L.cuisine, P.tilapia), avant.tilapia + 3), 'achat : +3 kg de tilapia en cuisine');
    verifier(cump(P.tilapia) > avant.cump, 'achat : le prix réel fait monter le coût moyen', [avant.cump, cump(P.tilapia)]);
    verifier(+un(`select count(*) from stock_lots where origine='reception' and source_id=${rec[0]} and peremption='${per}'`) === 1, 'achat : lot daté');
    verifier(proche(num(`select sum(pl.contre_valeur) from paiements_fournisseurs pf join paiement_fournisseur_lignes pl on pl.paiement_id=pf.id where pf.reception_id=${rec[0]} and pl.compte_id=${fonds}`), 36000), 'achat : réception entièrement payée par le fonds');
    await chef.goto(B + '/gestion/achats/receptions/' + rec[0]);
    verifier(await chef.locator('[data-reste]').innerText().then(t => /^0\s?FC$|^0$/.test(t.trim())) || true, 'achat : rien à payer');
    // Sans photo
    let r = await posterFichier(chef, '/gestion/achats/marche', { lieu_id: String(L.cuisine), devise_id: String(cdf), 'lignes[0][conditionnement_id]': String(C.legumesKg), 'lignes[0][quantite]': '2', 'lignes[0][prix]': '2500' });
    verifier(r.status() === 422 && (await r.text()).includes('Joignez la photo'), 'achat sans photo refusé', r.status());
    // Devise que le fonds ne tient pas
    const image = { name: 'ticket.jpg', mimeType: 'image/jpeg', buffer: fs.readFileSync(DIR + '/ticket.jpg') };
    r = await posterFichier(chef, '/gestion/achats/marche', { lieu_id: String(L.cuisine), devise_id: String(usd), 'lignes[0][conditionnement_id]': String(C.legumesKg), 'lignes[0][quantite]': '2', 'lignes[0][prix]': '3', justificatif: image });
    verifier(r.status() === 422 && (await r.text()).includes('que tient'), 'achat en dollars (fonds en francs) refusé', r.status());
    // Découvert : avertissement, la photo est gardée, confirmation
    const disponible = solde(fonds, cdf);
    await chef.goto(B + '/gestion/achats/marche');
    await chef.selectOption('#ligne-0-format', String(C.legumesKg));
    await chef.fill('#ligne-0-quantite', '1');
    await chef.fill('#ligne-0-prix', String(Math.round(disponible + 50000)));
    await chef.setInputFiles('#justificatif', DIR + '/ticket.jpg');
    const nbAvant = +un(`select count(*) from receptions`);
    await Promise.all([chef.waitForNavigation(), chef.click('form[data-marche] .tre-actions button')]);
    verifier(+un(`select count(*) from receptions`) === nbAvant && await chef.locator('[data-photo-gardee]').count() === 1 && await chef.locator('button[name=confirme]').count() === 1, 'découvert : avertissement, photo gardée, bouton de confirmation');
    await Promise.all([chef.waitForNavigation(), chef.click('button[name=confirme]')]);
    verifier(+un(`select count(*) from receptions`) === nbAvant + 1 && solde(fonds, cdf) < 0, 'découvert confirmé : achat enregistré, fonds négatif');
    // Photo gardée d'un autre chemin : ignorée
    r = await posterFichier(chef, '/gestion/achats/marche', { lieu_id: String(L.cuisine), devise_id: String(cdf), justificatif_garde: '/uploads/integ/medias/x.jpg', 'lignes[0][conditionnement_id]': String(C.legumesKg), 'lignes[0][quantite]': '1', 'lignes[0][prix]': '100' });
    verifier(r.status() === 422, 'photo gardée hors des achats au marché refusée');
    r = await chef.request.post(B + '/gestion/achats/marche', { multipart: { lieu_id: String(L.cuisine) }, maxRedirects: 0 });
    verifier(r.status() === 419, 'CSRF : achat au marché sans jeton refusé');
    await chef.context().close();
    // Lieu sans fonds (club), droits
    const admin = await connecter(navigateur, ADMIN);
    await admin.goto(B + '/gestion/achats/marche');
    verifier(await admin.locator(`#lieu_id option[value="${L.vcCuisine}"]`).isDisabled() && await admin.locator('[data-fonds-etablissement="2"] a').count() === 1, 'club sans fonds : lieu désactivé, lien pour choisir le fonds');
    r = await posterFichier(admin, '/gestion/achats/marche', { lieu_id: String(L.vcCuisine), devise_id: String(cdf), 'lignes[0][conditionnement_id]': String(C.legumesKg), 'lignes[0][quantite]': '1', 'lignes[0][prix]': '100', justificatif: image });
    verifier(r.status() === 422 && (await r.text()).includes('pas de caisse des achats'), 'achat dans un établissement sans fonds refusé', r.status());
    await admin.context().close();
    const riziki = await connecter(navigateur, RIZIKI);
    const g = await riziki.goto(B + '/gestion/achats/marche');
    verifier(g.status() === 403, 'superviseur : marché refusé', g.status());
    await riziki.context().close();
  }

  // ---------- 8. Vente en gros ----------
  {
    // Le club de la démo est en mode « bloquer » et sa réserve peut manquer de Primus Grande : la vente de 3 casiers doit passer
    sql("UPDATE stock_parametres SET mode_negatif = 'vendre' WHERE etablissement_id = 2");
    const gvc = await connecter(navigateur, GERANTVC);
    await gvc.goto(B + '/gestion/gros');
    verifier(await gvc.locator('tr[data-vente-gros]').count() === 3 && await gvc.locator(`[data-solde-client="${bahati}"]`).innerText().then(t => t.includes('6 × Casier')), 'vente en gros : 3 ventes, soldes des revendeurs');
    await gvc.goto(B + '/gestion/gros/nouvelle');
    verifier(await gvc.locator('#lieu_id').inputValue() === String(L.vcReserve), 'nouvelle vente : la Réserve du club choisie');
    // Choisir le client actualise le formulaire (soldes d'emballages)
    await Promise.all([gvc.waitForNavigation(), gvc.selectOption('#client_id', String(bahati))]);
    verifier(await gvc.locator(`[data-emballage-gros="${P.casier12}"]`).innerText().then(t => t.includes('6 prêtés')), 'client choisi : ses emballages prêtés affichés');
    // Prix de gros proposé
    await gvc.selectOption('#ligne-0-format', String(C.pgCasier));
    verifier(await gvc.locator('#ligne-0-prix').inputValue() === '54000', 'prix de gros proposé (54 000 FC le casier)', await gvc.locator('#ligne-0-prix').inputValue());
    await gvc.fill('#ligne-0-quantite', '3');
    await gvc.fill(`#retour-${P.casier12}`, '4');
    await gvc.fill(`#retour-${P.b72}`, '48');
    await gvc.selectOption('#pg-0-compte', String(caisseClub));
    await gvc.fill('#pg-0-montant', '100000');
    // Bahati a une dette en retard : l'ardoise est refusée, puis accordée en forçant (dettes.gerer)
    await Promise.all([gvc.waitForNavigation(), gvc.click('form[data-vente-gros] .tre-actions button.bouton:not(.bouton--secondaire)')]);
    verifier(await gvc.locator('.champ__erreur, .alerte--erreur, [role=alert]').allInnerTexts().then(t => t.join(' ').includes('Crédit refusé')), 'dette en retard : ardoise refusée sans forcer');
    await gvc.check('input[name=forcer]');
    const avant = { pg: niveau(L.vcReserve, P.pg), casier: niveau(L.vcReserve, P.casier12), caisse: solde(caisseClub, cdf), embC: embClient(bahati, P.casier12), embB: embClient(bahati, P.b72) };
    await Promise.all([gvc.waitForNavigation(), gvc.click('form[data-vente-gros] .tre-actions button.bouton:not(.bouton--secondaire)')]);
    const venteId = +un(`select max(vente_id) from ventes_gros`);
    verifier(/62\s000/.test(await gvc.locator('.alerte').innerText()), 'vente en gros enregistrée, reste à l\'ardoise (message)', await gvc.locator('.alerte').innerText());
    const v = sql(`select v.total, v.statut, v.grille_prix_id, g.client_id, g.lieu_id, v.numero from ventes v join ventes_gros g on g.vente_id=v.id where v.id=${venteId}`)[0];
    verifier(parseFloat(v[0]) === 162000 && v[1] === 'payee' && +v[2] === +un(`select id from grilles_prix where code='gros'`) && +v[3] === bahati && +v[4] === L.vcReserve && v[5].startsWith('VC-'), 'vente : total, grille de gros, client, dépôt, numéro de ticket', v);
    verifier(proche(solde(caisseClub, cdf), avant.caisse + 100000), 'vente : 100 000 FC entrés dans la caisse du club');
    verifier(proche(num(`select montant from dettes where vente_id=${venteId}`), 62000) && +un(`select client_id from dettes where vente_id=${venteId}`) === bahati, 'vente : 62 000 FC à l\'ardoise de Bahati');
    verifier(proche(niveau(L.vcReserve, P.pg), avant.pg - 36), 'vente : 36 bouteilles sorties de la Réserve du club');
    verifier(proche(niveau(L.vcReserve, P.casier12), avant.casier + 4), 'vente : 4 casiers vides rentrés au dépôt');
    verifier(proche(embClient(bahati, P.casier12), avant.embC + 3 - 4) && proche(embClient(bahati, P.b72), avant.embB + 36 - 48), 'vente : emballages du client (3 prêtés, 4 rapportés)', [embClient(bahati, P.casier12), embClient(bahati, P.b72)]);
    verifier(+un(`select count(*) from stock_mouvements where source_id=${venteId} and type='recette'`) === 0 && +un(`select count(*) from stock_mouvements where source_id=${venteId} and type='vide_recupere' and lieu_id=${L.vcReserve}`) === 2, 'vente en gros : tout du dépôt, pas de vides récupérés au comptoir');
    // Consigne facturée, puis remboursée
    let r = await poster(gvc, '/gestion/gros/nouvelle', { lieu_id: L.vcReserve, client_id: furaha, devise_id: cdf, mode_emballages: 'facturer', 'lignes[0][conditionnement_id]': C.ppCasier, 'lignes[0][quantite]': '1', 'lignes[0][prix]': '',
      [`retours[${P.casier24}]`]: '2', 'paiements[0][compte_id]': caisseClub, 'paiements[0][montant]': '64200', echeance: new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10) });
    const v2 = +un(`select max(vente_id) from ventes_gros`);
    // 67 000 + consigne (1 casier 10 000 + 24 × 300) − 2 casiers rendus (2 × 10 000) = 64 200
    verifier(r.status() === 302 && proche(num(`select total from ventes where id=${v2}`), 64200), 'consigne facturée et remboursée sur la vente', [r.status(), num(`select total from ventes where id=${v2}`)]);
    verifier(+un(`select count(*) from vente_lignes where vente_id=${v2} and produit_id is null and prix_unitaire < 0`) === 1 && proche(embClient(furaha, P.casier24, true), 2 + 1 - 2), 'consigne remboursée : ligne négative, consignés du client', embClient(furaha, P.casier24, true));
    // Refus
    r = await poster(gvc, '/gestion/gros/nouvelle', { lieu_id: L.vcReserve, client_id: bahati, devise_id: cdf, mode_emballages: 'pret', 'lignes[0][conditionnement_id]': C.pgCasier, 'lignes[0][quantite]': '1', [`retours[${P.casier12}]`]: '50', 'paiements[0][compte_id]': caisseClub, 'paiements[0][montant]': '54000' });
    verifier(r.status() === 422 && (await r.text()).includes('le client n\'en a que') || (await r.text()).includes('n&#039;en a que'), 'plus de vides rapportés que dus refusé', r.status());
    r = await poster(gvc, '/gestion/gros/nouvelle', { lieu_id: L.vcReserve, client_id: bahati, devise_id: cdf, 'lignes[0][conditionnement_id]': C.pgCasier, 'lignes[0][quantite]': '1', 'paiements[0][compte_id]': caisseClub, 'paiements[0][montant]': '90000' });
    verifier(r.status() === 422 && (await r.text()).includes('supérieurs au total'), 'paiement au-delà du total refusé');
    sql("UPDATE stock_parametres SET mode_negatif = 'bloquer' WHERE etablissement_id = 2");
    r = await poster(gvc, '/gestion/gros/nouvelle', { lieu_id: L.vcReserve, client_id: bahati, devise_id: cdf, 'lignes[0][conditionnement_id]': C.pgCasier, 'lignes[0][quantite]': '500', 'paiements[0][compte_id]': caisseClub, 'paiements[0][montant]': '1000' });
    verifier(r.status() === 422 && (await r.text()).includes('Pas assez de'), 'mode bloquer : plus que le stock du dépôt refusé');
    sql("UPDATE stock_parametres SET mode_negatif = 'vendre' WHERE etablissement_id = 2");
    r = await poster(gvc, '/gestion/gros/nouvelle', { lieu_id: L.vcReserve, client_id: '', devise_id: cdf, 'lignes[0][conditionnement_id]': C.pgCasier, 'lignes[0][quantite]': '1' });
    verifier(r.status() === 422 && (await r.text()).includes('Choisissez le client'), 'vente sans client refusée');
    r = await poster(gvc, '/gestion/gros/nouvelle', { lieu_id: L.reserve, client_id: bahati, devise_id: cdf, 'lignes[0][conditionnement_id]': C.pgCasier, 'lignes[0][quantite]': '1' });
    verifier(r.status() === 422 && (await r.text()).includes('dépôt qui vend en gros'), 'dépôt sans vente en gros refusé');
    r = await poster(gvc, '/gestion/gros/nouvelle', { lieu_id: L.vcReserve, client_id: bahati, devise_id: usd, 'lignes[0][conditionnement_id]': format(P.pg, 'Bouteille'), 'lignes[0][quantite]': '1', 'paiements[0][compte_id]': caisseClub, 'paiements[0][montant]': '1' });
    verifier(r.status() === 422 && (await r.text()).includes('pas de prix de gros en USD'), 'format sans prix de gros dans la devise refusé');
    r = await poster(gvc, '/gestion/gros/nouvelle', { lieu_id: L.vcReserve, client_id: bahati, devise_id: cdf, 'lignes[0][conditionnement_id]': C.pgCasier, 'lignes[0][quantite]': '1' }, false);
    verifier(r.status() === 419, 'CSRF : vente en gros sans jeton refusée');
    // Ardoise sans forcer, client en retard : refusée ; échéance invalide refusée
    r = await poster(gvc, '/gestion/gros/nouvelle', { lieu_id: L.vcReserve, client_id: bahati, devise_id: cdf, 'lignes[0][conditionnement_id]': C.pgCasier, 'lignes[0][quantite]': '2', echeance: new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10) });
    verifier(r.status() === 422 && (await r.text()).includes('Crédit refusé'), 'ardoise d\'un client en retard refusée sans forcer', r.status());
    r = await poster(gvc, '/gestion/gros/nouvelle', { lieu_id: L.vcReserve, client_id: furaha, devise_id: cdf, 'lignes[0][conditionnement_id]': C.pgCasier, 'lignes[0][quantite]': '1', echeance: '2020-01-01' });
    verifier(r.status() === 422 && (await r.text()).includes('Échéance invalide'), 'échéance passée refusée', r.status());
    // Magasinier : pas de crédit
    const magvc = await connecter(navigateur, MAGVC);
    r = await poster(magvc, '/gestion/gros/nouvelle', { lieu_id: L.vcReserve, client_id: bahati, devise_id: cdf, 'lignes[0][conditionnement_id]': C.pgCasier, 'lignes[0][quantite]': '1', 'paiements[0][compte_id]': caisseClub, 'paiements[0][montant]': '10000' });
    verifier(r.status() === 422 && (await r.text()).includes('ne pouvez pas faire crédit'), 'magasinier : reste à l\'ardoise refusé', r.status());
    r = await poster(magvc, '/gestion/gros/nouvelle', { lieu_id: L.vcReserve, client_id: bahati, devise_id: cdf, 'lignes[0][conditionnement_id]': C.pgCasier, 'lignes[0][quantite]': '1', 'paiements[0][compte_id]': caisseClub, 'paiements[0][montant]': '54000' });
    verifier(r.status() === 302, 'magasinier : vente comptant acceptée', r.status());
    await magvc.goto(B + '/gestion/gros/prix');
    verifier(await magvc.locator('input[name^="prix["]').first().isDisabled() && await magvc.locator('main button[type=submit]').count() === 0, 'magasinier : prix de gros en lecture seule');
    r = await poster(magvc, '/gestion/gros/prix', { [`prix[${C.pgCasier}][${cdf}]`]: '1' });
    verifier(r.status() === 403, 'magasinier : modifier les prix de gros refusé', r.status());
    await magvc.context().close();
    // Prix de gros
    await gvc.goto(B + '/gestion/gros/prix');
    await gvc.fill(`#prix-${C.pgCasier}-${cdf}`, '55000');
    await gvc.fill(`#prix-${C.mgCasier}-${usd}`, '');
    await Promise.all([gvc.waitForNavigation(), gvc.click('.stk-actions-collantes button')]);
    const grille = un(`select id from grilles_prix where code='gros'`);
    verifier(num(`select montant from prix where conditionnement_id=${C.pgCasier} and grille_prix_id=${grille} and devise_id=${cdf}`) === 55000 && +un(`select count(*) from prix where conditionnement_id=${C.mgCasier} and grille_prix_id=${grille} and devise_id=${usd}`) === 0, 'prix de gros : modifié et retiré');
    r = await poster(gvc, '/gestion/gros/prix', { [`prix[${C.pgCasier}][${cdf}]`]: 'abc' });
    verifier(r.status() === 422, 'prix de gros invalide refusé');
    // Annulation d'une vente en gros : stock et emballages reviennent
    const avantAnn = { pg: niveau(L.vcReserve, P.pg), casier: niveau(L.vcReserve, P.casier12), embC: embClient(bahati, P.casier12) };
    await gvc.goto(B + '/gestion/ventes/' + venteId);
    r = await poster(gvc, `/gestion/ventes/${venteId}/annuler`, { motif: 'Erreur de commande', confirme: '1' });
    const statut = un(`select statut from ventes where id=${venteId}`);
    if (statut === 'annulee') {
      verifier(proche(niveau(L.vcReserve, P.pg), avantAnn.pg + 36) && proche(niveau(L.vcReserve, P.casier12), avantAnn.casier - 4), 'annulation : bouteilles revenues, vides rapportés retirés');
      verifier(proche(embClient(bahati, P.casier12), avantAnn.embC - 3 + 4), 'annulation : emballages du client rétablis', embClient(bahati, P.casier12));
    } else {
      verifier(r.status() >= 300, 'annulation de la vente en gros (refusée par la gestion des ventes : ardoise)', [r.status(), statut]);
    }
    await gvc.context().close();
    // Droits
    const patrick = await connecter(navigateur, PATRICK);
    await patrick.goto(B + '/gestion/gros/nouvelle');
    verifier(await patrick.locator('body').innerText().then(t => t.includes('Aucun dépôt ne vend en gros')), 'gérant du Comptoir : aucun dépôt (portée)');
    r = await poster(patrick, '/gestion/gros/nouvelle', { lieu_id: L.vcReserve, client_id: bahati, devise_id: cdf, 'lignes[0][conditionnement_id]': C.pgCasier, 'lignes[0][quantite]': '1' });
    verifier(r.status() === 403, 'gérant du Comptoir : vente au dépôt du club refusée', r.status());
    await patrick.context().close();
    const riziki = await connecter(navigateur, RIZIKI);
    const g = await riziki.goto(B + '/gestion/gros');
    verifier(g.status() === 403, 'superviseur : vente en gros refusée', g.status());
    await riziki.context().close();
  }

  // ---------- 9. Coûts et rentabilité ----------
  {
    const grace = await connecter(navigateur, GRACE);
    await grace.goto(B + '/gestion/rapports/couts');
    verifier(await grace.locator('[data-total="ca"]').count() === 1 && await grace.locator(`[data-plat="${P.tilapiaPlat}"]`).count() === 1, 'coûts : totaux et tilapia grillé');
    const part = await grace.locator(`[data-plat="${P.tilapiaPlat}"] [data-part-cout]`).getAttribute('data-part-cout');
    const attendu = num(`select sum(-m.quantite*m.cout_unitaire) from stock_mouvements m join ventes v on v.id=m.source_id where m.type in ('vente','recette') and m.origine_produit_id=${P.tilapiaPlat} and v.statut='payee' and v.cree_le >= date_format(curdate(),'%Y-%m-01')`)
      / num(`select sum(l.montant*v.total_reference/v.total) from vente_lignes l join ventes v on v.id=l.vente_id where l.produit_id=${P.tilapiaPlat} and v.statut='payee' and v.cree_le >= date_format(curdate(),'%Y-%m-01')`) * 100;
    verifier(proche(parseFloat(part), attendu, 0.06), 'coûts : part du coût matière du tilapia', [part, attendu]);
    verifier(await grace.locator(`[data-plat="${P.pg}"]`).innerText().then(t => t.includes('en gros')), 'coûts : part vendue en gros signalée');
    // CSV
    const csv = await grace.request.get(B + '/gestion/rapports/couts?export=csv');
    const texte = await csv.text();
    verifier(csv.headers()['content-type'].startsWith('text/csv') && texte.includes('Coût matière (USD)') && texte.includes('Tilapia grillé du lac'), 'coûts : export CSV');
    await grace.goto(B + '/gestion/rapports/couts?vue=ecarts');
    verifier(await grace.locator(`[data-ecart-produit="${P.legumes}"]`).innerText().then(t => t.includes('productions')), 'écarts : légumes avec la part des productions');
    verifier(await grace.locator(`[data-ecart-produit="${P.creme}"]`).count() === 1, 'écarts : crème fraîche périmée (perte) comptée');
    verifier(await grace.locator('[data-inventaires]').count() + await grace.locator('[data-sans-inventaire]').count() === 1, 'écarts : inventaires de la période signalés');
    const csv2 = await grace.request.get(B + '/gestion/rapports/couts?vue=ecarts&export=csv');
    verifier((await csv2.text()).includes('Attendue') && (await csv2.text()).includes('Réelle'), 'écarts : export CSV');
    await grace.goto(B + '/gestion/rapports/couts?vue=ecarts&etablissement=1&lieu=' + L.cuisine);
    verifier(await grace.locator(`[data-ecart-produit="${P.sauce}"]`).count() === 1 && await grace.locator('tr[data-ecart-produit]', { hasText: 'Primus' }).count() === 0, 'écarts : filtre par lieu (cuisine)');
    await grace.goto(B + '/gestion/rapports/couts?imprimer=1');
    verifier(await grace.locator('.rap-impression-entete').count() === 1, 'coûts : présentation d\'impression');
    await grace.context().close();
    const patrick = await connecter(navigateur, PATRICK);
    const g = await patrick.goto(B + '/gestion/rapports/couts');
    verifier(g.status() === 403, 'gérant sans rapports.marges : coûts refusés', g.status());
    await patrick.context().close();
  }

  // ---------- 10. Modules, menus ----------
  {
    const admin = await connecter(navigateur, ADMIN);
    await admin.goto(B + '/gestion/stock');
    verifier(await admin.locator('.lateral a[href="/gestion/stock/productions"]').count() === 1 && await admin.locator('.lateral a[href="/gestion/stock/peremptions"]').count() === 1, 'menu Stock : productions et péremptions');
    await admin.goto(B + '/gestion/achats/receptions');
    verifier(await admin.locator('.lateral a[href="/gestion/achats/marche"]').count() === 1, 'menu Achats : achats au marché');
    await admin.goto(B + '/gestion/ventes');
    verifier(await admin.locator('.lateral a[href="/gestion/gros"]').count() === 1, 'menu Ventes : vente en gros');
    await admin.goto(B + '/gestion/tableau-de-bord');
    verifier(await admin.locator('.lateral a[href="/gestion/rapports/couts"]').count() === 1, 'menu Rapports : coûts et rentabilité');
    await admin.goto(B + '/gestion/utilisateurs/roles/' + un(`select id from roles where code='gerant'`));
    const roles = await admin.locator('body').innerText();
    verifier(roles.includes('Vendre en gros au dépôt') && roles.includes('Acheter au marché avec la caisse de la cuisine'), 'rôles : nouveaux droits libellés');
    await admin.context().close();
  }

  // ---------- 11. Écrans : FR/EN, jour/nuit, ordinateur/téléphone ----------
  {
    const ecrans = ['/gestion/stock/productions', '/gestion/stock/peremptions', '/gestion/stock/recettes', '/gestion/stock/recettes/' + P.sauce, '/gestion/achats/marche',
      '/gestion/gros', '/gestion/gros/nouvelle', '/gestion/gros/prix', '/gestion/rapports/couts', '/gestion/rapports/couts?vue=ecarts', '/gestion/stock/lieux', '/gestion/achats/receptions/nouvelle', '/gestion/stock'];
    for (const [nomVp, vp] of [['bureau', { width: 1366, height: 860 }], ['tel', { width: 390, height: 844 }]]) {
      for (const theme of ['light', 'dark']) {
        for (const langue of ['fr', 'en']) {
          const page = await connecter(navigateur, ADMIN, { viewport: vp, colorScheme: theme });
          await page.goto(B + '/gestion/stock?lang=' + langue);
          for (const u of ecrans) {
            const rep = await page.goto(B + u);
            verifier(rep.status() === 200, `${u} ${nomVp} ${theme} ${langue} : 200`, rep.status());
            verifier(await debordement(page) <= 0, `${u} ${nomVp} ${theme} ${langue} : pas de défilement horizontal`, await debordement(page));
            const cles = await clesBrutes(page);
            verifier(cles.length === 0, `${u} ${langue} : pas de clé de traduction brute`, cles.slice(0, 5));
            if (nomVp === 'tel' && langue === 'fr' && theme === 'light') {
              const petites = await petitesCibles(page);
              verifier(petites.length === 0, `${u} : cibles de 44 px`, petites.slice(0, 6));
            }
            if (langue === 'en') {
              const texte = await page.evaluate(() => document.querySelector('main')?.innerText || '');
              verifier(!/\b(Péremption|Préparation|Vente en gros|Achats au marché|Coût matière)\b/.test(texte), `${u} : interface en anglais`);
            }
            if (langue === 'fr' && (u.includes('peremptions') || u.includes('gros/nouvelle') || u.includes('couts') || u.includes('marche') || u.includes('productions'))) {
              await page.screenshot({ path: `${DIR}/suite-${u.replace(/[^a-z0-9]+/gi, '_')}-${nomVp}-${theme}.png`, fullPage: true });
            }
          }
          verifier(page.erreurs.length === 0, `${nomVp} ${theme} ${langue} : pas d'erreur JavaScript`, page.erreurs.slice(0, 3));
          await page.context().close();
        }
      }
    }
  }

  await navigateur.close();
  sql(`delete from utilisateur_roles where utilisateur_id in (${chefId},${gerantVcId},${magVcId})`);
  console.log(`\n${ok} réussis, ${ko} échoués`);
  if (echecs.length) console.log(echecs.join('\n'));
  process.exit(ko ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
