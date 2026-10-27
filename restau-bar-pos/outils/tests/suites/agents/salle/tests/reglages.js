// Services horaires (catégories, mode rapide), happy hour (prix du moment, gardés sur la note), journée
// d'exploitation, réservations (table « réservée », arrivée), écrans de gestion (plan, glisser-déposer),
// écran de cuisine autorisé, droits et jetons, français et anglais.
const { chromium } = require('playwright');
const crypto = require('crypto');
const { execSync } = require('child_process');
const { B, MDP, S, sql, restaurer, v, bilan, connecter, ajouter } = require('./commun');
const RACINE = '/home/user/Eglise/restau-bar-pos';
const php = code => execSync(`php -r ${JSON.stringify("require '" + RACINE + "/app/bootstrap.php'; App\\Core\\Instance::charger('integ'); date_default_timezone_set('Africa/Lubumbashi'); " + code)}`).toString().trim();

(async () => {
  restaurer();
  const b = await chromium.launch(); const err = [];

  // 1. Journée d'exploitation (helper des rapports)
  v(php("echo App\\Core\\Journee::du('2026-10-31 02:00:00', ['fin_journee' => '07:00:00']);") === '2026-10-30', 'samedi 2 h avec fin à 7 h : journée du vendredi');
  v(php("echo App\\Core\\Journee::du('2026-10-31 07:00:00', ['fin_journee' => '07:00:00']);") === '2026-10-31', 'samedi 7 h : journée du samedi');
  v(php("echo implode(' → ', App\\Core\\Journee::bornes('2026-10-30', ['fin_journee' => '07:00:00']));") === '2026-10-30 07:00:00 → 2026-10-31 07:00:00', 'bornes de la journée du vendredi : 7 h → 7 h le lendemain');
  v(php("echo App\\Core\\Journee::sql('v.cree_le');") === 'DATE(SUBTIME(v.cree_le, e.fin_journee))', 'expression SQL de regroupement par journée');
  const parJour = php("echo json_encode(App\\Core\\Database::tous(\"SELECT \" . App\\Core\\Journee::sql('v.cree_le') . \" AS jour, COUNT(*) AS n FROM ventes v JOIN etablissements e ON e.id = v.etablissement_id WHERE v.etablissement_id = 2 GROUP BY jour ORDER BY jour DESC LIMIT 3\"));");
  v(JSON.parse(parJour).length > 0, 'ventes du club regroupées par journée d\'exploitation : ' + parJour);
  v(php("echo App\\Repositories\\ServiceRepository::debutEnCours(5, '22:00:00', '05:00:00', new DateTimeImmutable('2026-10-31 02:00:00'))?->format('Y-m-d H:i');") === '2026-10-30 22:00', 'service du vendredi 22 h – 5 h : en cours samedi à 2 h, commencé vendredi');
  v(php("var_dump(App\\Repositories\\ServiceRepository::debutEnCours(5, '22:00:00', '05:00:00', new DateTimeImmutable('2026-10-31 06:00:00')));") === 'NULL', '… terminé samedi à 6 h');

  // 2. Service en cours qui ne vend pas les plats (cuisine fermée) et en mode rapide
  const n = php("echo date('N');");
  // Seul service du jour : sinon, selon l'heure du test, « Restaurant » (11 h – 15 h) ou « Bar & terrasse » (17 h – minuit) passe avant
  sql(`DELETE FROM horaires WHERE etablissement_id=1 AND jour=${n}`);
  sql(`INSERT INTO horaires (etablissement_id, service_fr, service_en, jour, ouverture, fermeture) VALUES (1, 'Essai nuit', 'Night test', ${n}, '00:00:00', '23:59:00')`);
  const tous = sql("SELECT GROUP_CONCAT(id) FROM categories WHERE parent_id IS NULL AND nom_fr <> 'Plats'");
  sql("INSERT INTO services_horaires (etablissement_id, nom_fr, tables, cuisine, partage, rapide) VALUES (1, 'Essai nuit', 1, 1, 1, 1)");
  tous.split(',').forEach(c => sql(`INSERT INTO service_categories (service_id, categorie_id) SELECT id, ${c} FROM services_horaires WHERE nom_fr='Essai nuit'`));
  const ctx = await b.newContext({ viewport: { width: 1366, height: 860 } });
  const p = await connecter(ctx, 'admin@demo.test', MDP, err);
  p.on('dialog', d => d.accept());
  await p.goto(B + '/caisse?e=comptoir-du-lac'); await p.evaluate(() => sessionStorage.clear()); await p.reload(); await p.waitForTimeout(700);
  v((await p.textContent('#service')).includes('Essai nuit'), 'barre du haut : service en cours « Essai nuit »');
  v(await p.locator('#onglets .onglet:has-text("Plats")').count() === 0 && await p.locator('.tuile__nom:text-is("Tilapia grillé du lac")').count() === 0, 'cuisine fermée : les plats disparaissent de la caisse');
  v(await p.locator('#caisse[data-mode="rapide"]').count() === 1, 'service en mode comptoir rapide');
  const csrf = () => p.evaluate(() => JSON.parse(document.getElementById('donnees-caisse').textContent).csrf);
  const caisseFC = sql("SELECT c.id FROM comptes_tresorerie c JOIN compte_devises cd ON cd.compte_id=c.id JOIN devises d ON d.id=cd.devise_id WHERE c.type='caisse' AND c.etablissement_id=1 AND d.code='CDF' LIMIT 1");
  const tilapia = sql("SELECT c.id FROM conditionnements c JOIN produits p ON p.id=c.produit_id WHERE p.nom_fr LIKE 'Tilapia%'");
  let r = await p.request.post(B + '/caisse/encaisser', { form: { csrf: await csrf(), vente: JSON.stringify({ cle: crypto.randomUUID(), etab: 'comptoir-du-lac', lignes: [{ id: +tilapia, qte: 1 }], paiements: [{ devise: 'CDF', compte: +caisseFC, recu: 50000 }] }) } });
  v(r.status() === 422, 'serveur : un plat d\'une catégorie fermée est refusé à la vente directe');
  const t3 = sql("SELECT n.id FROM notes n JOIN salle_tables t ON t.id=n.table_id WHERE t.nom='T3' AND n.statut='ouverte'");
  r = await p.request.post(B + '/caisse/encaisser', { form: { csrf: await csrf(), vente: JSON.stringify({ cle: crypto.randomUUID(), etab: 'comptoir-du-lac', note: { id: +t3, mode: 'tout' }, lignes: [], paiements: [{ devise: 'CDF', compte: +caisseFC, recu: 200000 }] }) } });
  v(r.status() === 200 && sql(`SELECT statut FROM notes WHERE id=${t3}`) === 'payee', 'une note avec des plats commandés avant se paie quand même');
  sql("DELETE FROM services_horaires WHERE nom_fr='Essai nuit'"); sql("DELETE FROM horaires WHERE service_fr='Essai nuit'");

  // 3. Happy hour : prix du moment à la caisse, gardés sur la note après la fin du happy hour
  await p.reload(); await p.waitForTimeout(600);
  const prixPrimus = () => p.evaluate(() => { const D = JSON.parse(document.getElementById('donnees-caisse').textContent); for (const pr of D.produits) for (const f of pr.formats) if (pr.nom === 'Primus' && f.taille.startsWith('Grande') && f.nom === 'Bouteille') return f.prix.CDF; });
  const normal = await prixPrimus();
  sql("INSERT INTO happy_hours (etablissement_id, nom_fr, nom_en, jours, debut, fin, grille_prix_id) SELECT 1, 'Happy essai', 'Happy test', '1,2,3,4,5,6,7', '00:00:00', '23:59:00', id FROM grilles_prix WHERE code='happy'");
  await p.reload(); await p.waitForTimeout(600);
  const happy = await prixPrimus();
  v(normal === 5500 && happy < normal, `happy hour : Primus Grande ${normal} FC → ${happy} FC`);
  v((await p.textContent('#service')).includes('Happy essai'), 'barre du haut : « Happy essai jusqu\'à 23:59 »');
  await p.click('#salle'); await p.waitForSelector('#salle-fenetre[open]'); await p.click('.table-plan:has-text("T1")'); await p.waitForTimeout(400);
  await ajouter(p, ['Primus|Grande']); await p.click('#envoyer'); await p.waitForTimeout(900);
  const nT1 = sql("SELECT n.id FROM notes n JOIN salle_tables t ON t.id=n.table_id WHERE t.nom='T1' AND n.statut='ouverte'");
  v(sql(`SELECT CONCAT(g.code, ':', l.prix) FROM note_lignes l JOIN grilles_prix g ON g.id=l.grille_prix_id WHERE l.note_id=${nT1}`) === `happy:${happy}.000`, 'ligne de la note : grille happy hour gardée');
  await p.evaluate(() => { window.__marque = 1; });
  sql("DELETE FROM happy_hours WHERE nom_fr='Happy essai'");
  await p.evaluate(() => window.Caisse.viderLocal());
  await p.waitForTimeout(10000);
  v(await p.evaluate(() => window.__marque === undefined), 'fin du happy hour : la caisse se recharge seule (ticket vide)');
  v(await prixPrimus() === normal, 'après le happy hour : prix normal à la caisse');
  await p.waitForTimeout(800);
  v((await p.textContent('#total')).replace(/\s/g, '') === `${happy}FC`.replace(/\B(?=(\d{3})+(?!\d))/g, ''), 'la note garde le prix du happy hour : ' + await p.textContent('#total'));
  r = await p.request.post(B + '/caisse/encaisser', { form: { csrf: await csrf(), vente: JSON.stringify({ cle: crypto.randomUUID(), etab: 'comptoir-du-lac', note: { id: +nT1, mode: 'tout' }, lignes: [], paiements: [{ devise: 'CDF', compte: +caisseFC, recu: happy }] }) } });
  v(r.status() === 200 && sql('SELECT total FROM ventes ORDER BY id DESC LIMIT 1') === happy.toFixed(3), 'payée au prix du happy hour (prix recalculés avec la grille de la commande)');

  // 4. Réservations : saisie dans la gestion par le gérant, « réservée » sur le plan, arrivée en ouvrant la note
  const ctxG = await b.newContext({ viewport: { width: 1366, height: 860 } });
  const g = await connecter(ctxG, 'patrick@demo.test', 'Gerant-Goma-2026', err);
  await g.goto(B + '/gestion/salle/reservations');
  const heure = php("echo date('H:i', time() + 3600);");
  await g.fill('input[name=nom]', 'Famille Essai'); await g.fill('input[name=telephone]', '0997 000 111'); await g.fill('input[name=personnes]', '4');
  await g.fill('input[name=heure]', heure); await g.selectOption('select[name=table_id]', { label: 'T6 · Salle (4 places)' });
  await Promise.all([g.waitForNavigation(), g.click('#resa-form button[type=submit]')]);
  v((await g.textContent('.alerte')).includes('Famille Essai') && (await g.textContent('.sal-resas')).includes('Famille Essai'), 'réservation enregistrée et listée');
  const resa = sql("SELECT CONCAT(id, '|', telephone, '|', personnes, '|', DATE_FORMAT(debut, '%H:%i')) FROM reservations WHERE nom='Famille Essai'");
  v(resa.endsWith('|+243997000111|4|' + heure), 'réservation : téléphone normalisé, 4 personnes, ' + heure + ' : ' + resa);
  await g.goto(B + '/gestion/salle/reservations?jour=2026-01-01');
  v((await g.textContent('main')).includes('Aucune réservation'), 'autre jour : aucune réservation');
  await p.click('#salle'); await p.waitForSelector('#salle-fenetre[open]'); await p.waitForTimeout(1500);
  v((await p.getAttribute('.table-plan:has-text("T6")', 'data-statut')) === 'reservee' && (await p.textContent('.table-plan:has-text("T6")')).includes('Famille Essai'), 'plan : T6 « Réservée », heure et nom');
  await p.click('.table-plan:has-text("T6")'); await p.waitForTimeout(300);
  await ajouter(p, ['Coca-Cola']); await p.click('#envoyer'); await p.waitForTimeout(900);
  v(sql("SELECT statut FROM reservations WHERE nom='Famille Essai'") === 'arrivee' && sql("SELECT couverts FROM notes n JOIN salle_tables t ON t.id=n.table_id WHERE t.nom='T6' AND n.statut='ouverte'") === '4', 'note ouverte sur la table réservée : réservation « arrivée », 4 couverts');

  // 5. Gestion : plan de salle (ajout, glisser-déposer), droits
  await g.goto(B + '/gestion/salle');
  await g.fill('form[action="/gestion/salle/tables"] input[name=nom]', 'T20');
  await Promise.all([g.waitForNavigation(), g.click('form[action="/gestion/salle/tables"] button[type=submit]')]);
  v((await g.textContent('.alerte')).includes('T20') && await g.locator('.sal-table:has-text("T20")').count() === 1, 'table T20 ajoutée au plan, à une place libre');
  const t20 = g.locator('.sal-table:has-text("T20")');
  await t20.scrollIntoViewIfNeeded(); const boite = await t20.boundingBox(); const avant = sql("SELECT CONCAT(x, ',', y) FROM salle_tables WHERE nom='T20'");
  await g.mouse.move(boite.x + 10, boite.y + 10); await g.mouse.down(); await g.mouse.move(boite.x + 10 - 120, boite.y + 10 + 80, { steps: 8 }); await g.mouse.up();
  await g.waitForTimeout(1200);
  const apres = sql("SELECT CONCAT(x, ',', y) FROM salle_tables WHERE nom='T20'");
  v(apres !== avant && (await g.textContent('#sal-plan-etat')).includes('enregistré'), `glisser-déposer : T20 ${avant} → ${apres}, enregistré`);
  await t20.focus(); await g.keyboard.press('Shift+ArrowRight'); await g.waitForTimeout(1000);
  v(sql("SELECT largeur FROM salle_tables WHERE nom='T20'") === '4', 'clavier : Maj + flèche élargit la table');
  await g.fill('form[action="/gestion/salle/tables"] input[name=nom]', 'T1');
  await Promise.all([g.waitForNavigation(), g.click('form[action="/gestion/salle/tables"] button[type=submit]')]);
  v((await g.textContent('.alerte--erreur')).includes('T1'), 'nom déjà pris : refusé avec un message');
  const t3id = sql("SELECT id FROM salle_tables WHERE nom='T8'");
  r = await g.request.post(B + `/gestion/salle/tables/${t3id}/retirer`, { form: { csrf: await g.locator('input[name=csrf]').first().inputValue() } });
  v(sql(`SELECT actif FROM salle_tables WHERE id=${t3id}`) === '1', 'table avec une note ouverte : on ne la retire pas');
  r = await g.request.post(B + '/gestion/salle/services/journee', { form: { csrf: await g.locator('input[name=csrf]').first().inputValue(), etablissement: '2', fin_journee: '06:00' } });
  v(r.status() === 403, 'gérant du Comptoir du Lac : régler le Volcano Club refusé (403)');
  r = await g.request.post(B + '/gestion/salle/services/journee', { form: { csrf: 'faux', etablissement: '1', fin_journee: '06:00' } });
  v(r.status() === 419, 'formulaire de gestion sans jeton : 419');
  // Comptable : ni la salle ni la caisse
  const ctxC = await b.newContext(); const cpt = await connecter(ctxC, 'grace@demo.test', 'Compta-Goma-2026', err);
  v((await cpt.goto(B + '/gestion/salle')).status() === 403, 'comptable : Plan de salle refusé (403)');
  v((await cpt.request.get(B + '/caisse/salle?etab=comptoir-du-lac')).status() === 403, 'comptable : état de la salle à la caisse refusé (403)');
  v((await cpt.goto(B + '/caisse/cuisine')).status() === 403, 'comptable : écran de cuisine refusé (403)');
  // Superviseure du club : voit la salle, ne modifie pas le plan
  const ctxR = await b.newContext(); const riz = await connecter(ctxR, 'riziki@demo.test', 'Superviseur-2026', err);
  await riz.goto(B + '/gestion/salle');
  v((await riz.textContent('h1')).includes('Plan de salle') && await riz.locator('form[action="/gestion/salle/tables"]').count() === 0, 'superviseure : plan en lecture seule');
  r = await riz.request.post(B + '/gestion/salle/tables', { form: { csrf: 'x', etablissement: '2', nom: 'X' } });
  v(r.status() === 403 || r.status() === 419, 'superviseure : ajouter une table refusé');
  v((await ctx.newPage().then(async q => { const rr = await q.goto(B + '/gestion/salle/reservations?etablissement=2'); return rr.status(); })) === 200, 'propriétaire : réservations du club');

  // 6. Écran de cuisine autorisé par le gérant (sans connexion ensuite), retiré dans la gestion
  const ctxK = await b.newContext({ viewport: { width: 1280, height: 800 } });
  const k = await ctxK.newPage();
  const rk = await k.goto(B + '/caisse/cuisine');
  v(new URL(k.url()).pathname === '/gestion/connexion', 'écran de cuisine sans connexion ni autorisation : vers la connexion');
  await k.fill('#email', 'patrick@demo.test'); await k.fill('#mot_de_passe', 'Gerant-Goma-2026'); await Promise.all([k.waitForNavigation(), k.click('button[type=submit]')]);
  await k.goto(B + '/caisse/cuisine'); await k.click('.cuisine__autoriser > summary');
  await k.fill('.cuisine__autoriser-form input[name=nom]', 'Tablette du grill');
  await k.selectOption('.cuisine__autoriser-form select[name=poste]', { label: 'Grill' });
  await Promise.all([k.waitForNavigation(), k.click('.cuisine__autoriser-form button[type=submit]')]);
  v((await k.textContent('.cuisine__ecran')).includes('Tablette du grill') && await k.locator('a.puce[href="/gestion"]').count() === 0, 'écran autorisé : il affiche la cuisine sans connexion (gérant déconnecté)');
  v((await k.goto(B + '/gestion/salle')).url().includes('/gestion/connexion'), 'sur l\'écran autorisé, la gestion demande la connexion');
  await k.goto(B + '/caisse/cuisine'); await k.waitForTimeout(1200);
  v((await k.textContent('.cuisine__postes [aria-current]')).includes('Grill'), 'écran autorisé : poste Grill par défaut');
  const bon = k.locator('.bon-carte').first();
  const statut = await bon.getAttribute('class');
  await bon.locator('.bon-carte__avancer').click(); await k.waitForTimeout(800);
  v((await k.locator('.bon-carte').first().getAttribute('class')) !== statut, 'écran autorisé : il fait avancer un bon');
  await g.goto(B + '/gestion/cuisine');
  v((await g.textContent('main')).includes('Tablette du grill'), 'gestion : écran autorisé listé');
  await Promise.all([g.waitForNavigation(), g.locator('.sal-ecran', { hasText: 'Tablette du grill' }).locator('button').click()]);
  await k.goto(B + '/caisse/cuisine');
  v(new URL(k.url()).pathname === '/gestion/connexion', 'écran retiré : il ne s\'ouvre plus');

  // 7. Routage dans la gestion : Plats → Friteuse puis retour
  const plats = sql("SELECT id FROM categories WHERE nom_fr='Plats'");
  await g.goto(B + '/gestion/cuisine');
  await g.selectOption(`#routage-${plats}`, { label: 'Friteuse' });
  await Promise.all([g.waitForNavigation(), g.click('form[action="/gestion/cuisine/routage"] button[type=submit]')]);
  v(sql(`SELECT p.nom FROM cuisine_routage r JOIN postes_cuisine p ON p.id=r.poste_id WHERE r.etablissement_id=1 AND r.categorie_id=${plats}`) === 'Friteuse', 'routage enregistré : Plats → Friteuse');

  // 8. Anglais
  const ctxE = await b.newContext({ viewport: { width: 1366, height: 860 } });
  const en = await connecter(ctxE, 'admin@demo.test', MDP, err);
  await en.goto(B + '/caisse?e=comptoir-du-lac&lang=en'); await en.waitForTimeout(700);
  v((await en.textContent('#salle')).includes('Floor'), 'EN : bouton « Floor »');
  await en.click('#salle'); await en.waitForTimeout(700);
  v((await en.textContent('#salle-fenetre')).includes('Occupied') && (await en.textContent('#salle-fenetre')).includes('Counter sale'), 'EN : plan « Occupied », « Counter sale »');
  await en.goto(B + '/caisse/cuisine?lang=en'); await en.waitForTimeout(800);
  v((await en.textContent('.cuisine__postes')).includes('All stations') && (await en.textContent('#bons')).includes('Start'), 'EN : écran de cuisine');
  await en.goto(B + '/gestion/salle/services?lang=en');
  v((await en.textContent('h1')).includes('Service times'), 'EN : « Service times »');

  v(err.length === 0, 'aucune erreur JS ' + err.join(' | '));
  bilan(); await b.close();
})();
