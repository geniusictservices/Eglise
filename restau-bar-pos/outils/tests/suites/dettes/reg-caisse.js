const { chromium } = require('playwright'); const fs = require('fs'); const { execSync } = require('child_process');
const B = 'http://integ.localhost:8250'; const dir = __dirname + '/'; const MDP = fs.readFileSync('/home/user/Eglise/restau-bar-pos/outils/tests/instance.txt', 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
const sql = q => execSync(`mysql -u root -N --default-character-set=utf8mb4 rbp_integ -e ${JSON.stringify("SET time_zone = '+02:00'; " + q)}`).toString().trim();
let nb = 0; const ko = []; const v = (c, m) => { nb++; if (!c) ko.push(m); console.log((c ? 'ok    ' : 'ÉCHEC ') + m); };
const maxAvant = Number(sql('SELECT COALESCE(MAX(id),0) FROM ventes')); const debut = sql('SELECT NOW()');
fs.writeFileSync('/home/user/Eglise/restau-bar-pos/outils/tests/suites/dettes/nettoyage-caisse.json', JSON.stringify({ maxAvant, debut }));
async function connecter(ctx, id, mdp) { const p = await ctx.newPage(); await p.goto(B + '/gestion/connexion'); await p.fill('#email', id); await p.fill('#mot_de_passe', mdp); await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]); return p; }
async function ajouter(p, noms) {
  for (const n of noms) {
    const [nom, taille] = n.split('|');
    await p.locator('#grille .tuile', { has: p.locator(`.tuile__nom:text-is("${nom}")`) }).click(); await p.waitForTimeout(120);
    if (await p.locator('#formats[open]').count()) { await p.locator('#formats-liste .format', { hasText: taille }).click(); await p.waitForTimeout(120); }
  }
}
const derniere = () => sql(`SELECT id FROM ventes WHERE id > ${maxAvant} ORDER BY id DESC LIMIT 1`);
(async () => {
  const b = await chromium.launch(); const err = [];
  const ctx = await b.newContext({ viewport: { width: 1366, height: 860 } });
  const p = await connecter(ctx, 'admin@demo.test', MDP); p.on('pageerror', x => err.push(x.message)); p.on('console', m => m.type() === 'error' && !m.text().includes('Failed to load resource') && err.push(m.text()));
  await p.goto(B + '/caisse?e=comptoir-du-lac'); await p.evaluate(() => sessionStorage.clear()); await p.reload();
  v(await p.locator('#encaisser').isDisabled(), 'ticket vide : Encaisser inactif');
  const raccourcis = await p.locator('.paiements [data-compte] .paiement__type').allTextContents();
  v(raccourcis.join(',') === 'Caisse,Mobile money,Banque', 'panneau : le compte principal de chaque type : ' + (await p.locator('.paiements [data-compte] .paiement__nom').allTextContents()).join(', '));
  // 1. Payé juste en FC
  await ajouter(p, ['Primus|Grande', 'Primus|Grande', 'Kivu Sunset']);
  await p.click('#encaisser');
  v((await p.textContent('#paiement-total')).replace(/\s/g, '') === '33800FC', 'fenêtre : total 33 800 FC');
  v(await p.inputValue('#recu-CDF') === '33800' && (await p.textContent('#paiement-bilan')).includes('Réglé'), 'montant exact proposé, réglé');
  const groupes = await p.locator('.paiement-ligne .paiement-type__nom').allTextContents();
  v(groupes[0] === 'Caisse' && groupes.includes('Mobile money') && await p.locator('.paiement-ligne .paiement-type:first-child .puce[aria-pressed=true]').count() === 1, 'comptes rangés par type, une caisse choisie par défaut : ' + groupes.join(', '));
  await p.click('#paiement-valider'); await p.waitForSelector('#paiement-fin:not([hidden])');
  let id = derniere();
  v(sql(`SELECT CONCAT(total,'|',rendu,'|',statut) FROM ventes WHERE id=${id}`) === '33800.000|0.000|payee', 'vente 1 enregistrée : 33 800 FC, sans monnaie');
  v(await p.locator('#lignes li').count() === 0 && await p.evaluate(() => Object.keys(sessionStorage).filter(k => k.startsWith('caisse.ticket')).every(k => sessionStorage.getItem(k) === null || sessionStorage.getItem(k) === '[]')), 'ticket vidé après l\'encaissement');
  await p.click('#paiement-imprimer').catch(() => {});
  v((await p.innerHTML('#zone-impression')).includes('recu__total'), 'Imprimer : le reçu est prêt pour l\'impression');
  await p.click('#paiement-suivant');
  // 2. Les deux : 5 $ + le reste en FC
  await ajouter(p, ['Primus|Grande', 'Primus|Grande', 'Kivu Sunset']);
  await p.click('#encaisser'); await p.click('#paiement-devises button:has-text("Les deux")');
  await p.fill('#recu-USD', '5');
  const resteFC = await p.inputValue('#recu-CDF');
  v(resteFC === '19800', 'les deux : 5 $ puis le reste proposé en FC : ' + resteFC);
  await p.locator('#paiement-lignes .paiement-ligne:nth-child(2) .paiement-type', { hasText: 'Mobile money' }).locator('.puce').first().click();
  await p.click('#paiement-valider'); await p.waitForSelector('#paiement-fin:not([hidden])'); id = derniere();
  v(sql(`SELECT GROUP_CONCAT(CONCAT(p.moyen,':',d.code,':',p.recu) ORDER BY p.id) FROM vente_paiements p JOIN devises d ON d.id=p.devise_id WHERE vente_id=${id}`) === 'caisse:USD:5.000,mobile_money:CDF:19800.000', 'paiements : 5 $ en caisse + 19 800 FC sur un compte mobile money');
  await p.click('#paiement-suivant');
  // 3. Monnaie : 50 $ pour 12 $, rendue en FC
  await ajouter(p, ['Primus|Grande', 'Primus|Grande', 'Kivu Sunset']);
  await p.click('#encaisser'); await p.click('#paiement-devises button:text-is("$")');
  await p.locator('.paiement-rapide', { hasText: '$50' }).click();
  v((await p.textContent('#paiement-bilan')).includes('Monnaie à rendre') && await p.locator('#paiement-rendu:not([hidden])').count() === 1, 'monnaie à rendre, au choix FC ou $');
  await p.locator('#paiement-rendu-devises button', { hasText: 'FC' }).click();
  await p.click('#paiement-valider'); await p.waitForSelector('#paiement-fin:not([hidden])'); id = derniere();
  v(sql(`SELECT CONCAT(v.rendu,'|',d.code) FROM ventes v JOIN devises d ON d.id=v.rendu_devise_id WHERE v.id=${id}`) === '107000.000|CDF', '50 $ pour 12 $ : 107 000 FC rendus (38/12 de 33 800 = 107 033, vers le bas)');
  v((await p.textContent('#paiement-recu')).includes('Monnaie rendue'), 'reçu : monnaie rendue');
  await p.click('#paiement-suivant');
  // 4. Paiement insuffisant : bouton inactif
  await ajouter(p, ['Kivu Sunset']); await p.click('#encaisser'); await p.fill('#recu-CDF', '10000');
  v(await p.locator('#paiement-valider').isDisabled() && (await p.textContent('#paiement-bilan')).includes('Reste à payer'), 'paiement insuffisant : « Reste à payer », validation impossible');
  // 5. Double toucher : une seule vente
  await p.fill('#recu-CDF', '22800'); const avant = Number(sql('SELECT COUNT(*) FROM ventes'));
  await p.locator('#paiement-valider').dblclick(); await p.waitForSelector('#paiement-fin:not([hidden])'); await p.waitForTimeout(500);
  v(Number(sql('SELECT COUNT(*) FROM ventes')) === avant + 1, 'double toucher sur Valider : une seule vente');
  await p.click('#paiement-suivant');
  // 6. Panne réseau puis nouvel essai : une seule vente
  await ajouter(p, ['Kivu Sunset']); await p.click('#encaisser');
  let coupe = true; await p.route('**/caisse/encaisser', async r => { if (coupe) { coupe = false; const rep = await r.fetch(); await r.abort(); } else { await r.continue(); } });
  const avant2 = Number(sql('SELECT COUNT(*) FROM ventes'));
  await p.click('#paiement-valider'); await p.waitForSelector('#paiement-erreur:not([hidden])');
  v((await p.textContent('#paiement-erreur')).length > 0, 'réponse perdue : message, on peut réessayer');
  await p.click('#paiement-valider'); await p.waitForSelector('#paiement-fin:not([hidden])');
  v(Number(sql('SELECT COUNT(*) FROM ventes')) === avant2 + 1, 'réponse perdue puis nouvel essai : une seule vente (même clé)');
  await p.unroute('**/caisse/encaisser'); await p.click('#paiement-suivant');
  // 7. Club, caisse partagée : deux comptes mobile money en CDF, verrouillage après encaissement
  const tab = await b.newContext({ viewport: { width: 1280, height: 800 }, hasTouch: true });
  const t = await connecter(tab, 'admin@demo.test', MDP); t.on('pageerror', x => err.push(x.message));
  await t.goto(B + '/caisse?e=volcano-club'); await t.click('#quitter'); await t.click('.partager > summary:has-text("caisse partagée")');
  await Promise.all([t.waitForNavigation(), t.click('.partager__form button[type=submit]')]);
  await t.click('.verrou__personne[data-nom="Jean-Paul Bahati"]'); for (const c of '161803') await t.click(`#pave-touches [data-chiffre="${c}"]`); await t.waitForLoadState('load'); await t.waitForTimeout(300);
  await ajouter(t, ['Mojito']); await t.locator('.paiements [data-compte]', { hasText: 'Mobile money' }).click();
  const mm = t.locator('.paiement-ligne .paiement-type', { hasText: 'Mobile money' });
  const comptes = await mm.locator('.puce').allTextContents();
  v(comptes.length >= 2 && comptes.join(',').includes('Airtel') && await mm.locator('.puce[aria-pressed=true]').count() === 1, 'Club : raccourci mobile money, choix entre comptes mobile money : ' + comptes.join(', '));
  await mm.locator('.puce', { hasText: 'Airtel' }).click();
  await t.click('#paiement-valider'); await t.waitForSelector('#paiement-fin:not([hidden])'); id = derniere();
  v(sql(`SELECT c.nom FROM vente_paiements p JOIN comptes_tresorerie c ON c.id=p.compte_id WHERE p.vente_id=${id}`) .startsWith('Airtel'), 'paiement entré sur le compte Airtel Money');
  v(sql(`SELECT u.nom FROM ventes v JOIN utilisateurs u ON u.id=v.utilisateur_id WHERE v.id=${id}`) === 'Jean-Paul Bahati' && /^VC-\d{6}$/.test(sql(`SELECT numero FROM ventes WHERE id=${id}`)), 'vente au nom de Jean-Paul, numéro VC-…');
  v((await t.textContent('#paiement-suivant')).trim() === 'Terminer' && (await t.textContent('#paiement-recu')).includes('verrouillée'), 'après l\'encaissement : caisse verrouillée (réglage du Club)');
  const autre = await tab.newPage(); await autre.goto(B + '/caisse');
  v(await autre.locator('.verrou__personnes').count() === 1, 'verrouillage fait par le serveur : un autre onglet est déjà sur « Qui êtes-vous ? »');
  await autre.close();
  await Promise.all([t.waitForNavigation(), t.click('#paiement-suivant')]);
  v(await t.locator('.verrou__personnes').count() === 1, '« Terminer » : retour à « Qui êtes-vous ? »');
  // 8. Serveuse : prépare, n'encaisse pas
  const tab2 = await b.newContext({ viewport: { width: 1280, height: 800 } });
  const s = await connecter(tab2, 'admin@demo.test', MDP);
  await s.goto(B + '/caisse?e=comptoir-du-lac'); await s.click('#quitter'); await s.click('.partager > summary:has-text("caisse partagée")');
  await Promise.all([s.waitForNavigation(), s.click('.partager__form button[type=submit]')]);
  await s.click('.verrou__personne[data-nom="Aline Mukeba"]'); for (const c of '271828') await s.click(`#pave-touches [data-chiffre="${c}"]`); await s.waitForLoadState('load'); await s.waitForTimeout(300);
  await ajouter(s, ['Kivu Sunset']);
  v(await s.locator('#encaisser').isDisabled() && await s.locator('.paiements [data-compte]:not([disabled])').count() === 0 && (await s.textContent('#note-encaisser')).includes('caissier'), 'serveuse : Encaisser inactif, message « appelez un caissier »');
  // 9. Téléphone
  const tel = await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
  const tp = await connecter(tel, 'admin@demo.test', MDP);
  await tp.goto(B + '/caisse?e=comptoir-du-lac'); await ajouter(tp, ['Kivu Sunset', 'Primus|Petite']); await tp.click('#resume'); await tp.waitForTimeout(300); await tp.click('#encaisser');
  await tp.click('#paiement-devises button:has-text("Les deux")');
  v(await tp.evaluate(() => { const d = document.getElementById('paiement'); return d.scrollWidth <= d.clientWidth + 1 && document.documentElement.scrollWidth <= innerWidth; }), 'téléphone : fenêtre en plein écran, sans défilement horizontal');
  v(err.length === 0, 'aucune erreur JS ' + err.join(' | '));
  console.log(`\n${nb - ko.length}/${nb}`); await b.close();
})();
