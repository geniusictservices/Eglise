// Fumée : connexion à la console puis chaque écran, erreurs JS et codes HTTP
const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1366, height: 860 } });
  const erreurs = [];
  p.on('pageerror', e => erreurs.push('JS ' + e.message));
  p.on('console', m => { if (m.type() === 'error') erreurs.push('console ' + m.text()); });
  const A = 'http://admin.localhost:8180';
  await p.goto(A + '/connexion');
  await p.fill('#email', 'super@plateforme.test');
  await p.fill('#mot_de_passe', 'Console-Goma-2026!');
  await p.click('button[type=submit]');
  for (const u of ['/', '/clients', '/clients/nouveau', '/clients/nouveau?instance=cnslbar', '/formules', '/formules/nouvelle', '/formules/1', '/messagerie', '/reglages', '/journal', '/journal?vue=messages', '/compte']) {
    const r = await p.goto(A + u);
    const h1 = await p.locator('h1').first().textContent().catch(() => '?');
    const large = await p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
    console.log(r.status(), u, '·', h1.trim(), large ? 'DÉBORDE' : '');
  }
  console.log(erreurs.length ? erreurs.join('\n') : 'aucune erreur JS');
  await b.close();
})();
