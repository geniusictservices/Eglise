/**
 * Captures d'écran du manuel d'utilisation, en version ordinateur et téléphone.
 *
 * Prérequis : base de démonstration fraîche et serveur lancé :
 *   php artisan migrate:fresh --seed && php artisan serve
 * Puis : node scripts/manuel/captures.mjs [identifiant-de-scène…]
 *
 * Chaque scène ouvre un écran dans un état réaliste (formulaire rempli,
 * fenêtre ouverte…) et pose des repères numérotés sur les éléments décrits
 * dans le manuel. Les images vont dans docs/manuel/captures/{bureau,mobile}/.
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const BASE = process.env.WAUMINI_URL ?? 'http://127.0.0.1:8000';
const OUT = new URL('../../docs/manuel/captures/', import.meta.url).pathname;
const PASSWORD = 'Waumini2026';
const only = process.argv.slice(2);

const VIEWPORTS = {
    bureau: { viewport: { width: 1366, height: 820 }, deviceScaleFactor: 1, isMobile: false },
    mobile: { viewport: { width: 390, height: 780 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true },
};

// Repères numérotés posés au-dessus des éléments décrits dans le texte.
async function mark(page, marks) {
    // Fait défiler pour que les éléments repérés soient visibles (au-dessus de la barre d'onglets sur téléphone).
    await page.evaluate((marks) => {
        const els = marks.map(({ selector }) => [...document.querySelectorAll(selector)].find((e) => e.getClientRects().length)).filter(Boolean);
        if (!els.length || els.some((e) => e.closest('[role=dialog]') || getComputedStyle(e).position === 'fixed' || e.closest('nav[aria-label="Navigation principale"]'))) return;
        const rects = els.map((e) => e.getBoundingClientRect());
        const top = Math.min(...rects.map((r) => r.top)) + window.scrollY;
        const bottom = Math.max(...rects.map((r) => r.bottom)) + window.scrollY;
        const usable = window.innerHeight - (window.innerWidth < 1024 ? 150 : 90);
        const target = bottom - top < usable ? top - (usable - (bottom - top)) / 2 : top - 90;
        window.scrollTo(0, Math.max(0, target));
    }, marks);
    await page.waitForTimeout(150);
    await page.evaluate((marks) => {
        document.querySelectorAll('.manuel-repere').forEach((el) => el.remove());
        marks.forEach(({ selector, label, position = 'left' }) => {
            const el = [...document.querySelectorAll(selector)].find((e) => e.offsetParent !== null || e.getClientRects().length);
            if (!el) return;
            const r = el.getBoundingClientRect();
            const ring = document.createElement('div');
            ring.className = 'manuel-repere';
            Object.assign(ring.style, {
                position: 'fixed', left: `${r.left - 4}px`, top: `${r.top - 4}px`, width: `${r.width + 8}px`, height: `${r.height + 8}px`,
                border: '3px solid #E09A2D', borderRadius: '14px', zIndex: 9998, pointerEvents: 'none', boxShadow: '0 0 0 4px rgba(224,154,45,.18)',
            });
            const badge = document.createElement('div');
            badge.className = 'manuel-repere';
            badge.textContent = label;
            const size = 28;
            const left = position === 'right' ? r.right - size / 2 : r.left - size / 2;
            Object.assign(badge.style, {
                position: 'fixed', left: `${Math.max(2, Math.min(window.innerWidth - size - 2, left))}px`, top: `${Math.max(2, r.top - size / 2)}px`,
                width: `${size}px`, height: `${size}px`, borderRadius: '50%', background: '#B5532F', color: '#fff', zIndex: 9999,
                font: '700 15px/28px Outfit, sans-serif', textAlign: 'center', boxShadow: '0 2px 6px rgba(0,0,0,.25)', pointerEvents: 'none',
            });
            document.body.append(ring, badge);
        });
    }, marks);
}

async function login(page, phone) {
    await page.goto(`${BASE}/connexion`);
    await page.fill('#phone', phone);
    await page.fill('#password', PASSWORD);
    await Promise.all([page.waitForURL(/tableau-de-bord|profil/), page.click('button[type=submit]')]);
}

async function settle(page) {
    await page.waitForLoadState('networkidle');
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(250);
}

const isMobile = (page) => page.viewportSize().width < 600;
const openDrawer = async (page) => { await page.click('button[aria-label="Ouvrir le menu"]'); await page.waitForTimeout(350); };

/** Les scènes : identifiant, utilisateur connecté, préparation de l'écran. */
const SCENES = [
    {
        id: '01-connexion', user: null,
        run: async (page) => {
            await page.goto(`${BASE}/connexion`);
            await page.fill('#phone', '0990 000 001');
            await page.fill('#password', 'Waumini2026');
            await settle(page);
            await mark(page, [{ selector: '#phone', label: '1' }, { selector: '#password', label: '2' }, { selector: 'form button[type=submit]', label: '3' }, { selector: '[x-data=passkeyLogin] button', label: '4' }]);
        },
    },
    {
        id: '02-premiere-connexion', user: '0990000012',
        run: async (page) => {
            await page.goto(`${BASE}/profil`);
            await settle(page);
            await page.fill('#currentPassword', PASSWORD);
            await page.fill('#password', 'MonEglise2026');
            await page.fill('#passwordConfirmation', 'MonEglise2026');
            await mark(page, isMobile(page)
                ? [{ selector: 'main .border-ochre-300', label: '1' }]
                : [{ selector: 'main .border-ochre-300', label: '1' }, { selector: '#password', label: '2' }]);
        },
    },
    {
        id: '03-tableau-de-bord', user: '0990000001',
        run: async (page) => {
            await page.goto(`${BASE}/tableau-de-bord`);
            await settle(page);
            const marks = [{ selector: 'main .grid.grid-cols-2', label: '2' }, { selector: 'main .ring-progress', label: '3' }];
            marks.unshift(isMobile(page) ? { selector: 'button[aria-label="Ouvrir le menu"]', label: '1' } : { selector: 'aside button[aria-expanded]', label: '1' });
            if (isMobile(page)) marks.push({ selector: 'nav[aria-label="Navigation principale"]', label: '4' });
            await mark(page, marks);
        },
    },
    {
        id: '04-changer-de-communaute', user: '0990000001',
        run: async (page) => {
            await page.goto(`${BASE}/tableau-de-bord`);
            await settle(page);
            if (isMobile(page)) await openDrawer(page);
            const switcher = isMobile(page) ? 'div[role=dialog] aside button[aria-expanded]' : 'aside.lg\\:flex button[aria-expanded]';
            await page.click(switcher);
            await page.waitForTimeout(300);
            await mark(page, [{ selector: `${switcher.replace(' button[aria-expanded]', '')} ul button`, label: '1' }]);
        },
    },
    {
        id: '05-menu-telephone', user: '0990000001', only: 'mobile',
        run: async (page) => {
            await page.goto(`${BASE}/tableau-de-bord`);
            await settle(page);
            await openDrawer(page);
            await mark(page, [{ selector: 'div[role=dialog] aside nav', label: '1' }]);
        },
    },
    {
        id: '06-installer-android', user: null, only: 'mobile',
        run: async (page) => {
            await page.goto(`${BASE}/installer`);
            await page.click('button[role=tab]:has-text("Android")');
            await settle(page);
        },
    },
    {
        id: '07-installer-iphone', user: null, only: 'mobile',
        run: async (page) => {
            await page.goto(`${BASE}/installer`);
            await page.click('button[role=tab]:has-text("iPhone")');
            await settle(page);
        },
    },
    {
        id: '08-installer-windows', user: null, only: 'bureau',
        run: async (page) => {
            await page.goto(`${BASE}/installer`);
            await page.click('button[role=tab]:has-text("Windows")');
            await settle(page);
        },
    },
    {
        id: '09-hierarchie', user: '0990000001',
        run: async (page) => {
            await page.goto(`${BASE}/hierarchie`);
            await settle(page);
            await mark(page, isMobile(page)
                ? [{ selector: 'main span.font-mono', label: '2' }]
                : [{ selector: 'main .btn-primary', label: '1' }, { selector: 'main span.font-mono', label: '2' }]);
        },
    },
    {
        id: '10-hierarchie-ajouter', user: '0990000001',
        run: async (page) => {
            await page.goto(`${BASE}/hierarchie`);
            await settle(page);
            await page.click('main .btn-primary');
            await page.waitForSelector('#level-name', { state: 'visible' });
            await page.fill('#level-name', 'Paroisse de Ndosho');
            await page.fill('#level-label', 'Paroisse');
            await page.fill('#level-city', 'Goma');
            await page.waitForTimeout(300);
            await mark(page, [{ selector: '#level-name', label: '1' }, { selector: '#level-label', label: '2' }]);
        },
    },
    {
        id: '11-hierarchie-demande', user: '0990000001',
        run: async (page) => {
            await page.goto(`${BASE}/hierarchie`);
            await settle(page);
            await mark(page, [{ selector: 'main section.border-ochre-300 li', label: '1' }, { selector: 'main section.border-ochre-300 .btn-primary', label: '2', position: 'right' }]);
        },
    },
    {
        id: '12-hierarchie-rejoindre', user: '0990000020',
        run: async (page) => {
            await page.goto(`${BASE}/hierarchie`);
            await settle(page);
            await page.click('main .btn-secondary');
            await page.waitForSelector('#target', { state: 'visible' });
            await page.fill('#target', 'region-nord-kivu');
            await page.fill('#msg', 'Notre église est membre de la CEP depuis 2019.');
            await page.waitForTimeout(300);
            await mark(page, [{ selector: '#target', label: '1' }]);
        },
    },
    {
        id: '13-utilisateurs', user: '0990000001',
        run: async (page) => {
            await page.goto(`${BASE}/utilisateurs`);
            await settle(page);
            await mark(page, [{ selector: 'main a.btn-primary', label: '1' }, { selector: '#search', label: '2' }]);
        },
    },
    {
        id: '14-utilisateur-nouveau', user: '0990000001',
        run: async (page) => {
            await page.goto(`${BASE}/utilisateurs/nouveau`);
            await settle(page);
            await page.fill('#name', 'Rachel Kahambu');
            await page.fill('#phone', '0997 450 128');
            await page.selectOption('#roleId', { label: 'Secrétaire' });
            await page.selectOption('#scopeId', { label: /Himbi/ }).catch(async () => {
                const value = await page.$eval('#scopeId', (s) => [...s.options].find((o) => o.text.includes('Himbi')).value);
                await page.selectOption('#scopeId', value);
            });
            await mark(page, isMobile(page)
                ? [{ selector: '#roleId', label: '2' }, { selector: '#scopeId', label: '3' }]
                : [{ selector: '#phone', label: '1' }, { selector: '#roleId', label: '2' }, { selector: '#scopeId', label: '3' }]);
        },
    },
    {
        id: '16-utilisateur-roles', user: '0990000001',
        run: async (page) => {
            await page.goto(`${BASE}/utilisateurs?q=Furaha`);
            await settle(page);
            const href = await page.evaluate(() => [...document.querySelectorAll('main a[href*="/utilisateurs/"]')]
                .find((a) => (a.closest('tr') ?? a).textContent.includes('Furaha')).href);
            await page.goto(href);
            await settle(page);
            const panel = page.locator('main section.card');
            await panel.scrollIntoViewIfNeeded();
            await page.selectOption('#roleId', { label: 'Responsable de département' });
            await mark(page, [{ selector: 'main section.card ul li', label: '1' }, { selector: '#roleId', label: '2' }, { selector: 'main section.card label:has(input[type=checkbox])', label: '3' }]);
        },
    },
    {
        id: '17-roles', user: '0990000001',
        run: async (page) => {
            await page.goto(`${BASE}/roles`);
            await settle(page);
            await mark(page, [{ selector: 'main a.btn-primary', label: '1' }, { selector: 'main article .btn-ghost', label: '2' }]);
        },
    },
    {
        id: '18-role-modifier', user: '0990000001',
        run: async (page) => {
            await page.goto(`${BASE}/roles`);
            await page.click('article:has-text("Trésorier adjoint") a:has-text("Modifier")');
            await settle(page);
            await page.evaluate(() => {
                const finances = [...document.querySelectorAll('main fieldset.card')].find((f) => f.querySelector('legend')?.textContent.includes('Finances'));
                finances.id = 'manuel-finances';
            });
            await mark(page, [{ selector: '#manuel-finances', label: '1' }]);
        },
    },
    {
        id: '19-devises', user: '0990000004',
        run: async (page) => {
            await page.goto(`${BASE}/devises`);
            await settle(page);
            await page.fill('#rate-RWF', '1 438');
            const marks = [{ selector: '#rate-RWF', label: '2' }, { selector: 'article:has(#rate-RWF) .btn-primary', label: '3', position: 'right' }];
            await mark(page, isMobile(page) ? marks : [{ selector: '#rate-date', label: '1' }, ...marks]);
        },
    },
    {
        id: '20-parametres', user: '0990000001',
        run: async (page) => {
            await page.goto(`${BASE}/parametres`);
            await settle(page);
            await mark(page, [{ selector: 'main [role=tablist]', label: '1' }]);
        },
    },
    {
        id: '21-libelles', user: '0990000001',
        run: async (page) => {
            await page.goto(`${BASE}/parametres?onglet=libelles`);
            await settle(page);
            await page.fill('#term-departement', 'Ministère');
            await mark(page, [{ selector: '#term-departement', label: '1' }]);
        },
    },
    {
        id: '22-acces-support', user: '0990000001',
        run: async (page) => {
            await page.goto(`${BASE}/parametres?onglet=support`);
            await settle(page);
            await mark(page, [{ selector: 'main section.card label', label: '1' }]);
        },
    },
    {
        id: '23-journal', user: '0990000001',
        run: async (page) => {
            await page.goto(`${BASE}/journal`);
            await settle(page);
            await page.click('main button:has-text("Vérifier")');
            await page.waitForSelector('main .bg-ink-50.text-ink-700');
            const detail = page.locator('main li:has(button:has-text("Détails"))').first();
            await detail.locator('button:has-text("Détails")').click();
            await page.waitForTimeout(250);
            await mark(page, isMobile(page)
                ? [{ selector: 'main li table', label: '2' }]
                : [{ selector: 'main .bg-ink-50.text-ink-700', label: '1' }, { selector: 'main li table', label: '2' }]);
        },
    },
    {
        id: '24-profil', user: '0990000002',
        run: async (page) => {
            await page.goto(`${BASE}/profil`);
            await settle(page);
            await mark(page, [{ selector: '#locale', label: '1' }]);
        },
    },
    {
        id: '25-empreinte', user: '0990000003',
        run: async (page) => {
            await page.goto(`${BASE}/profil`);
            await settle(page);
            await page.click('text=Activer sur cet appareil');
            await page.waitForSelector('#passkeyName', { state: 'visible' });
            await page.fill('#passkeyName', 'Téléphone de Neema');
            await page.fill('#passkeyPassword', 'Waumini2026');
            await page.waitForTimeout(300);
            await mark(page, [{ selector: '#passkeyName', label: '1' }, { selector: '#passkeyPassword', label: '2' }]);
        },
    },
    {
        id: '15-mot-de-passe-provisoire', user: '0990000001',
        run: async (page, viewport) => {
            await page.goto(`${BASE}/utilisateurs/nouveau`);
            await settle(page);
            await page.fill('#name', 'Rachel Kahambu');
            await page.fill('#phone', viewport === 'mobile' ? '0997 450 129' : '0997 450 130');
            await page.selectOption('#roleId', { label: 'Secrétaire' });
            await page.click('main form button[type=submit]');
            await page.waitForSelector('main code', { timeout: 10000 });
            await page.evaluate(() => window.scrollTo(0, 0));
            await settle(page);
            await mark(page, [{ selector: 'main code', label: '1' }, { selector: 'main a[href^="https://wa.me"]', label: '2' }]);
        },
    },
];

const browser = await chromium.launch();
let count = 0;

// Scène par scène (ordinateur puis téléphone), pour que les deux versions montrent les mêmes données.
for (const name of Object.keys(VIEWPORTS)) mkdirSync(`${OUT}${name}`, { recursive: true });

for (const scene of SCENES) {
    if (only.length && !only.includes(scene.id)) continue;

    for (const [name, options] of Object.entries(VIEWPORTS)) {
        if (scene.only && scene.only !== name) continue;

        const context = await browser.newContext({ ...options, locale: 'fr-FR', timezoneId: 'Africa/Lubumbashi' });
        const page = await context.newPage();
        page.on('pageerror', (e) => console.error(`  [${name}/${scene.id}] erreur JS : ${e.message}`));

        try {
            if (scene.user) await login(page, scene.user);
            await scene.run(page, name);
            await page.screenshot({ path: `${OUT}${name}/${scene.id}.png` });
            count++;
            console.log(`✓ ${name}/${scene.id}`);
        } catch (error) {
            console.error(`✗ ${name}/${scene.id} : ${error.message.split('\n')[0]}`);
        }

        await context.close();
    }
}

await browser.close();
console.log(`${count} captures enregistrées.`);
