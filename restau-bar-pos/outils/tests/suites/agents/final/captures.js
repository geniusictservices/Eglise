// Captures finales sur les données de démo d'origine.
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const B = 'http://integ.localhost:8250';
const D = path.join(__dirname, 'captures');
fs.mkdirSync(D, { recursive: true });
const MDP = fs.readFileSync(path.join(__dirname, '../../instance.txt'), 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
const TEL = { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true };
const TABLETTE = { viewport: { width: 1180, height: 820 }, hasTouch: true };
const BUREAU = { viewport: { width: 1366, height: 860 } };
const faits = [];

(async () => {
  const nav = await chromium.launch();
  const erreurs = [];
  async function contexte(opt, theme) {
    const ctx = await nav.newContext({ ...opt, locale: 'fr-FR', colorScheme: theme === 'nuit' ? 'dark' : 'light' });
    await ctx.addCookies([{ name: 'lang', value: 'fr', url: B }]);
    const p = await ctx.newPage();
    p.on('pageerror', e => erreurs.push(e.message));
    p.on('console', m => m.type() === 'error' && erreurs.push(m.text()));
    return { ctx, p };
  }
  async function connecter(p) {
    await p.goto(B + '/gestion/connexion');
    await p.fill('#email', 'admin@demo.test'); await p.fill('#mot_de_passe', MDP);
    await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  }
  async function cliche(p, nom, pleine = false) {
    await p.evaluate(() => document.fonts.ready); await p.waitForTimeout(500);
    const f = path.join(D, nom + '.png'); await p.screenshot({ path: f, fullPage: pleine }); faits.push(f);
  }
  async function remplirTicket(p, indices) {
    await p.waitForSelector('.tuile');
    for (const i of indices) {
      const t = await p.$$('.tuile:not(:disabled)'); if (!t[i]) continue;
      await t[i].click();
      if (await p.$('#formats[open]')) { const f = await p.$$('#formats-liste .format'); await f[f.length - 1].click(); }
    }
  }

  // Vitrine restaurant, téléphone, jour (thème par défaut du restaurant-bar)
  let { ctx, p } = await contexte(TEL, 'jour');
  await p.goto(B + '/e/comptoir-du-lac');
  await cliche(p, 'vitrine-restaurant-telephone-jour');
  await p.evaluate(() => { const s = document.querySelector('section.categorie'); if (s) window.scrollTo(0, s.getBoundingClientRect().top + scrollY - 120); });
  await cliche(p, 'vitrine-restaurant-telephone-jour-menu');
  await ctx.close();

  // Vitrine club, téléphone, nuit (zone VIP, francs congolais)
  ({ ctx, p } = await contexte(TEL, 'nuit'));
  await p.goto(B + '/e/volcano-club?zone=vip&devise=CDF');
  await cliche(p, 'vitrine-club-telephone-nuit');
  await p.evaluate(() => { const s = document.querySelector('section.categorie'); if (s) window.scrollTo(0, s.getBoundingClientRect().top + scrollY - 120); });
  await cliche(p, 'vitrine-club-telephone-nuit-menu');
  await ctx.close();

  // Caisse restaurant, ordinateur
  ({ ctx, p } = await contexte(BUREAU, 'jour'));
  await connecter(p);
  await p.goto(B + '/caisse?e=comptoir-du-lac');
  await remplirTicket(p, [0, 0, 3, 6, 10]);
  await cliche(p, 'caisse-restaurant-ordinateur');

  // Gestion : tableau de bord, fiche produit, fiche établissement, devises, design
  await p.goto(B + '/gestion'); await cliche(p, 'gestion-tableau-de-bord', true);
  await p.goto(B + '/gestion/catalogue/produits/1'); await cliche(p, 'gestion-fiche-produit', true);
  await p.goto(B + '/gestion/etablissements/2'); await cliche(p, 'gestion-fiche-etablissement', true);
  await p.goto(B + '/gestion/devises'); await cliche(p, 'gestion-devises', true);
  await p.goto(B + '/gestion/design'); await cliche(p, 'gestion-design', true);
  await p.goto(B + '/gestion/catalogue'); await cliche(p, 'gestion-catalogue', true);
  await ctx.close();

  // Caisse club, tablette (thème nuit par défaut du nightclub)
  ({ ctx, p } = await contexte(TABLETTE, 'nuit'));
  await connecter(p);
  await p.goto(B + '/caisse?e=volcano-club&zone=vip');
  await remplirTicket(p, [0, 1, 1, 5]);
  await cliche(p, 'caisse-club-tablette');
  await ctx.close();

  await nav.close();
  console.log(faits.join('\n'));
  console.log('erreurs JS :', erreurs.length ? erreurs : 'aucune');
})();
