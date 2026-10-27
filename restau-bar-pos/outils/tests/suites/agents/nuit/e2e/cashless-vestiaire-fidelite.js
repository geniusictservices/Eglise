// Cashless : carte créée à la caisse, recharge par la fenêtre de paiement (en FC pour une carte en $ : équivalent
// arrondi vers le haut, monnaie rendue ; l'argent entre en trésorerie « cashless »), double envoi, paiement par carte
// (seul ou avec des espèces, sans mouvement de trésorerie pour la part carte), solde insuffisant, carte bloquée, code
// falsifié, part trop grande, annulation (solde rendu), remboursement (sortie de trésorerie), passif.
// Vestiaire (Didier, caisse partagée) : dépôt payant (vente + ticket QR), retour par numéro et par code, déjà rendu,
// objets restants, dépôt gratuit refusé tant qu'il y a des frais. Fidélité (Comptoir du Lac) : points gagnés,
// récompense (10 %) et article offert, points insuffisants, fiche du client.
const { chromium } = require('playwright');
const { B, MDP, CAP, sql, restaurer, v, bilan, proche, cle, connecter, partager, pinCaisse, ajouter, donnees, encaisser, poster, lire, caisseDe, attendre } = require('./commun');

(async () => {
  restaurer();
  sql("UPDATE stock_parametres SET mode_negatif = 'vendre'");
  sql('UPDATE etablissements SET verrou_apres_encaissement = 0');
  const b = await chromium.launch(); const err = [];
  const ctx = await b.newContext({ viewport: { width: 1366, height: 860 } });
  const p = await connecter(ctx, 'admin@demo.test', MDP, err);
  await p.goto(B + '/caisse?e=volcano-club'); await p.evaluate(() => sessionStorage.clear()); await p.reload(); await p.waitForTimeout(700);
  const fc = caisseDe(2, 'CDF'), usd = caisseDe(2, 'USD');
  let r;
  const tresor = (devise, compte) => Number(sql(`SELECT COALESCE(SUM(IF(sens='entree', montant, -montant)), 0) FROM mouvements_tresorerie WHERE compte_id=${compte} AND devise_id=${devise}`));

  // ---------- 1. Nouvelle carte à la caisse ----------
  await p.click('#nuit-cashless'); await p.waitForSelector('#nuit-fenetre[open]');
  await p.click('#nuit-corps .ardoise-nouveau > summary'); await p.fill('#carte-porteur', 'Jean Essai');
  await p.click('#carte-creer'); await p.waitForSelector('#carte-recharger');
  const carte = JSON.parse(sql("SELECT JSON_OBJECT('id', id, 'numero', numero, 'jeton', jeton) FROM cartes_cashless ORDER BY id DESC LIMIT 1"));
  const code = 'C' + String(carte.numero).padStart(4, '0') + '-' + carte.jeton;
  v(carte.numero === 11 && (await p.textContent('#nuit-corps')).includes(code), 'carte n° 11 créée, code affiché ' + code);
  v(await p.locator('#nuit-corps .nuit-impression [data-qr] svg').count() === 1, 'carte à imprimer avec son code QR');
  await p.screenshot({ path: CAP + 'cashless-nouvelle-ordi.png' });

  // ---------- 2. Recharge de 20 $ payée en francs (60 000 FC) ----------
  const fcAvant = tresor(2, fc);
  await p.click('#carte-recharger');
  await p.locator('#nuit-corps .nuit-puces .puce', { hasText: '$' }).first().click();
  await p.fill('#recharge-montant', '20'); await p.click('#recharge-valider');
  await p.waitForSelector('#paiement[open]');
  const tot = await p.evaluate(() => window.Caisse.paiement().totaux);
  v(proche(tot.USD, 20) && proche(tot.CDF, 57000), `recharge : 20 $ ou 57 000 FC (${tot.CDF})`);
  v(await p.locator('#paiement-remise').count() === 0 && await p.locator('#paiement-carte').count() === 0, 'recharge : ni remise ni paiement par carte');
  await p.screenshot({ path: CAP + 'cashless-recharge-ordi.png' });
  // Payer 60 000 FC en espèces (monnaie 3 000 FC)
  await p.evaluate(() => { const pa = window.Caisse.paiement(); pa.lignes.forEach(l => { l.recu = 0; l.saisi = true; }); });
  r = await poster(p, '/caisse/nuit/carte/recharger', { vente: JSON.stringify({ cle: cle(), etab: 'volcano-club', extra: { carte: code, devise: 'USD', montant: 20 }, paiements: [{ devise: 'CDF', compte: fc, recu: 60000 }], rendu: 'CDF' }) });

  v(r.statut === 200 && proche(sql(`SELECT solde FROM carte_soldes WHERE carte_id=${carte.id} AND devise_id=1`), 20), 'recharge par l\'API : solde 20 $ ' + (r.j.erreur || ''));
  v(proche(tresor(2, fc) - fcAvant, 57000), 'trésorerie : +57 000 FC net (60 000 reçus, 3 000 rendus) ' + (tresor(2, fc) - fcAvant));
  v(+sql("SELECT COUNT(*) FROM mouvements_tresorerie WHERE nature='cashless' AND date_operation > NOW() - INTERVAL 1 HOUR") >= 1, 'mouvement de trésorerie « cashless »');
  await p.keyboard.press('Escape');
  // Double envoi d'une recharge (même clé) : une seule
  const cr = cle();
  const corpsR = { vente: JSON.stringify({ cle: cr, etab: 'volcano-club', extra: { carte: code, devise: 'USD', montant: 10 }, paiements: [{ devise: 'USD', compte: usd, recu: 10 }] }) };
  await Promise.all([poster(p, '/caisse/nuit/carte/recharger', corpsR), poster(p, '/caisse/nuit/carte/recharger', corpsR)]);
  v(proche(sql(`SELECT solde FROM carte_soldes WHERE carte_id=${carte.id} AND devise_id=1`), 30) && +sql(`SELECT COUNT(*) FROM carte_mouvements WHERE cle='${cr}'`) === 1, 'double envoi d\'une recharge : une seule (solde 30 $)');
  // Recharge par l'écran (Riziki n'est pas nécessaire : l'admin) payée en dollars
  await p.reload(); await p.waitForTimeout(600);
  await p.click('#nuit-cashless'); await p.waitForSelector('#nuit-fenetre[open]');
  await p.fill('.scanner__champ', code); await p.click('.scanner__valider'); await p.waitForSelector('#carte-recharger');
  await p.click('#carte-recharger'); await p.locator('#nuit-corps .nuit-puces .puce', { hasText: '$' }).first().click();
  await p.fill('#recharge-montant', '5'); await p.click('#recharge-valider'); await p.waitForSelector('#paiement[open]');
  await p.click('#paiement-valider'); await p.waitForTimeout(1200);
  await p.waitForSelector('#paiement-fin:not([hidden])', { timeout: 8000 });
  v(proche(sql(`SELECT solde FROM carte_soldes WHERE carte_id=${carte.id} AND devise_id=1`), 35), 'recharge à l\'écran : solde 35 $');
  v((await p.textContent('#paiement-recu')).includes(code.slice(0, 5)), 'reçu de recharge avec la carte');
  await p.screenshot({ path: CAP + 'cashless-recu-recharge-ordi.png' });
  await p.click('#paiement-suivant').catch(() => {});

  // ---------- 3. Payer avec la carte ----------
  const D = await donnees(p);
  const heineken = D.produits.find(x => x.nom === 'Heineken').formats[0];
  const usdAvant = tresor(1, usd), fcAvant2 = tresor(2, fc);
  r = await encaisser(p, { lignes: [{ id: heineken.id, qte: 2 }], paiements: [], cartes: [{ code, devise: 'USD', montant: heineken.prix.USD * 2 }] });
  v(r.statut === 200 && proche(sql(`SELECT solde FROM carte_soldes WHERE carte_id=${carte.id} AND devise_id=1`), 35 - heineken.prix.USD * 2), `payé entièrement par carte : solde ${35 - heineken.prix.USD * 2} $ ` + (r.j.erreur || ''));
  v(proche(tresor(1, usd), usdAvant) && proche(tresor(2, fc), fcAvant2), 'part carte : aucun mouvement de trésorerie');
  const venteCarte = sql(`SELECT id FROM ventes WHERE numero='${r.j.numero}'`);
  v(+sql(`SELECT COUNT(*) FROM vente_cashless WHERE vente_id=${venteCarte}`) === 1 && r.j.recu.includes(code.slice(0, 5)), 'vente : paiement cashless enregistré, carte sur le reçu');
  // Mixte : 1 $ par carte, le reste en FC
  const fcAvant3 = tresor(2, fc);
  const resteFC = Math.round(heineken.prix.CDF * 2 * (1 - 1 / (heineken.prix.USD * 2)));
  r = await encaisser(p, { lignes: [{ id: heineken.id, qte: 2 }], paiements: [{ devise: 'CDF', compte: fc, recu: Math.ceil(resteFC / 100) * 100 }], cartes: [{ code, devise: 'USD', montant: 1 }] });
  v(r.statut === 200, 'mixte (1 $ carte + FC) : encaissé ' + (r.j.erreur || ''));
  v(tresor(2, fc) - fcAvant3 > 0 && tresor(2, fc) - fcAvant3 < heineken.prix.CDF * 2, `trésorerie : seulement la part en FC (${tresor(2, fc) - fcAvant3} FC)`);
  // Refus : solde insuffisant, part trop grande, code falsifié, carte bloquée
  r = await encaisser(p, { lignes: [{ id: heineken.id, qte: 50 }], paiements: [], cartes: [{ code, devise: 'USD', montant: 500 }] });
  v(r.statut === 409 && r.j.code === 'carte_solde', 'solde insuffisant : refusé (409)');
  r = await encaisser(p, { lignes: [{ id: heineken.id, qte: 1 }], paiements: [], cartes: [{ code, devise: 'USD', montant: heineken.prix.USD + 5 }] });
  v(r.statut === 422 && r.j.code === 'carte', 'carte qui paie plus que le ticket : refusé (422)');
  r = await encaisser(p, { lignes: [{ id: heineken.id, qte: 1 }], paiements: [], cartes: [{ code: code.slice(0, -2) + 'ZZ', devise: 'USD', montant: 1 }] });
  v(r.statut === 422 && r.j.code === 'carte', 'code de carte falsifié : refusé (422)');
  const bloquee = JSON.parse(sql("SELECT JSON_OBJECT('n', numero, 'j', jeton) FROM cartes_cashless WHERE statut='bloquee' LIMIT 1"));
  r = await encaisser(p, { lignes: [{ id: heineken.id, qte: 1 }], paiements: [], cartes: [{ code: 'C' + String(bloquee.n).padStart(4, '0') + '-' + bloquee.j, devise: 'USD', montant: 1 }] });
  v(r.statut === 409 && r.j.code === 'carte_bloquee', 'carte bloquée (perdue) : refusée (409)');
  const soldeAvantSolde = Number(sql(`SELECT solde FROM carte_soldes WHERE carte_id=${carte.id} AND devise_id=1`));
  v(soldeAvantSolde >= 0, 'jamais de solde négatif');
  // Paiement par carte à l'écran
  await p.reload(); await p.waitForTimeout(600);
  await ajouter(p, ['Heineken']);
  await p.click('#encaisser'); await p.waitForSelector('#paiement[open]');
  await p.click('#paiement-carte'); await p.waitForSelector('#nuit-fenetre[open]');
  await p.fill('.scanner__champ', code); await p.click('.scanner__valider');
  await p.waitForSelector('#nuit-corps button[data-devise="USD"]'); await p.click('#nuit-corps button[data-devise="USD"]');
  await p.waitForTimeout(300);
  v((await p.textContent('#paiement-nuit')).includes('C0011'), 'fenêtre : carte ajoutée au paiement');
  await p.screenshot({ path: CAP + 'cashless-paiement-ordi.png' });
  await p.click('#paiement-valider'); await p.waitForSelector('#paiement-fin:not([hidden])', { timeout: 8000 });
  const soldeEcran = Number(sql(`SELECT solde FROM carte_soldes WHERE carte_id=${carte.id} AND devise_id=1`));
  v(proche(soldeEcran, soldeAvantSolde - heineken.prix.USD), `écran : payé par carte (solde ${soldeEcran} $)`);
  await p.click('#paiement-suivant').catch(() => {});

  // ---------- 4. Annulation d'une vente payée par carte : solde rendu ----------
  const g = await connecter(await b.newContext({ viewport: { width: 1366, height: 860 } }), 'admin@demo.test', MDP, err);
  await g.goto(B + '/gestion/ventes/' + venteCarte);
  const jg = await g.evaluate(() => document.querySelector('[name=csrf]').value);
  await g.request.post(B + `/gestion/ventes/${venteCarte}/annuler`, { form: { csrf: jg, motif: 'Essai', confirme: '1' } });
  v(sql(`SELECT statut FROM ventes WHERE id=${venteCarte}`) === 'annulee' && proche(sql(`SELECT solde FROM carte_soldes WHERE carte_id=${carte.id} AND devise_id=1`), soldeEcran + heineken.prix.USD * 2), 'vente annulée : la carte retrouve son solde');

  // ---------- 5. Remboursement (sortie de trésorerie), blocage, passif ----------
  const solde = Number(sql(`SELECT solde FROM carte_soldes WHERE carte_id=${carte.id} AND devise_id=1`));
  r = await poster(p, '/caisse/nuit/carte/rembourser', { carte: carte.id, devise: 'USD', montant: solde + 1, compte: usd, cle: cle() });
  v(r.statut === 409, 'rembourser plus que le solde : refusé (409)');
  const usdAvantR = tresor(1, usd);
  const cleR = cle();
  await Promise.all([poster(p, '/caisse/nuit/carte/rembourser', { carte: carte.id, devise: 'USD', montant: solde, compte: usd, cle: cleR }), poster(p, '/caisse/nuit/carte/rembourser', { carte: carte.id, devise: 'USD', montant: solde, compte: usd, cle: cleR })]);
  v(proche(sql(`SELECT solde FROM carte_soldes WHERE carte_id=${carte.id} AND devise_id=1`), 0) && proche(usdAvantR - tresor(1, usd), solde), `remboursé ${solde} $ une seule fois : sortie de trésorerie, solde 0`);
  r = await poster(p, '/caisse/nuit/carte/bloquer', { carte: carte.id, bloquer: '1', motif: 'Perdue' });
  v(r.statut === 200 && sql(`SELECT statut FROM cartes_cashless WHERE id=${carte.id}`) === 'bloquee', 'carte bloquée (motif « Perdue »)');
  r = await poster(p, '/caisse/nuit/carte/recharger', { vente: JSON.stringify({ cle: cle(), etab: 'volcano-club', extra: { carte: code, devise: 'USD', montant: 5 }, paiements: [{ devise: 'USD', compte: usd, recu: 5 }] }) });
  v(r.statut === 409, 'recharger une carte bloquée : refusé (409)');
  // Riziki (superviseure) recharge mais ne rembourse ni ne bloque
  const ctxR = await b.newContext({ viewport: { width: 1366, height: 860 } });
  const rz = await connecter(ctxR, 'riziki@demo.test', 'Superviseur-2026', err);
  await rz.goto(B + '/caisse?e=volcano-club'); await rz.waitForTimeout(500);
  const Dr = await donnees(rz);
  v(Dr.nuit.cashless.recharger && !Dr.nuit.cashless.gerer, 'superviseure : recharger oui, gérer non');
  r = await poster(rz, '/caisse/nuit/carte/rembourser', { carte: 1, devise: 'USD', montant: 1, compte: usd, cle: cle() });
  v(r.statut === 403, 'superviseure : rembourser refusé (403)');
  r = await poster(rz, '/caisse/nuit/carte/bloquer', { carte: 1, bloquer: '1' });
  v(r.statut === 403, 'superviseure : bloquer refusé (403)');
  // Passif = somme des soldes
  await g.goto(B + '/gestion/cashless/passif');
  const passif = Number(sql('SELECT ROUND(SUM(solde), 2) FROM carte_soldes WHERE devise_id=1'));
  const txt = await g.textContent('main');
  v(txt.includes(passif.toFixed(2).replace('.', ',')) || txt.includes(passif.toFixed(2)), `passif : ${passif} $ (somme des soldes)`);
  await g.screenshot({ path: CAP + 'cashless-passif-ordi.png', fullPage: true });
  await g.goto(B + '/gestion/cashless/' + carte.id);
  v((await g.textContent('main')).includes('Perdue'), 'fiche de la carte : bloquée, avec son motif');
  await g.screenshot({ path: CAP + 'cashless-fiche-ordi.png', fullPage: true });

  // ---------- 6. Vestiaire : Didier, caisse partagée (tablette) ----------
  const ctxV = await b.newContext({ viewport: { width: 1024, height: 768 } });
  const vs = await partager(ctxV, 'volcano-club', 'Didier Kasereka', '846295', err);
  v(vs.url().includes('/caisse/vestiaire'), 'vestiaire : la caisse s\'ouvre sur le vestiaire');
  const Dv = await donnees(vs);
  v(Dv.poste === 'vestiaire' && Dv.nuit.vestiaire.payant, 'dépôt payant (2 000 FC / 1 $)');
  r = await poster(vs, '/caisse/vestiaire/deposer', { description: 'Veste', cle: cle() });
  v(r.statut === 409, 'dépôt gratuit refusé tant qu\'il y a des frais (409)');
  await vs.locator('#grille .tuile').first().click(); await vs.waitForSelector('#nuit-fenetre[open]');
  await vs.fill('#depot-description', 'Veste noire + casque'); await vs.click('#nuit-corps button[type=submit]');
  await vs.click('#encaisser'); await vs.waitForSelector('#paiement[open]');
  const tv = await vs.evaluate(() => window.Caisse.paiement().totaux);
  v(proche(tv.CDF, 2000) && proche(tv.USD, 1), 'frais de vestiaire : 2 000 FC / 1 $');
  await vs.click('#paiement-valider'); await vs.waitForSelector('#paiement-fin:not([hidden])', { timeout: 8000 });
  const ticket = JSON.parse(sql("SELECT JSON_OBJECT('id', id, 'numero', numero, 'jeton', jeton, 'vente', vente_id) FROM vestiaire_tickets ORDER BY id DESC LIMIT 1"));
  const codeV = 'V' + String(ticket.id).padStart(5, '0') + '-' + ticket.jeton;
  v(ticket.vente !== null && await vs.locator(`#paiement-recu [data-qr="${codeV}"]`).count() === 1, `ticket n° ${ticket.numero} à code QR, lié à la vente`);
  await vs.screenshot({ path: CAP + 'vestiaire-ticket-tablette.png' });
  await vs.click('#paiement-suivant').catch(() => {});
  await vs.waitForTimeout(400);
  if (await vs.locator('.verrou__personne').count()) { await pinCaisse(vs, 'Didier Kasereka', '846295'); }
  // Retour : par le numéro, puis le même → déjà rendu ; un code falsifié → inconnu
  const restants = +sql("SELECT COUNT(*) FROM vestiaire_tickets WHERE etablissement_id=2 AND statut='depose'");
  await vs.click('#nuit-rendre'); await vs.waitForSelector('#nuit-fenetre[open]');
  await vs.fill('.scanner__champ', String(ticket.numero)); await vs.click('.scanner__valider'); await vs.waitForTimeout(600);
  v((await vs.textContent('.nuit-controle')).includes(String(ticket.numero)) && sql(`SELECT statut FROM vestiaire_tickets WHERE id=${ticket.id}`) === 'rendu', 'retour par le numéro : rendu');
  await vs.screenshot({ path: CAP + 'vestiaire-rendu-tablette.png' });
  await vs.fill('.scanner__champ', codeV); await vs.click('.scanner__valider'); await vs.waitForTimeout(600);
  v(await vs.locator('.nuit-controle--refus').count() === 1, 'même ticket par son code : « déjà rendu »');
  r = await poster(vs, '/caisse/vestiaire/rendre', { code: codeV.slice(0, -1) + 'Z' });
  v(r.statut === 404, 'code falsifié : inconnu (404)');
  await vs.keyboard.press('Escape');
  await vs.click('#nuit-restants'); await vs.waitForSelector('#nuit-fenetre[open]'); await vs.waitForTimeout(500);
  v(await vs.locator('#nuit-corps .nuit-restants li').count() === restants - 1, `objets restants : ${restants - 1}`);
  await vs.keyboard.press('Escape');
  // Sans frais : dépôt gratuit, ticket sans vente
  sql('DELETE FROM vestiaire_frais WHERE etablissement_id = 2');
  const cleD = cle();
  r = await poster(vs, '/caisse/vestiaire/deposer', { description: 'Sac', cle: cleD });
  const r2 = await poster(vs, '/caisse/vestiaire/deposer', { description: 'Sac', cle: cleD });
  v(r.statut === 200 && +sql(`SELECT COUNT(*) FROM vestiaire_tickets WHERE cle='${cleD}' AND vente_id IS NULL`) === 1 && r2.statut === 200, 'sans frais : dépôt gratuit, double envoi → un seul ticket');
  r = await encaisser(vs, { poste: 'caisse', lignes: [{ id: heineken.id, qte: 1 }], paiements: [{ devise: 'USD', compte: usd, recu: 5 }] });
  v(r.statut === 403, 'vestiaire : vente au bar refusée (403)');

  // ---------- 7. Fidélité au Comptoir du Lac ----------
  await p.goto(B + '/caisse?e=comptoir-du-lac'); await p.evaluate(() => sessionStorage.clear()); await p.reload(); await p.waitForTimeout(700);
  const Dl = await donnees(p);
  v(Dl.nuit && Dl.nuit.fidelite && Dl.nuit.fidelite.recompenses.length === 2, 'Comptoir du Lac : fidélité active, 2 récompenses');
  const client = JSON.parse(sql("SELECT JSON_OBJECT('id', client_id, 'pts', SUM(points)) FROM fidelite_mouvements GROUP BY client_id ORDER BY SUM(points) DESC LIMIT 1"));
  const mojito = Dl.produits.find(x => x.formats.some(f => f.id === 13));
  const fLac = caisseDe(1, 'CDF'), uLac = caisseDe(1, 'USD');
  const art = Dl.produits.find(x => x.nom === 'Primus' || x.nom.startsWith('Primus')) || Dl.produits[0];
  const fa = art.formats.find(f => typeof f.prix.USD === 'number');
  // Gain : 1 point par dollar
  r = await encaisser(p, { lignes: [{ id: fa.id, qte: 10 }], paiements: [{ devise: 'USD', compte: uLac, recu: fa.prix.USD * 10 }], fidelite: { client: client.id } });
  const gain = Math.floor(fa.prix.USD * 10);
  v(r.statut === 200 && +sql(`SELECT points FROM fidelite_mouvements WHERE vente_id=(SELECT id FROM ventes WHERE numero='${r.j.numero}')`) === gain, `points gagnés : ${gain} (1 par $) ` + (r.j.erreur || ''));
  v(r.j.recu.includes(String(client.pts + gain)) || /point/i.test(r.j.recu), 'reçu : les points du client');
  // Récompense 10 % (100 points)
  r = await encaisser(p, { lignes: [{ id: fa.id, qte: 4 }], paiements: [{ devise: 'USD', compte: uLac, recu: fa.prix.USD * 4 }], fidelite: { client: client.id, recompense: 1 } });
  const vf = sql(`SELECT id FROM ventes WHERE numero='${r.j.numero}'`);
  v(r.statut === 200 && proche(sql(`SELECT total FROM vente_totaux WHERE vente_id=${vf} AND devise_id=1`), fa.prix.USD * 4 * 0.9) && sql(`SELECT type FROM vente_remises WHERE vente_id=${vf}`) === 'fidelite', 'récompense 10 % : ticket remisé (remise « fidélité », sans PIN)');
  v(+sql(`SELECT SUM(points) FROM fidelite_mouvements WHERE vente_id=${vf} AND type='utilisation'`) === -100, '100 points utilisés');
  // Mojito offert (60 points) : la ligne Mojito à 0
  if (mojito) {
    r = await encaisser(p, { lignes: [{ id: 13, qte: 1 }, { id: fa.id, qte: 1 }], paiements: [{ devise: 'USD', compte: uLac, recu: fa.prix.USD }], fidelite: { client: client.id, recompense: 2 } });
    v(r.statut === 200 && sql(`SELECT offert FROM vente_lignes WHERE vente_id=(SELECT id FROM ventes WHERE numero='${r.j.numero}') AND conditionnement_id=13`) === '1', 'Mojito offert par la fidélité ' + (r.j.erreur || ''));
    r = await encaisser(p, { lignes: [{ id: fa.id, qte: 1 }], paiements: [{ devise: 'USD', compte: uLac, recu: fa.prix.USD }], fidelite: { client: client.id, recompense: 2 } });
    v(r.statut === 422 && r.j.code === 'fidelite', 'Mojito offert sans Mojito au ticket : refusé (422)');
  }
  const pauvre = sql("SELECT c.id FROM clients c LEFT JOIN fidelite_mouvements m ON m.client_id=c.id WHERE c.type <> 'personnel' AND c.actif=1 GROUP BY c.id HAVING COALESCE(SUM(m.points),0) < 100 LIMIT 1");
  r = await encaisser(p, { lignes: [{ id: fa.id, qte: 1 }], paiements: [{ devise: 'USD', compte: uLac, recu: fa.prix.USD }], fidelite: { client: +pauvre, recompense: 1 } });
  v(r.statut === 409 && r.j.code === 'points', 'points insuffisants : refusé (409)');
  // À l'écran : client choisi, récompense
  await p.reload(); await p.waitForTimeout(600);
  await ajouter(p, [art.nom + (art.formats.length > 1 ? '|' + (fa.taille || fa.nom) : '')]);
  await p.click('#encaisser'); await p.waitForSelector('#paiement[open]');
  await p.click('#paiement-fidelite'); await p.waitForSelector('#nuit-fenetre[open]');
  const nomClient = sql(`SELECT nom FROM clients WHERE id=${client.id}`);
  await p.fill('#fidelite-recherche', nomClient.slice(0, 5)); await p.waitForTimeout(700);
  await p.locator('#nuit-corps .ardoise-client', { hasText: nomClient }).first().click(); await p.waitForTimeout(300);
  await p.screenshot({ path: CAP + 'fidelite-recompense-ordi.png' });
  if (await p.locator('#nuit-corps [data-recompense="1"]').count()) { await p.click('#nuit-corps [data-recompense="1"]'); } else { await p.click('#fidelite-sans'); }
  await p.waitForTimeout(300);
  v((await p.textContent('#paiement-nuit')).includes(nomClient), 'fenêtre : le client fidèle et ses points');
  await p.click('#paiement-valider'); await p.waitForSelector('#paiement-fin:not([hidden])', { timeout: 8000 });
  await g.goto(B + '/gestion/clients/' + client.id);
  v(+(await g.textContent('[data-points]')) === +sql(`SELECT SUM(points) FROM fidelite_mouvements WHERE client_id=${client.id}`), 'fiche du client : solde de points à jour');
  await g.goto(B + '/gestion/fidelite');
  await g.screenshot({ path: CAP + 'gestion-fidelite-ordi.png', fullPage: true });

  v(err.length === 0, 'aucune erreur JavaScript ' + err.join(' | '));
  await b.close(); bilan();
})();
