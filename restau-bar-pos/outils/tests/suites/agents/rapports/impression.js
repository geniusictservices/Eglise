const { chromium } = require('playwright');
(async () => {
  const nav = await chromium.launch();
  const ctx = await nav.newContext({ viewport: { width: 1000, height: 1400 } });
  const p = await ctx.newPage();
  await p.goto('http://integ.localhost:8250/gestion/connexion?lang=fr');
  await p.fill('#email', 'admin@demo.test'); await p.fill('#mot_de_passe', 'Essai-Integ-2026');
  await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  for (const [u, n] of [['/gestion/tableau-de-bord?du=2026-10-12&au=2026-10-18', 'impression-tableau'], ['/gestion/evenements/2', 'impression-soiree'], ['/gestion/rapports/personnel?du=2026-10-12&au=2026-10-18', 'impression-personnel']]) {
    await p.goto('http://integ.localhost:8250' + u);
    await p.emulateMedia({ media: 'print' });
    await p.screenshot({ path: n + '.png', fullPage: true });
    await p.pdf({ path: n + '.pdf', format: 'A4', printBackground: true });
    await p.emulateMedia({ media: 'screen' });
  }
  await nav.close();
})();
