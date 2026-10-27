// Instance neuve (bin/creer-instance.php sans --demo) : connexion, devise, établissement, catégorie, produit et prix,
// compte de caisse, puis une vente à la caisse ; aucune erreur JS ni PHP, aucune clé brute.
const { chromium } = require('playwright'); const fs = require('fs'); const { execSync } = require('child_process');
const SD = process.env.SD || 'essaineuf'; const B = `http://${SD}.localhost:8250`;
const MDP = fs.readFileSync(__dirname + `/../paquet/creer-${SD}.txt`, 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
const sql = q => execSync(`mysql -N --default-character-set=utf8mb4 rbp_${SD} -e ${JSON.stringify(q)}`).toString().trim();
const JOURNAL = '/home/user/Eglise/restau-bar-pos/storage/logs/php-erreurs.log';
const taille = () => fs.statSync(JOURNAL).size; const debut = taille();
let nb = 0; const ko = []; const v = (c, m) => { nb++; if (!c) ko.push(m); console.log((c ? 'ok    ' : 'ÉCHEC ') + m); };
const cles = p => p.evaluate(() => [...document.querySelectorAll('body *')].flatMap(el => [...el.childNodes].filter(n => n.nodeType === 3).map(n => n.textContent.trim())).filter(t => /^[a-z][a-z0-9_]*\.[a-z0-9_]+$/.test(t)));
(async () => {
  const b = await chromium.launch(); const err = [];
  const ctx = await b.newContext({ viewport: { width: 1366, height: 860 } }); const p = await ctx.newPage();
  p.on('pageerror', e => err.push(e.message)); p.on('console', m => m.type() === 'error' && !/status of 4\d\d/.test(m.text()) && err.push(m.text()));
  const soumettre = async (sel = 'main form button[type=submit]') => { const [r] = await Promise.all([p.waitForNavigation(), p.click(sel)]); return r; };
  await p.goto(B + '/gestion/connexion'); await p.fill('#email', 'patron@' + SD + '.test'); await p.fill('#mot_de_passe', MDP);
  await soumettre('button[type=submit]');
  v(new URL(p.url()).pathname === '/gestion', 'connexion du propriétaire : écran des modules');
  v(sql('SELECT COUNT(*) FROM utilisateurs') === '1' && sql('SELECT COUNT(*) FROM etablissements') === '0', 'base neuve : un seul compte, aucun établissement');
  v(sql("SELECT GROUP_CONCAT(code ORDER BY id) FROM grilles_prix") === 'gros,normal', 'grilles de départ : « Prix normal » (carte) et « Prix de gros »');
  let r0 = await p.goto(B + '/caisse');
  v(r0.status() === 403 && (await p.textContent('main')).includes('Aucun établissement n\'est encore créé'), 'caisse avant le premier établissement : explication (pas « votre rôle »)');
  // Devise de référence (dollar), puis le franc congolais avec son taux
  await p.goto(B + '/gestion/devises/nouvelle');
  await p.fill('#code', 'USD'); await p.fill('#symbole', '$'); await p.fill('#nom_fr', 'Dollar américain'); await p.fill('#nom_en', 'US dollar');
  await p.check('input[name=decimales][value="2"]'); await p.check('input[name=symbole_avant][value="1"]');
  let r = await soumettre('main form button[type=submit]');
  v(r.status() === 200 && sql("SELECT CONCAT(code, ':', est_reference) FROM devises") === 'USD:1', 'première devise : USD, devise de référence');
  await p.goto(B + '/gestion/devises/nouvelle');
  await p.fill('#code', 'CDF'); await p.fill('#symbole', 'FC'); await p.fill('#nom_fr', 'Franc congolais');
  await p.check('input[name=decimales][value="0"]'); await p.check('input[name=symbole_avant][value="0"]'); await p.fill('#arrondi', '100');
  const champTaux = await p.$('#taux'); if (champTaux) { await p.fill('#taux', '2850'); }
  r = await soumettre('main form button[type=submit]');
  v(r.status() === 200 && sql("SELECT COUNT(*) FROM devises WHERE code='CDF'") === '1', 'deuxième devise : CDF' + (champTaux ? ' avec son taux' : ''));
  await p.goto(B + '/gestion/devises');
  await p.fill('#taux-' + sql("SELECT id FROM devises WHERE code='CDF'"), '2850');
  await Promise.all([p.waitForNavigation(), p.locator('form[action$="/gestion/devises/taux#taux"] button[type=submit]').click()]);
  v(sql("SELECT COUNT(*) FROM taux_change t JOIN devises d ON d.id=t.devise_id WHERE d.code='CDF'") === '1', 'taux du franc saisi (2 850 FC pour 1 $)');
  v((await cles(p)).length === 0, 'devises : aucune clé brute');
  // Établissement
  await p.goto(B + '/gestion/etablissements/nouveau');
  v(await p.$eval('#etb-grille', s => s.options[s.selectedIndex].textContent.trim()) === 'Prix normal', 'nouvel établissement : grille « Prix normal » proposée par défaut');
  await p.fill('#etb-nom', 'Le Petit Kivu'); await p.check('input[name=type][value="restaurant_bar"]');
  for (const c of await p.$$('main form input[type=checkbox][name^="devises"]')) { await c.check(); }
  r = await soumettre('main form button[type=submit]');
  const etab = sql("SELECT id FROM etablissements WHERE nom='Le Petit Kivu'");
  v(!!etab && sql(`SELECT g.code FROM etablissements e JOIN grilles_prix g ON g.id=e.grille_prix_id WHERE e.id=${etab}`) === 'normal', 'établissement créé, grille « normal »');
  const slug = sql(`SELECT slug FROM etablissements WHERE id=${etab}`);
  console.log('devises acceptées : ' + sql(`SELECT GROUP_CONCAT(d.code) FROM etablissement_devises ed JOIN devises d ON d.id=ed.devise_id WHERE ed.etablissement_id=${etab}`));
  // Catégorie puis produit avec son prix
  await p.goto(B + '/gestion/catalogue/categories');
  const nomCat = await p.$('main form input[name=nom_fr]');
  await nomCat.fill('Bières'); await Promise.all([p.waitForNavigation(), nomCat.evaluate(el => el.form.requestSubmit())]);
  v(sql("SELECT COUNT(*) FROM categories WHERE nom_fr='Bières'") === '1', 'catégorie « Bières » créée');
  await p.goto(B + '/gestion/catalogue/produits/nouveau');
  const champs = await p.$$eval('main form input, main form select', l => l.map(e => `${e.name}:${e.type}`));
  console.log(champs.join(' '));
  await p.fill('main form [name=nom_fr]', 'Primus');
  const cat = await p.$('main form select[name=categorie_id]'); if (cat) { await cat.selectOption({ label: 'Bières' }); }
  const prix = await p.$$('main form input[name^="prix"]');
  for (const champ of prix) { const n = await champ.getAttribute('name'); console.log('prix : ' + n); }
  // Prix dans la devise de référence (USD, id 1) : 2 $
  await p.fill('main form input[name="prix[' + sql("SELECT id FROM devises WHERE code='USD'") + ']"]', '2');
  for (const c of await p.$$('main form input[type=checkbox][name^="etablissements"]')) { await c.check(); }
  r = await soumettre('main form button[type=submit]');
  const produit = sql("SELECT id FROM produits WHERE nom_fr='Primus'");
  v(!!produit && +sql(`SELECT COUNT(*) FROM prix pr JOIN conditionnements c ON c.id=pr.conditionnement_id WHERE c.produit_id=${produit}`) >= 1, 'produit « Primus » créé avec un prix');
  // Compte de caisse
  await p.goto(B + '/gestion/tresorerie/comptes/nouveau');
  await p.check('input[name=type][value=caisse]'); await p.fill('#nom', 'Caisse du bar');
  for (const c of await p.$$('main form input[type=checkbox][name^="devises"]')) { await c.check(); }
  r = await soumettre('main form button[type=submit]');
  v(sql("SELECT COUNT(*) FROM comptes_tresorerie WHERE nom='Caisse du bar'") === '1', 'compte « Caisse du bar » créé');
  // Caisse : une vente
  await p.goto(B + '/caisse?e=' + slug);
  v(await p.locator('#grille .tuile').count() === 1, 'caisse : la tuile du produit');
  await p.locator('#grille .tuile').first().click(); await p.waitForTimeout(200);
  if (await p.locator('#formats[open]').count()) { await p.locator('#formats-liste .format').first().click(); }
  await p.click('#encaisser'); await p.waitForSelector('#paiement[open]');
  await p.click('#paiement-valider'); await p.waitForSelector('#paiement-fin:not([hidden])', { timeout: 15000 });
  v(sql("SELECT COUNT(*) FROM ventes WHERE statut='payee'") === '1', 'vente enregistrée');
  v(+sql("SELECT COUNT(*) FROM mouvements_tresorerie WHERE nature='vente'") >= 1, 'mouvement de trésorerie de la vente');
  v((await cles(p)).length === 0, 'caisse : aucune clé brute');
  await p.goto(B + '/gestion/ventes'); v((await p.textContent('main')).includes('Primus') || (await p.locator('tr[data-vente], .ven-liste li, .ven-liste tr').count()) >= 1, 'la vente apparaît dans Ventes');
  const php = fs.readFileSync(JOURNAL).subarray(debut).toString();
  v(php.trim() === '', 'journal PHP vide pendant le parcours ' + php.slice(0, 300));
  v(err.length === 0, 'aucune erreur JS ' + err.join(' | '));
  console.log(`\n${nb - ko.length}/${nb}`); await b.close();
})();
