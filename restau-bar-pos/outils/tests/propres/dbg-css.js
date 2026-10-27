const { chromium } = require('playwright'); const fs = require('fs');
const B = 'http://integ2.localhost:8250';
(async () => {
  const b = await chromium.launch(); const ctx = await b.newContext({ viewport: { width: 390, height: 800 }, isMobile: true, hasTouch: true, serviceWorkers: 'block' });
  let vu = []; await ctx.route('**/*.css*', r => { vu.push(r.request().url()); if (r.request().url().includes('caisse.css')) return r.fulfill({ contentType: 'text/css', body: fs.readFileSync(__dirname + '/ancien-caisse.css') }); return r.continue(); });
  const p = await ctx.newPage();
  await p.goto(B + '/gestion/connexion'); await p.fill('#email', 'patrick@demo.test'); await p.fill('#mot_de_passe', 'Gerant-Goma-2026');
  await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  await p.goto(B + '/caisse?e=comptoir-du-lac'); await p.waitForTimeout(800);
  console.log(vu.filter(u => u.includes('caisse')), await p.evaluate(() => [getComputedStyle(document.querySelector('.haut__reglages')).flexWrap, document.documentElement.scrollWidth, !!document.getElementById('garanties')]));
  await b.close();
})();
