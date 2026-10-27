const { chromium } = require('playwright');
const fs = require('fs');
const B = 'http://integ.localhost:8250';
const MDP = fs.readFileSync(__dirname + '/../../instance.txt', 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
(async () => {
  const [url, sortie, w, h, theme, lang] = process.argv.slice(2);
  const b = await chromium.launch();
  const c = await b.newContext({ viewport: { width: +w, height: +h } });
  const p = await c.newPage();
  await p.goto(B + '/gestion/connexion?lang=' + (lang || 'fr'));
  await p.fill('input[name=email]', 'admin@demo.test'); await p.fill('input[name=mot_de_passe]', MDP);
  await Promise.all([p.waitForNavigation(), p.click('.connexion button[type=submit]')]);
  await p.evaluate(t => localStorage.setItem('gestion.theme', t), theme || 'jour');
  await p.goto(B + url);
  console.log('debord', await p.evaluate(() => document.documentElement.scrollWidth - innerWidth));
  await p.screenshot({ path: sortie, fullPage: process.env.PLEIN !== '0' });
  await b.close();
})();
