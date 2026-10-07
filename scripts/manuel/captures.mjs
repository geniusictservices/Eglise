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
// --site : captures propres (sans repères) pour le site public, dans public/images/site/.
const SITE = process.argv.includes('--site');
const SITE_SCENES = ['03-tableau-de-bord', '09-hierarchie', '13-utilisateurs', '19-devises'];
const SITE_OUT = new URL('../../public/images/site/', import.meta.url).pathname;
// --livret : captures propres pour le livret de présentation (docs/livret), en version ordinateur et téléphone.
const LIVRET = process.argv.includes('--livret');
const LIVRET_SCENES = ['03-tableau-de-bord', '05-menu-telephone', '22-acces-support', '27-membres', '29-membre-fiche', '36-carte',
    '39-finances', '42-recu', '45-collecte', '48-declarations', '49-depenses', '51-depense-signature', '57-rapport', '62-budget', '68-plan',
    '76-bulletin', '82-calendrier', '84-presences-culte', '86-annonces', '90-document-imprime', '91-verification', '95-registre',
    '96-suivi-pastoral', '99-espace-membre', '102-consolidation', '109-site-accueil', '110-site-don'];
const LIVRET_OUT = new URL('../../docs/livret/captures/', import.meta.url).pathname;
const only = SITE ? SITE_SCENES : LIVRET ? LIVRET_SCENES : process.argv.slice(2).filter((a) => !a.startsWith('--'));

