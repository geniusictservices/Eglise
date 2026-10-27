// Montants qui débordent de leur case (texte plus large que la boîte) : node chiffres.js <url> [largeur…]
const { chromium } = require('playwright'); const fs = require('fs');
const B = process.env.B || 'http://integ2.localhost:8250';
const MDP = fs.readFileSync('/home/user/Eglise/restau-bar-pos/outils/tests/instance.txt', 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
const [url0, ...largeurs] = process.argv.slice(2);
const urls = url0.startsWith('@') ? fs.readFileSync(url0.slice(1), 'utf8').split('\n').filter(Boolean) : [url0];
(async () => {
  const b = await chromium.launch(); let ko = 0;
  for (const l of (largeurs.length ? largeurs : ['1366', '1180', '390']).map(Number)) {
    const ctx = await b.newContext({ viewport: { width: l, height: 900 } }); const p = await ctx.newPage();
    await p.goto(B + '/gestion/connexion'); await p.fill('#email', 'admin@demo.test'); await p.fill('#mot_de_passe', MDP);
    await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
    for (const url of urls) {
    await p.goto(B + url); await p.waitForTimeout(150);
    if (process.env.SANS_CLASSE) { await p.evaluate(c => document.querySelectorAll('.' + c).forEach(e => e.classList.remove(c)), process.env.SANS_CLASSE); }
    const r = await p.evaluate(() => {
    const ignorer = e => { for (let x = e; x && x !== document.body; x = x.parentElement) { const st = getComputedStyle(x); if (x.matches('option, select, .visuellement-cache, [class$="-cache"], [class*="-cache "]') || st.clip === 'rect(0px, 0px, 0px, 0px)' || (st.overflowX === 'auto' || st.overflowX === 'scroll') && x.scrollWidth > x.clientWidth) return true; } return false; };
    return [...document.querySelectorAll('main *')].filter(e => { if (ignorer(e)) return false;
      if (!e.childNodes.length || ![...e.childNodes].some(n => n.nodeType === 3 && n.textContent.trim())) return false;
      const st = getComputedStyle(e); if (st.overflow === 'hidden' && st.textOverflow === 'ellipsis') return false;
      return e.scrollWidth > e.clientWidth + 1 && st.display !== 'inline';
    }).map(e => `${e.tagName.toLowerCase()}.${e.className} « ${e.textContent.trim().slice(0, 30)} » ${e.scrollWidth}>${e.clientWidth}`); });
    // Texte plus large que sa case (carte, chiffre, indicateur) : mesuré avec un Range sur le texte lui-même
    const sortent = await p.evaluate(() => {
    const ignorer = e => { for (let x = e; x && x !== document.body; x = x.parentElement) { const st = getComputedStyle(x); if (x.matches('option, select, .visuellement-cache, [class$="-cache"], [class*="-cache "]') || st.clip === 'rect(0px, 0px, 0px, 0px)' || (st.overflowX === 'auto' || st.overflowX === 'scroll') && x.scrollWidth > x.clientWidth) return true; } return false; };
      const SEL = '.chiffre, [class*="kpi"]:not([class*="kpis"]), [class*="carte"]:not([class*="cartes"]), td, th';
      const out = [];
      document.querySelectorAll('main ' + SEL.split(', ').join(', main ')).forEach(c => {
        const z = c.getBoundingClientRect(); if (!z.width || ignorer(c)) return;
        const w = document.createTreeWalker(c, NodeFilter.SHOW_TEXT);
        for (let n = w.nextNode(); n; n = w.nextNode()) {
          if (!n.textContent.trim() || ignorer(n.parentElement)) continue;
          const r = document.createRange(); r.selectNodeContents(n); const a = r.getBoundingClientRect();
          if (a.width && (a.right > z.right + 1 || a.left < z.left - 1)) { out.push(`« ${n.textContent.trim().slice(0, 30)} » sort de ${c.tagName.toLowerCase()}.${(c.className || '').toString().split(' ')[0]} (${Math.round(a.right - z.right)} px)`); break; }
        }
      });
      return [...new Set(out)];
    });
    if (r.length + sortent.length || urls.length === 1) { console.log(l + ' px ' + url, JSON.stringify(r.concat(sortent), null, 0)); } ko += r.length + sortent.length;
    if (urls.length === 1) { await p.screenshot({ path: __dirname + `/chiffres-${l}.png`, fullPage: true }); }
    }
    await ctx.close();
  }
  console.log(ko ? ko + ' débordement(s)' : 'aucun débordement'); await b.close();
})();
