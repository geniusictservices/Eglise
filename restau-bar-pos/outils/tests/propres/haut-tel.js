// Barre du haut de la caisse sur téléphone : chaque bouton reçoit bien le toucher à son centre (aucun chevauchement),
// pas de défilement horizontal, cibles de 44 px, fenêtre des garanties entière à l'écran (gérant avec une garantie à confirmer)
const { chromium } = require('playwright'); const fs = require('fs'); const { execSync } = require('child_process');
const B = process.env.B || 'http://integ.localhost:8250'; const DB = process.env.DB || 'rbp_integ';
const MDP = fs.readFileSync('/home/user/Eglise/restau-bar-pos/outils/tests/instance.txt', 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
const sql = q => execSync(`mysql -N --default-character-set=utf8mb4 ${DB} -e ${JSON.stringify(q)}`).toString().trim();
let nb = 0; const ko = []; const v = (c, m) => { nb++; if (!c) ko.push(m); console.log((c ? 'ok    ' : 'ÉCHEC ') + m); };
(async () => {
  v(+sql("SELECT COUNT(*) FROM dettes WHERE garant_id=2 AND garant_statut='en_attente' AND statut='ouverte'") >= 1, 'Patrick a une garantie à confirmer (démo)');
  const b = await chromium.launch(); const err = [];
  for (const [qui, email, mdp, etab] of [['Patrick', 'patrick@demo.test', 'Gerant-Goma-2026', 'comptoir-du-lac'], ['admin', 'admin@demo.test', MDP, 'comptoir-du-lac'], ['admin', 'admin@demo.test', MDP, 'volcano-club'], ['Riziki', 'riziki@demo.test', 'Superviseur-2026', 'volcano-club']]) {
    for (const l of [390, 360]) {
      const ctx = await b.newContext({ viewport: { width: l, height: 800 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2, serviceWorkers: process.env.ANCIEN ? 'block' : 'allow' });
      if (process.env.ANCIEN) { await ctx.route('**/assets/css/caisse.css*', r => r.fulfill({ contentType: 'text/css', body: fs.readFileSync(__dirname + '/ancien-caisse.css') })); }
      const p = await ctx.newPage(); p.on('pageerror', x => err.push(x.message));
      await p.goto(B + '/gestion/connexion'); await p.fill('#email', email); await p.fill('#mot_de_passe', mdp);
      await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
      await p.goto(B + '/caisse?e=' + etab); await p.waitForSelector('#caisse'); await p.waitForTimeout(600);
      const nom = `${qui} ${etab} ${l} px`;
      const fen = await p.evaluate(() => { const d = document.querySelector('dialog[open]'); if (!d) return null; const r = d.getBoundingClientRect(); return { id: d.id, gauche: r.left, droite: r.right, l: innerWidth, coupe: [...d.querySelectorAll('*')].some(e => e.getBoundingClientRect().right > innerWidth + 0.5) }; });
      if (fen) {
        v(fen.gauche >= 0 && fen.droite <= fen.l + 0.5 && !fen.coupe, `${nom} : fenêtre « ${fen.id} » entière à l'écran (${Math.round(fen.gauche)}–${Math.round(fen.droite)} / ${fen.l})`);
        await p.keyboard.press('Escape'); await p.waitForTimeout(300);
      }
      const r = await p.evaluate(() => {
        const W = document.documentElement.clientWidth;
        const boutons = [...document.querySelectorAll('.haut button, .haut a, .haut select, .haut input')].filter(e => { const b = e.getBoundingClientRect(); return b.width && b.height && getComputedStyle(e).visibility !== 'hidden' && !e.closest('[hidden]'); });
        const voles = boutons.filter(e => { const b = e.getBoundingClientRect(); const x = b.left + b.width / 2, y = b.top + b.height / 2; const t = document.elementFromPoint(x, y); return !(t && (t === e || e.contains(t) || t.contains(e) && t.tagName === 'LABEL')); })
          .map(e => { const b = e.getBoundingClientRect(); const t = document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2); return (e.id || e.className) + ' ← ' + (t ? (t.closest('button, a') || t).id || t.className : 'rien'); });
        const petits = boutons.filter(e => e.tagName !== 'INPUT' || e.type !== 'hidden').filter(e => { const b = e.getBoundingClientRect(); return b.height < 43.5 || b.width < 43.5; }).map(e => (e.id || e.className) + ' ' + Math.round(e.getBoundingClientRect().width) + '×' + Math.round(e.getBoundingClientRect().height));
        return { debord: document.documentElement.scrollWidth - W, voles, petits, n: boutons.length };
      });
      v(r.debord <= 0, `${nom} : pas de défilement horizontal (${r.debord})`);
      v(r.voles.length === 0, `${nom} : les ${r.n} commandes du haut reçoivent leur toucher ${r.voles.join(', ')}`);
      v(r.petits.length === 0, `${nom} : cibles de 44 px ${r.petits.join(', ')}`);
      // Toucher la personne ouvre bien sa fenêtre (et pas les ardoises)
      await p.locator('#quitter').tap({ timeout: 3000 }).catch(() => {}); await p.waitForTimeout(300);
      v(await p.evaluate(() => { const d = document.querySelector('dialog[open]'); return d ? d.id : ''; }) === 'fenetre-quitter', `${nom} : toucher la personne ouvre sa fenêtre (${await p.evaluate(() => (document.querySelector('dialog[open]') || {}).id || 'aucune')})`);
      if (l === 390 && qui === 'Patrick') { await p.keyboard.press('Escape'); await p.screenshot({ path: __dirname + '/haut-tel-patrick.png' }); }
      await ctx.close();
    }
  }
  v(err.length === 0, 'aucune erreur JS ' + err.join(' | '));
  console.log(`\n${nb - ko.length}/${nb}`); await b.close();
})();
