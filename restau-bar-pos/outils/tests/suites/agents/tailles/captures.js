// Captures sur les données de démonstration (base remise à neuf avant).
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const B = 'http://integ.localhost:8250';
const ICI = __dirname;
const DOSSIER = path.join(ICI, 'captures');
const MDP = fs.readFileSync(path.join(ICI, '../../instance.txt'), 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
const erreurs = [];

async function contexte(nav, w, h) {
  const ctx = await nav.newContext({ viewport: { width: w, height: h }, locale: 'fr-FR', deviceScaleFactor: w < 500 ? 2 : 1 });
  await ctx.addCookies([{ name: 'lang', value: 'fr', url: B }]);
  const p = await ctx.newPage();
  p.on('pageerror', e => erreurs.push(e.message));
  await p.goto(B + '/gestion/connexion');
  await p.fill('#email', 'admin@demo.test'); await p.fill('#mot_de_passe', MDP);
  await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  await p.goto(B + '/gestion?lang=fr');
  return [ctx, p];
}
const prendre = (p, nom, options = {}) => p.screenshot({ path: path.join(DOSSIER, nom), type: 'jpeg', quality: 85, ...options });

(async () => {
  const nav = await chromium.launch();
  let [ctx, p] = await contexte(nav, 1366, 860);

  await p.goto(B + '/gestion/catalogue/categories');
  await p.evaluate(() => document.querySelector('#categorie-1').scrollIntoView());
  await p.evaluate(() => window.scrollBy(0, -90));
  await prendre(p, 'categories-ordi.jpg');

  await p.goto(B + '/gestion/catalogue/produits/1#tailles');
  await p.evaluate(() => { const s = document.querySelector('#tailles'); window.scrollTo(0, s.getBoundingClientRect().top + window.scrollY - 70); });
  await p.waitForTimeout(200);
  await prendre(p, 'produit-tailles-ordi.jpg');

  await p.goto(B + '/caisse?e=comptoir-du-lac');
  await p.evaluate(() => sessionStorage.clear());
  await p.reload();
  await p.click('#onglets .onglet:has-text("Bières")');
  await p.click('.tuile:has-text("Mützig")'); await p.click('#formats-liste .format:has-text("Grande")');
  await p.click('.tuile:has-text("Heineken")');
  await prendre(p, 'caisse-souscategories-ordi.jpg');
  await p.click('#sous-onglets .sous-onglet:has-text("Locales")');
  await p.click('.tuile:has-text("Primus")');
  await p.waitForTimeout(150);
  await prendre(p, 'caisse-tailles-ordi.jpg');
  await p.keyboard.press('Escape');
  await p.evaluate(() => sessionStorage.clear());
  await ctx.close();

  [ctx, p] = await contexte(nav, 390, 844);
  await p.goto(B + '/gestion/catalogue/categories');
  await p.evaluate(() => { document.querySelector('#categorie-1').scrollIntoView(); window.scrollBy(0, -12); });
  await prendre(p, 'categories-tel.jpg');

  await p.goto(B + '/e/comptoir-du-lac?devise=USD');
  await p.evaluate(() => { const s = document.querySelector('#cat-1'); window.scrollTo(0, s.getBoundingClientRect().top + window.scrollY - document.querySelector('#barre').offsetHeight - 8); });
  await p.waitForTimeout(400);
  await prendre(p, 'vitrine-tailles-tel.jpg');

  await p.goto(B + '/caisse?e=comptoir-du-lac');
  await p.evaluate(() => sessionStorage.clear());
  await p.reload();
  await p.click('#onglets .onglet:has-text("Bières")');
  await p.click('.tuile:has-text("Heineken")');
  await p.click('.tuile:has-text("Primus")');
  await p.waitForTimeout(150);
  await prendre(p, 'caisse-tailles-tel.jpg');
  await p.evaluate(() => sessionStorage.clear());
  await ctx.close();

  await nav.close();
  console.log(erreurs.length ? 'Erreurs JS : ' + erreurs.join(' | ') : 'captures faites, aucune erreur JS');
})();
