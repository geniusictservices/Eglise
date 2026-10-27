const { chromium } = require('playwright');
const fs = require('fs'); const path = require('path');
const B = 'http://integ.localhost:8250';
const MDP = fs.readFileSync(path.join(__dirname, '../../instance.txt'), 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
(async () => {
  const nav = await chromium.launch();
  for (const [w, h, n] of [[1366, 860, 'ordi'], [390, 844, 'tel']]) {
    const ctx = await nav.newContext({ viewport: { width: w, height: h } });
    await ctx.addCookies([{ name: 'lang', value: 'fr', url: B }]);
    const p = await ctx.newPage();
    await p.goto(B + '/gestion/connexion');
    await p.fill('#email', 'admin@demo.test'); await p.fill('#mot_de_passe', MDP);
    await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
    await p.goto(B + '/gestion/catalogue?lang=fr');
    await p.evaluate(() => { document.querySelector('#categorie-8').scrollIntoView(); });
    await p.screenshot({ path: `controle-liste-${n}.jpg`, type: 'jpeg', quality: 70 });
    await p.goto(B + '/gestion/catalogue/produits/20');
    await p.screenshot({ path: `controle-variante-${n}.jpg`, type: 'jpeg', quality: 70 });
    await p.goto(B + '/caisse?e=volcano-club&zone=vip');
    await p.click('#onglets .onglet:has-text("Bières")');
    await p.click('.tuile:has-text("Mützig")'); await p.click('#formats-liste .format:has-text("Grande")');
    if (n === 'tel') { await p.click('#resume'); await p.waitForTimeout(400); }
    await p.screenshot({ path: `controle-club-${n}.jpg`, type: 'jpeg', quality: 70 });
    await p.evaluate(() => sessionStorage.clear());
    await ctx.close();
  }
  await nav.close();
})();