const VIEWPORTS = {
    bureau: { viewport: { width: 1366, height: 820 }, deviceScaleFactor: 1, isMobile: false },
    mobile: { viewport: { width: 390, height: 780 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true },
};

// Repères numérotés posés au-dessus des éléments décrits dans le texte.
async function mark(page, marks) {
    if (SITE || LIVRET) return;
    // Fait défiler pour que les éléments repérés soient visibles (au-dessus de la barre d'onglets sur téléphone).
    await page.evaluate((marks) => {
        const els = marks.map(({ selector, text }) => [...document.querySelectorAll(selector)].find((e) => e.getClientRects().length && (!text || e.textContent.includes(text)))).filter(Boolean);
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
        marks.forEach(({ selector, label, position = 'left', text }) => {
            const el = [...document.querySelectorAll(selector)].find((e) => (e.offsetParent !== null || e.getClientRects().length) && (!text || e.textContent.includes(text)));
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
    await Promise.all([page.waitForURL(/tableau-de-bord|profil|mon-espace/), page.click('button[type=submit]')]);
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
        id: '21-apparence', user: '0990000001',
        run: async (page) => {
            await page.goto(`${BASE}/parametres?onglet=apparence`);
            await settle(page);
            await page.click('button:has-text("Forêt")');
            await page.waitForTimeout(600);
            await mark(page, [{ selector: 'main fieldset', label: '1' }, { selector: '#primaryColor', label: '2' }, { selector: 'main section[aria-label]', label: '3' }]);
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
        id: '00-inscription-communaute', user: null,
        run: async (page) => {
            await page.goto(`${BASE}/inscription`);
            await settle(page);
            await page.click('label:has-text("Le siège")');
            await page.fill('#communityName', 'Communauté des Églises du Lac');
            await page.fill('#city', 'Goma');
            await page.fill('#province', 'Nord-Kivu');
            await page.waitForTimeout(250);
            await mark(page, [{ selector: 'fieldset', label: '1' }, { selector: '#communityName', label: '2' }]);
        },
    },
    {
        id: '00-inscription-compte', user: null,
        run: async (page) => {
            await page.goto(`${BASE}/inscription`);
            await settle(page);
            await page.fill('#communityName', 'Église Béthel de Ndosho');
            await page.fill('#city', 'Goma');
            await page.click('main form button[type=submit]');
            await page.waitForSelector('#name');
            await page.fill('#name', 'Samuel Kitambala');
            await page.fill('#phone', '0997 222 333');
            await page.fill('#password', 'Bethel2026');
            await page.fill('#passwordConfirmation', 'Bethel2026');
            await page.check('input[wire\\:model="accept"]');
            await page.waitForTimeout(250);
            await mark(page, [{ selector: '#phone', label: '1' }, { selector: 'main form button[type=submit]', label: '2' }]);
        },
    },
    {
        id: '26-abonnement', user: '0990000001',
        run: async (page) => {
            await page.goto(`${BASE}/abonnement`);
            await settle(page);
            await mark(page, [{ selector: 'main section.card', label: '1' }, { selector: '#tier', label: '2' }]);
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
    // ---------- Registre des membres (secrétaire de la paroisse de Himbi) ----------
    {
        id: '27-membres', user: '0990000008',
        run: async (page) => {
            await page.goto(`${BASE}/membres`);
            await settle(page);
            await mark(page, isMobile(page)
                ? [{ selector: 'main .grid.grid-cols-2', label: '1' }, { selector: '#search', label: '2' }, { selector: 'button[aria-controls=member-filters]', label: '3' }, { selector: 'main a[href$="/membres/nouveau"]', label: '4' }]
                : [{ selector: 'main .grid.grid-cols-2', label: '1' }, { selector: '#search', label: '2' }, { selector: '#member-filters', label: '3' }, { selector: 'main a[href$="/membres/nouveau"]', label: '4', position: 'right' }, { selector: 'button[aria-label="Excel"]', label: '5' }]);
        },
    },
    {
        id: '28-membre-doublon', user: '0990000008',
        run: async (page) => {
            await page.goto(`${BASE}/membres/nouveau`);
            await settle(page);
            await page.fill('#last_name', 'Kahindo');
            await page.fill('#first_name', 'Esther');
            await page.click('main label:has(input[value=F])');
            await page.click('main form button[type=submit]');
            await page.waitForSelector('main [role=alert]');
            await page.evaluate(() => window.scrollTo(0, 0));
            await settle(page);
            await mark(page, isMobile(page)
                ? [{ selector: 'main [role=alert]', label: '1' }]
                : [{ selector: 'main [role=alert]', label: '1' }, { selector: 'main aside section:nth-of-type(2)', label: '2' }, { selector: 'main aside section:nth-of-type(3)', label: '3' }]);
        },
    },
    {
        id: '29-membre-fiche', user: '0990000008',
        run: async (page) => {
            await page.goto(`${BASE}/membres?q=Jean-Paul`);
            await page.click(isMobile(page) ? 'main ul a[href*="/membres/"]' : 'main table a[href*="/membres/"]');
            await page.waitForURL(/\/membres\/\d+$/);
            await settle(page);
            await mark(page, [{ selector: 'main a[href$="/carte"]', label: '1' }, { selector: 'main button[wire\\:click=openStatus]', label: '2' }, { selector: 'main [role=tablist]', label: '3' }]);
        },
    },
    {
        id: '30-membre-parcours', user: '0990000008',
        run: async (page) => {
            await page.goto(`${BASE}/membres?q=Jean-Paul`);
            await page.click(isMobile(page) ? 'main ul a[href*="/membres/"]' : 'main table a[href*="/membres/"]');
            await page.waitForURL(/\/membres\/\d+$/);
            await page.click('main [role=tab]:nth-child(2)');
            await page.waitForSelector('text=Étapes de vie');
            await settle(page);
            await page.evaluate(() => document.querySelector('[role=tablist]').scrollIntoView());
            await mark(page, [{ selector: 'main button[wire\\:click=openEvent]', label: '1' }, { selector: 'main section:has(> h2) ol', label: '2' }]);
        },
    },
    {
        id: '31-menage', user: '0990000008',
        run: async (page) => {
            await page.goto(`${BASE}/menages`);
            await page.click('main ul a[href*="/menages/"]');
            await page.waitForURL(/\/menages\/\d+$/);
            await settle(page);
            await mark(page, isMobile(page)
                ? [{ selector: 'main ul select', label: '1' }]
                : [{ selector: 'main ul select', label: '1' }, { selector: 'main input[type=search]', label: '2' }, { selector: 'main button[wire\\:click=shareAddress]', label: '3' }]);
        },
    },
    {
        id: '32-departements', user: '0990000008',
        run: async (page) => {
            await page.goto(`${BASE}/departements`);
            await settle(page);
            await mark(page, [{ selector: 'main ul li:first-child a', label: '1' }, { selector: 'main section.border-dashed', label: '2' }]);
        },
    },
    {
        id: '33-departement', user: '0990000008',
        run: async (page) => {
            await page.goto(`${BASE}/departements`);
            await page.click('main ul a:has-text("Chorale")');
            await page.waitForURL(/\/departements\/\d+$/);
            await settle(page);
            await mark(page, isMobile(page)
                ? [{ selector: 'main ul select', label: '1' }]
                : [{ selector: 'main ul select', label: '1' }, { selector: 'main input[type=search]', label: '2' }]);
        },
    },
    {
        id: '34-import-modele', user: '0990000008',
        run: async (page) => {
            await page.goto(`${BASE}/membres/importer`);
            await settle(page);
            await mark(page, [{ selector: 'main a[href$="/modele-excel"]', label: '1' }, { selector: 'main label:has(input[type=file])', label: '2' }]);
        },
    },
    {
        id: '35-import-verification', user: '0990000008',
        run: async (page) => {
            await page.goto(`${BASE}/membres/importer`);
            await page.setInputFiles('input[type=file]', new URL('./registre-exemple.xlsx', import.meta.url).pathname);
            await page.waitForSelector('text=lignes lues', { timeout: 20000 });
            await settle(page);
            await mark(page, isMobile(page)
                ? [{ selector: 'main .card .grid', label: '1' }]
                : [{ selector: 'main .card .grid', label: '1' }, { selector: 'main .card ul li:first-child', label: '2' }, { selector: 'main section.sticky button.btn-primary', label: '3' }]);
            // On repart sans importer : la base de démonstration reste intacte.
        },
    },
    {
        id: '36-carte', user: '0990000008',
        run: async (page) => {
            await page.goto(`${BASE}/membres?q=Jean-Paul`);
            await page.click(isMobile(page) ? 'main ul a[href*="/membres/"]' : 'main table a[href*="/membres/"]');
            await page.waitForURL(/\/membres\/\d+$/);
            await page.goto(page.url() + '/carte');
            await settle(page);
            await mark(page, [{ selector: '[role=group]', label: '1' }, { selector: 'button[onclick="window.print()"]', label: '2' }, { selector: 'section[aria-label=Verso] svg', label: '3' }]);
        },
    },
    {
        id: '37-reglages-registre', user: '0990000001',
        run: async (page) => {
            await page.goto(`${BASE}/membres/reglages`);
            await settle(page);
            await mark(page, [{ selector: '#numberFormat', label: '1' }, { selector: '#code', label: '2' }, { selector: 'main section.wax', label: '3' }]);
        },
    },
    {
        id: '38-reglages-champs', user: '0990000008',
        run: async (page) => {
            await page.goto(`${BASE}/membres/reglages?onglet=champs`);
            await settle(page);
            await mark(page, [{ selector: 'main button[wire\\:click=editField]', label: '1' }]);
        },
    },
    // ---------- Finances (trésorière de la paroisse de Himbi) ----------
    {
        id: '39-finances', user: '0990000007',
        run: async (page) => {
            await page.goto(`${BASE}/finances`);
            await settle(page);
            await mark(page, [{ selector: 'main section.wax', label: '1' }, { selector: 'main a[href*="/finances/operations?compte="]', label: '2' }]);
        },
    },
    {
        id: '40-compte', user: '0990000007',
        run: async (page) => {
            await page.goto(`${BASE}/finances/comptes`);
            await page.click('main button:has-text("Caisse principale")');
            await page.waitForSelector('[role=dialog] input[type=checkbox]');
            await settle(page);
            await mark(page, [{ selector: '[role=dialog] form > :first-child', label: '1' }, { selector: '[role=dialog] label:has(input[type=checkbox])', label: '2' }]);
        },
    },
    {
        id: '41-recette', user: '0990000007',
        run: async (page) => {
            await page.goto(`${BASE}/finances/recette`);
            await settle(page);
            await page.selectOption('#categoryId', { label: 'Dîme' });
            await page.waitForSelector('main input[aria-label="Rechercher le membre"]');
            await page.fill('main input[aria-label="Rechercher le membre"]', 'Kavira');
            await page.waitForSelector('main button[wire\\:click^=chooseMember]');
            await page.click('main button[wire\\:click^=chooseMember]');
            await page.waitForTimeout(400);
            await page.selectOption('#accountId', { label: 'Caisse principale' });
            await page.waitForTimeout(400);
            await page.selectOption('#currency', 'USD');
            await page.waitForTimeout(300);
            await page.fill('#amount', '25');
            await settle(page);
            await mark(page, [{ selector: '#accountId', label: '1' }, { selector: '#categoryId', label: '2' }, { selector: '#amount', label: '3' }]);
        },
    },
    {
        id: '42-recu', user: '0990000007',
        run: async (page) => {
            await page.goto(`${BASE}/finances/operations`);
            const href = await page.getAttribute('main a[href*="/finances/recu/"]', 'href');
            await page.goto(href);
            await settle(page);
            await mark(page, [{ selector: '[role=group]', label: '1' }, { selector: 'button[onclick="window.print()"]', label: '2' }]);
        },
    },
    {
        id: '43-virement', user: '0990000007',
        run: async (page) => {
            await page.goto(`${BASE}/finances/virement`);
            await settle(page);
            await page.fill('#amountOut', '285000');
            await page.waitForTimeout(300);
            if (await page.$('#amountIn')) await page.fill('#amountIn', '100');
            await settle(page);
            await mark(page, [{ selector: '#from', label: '1' }, { selector: '#to', label: '2' }]);
        },
    },
    {
        id: '44-journal', user: '0990000007',
        run: async (page) => {
            await page.goto(`${BASE}/finances/operations`);
            await settle(page);
            await mark(page, [{ selector: 'main button[wire\\:click="shiftMonth(-1)"]', label: '1' }, { selector: 'main select[wire\\:model\\.live=account]', label: '2' }]);
        },
    },
    {
        id: '45-collecte', user: '0990000007',
        run: async (page) => {
            await page.goto(`${BASE}/finances/collecte`);
            await page.click('main a:has-text("Culte des jeunes")');
            await page.waitForURL(/\/finances\/collecte\/\d+$/);
            await settle(page);
            await mark(page, isMobile(page)
                ? [{ selector: 'main input[wire\\:model\\.blur^="counts.USD"]', label: '1' }]
                : [{ selector: 'main input[wire\\:model\\.blur^="counts.USD"]', label: '1' }, { selector: 'main :has(> h2.mb-3)', label: '2' }, { selector: 'main button[wire\\:click=validateSheet]', label: '3' }]);
        },
    },
    {
        id: '46-promesses', user: '0990000007',
        run: async (page) => {
            await page.goto(`${BASE}/finances/promesses`);
            await settle(page);
            await mark(page, [{ selector: 'main section.wax', label: '1' }, { selector: 'main a[href$="/finances/promesses/nouvelle"]', label: '2' }]);
        },
    },
    {
        id: '47-promesse', user: '0990000007',
        run: async (page) => {
            // La première promesse de la démo : trois mois versés sur six.
            await page.goto(`${BASE}/finances/promesses/1`);
            await settle(page);
            await mark(page, [{ selector: 'main button[wire\\:click=openPayment]', label: '1' }, { selector: 'main a[href^="https://wa.me"]', label: '2' }]);
        },
    },
    {
        id: '48-declarations', user: '0990000007',
        run: async (page) => {
            await page.goto(`${BASE}/finances/paiements-declares`);
            await page.click('main button[wire\\:click^=review]');
            await page.waitForSelector('#rv-account');
            await settle(page);
            await mark(page, [{ selector: '#rv-account', label: '1' }, { selector: '[role=dialog] button[wire\\:click=approve]', label: '2' }]);
        },
    },
    {
        id: '49-depenses', user: '0990000007',
        run: async (page) => {
            await page.goto(`${BASE}/finances/depenses?etape=all`);
            await settle(page);
            await mark(page, [{ selector: 'main .overflow-x-auto', label: '1' }, { selector: 'main p.border-terra-100', label: '2' }]);
        },
    },
    {
        id: '50-depense-demande', user: '0990000009',
        run: async (page) => {
            await page.goto(`${BASE}/finances/depenses/nouvelle`);
            await settle(page);
            await page.fill('#title', 'Location de bâches pour la convention des jeunes');
            await page.selectOption('#departmentId', { label: 'Jeunesse' });
            await page.selectOption('#categoryId', { label: 'Fournitures et matériel' });
            await page.fill('#amount', '120');
            await page.check('main input[type=checkbox]');
            await page.waitForSelector('main input[placeholder^="Membre"]');
            await settle(page);
            await mark(page, [{ selector: '#title', label: '1' }, { selector: 'main label:has(input[type=checkbox])', label: '2' }]);
        },
    },
    {
        id: '51-depense-signature', user: '0990000006',
        run: async (page) => {
            await page.goto(`${BASE}/finances/depenses?etape=all`);
            await page.click('main a:has-text("50 chaises")');
            await page.waitForURL(/\/finances\/depenses\/\d+$/);
            await settle(page);
            await mark(page, [{ selector: 'main ol[aria-label=Circuit]', label: '1' }, { selector: 'main form[wire\\:submit=approve] button.btn-primary', label: '2' }]);
        },
    },
    {
        id: '52-depense-decaisser', user: '0990000007',
        run: async (page) => {
            await page.goto(`${BASE}/finances/depenses?etape=all`);
            await page.click('main a:has-text("toiture")');
            await page.waitForURL(/\/finances\/depenses\/\d+$/);
            await settle(page);
            await mark(page, [{ selector: 'main form[wire\\:submit=disburse] .space-y-2', label: '1' }, { selector: 'main aside section', label: '2' }]);
        },
    },
    {
        id: '53-avance-justifier', user: '0990000007',
        run: async (page) => {
            await page.goto(`${BASE}/finances/depenses?etape=all`);
            await page.click('main a:has-text("Cordes de guitare")');
            await page.waitForURL(/\/finances\/depenses\/\d+$/);
            await page.fill('#spent', '65');
            await page.waitForSelector('main p.bg-leaf-50');
            await settle(page);
            await mark(page, [{ selector: '#spent', label: '1' }, { selector: 'main p.bg-leaf-50', label: '2' }]);
        },
    },
    {
        id: '54-circuit', user: '0990000001',
        run: async (page) => {
            await page.goto(`${BASE}/finances/comptes?onglet=circuit`);
            await settle(page);
            await mark(page, [{ selector: 'main form .grid-cols-3', label: '1' }, { selector: '#advanceDays', label: '2' }]);
        },
    },
    {
        id: '55-clotures', user: '0990000007',
        run: async (page) => {
            await page.goto(`${BASE}/finances/clotures`);
            await settle(page);
            await mark(page, [{ selector: 'main li.border-leaf-300', label: '1' }, { selector: 'main button[wire\\:click^=askClose]', label: '2' }]);
        },
    },
    {
        id: '56-cloture-fenetre', user: '0990000007',
        run: async (page) => {
            await page.goto(`${BASE}/finances/clotures`);
            await page.click('main button[wire\\:click^=askClose]');
            await page.waitForSelector('[role=dialog] .bg-ochre-50');
            await settle(page);
            await mark(page, [{ selector: '[role=dialog] .bg-ochre-50', label: '1' }]);
        },
    },
    {
        id: '57-rapport', user: '0990000007',
        run: async (page) => {
            await page.goto(`${BASE}/finances/rapports?annee=2026&mois=8`);
            await settle(page);
            await mark(page, [{ selector: 'main select[wire\\:model\\.live=month]', label: '1' }, { selector: 'main a[href*="/rapports/imprimer"]', label: '2' }, { selector: 'main a[href*="/rapports/excel"]', label: '3' }]);
        },
    },
    {
        id: '58-rapport-imprime', user: '0990000007',
        run: async (page) => {
            await page.goto(`${BASE}/finances/rapports/imprimer?annee=2026&mois=0`);
            await settle(page);
        },
    },
    {
        id: '59-identite', user: '0990000001',
        run: async (page) => {
            await page.goto(`${BASE}/parametres?onglet=identite`);
            await settle(page);
            await mark(page, isMobile(page)
                ? [{ selector: 'main form section:first-child', label: '1' }]
                : [{ selector: 'main form section:first-child', label: '1' }, { selector: 'main form section:nth-child(2) h2', label: '2' }, { selector: 'main form aside section:first-child h2, main form > div:nth-child(2) section:first-child h2', label: '3' }]);
        },
    },
    // ---------- Plan d'action et budget (paroisse de Himbi) ----------
    {
        id: '60-exercice', user: '0990000007',
        run: async (page) => {
            await page.goto(`${BASE}/finances/comptes?onglet=exercice`);
            await settle(page);
            await mark(page, [{ selector: '#fiscalStart', label: '1' }]);
        },
    },
    {
        id: '61-utilisateur-fiche', user: '0990000001',
        run: async (page) => {
            // L'administrateur du siège ouvre la paroisse de Himbi (cinquième communauté de la démo), puis le compte de Josué Kakule.
            await page.goto(`${BASE}/tableau-de-bord`);
            await page.evaluate(async () => {
                const token = document.querySelector('meta[name=csrf-token]').content;
                await fetch('/communaute/5/ouvrir', { method: 'POST', headers: { 'X-CSRF-TOKEN': token } });
            });
            await page.goto(`${BASE}/utilisateurs/9`);
            await page.waitForURL(/\/utilisateurs\/\d+$/);
            await settle(page);
            await mark(page, [{ selector: 'main section:last-of-type .bg-ochre-50', label: '1' }]);
        },
    },
    {
        id: '62-budget', user: '0990000007',
        run: async (page) => {
            await page.goto(`${BASE}/budget`);
            await settle(page);
            await mark(page, [{ selector: 'main section.wax', label: '1' }, { selector: 'main section.card ul li:nth-child(2)', label: '2' }]);
        },
    },
    {
        id: '63-proposition', user: '0990000007',
        run: async (page) => {
            // La chorale prépare ses besoins de l'an prochain : une ligne en francs, avec sa justification.
            await page.goto(`${BASE}/budget?exercice=${new Date().getFullYear() + 1}`);
            await page.click('main li:has-text("Chorale") a');
            await page.waitForURL(/\/departement\//);
            await page.click('main button[wire\\:click^=editLine]');
            await page.waitForSelector('[role=dialog] #ln-label');
            await page.fill('#ln-label', 'Uniformes de la chorale');
            await page.selectOption('#ln-cat', { label: 'Fournitures et matériel' });
            await page.fill('#ln-amount', '1120000');
            await page.selectOption('#ln-cur', 'CDF');
            await page.fill('#ln-just', 'Vingt choristes ; les anciens uniformes datent de 2019.');
            await settle(page);
            await mark(page, [{ selector: '#ln-cur', label: '1' }, { selector: '#ln-just', label: '2' }]);
        },
    },
    {
        id: '64-budget-version', user: '0990000007',
        run: async (page) => {
            await page.goto(`${BASE}/budget`);
            await page.click('main a:has-text("Voir le budget adopté")');
            await page.waitForURL(/\/budget\/version\/\d+$/);
            await settle(page);
            await mark(page, [{ selector: 'main section.wax .grid', label: '1' }, { selector: 'main p.border-leaf-100', label: '2' }, { selector: 'main li', text: 'Nouvelle sonorisation', label: '3' }]);
        },
    },
    {
        id: '65-suivi-budget', user: '0990000006',
        run: async (page) => {
            await page.goto(`${BASE}/budget/suivi`);
            await settle(page);
            await mark(page, [{ selector: 'main tbody tr', text: 'Jeunesse · Fournitures', label: '1' }, { selector: 'main section:last-of-type ul li:first-child', label: '2' }]);
        },
    },
    {
        id: '66-depassement-demande', user: '0990000007',
        run: async (page) => {
            await page.goto(`${BASE}/finances/depenses?etape=all`);
            await page.click('main a:has-text("Rafraîchissements")');
            await page.waitForURL(/\/finances\/depenses\/\d+$/);
            await page.click('main button[wire\\:click=askOverrun]');
            await page.waitForSelector('[role=dialog] #ov-amount');
            await page.click('[role=dialog] label:has(input[value=reserves])');
            await page.waitForSelector('#ov-detail');
            await page.fill('#ov-detail', 'Excédent de l’exercice 2025');
            await page.fill('#ov-reason', 'Réunion des diacres de toute la paroisse, prévue après l’adoption du budget.');
            await settle(page);
            await mark(page, [{ selector: '[role=dialog] fieldset', label: '1' }]);
        },
    },
    {
        id: '67-depassement-decision', user: '0990000006',
        run: async (page) => {
            await page.goto(`${BASE}/finances/depenses?etape=all`);
            await page.click('main a:has-text("sonorisation pour la convention")');
            await page.waitForURL(/\/finances\/depenses\/\d+$/);
            await settle(page);
            await mark(page, [{ selector: 'main section.border-terra-300 dl', label: '1' }, { selector: 'main section.border-ochre-300 p', label: '2' }, { selector: 'main button[wire\\:click="decideOverrun(true)"]', label: '3' }]);
        },
    },
    {
        id: '68-plan', user: '0990000006',
        run: async (page) => {
            await page.goto(`${BASE}/plan`);
            await settle(page);
            await mark(page, [{ selector: 'main section.wax .ring-progress', label: '1' }, { selector: 'main section.card .ring-progress', label: '2' }, { selector: 'main li.border-terra-200', label: '3' }]);
        },
    },
    {
        id: '69-avancement', user: '0990000009',
        run: async (page) => {
            await page.goto(`${BASE}/plan`);
            await page.click('main li:has-text("Tournoi de la paix") button[wire\\:click^=editProgress]');
            await page.waitForSelector('[role=dialog] #pg-value');
            await page.fill('#pg-note', 'Terrain réservé, six équipes inscrites.');
            await page.evaluate(() => { const r = document.querySelector('#pg-value'); r.value = 25; r.dispatchEvent(new Event('input', { bubbles: true })); });
            await settle(page);
            await mark(page, [{ selector: '#pg-value', label: '1' }]);
        },
    },
    {
        id: '70-reunion', user: '0990000008',
        run: async (page) => {
            await page.goto(`${BASE}/reunions`);
            await page.click('main a:has-text("Conseil de paroisse de juillet")');
            await page.waitForURL(/\/reunions\/\d+$/);
            await settle(page);
            await mark(page, isMobile(page)
                ? [{ selector: '#m-minutes', label: '1' }]
                : [{ selector: '#m-minutes', label: '1' }, { selector: 'main aside ul', label: '2' }, { selector: 'main form[wire\\:submit=addDecision]', label: '3' }]);
        },
    },
    // ---------- Paie (paroisse de Himbi) ----------
    {
        id: '71-paie', user: '0990000007',
        run: async (page) => {
            await page.goto(`${BASE}/paie`);
            await settle(page);
            await mark(page, [{ selector: 'main section.card', text: 'Budget des salaires', label: '1' }, { selector: 'main section.grid', label: '2' }, { selector: 'main button[wire\\:click=askPrepare]', label: '3' }]);
        },
    },
    {
        id: '72-paie-rythme', user: '0990000007',
        run: async (page) => {
            await page.goto(`${BASE}/paie/reglages?onglet=rythmes`);
            await page.click('main button[wire\\:click=editSchedule]');
            await page.waitForSelector('[role=dialog] #sc-unit');
            await page.fill('#sc-name', 'Intervenants de la convention');
            await page.selectOption('#sc-unit', 'service');
            await page.waitForSelector('#sc-label');
            await page.fill('#sc-label', 'journée');
            await settle(page);
            await mark(page, [{ selector: '#sc-unit', label: '1' }, { selector: '#sc-label', label: '2' }]);
        },
    },
    {
        id: '73-paie-element', user: '0990000007',
        run: async (page) => {
            await page.goto(`${BASE}/paie/reglages`);
            await page.locator('main li button', { hasText: 'Mutuelle' }).click();
            await page.waitForSelector('[role=dialog] #it-calc');
            await settle(page);
            await mark(page, [{ selector: '#it-calc', label: '1' }, { selector: '[role=dialog] label:has(input[wire\\:model="item.applies_to_all"])', label: '2' }]);
        },
    },
    {
        id: '74-beneficiaire', user: '0990000007',
        run: async (page) => {
            await page.goto(`${BASE}/paie/beneficiaires`);
            await page.locator('main li button', { hasText: 'Pasteur Daniel Paluku' }).click();
            await page.waitForSelector('[role=dialog] #py-schedule');
            await settle(page);
            await mark(page, [{ selector: '#py-schedule', label: '1' }, { selector: '[role=dialog] fieldset', label: '2' }]);
        },
    },
    {
        id: '75-paie-approbation', user: '0990000006',
        run: async (page) => {
            await page.goto(`${BASE}/paie`);
            await page.locator('main ul a', { hasText: 'Octobre' }).click();
            await page.waitForURL(/\/paie\/\d+$/);
            await settle(page);
            await mark(page, [{ selector: 'main section.wax .flex-wrap', label: '1' }, { selector: 'main form[wire\\:submit=approve] button.btn-primary', label: '2' }, { selector: 'main section.card', text: 'Budget des salaires', label: '3' }]);
        },
    },
    {
        id: '76-bulletin', user: '0990000007',
        run: async (page) => {
            await page.goto(`${BASE}/paie`);
            await page.locator('main ul a', { hasText: 'Octobre' }).click();
            await page.waitForURL(/\/paie\/\d+$/);
            const href = await page.locator('main tbody tr', { hasText: 'Kambale' }).locator('a[href*="/paie/bulletin/"]').getAttribute('href');
            await page.goto(href);
            await settle(page);
        },
    },
    {
        id: '77-avances', user: '0990000006',
        run: async (page) => {
            await page.goto(`${BASE}/paie/avances`);
            await settle(page);
            await mark(page, [{ selector: 'main li.border-ochre-300 button[wire\\:click$="true)"]', label: '1' }, { selector: 'main li .rounded-full.bg-sand-100', label: '2' }]);
        },
    },
    // ---------- Nouveautés, groupes, calendrier, présences, annonces (paroisse de Himbi) ----------
    {
        id: '78-nouveautes', user: '0990000006',
        run: async (page) => {
            await page.goto(`${BASE}/nouveautes`);
            await settle(page);
            const bell = isMobile(page) ? 'header.wax a[href$="/nouveautes"]' : 'header.hidden a[href$="/nouveautes"]';
            await mark(page, [{ selector: bell, label: '1' }, { selector: 'main [x-data^=pushToggle]', label: '2' }, { selector: 'main ul li a', label: '3' }]);
        },
    },
    {
        id: '79-groupes', user: '0990000008',
        run: async (page) => {
            await page.goto(`${BASE}/groupes`);
            await settle(page);
            await mark(page, [{ selector: 'main ul li a', label: '1' }, { selector: 'main button[wire\\:click=create]', label: '2' }]);
        },
    },
    {
        id: '80-groupe', user: '0990000009',
        run: async (page) => {
            await page.goto(`${BASE}/groupes`);
            await page.locator('main ul li a', { hasText: 'Jeunes en mission' }).click();
            await page.waitForURL(/\/groupes\/\d+$/);
            await settle(page);
            await mark(page, isMobile(page)
                ? [{ selector: 'main section.border-ochre-300', label: '1' }, { selector: 'main button[wire\\:click=openMeeting]', label: '2' }]
                : [{ selector: 'main section.border-ochre-300', label: '1' }, { selector: 'main button[wire\\:click=openMeeting]', label: '2' }, { selector: 'main section.card', text: 'personnes', label: '3' }]);
        },
    },
    {
        id: '81-groupe-presences', user: '0990000009',
        run: async (page) => {
            await page.goto(`${BASE}/groupes`);
            await page.locator('main ul li a', { hasText: 'Jeunes en mission' }).click();
            await page.waitForURL(/\/groupes\/\d+$/);
            await page.click('main button[wire\\:click=openMeeting]');
            await page.waitForSelector('[role=dialog] #m-topic');
            await page.fill('#m-topic', 'Néhémie : bâtir ensemble');
            await page.click('[role=dialog] button[wire\\:click=allPresent]');
            await settle(page);
            await mark(page, [{ selector: '[role=dialog] button[wire\\:click=allPresent]', label: '1', position: 'right' }, { selector: '[role=dialog] [role=radiogroup]', label: '2' }]);
        },
    },
    {
        id: '82-calendrier', user: '0990000008',
        run: async (page) => {
            await page.goto(`${BASE}/calendrier`);
            await settle(page);
            await mark(page, [{ selector: 'main h2.text-lg', label: '1' }, { selector: 'main ul li a', text: 'Culte du dimanche', label: '2' }, { selector: 'main button[wire\\:click=openEventForm]', label: '3' }]);
        },
    },
    {
        id: '83-activite', user: '0990000008',
        run: async (page) => {
            await page.goto(`${BASE}/calendrier`);
            await page.click('main button[wire\\:click=openEventForm]');
            await page.waitForSelector('[role=dialog] #e-title');
            await page.fill('#e-title', 'Culte de sainte cène');
            await page.selectOption('#e-repeats', 'monthly_weekday');
            await page.waitForSelector('#e-until');
            await page.locator('[role=dialog] label', { hasText: 'Sur inscription' }).scrollIntoViewIfNeeded();
            await settle(page);
            await mark(page, [{ selector: '#e-repeats', label: '1' }, { selector: '#e-aud', label: '2' }, { selector: '[role=dialog] .rounded-xl.border', label: '3' }]);
        },
    },
    {
        id: '84-presences-culte', user: '0990000008',
        run: async (page) => {
            await page.goto(`${BASE}/presences`);
            await page.locator('main a', { hasText: 'Culte du dimanche' }).first().click();
            await page.waitForURL(/\/calendrier\/\d+\//);
            await settle(page);
            await mark(page, isMobile(page)
                ? [{ selector: 'main form[wire\\:submit=saveCounts] .grid-cols-3', label: '1' }, { selector: '#c-total', label: '2' }]
                : [{ selector: 'main form[wire\\:submit=saveCounts]', label: '1' }, { selector: 'main section.card', text: 'Pointage', label: '2' }, { selector: 'main section.card', text: 'Visiteurs', label: '3' }]);
        },
    },
    {
        id: '85-presences', user: '0990000008',
        run: async (page) => {
            await page.goto(`${BASE}/presences`);
            await settle(page);
            await mark(page, isMobile(page)
                ? [{ selector: 'main [role=img]', label: '1' }]
                : [{ selector: 'main [role=img]', label: '1' }, { selector: 'main section.card', text: 'Visiteurs à revoir', label: '2' }, { selector: 'main section.border-ochre-300', label: '3' }]);
        },
    },
    {
        id: '86-annonces', user: '0990000008',
        run: async (page) => {
            await page.goto(`${BASE}/annonces`);
            await settle(page);
            await mark(page, [{ selector: 'main a[href^="https://wa.me"]', label: '1' }, { selector: 'main button[wire\\:click=create]', label: '2' }]);
        },
    },
    {
        id: '87-annoncer-activite', user: '0990000008',
        run: async (page) => {
            await page.goto(`${BASE}/annonces`);
            await page.locator('main a', { hasText: 'Convention des jeunes' }).first().click();
            await page.waitForURL(/\/annonces\/\d+$/);
            await page.click('main a[href*="/calendrier/"]');
            await page.waitForURL(/\/calendrier\/\d+\//);
            await settle(page);
            await mark(page, [{ selector: 'main a[href^="https://wa.me"]', label: '1' }, { selector: 'main section.card', text: 'Inscriptions', label: '2' }]);
        },
    },
    // ---------- Documents et registres (paroisse de Himbi) ----------
    {
        id: '88-documents', user: '0990000008',
        run: async (page) => {
            await page.goto(`${BASE}/documents`);
            await settle(page);
            await mark(page, [{ selector: 'main ul li a[href*="/imprimer"]', label: '1' }, { selector: 'main a[href$="/documents/delivrer"]', label: '2' }]);
        },
    },
    {
        id: '89-delivrer', user: '0990000008',
        run: async (page) => {
            await page.goto(`${BASE}/documents/delivrer`);
            await page.locator('main button', { hasText: 'Ordre de mission' }).click();
            await page.waitForSelector('main input[type=search]');
            await page.fill('main input[type=search]', 'Paluku Samuel');
            await page.locator('main button[wire\\:click^=chooseMember]').first().click();
            await page.waitForSelector('#d-destination');
            await page.fill('#d-destination', 'Bukavu');
            await page.fill('#d-objet', 'représenter la paroisse au synode régional');
            await page.fill('#d-du', '2026-11-02');
            await page.fill('#d-au', '2026-11-05');
            await page.fill('#d-sign', 'Daniel Paluku');
            await page.waitForTimeout(900);
            await settle(page);
            await mark(page, isMobile(page)
                ? [{ selector: '#d-destination', label: '1' }]
                : [{ selector: '#d-destination', label: '1' }, { selector: 'main aside article', label: '2' }, { selector: 'main form button.btn-primary', label: '3' }]);
        },
    },
    {
        id: '90-document-imprime', user: '0990000008',
        run: async (page) => {
            await page.goto(`${BASE}/documents?q=Josias`);
            await page.click('main ul li a[href*="/imprimer"]');
            await page.waitForURL(/\/imprimer$/);
            await settle(page);
            await mark(page, [{ selector: 'a[href*="/verifier/document/"]', label: '1' }]);
        },
    },
    {
        id: '91-verification',
        run: async (page) => {
            const staff = await page.context().newPage();
            await login(staff, '0990000008');
            await staff.goto(`${BASE}/documents?q=Josias`);
            await staff.click('main ul li a[href*="/imprimer"]');
            const href = await staff.locator('a[href*="/verifier/document/"]').getAttribute('href');
            await staff.close();
            await page.context().clearCookies();
            await page.goto(href);
            await settle(page);
        },
    },
    {
        id: '92-modeles', user: '0990000008',
        run: async (page) => {
            await page.goto(`${BASE}/documents/modeles`);
            await settle(page);
            await mark(page, [{ selector: 'main li button[wire\\:click^=adapt]', label: '1' }, { selector: 'main a[href$="/documents/modeles/nouveau"]', label: '2' }]);
        },
    },
    {
        id: '93-modele-editeur', user: '0990000008',
        run: async (page) => {
            await page.goto(`${BASE}/documents/modeles/nouveau`);
            await page.fill('#t-name', 'Attestation de choriste');
            await page.fill('#t-code', 'ACH');
            await page.fill('#t-title', 'Attestation de choriste');
            await page.click('main button[wire\\:click=addField]');
            await page.waitForSelector('input[wire\\:model\\.live\\.debounce\\.500ms="form.fields.0.label"]');
            await page.fill('input[wire\\:model\\.live\\.debounce\\.500ms="form.fields.0.label"]', 'Voix');
            await page.fill('main textarea', "Je soussigné(e), **{signataire}**, {qualite_signataire} de {communaute}, atteste que **{civilite} {nom_officiel}**, {né} le {date_naissance}, chante à la chorale Les Messagers depuis le {date_adhesion}, à la voix de **{voix}**.\n\nEn foi de quoi, la présente attestation lui est délivrée pour servir et valoir ce que de droit.");
            await page.waitForTimeout(1200);
            await settle(page);
            await mark(page, isMobile(page)
                ? [{ selector: 'main textarea', label: '1' }]
                : [{ selector: 'main section.card', text: 'Champs à remplir', label: '1' }, { selector: 'main textarea', label: '2' }, { selector: 'main aside article', label: '3' }]);
        },
    },
    {
        id: '94-registres', user: '0990000008',
        run: async (page) => {
            await page.goto(`${BASE}/registres?q=Kahindo`);
            await settle(page);
            await mark(page, [{ selector: 'main input[type=search]', label: '1' }, { selector: 'main section.card ul li a', label: '2' }]);
        },
    },
    {
        id: '95-registre', user: '0990000008',
        run: async (page) => {
            await page.goto(`${BASE}/registres`);
            await page.locator('main ul li a', { hasText: 'Registre des baptêmes' }).click();
            await page.waitForURL(/\/registres\/\d+/);
            await settle(page);
            await mark(page, isMobile(page)
                ? [{ selector: 'main form[wire\\:submit=save] button.btn-primary', label: '1', position: 'right' }]
                : [{ selector: 'main form[wire\\:submit=save]', label: '1' }, { selector: 'main li a[href*="acte="]', label: '2' }]);
        },
    },
    // ---------- Suivi pastoral et espace membre (paroisse de Himbi) ----------
    {
        id: '96-suivi-pastoral', user: '0990000006',
        run: async (page) => {
            await page.goto(`${BASE}/suivi-pastoral`);
            await settle(page);
            await mark(page, [{ selector: 'main ul li a', label: '1' }, { selector: 'main button[wire\\:click="$set(\'kind\', \'\')"]', label: '2' }, { selector: 'main button[wire\\:click=create]', label: '3' }]);
        },
    },
    {
        id: '97-suivi', user: '0990000006',
        run: async (page) => {
            await page.goto(`${BASE}/suivi-pastoral`);
            await page.locator('main ul li a', { hasText: 'Rebecca' }).click();
            await page.waitForURL(/\/suivi-pastoral\/\d+$/);
            await settle(page);
            await mark(page, isMobile(page)
                ? [{ selector: 'main form[wire\\:submit=addNote]', label: '1' }]
                : [{ selector: 'main form[wire\\:submit=addNote]', label: '1' }, { selector: 'main ol li .badge', label: '2' }]);
        },
    },
    {
        id: '98-anniversaires', user: '0990000006',
        run: async (page) => {
            await page.goto(`${BASE}/suivi-pastoral?onglet=anniversaires`);
            await settle(page);
            await mark(page, [{ selector: 'main ul li a[href^="https://wa.me"]', label: '1', position: 'right' }]);
        },
    },
    {
        id: '99-espace-membre', user: '0990000013',
        run: async (page) => {
            await page.goto(`${BASE}/mon-espace`);
            await settle(page);
            await mark(page, [{ selector: 'main a[href*="/carte"]', label: '1' }, { selector: 'main button', text: 'Demander la prière', label: '2' }, { selector: 'main button', text: 'Demander une attestation', label: '3' }]);
        },
    },
    {
        id: '100-ouvrir-espace', user: '0990000001',
        run: async (page) => {
            await page.goto(`${BASE}/membres?q=Ruth`);
            await page.click(isMobile(page) ? 'main ul a[href*="/membres/"]' : 'main table a[href*="/membres/"]');
            await page.waitForURL(/\/membres\/\d+$/);
            await settle(page);
            await mark(page, [{ selector: 'main form[wire\\:submit=openSpace]', label: '1' }]);
        },
    },
    {
        id: '101-cotisations', user: '0990000008',
        run: async (page) => {
            await page.goto(`${BASE}/groupes`);
            await page.locator('main ul li a', { hasText: 'Prière des mamans' }).click();
            await page.waitForURL(/\/groupes\/\d+$/);
            await settle(page);
            await mark(page, [{ selector: 'main table', label: '1' }]);
        },
    },
    // ---------- Consolidation multi-paroisses (région Nord-Kivu, paroisse de Himbi) ----------
    {
        id: '102-consolidation', user: '0990000005',
        run: async (page) => {
            await page.goto(`${BASE}/consolidation`);
            await settle(page);
            await mark(page, [
                { selector: 'main button[wire\\:click="shift(1)"]', label: '1', position: 'right' },
                { selector: isMobile(page) ? 'main ul.md\\:hidden button' : 'main table button', label: '2' },
                { selector: 'main section h2', text: 'Saisies en retard', label: '3' },
            ]);
        },
    },
    {
        id: '103-quotes-parts', user: '0990000007',
        run: async (page) => {
            await page.goto(`${BASE}/quotes-parts`);
            await settle(page);
            await mark(page, [{ selector: 'main button[wire\\:click^=askSend]', label: '1', position: 'right' }, { selector: 'main form[wire\\:submit=saveRule]', label: '2' }]);
        },
    },
    {
        id: '104-transferts', user: '0990000008',
        run: async (page) => {
            await page.goto(`${BASE}/transferts`);
            await settle(page);
            await mark(page, [{ selector: 'main button[wire\\:click^=accept]', label: '1' }]);
        },
    },    {
        id: '105-transfert-membre', user: '0990000008',
        run: async (page) => {
            await page.goto(`${BASE}/membres?q=Ruth`);
            await page.click(isMobile(page) ? 'main ul a[href*="/membres/"]' : 'main table a[href*="/membres/"]');
            await page.waitForURL(/\/membres\/\d+$/);
            await settle(page);
            await mark(page, [{ selector: 'main form[wire\\:submit=requestTransfer]', label: '1' }]);
        },
    },    // ---------- Sites vitrines ----------
    {
        id: '106-site-vitrine', user: '0990000008',
        run: async (page) => {
            await page.goto(`${BASE}/site-vitrine`);
            await settle(page);
            await mark(page, [
                { selector: 'main input[wire\\:model="form.is_published"]', label: '1', position: 'right' },
                { selector: 'main [role=tablist]', label: '2' },
                { selector: 'main label:has(input[value=chaleureux])', label: '3' },
            ]);
        },
    },
    {
        id: '107-site-pages', user: '0990000008',
        run: async (page) => {
            await page.goto(`${BASE}/site-vitrine?onglet=pages`);
            await settle(page);
            await mark(page, [{ selector: 'main label:has(input[value=programme])', label: '1' }, { selector: 'main label:has(input[value=don])', label: '2' }]);
        },
    },
    {
        id: '108-predications', user: '0990000008',
        run: async (page) => {
            await page.goto(`${BASE}/predications`);
            await settle(page);
            await mark(page, [{ selector: 'main button[wire\\:click=create]', label: '1', position: 'right' }, { selector: 'main aside', label: '2' }]);
        },
    },
    {
        id: '109-site-accueil',
        run: async (page) => {
            await page.goto(`${BASE}/site/cep-himbi`);
            await settle(page);
        },
    },
    {
        id: '110-site-don',
        run: async (page) => {
            await page.goto(`${BASE}/site/cep-himbi/don`);
            await settle(page);
            await mark(page, [{ selector: 'main section div.rounded-2xl', label: '1' }, { selector: 'main input[name=reference]', label: '2' }]);
        },
    },
    {
        id: '111-site-lumiere',
        run: async (page) => {
            await page.goto(`${BASE}/site/cep-katindo`);
            await settle(page);
        },
    },
    {
        id: '112-site-solennel',
        run: async (page) => {
            await page.goto(`${BASE}/site/cep-siege`);
            await settle(page);
        },
    },    // ---------- Abonnement et support ----------
    {
        id: '113-declarer-abonnement', user: '0990000032',
        run: async (page) => {
            await page.goto(`${BASE}/abonnement`);
            await settle(page);
            await page.click('main button[wire\\:click=openDeclare]');
            await page.waitForSelector('#d-plan', { state: 'visible' });
            await page.selectOption('#d-plan', { label: 'Msingi' });
            await page.waitForTimeout(600);
            await mark(page, [{ selector: '#d-plan', label: '1' }, { selector: '#d-ref', label: '2' }]);
        },
    },
    {
        id: '114-support', user: '0990000008',
        run: async (page) => {
            await page.goto(`${BASE}/support`);
            await settle(page);
            await mark(page, [{ selector: 'main section.card a', label: '1' }, { selector: 'main button', text: 'Nouvelle demande', label: '2', position: 'right' }]);
        },
    },
    {
        id: '115-ticket', user: '0990000008',
        run: async (page) => {
            await page.goto(`${BASE}/support`);
            await page.goto(await page.locator('main a[href*="/support/"]').first().getAttribute('href'));
            await settle(page);
            await mark(page, [{ selector: '#tk-reply', label: '1' }, { selector: 'main button[wire\\:click=close]', label: '2' }]);
        },
    },
];

const browser = await chromium.launch();
let count = 0;

// Scène par scène (ordinateur puis téléphone), pour que les deux versions montrent les mêmes données.
for (const name of Object.keys(VIEWPORTS)) mkdirSync(`${LIVRET ? LIVRET_OUT : OUT}${name}`, { recursive: true });
mkdirSync(SITE_OUT, { recursive: true });

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
            await page.screenshot({ path: SITE ? `${SITE_OUT}${name}-${scene.id}.png` : `${LIVRET ? LIVRET_OUT : OUT}${name}/${scene.id}.png` });
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
