// Écrans du module Nuit : gestion et caisse (porte, vestiaire, bar avec ses fenêtres), en français et en anglais,
// jour et nuit, ordinateur 1366 × 860, tablette 1024 × 768, téléphone 390 × 844 : réponse 200, pas de défilement
// horizontal, cibles de 44 px, pas de clé de traduction visible, aucune erreur JavaScript ; captures.
// Droits : écrans refusés selon le rôle ; modules montrés seulement s'il y a un lounge ou un nightclub.
const { chromium } = require('playwright');
const { B, MDP, CAP, sql, restaurer, v, bilan, connecter, ajouter, donnees, attendre } = require('./commun');

const VUES = [['ordi', { width: 1366, height: 860 }, false], ['tablette', { width: 1024, height: 768 }, false], ['tel', { width: 390, height: 844 }, true]];
async function verifier(p, quoi, zone) {
  const r = await p.evaluate(sel => {
    const racine = sel ? document.querySelector(sel) : document;
    const largeur = document.documentElement.scrollWidth - window.innerWidth;
    const petits = [];
    (racine || document).querySelectorAll('button, a.bouton, a.puce, .puce, select, input:not([type=hidden]):not([type=checkbox]):not([type=radio]), summary.bouton').forEach(e => {
      const r = e.getBoundingClientRect(); const s = getComputedStyle(e);
      if (r.width === 0 || r.height === 0 || s.visibility === 'hidden' || e.closest('[hidden]') || e.closest('dialog:not([open])')) { return; }
      if (e.classList.contains('lien') || e.closest('.tableau td a.lien')) { return; }
      if (r.height < 43.5 || r.width < 43.5) { petits.push((e.id || e.className || e.tagName).toString().slice(0, 40) + ' ' + Math.round(r.width) + '×' + Math.round(r.height)); }
    });
    const texte = document.body.innerText;
    const cles = (texte.match(/\b(gnuit|nuit|ecran|perm|module)\.[a-z_]{3,}/g) || []).slice(0, 5);
    return { largeur, petits: petits.slice(0, 6), cles };
  }, zone || null);
  v(r.largeur <= 1, `${quoi} : pas de défilement horizontal (${r.largeur})`);
  v(r.petits.length === 0, `${quoi} : cibles ≥ 44 px ${r.petits.join(', ')}`);
  v(r.cles.length === 0, `${quoi} : pas de clé de traduction visible ${r.cles.join(', ')}`);
}

