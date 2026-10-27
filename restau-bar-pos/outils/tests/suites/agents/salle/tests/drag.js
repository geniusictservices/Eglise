const { chromium } = require('playwright');
const { B, connecter, sql } = require('./commun');
(async () => {
  const b = await chromium.launch(); const err = [];
  const ctx = await b.newContext({ viewport: { width: 1366, height: 860 } });
  const g = await connecter(ctx, 'patrick@demo.test', 'Gerant-Goma-2026', err);
  await g.goto(B + '/gestion/salle');
  await g.evaluate(() => { ['pointerdown','pointermove','pointerup'].forEach(t => document.addEventListener(t, e => { window.__ev = (window.__ev||[]); window.__ev.push(t + ':' + e.target.className); }, true)); });
  const t = g.locator('.sal-table:has-text("T1")').first(); const bb = await t.boundingBox(); console.log(bb);
  await g.mouse.move(bb.x + 10, bb.y + 10); await g.mouse.down(); await g.mouse.move(bb.x + 130, bb.y + 90, { steps: 8 }); await g.mouse.up();
  await g.waitForTimeout(1000);
  console.log(await g.evaluate(() => (window.__ev || []).slice(0, 5).concat((window.__ev||[]).length)), await t.getAttribute('data-x'), err);
  await b.close();
})();
