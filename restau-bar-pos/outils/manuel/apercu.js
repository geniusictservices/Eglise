const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1366, height: 900 } });
  await p.goto('file:///home/user/Eglise/restau-bar-pos/docs/manuel/index.html', { waitUntil: 'networkidle' });
  for (const [i, ancre] of ['haut', 'encaisser', 'ardoise', 'etablissements', 'console'].entries()) {
    await p.evaluate(a => document.getElementById(a).scrollIntoView(), ancre); await p.waitForTimeout(400);
    await p.screenshot({ path: `explore/apercu-${i}.png` });
  }
  const t = await b.newPage({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 1 });
  await t.goto('file:///home/user/Eglise/restau-bar-pos/docs/manuel/index.html#vendre', { waitUntil: 'networkidle' });
  await t.waitForTimeout(500); await t.screenshot({ path: 'explore/apercu-tel.png' });
  console.log('largeur', await t.evaluate(() => document.documentElement.scrollWidth));
  await b.close();
})();
