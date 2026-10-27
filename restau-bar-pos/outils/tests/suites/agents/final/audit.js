// Parcours complet : node audit.js [filtre-url]
// fr/en × jour/nuit × bureau/téléphone : code HTTP, erreurs JS, journal PHP, débordement, contraste, clés brutes.
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const base = 'http://integ.localhost:8250';
const dossier = path.join(__dirname, 'audit');
fs.mkdirSync(dossier, { recursive: true });
const journal = '/home/user/Eglise/restau-bar-pos/storage/logs/php-erreurs.log';
const mdp = fs.readFileSync(path.join(__dirname, '../../instance.txt'), 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
const filtre = process.argv[2] || '';
const seulement = (process.argv[3] || '').split(',').filter(Boolean); // ex. fr-nuit-tel

const BUREAU = { nom: 'bureau', width: 1366, height: 860 };
const TEL = { nom: 'tel', width: 390, height: 844, isMobile: true, hasTouch: true, deviceScaleFactor: 2 };

const publiques = process.env.EXTRA ? [] : ['/', '/e/comptoir-du-lac', '/e/volcano-club?zone=vip&devise=CDF', '/q/volc-vip', '/page-inexistante'];
const extra = (process.env.EXTRA || '').split(',').filter(Boolean);
const privees = extra.length ? extra : [
  '/gestion', '/gestion/utilisateurs', '/gestion/utilisateurs/2', '/gestion/utilisateurs/nouveau', '/gestion/utilisateurs/roles', '/gestion/utilisateurs/roles/2', '/gestion/utilisateurs/appareils', '/gestion/utilisateurs/journal', '/gestion/tableau-de-bord', '/gestion/tresorerie', '/gestion/tresorerie/transferts', '/gestion/depenses', '/gestion/depenses/nouvelle', '/gestion/catalogue', '/gestion/catalogue/produits/1', '/gestion/catalogue/produits/nouveau',
  '/gestion/catalogue/categories', '/gestion/etablissements', '/gestion/etablissements/nouveau',
  '/gestion/etablissements/1', '/gestion/etablissements/2', '/gestion/grilles', '/gestion/devises',
  '/gestion/devises/2', '/gestion/devises/nouvelle', '/gestion/compte', '/gestion/qr',
  '/gestion/qr/volc-vip/imprimer', '/gestion/design', '/caisse?e=comptoir-du-lac', '/caisse?e=volcano-club',
];

function tailleJournal() { try { return fs.statSync(journal).size; } catch (e) { return 0; } }
function lireJournalDepuis(pos) {
  const t = tailleJournal(); if (t <= pos) return '';
  const fd = fs.openSync(journal, 'r'); const buf = Buffer.alloc(t - pos); fs.readSync(fd, buf, 0, t - pos, pos); fs.closeSync(fd);
  return buf.toString('utf8');
}

// Analyse dans la page : débordement, contraste, clés brutes, thème effectif
function analyser() {
  function rgb(c) { const m = c.match(/[\d.]+/g); if (!m) return null; const v = m.map(Number); if (c.startsWith('color(')) { return v.slice(0, 3).map(x => x * 255).concat(v.length > 3 ? [v[3]] : []); } return v; }
  function lum(c) { return c.slice(0, 3).map(v => { v /= 255; return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); }).reduce((s, v, i) => s + v * [.2126, .7152, .0722][i], 0); }
  function melange(haut, bas) { const a = haut.length > 3 ? haut[3] : 1; return [0, 1, 2].map(i => haut[i] * a + bas[i] * (1 - a)); }
  function fond(el) {
    const pile = [];
    for (; el; el = el.parentElement) {
      const st = getComputedStyle(el);
      if (st.backgroundImage && st.backgroundImage !== 'none' && !st.backgroundImage.startsWith('linear-gradient')) return null; // photo : non vérifié
      const c = rgb(st.backgroundColor);
      if (c && (c.length < 4 || c[3] > 0)) { pile.push(c); if (c.length < 4 || c[3] >= 1) break; }
    }
    let res = [255, 255, 255];
    if (pile.length && (pile[pile.length - 1].length < 4 || pile[pile.length - 1][3] >= 1)) res = pile.pop().slice(0, 3);
    while (pile.length) res = melange(pile.pop(), res);
    return res;
  }
  const contraste = [];
  const cles = [];
  const motifCle = /^[a-z][a-z0-9_]*\.[a-z0-9_]+(\.[a-z0-9_]+)*$/;
  document.querySelectorAll('body *').forEach(el => {
    for (const attr of ['placeholder', 'title', 'aria-label', 'alt']) {
      const v = el.getAttribute(attr); if (v && motifCle.test(v.trim())) cles.push(attr + '=' + v);
    }
    if (el.matches('script, style, noscript, template')) return;
    const textes = [...el.childNodes].filter(n => n.nodeType === 3 && n.textContent.trim());
    if (!textes.length) return;
    textes.forEach(n => { const t = n.textContent.trim(); if (motifCle.test(t)) cles.push(t); });
    const r = el.getBoundingClientRect(); if (!r.width || !r.height) return;
    const st = getComputedStyle(el);
    if (st.visibility === 'hidden' || el.closest('[disabled], [aria-disabled="true"], .tuile--epuise, .produit--epuise, .visuellement-cache, .entete--photo, [hidden]')) return;
    let op = 1; for (let p = el; p; p = p.parentElement) op *= +getComputedStyle(p).opacity; if (op < 1) return;
    const c = rgb(st.color); const f = fond(el); if (!c || !f) return;
    const cc = c.length > 3 && c[3] < 1 ? melange(c, f) : c;
    const a = lum(cc), b = lum(f);
    const ratio = (Math.max(a, b) + .05) / (Math.min(a, b) + .05);
    const grand = parseFloat(st.fontSize) >= 24 || (parseFloat(st.fontSize) >= 18.6 && +st.fontWeight >= 700);
    if (ratio < (grand ? 3 : 4.5)) contraste.push(ratio.toFixed(2) + ' <' + el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().replace(/\s+/g, '.') : '') + '> « ' + el.textContent.trim().replace(/\s+/g, ' ').slice(0, 40) + ' »');
  });
  const doc = document.documentElement;
  return {
    theme: window.themeVolcan ? window.themeVolcan.effectif() : doc.dataset.theme,
    debord: Math.max(doc.scrollWidth, document.body.scrollWidth) - doc.clientWidth,
    contraste: [...new Set(contraste)],
    cles: [...new Set(cles)],
    titre: document.title,
  };
}

