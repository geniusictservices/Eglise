/**
 * Audit : parcourt toutes les pages accessibles à chaque compte de démo, sur ordinateur et téléphone,
 * et relève les erreurs (statut HTTP, erreurs JavaScript, ressources en échec, débordement horizontal
 * sur téléphone, texte non traduit). Usage : node scripts/audit-pages.mjs [téléphone…]
 */
import { chromium } from 'playwright';

const BASE = process.env.WAUMINI_URL ?? 'http://127.0.0.1:8000';
const USERS = process.argv.slice(2).length ? process.argv.slice(2) : ['0990000001', '0990000006', '0990000007', '0990000008', '0990000009', '0990000013', '0990000099', '0990000030'];
const SKIP = /\/(demo|deconnexion|logout|livewire|aide\/captures|build|sauvegardes\/|rapports\/excel|modele-excel)|\/carte$|\.(png|jpg|pdf|xlsx|zip|mp3)$/;
const MAX = 220;

const pattern = (u) => new URL(u).pathname.replace(/\/\d+(?=\/|$)/g, '/{id}').replace(/\/[0-9a-f-]{20,}(?=\/|$)/g, '/{t}') + ([...new URL(u).searchParams.keys()].sort().map((k) => `?${k}=${k === 'onglet' || k === 'tab' ? new URL(u).searchParams.get(k) : ''}`).join(''));

const browser = await chromium.launch();
const problems = [];

for (const phone of USERS) {
    const desk = await browser.newContext({ viewport: { width: 1366, height: 820 } });
    const page = await desk.newPage();
    await page.goto(`${BASE}/connexion`);
    await page.fill('#phone', phone);
    await page.fill('#password', 'Waumini2026');
    await Promise.all([page.waitForURL(/tableau-de-bord|profil|mon-espace|admin/), page.click('button[type=submit]')]);
    await page.waitForLoadState('networkidle');
    const start = page.url();
    const mobile = await browser.newContext({ viewport: { width: 390, height: 780 }, isMobile: true, hasTouch: true, storageState: await desk.storageState() });
    const mpage = await mobile.newPage();

    const seen = new Set(); const perPattern = new Map(); const queue = [start]; const from = new Map(); let count = 0;
    const watch = (p, tag) => {
        p.on('pageerror', (e) => problems.push([phone, tag, p.url(), 'JS', e.message.slice(0, 160)]));
        p.on('console', (m) => { if (m.type() === 'error' && !/favicon|Failed to load resource/.test(m.text())) problems.push([phone, tag, p.url(), 'console', m.text().slice(0, 160)]); });
        p.on('response', (r) => { if (r.status() >= 400 && r.url().startsWith(BASE) && r.request().resourceType() !== 'document') problems.push([phone, tag, p.url(), `ressource ${r.status()}`, r.url()]); });
    };
    watch(page, 'bureau'); watch(mpage, 'mobile');

    while (queue.length && count < MAX) {
        const url = queue.shift();
        const pat = pattern(url);
        if (seen.has(url) || (perPattern.get(pat) ?? 0) >= 2) continue;
        seen.add(url); perPattern.set(pat, (perPattern.get(pat) ?? 0) + 1); count++;
        for (const [p, tag] of [[page, 'bureau'], [mpage, 'mobile']]) {
            let res;
            try { res = await p.goto(url, { waitUntil: 'networkidle', timeout: 30000 }); } catch (e) { problems.push([phone, tag, url, 'timeout', e.message.slice(0, 100)]); continue; }
            const status = res?.status() ?? 0;
            if (status >= 400) { problems.push([phone, tag, url, `HTTP ${status}`, `lien depuis ${from.get(url) ?? '?'}`]); continue; }
            const info = await p.evaluate(() => {
                const text = document.body?.innerText ?? '';
                const over = document.documentElement.scrollWidth - window.innerWidth;
                const wide = over > 1 ? [...document.querySelectorAll('body *')].filter((e) => { const r = e.getBoundingClientRect(); return r.right > window.innerWidth + 1 && r.width > 0 && getComputedStyle(e).position !== 'fixed' && !e.closest('.overflow-x-auto, .overflow-auto, .overflow-hidden, [style*="overflow"]'); }).slice(0, 2).map((e) => `${e.tagName.toLowerCase()}.${[...e.classList].slice(0, 3).join('.')} «${(e.innerText || '').slice(0, 30)}»`) : [];
                const raw = text.match(/(\b[a-z]+\.[a-z_]+\.[a-z_.]+\b|__\(|\{\{|@lang|:[a-z]+\b(?= ))/g);
                return { over, wide, raw: raw ? [...new Set(raw)].slice(0, 4) : null, links: [...document.querySelectorAll('a[href]')].map((a) => a.href), err: /Whoops|Server Error|Exception|SQLSTATE|Undefined (variable|array key|property)/.test(text) };
            });
            if (info.err) problems.push([phone, tag, url, 'erreur affichée', '']);
            if (tag === 'mobile' && info.over > 1) problems.push([phone, tag, url, `déborde de ${info.over}px`, info.wide.join(' | ')]);
            if (info.raw && tag === 'bureau') problems.push([phone, tag, url, 'texte brut ?', info.raw.join(' ')]);
            if (tag === 'bureau') for (const l of info.links) {
                if (!l.startsWith(BASE)) continue;
                const clean = l.split('#')[0];
                if (!SKIP.test(new URL(clean).pathname) && !seen.has(clean)) { queue.push(clean); if (!from.has(clean)) from.set(clean, url); }
            }
        }
    }
    console.log(`${phone} : ${count} pages`);
    await desk.close(); await mobile.close();
}
await browser.close();
const uniq = [...new Map(problems.map((p) => [p.slice(1).join('|').replace(/\d+/g, '#'), p])).values()];
for (const p of uniq) console.log(p.join(' · '));
console.log(`${uniq.length} problèmes`);
