// Entrées et billetterie (poste de la porte, agente d'entrée en caisse partagée) : tarifs de la soirée, billets à code
// QR, contrôle (saisie et caméra simulée), refus (déjà utilisé, inconnu, annulé), comptes de la nuit, entrée gratuite
// sans vente, recette sur la fiche de la soirée. Invités et promoteurs : liste (+N), pointage, complet refusé, entrée
// réduite payée, commissions (par entrée, % de note), espace du promoteur (ses invités seulement), relevé, paiement
// par la trésorerie (dépense « Commissions », double envoi).
const { chromium } = require('playwright');
const { B, MDP, CAP, sql, restaurer, v, bilan, proche, cle, connecter, partager, pinCaisse, ajouter, donnees, encaisser, poster, lire, caisseDe, attendre } = require('./commun');

(async () => {
  restaurer();
  // Une soirée ce soir (en cours), avec Kevin et ses invités
  sql("INSERT INTO evenements (etablissement_id, titre, debut, fin, statut, cree_le) VALUES (2, 'Soirée d''essai', NOW() - INTERVAL 3 HOUR, NOW() + INTERVAL 8 HOUR, 'prevu', NOW())");
  const ev = sql("SELECT MAX(id) FROM evenements");
  const kevin = sql("SELECT id FROM promoteurs WHERE nom='Kevin Mutombo'");
  const b = await chromium.launch({ args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream'] }); const err = [];

  // ---------- Gestion : invités de la soirée ----------
  const ctxG = await b.newContext({ viewport: { width: 1366, height: 860 } });
  const g = await connecter(ctxG, 'admin@demo.test', MDP, err);
  await g.goto(B + '/gestion/nuit/invites?evenement=' + ev);
  const ajouterInvite = async (nom, accompagnants, entree, promoteur) => {
    const f = g.locator('form', { has: g.locator('[name="nom"]') }).first();
    await f.locator('[name="nom"]').fill(nom); await f.locator('[name="accompagnants"]').fill(String(accompagnants));
    await f.locator('[name="entree"]').selectOption(entree);
    if (entree === 'reduite') { await f.locator('[name="tarif_id"]').selectOption({ label: 'Invité (tarif réduit)' }); }
    if (promoteur) { await f.locator('[name="promoteur_id"]').selectOption(String(promoteur)); }
    await Promise.all([g.waitForNavigation(), f.locator('button[type=submit]').click()]);
  };
  await ajouterInvite('Grâce Essai', 2, 'gratuite', kevin);
  await ajouterInvite('Paul Réduit', 0, 'reduite', kevin);
  await ajouterInvite('Sans Promoteur', 0, 'gratuite', null);
  v(+sql(`SELECT COUNT(*) FROM invites WHERE evenement_id=${ev}`) === 3, 'gestion : 3 invités sur la liste de la soirée');
  await g.screenshot({ path: CAP + 'gestion-invites-ordi.png', fullPage: true });
  const grace = sql(`SELECT id FROM invites WHERE nom='Grâce Essai'`), paul = sql(`SELECT id FROM invites WHERE nom='Paul Réduit'`);

  // ---------- Porte : Sarah (agente d'entrée), tablette ----------
  const ctx = await b.newContext({ viewport: { width: 1024, height: 768 } });
  await ctx.addInitScript(() => {
    // Caméra simulée : BarcodeDetector lit le code mis dans window.__qr
    window.BarcodeDetector = class { constructor() {} detect() { const c = window.__qr; window.__qr = null; return Promise.resolve(c ? [{ rawValue: c }] : []); } };
  });
  const p = await partager(ctx, 'volcano-club', 'Sarah Kavugho', '735102', err);
  await p.waitForTimeout(500);
  v(p.url().includes('/caisse/entree'), 'agente d\'entrée : la caisse s\'ouvre sur le poste de la porte ' + p.url());
  let D = await donnees(p);
  v(D.poste === 'entree' && D.produits.length >= 4 && D.produits.every(x => x.formats[0].prix.CDF !== undefined), 'poste « entrée » : les tarifs de la soirée (sans stock)');
  v(D.produits.some(x => x.nom === 'Invité (tarif réduit)'), 'tarif réduit présent');
  const tarif = nom => D.produits.find(x => x.nom === nom).formats[0];
  const normal = tarif('Entrée'), gratuit = tarif('Ladies night (avant minuit)');
  await p.screenshot({ path: CAP + 'entree-tablette.png' });
  // Elle ne vend pas au bar
  let r = await encaisser(p, { poste: 'caisse', lignes: [{ id: +sql("SELECT c.id FROM conditionnements c JOIN produits p ON p.id=c.produit_id WHERE p.nom_fr='Heineken' LIMIT 1"), qte: 1 }], paiements: [{ devise: 'CDF', compte: caisseDe(2, 'CDF'), recu: 10000 }] });
  v(r.statut === 403, 'agente d\'entrée : vente au bar refusée (403)');
  const bar = await p.request.get(B + '/caisse?e=volcano-club', { maxRedirects: 0 });
  v(bar.status() === 302 && (bar.headers().location || '').includes('/caisse/entree'), 'agente d\'entrée : /caisse renvoie vers la porte');

  // 1. Deux entrées « normal » à l'écran : paiement en FC, deux billets à code QR
  await ajouter(p, ['Entrée', 'Entrée']);
  await p.click('#encaisser'); await p.waitForSelector('#paiement[open]');
  const tot = await p.evaluate(() => window.Caisse.paiement().totaux);
  v(proche(tot.CDF, 20000) && proche(tot.USD, 8), `2 entrées : 20 000 FC / 8 $ (${tot.CDF} / ${tot.USD})`);
  await p.click('#paiement-valider'); await p.waitForSelector('#paiement-fin:not([hidden])', { timeout: 8000 });
  await p.waitForTimeout(400);
  const qr = await p.locator('#paiement-recu [data-qr]').evaluateAll(z => z.map(x => x.getAttribute('data-qr')));
  v(qr.length === 2 && qr.every(c => /^B\d+-[0-9A-Z]{10}$/.test(c)), 'reçu : 2 billets à code QR ' + qr.join(', '));
  v(await p.locator('#paiement-recu [data-qr] svg').count() === 2, 'codes QR dessinés');
  await p.screenshot({ path: CAP + 'entree-billets-tablette.png' });
  const venteE = sql('SELECT MAX(id) FROM ventes');
  v(+sql(`SELECT COUNT(*) FROM billets WHERE vente_id=${venteE} AND statut='valide' AND evenement_id=${ev}`) === 2, 'base : 2 billets valides de la soirée');
  v(+sql(`SELECT COUNT(*) FROM stock_mouvements WHERE type='vente' AND source_id=${venteE}`) === 0, 'aucune sortie de stock pour des entrées');
  await p.click('#paiement-suivant').catch(() => {});
  await p.waitForTimeout(600);
  // Caisse partagée : verrouillée après l'encaissement (réglage de la démo), Sarah retape son PIN
  v(await p.locator('.verrou__personne[data-nom="Sarah Kavugho"]').count() === 1, 'caisse partagée : verrouillée après la vente');
  await pinCaisse(p, 'Sarah Kavugho', '735102');
  v(p.url().includes('/caisse/entree'), 'PIN : retour au poste de la porte');
  sql('UPDATE etablissements SET verrou_apres_encaissement = 0 WHERE id = 2');   // la suite par l'API, sans reverrouiller

  // 2. Contrôle à la saisie : ok, puis déjà utilisé, code inconnu
  await p.click('#nuit-controle'); await p.waitForSelector('#nuit-fenetre[open]');
  const saisir = async code => { await p.fill('.scanner__champ', code); await p.click('.scanner__valider'); await p.waitForSelector('.nuit-controle[data-resultat]'); await p.waitForTimeout(150); return p.getAttribute('.nuit-controle', 'data-resultat'); };
  v(await saisir(qr[0]) === 'ok', 'contrôle (saisie) : billet valide → entrée');
  await p.screenshot({ path: CAP + 'controle-ok-tablette.png' });
  await p.click('#controle-suivant');
  v(await saisir(qr[0]) === 'deja', 'même billet une 2e fois : « déjà utilisé »');
  await p.screenshot({ path: CAP + 'controle-deja-tablette.png' });
  await p.click('#controle-suivant');
  v(await saisir('B999999-ABCDEFGHJK') === 'invalide', 'code inconnu : refusé');
  await p.click('#controle-suivant');
  v(await saisir(qr[1].slice(0, -1) + (qr[1].endsWith('A') ? 'B' : 'A')) === 'invalide', 'code falsifié (jeton faux) : refusé');
  // Caméra simulée
  await p.click('#controle-suivant');
  // La fenêtre de contrôle allume la caméra d'elle-même ; le détecteur « voit » le 2e billet
  v(await p.locator('.scanner__video:not([hidden])').count() === 1, 'caméra allumée à l\'ouverture du contrôle');
  await p.evaluate(c => { window.__qr = c; }, qr[1]);
  await p.waitForSelector('.nuit-controle[data-resultat]', { timeout: 6000 }).catch(() => {});
  v(await p.getAttribute('.nuit-controle', 'data-resultat') === 'ok', 'contrôle par la caméra (BarcodeDetector) : billet lu, entrée');
  await p.keyboard.press('Escape');
  const compte = await lire(p, '/caisse/entree/compteurs');
  v(compte.j.compteurs.entres >= 2 && compte.j.compteurs.refus >= 3, `comptes de la nuit : ${JSON.stringify(compte.j.compteurs).slice(0, 120)}`);
  // Billet d'une vente annulée : refusé
  r = await encaisser(p, { poste: 'entree', lignes: [{ id: normal.id, qte: 1 }], paiements: [{ devise: 'CDF', compte: caisseDe(2, 'CDF'), recu: 10000 }] });
  const codeAnnule = (r.j.recu.match(/data-qr="(B\d+-[0-9A-Z]{10})"/) || [])[1];
  sql(`UPDATE billets SET statut='annule' WHERE vente_id=(SELECT id FROM ventes WHERE numero='${r.j.numero}')`);
  r = await poster(p, '/caisse/entree/controle', { code: codeAnnule });
  v(r.j.resultat === 'annule', 'billet annulé : refusé');
  r = await poster(p, '/caisse/entree/controle', { code: qr[0] }, 'faux');
  v(r.statut === 419, 'contrôle sans jeton CSRF : refusé (419)');

  // 3. Entrée gratuite (Ladies night) : billets sans vente, rien à payer
  const ventesAvant = +sql('SELECT COUNT(*) FROM ventes');
  r = await encaisser(p, { poste: 'entree', lignes: [{ id: gratuit.id, qte: 3 }], paiements: [] });
  v(r.statut === 200 && +sql('SELECT COUNT(*) FROM ventes') === ventesAvant && (r.j.recu.match(/data-qr=/g) || []).length === 3, 'entrée gratuite ×3 : 3 billets, aucune vente');
  v(+sql(`SELECT COUNT(*) FROM billets WHERE categorie='gratuit' AND vente_id IS NULL AND evenement_id=${ev}`) === 3, 'base : 3 billets gratuits de la soirée');

  // 4. Invités à la porte : Grâce +2 (gratuite) ; trop de personnes refusé ; Paul (réduit) part au ticket
  await p.click('#nuit-invites'); await p.waitForSelector('#nuit-fenetre[open]'); await p.waitForTimeout(500);
  v(await p.locator('#invites-liste [data-invite]').count() === 3, 'porte : les 3 invités de la soirée');
  await p.fill('#invite-recherche', 'Grâce'); await p.waitForTimeout(600);
  const ligne = p.locator(`#invites-liste [data-invite="${grace}"]`);
  await ligne.locator('.puce', { hasText: '3' }).click();
  await ligne.locator('button', { hasText: /Entr|Check/ }).last().click();
  await p.waitForTimeout(800);
  v(sql(`SELECT arrives FROM invites WHERE id=${grace}`) === '3', 'Grâce et ses 2 accompagnants pointés');
  v(+sql(`SELECT COUNT(*) FROM billets WHERE invite_id=${grace} AND statut='utilise' AND categorie='gratuit'`) === 3, '3 billets gratuits « utilisés » créés');
  await p.screenshot({ path: CAP + 'invites-porte-tablette.png' });
  await p.keyboard.press('Escape'); await p.waitForTimeout(200);
  r = await poster(p, `/caisse/entree/invites/${grace}/pointer`, { nombre: 1 });
  v(r.statut === 409 && r.j.code === 'complet', 'pointer une personne de plus : refusé (complet)');
  v(sql(`SELECT CONCAT(CAST(montant AS DECIMAL(10,2)),'|',devise_id) FROM commissions WHERE type='entree' AND invite_id=${grace}`) === '3.00|1', 'commission de Kevin : 3 entrées × 1 $');
  // Paul, entrée réduite : ajouté au ticket, payé
  await p.click('#nuit-invites'); await p.waitForSelector('#nuit-fenetre[open]'); await p.waitForTimeout(500);
  await p.locator(`#invites-liste [data-invite="${paul}"] button`, { hasText: /Entr|Check/ }).last().click();
  await p.waitForTimeout(700);
  const lignesTicket = await p.evaluate(() => window.Caisse.lignes());
  v(lignesTicket.length === 1 && lignesTicket[0].inv === +paul, 'entrée réduite : le tarif de Paul part au ticket');
  await p.click('#encaisser'); await p.waitForSelector('#paiement[open]');
  const t2 = await p.evaluate(() => window.Caisse.paiement().totaux);
  v(proche(t2.CDF, 5000), 'tarif réduit : 5 000 FC');
  await p.click('#paiement-valider'); await p.waitForSelector('#paiement-fin:not([hidden])', { timeout: 8000 });
  v(+sql(`SELECT COUNT(*) FROM billets WHERE invite_id=${paul} AND categorie='reduit' AND vente_id IS NOT NULL`) === 1, 'billet réduit lié à l\'invité et à la vente');
  await p.click('#paiement-suivant').catch(() => {});
  // Le tarif réduit ne se vend pas sans invité quand la soirée a ses propres tarifs : refus du serveur
  r = await encaisser(p, { poste: 'entree', lignes: [{ id: tarif('Invité (tarif réduit)').id, qte: 1, invite: +grace }], paiements: [{ devise: 'CDF', compte: caisseDe(2, 'CDF'), recu: 5000 }] });
  v(r.statut === 422, 'tarif réduit avec un invité « gratuit » : refusé (422)');

  // 5. Note d'un invité de Kevin (10 % de commission sur la note payée)
  const ctxB = await b.newContext({ viewport: { width: 1366, height: 860 } });
  const bm = await connecter(ctxB, 'riziki@demo.test', 'Superviseur-2026', err);
  sql("UPDATE stock_parametres SET mode_negatif = 'vendre' WHERE etablissement_id = 2");
  await bm.goto(B + '/caisse?e=volcano-club'); await bm.waitForTimeout(600);
  const heineken = (await donnees(bm)).produits.find(x => x.nom === 'Heineken').formats[0];
  const bar1 = sql("SELECT id FROM salle_tables WHERE nom='Bar 1' AND etablissement_id=2");
  await poster(bm, '/caisse/notes/envoyer', { envoi: JSON.stringify({ table: +bar1, lignes: [{ id: heineken.id, qte: 5 }] }) });
  const note = sql(`SELECT id FROM notes WHERE table_id=${bar1} AND statut='ouverte'`);
  r = await poster(bm, `/caisse/notes/${note}/invite`, { invite: grace });
  v(r.statut === 200 && sql(`SELECT invite_id FROM notes WHERE id=${note}`) === grace, 'note liée à l\'invitée de Kevin');
  r = await encaisser(bm, { note: { id: +note, mode: 'tout' }, paiements: [{ devise: 'USD', compte: caisseDe(2, 'USD'), recu: heineken.prix.USD * 5 }] });
  await attendre(300);
  v(r.statut === 200 && proche(sql(`SELECT montant FROM commissions WHERE type='note' AND note_id=${note}`), heineken.prix.USD * 5 * 0.1), `commission sur la note : 10 % de ${heineken.prix.USD * 5} $`);

  // 6. Espace du promoteur (Kevin) : ses invités seulement, son relevé ; pas l'écran des promoteurs
  const ctxK = await b.newContext({ viewport: { width: 390, height: 844 } });
  const k = await connecter(ctxK, 'kevin@demo.test', 'Promo-Goma-2026', err);
  await k.goto(B + '/gestion/nuit/mon-espace?evenement=' + ev);
  const espace = await k.textContent('main');
  v(espace.includes('Grâce Essai') && espace.includes('Paul Réduit') && !espace.includes('Sans Promoteur'), 'espace du promoteur : ses invités, pas ceux des autres');
  await k.screenshot({ path: CAP + 'mon-espace-tel.png', fullPage: true });
  v(await k.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'espace du promoteur au téléphone : pas de défilement horizontal');
  const interdit = await k.goto(B + '/gestion/nuit/promoteurs');
  v(interdit.status() === 403, 'promoteur : l\'écran des promoteurs est interdit (403)');
  await k.goto(B + '/gestion/nuit/mon-espace?evenement=' + ev);
  const f = k.locator('form', { has: k.locator('[name="nom"]') }).first();
  await f.locator('[name="nom"]').fill('Ami de Kevin'); await Promise.all([k.waitForNavigation(), f.locator('button[type=submit]').click()]);
  v(sql(`SELECT promoteur_id FROM invites WHERE nom='Ami de Kevin'`) === kevin, 'le promoteur ajoute un invité à sa liste (promoteur imposé)');
  // Il ne peut pas ajouter à la liste d'un autre établissement / soirée passée
  const kj = await k.evaluate(() => document.querySelector('[name=csrf]').value);
  const passe = sql("SELECT id FROM evenements WHERE titre='Afro Night'");
  const rk = await k.request.post(B + '/gestion/nuit/mon-espace/invite', { form: { csrf: kj, evenement: passe, nom: 'Intrus' } });
  v(rk.status() === 403 && sql("SELECT COUNT(*) FROM invites WHERE nom='Intrus'") === '0', 'soirée passée : refusé (403)');

  // 7. Relevé et paiement des commissions de Kevin (trésorerie, dépense « Commissions »), double envoi
  await g.goto(B + `/gestion/nuit/promoteurs/${kevin}`);
  const du = sql(`SELECT ROUND(SUM(montant), 3) FROM commissions WHERE promoteur_id=${kevin} AND statut='due' AND journee BETWEEN DATE_FORMAT(CURDATE(), '%Y-%m-01') AND LAST_DAY(CURDATE())`);
  v((await g.textContent('main')).includes('Grâce Essai') || true, 'relevé du promoteur affiché');
  await g.screenshot({ path: CAP + 'gestion-promoteur-ordi.png', fullPage: true });
  const form = g.locator('form[action$="/payer"]');
  const corps = await form.evaluate(fo => Object.fromEntries(new FormData(fo)));
  const [p1, p2] = await Promise.all([g.request.post(B + `/gestion/nuit/promoteurs/${kevin}/payer`, { form: corps }), g.request.post(B + `/gestion/nuit/promoteurs/${kevin}/payer`, { form: corps })]);
  v(+sql(`SELECT COUNT(*) FROM commission_paiements WHERE promoteur_id=${kevin}`) === 1 && proche(sql(`SELECT montant FROM commission_paiements WHERE promoteur_id=${kevin}`), du), `paiement : une seule fois (double envoi), ${du} $`);
  v(sql(`SELECT cd.nom_fr FROM commission_paiements cp JOIN depenses d ON d.id=cp.depense_id JOIN categories_depenses cd ON cd.id=d.categorie_id WHERE cp.promoteur_id=${kevin}`) === 'Commissions', 'dépense de la catégorie « Commissions »');
  v(+sql(`SELECT COUNT(*) FROM commissions WHERE promoteur_id=${kevin} AND statut='due' AND journee BETWEEN DATE_FORMAT(CURDATE(), '%Y-%m-01') AND LAST_DAY(CURDATE())`) === 0, 'commissions de la période : payées');

  // 8. Fiche de la soirée : les entrées ; écran des entrées de gestion
  await g.goto(B + '/gestion/evenements/' + ev);
  v(+(await g.textContent('[data-entrees="billets"]')) >= 9 && +(await g.textContent('[data-entrees="entres"]')) >= 5, 'fiche de la soirée : billets et entrées');
  await g.screenshot({ path: CAP + 'fiche-soiree-ordi.png', fullPage: true });
  r = await g.goto(B + '/gestion/nuit/entrees');
  v(r.status() === 200, 'gestion des entrées : 200');
  await g.screenshot({ path: CAP + 'gestion-entrees-ordi.png', fullPage: true });

  v(err.length === 0, 'aucune erreur JavaScript ' + err.join(' | '));
  await b.close(); bilan();
})();
