// Vérifie l'aide dans l'application : manuel, images, PDF, liens internes
const o = require('./outils');
(async () => {
  await o.lancer();
  const ctx = await o.contexte(o.ORDI);
  const p = await o.connecter(ctx, 'grace@demo.test', 'Compta-Goma-2026');
  const r = await p.goto(o.B + '/gestion/aide', { waitUntil: 'networkidle' });
  console.log('aide', r.status());
  await p.evaluate(() => document.querySelectorAll('img[loading]').forEach(i => i.removeAttribute('loading')));
  await p.waitForTimeout(3000);
  console.log('images cassées', await p.evaluate(() => [...document.images].filter(i => !i.naturalWidth).map(i => i.src).slice(0, 5)), 'sur', await p.evaluate(() => document.images.length));
  console.log('doc-externe restants', await p.locator('a.doc-externe').count(), '· chemins docs', await p.locator('span.chemin:has-text("docs/")').count());
  const ids = await p.evaluate(() => new Set([...document.querySelectorAll('[id]')].map(e => e.id)));
  const ancres = await p.evaluate(() => [...document.querySelectorAll('a[href^="#"]')].map(a => a.getAttribute('href').slice(1)).filter(h => h && !document.getElementById(h)));
  console.log('ancres cassées', ancres);
  const pdf = await p.request.get(o.B + '/gestion/aide/manuel.pdf');
  console.log('pdf', pdf.status(), pdf.headers()['content-type'], (await pdf.body()).length);
  await p.click('text=Télécharger le manuel (PDF)').catch(e => console.log('lien pdf', e.message));
  await p.screenshot({ path: __dirname + '/explore/aide.png' });
  await o.fermer();
})();
