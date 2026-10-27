// Visite de tous les écrans du module : débordement horizontal, erreurs JS, cibles < 44 px, captures (FR/EN, jour/nuit, 1366 et 390).
const { chromium } = require('playwright'); const { execSync } = require('child_process');
const B = 'http://integ.localhost:8250'; const D = __dirname;
const sql = q => execSync(`mysql -N --default-character-set=utf8mb4 rbp_integ -e ${JSON.stringify("SET time_zone = '+02:00'; " + q)}`).toString().trim();
const pages = process.argv[2] ? process.argv[2].split(',') : null;
(async () => {
  const b = await chromium.launch();
  const bulletinBrouillon = sql("SELECT id FROM bulletins WHERE statut='brouillon' LIMIT 1"), bulletinPaye = sql("SELECT id FROM bulletins WHERE statut='paye' LIMIT 1");
  const employe = sql("SELECT id FROM employes WHERE nom LIKE 'Esther%'"), pointage = sql('SELECT MAX(id) FROM pointages');
  const ecrans = { employes: '/gestion/personnel/employes', fiche: '/gestion/personnel/employes/' + employe, formulaire: '/gestion/personnel/employes/' + employe + '/modifier',
    presence: '/gestion/personnel/presence?etablissement=2&jour=' + sql("SELECT MAX(journee) FROM pointages WHERE etablissement_id=2"), pointages: '/gestion/personnel/presence/pointages?periode=7j',
    correction: '/gestion/personnel/presence/pointages/' + pointage, bornes: '/gestion/personnel/presence/bornes', reglages: '/gestion/personnel/presence/reglages',
    plannings: '/gestion/personnel/plannings', feries: '/gestion/personnel/plannings/feries', conges: '/gestion/personnel/conges', paie: '/gestion/personnel/paie',
    brouillon: '/gestion/personnel/paie/' + bulletinBrouillon, paye: '/gestion/personnel/paie/' + bulletinPaye, imprimer: '/gestion/personnel/paie/' + bulletinPaye + '/imprimer',
    masse: '/gestion/personnel/paie/masse', avances: '/gestion/personnel/avances', rapport: '/gestion/rapports/presence?periode=mois', pointer: '/pointer' };
  let probleme = 0;
  for (const [l, h, nom] of [[1366, 860, 'ordi'], [390, 844, 'tel']]) {
    for (const theme of ['jour', 'nuit']) {
      for (const langue of ['fr', 'en']) {
        const ctx = await b.newContext({ viewport: { width: l, height: h }, colorScheme: theme === 'nuit' ? 'dark' : 'light' });
        const p = await ctx.newPage(); const err = [];
        p.on('pageerror', x => err.push(x.message)); p.on('console', m => m.type() === 'error' && !m.text().includes('Failed to load resource') && err.push(m.text()));
        await p.goto(B + '/gestion/connexion?lang=' + langue); await p.fill('#email', 'admin@demo.test'); await p.fill('#mot_de_passe', 'Essai-Integ-2026');
        await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
        for (const [cle, url] of Object.entries(ecrans)) {
          if (pages && !pages.includes(cle)) continue;
          const r = await p.goto(B + url); await p.waitForTimeout(150);
          const mesure = await p.evaluate(() => {
            const larg = document.documentElement.scrollWidth - window.innerWidth;
            const petits = [...document.querySelectorAll('a, button, input:not([type=hidden]), select, summary, textarea')].filter(e => {
              const r = e.getBoundingClientRect(); if (!r.width || !r.height || getComputedStyle(e).visibility === 'hidden') return false;
              if (e.closest('.defile, table, .visuellement-cache') && r.height >= 24) return false; // liens dans un tableau : ligne entière
              if (e.type === 'checkbox' || e.type === 'radio' || e.classList.contains('visuellement-cache')) return false;
              return r.height < 44 && !(e.tagName === 'A' && getComputedStyle(e).display === 'inline');
            }).map(e => (e.tagName + '.' + e.className + ' «' + (e.textContent || e.name || '').trim().slice(0, 25) + '» ' + Math.round(e.getBoundingClientRect().height)));
            return { larg, petits: petits.slice(0, 6), php: /Warning|Fatal error|Notice:|Deprecated:/.test(document.body.innerText) };
          });
          const ok = r.status() === 200 && mesure.larg <= 0 && !mesure.php;
          if (!ok || mesure.petits.length) { probleme++; console.log(`${nom} ${theme} ${langue} ${cle}: statut ${r.status()} débord ${mesure.larg} php ${mesure.php} petits ${JSON.stringify(mesure.petits)}`); }
          if (langue === 'fr' || ['fiche', 'brouillon', 'plannings'].includes(cle)) await p.screenshot({ path: `${D}/${cle}-${nom}-${theme}-${langue}.png`, fullPage: true });
        }
        if (err.length) { probleme++; console.log(`${nom} ${theme} ${langue} erreurs JS: ${err.join(' | ')}`); }
        await ctx.close();
      }
    }
  }
  console.log(probleme ? probleme + ' problème(s)' : 'aucun problème'); await b.close();
})();
