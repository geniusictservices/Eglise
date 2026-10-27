// Cherche du texte français dans les pages anglaises (mots outils français, hors données).
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const B = 'http://integ.localhost:8250';
const MDP = fs.readFileSync(path.join(__dirname, '../../instance.txt'), 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
const pages = ['/', '/e/comptoir-du-lac', '/e/volcano-club?zone=vip&devise=CDF', '/gestion', '/gestion/catalogue', '/gestion/catalogue/produits/1',
  '/gestion/catalogue/produits/nouveau', '/gestion/catalogue/categories', '/gestion/etablissements', '/gestion/etablissements/nouveau',
  '/gestion/etablissements/1', '/gestion/grilles', '/gestion/devises', '/gestion/devises/2', '/gestion/devises/nouvelle', '/gestion/compte',
  '/gestion/qr', '/gestion/design', '/caisse?e=comptoir-du-lac', '/page-inexistante'];
const motifs = /\b(le|la|les|des|du|une|est|et|pour|avec|dans|sur|vous|votre|aucun|aucune|Enregistrer|Ajouter|Modifier|Supprimer|Prix|Fermé|Ouvert)\b|[éèêàùç]/;
(async () => {
  const nav = await chromium.launch();
  const ctx = await nav.newContext({ locale: 'en-US' });
  await ctx.addCookies([{ name: 'lang', value: 'en', url: B }]);
  const p = await ctx.newPage();
  await p.goto(B + '/gestion/connexion');
  const tc = await p.evaluate(() => document.body.innerText);
  await p.fill('#email', 'admin@demo.test'); await p.fill('#mot_de_passe', MDP);
  await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  for (const u of ['/gestion/connexion', ...pages]) {
    let texte;
    if (u === '/gestion/connexion') texte = tc; else { await p.goto(B + u); await p.waitForTimeout(200);
      texte = await p.evaluate(() => [document.body.innerText, ...[...document.querySelectorAll('[placeholder],[title],[aria-label]')].map(e => e.getAttribute('placeholder') || e.getAttribute('title') || e.getAttribute('aria-label'))].join('\n')); }
    const lignes = [...new Set(texte.split('\n').map(l => l.trim()).filter(l => l && motifs.test(l)))];
    console.log(u + (lignes.length ? '\n   ' + lignes.slice(0, 40).join('\n   ') : ' : rien'));
  }
  await nav.close();
})();