(async () => {
  const navigateur = await chromium.launch();
  const bilan = [];
  let nbPages = 0;
  for (const langue of ['fr', 'en']) {
    for (const theme of ['jour', 'nuit']) {
      for (const vue of [BUREAU, TEL]) {
        const combo = `${langue}-${theme}-${vue.nom}`;
        if (seulement.length && !seulement.includes(combo)) continue;
        const ctx = await navigateur.newContext({
          viewport: { width: vue.width, height: vue.height }, deviceScaleFactor: vue.deviceScaleFactor || 1,
          isMobile: !!vue.isMobile, hasTouch: !!vue.hasTouch, locale: langue === 'fr' ? 'fr-FR' : 'en-US',
          colorScheme: theme === 'nuit' ? 'dark' : 'light',
        });
        await ctx.addCookies([{ name: 'lang', value: langue, url: base }]);
        await ctx.addInitScript((t) => {
          try { ['vitrine.theme', 'gestion.theme', 'caisse.theme.comptoir-du-lac', 'caisse.theme.volcano-club'].forEach(k => localStorage.setItem(k, t)); } catch (e) {}
        }, theme);
        const p = await ctx.newPage();
        let erreursJs = [];
        p.on('pageerror', e => erreursJs.push('pageerror: ' + e.message));
        p.on('console', m => { if (m.type() === 'error') erreursJs.push('console: ' + m.text()); });
        p.on('dialog', d => { erreursJs.push('dialog: ' + d.message()); d.dismiss(); });

        const liste = [...publiques, '/gestion/connexion', 'CONNEXION', ...privees];
        for (const url of liste) {
          if (url === 'CONNEXION') {
            await p.goto(base + '/gestion/connexion');
            await p.fill('#email', 'admin@demo.test'); await p.fill('#mot_de_passe', mdp);
            await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
            await ctx.addCookies([{ name: 'lang', value: langue, url: base }]); // la connexion applique la langue du compte
            continue;
          }
          if (filtre && !url.includes(filtre)) continue;
          erreursJs = [];
          const pos = tailleJournal();
          const rep = await p.goto(base + url, { waitUntil: 'networkidle' });
          const statut = rep ? rep.status() : 0;
          const notes = [];
          // Actions propres à la caisse
          if (url.startsWith('/caisse')) {
            await p.waitForSelector('.tuile');
            const tuiles = await p.$$('.tuile:not(:disabled)');
            for (const i of [0, 0, 2, 4]) {
              if (!tuiles[i]) continue;
              await tuiles[i].click();
              if (await p.$('#formats[open]')) { const f = await p.$$('#formats-liste .format'); await f[f.length - 1].click(); }
            }
            const lignes = await p.$$eval('#lignes li', l => l.length);
            if (lignes < 2) notes.push('ticket : ' + lignes + ' ligne(s)');
            if (vue === TEL) {
              const resume = await p.$('#resume:not([hidden])');
              if (!resume) notes.push('pas de bouton résumé sur téléphone');
              else { await resume.click(); await p.waitForTimeout(400); const visible = await p.isVisible('#ticket'); if (!visible) notes.push('panneau ticket invisible'); }
            }
          }
          await p.waitForTimeout(250);
          const a = await p.evaluate(analyser);
          const php = lireJournalDepuis(pos);
          const attendu = url === '/page-inexistante' ? 404 : 200;
          const pb = [];
          if (statut !== attendu) pb.push('HTTP ' + statut);
          if (url === '/q/volc-vip' && !p.url().includes('zone=vip')) pb.push('redirection QR : ' + p.url());
          if (erreursJs.length) pb.push('JS : ' + erreursJs.join(' | '));
          if (php) pb.push('PHP : ' + php.trim().slice(0, 400));
          if (vue === TEL && a.debord > 0) pb.push('débordement ' + a.debord + ' px');
          if (a.theme !== theme && !url.startsWith('/gestion/qr/')) pb.push('thème ' + a.theme);
          if (a.contraste.length) pb.push('contraste : ' + a.contraste.slice(0, 8).join(' ; ') + (a.contraste.length > 8 ? ' …+' + (a.contraste.length - 8) : ''));
          if (a.cles.length) pb.push('clés : ' + a.cles.join(', '));
          pb.push(...notes);
          const nom = combo + '_' + url.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '') + '.png';
          await p.screenshot({ path: path.join(dossier, nom || 'accueil.png'), fullPage: true });
          nbPages++;
          bilan.push({ combo, url, statut, pb });
          console.log((pb.length ? 'KO ' : 'ok ') + combo + ' ' + url + (pb.length ? '\n   - ' + pb.join('\n   - ') : ''));
        }
        await ctx.close();
      }
    }
  }
  await navigateur.close();
  const ko = bilan.filter(b => b.pb.length);
  fs.writeFileSync(path.join(__dirname, 'audit.json'), JSON.stringify(bilan, null, 1));
  console.log(`\n${nbPages} pages, ${ko.length} avec problème`);
})();
