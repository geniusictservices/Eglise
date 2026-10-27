// Notes ouvertes : plan de salle, ouvrir une table, précisions et allergie, envoi en cuisine, plusieurs serveurs,
// addition, écran de cuisine (statuts, à suivre, prêt → serveur prévenu), lancer la suite, rupture, annulation
// avec le PIN d'un supérieur (perte), transfert, fusion.
const { chromium } = require('playwright');
const { B, MDP, S, sql, restaurer, v, bilan, connecter, suivre, ajouter, partager, pinCaisse, attendre } = require('./commun');

(async () => {
  restaurer();
  const b = await chromium.launch(); const err = [];
  const ctx = await b.newContext({ viewport: { width: 1366, height: 860 } });
  const p = await connecter(ctx, 'admin@demo.test', MDP, err);
  p.on('dialog', d => d.accept());
  await p.goto(B + '/caisse?e=comptoir-du-lac'); await p.evaluate(() => sessionStorage.clear()); await p.reload(); await p.waitForTimeout(600);

  // 1. Plan de salle
  await p.click('#salle'); await p.waitForSelector('#salle-fenetre[open]'); await p.waitForTimeout(400);
  v(await p.locator('#plan .table-plan').count() === 7, 'plan : 7 tables dans la zone Salle');
  v(await p.locator('.table-plan[data-statut="occupee"]', { hasText: 'T3' }).count() === 1 && /Occupée/.test(await p.textContent('.table-plan:has-text("T3")')), 'T3 occupée, statut écrit');
  v(/\d+ min|\d+ h \d+/.test(await p.textContent('.table-plan:has-text("T3")')) && /167\s800/.test(await p.textContent('.table-plan:has-text("T3")')), 'T3 : montant et durée de la note');
  await p.click('#salle-zones .puce:has-text("Terrasse")');
  v(/Addition demandée/.test(await p.textContent('.table-plan:has-text("T11")')), 'terrasse : T11 « Addition demandée »');
  v((await p.textContent('#salle-notes')).includes('Bar — Patrick'), 'notes sans table : « Bar — Patrick »');
  await p.screenshot({ path: S + 'caisse-plan-ordi.png' });

  // 2. Ouvrir T1, précisions, allergie, à lancer plus tard, envoyer
  await p.click('#salle-zones .puce:has-text("Salle")');
  await p.click('.table-plan:has-text("T1")'); await p.waitForTimeout(300);
  v((await p.textContent('#destination')).includes('Table T1') && !(await p.locator('#salle-fenetre[open]').count()), 'T1 choisie : destination « Table T1 »');
  await ajouter(p, ['Primus|Grande', 'Primus|Grande', 'Tilapia grillé du lac', 'Poulet braisé']);
  v(await p.locator('#envoyer').isVisible() && (await p.textContent('#envoyer')).trim() === 'Envoyer', 'bouton « Envoyer » avec de nouvelles lignes');
  await p.locator('#lignes .ligne', { hasText: 'Tilapia' }).locator('.ligne__texte').click();
  await p.waitForSelector('#ligne-fenetre[open]');
  await p.click('#ligne-remarques .puce:has-text("Bien cuit")');
  await p.fill('#ligne-allergie', 'arachides');
  await p.click('#ligne-valider'); await p.waitForTimeout(200);
  v((await p.textContent('#lignes')).includes('Bien cuit') && /Allergie : arachides/i.test(await p.textContent('#lignes')), 'précision et allergie sur la ligne');
  await p.locator('#lignes .ligne', { hasText: 'Poulet' }).locator('.ligne__texte').click();
  await p.waitForSelector('#ligne-fenetre[open]'); await p.check('#ligne-temps'); await p.click('#ligne-valider'); await p.waitForTimeout(200);
  v((await p.textContent('#lignes')).includes('À lancer'), 'Poulet « à lancer » plus tard');
  await ajouter(p, ['Tilapia grillé du lac']);
  v(await p.locator('#lignes .ligne').count() === 4, 'un Tilapia de plus sans précision : ligne à part');
  await p.locator('#lignes .ligne', { hasText: 'Tilapia' }).nth(1).locator('.pas__bouton').first().click();
  await p.click('#envoyer'); await p.waitForTimeout(900);
  const n1 = sql("SELECT id FROM notes WHERE table_id = (SELECT id FROM salle_tables WHERE nom='T1') AND statut='ouverte'");
  v(!!n1, 'note créée sur T1 au premier envoi');
  const lignes = sql(`SELECT GROUP_CONCAT(CONCAT(libelle,':',quantite,':',statut,':',COALESCE(remarque,''),':',COALESCE(allergie,''),':',COALESCE((SELECT nom FROM postes_cuisine WHERE id=poste_id),'-')) ORDER BY id SEPARATOR '|') FROM note_lignes WHERE note_id=${n1}`);
  v(lignes === 'Primus Grande 72 cl:2:sans:::-|Tilapia grillé du lac:1:recu:Bien cuit:arachides:Grill|Poulet braisé:1:attente:::Grill', 'lignes : bière sur la note, Tilapia reçu au Grill (précision, allergie), Poulet à lancer : ' + lignes);
  v(await p.locator('#lignes .ligne').count() === 0 && await p.locator('#note-lignes .note-ligne').count() === 3, 'après l\'envoi : ticket local vide, 3 lignes envoyées affichées');
  v(sql(`SELECT GROUP_CONCAT(DISTINCT ajoute_par) FROM note_lignes WHERE note_id=${n1}`) === '1' && sql(`SELECT serveur_id FROM notes WHERE id=${n1}`) === '1', 'qui a commandé : l\'administrateur, serveur de la note');
  v((await p.textContent('#total')).replace(/\s/g, '') === '88000FC', 'total du panneau = reste de la note (2×5 500 + 42 800 + 34 200) : ' + await p.textContent('#total'));
  await p.screenshot({ path: S + 'caisse-note-ordi.png' });

  // 3. Deuxième serveuse sur une caisse partagée : elle ajoute à la même note, ne peut pas encaisser, demande l'addition
  const ctx2 = await b.newContext({ viewport: { width: 1280, height: 800 }, hasTouch: true });
  const a = await partager(ctx2, 'comptoir-du-lac', 'Aline Mukeba', '271828', err);
  a.on('dialog', d => d.accept());
  await a.click('#salle'); await a.waitForSelector('#salle-fenetre[open]'); await a.waitForTimeout(500);
  await a.click('.table-plan:has-text("T1")'); await a.waitForTimeout(700);
  v(await a.locator('#note-lignes .note-ligne').count() === 3, 'Aline ouvre T1 : elle voit les 3 lignes');
  await ajouter(a, ['Coca-Cola']);
  await a.click('#envoyer'); await a.waitForTimeout(800);
  v(sql(`SELECT u.nom FROM note_lignes l JOIN utilisateurs u ON u.id=l.ajoute_par WHERE l.note_id=${n1} ORDER BY l.id DESC LIMIT 1`) === 'Aline Mukeba', 'ligne ajoutée par Aline dans la même note');
  await a.waitForTimeout(1500);
  v(await a.locator('.verrou__personnes').count() === 1, 'caisse partagée : verrouillée après l\'envoi (réglage de l\'établissement)');
  await pinCaisse(a, 'Aline Mukeba', '271828');
  v(await a.locator('#encaisser').isDisabled(), 'Aline (sans « encaisser ») : Encaisser inactif sur la note');
  v(await a.locator('#addition').count() === 1, 'Aline : bouton Addition');
  await a.evaluate(() => { window.print = () => {}; });
  await a.click('#addition'); await a.waitForTimeout(800);
  v(sql(`SELECT addition_par FROM notes WHERE id=${n1}`) === '4' && (await a.innerHTML('#zone-impression')).includes('recu--addition'), 'addition demandée par Aline, pré-note prête à imprimer');
  v((await a.textContent('#zone-impression')).replace(/\s/g, '').includes('92300'), 'pré-note : 92 300 FC (avec le Coca)');
  // Le plan du caissier montre l'addition demandée
  await p.click('#salle'); await p.waitForTimeout(1500);
  v(/Addition demandée/.test(await p.textContent('.table-plan:has-text("T1")')), 'plan : T1 « Addition demandée » (vu d\'une autre caisse)');
  await p.click('[data-fermer-salle]');

  // 4. Écran de cuisine : bon du Grill, allergie, à suivre ; Commencer, Prêt → serveur prévenu → Servi
  const ctx3 = await b.newContext({ viewport: { width: 1366, height: 860 } });
  const c = await connecter(ctx3, 'admin@demo.test', MDP, err);
  const grill = sql("SELECT id FROM postes_cuisine WHERE nom='Grill' AND etablissement_id=1");
  await c.goto(B + '/caisse/cuisine?etab=comptoir-du-lac&poste=' + grill); await c.waitForTimeout(1200);
  const carte = c.locator('.bon-carte', { hasText: 'Table T1' });
  v(await carte.count() === 1, 'écran du Grill : un bon « Table T1 »');
  v(/Allergie : arachides/i.test(await carte.textContent()) && (await carte.textContent()).includes('Bien cuit'), 'bon : précision et ALLERGIE en évidence');
  v(/À suivre/.test(await carte.textContent()) && (await carte.textContent()).includes('Poulet braisé'), 'bon : Poulet « à suivre »');
  v(await c.locator('.bon-carte', { hasText: 'Coca' }).count() === 0, 'le Coca (sans poste) ne part pas en cuisine');
  await c.screenshot({ path: S + 'cuisine-ordi.png' });
  await carte.locator('.bon-carte__avancer').click(); await c.waitForTimeout(700);
  v(sql(`SELECT statut FROM note_lignes WHERE note_id=${n1} AND libelle LIKE 'Tilapia%'`) === 'preparation', 'Commencer → en préparation');
  await c.locator('.bon-carte', { hasText: 'Table T1' }).locator('.bon-carte__avancer').click(); await c.waitForTimeout(700);
  v(sql(`SELECT CONCAT(statut, ':', pret_le IS NOT NULL) FROM note_lignes WHERE note_id=${n1} AND libelle LIKE 'Tilapia%'`) === 'pret:1', 'Prêt → prêt, heure gardée');
  await p.waitForTimeout(9000);
  v(!(await p.locator('#prets-bandeau').isHidden()) && (await p.textContent('#prets-bandeau')).includes('Tilapia'), 'caisse du serveur : bandeau « Table T1 · prêt : Tilapia »');
  v((await p.textContent('#salle-prets')).trim() !== '', 'pastille des plats prêts sur le bouton Salle');
  await p.click('#prets-servi'); await p.waitForTimeout(700);
  v(sql(`SELECT statut FROM note_lignes WHERE note_id=${n1} AND libelle LIKE 'Tilapia%'`) === 'servi', 'Servi depuis la caisse');

  // 5. Lancer la suite
  await p.waitForTimeout(500);
  v((await p.textContent('#lancer')).includes('(1)'), 'bouton « Lancer la suite (1) »');
  await p.click('#lancer'); await p.waitForTimeout(700);
  v(sql(`SELECT CONCAT(statut, ':', envoi_id IS NOT NULL) FROM note_lignes WHERE note_id=${n1} AND libelle LIKE 'Poulet%'`) === 'recu:1', 'Poulet lancé : reçu au Grill, nouveau bon');
  await c.waitForTimeout(5500);
  v(await c.locator('.bon-carte', { hasText: 'Poulet braisé' }).count() >= 1, 'écran du Grill : le bon du Poulet arrive');

  // 6. Rupture : le chef bloque les Brochettes → grisées à la caisse sans recharger
  await c.click('#cuisine-ruptures'); await c.waitForSelector('#fenetre-ruptures[open]');
  await c.locator('.cuisine-rupture', { hasText: 'Brochettes' }).locator('button').click(); await c.waitForTimeout(500);
  v(sql("SELECT disponible FROM produits WHERE nom_fr LIKE 'Brochettes%'") === '0', 'rupture enregistrée');
  await p.waitForTimeout(9000);
  const tuile = p.locator('#grille .tuile', { has: p.locator('.tuile__nom:text-is("Brochettes de chèvre")') });
  v(await tuile.isDisabled() && (await tuile.textContent()).includes('Épuisé'), 'caisse : Brochettes grisées et impossibles à toucher, sans recharger');
  const r = await p.request.post(B + '/caisse/notes/envoyer', { form: { csrf: await p.evaluate(() => JSON.parse(document.getElementById('donnees-caisse').textContent).csrf), etab: 'comptoir-du-lac',
    envoi: JSON.stringify({ note: +n1, lignes: [{ id: +sql("SELECT c.id FROM conditionnements c JOIN produits p ON p.id=c.produit_id WHERE p.nom_fr LIKE 'Brochettes%'"), qte: 1 }] }) } });
  v(r.status() === 422, 'serveur : un plat en rupture est refusé à l\'envoi (422)');
  await c.locator('.cuisine-rupture', { hasText: 'Brochettes' }).locator('button').click(); await c.waitForTimeout(400);
  await c.keyboard.press('Escape');

  // 7. Annulation d'une ligne lancée : Aline demande, Patrick valide avec son PIN ; perte dans le stock
  await a.reload(); await a.waitForTimeout(1200);
  if (await a.locator('.verrou__personnes').count()) { await pinCaisse(a, 'Aline Mukeba', '271828'); }
  const pertesAvant = Number(sql('SELECT COUNT(*) FROM pertes_stock'));
  await a.locator('#note-lignes .note-ligne', { hasText: 'Poulet' }).locator('.note-ligne__annuler').click();
  await a.waitForSelector('#annuler-fenetre[open]');
  v(!(await a.locator('#annuler-validation').isHidden()), 'ligne lancée : validation par un supérieur demandée');
  await a.click('#annuler-valider');
  v((await a.textContent('#annuler-erreur')).length > 0, 'sans valideur ni PIN : message');
  await a.click('#annuler-motifs .puce:has-text("Plat renvoyé")');
  await a.click('#annuler-valideurs .puce:has-text("Patrick")');
  await a.fill('#annuler-pin', '111222'); await a.click('#annuler-valider'); await a.waitForTimeout(600);
  v(/incorrect|essai/i.test(await a.textContent('#annuler-erreur')) && sql(`SELECT annulee_le IS NULL FROM note_lignes WHERE note_id=${n1} AND libelle LIKE 'Poulet%'`) === '1', 'PIN faux : refusé, ligne intacte : ' + await a.textContent('#annuler-erreur'));
  await a.click('#annuler-valideurs .puce:has-text("Patrick")');
  await a.fill('#annuler-pin', '482913'); await a.click('#annuler-valider'); await a.waitForTimeout(800);
  const ann = sql(`SELECT CONCAT(annulee_par, ':', annulation_validee_par, ':', perte, ':', annulation_motif) FROM note_lignes WHERE note_id=${n1} AND libelle LIKE 'Poulet%'`);
  v(ann === '4:2:1:Plat renvoyé', 'annulée : demandée par Aline, validée par Patrick, comptée en perte : ' + ann);
  const journal = sql("SELECT details FROM journal WHERE action='note_ligne_annulee' ORDER BY id DESC LIMIT 1");
  v(journal.includes('Aline Mukeba') && journal.includes('Patrick Kambale'), 'journal : les deux noms');
  v(Number(sql('SELECT COUNT(*) FROM pertes_stock')) > pertesAvant, 'module stock présent : perte enregistrée (' + sql("SELECT GROUP_CONCAT(commentaire) FROM pertes_stock ORDER BY id DESC LIMIT 1") + ')');
  v(sql("SELECT pin_essais FROM utilisateurs WHERE id=2") === '0', 'PIN juste : compteur d\'essais remis à zéro');
  v((await a.textContent('#note-lignes')).includes('Annulée'), 'la ligne annulée reste visible, barrée');
  // Ligne pas encore lancée : sans validation
  await ajouter(a, ['Poulet braisé']);
  await a.locator('#lignes .ligne').first().locator('.ligne__texte').click(); await a.waitForSelector('#ligne-fenetre[open]'); await a.check('#ligne-temps'); await a.click('#ligne-valider');
  await a.click('#envoyer'); await a.waitForTimeout(1800);
  if (await a.locator('.verrou__personnes').count()) { await pinCaisse(a, 'Aline Mukeba', '271828'); }
  await a.locator('#note-lignes .note-ligne', { hasText: 'Poulet' }).filter({ hasText: 'À lancer' }).locator('.note-ligne__annuler').click();
  await a.waitForSelector('#annuler-fenetre[open]');
  v(await a.locator('#annuler-validation').isHidden(), 'plat « à lancer » pas encore parti : annulation sans validation');
  await a.click('#annuler-valider'); await a.waitForTimeout(700);
  v(sql(`SELECT COUNT(*) FROM note_lignes WHERE note_id=${n1} AND libelle LIKE 'Poulet%' AND annulee_le IS NOT NULL AND perte = 0`) === '1', 'annulée sans perte');

  // 8. Transférer T1 → T4, fusionner « Bar — Patrick » dans T4
  await p.reload(); await p.waitForTimeout(1200);
  await p.click('#note-transferer'); await p.waitForSelector('#salle-fenetre[open]'); await p.waitForTimeout(500);
  v((await p.textContent('#salle-aide')).includes('transférer'), 'transfert : consigne affichée');
  await p.click('.table-plan:has-text("T4")'); await p.waitForTimeout(800);
  v(sql(`SELECT t.nom FROM notes n JOIN salle_tables t ON t.id=n.table_id WHERE n.id=${n1}`) === 'T4' && (await p.textContent('#destination')).includes('T4'), 'note transférée sur T4');
  v(sql("SELECT COUNT(*) FROM journal WHERE action='note_transferee'") === '1', 'transfert au journal');
  // Transférer sur une table occupée : proposer la fusion (T3)
  const patrick = sql("SELECT id FROM notes WHERE nom='Bar — Patrick' AND statut='ouverte'");
  await p.click('#salle'); await p.waitForSelector('#salle-fenetre[open]'); await p.waitForTimeout(400);
  await p.click(`.salle-note[data-note="${patrick}"]`); await p.waitForTimeout(800);
  v((await p.textContent('#destination')).includes('Bar — Patrick'), 'note nommée « Bar — Patrick » ouverte');
  await p.click('#note-fusionner'); await p.waitForSelector('#salle-fenetre[open]'); await p.waitForTimeout(400);
  await p.click('.table-plan:has-text("T4")'); await p.waitForTimeout(900);
  v(sql(`SELECT CONCAT(statut, ':', fusionnee_dans) FROM notes WHERE id=${patrick}`) === `fusionnee:${n1}` && sql(`SELECT COUNT(*) FROM note_lignes WHERE note_id=${n1} AND libelle LIKE 'Primus Petite%'`) === '1', 'fusion : les lignes de « Bar — Patrick » passent sur T4');
  v((await p.textContent('#destination')).includes('T4'), 'après la fusion, la caisse est sur la note T4');

  // 9. Nouvelle note nommée depuis la caisse (sans table), directe vers la note
  await p.click('#salle'); await p.waitForSelector('#salle-fenetre[open]');
  await p.fill('#salle-nom', 'Comptoir — Moïse'); await p.click('#salle-nouvelle-ok'); await p.waitForTimeout(300);
  await ajouter(p, ['Mojito']); await p.click('#envoyer'); await p.waitForTimeout(900);
  v(sql("SELECT COUNT(*) FROM notes WHERE nom='Comptoir — Moïse' AND statut='ouverte' AND table_id IS NULL") === '1', 'note nommée créée au premier envoi');
  // Envoyer un ticket du comptoir à une table libre (« Envoyer à une table »)
  await p.click('#note-quitter'); await p.waitForTimeout(200);
  await ajouter(p, ['Heineken']);
  v((await p.textContent('#envoyer')).includes('Envoyer à une table'), 'vente au comptoir : « Envoyer à une table »');
  await p.click('#envoyer'); await p.waitForSelector('#salle-fenetre[open]'); await p.waitForTimeout(400);
  await p.click('.table-plan:has-text("T6")'); await p.waitForTimeout(1000);
  v(sql("SELECT COUNT(*) FROM notes n JOIN salle_tables t ON t.id=n.table_id WHERE t.nom='T6' AND n.statut='ouverte'") === '1', 'ticket envoyé sur T6 (note créée)');
  // Double envoi (même clé) : rien de plus
  const cleEnvoi = '11111111-2222-4333-8444-555555555555';
  const csrf = await p.evaluate(() => JSON.parse(document.getElementById('donnees-caisse').textContent).csrf);
  const corps = { csrf, etab: 'comptoir-du-lac', envoi: JSON.stringify({ cle: cleEnvoi, table: +sql("SELECT id FROM salle_tables WHERE nom='T12'"), lignes: [{ id: +sql("SELECT c.id FROM conditionnements c JOIN produits p ON p.id=c.produit_id WHERE p.nom_fr='Coca-Cola' LIMIT 1"), qte: 1 }] }) };
  await p.request.post(B + '/caisse/notes/envoyer', { form: corps }); await p.request.post(B + '/caisse/notes/envoyer', { form: corps });
  v(sql("SELECT COUNT(*) FROM note_lignes l JOIN notes n ON n.id=l.note_id JOIN salle_tables t ON t.id=n.table_id WHERE t.nom='T12'") === '1', 'double envoi (même clé) : une seule ligne');
  const mauvais = await p.request.post(B + '/caisse/notes/envoyer', { form: { ...corps, csrf: 'faux' } });
  v(mauvais.status() === 419, 'envoi sans jeton : 419');

  v(err.length === 0, 'aucune erreur JS ' + err.join(' | '));
  bilan(); await b.close();
})();
