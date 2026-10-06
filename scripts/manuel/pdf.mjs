// Produit le manuel complet en PDF depuis l'application (serveur lancé) :
//   node scripts/manuel/pdf.mjs [fichier.pdf] [--leger]
// --leger : captures en JPEG, pour un fichier deux à trois fois plus petit (à envoyer par WhatsApp ou e-mail).
import { chromium } from 'playwright';
const args = process.argv.slice(2);
const light = args.includes('--leger');
const out = args.find((a) => !a.startsWith('--')) ?? 'manuel-waumini.pdf';
const browser = await chromium.launch();
const page = await browser.newPage();
await page.goto(`${process.env.BASE ?? 'http://127.0.0.1:8000'}/aide/manuel-complet`, { waitUntil: 'networkidle', timeout: 120000 });
await page.evaluate(() => document.fonts.ready);
if (light) {
    await page.evaluate(async () => {
        for (const img of document.querySelectorAll('main img')) {
            await img.decode().catch(() => {});
            const scale = Math.min(1, 1100 / img.naturalWidth);
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(img.naturalWidth * scale);
            canvas.height = Math.round(img.naturalHeight * scale);
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#fff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
            img.src = canvas.toDataURL('image/jpeg', 0.72);
            await img.decode().catch(() => {});
        }
    });
}
await page.pdf({ path: out, format: 'A4', printBackground: true, margin: { top: '16mm', bottom: '16mm', left: '14mm', right: '14mm' },
    displayHeaderFooter: true, headerTemplate: '<span></span>',
    footerTemplate: '<div style="width:100%;font-size:8px;color:#8F8577;text-align:center;font-family:sans-serif">Waumini · Manuel d’utilisation · <span class="pageNumber"></span> / <span class="totalPages"></span></div>' });
await browser.close();
console.log(out);