(async () => {
  restaurer();
  sql("UPDATE stock_parametres SET mode_negatif = 'vendre'");
  const b = await chromium.launch(); const err = [];
  const client = sql('SELECT client_id FROM fidelite_mouvements GROUP BY client_id ORDER BY SUM(points) DESC LIMIT 1');
  const pages = ['/gestion/nuit/entrees', '/gestion/nuit/invites', '/gestion/nuit/promoteurs', '/gestion/nuit/promoteurs/1', '/gestion/nuit/formules',
    '/gestion/nuit/minimums', '/gestion/nuit/vestiaire', '/gestion/cashless', '/gestion/cashless/1', '/gestion/cashless/passif', '/gestion/remises',
    '/gestion/fidelite', '/gestion/evenements/2', '/gestion/clients/' + client, '/gestion/ventes', '/gestion'];

  // ---------- Gestion ----------
  for (const [nom, vue, mobile] of VUES) {
    for (const [langue, schema] of [['fr', 'light'], ['en', 'dark']].concat(nom === 'tel' ? [['fr', 'dark'], ['en', 'light']] : [])) {
      const ctx = await b.newContext({ viewport: vue, isMobile: mobile, hasTouch: mobile, deviceScaleFactor: mobile ? 2 : 1, colorScheme: schema });
      const p = await connecter(ctx, 'admin@demo.test', MDP, err);
      await ctx.addCookies([{ name: 'lang', value: langue, url: B }]);
      sql(`UPDATE utilisateurs SET langue='${langue}' WHERE id=1`);
      for (const u of pages) {
        const r = await p.goto(B + u);
        v(r.status() === 200, `${nom} ${langue} ${schema} ${u} : 200 (${r.status()})`);
        v(await p.getAttribute('html', 'lang') === langue, `${u} : langue ${langue}`);
        await verifier(p, `${nom} ${langue} ${schema} ${u}`, 'main');
        if ((nom === 'tel' && schema === 'dark') || (nom === 'ordi' && langue === 'fr') || (nom === 'tablette' && langue === 'en')) {
          await p.screenshot({ path: CAP + `g${u.replace(/\//g, '-')}-${nom}-${langue}-${schema}.png`, fullPage: true });
        }
      }
      await ctx.close();
    }
  }
  sql("UPDATE utilisateurs SET langue='fr' WHERE id=1");

  // Espace du promoteur (téléphone, nuit, anglais)
  {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2, colorScheme: 'dark' });
    const k = await connecter(ctx, 'kevin@demo.test', 'Promo-Goma-2026', err);
    for (const langue of ['fr', 'en']) {
      sql(`UPDATE utilisateurs SET langue='${langue}' WHERE email='kevin@demo.test'`);
      await ctx.addCookies([{ name: 'lang', value: langue, url: B }]);
      const r = await k.goto(B + '/gestion/nuit/mon-espace');
      v(r.status() === 200, `promoteur ${langue} : mon espace 200`);
      await verifier(k, `promoteur ${langue} : mon espace`, 'main');
      await k.screenshot({ path: CAP + `mon-espace-tel-${langue}.png`, fullPage: true });
    }
    // Droits du promoteur
    for (const u of ['/gestion/nuit/promoteurs', '/gestion/nuit/invites', '/gestion/cashless', '/gestion/remises', '/gestion/nuit/entrees']) {
      const r = await k.goto(B + u);
      v(r.status() === 403, `promoteur : ${u} interdit (${r.status()})`);
    }
    await ctx.close();
  }
  // Comptable (nuit.voir) : voit les entrées, ne change pas les tarifs ; superviseure : pas les réglages des formules
  {
    const ctx = await b.newContext({ viewport: { width: 1366, height: 860 } });
    const c = await connecter(ctx, 'grace@demo.test', 'Compta-Goma-2026', err);
    let r = await c.goto(B + '/gestion/nuit/entrees');
    v(r.status() === 200, 'comptable : entrées visibles');
    const jc = await c.evaluate(() => (document.querySelector('[name=csrf]') || {}).value || '');
    r = await c.request.post(B + '/gestion/nuit/entrees/tarifs', { form: { csrf: jc, etablissement: 2, categorie: 'normal', nom_fr: 'Pirate' } });
    v(r.status() === 403 && sql("SELECT COUNT(*) FROM entree_tarifs WHERE nom_fr='Pirate'") === '0', 'comptable : créer un tarif refusé (403)');
    await ctx.close();
    const ctx2 = await b.newContext({ viewport: { width: 1366, height: 860 } });
    const rz = await connecter(ctx2, 'riziki@demo.test', 'Superviseur-2026', err);
    await rz.goto(B + '/gestion/nuit/entrees');
    const jr = await rz.evaluate(() => (document.querySelector('[name=csrf]') || {}).value || '');
    r = await rz.request.post(B + '/gestion/nuit/formules', { form: { csrf: jr, produit_id: 1 } });
    v(r.status() === 403, 'superviseure : enregistrer une formule refusé (403)');
    r = await rz.request.post(B + '/gestion/nuit/vestiaire/frais', { form: { csrf: jr, etablissement: 2 } });
    v(r.status() === 403, 'superviseure : frais du vestiaire refusés (403)');
    r = await rz.request.post(B + '/gestion/fidelite/reglages', { form: { csrf: jr, etablissement: 1 } });
    v(r.status() === 403, 'superviseure : réglages de la fidélité refusés (403)');
    r = await rz.request.post(B + '/gestion/nuit/entrees/tarifs', { form: { csrf: 'faux', etablissement: 2, categorie: 'normal', nom_fr: 'Pirate' } });
    v(r.status() === 419 || r.status() === 403, 'jeton CSRF faux : refusé');
    await ctx2.close();
  }
  // Modules : montrés seulement s'il y a un lounge ou un nightclub
  {
    const ctx = await b.newContext({ viewport: { width: 1366, height: 860 } });
    const p = await connecter(ctx, 'admin@demo.test', MDP, err);
    await p.goto(B + '/gestion');
    const avec = await p.textContent('main');
    v(avec.includes('Nuit et entrées') && avec.includes('Cashless'), 'modules : « Nuit et entrées » et « Cashless » avec le Volcano Club');
    sql("UPDATE etablissements SET type='restaurant_bar' WHERE id=2");
    await p.goto(B + '/gestion');
    const sans = await p.textContent('main');
    v(!sans.includes('Nuit et entrées') && !sans.includes('Cashless'), 'sans lounge ni nightclub : modules cachés');
    const r = await p.goto(B + '/caisse?e=volcano-club'); await p.waitForTimeout(500);
    v(r.status() === 200 && !(await donnees(p)).nuit.cashless, 'restaurant-bar : pas de cashless à la caisse');
    sql("UPDATE etablissements SET type='nightclub' WHERE id=2");
    await ctx.close();
  }

  // ---------- Caisse : bar (fenêtres), porte, vestiaire ----------
  for (const [nom, vue, mobile] of VUES) {
    for (const [langue, theme] of [['fr', 'nuit'], ['en', 'jour']]) {
      const ctx = await b.newContext({ viewport: vue, isMobile: mobile, hasTouch: mobile || nom === 'tablette', deviceScaleFactor: mobile ? 2 : 1 });
      await ctx.addInitScript(t => { try { localStorage.setItem('caisse.theme.volcano-club', t); } catch (e) {} }, theme);
      sql(`UPDATE utilisateurs SET langue='${langue}' WHERE id=1`);
      const p = await connecter(ctx, 'admin@demo.test', MDP, err);
      await ctx.addCookies([{ name: 'lang', value: langue, url: B }]);
      const cap = s => p.screenshot({ path: CAP + `c-${s}-${nom}-${langue}-${theme}.png` });
      await p.goto(B + '/caisse?e=volcano-club'); await p.evaluate(() => sessionStorage.clear()); await p.reload(); await p.waitForTimeout(700);
      v(await p.getAttribute('html', 'data-theme') === theme, `${nom} : caisse en thème ${theme}`);
      await verifier(p, `${nom} ${langue} ${theme} caisse du bar`);
      // Formule
      await p.locator('#grille .tuile', { has: p.locator('.tuile__nom', { hasText: /Johnnie Walker Black 70 cl \+ 4/ }) }).click(); await p.waitForSelector('#nuit-fenetre[open]');
      await verifier(p, `${nom} ${langue} ${theme} fenêtre de la formule`, '#nuit-fenetre'); await cap('formule');
      await p.keyboard.press('Escape');
      // Paiement : remise, carte
      await ajouter(p, ['Heineken']);
      if (mobile) { await p.click('#resume').catch(() => {}); await p.waitForTimeout(300); }
      await p.click('#encaisser'); await p.waitForSelector('#paiement[open]'); await p.waitForTimeout(300);
      await verifier(p, `${nom} ${langue} ${theme} paiement (zone nuit)`, '#paiement'); await cap('paiement');
      await p.click('#paiement-remise'); await p.waitForSelector('#nuit-fenetre[open]');
      await verifier(p, `${nom} ${langue} ${theme} fenêtre de remise`, '#nuit-fenetre'); await cap('remise');
      await p.fill('#remise-valeur', '50'); await p.click('#remise-appliquer'); await p.waitForTimeout(300);
      await verifier(p, `${nom} ${langue} ${theme} paiement avec validation`, '#paiement'); await cap('validation');
      await p.keyboard.press('Escape'); await p.waitForTimeout(200);
      if (await p.locator('#paiement[open]').count()) { await p.keyboard.press('Escape'); }
      // Cashless
      await p.click('#nuit-cashless'); await p.waitForSelector('#nuit-fenetre[open]');
      const carte = JSON.parse(sql("SELECT JSON_OBJECT('n', numero, 'j', jeton) FROM cartes_cashless WHERE numero=2"));
      await p.fill('.scanner__champ', 'C' + String(carte.n).padStart(4, '0') + '-' + carte.j); await p.click('.scanner__valider'); await p.waitForSelector('#carte-recharger');
      await verifier(p, `${nom} ${langue} ${theme} fenêtre cashless`, '#nuit-fenetre'); await cap('cashless');
      await p.keyboard.press('Escape');
      // Porte et vestiaire
      for (const poste of ['entree', 'vestiaire']) {
        await p.goto(B + `/caisse/${poste}?e=volcano-club`); await p.waitForTimeout(600);
        await verifier(p, `${nom} ${langue} ${theme} poste ${poste}`); await cap(poste);
        const bouton = poste === 'entree' ? '#nuit-controle' : '#nuit-rendre';
        await p.click(bouton); await p.waitForSelector('#nuit-fenetre[open]'); await p.waitForTimeout(300);
        await verifier(p, `${nom} ${langue} ${theme} ${poste} : fenêtre de lecture`, '#nuit-fenetre'); await cap(poste + '-lecture');
        await p.keyboard.press('Escape');
        if (poste === 'vestiaire') {
          await p.click('#nuit-restants'); await p.waitForSelector('#nuit-fenetre[open]'); await p.waitForTimeout(400);
          await verifier(p, `${nom} ${langue} ${theme} vestiaire : objets restants`, '#nuit-fenetre'); await cap('restants');
          await p.keyboard.press('Escape');
        }
      }
      await ctx.close();
    }
  }
  sql("UPDATE utilisateurs SET langue='fr' WHERE id=1");
  v(err.length === 0, 'aucune erreur JavaScript ' + err.slice(0, 5).join(' | '));
  await b.close(); bilan();
})();
