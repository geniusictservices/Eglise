const { chromium } = require('playwright'); const fs = require('fs');
const MDP = fs.readFileSync(__dirname + '/../instance.txt', 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
(async () => {
  const b = await chromium.launch(); const p = await (await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, deviceScaleFactor: 2 })).newPage();
  const err = []; p.on('pageerror', x => err.push(x.message));
  await p.goto('http://integ.localhost:8250/gestion/connexion'); await p.fill('#email', 'admin@demo.test'); await p.fill('#mot_de_passe', MDP); await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  await p.goto('http://integ.localhost:8250/gestion/remboursements');
  const n = await p.locator('.barre-bas__lien').count(); const cls = await p.getAttribute('.barre-bas', 'class');
  console.log('liens', n, cls, await p.locator('.barre-bas__lien').allTextContents(), 'débord', await p.evaluate(() => document.documentElement.scrollWidth > innerWidth), err);
  await p.screenshot({ path: __dirname + '/barre-tel.png' }); await b.close();
})();
