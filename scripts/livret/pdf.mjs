// Produit les PDF du livret de présentation, à partir du dossier préparé par
//   php artisan waumini:livret {dossier} --site=https://…
//   node scripts/livret/pdf.mjs {dossier}
// Deux fichiers dans le dossier :
//   livret-waumini.pdf            les pages A5 dans l'ordre, à partager (WhatsApp, e-mail) ;
//   livret-waumini-impression.pdf les pages imposées deux par deux sur des A4 en paysage :
//                                 imprimer en recto verso (retourner sur le bord court),
//                                 plier les feuilles en deux ensemble, agrafer au pli.
import { chromium } from 'playwright';
import { resolve } from 'node:path';

const dir = resolve(process.argv[2] ?? '.');
const browser = await chromium.launch();
const page = await browser.newPage();
await page.goto(`file://${dir}/livret.html`, { waitUntil: 'networkidle' });
await page.evaluate(() => document.fonts.ready);

// Une image qui déborde du bas de sa page partirait sur la suivante : on le signale.
await page.emulateMedia({ media: 'print' });
const overflows = await page.evaluate(() => [...document.querySelectorAll('.page')].flatMap((p, i) => {
    const limit = p.getBoundingClientRect().bottom - (p.querySelector('.folio') ? 14 * 96 / 25.4 : 0);
    return [...p.querySelectorAll('img, .note, .card, .facts, ul, ol')].filter((e) => e.getBoundingClientRect().bottom > limit + 1).map((e) => `page ${i + 1} : ${e.tagName.toLowerCase()} ${e.getAttribute('alt') ?? e.className}`);
}));
overflows.forEach((o) => console.warn(`Dépasse le bas de la page, ${o}`));
await page.pdf({ path: `${dir}/livret-waumini.pdf`, width: '148mm', height: '210mm', printBackground: true, preferCSSPageSize: true });

// Imposition en cahier : sur chaque face d'une feuille A4, deux pages A5 côte à côte,
// dans l'ordre qui donne un livret lisible une fois les feuilles pliées ensemble.
await page.evaluate(() => {
    const pages = [...document.querySelectorAll('.page')];
    while (pages.length % 4) {
        const blank = document.createElement('section');
        blank.className = 'page';
        pages.push(blank);
    }
    const n = pages.length;
    const style = document.createElement('style');
    style.textContent = '@page { size: 297mm 210mm; margin: 0; } .sheet { display: flex; width: 297mm; height: 210mm; break-after: page; overflow: hidden; } .sheet .page { margin: 0; break-after: auto; }';
    document.head.append(style);
    const sheets = [];
    for (let i = 0; i < n / 2; i++) {
        const pair = i % 2 === 0 ? [n - 1 - i, i] : [i, n - 1 - i];
        const sheet = document.createElement('div');
        sheet.className = 'sheet';
        pair.forEach((k) => sheet.append(pages[k]));
        sheets.push(sheet);
    }
    document.body.prepend(...sheets);
});
await page.pdf({ path: `${dir}/livret-waumini-impression.pdf`, width: '297mm', height: '210mm', printBackground: true, preferCSSPageSize: true });

await browser.close();
console.log(`${dir}/livret-waumini.pdf\n${dir}/livret-waumini-impression.pdf`);
