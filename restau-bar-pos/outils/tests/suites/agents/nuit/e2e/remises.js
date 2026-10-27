// Remises et offerts à la caisse : % / montant / offert, ligne ou ticket, motif ; limite du rôle (superviseure 10 %),
// PIN d'un supérieur au-delà (faux PIN, valideur sans droit), caissière sans « caisse.remise », journal avec les deux noms,
// offert = perte « offert » dans le stock ; calcul dans les deux devises ; CSRF ; double envoi ; reçu, ventes, rapport.
const { chromium } = require('playwright');
const { B, CAP, sql, restaurer, v, bilan, proche, cle, connecter, ajouter, partager, donnees, encaisser, format, caisseDe, attendre } = require('./commun');

(async () => {
  restaurer();
  const b = await chromium.launch(); const err = [];
  const ctx = await b.newContext({ viewport: { width: 1366, height: 860 } });
  const p = await connecter(ctx, 'riziki@demo.test', 'Superviseur-2026', err);
  sql("UPDATE stock_parametres SET mode_negatif = 'vendre' WHERE etablissement_id = 2");   // la démo met le club en « bloquer »
  await p.goto(B + '/caisse?e=volcano-club'); await p.evaluate(() => sessionStorage.clear()); await p.reload(); await p.waitForTimeout(700);
  const D = await donnees(p);
  v(D.nuit && D.nuit.remise && Math.abs(D.nuit.remise.limite - 0.1) < 1e-9, 'superviseure : limite de remise 10 % envoyée à la caisse');
  v(D.nuit.remise.valideurs.some(x => x.id === 1) && !D.nuit.remise.valideurs.some(x => x.id === 8), 'valideurs : l\'administrateur, pas elle-même');
  const heineken = await format(p, 'Heineken');
  const fc = caisseDe(2, 'CDF');
  const brutFC = heineken.prix.CDF * 2, brutUSD = heineken.prix.USD * 2;
  const payer = (montant) => [{ devise: 'CDF', compte: fc, recu: montant }];

  // 1. 10 % sur le ticket : dans la limite, sans PIN ; les deux devises baissent de 10 %
  let r = await encaisser(p, { lignes: [{ id: heineken.id, qte: 2 }], paiements: payer(brutFC), remises: { lignes: {}, ticket: { type: 'pourcent', valeur: 10, motif: 'client_vip' } } });
  v(r.statut === 200, '10 % sur le ticket : encaissé (200) ' + (r.j.erreur || r.j.numero));
  let id = sql(`SELECT id FROM ventes WHERE numero='${r.j.numero}'`);
  v(proche(sql(`SELECT total FROM vente_totaux WHERE vente_id=${id} AND devise_id=2`), brutFC * 0.9), `total FC = ${brutFC} × 0,9`);
  v(proche(sql(`SELECT total FROM vente_totaux WHERE vente_id=${id} AND devise_id=1`), brutUSD * 0.9), `total $ = ${brutUSD} × 0,9`);
  v(proche(sql(`SELECT remise FROM ventes WHERE id=${id}`), brutFC * 0.1) && proche(sql(`SELECT brut FROM ventes WHERE id=${id}`), brutFC), 'vente : brut et remise enregistrés');
  v(sql(`SELECT CONCAT(type,'|',taux,'|',motif,'|',accorde_par,'|',IFNULL(valide_par,'-')) FROM vente_remises WHERE vente_id=${id}`) === 'pourcent|0.100000|client_vip|8|-', 'remise enregistrée : 10 %, motif, accordée par Riziki, sans valideur');

  // 2. 15 % : au-delà de la limite → PIN d'un supérieur
  const quinze = { lignes: {}, ticket: { type: 'pourcent', valeur: 15, motif: 'geste' } };
  r = await encaisser(p, { lignes: [{ id: heineken.id, qte: 2 }], paiements: payer(brutFC), remises: quinze });
  v(r.statut === 403 && r.j.code === 'remise_validation', '15 % sans PIN : refusé (403 remise_validation) : ' + r.j.erreur);
  r = await encaisser(p, { lignes: [{ id: heineken.id, qte: 2 }], paiements: payer(brutFC), remises: { ...quinze, validateur: 1, pin: '000000' } });
  v(r.statut === 422 && r.j.code === 'remise_pin', 'faux PIN du supérieur : refusé (422) : ' + r.j.erreur);
  v(+sql('SELECT pin_essais FROM utilisateurs WHERE id=1') === 1, 'faux PIN compté (1 essai)');
  r = await encaisser(p, { lignes: [{ id: heineken.id, qte: 2 }], paiements: payer(brutFC), remises: { ...quinze, validateur: 6, pin: '161803' } });
  v(r.statut === 403 && r.j.code === 'remise_validation', 'valideur sans droit (barman) : refusé (403)');
  r = await encaisser(p, { lignes: [{ id: heineken.id, qte: 2 }], paiements: payer(brutFC), remises: { ...quinze, validateur: 1, pin: '908172' } });
  v(r.statut === 200, '15 % avec le PIN de l\'administrateur : encaissé');
  id = sql(`SELECT id FROM ventes WHERE numero='${r.j.numero}'`);
  v(sql(`SELECT valide_par FROM vente_remises WHERE vente_id=${id}`) === '1' && +sql('SELECT pin_essais FROM utilisateurs WHERE id=1') === 0, 'valideur enregistré, essais remis à zéro');
  const journal = sql(`SELECT details FROM journal WHERE action='remise_accordee' ORDER BY id DESC LIMIT 1`);
  v(journal.includes('Riziki') && journal.includes('Administrateur'), 'journal « remise accordée » : les deux noms');

  // 3. Offert sur une ligne (100 % > limite) : PIN ; la ligne offerte sort du stock comme une perte « offert »
  const primus = await format(p, 'Primus', 'Grande');
  const pertesAvant = +sql("SELECT COUNT(*) FROM pertes_stock WHERE motif='offert'");
  r = await encaisser(p, { lignes: [{ id: heineken.id, qte: 1 }, { id: primus.id, qte: 1 }], paiements: payer(heineken.prix.CDF),
    remises: { lignes: { i1: { type: 'offert', motif: 'geste', precision: 'anniversaire' } }, ticket: null, validateur: 1, pin: '908172' } });
  v(r.statut === 200, 'Primus offerte (PIN) : encaissé ' + (r.j.erreur || ''));
  id = sql(`SELECT id FROM ventes WHERE numero='${r.j.numero}'`);
  v(proche(sql(`SELECT total FROM vente_totaux WHERE vente_id=${id} AND devise_id=2`), heineken.prix.CDF), 'total = la Heineken seule');
  v(sql(`SELECT CONCAT(offert,'|',remise) FROM vente_lignes WHERE vente_id=${id} AND conditionnement_id=${primus.id}`) === `1|${(+primus.prix.CDF).toFixed(3)}`, 'ligne offerte : offert = 1, remise = son prix');
  await attendre(300);
  v(+sql("SELECT COUNT(*) FROM pertes_stock WHERE motif='offert'") === pertesAvant + 1 && sql("SELECT commentaire FROM pertes_stock WHERE motif='offert' ORDER BY id DESC LIMIT 1").includes(r.j.numero), 'perte « offert » créée dans le stock (avec le numéro de la vente)');
  v(r.j.recu.includes('Offert') || r.j.recu.includes('offert'), 'reçu : la ligne offerte est marquée');

  // 4. Montant en $ sur un ticket en FC (même part dans chaque devise) ; montant plus grand que la ligne ; motif inconnu
  r = await encaisser(p, { lignes: [{ id: heineken.id, qte: 2 }], paiements: payer(brutFC), remises: { lignes: { i0: { type: 'montant', valeur: 0.1, devise: 'USD', motif: 'promotion' } }, ticket: null } });
  v(r.statut === 200, `remise de 0,10 $ sur la ligne : encaissée ${r.j.erreur || ''}`);
  id = sql(`SELECT id FROM ventes WHERE numero='${r.j.numero}'`);
  const part = 0.1 / brutUSD;
  v(proche(sql(`SELECT total FROM vente_totaux WHERE vente_id=${id} AND devise_id=1`), brutUSD - 0.1) && proche(sql(`SELECT total FROM vente_totaux WHERE vente_id=${id} AND devise_id=2`), Math.round(brutFC * (1 - part) * 1000) / 1000, 0.002), 'même part retirée en $ et en FC');
  r = await encaisser(p, { lignes: [{ id: heineken.id, qte: 1 }], paiements: payer(brutFC), remises: { lignes: { i0: { type: 'montant', valeur: 999, devise: 'USD', motif: 'geste' } }, ticket: null } });
  v(r.statut === 422 && r.j.code === 'remise', 'remise plus grande que la ligne : refusée (422)');
  r = await encaisser(p, { lignes: [{ id: heineken.id, qte: 1 }], paiements: payer(brutFC), remises: { lignes: {}, ticket: { type: 'pourcent', valeur: 5, motif: 'nimporte' } } });
  v(r.statut === 422 && r.j.code === 'remise', 'motif inconnu : refusé (422)');
  r = await encaisser(p, { lignes: [{ id: heineken.id, qte: 1 }], paiements: payer(brutFC), remises: { lignes: {}, ticket: { type: 'pourcent', valeur: 120, motif: 'geste' } } });
  v(r.statut === 422, 'plus de 100 % : refusé (422)');

  // 5. CSRF et double envoi
  r = await encaisser(p, { lignes: [{ id: heineken.id, qte: 1 }], paiements: payer(brutFC) }, 'faux');
  v(r.statut === 419, 'jeton CSRF faux : refusé (419)');
  const c = cle(); const D2 = await donnees(p);
  const corps = { csrf: D2.csrf, vente: JSON.stringify({ cle: c, etab: 'volcano-club', lignes: [{ id: heineken.id, qte: 2 }], paiements: payer(brutFC), remises: { lignes: {}, ticket: { type: 'pourcent', valeur: 5, motif: 'geste' } } }) };
  const [r1, r2] = await Promise.all([p.request.post(B + '/caisse/encaisser', { form: corps }), p.request.post(B + '/caisse/encaisser', { form: corps })]);
  const [j1, j2] = [await r1.json(), await r2.json()];
  v(r1.status() === 200 && r2.status() === 200 && j1.numero === j2.numero && +sql(`SELECT COUNT(*) FROM ventes WHERE cle='${c}'`) === 1 && +sql(`SELECT COUNT(*) FROM vente_remises r JOIN ventes v ON v.id=r.vente_id WHERE v.cle='${c}'`) === 1, 'double envoi simultané : une seule vente, une seule remise');

  // 6. Caissière (sans « caisse.remise ») au Comptoir du Lac : toute remise demande un PIN ; Patrick valide
  const ctx2 = await b.newContext({ viewport: { width: 1024, height: 768 } });
  const q = await partager(ctx2, 'comptoir-du-lac', 'Esther Kahindo', '314159', err);
  const Dq = await donnees(q);
  v(Dq.nuit.remise && Dq.nuit.remise.limite === 0, 'caissière : limite 0 (toute remise validée)');
  const fanta = (await donnees(q)).produits.find(x => x.formats.some(f => typeof f.prix.CDF === 'number'));
  const ff = fanta.formats.find(f => typeof f.prix.CDF === 'number');
  const fcLac = caisseDe(1, 'CDF');
  r = await encaisser(q, { lignes: [{ id: ff.id, qte: 1 }], paiements: [{ devise: 'CDF', compte: fcLac, recu: ff.prix.CDF }], remises: { lignes: {}, ticket: { type: 'pourcent', valeur: 5, motif: 'geste' } } });
  v(r.statut === 403 && r.j.code === 'remise_validation', 'caissière : 5 % refusé sans PIN (403)');
  r = await encaisser(q, { lignes: [{ id: ff.id, qte: 1 }], paiements: [{ devise: 'CDF', compte: fcLac, recu: ff.prix.CDF }], remises: { lignes: {}, ticket: { type: 'pourcent', valeur: 5, motif: 'geste' }, validateur: 2, pin: '482913' } });
  v(r.statut === 200, 'caissière : 5 % validé par Patrick (PIN)');

  // 7. Fenêtre de paiement (Riziki) : remise de 10 % puis 20 % avec le PIN, dans l'écran
  await p.reload(); await p.waitForTimeout(600);
  await ajouter(p, ['Heineken', 'Heineken']);
  await p.click('#encaisser'); await p.waitForSelector('#paiement[open]');
  await p.click('#paiement-remise'); await p.waitForSelector('#nuit-fenetre[open]');
  await p.fill('#remise-valeur', '10'); await p.click('#remise-appliquer'); await p.waitForTimeout(250);
  let tot = await p.evaluate(() => window.Caisse.paiement().totaux);
  v(proche(tot.CDF, brutFC * 0.9) && proche(tot.USD, brutUSD * 0.9), `fenêtre : totaux remisés (${tot.CDF} FC, ${tot.USD} $)`);
  v((await p.textContent('#paiement-nuit')).includes('10'), 'fenêtre : la remise est affichée');
  await p.screenshot({ path: CAP + 'remise-fenetre-ordi.png' });
  // Remplacer par 20 % : la validation apparaît
  await p.click('#paiement-remise'); await p.waitForSelector('#nuit-fenetre[open]');
  await p.fill('#remise-valeur', '20'); await p.click('#remise-appliquer'); await p.waitForTimeout(250);
  v(await p.locator('#nuit-pin').count() === 1, '20 % : demande du PIN d\'un supérieur');
  await p.locator('#paiement-nuit .nuit-validation .puce', { hasText: 'Administrateur' }).click();
  await p.fill('#nuit-pin', '908172');
  await p.screenshot({ path: CAP + 'remise-validation-ordi.png' });
  await p.click('#paiement-valider'); await p.waitForSelector('#paiement-fin:not([hidden])', { timeout: 8000 });
  const recu = await p.textContent('#paiement-recu');
  v(/Sous-total|Remise/i.test(recu), 'reçu : sous-total et remise');
  await p.screenshot({ path: CAP + 'remise-recu-ordi.png' });
  const der = sql("SELECT CONCAT(taux,'|',valide_par) FROM vente_remises ORDER BY id DESC LIMIT 1");
  v(der === '0.200000|1', 'écran : 20 % enregistré avec le valideur');

  // 8. Gestion : rapport des remises, liste des ventes, limites des rôles
  const g = await connecter(await b.newContext({ viewport: { width: 1366, height: 860 } }), 'admin@demo.test', require('./commun').MDP, err);
  await g.goto(B + '/gestion/remises');
  const page = await g.textContent('main');
  v(page.includes('Riziki') && page.includes('Administrateur'), 'rapport des remises : accordée par et validée par');
  await g.screenshot({ path: CAP + 'gestion-remises-ordi.png', fullPage: true });
  await g.goto(B + '/gestion/ventes');
  v(await g.locator('[data-total^="remises-"]').count() > 0, 'liste des ventes : total des remises');
  // Limite du rôle superviseur à 20 % : 15 % passe sans PIN
  const superv = sql("SELECT id FROM roles WHERE code='superviseur'");
  await g.goto(B + '/gestion/remises');
  await g.fill(`[name="limite[${superv}]"]`, '20');
  await Promise.all([g.waitForNavigation(), g.locator(`[name="limite[${superv}]"]`).locator('xpath=ancestor::form').locator('button[type=submit]').click()]);
  v(sql(`SELECT remise_max FROM roles WHERE id=${superv}`) === '20.00', 'limite du rôle superviseur : 20 %');
  r = await encaisser(p, { lignes: [{ id: heineken.id, qte: 2 }], paiements: payer(brutFC), remises: quinze });
  v(r.statut === 200, '15 % désormais dans la limite : sans PIN ' + r.statut + ' ' + (r.j.erreur || ''));
  // Riziki sans le droit d'administrer les limites
  const rz = await p.request.post(B + '/gestion/remises/limites', { form: { csrf: 'x', [`limite[${superv}]`]: '100' } });
  v(rz.status() >= 400 && sql(`SELECT remise_max FROM roles WHERE id=${superv}`) === '20.00', 'superviseure : ne change pas les limites');

  v(err.length === 0, 'aucune erreur JavaScript ' + err.join(' | '));
  await b.close(); bilan();
})();
