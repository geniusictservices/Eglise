// Ordinateur 1366 × 860, tablette 1024 × 768, téléphone 390 × 844 : pas de défilement horizontal, cibles de 44 px,
// thèmes jour et nuit ; captures d'écran.
const { chromium } = require('playwright');
const { B, MDP, S, sql, restaurer, v, bilan, connecter, ajouter } = require('./commun');

async function sansDefilement(p, quoi) {
  const ok = await p.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);
  v(ok, quoi + ' : pas de défilement horizontal');
}
// Boutons visibles plus petits que 44 × 44 dans une zone
async function petites(p, selecteur) {
  return p.evaluate(sel => {
    const res = [];
    document.querySelectorAll(sel).forEach(zone => zone.querySelectorAll('button, a.puce, a.bouton, input[type=checkbox], select, input:not([type=hidden])').forEach(b => {
      const r = b.getBoundingClientRect(); const st = getComputedStyle(b);
      if (r.width === 0 || st.visibility === 'hidden' || b.closest('[hidden]')) { return; }
      const cible = b.type === 'checkbox' ? (b.closest('label') || b).getBoundingClientRect() : r;
      if (cible.height < 43.5 || cible.width < 43.5) { res.push((b.id || b.className || b.tagName) + ' ' + Math.round(cible.width) + '×' + Math.round(cible.height)); }
    }));
    return res;
  }, selecteur);
}

(async () => {
  restaurer();
  const b = await chromium.launch(); const err = [];
  for (const [nom, vue, mobile] of [['ordi', { width: 1366, height: 860 }, false], ['tablette', { width: 1024, height: 768 }, false], ['tel', { width: 390, height: 844 }, true]]) {
    const ctx = await b.newContext({ viewport: vue, isMobile: mobile, hasTouch: mobile || nom === 'tablette', deviceScaleFactor: mobile ? 2 : 1 });
    const p = await connecter(ctx, 'admin@demo.test', MDP, err);
    p.on('dialog', d => d.accept());
    await p.goto(B + '/caisse?e=comptoir-du-lac'); await p.evaluate(() => sessionStorage.clear()); await p.reload(); await p.waitForTimeout(700);
    await sansDefilement(p, `${nom} : caisse`);
    await p.click('#salle'); await p.waitForSelector('#salle-fenetre[open]'); await p.waitForTimeout(500);
    await sansDefilement(p, `${nom} : plan de salle`);
    let k = await petites(p, '#salle-fenetre');
    v(k.length === 0, `${nom} : plan de salle, cibles ≥ 44 px ` + k.join(', '));
    await p.screenshot({ path: S + `salle-${nom}.png` });
    await p.click('.table-plan:has-text("T3")'); await p.waitForTimeout(900);
    await ajouter(p, ['Mojito']);
    if (mobile) { await p.click('#resume'); await p.waitForTimeout(400); }
    await sansDefilement(p, `${nom} : note T3 dans le ticket`);
    k = await petites(p, '#ticket');
    v(k.length === 0, `${nom} : panneau du ticket, cibles ≥ 44 px ` + k.join(', '));
    await p.screenshot({ path: S + `note-${nom}.png` });
    await p.click('#partager').catch(() => {}); await p.waitForTimeout(500);
    if (await p.locator('#partage-fenetre[open]').count()) {
      await sansDefilement(p, `${nom} : partage`);
      await p.click('#partage-modes button[data-mode="egal"]');
      await p.screenshot({ path: S + `partage-${nom}.png` });
      await p.click('[data-fermer-partage]');
    }
    if (mobile) { await p.click('#resume').catch(() => {}); await p.waitForTimeout(300); }
    await p.locator('#note-lignes .note-ligne', { hasText: 'Tilapia' }).locator('.note-ligne__annuler').click(); await p.waitForTimeout(300);
    await p.screenshot({ path: S + `annuler-${nom}.png` });
    await p.click('[data-fermer-annuler]');
    // Écran de cuisine
    await p.goto(B + '/caisse/cuisine?etab=comptoir-du-lac'); await p.waitForTimeout(1000);
    await sansDefilement(p, `${nom} : écran de cuisine`);
    k = await petites(p, '#bons');
    v(k.length === 0, `${nom} : écran de cuisine, cibles ≥ 44 px ` + k.join(', '));
    await p.screenshot({ path: S + `cuisine-${nom}.png` });
    // Écrans de gestion
    for (const [u, n] of [['/gestion/salle', 'plan'], ['/gestion/salle/notes', 'notes'], ['/gestion/salle/reservations', 'reservations'], ['/gestion/salle/services', 'services'], ['/gestion/cuisine', 'gcuisine']]) {
      await p.goto(B + u); await p.waitForTimeout(300);
      await sansDefilement(p, `${nom} : ${u}`);
      if (nom !== 'tablette') { await p.screenshot({ path: S + `gestion-${n}-${nom}.png`, fullPage: true }); }
    }
    await ctx.close();
  }
  // Thèmes : club de nuit, caisse jour en nuit, cuisine en jour
  const ctx = await b.newContext({ viewport: { width: 1366, height: 860 } });
  const p = await connecter(ctx, 'admin@demo.test', MDP, err);
  await p.goto(B + '/caisse?e=volcano-club'); await p.waitForTimeout(700);
  v((await p.getAttribute('html', 'data-theme')) === 'nuit', 'Volcano Club : caisse en thème nuit');
  await p.click('#salle'); await p.waitForSelector('#salle-fenetre[open]'); await p.click('#salle-zones .puce:has-text("Carré VIP")').catch(() => {}); await p.waitForTimeout(600);
  await p.screenshot({ path: S + 'salle-club-nuit.png' });
  await p.goto(B + '/caisse/cuisine?etab=comptoir-du-lac'); await p.waitForTimeout(800);
  await p.click('[data-bascule-theme]'); await p.waitForTimeout(300);
  v((await p.getAttribute('html', 'data-theme')) === 'jour', 'écran de cuisine : bascule en thème jour');
  await p.screenshot({ path: S + 'cuisine-jour.png' });
  await p.goto(B + '/gestion/salle/services'); await p.click('[data-bascule-theme]'); await p.waitForTimeout(200);
  await p.screenshot({ path: S + 'gestion-services-nuit.png', fullPage: true });
  v(err.length === 0, 'aucune erreur JS ' + err.join(' | '));
  bilan(); await b.close();
})();
