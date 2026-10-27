// Manuel PDF (A4) depuis docs/manuel/index.html et sa feuille d'impression :
// couverture sans en-tête, puis le corps avec en-tête et pied de page (numéros de page) ;
// les numéros de page du sommaire viennent du PDF lui-même (pages.json, puis seconde passe).
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const ICI = __dirname;
const MANUEL = '/home/user/Eglise/restau-bar-pos/docs/manuel';
const SORTIE = path.join(MANUEL, 'manuel-restau-bar-pos.pdf');
const TMP = path.join(ICI, 'pdf-tmp'); fs.mkdirSync(TMP, { recursive: true });

const entete = `<div style="width:100%;font:7.5px Arial,sans-serif;color:#5B6168;padding:0 14mm;display:flex;justify-content:space-between">
  <span>restau-bar-pos · Manuel d'utilisation</span><span>27 octobre 2026</span></div>`;
const pied = `<div style="width:100%;font:7.5px Arial,sans-serif;color:#5B6168;padding:0 14mm;display:flex;justify-content:space-between">
  <span>Caisse · Gestion · Plateforme</span><span>Page <span class="pageNumber"></span> / <span class="totalPages"></span></span></div>`;

// Copie du manuel avec des images réduites (le PDF reste léger) ; l'HTML est recopié à chaque passe
const SITE = path.join(TMP, 'site');
function preparerSite() {
  fs.mkdirSync(path.join(SITE, 'captures'), { recursive: true });
  const actuelles = new Set(fs.readdirSync(path.join(MANUEL, 'captures')));
  for (const f of fs.readdirSync(path.join(SITE, 'captures'))) if (!actuelles.has(f)) fs.unlinkSync(path.join(SITE, 'captures', f));
  for (const f of fs.readdirSync(path.join(MANUEL, 'captures'))) {
    const src = path.join(MANUEL, 'captures', f), dst = path.join(SITE, 'captures', f);
    if (!fs.existsSync(dst) || fs.statSync(dst).mtimeMs < fs.statSync(src).mtimeMs) {
      execSync(`convert "${src}" -strip -resize '1000x1100>' -quality 55 -sampling-factor 4:2:0 "${dst}"`);
    }
  }
  fs.copyFileSync(path.join(MANUEL, 'index.html'), path.join(SITE, 'index.html'));
}
async function ouvrir(nav) {
  preparerSite();
  const p = await nav.newPage();
  await p.goto('file://' + path.join(SITE, 'index.html'), { waitUntil: 'networkidle' });
  await p.emulateMedia({ media: 'print' });
  await p.evaluate(() => document.querySelectorAll('img[loading]').forEach(i => i.removeAttribute('loading')));
  await p.evaluate(() => document.fonts.ready);
  await p.waitForFunction(() => [...document.images].every(i => i.complete && i.naturalWidth > 0), null, { timeout: 120000 });
  return p;
}
function pagesDesChapitres(pdf) {
  const html = fs.readFileSync(path.join(MANUEL, 'index.html'), 'utf8');
  const chapitres = [...html.matchAll(/<section class="chapitre" id="([^"]+)">\s*<h2><span class="num">(\d+)<\/span>(.*?)<\/h2>/g)]
    .map(m => ({ id: m[1], num: m[2], titre: m[3].replace(/<[^>]+>/g, '').replace(/&amp;/g, '&') }));
  const total = +execSync(`pdfinfo "${pdf}"`).toString().match(/Pages:\s+(\d+)/)[1];
  const textes = [];
  for (let i = 1; i <= total; i++) textes.push(execSync(`pdftotext -f ${i} -l ${i} -layout "${pdf}" -`).toString().replace(/\s+/g, '').slice(0, 400));
  const pages = {};
  let depuis = 1; // la page 1 est le sommaire
  for (const c of chapitres) {
    const cle = (c.num + c.titre).replace(/\s+/g, '');
    const i = textes.findIndex((t, k) => k >= depuis && t.includes(cle));
    if (i >= 0) { pages[c.id] = i + 1; depuis = i; } else console.log('page introuvable :', c.num, c.titre);
  }
  return { pages, total };
}
(async () => {
  const nav = await chromium.launch();
  let p = await ouvrir(nav);
  // Couverture seule, sans en-tête
  await p.addStyleTag({ content: 'main > *:not(.couverture), .sommaire { display: none !important; } .couverture { break-after: auto !important; }' });
  await p.pdf({ path: path.join(TMP, 'couverture.pdf'), format: 'A4', printBackground: true, pageRanges: '1', margin: { top: '16mm', bottom: '16mm', left: '14mm', right: '14mm' } });
  await p.close();
  // Corps : tout sauf la couverture (le sommaire est la page 1)
  async function corps() {
    const q = await ouvrir(nav);
    await q.addStyleTag({ content: '.couverture { display: none !important; }' });
    await q.pdf({ path: path.join(TMP, 'corps.pdf'), format: 'A4', printBackground: true, displayHeaderFooter: true, headerTemplate: entete, footerTemplate: pied,
      margin: { top: '16mm', bottom: '17mm', left: '14mm', right: '14mm' } });
    const casses = await q.evaluate(() => [...document.images].filter(i => !i.naturalWidth).map(i => i.src));
    await q.close();
    return casses;
  }
  let casses = await corps();
  const { pages, total } = pagesDesChapitres(path.join(TMP, 'corps.pdf'));
  const avant = fs.existsSync(path.join(ICI, 'pages.json')) ? fs.readFileSync(path.join(ICI, 'pages.json'), 'utf8') : '';
  fs.writeFileSync(path.join(ICI, 'pages.json'), JSON.stringify(pages, null, 1));
  if (avant !== JSON.stringify(pages, null, 1)) {
    execSync(`python3 "${path.join(ICI, 'construire.py')}"`, { stdio: 'inherit' });
    casses = await corps();
    const verif = pagesDesChapitres(path.join(TMP, 'corps.pdf'));
    if (JSON.stringify(verif.pages) !== JSON.stringify(pages)) console.log('ATTENTION : les numéros de page ont bougé à la seconde passe');
  }
  execSync(`pdfunite "${path.join(TMP, 'couverture.pdf')}" "${path.join(TMP, 'corps.pdf')}" "${SORTIE}"`);
  const n = execSync(`pdfinfo "${SORTIE}"`).toString().match(/Pages:\s+(\d+)/)[1];
  console.log(`PDF : ${n} pages (couverture + ${total}), ${(fs.statSync(SORTIE).size / 1048576).toFixed(1)} Mo ; images cassées : ${casses.length}`);
  await nav.close();
})();
