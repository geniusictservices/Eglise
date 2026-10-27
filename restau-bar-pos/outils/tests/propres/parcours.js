// Parcours de toutes les routes GET (app/routes/*.php et public/index.php), comme l'administrateur, Patrick (gérant),
// Grace (comptable) et Aline (serveuse, caisse partagée) ; FR et EN ; téléphone 390 px (et ordinateur pour l'administrateur).
// Contrôles : pas de 5xx, rien de nouveau dans le journal PHP, pas d'avertissement PHP dans la page, pas de clé de traduction
// brute, pas d'erreur JS, pas de défilement horizontal à 390 px ; à la caisse, cibles tactiles de 44 px au moins.
// node parcours.js [persona,…] [filtre]
const { chromium } = require('playwright'); const fs = require('fs'); const { execSync } = require('child_process');
const B = process.env.B || 'http://integ.localhost:8250'; const R = '/home/user/Eglise/restau-bar-pos';
const MDP = fs.readFileSync('/home/user/Eglise/restau-bar-pos/outils/tests/instance.txt', 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
const JOURNAL = R + '/storage/logs/php-erreurs.log';
const sql = q => execSync(`mysql -N --default-character-set=utf8mb4 ${process.env.DB || 'rbp_integ'} -e ${JSON.stringify(q)}`).toString().trim().split('\n')[0] || '';
const seulement = (process.argv[2] || '').split(',').filter(Boolean); const filtre = process.argv[3] || '';

// Routes GET déclarées
let routes = [];
for (const f of [R + '/public/index.php', ...fs.readdirSync(R + '/app/routes').filter(n => n.endsWith('.php')).map(n => R + '/app/routes/' + n)]) {
  for (const m of fs.readFileSync(f, 'utf8').matchAll(/->get\('([^']+)'/g)) { routes.push(m[1]); }
}
routes = [...new Set(routes)].filter(r => !r.startsWith('/console') && r !== '/connexion');
const capture = fs.readdirSync(R + '/docs/manuel/captures').find(n => n.endsWith('.jpg')) || 'x.jpg';
const valeurs = {
  '/caisse/bon/{id}': sql('SELECT id FROM note_envois ORDER BY id LIMIT 1'),
  '/caisse/cuisine/bon/{id}': sql('SELECT id FROM note_envois ORDER BY id LIMIT 1'),
  '/caisse/clients/{id}': sql("SELECT client_id FROM dettes WHERE statut='ouverte' AND etablissement_id=1 LIMIT 1"),
  '/caisse/notes/{id}': sql("SELECT id FROM notes WHERE statut='ouverte' AND etablissement_id=1 LIMIT 1"),
  '/e/{slug}': 'comptoir-du-lac', '/q/{code}': 'volc-vip',
  '/gestion/achats/commandes/{id}': sql('SELECT id FROM commandes_achat ORDER BY id LIMIT 1'),
  '/gestion/achats/fournisseurs/{id}': sql('SELECT id FROM fournisseurs ORDER BY id LIMIT 1'),
  '/gestion/achats/receptions/{id}': sql('SELECT id FROM receptions ORDER BY id LIMIT 1'),
  '/gestion/aide/captures/{nom}.jpg': capture.replace(/\.jpg$/, ''),
  '/gestion/assistance/entrer/{jeton}': 'a'.repeat(48),
  '/gestion/cashless/{id}': sql('SELECT id FROM cartes_cashless ORDER BY id LIMIT 1'),
  '/gestion/catalogue/produits/{id}': '1',
  '/gestion/clients/{id}': sql("SELECT client_id FROM dettes WHERE statut='ouverte' AND etablissement_id=1 LIMIT 1"),
  '/gestion/depenses/{id}': sql('SELECT id FROM depenses ORDER BY id LIMIT 1'),
  '/gestion/dettes/{id}': sql("SELECT id FROM dettes WHERE statut='ouverte' AND etablissement_id=1 LIMIT 1"),
  '/gestion/devises/{id}': '2', '/gestion/etablissements/{id}': '1',
  '/gestion/evenements/{id}': sql('SELECT id FROM evenements ORDER BY debut LIMIT 1'),
  '/gestion/mediatheque/{id}': sql('SELECT id FROM medias ORDER BY id LIMIT 1'),
  '/gestion/nuit/promoteurs/{id}': sql('SELECT id FROM promoteurs ORDER BY id LIMIT 1'),
  '/gestion/personnel/employes/{id}': sql("SELECT id FROM employes WHERE nom LIKE 'Esther%' LIMIT 1"),
  '/gestion/personnel/paie/{id}': sql('SELECT id FROM bulletins ORDER BY id LIMIT 1'),
  '/gestion/personnel/presence/pointages/{id}': sql('SELECT id FROM pointages ORDER BY id LIMIT 1'),
  '/gestion/qr/{code}': 'volc-vip',
  '/gestion/remboursements/{id}': sql('SELECT id FROM remboursements ORDER BY id LIMIT 1'),
  '/gestion/salle/notes/{id}': sql("SELECT id FROM notes WHERE etablissement_id=1 ORDER BY id LIMIT 1"),
  '/gestion/sessions-caisse/{id}': sql('SELECT id FROM sessions_caisse ORDER BY id LIMIT 1') || '999999',
  '/gestion/stock/inventaires/{id}': sql('SELECT id FROM inventaires ORDER BY id LIMIT 1'),
  '/gestion/stock/recettes/{id}': sql('SELECT id FROM recettes ORDER BY id LIMIT 1'),
  '/gestion/stock/transferts/{id}': sql('SELECT id FROM transferts_stock ORDER BY id LIMIT 1'),
  '/gestion/tresorerie/comptes/{id}': '1',
  '/gestion/utilisateurs/roles/{id}': '2', '/gestion/utilisateurs/{id}': '2',
  '/gestion/ventes/{id}': sql("SELECT id FROM ventes WHERE etablissement_id=1 AND statut='payee' ORDER BY id DESC LIMIT 1"),
};
const documentId = sql('SELECT id FROM employe_documents LIMIT 1');
function concret(r) {
  if (r.includes('/documents/{document}')) { return documentId ? r.replace('{id}', valeurs['/gestion/personnel/employes/{id}']).replace('{document}', documentId) : null; }
  if (!r.includes('{')) { return r; }
  const base = Object.keys(valeurs).find(k => r === k || r.startsWith(k.replace(/\{[a-z]+\}.*$/, '')) && r.replace(/\/modifier$|\/imprimer$/, '') === k);
  const cle = valeurs[r] !== undefined ? r : base;
  if (!cle || !valeurs[cle]) { return null; }
  return r.replace(/\{[a-z]+\}/, valeurs[cle]);
}
let pages = routes.map(r => [r, concret(r)]).filter(([r, c]) => { if (c === null) { console.log('sans valeur : ' + r); } return c !== null; });
// L'entrée et le vestiaire en dernier : sur une caisse partagée, une personne sans ce poste y verrouille la caisse (voulu)
const verrouillants = ['/caisse/entree', '/caisse/vestiaire'];
pages = pages.filter(([r]) => !verrouillants.includes(r)).concat(pages.filter(([r]) => verrouillants.includes(r)));
// Pages de la caisse : avec l'établissement
const avecEtab = u => (u === '/caisse' || u.startsWith('/caisse/cuisine') && !u.includes('/bon/')) ? u + (u.includes('?') ? '&' : '?') + (u === '/caisse' ? 'e=comptoir-du-lac' : 'etab=comptoir-du-lac') : u;

// Toutes les clés de traduction (FR et EN, communes et des modules) : un texte visible égal à une clé est une clé brute
const CLES = JSON.parse(execSync(`php -r '$k = []; foreach (["fr", "en"] as $l) { $k += require "${R}/lang/$l.php"; foreach (glob("${R}/lang/$l/*.php") as $f) { $k += require $f; } } echo json_encode(array_keys($k));'`).toString());
function analyser([estCaisse, cles0]) {
  // Une clé brute (manquante ou non traduite) commence par un préfixe de clé connu : « gestion.xxx », « caisse.xxx »…
  const prefixes = new Set(cles0.map(k => k.split('.')[0]));
  const motifCle = { test: t => /^[a-z][a-z0-9_]*\.[a-z0-9_]+(\.[a-z0-9_]+)*$/.test(t) && prefixes.has(t.split('.')[0]) };
  const cles = [];
  document.querySelectorAll('body *').forEach(el => {
    for (const attr of ['placeholder', 'title', 'aria-label', 'alt', 'value']) {
      const v = el.getAttribute(attr); if (v && motifCle.test(v.trim()) && !(attr === 'value' && ['hidden', 'checkbox', 'radio'].includes(el.type)) && !(attr === 'value' && el.tagName === 'OPTION')) cles.push(attr + '=' + v);
    }
    if (el.matches('script, style, noscript, template')) return;
    [...el.childNodes].filter(n => n.nodeType === 3).forEach(n => { const t = n.textContent.trim(); if (t && motifCle.test(t)) cles.push(t); });
  });
  const doc = document.documentElement;
  const texte = document.body ? document.body.innerText : '';
  const php = (texte.match(/(Warning|Notice|Deprecated|Fatal error|Parse error|Uncaught)\b[^\n]{0,160}/g) || []).filter(l => /\.php|on line|Stack trace/.test(l) || /^(Fatal|Parse)/.test(l));
  const petites = [];
  if (estCaisse) {
    document.querySelectorAll('button, a[href], [role=button], input:not([type=hidden]), select, summary').forEach(el => {
      const r = el.getBoundingClientRect(); const st = getComputedStyle(el);
      if (!r.width || !r.height || st.visibility === 'hidden' || el.closest('[hidden], dialog:not([open]), details:not([open]) > :not(summary)')) return;
      if (el.matches('input[type=checkbox], input[type=radio]') && el.closest('label') && el.closest('label').getBoundingClientRect().height >= 44) return;
      if (r.height < 44 - 0.5 || r.width < 44 - 0.5) petites.push(`${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''}${el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/)[0] : ''} « ${(el.textContent || el.getAttribute('aria-label') || '').trim().replace(/\s+/g, ' ').slice(0, 24)} » ${Math.round(r.width)}×${Math.round(r.height)}`);
    });
  }
  const debord = Math.max(doc.scrollWidth, document.body ? document.body.scrollWidth : 0) - doc.clientWidth;
  const fautifs = debord > 0 ? [...document.querySelectorAll('body *')].filter(el => { const r = el.getBoundingClientRect(); return r.width && r.right > doc.clientWidth + 1 && !el.closest('.onglets, [class*="defile"]'); })
    .slice(0, 5).map(el => `${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''}.${(typeof el.className === 'string' ? el.className : '').trim().replace(/\s+/g, '.')} (${Math.round(el.getBoundingClientRect().right)} px, ${getComputedStyle(el).position})`) : [];
  return { debord, fautifs, cles: [...new Set(cles)], php, petites: [...new Set(petites)], titre: document.title };
}
const tailleJournal = () => { try { return fs.statSync(JOURNAL).size; } catch (e) { return 0; } };
const journalDepuis = pos => { const t = tailleJournal(); if (t <= pos) return ''; const fd = fs.openSync(JOURNAL, 'r'); const b = Buffer.alloc(t - pos); fs.readSync(fd, b, 0, t - pos, pos); fs.closeSync(fd); return b.toString(); };

const PERSONAS = {
  admin: { email: 'admin@demo.test', mdp: MDP },
  patrick: { email: 'patrick@demo.test', mdp: 'Gerant-Goma-2026' },
  grace: { email: 'grace@demo.test', mdp: 'Compta-Goma-2026' },
  aline: { partagee: true, nom: 'Aline Mukeba', pin: '271828' },
};
(async () => {
  const b = await chromium.launch();
  const bilan = []; const statuts = {};
  for (const [nom, pers] of Object.entries(PERSONAS)) {
    if (seulement.length && !seulement.includes(nom)) continue;
    const vues = [['fr', 'tel'], ['en', 'tel']].concat(nom === 'admin' ? [['fr', 'ordi'], ['en', 'ordi']] : []);
    for (const [langue, vue] of vues) {
      const ctx = await b.newContext(vue === 'tel' ? { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 } : { viewport: { width: 1366, height: 860 } });
      const p = await ctx.newPage();
      let js = [];
      p.on('pageerror', e => js.push('pageerror: ' + e.message));
      // Les ressources en erreur sont relevées par leur réponse (la page elle-même, une API ouverte directement, peut répondre 4xx)
      let ressources = [];
      p.on('console', m => { if (m.type() === 'error' && !/^Failed to load resource/.test(m.text())) js.push('console: ' + m.text().slice(0, 200)); });
      p.on('response', r => { if (r.status() >= 400 && r.request().resourceType() !== 'document') ressources.push(r.status() + ' ' + r.url().replace(B, '')); });
      p.on('requestfailed', r => { if (!/ERR_ABORTED/.test(r.failure()?.errorText || '')) ressources.push('échec ' + r.url().replace(B, '') + ' ' + (r.failure()?.errorText || '')); });
      p.on('dialog', d => d.dismiss());
      // Connexion
      if (pers.partagee) {
        await p.goto(B + '/gestion/connexion'); await p.fill('#email', 'admin@demo.test'); await p.fill('#mot_de_passe', MDP);
        await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
        await p.goto(B + '/caisse?e=comptoir-du-lac'); await p.click('#quitter'); await p.click('.partager > summary');
        await Promise.all([p.waitForNavigation(), p.click('.partager__form button[type=submit]')]);
        await p.click(`.verrou__personne[data-nom="${pers.nom}"]`); for (const c of pers.pin) { await p.click(`#pave-touches [data-chiffre="${c}"]`); }
        await p.waitForLoadState('load'); await p.waitForTimeout(400);
      } else {
        await p.goto(B + '/gestion/connexion'); await p.fill('#email', pers.email); await p.fill('#mot_de_passe', pers.mdp);
        await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
      }
      await ctx.addCookies([{ name: 'lang', value: langue, url: B }]);
      const combo = `${nom}-${langue}-${vue}`;
      for (const [route, chemin] of pages) {
        const url = avecEtab(chemin);
        if (filtre && !url.includes(filtre)) continue;
        js = []; ressources = []; const pos = tailleJournal();
        let rep, erreur = null;
        try { rep = await p.goto(B + url, { waitUntil: 'load', timeout: 30000 }); } catch (e) { erreur = e.message.split('\n')[0]; }
        if (!rep && erreur && /Download is starting/.test(erreur)) { erreur = null; }
        const st = rep ? rep.status() : (erreur ? 0 : 200);
        const type = rep ? (rep.headers()['content-type'] || '') : '';
        const pb = [];
        if (erreur) pb.push('navigation : ' + erreur);
        if (st >= 500) pb.push('HTTP ' + st);
        if (type.includes('text/html') && st < 400 && !/\/caisse\/(bon|cuisine\/bon|clients)\//.test(url)) {
          await p.waitForTimeout(250);
          const a = await p.evaluate(analyser, [url.startsWith('/caisse') && !url.startsWith('/caisse/cuisine'), CLES]);
          if (a.cles.length) pb.push('clés brutes : ' + a.cles.join(', '));
          if (a.php.length) pb.push('PHP dans la page : ' + a.php.join(' | '));
          if (vue === 'tel' && a.debord > 0) pb.push('défilement horizontal ' + a.debord + ' px : ' + a.fautifs.join(', '));
          if (a.petites.length) pb.push('cibles < 44 px : ' + a.petites.slice(0, 10).join(' ; ') + (a.petites.length > 10 ? ` …+${a.petites.length - 10}` : ''));
        } else if (st < 400 && !erreur) {
          const corps = rep ? await rep.text().catch(() => '') : '';
          if (/(Warning|Notice|Deprecated|Fatal error)<\/b>:|on line <b>\d+/.test(corps)) pb.push('PHP dans la réponse');
        }
        if (js.length) pb.push('JS : ' + [...new Set(js)].join(' | '));
        if (ressources.length) pb.push('ressources en erreur : ' + [...new Set(ressources)].slice(0, 6).join(' | '));
        const php = journalDepuis(pos).trim(); if (php) pb.push('journal PHP : ' + php.slice(0, 300));
        statuts[route] = statuts[route] || {}; statuts[route][combo] = st;
        bilan.push({ combo, url, st, pb });
        console.log((pb.length ? 'KO ' : 'ok ') + combo + ' ' + st + ' ' + url + (pb.length ? '\n   - ' + pb.join('\n   - ') : ''));
      }
      await ctx.close();
    }
  }
  await b.close();
  fs.writeFileSync(__dirname + '/parcours.json', JSON.stringify({ bilan, statuts }, null, 1));
  const ko = bilan.filter(x => x.pb.length);
  console.log(`\n${bilan.length} pages, ${ko.length} avec problème`);
})();
