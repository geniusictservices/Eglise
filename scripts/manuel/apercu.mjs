// Aperçu rapide d’écrans pendant le développement (base de démo, serveur lancé) :
//   node scripts/manuel/apercu.mjs <dossier> /membres "/membres/1#button.selecteur"
import { chromium } from 'playwright';
const [out, ...paths] = process.argv.slice(2);
const BASE = 'http://127.0.0.1:8000';
const browser = await chromium.launch();
for (const [name, opts] of Object.entries({
    bureau: { viewport: { width: 1366, height: 820 } },
    mobile: { viewport: { width: 390, height: 780 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true },
})) {
    const page = await (await browser.newContext(opts)).newPage();
    await page.goto(`${BASE}/connexion`);
    await page.fill('#phone', process.env.PHONE ?? '0990000001');
    await page.fill('#password', 'Waumini2026');
    await page.click('main form button[type=submit], form button[type=submit]');
    await page.waitForURL(/tableau-de-bord|admin|mon-espace/);
    for (const p of paths) {
        const [path, action] = p.split('#');
        await page.goto(BASE + path);
        if (action) { await page.click(action); await page.waitForTimeout(600); }
        await page.waitForTimeout(300);
        const file = `${out}/${name}-${path.replace(/[^a-z0-9]+/gi, '_')}${action ? '-action' : ''}.png`;
        await page.screenshot({ path: file, fullPage: !action });
        console.log(file);
    }
}
await browser.close();
