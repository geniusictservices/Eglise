// Caisse partagée, plusieurs processus PHP : le PIN envoyé deux fois (double toucher, ou la première requête abandonnée
// par le navigateur après que le serveur l'a traitée) ne laisse jamais la caisse avec une session vide : la vente qui suit
// passe (pas de 401). Le serveur doit tourner avec PHP_CLI_SERVER_WORKERS >= 2.
const { chromium } = require('playwright'); const fs = require('fs'); const { execSync } = require('child_process');
const B = process.env.B || 'http://integ.localhost:8250'; const DB = process.env.DB || 'rbp_integ';
const MDP = fs.readFileSync('/home/user/Eglise/restau-bar-pos/outils/tests/instance.txt', 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
const sql = q => execSync(`mysql -N --default-character-set=utf8mb4 ${DB} -e ${JSON.stringify(q)}`).toString().trim();
let nb = 0; const ko = []; const v = (c, m) => { nb++; if (!c) ko.push(m); console.log((c ? 'ok    ' : 'ÉCHEC ') + m); };
(async () => {
  sql("UPDATE dettes SET garant_statut = 'refuse' WHERE garant_statut = 'en_attente'");
  const b = await chromium.launch(); const err = [];
  const ctx = await b.newContext({ viewport: { width: 1180, height: 820 } });
  const p = await ctx.newPage(); p.on('pageerror', x => err.push(x.message));
  await p.goto(B + '/gestion/connexion'); await p.fill('#email', 'admin@demo.test'); await p.fill('#mot_de_passe', MDP);
  await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  await p.goto(B + '/caisse?e=comptoir-du-lac'); await p.click('#quitter'); await p.click('.partager > summary');
  await Promise.all([p.waitForNavigation(), p.click('.partager__form button[type=submit]')]);
  const aline = sql("SELECT id FROM utilisateurs WHERE nom='Aline Mukeba'"), esther = sql("SELECT id FROM utilisateurs WHERE nom='Esther Kahindo'");
  const N = +(process.env.N || 8);
  let bons = 0, ventesOk = 0; const details = [];
  for (let i = 0; i < N; i++) {
    await p.goto(B + '/caisse'); await p.waitForSelector('.verrou__personne');
    // Deux envois du même PIN, le premier abandonné par le navigateur après quelques millisecondes
    const res = await p.evaluate(async ([uid, attente]) => {
      const csrf = document.querySelector('input[name=csrf]').value;
      const corps = () => { const f = new FormData(); f.append('csrf', csrf); f.append('utilisateur_id', uid); f.append('pin', '314159'); return f; };
      const c1 = new AbortController();
      const r1 = fetch('/caisse/pin', { method: 'POST', body: corps(), redirect: 'manual', signal: c1.signal, credentials: 'same-origin' }).then(r => r.status, () => 'abandon');
      setTimeout(() => c1.abort(), attente);
      const r2 = await fetch('/caisse/pin', { method: 'POST', body: corps(), redirect: 'manual', credentials: 'same-origin' }).then(r => r.type === 'opaqueredirect' ? 302 : r.status);
      return [await r1, r2];
    }, [esther, 3 + (i % 4) * 4]);
    await p.waitForTimeout(150);
    await p.goto(B + '/caisse');
    const ouverte = await p.locator('#caisse').count() === 1 && await p.locator('.verrou__personne').count() === 0;
    let vente = null;
    if (ouverte) {
      vente = await p.evaluate(async () => {
        const D = JSON.parse(document.getElementById('donnees-caisse').textContent);
        const f = D.produits.find(x => x.formats.some(y => y.disponible !== false)); const fo = f.formats[0];
        const c = D.comptes.find(x => x.type === 'caisse');
        const fd = new FormData(); fd.append('csrf', D.csrf);
        fd.append('vente', JSON.stringify({ cle: crypto.randomUUID(), etab: D.etab, lignes: [{ id: fo.id, qte: 1 }], paiements: [{ devise: D.principale, compte: c.id, recu: 10000000 }] }));
        const r = await fetch('/caisse/encaisser', { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json' } });
        return r.status;
      });
    }
    details.push(`${res[0]}/${res[1]}→${ouverte ? 'caisse' : 'verrou'}${vente ? ':' + vente : ''}`);
    if (ouverte) { bons++; } if (vente === 200) { ventesOk++; }
    // Verrouiller pour le tour suivant
    if (ouverte) { await p.evaluate(async () => { const D = JSON.parse(document.getElementById('donnees-caisse').textContent); const f = new FormData(); f.append('csrf', D.csrf); await fetch('/caisse/verrouiller', { method: 'POST', body: f, credentials: 'same-origin', redirect: 'manual' }); }); }
  }
  v(bons === N, `PIN envoyé deux fois (le premier abandonné) : la caisse s'ouvre ${bons}/${N} fois (${details.join(' ')})`);
  v(ventesOk === N, `la vente qui suit passe ${ventesOk}/${N} fois (jamais 401)`);
  v(err.length === 0, 'aucune erreur JS ' + err.join(' | '));
  console.log(`\n${nb - ko.length}/${nb}`); await b.close();
})();
