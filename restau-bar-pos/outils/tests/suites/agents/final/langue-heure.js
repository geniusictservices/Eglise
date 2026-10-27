const { chromium } = require('playwright');
const fs = require('fs');
const B = 'http://integ.localhost:8250';
const MDP = fs.readFileSync(__dirname + '/../../instance.txt', 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
(async () => {
  const nav = await chromium.launch();
  const ctx = await nav.newContext({ locale: 'en-US' });
  await ctx.addCookies([{ name: 'lang', value: 'en', url: B }]);
  const p = await ctx.newPage();
  await p.goto(B + '/gestion/connexion');
  console.log('avant connexion :', await p.getAttribute('html', 'lang'));
  await p.fill('#email', 'admin@demo.test'); await p.fill('#mot_de_passe', MDP);
  await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  console.log('après connexion (compte en fr) :', await p.getAttribute('html', 'lang'));
  await p.goto(B + '/gestion/compte');
  console.log('compte :', (await p.textContent('dl')).replace(/\s+/g, ' ').trim());
  console.log('heure PHP locale :', new Intl.DateTimeFormat('fr-FR', { timeZone: 'Africa/Lubumbashi', dateStyle: 'short', timeStyle: 'short' }).format(new Date()));
  await nav.close();
})();
