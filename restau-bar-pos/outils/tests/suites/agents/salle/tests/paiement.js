// Payer une note : toutes les variantes de la fenêtre de paiement (FC, $, les deux, mobile money, monnaie, ardoise),
// la vente créée depuis les lignes de la note (prix recalculés), partage par articles et en parts égales,
// vente directe au comptoir avec un plat (part quand même en cuisine), conflit (note payée entre-temps).
const { chromium } = require('playwright');
const crypto = require('crypto');
const { B, MDP, S, sql, restaurer, v, bilan, connecter, ajouter } = require('./commun');

async function ouvrirTable(p, nom, zone) {
  await p.click('#salle'); await p.waitForSelector('#salle-fenetre[open]'); await p.waitForTimeout(300);
  if (zone) { await p.click(`#salle-zones .puce:has-text("${zone}")`); }
  await p.click(`.table-plan:has-text("${nom}")`); await p.waitForTimeout(900);
}
const derniereVente = () => sql('SELECT id FROM ventes ORDER BY id DESC LIMIT 1');
const noteDe = table => sql(`SELECT n.id FROM notes n JOIN salle_tables t ON t.id=n.table_id WHERE t.nom='${table}' AND t.etablissement_id=1 ORDER BY n.id DESC LIMIT 1`);

(async () => {
  restaurer();
  const b = await chromium.launch(); const err = [];
  const ctx = await b.newContext({ viewport: { width: 1366, height: 860 } });
  const p = await connecter(ctx, 'admin@demo.test', MDP, err);
  p.on('dialog', d => d.accept());
  await p.goto(B + '/caisse?e=comptoir-du-lac'); await p.evaluate(() => sessionStorage.clear()); await p.reload(); await p.waitForTimeout(600);

  // 1. T11 (addition demandée) : payer en FC exactement, la note se ferme, la caisse revient au comptoir
  await ouvrirTable(p, 'T11', 'Terrasse');
  const n11 = noteDe('T11');
  const totalNote = sql(`SELECT SUM(quantite*prix) FROM note_lignes WHERE note_id=${n11} AND annulee_le IS NULL`);
  await p.click('#encaisser'); await p.waitForSelector('#paiement[open]');
  v((await p.textContent('#paiement-titre')).includes('Table T11'), 'fenêtre de paiement : « Encaisser · Table T11 »');
  v((await p.textContent('#paiement-total')).replace(/\s/g, '') === Number(totalNote).toLocaleString('fr-FR').replace(/\s/g, '') + 'FC', 'total = reste de la note : ' + await p.textContent('#paiement-total'));
  await p.click('#paiement-valider'); await p.waitForSelector('#paiement-fin:not([hidden])');
  let id = derniereVente();
  v(sql(`SELECT statut FROM notes WHERE id=${n11}`) === 'payee', 'note T11 payée et fermée');
  v(sql(`SELECT total FROM ventes WHERE id=${id}`) === Number(totalNote).toFixed(3), 'vente = total de la note');
  v(sql(`SELECT GROUP_CONCAT(CONCAT(libelle,'×',quantite) ORDER BY id) FROM vente_lignes WHERE vente_id=${id}`) === sql(`SELECT GROUP_CONCAT(CONCAT(libelle,'×',quantite,'.000') ORDER BY id) FROM note_lignes WHERE note_id=${n11}`), 'lignes de la vente = lignes de la note');
  v(sql(`SELECT COUNT(*) FROM note_paiements WHERE note_id=${n11} AND vente_id=${id}`) === '3', 'chaque ligne de la note garde la vente qui l\'a payée');
  v((await p.textContent('#paiement-recu')).includes('Poulet braisé'), 'reçu de la vente');
  await p.click('#paiement-suivant'); await p.waitForTimeout(400);
  v(await p.locator('#destination').isHidden(), 'note payée : retour à la vente au comptoir');

  // 2. T3 : les deux devises (5 $ + le reste en FC sur M-Pesa), lignes de la note + une nouvelle (envoyée avant)
  await ouvrirTable(p, 'T3', 'Salle');
  const n3 = noteDe('T3');
  await ajouter(p, ['Coca-Cola']);
  await p.click('#encaisser'); await p.waitForTimeout(900); await p.waitForSelector('#paiement[open]');
  v(sql(`SELECT COUNT(*) FROM note_lignes WHERE note_id=${n3} AND libelle='Coca-Cola'`) === '1', 'Encaisser avec une nouvelle ligne : elle est d\'abord envoyée sur la note');
  await p.click('#paiement-devises button:has-text("Les deux")');
  await p.fill('#recu-USD', '5');
  await p.locator('#paiement-lignes .paiement-ligne:nth-child(2) .paiement-type', { hasText: 'Mobile money' }).locator('.puce').first().click();
  await p.click('#paiement-valider'); await p.waitForSelector('#paiement-fin:not([hidden])');
  id = derniereVente();
  v(sql(`SELECT GROUP_CONCAT(CONCAT(p.moyen,':',d.code) ORDER BY p.id) FROM vente_paiements p JOIN devises d ON d.id=p.devise_id WHERE vente_id=${id}`) === 'caisse:USD,mobile_money:CDF', 'note payée 5 $ en caisse + le reste en FC sur mobile money');
  v(sql(`SELECT statut FROM notes WHERE id=${n3}`) === 'payee', 'note T3 fermée');
  await p.click('#paiement-suivant');

  // 3. T8 : 50 $ pour la note, monnaie rendue en FC
  await ouvrirTable(p, 'T8', 'Terrasse');
  const n8 = noteDe('T8');
  await p.click('#encaisser'); await p.waitForSelector('#paiement[open]');
  await p.click('#paiement-devises button:text-is("$")');
  await p.locator('.paiement-rapide', { hasText: '$50' }).click();
  await p.locator('#paiement-rendu-devises button', { hasText: 'FC' }).click().catch(() => {});
  await p.click('#paiement-valider'); await p.waitForSelector('#paiement-fin:not([hidden])');
  id = derniereVente();
  v(Number(sql(`SELECT rendu FROM ventes WHERE id=${id}`)) > 0 && sql(`SELECT d.code FROM ventes v JOIN devises d ON d.id=v.rendu_devise_id WHERE v.id=${id}`) === 'CDF', 'note payée en $ avec monnaie rendue en FC : ' + sql(`SELECT rendu FROM ventes WHERE id=${id}`));
  v(sql(`SELECT statut FROM notes WHERE id=${n8}`) === 'payee', 'note T8 fermée');
  await p.click('#paiement-suivant');

  // 4. Ardoise sur une note : servi par proposé = le serveur de la note ; une partie payée
  await p.goto(B + '/caisse?e=volcano-club'); await p.waitForTimeout(800);
  const nVip = sql("SELECT n.id FROM notes n JOIN salle_tables t ON t.id=n.table_id WHERE t.nom='VIP 2' AND n.statut='ouverte'");
  await ouvrirTable(p, 'VIP 2', 'Carré VIP');
  v(new URL(p.url()).searchParams.get('zone') === 'vip' || (await p.locator('#zone').inputValue()) === 'vip', 'table du Carré VIP : la caisse passe dans la zone VIP (sa grille de prix)');
  await p.waitForTimeout(800);
  await p.click('#encaisser'); await p.waitForSelector('#paiement[open]');
  await p.fill('#recu-CDF', '100000');
  await p.click('#ajouter-ardoise');
  await p.fill('#ardoise-recherche', 'Moïse'); await p.locator('.ardoise-client', { hasText: 'Moïse Paluku' }).click(); await p.waitForTimeout(200);
  v(await p.locator('#ardoise-servi [data-personne="7"][aria-pressed="true"]').count() === 1, 'ardoise : « servi par » proposé = Christian, serveur de la note');
  await p.click('#paiement-valider'); await p.waitForTimeout(500);
  if (await p.locator('#ardoise-forcer').count() && !(await p.locator('#paiement-fin:not([hidden])').count())) { await p.check('#ardoise-forcer'); await p.click('#paiement-valider'); }
  await p.waitForSelector('#paiement-fin:not([hidden])');
  id = derniereVente();
  v(sql(`SELECT CONCAT(servi_par, ':', devise_id) FROM dettes WHERE vente_id=${id}`) === '7:2' && sql(`SELECT statut FROM notes WHERE id=${nVip}`) === 'payee', 'note du Carré VIP : 100 000 FC payés, le reste à l\'ardoise de Moïse, servi par Christian');
  await p.click('#paiement-suivant');

  // 5. Partage : par articles puis en parts égales (trois ventes depuis une note)
  await p.goto(B + '/caisse?e=comptoir-du-lac&zone='); await p.waitForTimeout(800);
  await ouvrirTable(p, 'T2', 'Salle');
  await ajouter(p, ['Primus|Grande', 'Primus|Grande', 'Primus|Grande', 'Tilapia grillé du lac', 'Kivu Sunset']);
  await p.click('#envoyer'); await p.waitForTimeout(900);
  const n2 = noteDe('T2');
  const total2 = Number(sql(`SELECT SUM(quantite*prix) FROM note_lignes WHERE note_id=${n2}`));
  await p.click('#partager'); await p.waitForSelector('#partage-fenetre[open]');
  const primus = sql(`SELECT id FROM note_lignes WHERE note_id=${n2} AND libelle LIKE 'Primus%'`);
  await p.click(`#partage-lignes .pas__bouton[data-ligne="${primus}"]`);
  await p.click(`#partage-lignes .pas__bouton[data-ligne="${primus}"]`);
  v((await p.textContent('#partage-bilan')).replace(/\s/g, '').includes('11000FC'), 'par articles : 2 Primus choisies = 11 000 FC');
  await p.click('#partage-payer'); await p.waitForSelector('#paiement[open]');
  v((await p.textContent('#paiement-total')).replace(/\s/g, '') === '11000FC', 'fenêtre de paiement de la part : 11 000 FC');
  await p.click('#paiement-valider'); await p.waitForSelector('#paiement-fin:not([hidden])');
  const v1 = derniereVente();
  v(sql(`SELECT GROUP_CONCAT(CONCAT(libelle,'×',quantite)) FROM vente_lignes WHERE vente_id=${v1}`) === 'Primus Grande 72 cl×2.000' && sql(`SELECT quantite_payee FROM note_lignes WHERE id=${primus}`) === '2.000', 'vente 1 : 2 Primus sur 3 ; la ligne garde 2 payées');
  await p.click('#paiement-suivant'); await p.waitForTimeout(900);
  v(!!(await p.locator('#partage-fenetre[open]').count()), 'la fenêtre de partage revient pour la part suivante');
  await p.click('#partage-modes button[data-mode="egal"]');
  await p.click('#partage-nombres button[data-parts="2"]');
  v((await p.textContent('#partage-bilan')).includes('Part 1 sur 2'), 'parts égales : « Part 1 sur 2 »');
  await p.click('#partage-payer'); await p.waitForSelector('#paiement[open]');
  await p.click('#paiement-valider'); await p.waitForSelector('#paiement-fin:not([hidden])');
  const v2 = derniereVente();
  v(sql(`SELECT GROUP_CONCAT(quantite ORDER BY id) FROM vente_lignes WHERE vente_id=${v2}`) === '0.500,0.500,0.500', 'vente 2 : la moitié de chaque ligne restante (quantités 0,5)');
  v((await p.textContent('#paiement-recu')).includes('1/2'), 'reçu : quantité « 1/2 »');
  await p.click('#paiement-suivant'); await p.waitForTimeout(900);
  v((await p.textContent('#partage-bilan')).includes('Part 2 sur 2'), 'part suivante : « Part 2 sur 2 »');
  await p.click('#partage-payer'); await p.waitForSelector('#paiement[open]');
  await p.click('#paiement-valider'); await p.waitForSelector('#paiement-fin:not([hidden])');
  const v3 = derniereVente();
  v(sql(`SELECT statut FROM notes WHERE id=${n2}`) === 'payee', 'dernière part : la note est fermée');
  const somme = Number(sql(`SELECT SUM(total) FROM ventes WHERE id IN (${v1},${v2},${v3})`));
  v(Math.abs(somme - total2) < 1, `trois ventes = total de la note (${somme} / ${total2})`);
  v(sql(`SELECT SUM(quantite) FROM vente_lignes WHERE vente_id IN (${v1},${v2},${v3}) AND libelle LIKE 'Tilapia%'`) === '1.000', 'le Tilapia est vendu en entier sur les parts (stock juste)');
  v(sql(`SELECT parts_payees FROM notes WHERE id=${n2}`) === '2', 'partage : 2 parts payées');
  await p.click('#paiement-suivant');

  // 6. Conflit : la note a été payée par une autre caisse entre-temps → 409, rien d'encaissé
  await ouvrirTable(p, 'T5', 'Salle');
  await ajouter(p, ['Mojito']); await p.click('#envoyer'); await p.waitForTimeout(900);
  const n5 = noteDe('T5');
  const csrf = await p.evaluate(() => JSON.parse(document.getElementById('donnees-caisse').textContent).csrf);
  const caisseFC = sql("SELECT c.id FROM comptes_tresorerie c JOIN compte_devises cd ON cd.compte_id=c.id JOIN devises d ON d.id=cd.devise_id WHERE c.type='caisse' AND c.etablissement_id=1 AND d.code='CDF' LIMIT 1");
  const payer = (cle) => p.request.post(B + '/caisse/encaisser', { form: { csrf, vente: JSON.stringify({ cle, etab: 'comptoir-du-lac', note: { id: +n5, mode: 'tout' }, lignes: [], paiements: [{ devise: 'CDF', compte: +caisseFC, recu: 100000 }] }) } });
  let r = await payer(crypto.randomUUID());
  v(r.status() === 200, 'note payée par une requête directe (autre caisse)');
  const avant = sql('SELECT COUNT(*) FROM ventes');
  r = await payer(crypto.randomUUID());
  v(r.status() === 409 && sql('SELECT COUNT(*) FROM ventes') === avant, 'deuxième paiement de la même note : 409, aucune vente de plus');
  r = await p.request.post(B + '/caisse/encaisser', { form: { csrf, vente: JSON.stringify({ cle: crypto.randomUUID(), etab: 'comptoir-du-lac', note: { id: +noteDe('T3'), mode: 'lignes', lignes: [{ id: 999999, qte: 1 }] }, lignes: [], paiements: [{ devise: 'CDF', compte: +caisseFC, recu: 1000 }] }) } });
  v(r.status() === 409 || r.status() === 422, 'part illisible ou note fermée : refusée');
  await p.reload(); await p.waitForTimeout(1500);
  v(await p.locator('#destination').isHidden(), 'caisse : la note payée ailleurs est quittée au rechargement');

  // 7. Vente directe au comptoir avec un plat : encaissée tout de suite, le plat part au Grill
  await ajouter(p, ['Poulet braisé', 'Primus|Grande']);
  await p.locator('#lignes .ligne', { hasText: 'Poulet' }).locator('.ligne__texte').click(); await p.waitForSelector('#ligne-fenetre[open]');
  await p.click('#ligne-remarques .puce:has-text("À emporter")'); await p.click('#ligne-valider');
  await p.click('#encaisser'); await p.waitForSelector('#paiement[open]'); await p.click('#paiement-valider'); await p.waitForSelector('#paiement-fin:not([hidden])');
  id = derniereVente();
  const comptoir = sql(`SELECT id FROM notes WHERE vente_id=${id} AND type='comptoir'`);
  v(!!comptoir && sql(`SELECT GROUP_CONCAT(CONCAT(libelle,':',statut,':',COALESCE(remarque,''))) FROM note_lignes WHERE note_id=${comptoir}`) === 'Poulet braisé:recu:À emporter', 'vente directe : le Poulet (à emporter) part au Grill, la bière non');
  v(sql(`SELECT COUNT(*) FROM vente_lignes WHERE vente_id=${id}`) === '2', 'la vente directe reste une vente normale (2 lignes)');
  await p.click('#paiement-suivant');
  const cx = await b.newContext({ viewport: { width: 1366, height: 860 } });
  const c = await connecter(cx, 'admin@demo.test', MDP, err);
  await c.goto(B + '/caisse/cuisine?etab=comptoir-du-lac'); await c.waitForTimeout(1200);
  v(await c.locator('.bon-carte', { hasText: 'Comptoir CDL-' }).count() === 1, 'écran de cuisine : bon « Comptoir CDL-… » de la vente directe');

  v(err.length === 0, 'aucune erreur JS ' + err.join(' | '));
  bilan(); await b.close();
})();
