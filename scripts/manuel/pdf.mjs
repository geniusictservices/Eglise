// Produit le manuel complet en PDF depuis l'application (serveur lancé) :
//   node scripts/manuel/pdf.mjs [fichier.pdf]
import { chromium } from 'playwright';
const out = process.argv[2] ?? 'manuel-waumini.pdf';
const browser = await chromium.launch();
const page = await browser.newPage();
await page.goto(`${process.env.BASE ?? 'http://127.0.0.1:8000'}/aide/manuel-complet`, { waitUntil: 'networkidle', timeout: 120000 });
await page.evaluate(() => document.fonts.ready);
await page.pdf({ path: out, format: 'A4', printBackground: true, margin: { top: '16mm', bottom: '16mm', left: '14mm', right: '14mm' },
    displayHeaderFooter: true, headerTemplate: '<span></span>',
    footerTemplate: '<div style="width:100%;font-size:8px;color:#8F8577;text-align:center;font-family:sans-serif">Waumini · Manuel d’utilisation · <span class="pageNumber"></span> / <span class="totalPages"></span></div>' });
await browser.close();
console.log(out);
