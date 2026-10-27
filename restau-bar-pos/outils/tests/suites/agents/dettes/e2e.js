// Tests de bout en bout : Clients et dettes dans la gestion (clients, dettes, remboursements, garanties,
// messagerie, rapport des créances, délai d'ardoise de l'établissement, part à crédit d'une vente).
// Base : la démo reconstruite (apres-demo.sql), restaurée avant et après les tests.
const { chromium } = require('playwright');
const { execSync, spawn } = require('child_process');
const fs = require('fs');

const B = 'http://integ.localhost:8250';
const MOCK = 'http://127.0.0.1:8161';
const DIR = __dirname;
const RACINE = '/home/user/Eglise/restau-bar-pos';
const ADMIN = ['admin@demo.test', fs.readFileSync(DIR + '/../../instance.txt', 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1]];
const PATRICK = ['+243810000101', 'Gerant-Goma-2026'];      // gérant du Comptoir du Lac (connexion par téléphone)
const GRACE = ['grace@demo.test', 'Compta-Goma-2026'];       // comptable, tous les établissements
const RIZIKI = ['riziki@demo.test', 'Superviseur-2026'];     // superviseure du Volcano Club

let ok = 0, ko = 0;
const echecs = [];
function verifier(cond, nom, detail) {
  if (cond) { ok++; } else { ko++; echecs.push(nom + (detail !== undefined ? ' — ' + JSON.stringify(detail) : '')); console.log('ÉCHEC', nom, detail ?? ''); }
}
function sql(q) {
  return execSync(`mysql -u root -N -B rbp_integ -e ${JSON.stringify("SET time_zone = '+02:00'; " + q.replace(/\s+/g, ' '))}`, { encoding: 'utf8' }).trim().split('\n').filter(l => l !== '').map(l => l.split('\t'));
}
const un = (q) => sql(q)[0]?.[0];
function nombre(texte, langue = 'fr') {
  let t = String(texte).replace(/[^\d,.\-−]/g, '').replace('−', '-');
  t = langue === 'fr' ? t.replace(/\./g, '').replace(',', '.') : t.replace(/,/g, '');
  return parseFloat(t);
}
const proche = (a, b, tol = 0.006) => Math.abs(a - b) < tol;
// Seules les tables que les tests modifient sont sauvegardées puis restaurées : la base est partagée avec
// d'autres sessions de travail, dont les autres tables ne doivent pas revenir en arrière.
const TABLES = 'clients dettes remboursements remboursement_dettes messages parametres mouvements_tresorerie journal etablissements';
const SAUVEGARDE = DIR + '/avant-e2e-tables.sql';
function sauvegarder() { execSync(`mysqldump -u root rbp_integ ${TABLES} > ${SAUVEGARDE}`, { shell: '/bin/bash' }); }
function restaurer() { execSync(`mysql -u root rbp_integ < ${SAUVEGARDE}`, { shell: '/bin/bash' }); }

async function connecter(navigateur, [email, mdp], options = {}) {
  const contexte = await navigateur.newContext({ viewport: { width: 1366, height: 860 }, ...options });
  const page = await contexte.newPage();
  page.erreurs = [];
  page.on('pageerror', e => page.erreurs.push(String(e)));
  page.on('console', m => { if (m.type() === 'error' && !m.text().startsWith('Failed to load resource')) page.erreurs.push(m.text()); });
  page.on('response', rep => { if (rep.request().resourceType() !== 'document' && rep.status() >= 400) page.erreurs.push(rep.status() + ' ' + rep.url()); });
  await page.goto(B + '/gestion/connexion?lang=fr');
  await page.fill('#email', email);
  await page.fill('#mot_de_passe', mdp);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  return page;
}
async function jeton(page) {
  if (!(await page.locator('input[name=csrf]').count())) await page.goto(B + '/gestion');
  return page.locator('input[name=csrf]').first().getAttribute('value');
}
async function poster(page, url, donnees, avecJeton = true) {
  const form = { ...donnees };
  if (avecJeton) form.csrf = await jeton(page);
  const rep = await page.request.post(B + url, { form, maxRedirects: 0 });
  return { status: rep.status(), lieu: rep.headers()['location'] || '', texte: await rep.text() };
}
async function statut(page, url) { return (await page.goto(B + url)).status(); }
async function debordement(page) {
  return page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
}
async function clesBrutes(page) {
  const texte = await page.evaluate(() => document.body.innerText);
  return (texte.match(/\b(gclients|gdettes|gremb|garanties|messagerie|rcreances|gventes|tresorerie|gestion|ecran|etab|journal|msg)\.[a-z_]{3,}/g) || []);
}
function soldeCompte(compte, devise) {
  return +un(`select coalesce(sum(if(sens='entree',montant,-montant)),0) from mouvements_tresorerie where compte_id=${compte} and devise_id=${devise}`);
}

(async () => {
  sauvegarder();
  const mock = spawn('php', ['-S', '127.0.0.1:8161', DIR + '/mock.php'], { stdio: 'ignore' });
  const navigateur = await chromium.launch();
  const aujourdhui = execSync('TZ=Africa/Lubumbashi date +%F', { encoding: 'utf8' }).trim();
  const CDF = un("select id from devises where code='CDF'"), USD = un("select id from devises where code='USD'");

  const admin = await connecter(navigateur, ADMIN);
  const patrick = await connecter(navigateur, PATRICK);
  const grace = await connecter(navigateur, GRACE);
  const riziki = await connecter(navigateur, RIZIKI);
  verifier(patrick.url().endsWith('/gestion'), 'Patrick se connecte par téléphone');

  // ---------- Droits : écrans et menu ----------
  const droits = [
    [patrick, 'Patrick', { '/gestion/clients': 200, '/gestion/clients/nouveau': 200, '/gestion/dettes': 200, '/gestion/remboursements': 200, '/gestion/remboursements/nouveau': 200, '/gestion/garanties': 200, '/gestion/messagerie': 403, '/gestion/messagerie/journal': 403, '/gestion/rapports/creances': 200 }],
    [grace, 'Grace', { '/gestion/clients': 200, '/gestion/clients/nouveau': 403, '/gestion/dettes': 200, '/gestion/remboursements': 200, '/gestion/remboursements/nouveau': 200, '/gestion/garanties': 200, '/gestion/messagerie': 403, '/gestion/rapports/creances': 200 }],
    [riziki, 'Riziki', { '/gestion/clients': 200, '/gestion/clients/nouveau': 403, '/gestion/dettes': 200, '/gestion/remboursements/nouveau': 200, '/gestion/garanties': 200, '/gestion/messagerie': 403, '/gestion/rapports/creances': 403 }],
    [admin, 'Admin', { '/gestion/messagerie': 200, '/gestion/messagerie/journal': 200, '/gestion/rapports/creances': 200 }],
  ];
  for (const [p, nom, attendus] of droits) {
    for (const [url, code] of Object.entries(attendus)) verifier(await statut(p, url) === code, `${nom} ${url} → ${code}`);
  }
  await patrick.goto(B + '/gestion/dettes');
  const menu = await patrick.locator('.lateral__lien').allTextContents();
  for (const e of ['Clients', 'Dettes', 'Remboursements', 'Garanties']) verifier(menu.some(t => t.includes(e) && !t.includes('Bientôt')), 'menu Ventes : ' + e, menu);
  verifier(await patrick.locator('.lateral__lien[aria-current="page"]').textContent().then(t => t.includes('Dettes')), 'menu : Dettes actif');
  await admin.goto(B + '/gestion/messagerie');
  verifier(await admin.locator('.lateral__lien[aria-current="page"]').textContent().then(t => t.includes('Messagerie')), 'menu Paramètres : Messagerie');
  await admin.goto(B + '/gestion/rapports/creances');
  verifier(await admin.locator('.lateral__lien[aria-current="page"]').textContent().then(t => t.includes('Créances')), 'menu Rapports : Créances');
  await grace.goto(B + '/gestion/compte');
  verifier(!(await grace.locator('.lateral__lien').allTextContents()).some(t => t.includes('Messagerie')), 'Grace : pas de Messagerie dans le menu');

  // ---------- CSRF ----------
  const uneDette1 = un("select id from dettes where etablissement_id=1 and statut='ouverte' limit 1");
  for (const [url, donnees] of [
    ['/gestion/clients/nouveau', { nom: 'Sans jeton' }],
    [`/gestion/dettes/${uneDette1}/cloturer`, { statut: 'remise', motif_cloture: 'x', confirme: '1' }],
    ['/gestion/remboursements/nouveau', { client_id: '1' }],
    ['/gestion/garanties/' + uneDette1, { reponse: 'accepter' }],
  ]) {
    const r = await poster(patrick, url, donnees, false);
    verifier(r.status === 419, 'CSRF manquant refusé : ' + url, r.status);
  }
  verifier((await poster(admin, '/gestion/messagerie', { relances_etapes: '1' }, false)).status === 419, 'CSRF manquant refusé : messagerie');
  verifier((await poster(patrick, '/gestion/clients/nouveau', { nom: 'Jeton faux', csrf: 'abc' }, false)).status === 419, 'CSRF faux refusé');

  // ---------- Portée ----------
  async function totalAffiche(page, url, deviseId) {
    await page.goto(B + url);
    const t = await page.locator(`[data-total="reste-${deviseId}"]`).textContent().catch(() => '0');
    return nombre(t);
  }
  for (const [p, nom, where] of [[patrick, 'Patrick', 'etablissement_id=1'], [riziki, 'Riziki', 'etablissement_id=2'], [grace, 'Grace', '1=1']]) {
    const nb = +un(`select count(*) from dettes where statut='ouverte' and ${where}`);
    await p.goto(B + '/gestion/dettes');
    verifier(+(await p.locator('[data-total="nb"]').textContent()) === nb, `${nom} : nombre de dettes ouvertes de sa portée`, nb);
    const reste = +un(`select coalesce(sum(reste),0) from dettes where statut='ouverte' and devise_id=${CDF} and ${where}`);
    verifier(proche(await totalAffiche(p, '/gestion/dettes', CDF), reste), `${nom} : reste dû en CDF`, reste);
    const lignes = await p.locator('tr[data-dette]').evaluateAll(rs => rs.map(r => r.dataset.dette));
    const permis = sql(`select id from dettes where ${where}`).map(l => l[0]);
    verifier(lignes.every(id => permis.includes(id)), `${nom} : seules ses dettes sont listées`);
  }
  const dette2 = un("select id from dettes where etablissement_id=2 limit 1");
  verifier(await statut(patrick, '/gestion/dettes/' + dette2) === 404, 'Patrick : dette du club → 404');
  verifier(await statut(riziki, '/gestion/dettes/' + uneDette1) === 404, 'Riziki : dette du Comptoir → 404');
  verifier(await statut(grace, '/gestion/dettes/' + dette2) === 200, 'Grace : dette du club visible');
  const remb2 = un("select id from remboursements where etablissement_id=2 limit 1");
  if (remb2) verifier(await statut(patrick, '/gestion/remboursements/' + remb2) === 404, 'Patrick : remboursement du club → 404');
  // Encours d'un client limité à la portée
  const clientDeux = un("select client_id from dettes where statut='ouverte' group by client_id having count(distinct etablissement_id)=2 limit 1");
  if (clientDeux) {
    await patrick.goto(B + '/gestion/clients/' + clientDeux);
    const ids = await patrick.locator('tr[data-dette]').evaluateAll(rs => rs.map(r => r.dataset.dette));
    verifier(ids.length > 0 && ids.every(id => un(`select etablissement_id from dettes where id=${id}`) === '1'), 'fiche client (Patrick) : dettes du Comptoir seulement');
    const enc = +un(`select coalesce(sum(reste),0) from dettes where client_id=${clientDeux} and statut='ouverte' and etablissement_id=1 and devise_id=${CDF}`);
    if (enc > 0) verifier(proche(nombre(await patrick.locator('[data-encours="CDF"]').textContent()), enc), 'fiche client (Patrick) : encours du Comptoir', enc);
  }
  // Rapport : portée de Patrick
  await patrick.goto(B + '/gestion/rapports/creances');
  verifier(proche(nombre(await patrick.locator(`[data-total="devise-${CDF}"]`).textContent()), +un(`select sum(reste) from dettes where statut='ouverte' and etablissement_id=1 and devise_id=${CDF}`)), 'rapport (Patrick) : total CDF du Comptoir');
  verifier(await patrick.locator('[data-groupe="etablissement"] tbody tr').count() === 1, 'rapport (Patrick) : un seul établissement');

  // ---------- Liste des dettes : filtres, totaux, ancienneté ----------
  await admin.goto(B + '/gestion/dettes?statut=toutes');
  verifier(+(await admin.locator('[data-total="nb"]').textContent()) === +un('select count(*) from dettes'), 'toutes les dettes : nombre');
  await admin.goto(B + '/gestion/dettes?echeance=retard');
  verifier(+(await admin.locator('[data-total="nb"]').textContent()) === +un(`select count(*) from dettes where statut='ouverte' and echeance < '${aujourdhui}'`), 'filtre en retard');
  await admin.goto(B + '/gestion/dettes?echeance=a_venir');
  verifier(+(await admin.locator('[data-total="nb"]').textContent()) === +un(`select count(*) from dettes where statut='ouverte' and echeance >= '${aujourdhui}'`), 'filtre à venir');
  await admin.goto(B + '/gestion/dettes?garant=1');
  verifier(+(await admin.locator('[data-total="nb"]').textContent().catch(() => '0')) === +un("select count(*) from dettes where statut='ouverte' and garant_statut='en_attente'"), 'filtre garant en attente');
  const aline = un("select id from utilisateurs where nom like 'Aline%'");
  await admin.goto(B + `/gestion/dettes?servi=${aline}`);
  verifier(+(await admin.locator('[data-total="nb"]').textContent()) === +un(`select count(*) from dettes where statut='ouverte' and servi_par=${aline}`), 'filtre servi par');
  await admin.goto(B + `/gestion/dettes?responsable=${aline}&statut=toutes`);
  verifier(+(await admin.locator('[data-total="nb"]').textContent()) === +un(`select count(*) from dettes where responsable_id=${aline}`), 'filtre responsable');
  await admin.goto(B + `/gestion/dettes?devise=${USD}`);
  verifier(+(await admin.locator('[data-total="nb"]').textContent()) === +un(`select count(*) from dettes where statut='ouverte' and devise_id=${USD}`), 'filtre devise USD');
  await admin.goto(B + '/gestion/dettes?etablissement=2');
  verifier(+(await admin.locator('[data-total="nb"]').textContent()) === +un("select count(*) from dettes where statut='ouverte' and etablissement_id=2"), 'filtre établissement');
  await admin.goto(B + '/gestion/dettes?du=2026-10-15&au=2026-10-20&statut=toutes');
  verifier(+(await admin.locator('[data-total="nb"]').textContent()) === +un("select count(*) from dettes where cree_le between '2026-10-15 00:00:00' and '2026-10-20 23:59:59'"), 'filtre période');
  await admin.goto(B + '/gestion/dettes');
  for (const [tranche, cond] of [['0_30', '<= 30'], ['31_60', 'between 31 and 60'], ['plus_60', '> 60']]) {
    const attendu = +un(`select coalesce(sum(reste),0) from dettes where statut='ouverte' and reste>0 and devise_id=${CDF} and datediff('${aujourdhui}', date(cree_le)) ${cond}`);
    const t = await admin.locator(`tr[data-tranche="${tranche}"] td[data-devise="CDF"]`).textContent();
    verifier(attendu === 0 ? t.includes('—') : proche(nombre(t), attendu), 'ancienneté ' + tranche, [t, attendu]);
  }
  // Échéance dépassée en rouge sur la fiche ; lien vers le ticket ; aucun bouton « Relancer »
  const echue = un(`select id from dettes where statut='ouverte' and etablissement_id=1 and echeance < '${aujourdhui}' and vente_id is not null limit 1`);
  await patrick.goto(B + '/gestion/dettes/' + echue);
  verifier(await patrick.locator('[data-echeance].det-retard').count() === 1, 'fiche : échéance dépassée en rouge');
  const venteEchue = un(`select vente_id from dettes where id=${echue}`);
  verifier(await patrick.locator(`a[data-ticket][href="/gestion/ventes/${venteEchue}"]`).count() === 1, 'fiche : lien vers le ticket');
  verifier(!(await patrick.content()).match(/Relancer/i), 'pas de bouton Relancer');
  verifier(await patrick.locator('.det-message').count() === +un(`select count(*) from messages where dette_id=${echue}`), 'fiche : messages envoyés');

  // ---------- Clients ----------
  await admin.goto(B + '/gestion/clients?retard=1');
  verifier(+(await admin.locator('[data-total="clients"]').textContent()) === +un(`select count(distinct client_id) from dettes where statut='ouverte' and reste>0 and echeance < '${aujourdhui}'`), 'clients en retard');
  await admin.goto(B + '/gestion/clients?dette=1');
  verifier(+(await admin.locator('[data-total="clients"]').textContent()) === +un("select count(distinct client_id) from dettes where statut='ouverte' and reste>0"), 'clients avec dette');
  await admin.goto(B + '/gestion/clients?q=' + encodeURIComponent('0971234501'));
  verifier(await admin.locator('tr[data-client]').count() === 1 && (await admin.locator('tr[data-client]').textContent()).includes('Bisimwa'), 'recherche par téléphone local');
  await admin.goto(B + '/gestion/clients?type=vip');
  verifier(await admin.locator('tr[data-client]').count() === +un("select count(*) from clients where type='vip'"), 'filtre type VIP');
  // Encours : devise locale d'abord, dollar ensuite
  const mixte = un(`select client_id from dettes where statut='ouverte' group by client_id having count(distinct devise_id)=2 limit 1`);
  if (mixte) {
    await admin.goto(B + '/gestion/clients?dette=1&q=' + encodeURIComponent(un(`select nom from clients where id=${mixte}`)));
    const codes = await admin.locator(`tr[data-client="${mixte}"] [data-encours]`).evaluateAll(es => es.map(e => e.dataset.encours));
    verifier(JSON.stringify(codes) === '["CDF","USD"]', 'encours : CDF puis USD', codes);
  }
  // Création, normalisation, doublon, erreurs
  let r = await poster(patrick, '/gestion/clients/nouveau', { nom: '  Bahati   Neema ', telephone: '0970 111 222', email: 'neema@exemple.cd', type: 'habitue', langue: 'fr', canal: 'whatsapp', relances: '1', plafond: '150000', plafond_devise_id: CDF, notes: 'Table 4' });
  verifier(r.status === 302 && /\/gestion\/clients\/\d+$/.test(r.lieu), 'création client → fiche', r.status);
  const nouveau = un("select id from clients where telephone='+243970111222'");
  verifier(!!nouveau && un(`select concat(nom,'|',canal,'|',cast(plafond as unsigned),'|',type,'|',plafond_devise_id) from clients where id=${nouveau}`) === `Bahati Neema|whatsapp|150000|habitue|${CDF}`, 'client enregistré (téléphone +243, nom nettoyé)');
  r = await poster(patrick, '/gestion/clients/nouveau', { nom: 'Doublon', telephone: '+243 970 111 222', type: 'particulier' });
  verifier(r.status === 422 && r.texte.includes('Bahati Neema'), 'téléphone déjà pris → 422 avec le nom', r.status);
  r = await poster(patrick, '/gestion/clients/nouveau', { nom: '', telephone: '12', email: 'pas-un-mail', anniversaire: '2999-01-01', type: 'personnel', plafond: 'abc', plafond_devise_id: CDF });
  verifier(r.status === 422, 'saisie invalide → 422');
  for (const champ of ['nom', 'telephone', 'email', 'anniversaire', 'type', 'plafond']) verifier(r.texte.includes(`id="erreur-${champ}"`), 'erreur sur ' + champ);
  verifier(+un("select count(*) from clients where nom='Doublon' or nom=''") === 0, 'aucun client invalide créé');
  verifier((await poster(grace, '/gestion/clients/nouveau', { nom: 'Par Grace', type: 'particulier' })).status === 403, 'Grace ne crée pas de client (403)');
  // Modification : blocage du crédit, téléphone d'un autre refusé
  await patrick.goto(B + `/gestion/clients/${nouveau}/modifier`);
  verifier(await patrick.locator('#telephone').inputValue() === '+243 97 011 1222', 'formulaire : téléphone affiché lisible');
  await patrick.check('input[name=bloque]');
  await patrick.fill('#plafond', '');
  await Promise.all([patrick.waitForNavigation(), patrick.click('form.tre-formulaire button[type=submit]')]);
  verifier(un(`select concat(bloque,'|',ifnull(plafond,'null')) from clients where id=${nouveau}`) === '1|null', 'modification : crédit bloqué, sans plafond');
  verifier(await patrick.locator('.badge', { hasText: 'Crédit bloqué' }).count() >= 1, 'fiche : badge crédit bloqué');
  r = await poster(patrick, `/gestion/clients/${nouveau}/modifier`, { nom: 'Bahati Neema', telephone: '0971234501', type: 'habitue' });
  verifier(r.status === 422 && r.texte.includes('Bisimwa'), 'modification : numéro d\'un autre client refusé');
  verifier(+un("select count(*) from journal where action='client_modifie'") >= 1, 'journal : client modifié');
  const personnel = un("select id from clients where type='personnel' limit 1");
  verifier(await statut(patrick, `/gestion/clients/${personnel}/modifier`) === 409, 'client personnel : modification refusée (409)');
  await patrick.goto(B + `/gestion/clients/${personnel}`);
  verifier(await patrick.locator('[data-personnel]').count() === 1, 'client personnel : membre du personnel lié affiché');
  verifier(await patrick.locator(`a[href="/gestion/clients/${personnel}/modifier"]`).count() === 0, 'client personnel : pas de bouton Modifier');

  // ---------- Décisions sur une dette ----------
  const aChanger = un(`select id from dettes where etablissement_id=1 and statut='ouverte' and echeance >= '${aujourdhui}' limit 1`);
  const grace_id = un("select id from utilisateurs where email='grace@demo.test'");
  r = await poster(patrick, `/gestion/dettes/${aChanger}/responsable`, { responsable_id: grace_id, motif_responsable: '' });
  verifier(r.status === 422 && r.texte.includes('erreur-motif_responsable'), 'changer le responsable sans motif → 422');
  r = await poster(patrick, `/gestion/dettes/${aChanger}/responsable`, { responsable_id: grace_id, motif_responsable: 'Grace suit ce client' });
  verifier(r.status === 302 && un(`select responsable_id from dettes where id=${aChanger}`) === grace_id, 'responsable changé');
  verifier(+un(`select count(*) from journal where action='dette_responsable' and cible='dette ${aChanger}'`) === 1, 'journal : responsable changé');
  verifier((await poster(grace, `/gestion/dettes/${aChanger}/responsable`, { responsable_id: aline, motif_responsable: 'x' })).status === 403, 'Grace ne décide pas (403)');
  verifier((await poster(patrick, `/gestion/dettes/${dette2}/cloturer`, { statut: 'remise', motif_cloture: 'x', confirme: '1' })).status === 404, 'Patrick : décision sur une dette du club → 404');
  // Mise à charge : seulement après l'échéance
  await patrick.goto(B + `/gestion/dettes/${aChanger}`);
  verifier(await patrick.locator('[data-charge-impossible="pas_echue"]').count() === 1, 'mise à charge : expliquée « pas encore »');
  r = await poster(patrick, `/gestion/dettes/${aChanger}/a-charge`, { motif_charge: 'Essai', confirme: '1' });
  verifier(r.status === 409 && un(`select statut from dettes where id=${aChanger}`) === 'ouverte', 'mise à charge avant l\'échéance → 409');
  const aCharger = un(`select d.id from dettes d where d.etablissement_id=1 and d.statut='ouverte' and d.echeance < '${aujourdhui}' and d.responsable_id is not null and d.client_id not in (select id from clients where type='personnel') limit 1`);
  const resteCharge = un(`select reste from dettes where id=${aCharger}`), respCharge = un(`select responsable_id from dettes where id=${aCharger}`);
  r = await poster(patrick, `/gestion/dettes/${aCharger}/a-charge`, { motif_charge: 'Client parti à Bukavu', confirme: '' });
  verifier(r.status === 422, 'mise à charge sans confirmation → 422');
  r = await poster(patrick, `/gestion/dettes/${aCharger}/a-charge`, { motif_charge: 'Client parti à Bukavu', confirme: '1' });
  const nouvelleDette = un(`select id from dettes where origine_id=${aCharger}`);
  verifier(r.status === 302 && r.lieu.endsWith('/gestion/dettes/' + nouvelleDette), 'mise à charge → nouvelle dette', r.lieu);
  verifier(un(`select statut from dettes where id=${aCharger}`) === 'transferee', 'dette du client : mise à charge');
  verifier(un(`select concat(d.reste,'|',c.type,'|',c.utilisateur_id) from dettes d join clients c on c.id=d.client_id where d.id=${nouvelleDette}`) === `${resteCharge}|personnel|${respCharge}`, 'dette du personnel au nom du responsable');
  await patrick.goto(B + '/gestion/dettes/' + aCharger);
  verifier(await patrick.locator(`[data-transfert="${nouvelleDette}"]`).count() === 1, 'fiche : lien vers la dette du personnel');
  await patrick.goto(B + '/gestion/dettes/' + nouvelleDette);
  verifier(await patrick.locator(`[data-origine="${aCharger}"]`).count() === 1, 'fiche : lien vers la dette d\'origine');
  verifier(await patrick.locator('[data-charge-impossible="personnel"]').count() === 1, 'dette du personnel : pas de nouvelle mise à charge');
  // Annuler une dette déjà remboursée en partie : refusé ; remettre : accepté
  const remboursee = un("select d.id from dettes d join remboursement_dettes rd on rd.dette_id=d.id join remboursements r on r.id=rd.remboursement_id and r.statut='valide' where d.statut='ouverte' and d.etablissement_id=1 limit 1");
  if (remboursee) {
    r = await poster(patrick, `/gestion/dettes/${remboursee}/cloturer`, { statut: 'annulee', motif_cloture: 'Erreur', confirme: '1' });
    verifier(r.status === 409 && un(`select statut from dettes where id=${remboursee}`) === 'ouverte', 'annuler une dette remboursée → 409');
    r = await poster(patrick, `/gestion/dettes/${remboursee}/cloturer`, { statut: 'remise', motif_cloture: 'Geste commercial', confirme: '1' });
    verifier(r.status === 302 && un(`select concat(statut,'|',motif) from dettes where id=${remboursee}`) === 'remise|Geste commercial', 'remise avec motif');
  } else verifier(false, 'dette remboursée en partie introuvable');
  const aAnnuler = un("select d.id from dettes d where d.statut='ouverte' and d.etablissement_id=1 and not exists (select 1 from remboursement_dettes rd where rd.dette_id=d.id) and d.client_id not in (select id from clients where type='personnel') limit 1");
  r = await poster(patrick, `/gestion/dettes/${aAnnuler}/cloturer`, { statut: 'annulee', motif_cloture: '', confirme: '1' });
  verifier(r.status === 422, 'annulation sans motif → 422');
  // Par le navigateur (formulaire déplié)
  await patrick.goto(B + `/gestion/dettes/${aAnnuler}`);
  await patrick.click('#cloture summary');
  await patrick.check('#cloture input[value=annulee]');
  await patrick.fill('#motif_cloture', 'Saisie en double à la caisse');
  await patrick.check('#cloture input[name=confirme]');
  await Promise.all([patrick.waitForNavigation(), patrick.click('#cloture button[type=submit]')]);
  verifier(un(`select statut from dettes where id=${aAnnuler}`) === 'annulee', 'annulation par le formulaire');
  verifier(await patrick.locator('[data-statut="annulee"]').count() === 1 && (await patrick.locator('[data-motif]').textContent()).includes('double'), 'fiche : annulée avec motif');
  verifier(await patrick.locator('#decisions').count() === 0, 'dette close : plus de décisions');
  verifier((await poster(patrick, `/gestion/dettes/${aAnnuler}/cloturer`, { statut: 'remise', motif_cloture: 'x', confirme: '1' })).status === 409, 'décision sur une dette close → 409');

  // ---------- Remboursements ----------
  // Grace (comptable) : recherche, formulaire, trop, juste, double envoi
  const clientR = un("select d.client_id from dettes d join clients c on c.id=d.client_id where d.statut='ouverte' and d.devise_id=" + CDF + " and c.type<>'personnel' group by d.client_id having count(*)>=2 order by sum(d.reste) desc limit 1");
  const nomR = un(`select nom from clients where id=${clientR}`);
  await grace.goto(B + '/gestion/remboursements/nouveau');
  await grace.fill('#q', nomR.split(' ')[1] || nomR);
  await Promise.all([grace.waitForNavigation(), grace.click('form.det-recherche button')]);
  verifier(await grace.locator(`a.det-resultat[data-client="${clientR}"]`).count() === 1, 'recherche du client');
  await Promise.all([grace.waitForNavigation(), grace.click(`a.det-resultat[data-client="${clientR}"]`)]);
  const dues = sql(`select id from dettes where client_id=${clientR} and statut='ouverte' order by cree_le, id`).map(l => l[0]);
  verifier(JSON.stringify(await grace.locator('ol.det-lignes li').evaluateAll(ls => ls.map(l => l.dataset.dette))) === JSON.stringify(dues), 'dettes du client, les plus anciennes d\'abord');
  const etabR = un(`select etablissement_id from dettes where id=${dues[0]}`);
  verifier(await grace.locator('#etablissement_id').inputValue() === etabR, 'établissement proposé : celui de la plus ancienne dette');
  // Comptes : ceux de l'établissement et les communs (JavaScript)
  const visibles = await grace.locator('#compte_id option[data-etablissement]:not([hidden])').evaluateAll(os => os.map(o => o.dataset.etablissement));
  verifier(visibles.length > 0 && visibles.every(e => e === '' || e === etabR), 'comptes de l\'établissement et communs seulement', visibles);
  const autreEtab = etabR === '1' ? '2' : '1';
  await grace.selectOption('#etablissement_id', autreEtab);
  const visibles2 = await grace.locator('#compte_id option[data-etablissement]:not([hidden])').evaluateAll(os => os.map(o => o.dataset.etablissement));
  verifier(visibles2.every(e => e === '' || e === autreEtab), 'changer d\'établissement filtre les comptes');
  await grace.selectOption('#etablissement_id', etabR);
  const caisse = un(`select id from comptes_tresorerie where type='caisse' and etablissement_id=${etabR}`);
  await grace.selectOption('#compte_id', caisse);
  verifier(JSON.stringify(await grace.locator('#devise_id option').allTextContents()) === JSON.stringify(['USD', 'CDF']) || JSON.stringify(await grace.locator('#devise_id option').allTextContents()) === JSON.stringify(['CDF', 'USD']), 'devises du compte');
  await grace.selectOption('#devise_id', CDF);
  const totalDu = +un(`select sum(reste) from dettes where client_id=${clientR} and statut='ouverte' and devise_id=${CDF}`) ;
  const usdDu = +un(`select coalesce(sum(reste),0) from dettes where client_id=${clientR} and statut='ouverte' and devise_id=${USD}`);
  await grace.fill('#montant', String(totalDu + usdDu * 3000 + 100000));
  await grace.click('form.det-form-remb button[type=submit]');
  await grace.waitForLoadState();
  verifier((await grace.locator('#erreur-montant').textContent().catch(() => '')).includes('au plus'), 'trop → maximum indiqué', await grace.locator('#erreur-montant').textContent().catch(() => ''));
  verifier(+un(`select count(*) from remboursements where client_id=${clientR} and utilisateur_id=${grace_id}`) === 0, 'trop : rien enregistré');
  const premier = +un(`select reste from dettes where id=${dues[0]}`);
  const solde0 = soldeCompte(caisse, CDF);
  const montantR = premier + 1000;
  await grace.fill('#montant', String(montantR));
  const cle = await grace.locator('input[name=cle]').inputValue();
  await Promise.all([grace.waitForNavigation(), grace.click('form.det-form-remb button[type=submit]')]);
  const rembId = un(`select id from remboursements where cle='${cle}'`);
  verifier(!!rembId && grace.url().endsWith('/gestion/remboursements/' + rembId), 'remboursement enregistré → fiche');
  verifier(un(`select statut from dettes where id=${dues[0]}`) === 'soldee', 'plus ancienne dette soldée');
  const reste1 = +un(`select reste from dettes where id=${dues[1]}`);
  verifier(proche(+un(`select montant from remboursement_dettes where remboursement_id=${rembId} and dette_id=${dues[1]}`), 1000), 'le reste va sur la dette suivante');
  verifier(proche(soldeCompte(caisse, CDF), solde0 + montantR), 'caisse créditée du montant');
  verifier(await grace.locator('li[data-affectation]').count() === 2, 'fiche : répartition sur deux dettes');
  // Double envoi (même clé) : compté une fois
  const nbAvant = +un('select count(*) from remboursements');
  r = await poster(grace, '/gestion/remboursements/nouveau', { client_id: clientR, cle, etablissement_id: etabR, compte_id: caisse, devise_id: CDF, montant: String(montantR) });
  verifier(r.status === 302 && r.lieu.endsWith('/' + rembId) && +un('select count(*) from remboursements') === nbAvant && proche(soldeCompte(caisse, CDF), solde0 + montantR), 'double envoi : compté une fois');
  verifier(proche(+un(`select reste from dettes where id=${dues[1]}`), reste1), 'double envoi : reste inchangé');
  // Compte d'un autre établissement refusé
  const caisseAutre = un(`select id from comptes_tresorerie where type='caisse' and etablissement_id=${autreEtab}`);
  r = await poster(grace, '/gestion/remboursements/nouveau', { client_id: clientR, cle: '0b5e1a2c-3d4e-4f60-8a7b-9c0d1e2f3a4b', etablissement_id: etabR, compte_id: caisseAutre, devise_id: CDF, montant: '500' });
  verifier(r.status === 422 && r.texte.includes('erreur-compte_id'), 'compte d\'un autre établissement refusé');
  // Paiement en dollars d'une dette en francs (taux du jour)
  const taux = +un(`select taux from taux_change where devise_id=${CDF} order by valable_depuis desc, id desc limit 1`);
  const resteAvant = +un(`select reste from dettes where id=${dues[1]}`);
  r = await poster(grace, '/gestion/remboursements/nouveau', { client_id: clientR, cle: 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d', etablissement_id: etabR, compte_id: caisse, devise_id: USD, montant: '2' });
  verifier(r.status === 302, 'remboursement en dollars', r.status);
  verifier(proche(resteAvant - +un(`select reste from dettes where id=${dues[1]}`), 2 * taux, 1), 'dollars convertis au taux du jour', [resteAvant, taux]);
  // Riziki (club) : seulement les comptes du club et communs, seulement les dettes du club
  const clientClub = un("select client_id from dettes where statut='ouverte' and etablissement_id=2 limit 1");
  await riziki.goto(B + '/gestion/remboursements/nouveau?client=' + clientClub);
  const etabsOptions = await riziki.locator('#compte_id option[data-etablissement]').evaluateAll(os => os.map(o => o.dataset.etablissement));
  verifier(etabsOptions.length > 0 && etabsOptions.every(e => e === '' || e === '2'), 'Riziki : comptes du club et communs', etabsOptions);
  verifier((await riziki.locator('ol.det-lignes li').evaluateAll(ls => ls.map(l => l.dataset.dette))).every(id => un(`select etablissement_id from dettes where id=${id}`) === '2'), 'Riziki : dettes du club seulement');
  // Annulation : Grace n'a pas dettes.gerer ; Patrick annule
  await grace.goto(B + '/gestion/remboursements/' + rembId);
  verifier(await grace.locator('#annuler').count() === 0, 'Grace : pas de bouton annuler');
  verifier((await poster(grace, `/gestion/remboursements/${rembId}/annuler`, { motif: 'x', confirme: '1' })).status === 403, 'Grace : annulation refusée (403)');
  const rembP = un(`select r.id from remboursements r where r.etablissement_id=1 and r.statut='valide' and not exists (select 1 from remboursement_dettes rd join dettes d on d.id=rd.dette_id where rd.remboursement_id=r.id and d.statut not in ('ouverte','soldee')) order by r.id desc limit 1`);
  const affect = sql(`select dette_id, montant, (select reste from dettes where id=dette_id) from remboursement_dettes where remboursement_id=${rembP}`);
  const [compteP, deviseP, montantP] = sql(`select compte_id, devise_id, montant from remboursements where id=${rembP}`)[0];
  const soldeP = soldeCompte(compteP, deviseP);
  r = await poster(patrick, `/gestion/remboursements/${rembP}/annuler`, { motif: '', confirme: '1' });
  verifier(r.status === 422, 'annuler un remboursement sans motif → 422');
  await patrick.goto(B + '/gestion/remboursements/' + rembP);
  await patrick.fill('#motif', 'Saisi sur le mauvais client');
  await patrick.check('#annuler input[name=confirme]');
  await Promise.all([patrick.waitForNavigation(), patrick.click('#annuler button[type=submit]')]);
  verifier(un(`select statut from remboursements where id=${rembP}`) === 'annule', 'remboursement annulé');
  verifier(affect.every(([d, m, avant]) => proche(+un(`select reste from dettes where id=${d}`), +avant + +m) && un(`select statut from dettes where id=${d}`) === 'ouverte'), 'dettes : reste rétabli');
  verifier(proche(soldeCompte(compteP, deviseP), soldeP - +montantP), 'argent ressorti du compte');
  verifier(await patrick.locator('.det-annule').count() === 1, 'fiche : remboursement annulé');
  verifier((await poster(patrick, `/gestion/remboursements/${rembP}/annuler`, { motif: 'x', confirme: '1' })).status === 409, 'annuler deux fois → 409');
  // Liste : totaux par devise
  await admin.goto(B + '/gestion/remboursements?periode=tout');
  verifier(+(await admin.locator('[data-total="nb"]').textContent()) === +un("select count(*) from remboursements where statut='valide'"), 'liste : nombre de remboursements valides');
  verifier(proche(nombre(await admin.locator(`[data-total="devise-${CDF}"]`).textContent()), +un(`select sum(montant) from remboursements where statut='valide' and devise_id=${CDF}`)), 'liste : total CDF');
  await admin.goto(B + '/gestion/remboursements?periode=tout&moyen=mobile_money');
  verifier(+(await admin.locator('[data-total="nb"]').textContent()) === +un("select count(*) from remboursements where statut='valide' and moyen='mobile_money'"), 'liste : filtre mobile money');
  await admin.goto(B + `/gestion/remboursements?periode=tout&utilisateur=${grace_id}`);
  verifier(+(await admin.locator('[data-total="nb"]').textContent()) === +un(`select count(*) from remboursements where statut='valide' and utilisateur_id=${grace_id}`), 'liste : filtre utilisateur');
  await admin.goto(B + '/gestion/remboursements?periode=tout&statut=annule');
  verifier(await admin.locator('tr[data-remboursement]').count() === +un("select count(*) from remboursements where statut='annule'"), 'liste : filtre annulés');

  // ---------- Garanties ----------
  const patrick_id = un("select id from utilisateurs where telephone='+243810000101'");
  // La démo n'a qu'une garantie en attente pour Patrick (dette 17) ; les étapes précédentes (mise à la charge, annulation,
  // remboursements, qui prennent des dettes ouvertes du Comptoir) ont pu la clore : on en demande alors une autre
  if (+un(`select count(*) from dettes where garant_id=${patrick_id} and garant_statut='en_attente' and statut='ouverte'`) === 0) {
    sql(`update dettes set garant_id=${patrick_id}, garant_statut='en_attente' where id = (select id from (select d.id from dettes d join clients c on c.id=d.client_id
          where d.statut='ouverte' and d.etablissement_id=1 and d.garant_id is null and d.servi_par<>${patrick_id} and d.responsable_id=d.servi_par and c.type<>'personnel' order by d.id limit 1) x)`);
  }
  const enAttente = +un(`select count(*) from dettes where garant_id=${patrick_id} and garant_statut='en_attente' and statut='ouverte'`);
  await patrick.goto(B + '/gestion');
  verifier(enAttente > 0 && +(await patrick.locator('.modules-garanties').getAttribute('data-garanties')) === enAttente, 'accueil : nombre de garanties en attente', enAttente);
  await Promise.all([patrick.waitForNavigation(), patrick.click('.modules-garanties a')]);
  verifier(await patrick.locator('li.det-garantie').count() === enAttente, 'garanties : liste de Patrick');
  const g1 = await patrick.locator('li.det-garantie').first().getAttribute('data-dette');
  const servi1 = un(`select servi_par from dettes where id=${g1}`);
  verifier(un(`select responsable_id from dettes where id=${g1}`) === servi1, 'avant réponse : le serveur est responsable');
  await Promise.all([patrick.waitForNavigation(), patrick.click(`li.det-garantie[data-dette="${g1}"] button[value=accepter]`)]);
  verifier(un(`select concat(garant_statut,'|',responsable_id) from dettes where id=${g1}`) === `accepte|${patrick_id}`, 'accepter : le garant devient responsable');
  verifier((await patrick.locator('.alerte').first().textContent()).includes('garant'), 'accepter : message');
  verifier((await poster(patrick, '/gestion/garanties/' + g1, { reponse: 'refuser' })).status === 409, 'répondre deux fois → 409');
  const autreG = un(`select id from dettes where garant_statut='en_attente' and statut='ouverte' and garant_id<>${patrick_id} limit 1`);
  if (autreG) {
    verifier((await poster(patrick, '/gestion/garanties/' + autreG, { reponse: 'accepter' })).status === 409 && un(`select garant_statut from dettes where id=${autreG}`) === 'en_attente', 'répondre pour un autre garant → 409');
  }
  verifier((await poster(patrick, '/gestion/garanties/' + g1, { reponse: 'peut-etre' })).status === 422, 'réponse inconnue → 422');
  const g2 = un(`select id from dettes where garant_id=${patrick_id} and garant_statut='en_attente' and statut='ouverte' limit 1`);
  if (g2) {
    r = await poster(patrick, '/gestion/garanties/' + g2, { reponse: 'refuser' });
    verifier(r.status === 302 && un(`select concat(garant_statut,'|',responsable_id) from dettes where id=${g2}`) === `refuse|${un(`select servi_par from dettes where id=${g2}`)}`, 'refuser : le serveur reste responsable');
  }
  const riziki_id = un("select id from utilisateurs where email='riziki@demo.test'");
  if (+un(`select count(*) from dettes where garant_id=${riziki_id} and garant_statut='en_attente' and statut='ouverte'`) > 0) {
    await riziki.goto(B + '/gestion');
    verifier(await riziki.locator('.modules-garanties').count() === 1, 'accueil Riziki : garanties en attente');
  }
  await grace.goto(B + '/gestion');
  verifier(await grace.locator('.modules-garanties').count() === 0, 'accueil Grace : aucune garantie');

  // ---------- Messagerie (administrateur) ----------
  await admin.goto(B + '/gestion/messagerie');
  const champsCles = await admin.locator('input[name^="sms_"], input[name^="whatsapp_"], input[type=password]').count();
  verifier(champsCles === 0, 'messagerie : aucun champ de clé d\'API (réglées par la plateforme)', champsCles);
  verifier((await admin.locator('[data-etat="sms"]').textContent()).includes('Non réglé par la plateforme'), 'état SMS : non réglé');
  verifier((await admin.locator('[data-etat="whatsapp"]').textContent()).includes('Non réglé'), 'état WhatsApp : non réglé');
  verifier(await admin.locator('[data-passage-retard]').count() === 1 && (await admin.locator('[data-dernier-passage]').textContent()).includes('jamais'), 'dernier passage : jamais (avertissement)');
  verifier((await admin.locator('.det-code').textContent()).includes('5 * * * * php ') && (await admin.locator('.det-code').textContent()).includes('bin/relances.php --toutes'), 'ligne cron affichée');
  r = await poster(admin, '/gestion/messagerie', { relances_actives: '1', relances_etapes: 'a,b', relances_heure: '25', relances_canal: 'sms' });
  verifier(r.status === 422 && r.texte.includes('erreur-relances_etapes') && r.texte.includes('erreur-relances_heure'), 'étapes et heure invalides → 422');
  verifier(un("select valeur from parametres where cle='relances.etapes'") === '-1,1,7,30', 'réglages invalides : rien enregistré');
  await admin.goto(B + '/gestion/messagerie');
  await admin.fill('#relances_etapes', ' 7, −1 ,3, 3');
  await admin.selectOption('#relances_heure', '9');
  await admin.check('input[name=relances_canal][value=whatsapp]');
  await admin.uncheck('input[name=relances_responsable]');
  await Promise.all([admin.waitForNavigation(), admin.click('form.tre-formulaire button[type=submit]')]);
  verifier(un("select group_concat(concat(cle,'=',valeur) order by cle separator ';') from parametres where cle in ('relances.etapes','relances.heure','relances.canal','relances.responsable','relances.actives')") === 'relances.actives=1;relances.canal=whatsapp;relances.etapes=-1,3,7;relances.heure=9;relances.responsable=0', 'réglages des relances enregistrés et normalisés');
  verifier(+un("select count(*) from parametres where cle like 'sms.%' or cle like 'whatsapp.%'") === 0, 'aucun réglage sms.* ni whatsapp.* écrit par l\'instance');
  sql("insert into parametres (cle, valeur) values ('relances.dernier_passage', now()) on duplicate key update valeur = now()");
  await admin.goto(B + '/gestion/messagerie');
  verifier(await admin.locator('[data-passage-retard]').count() === 0, 'dernier passage récent : pas d\'avertissement');
  sql("update parametres set valeur = now() - interval 3 hour where cle='relances.dernier_passage'");
  await admin.goto(B + '/gestion/messagerie');
  verifier(await admin.locator('[data-passage-retard]').count() === 1, 'dernier passage il y a 3 h : avertissement');
  // Message d'essai : numéro invalide, puis sans canal (inscrit), puis par le faux Africa's Talking
  r = await poster(admin, '/gestion/messagerie/essai', { telephone: '12' });
  verifier(r.status === 422 && r.texte.includes('erreur-essai'), 'essai : numéro invalide → 422');
  await admin.goto(B + '/gestion/messagerie');
  await admin.fill('#essai-telephone', '0970 555 444');
  await Promise.all([admin.waitForNavigation(), admin.click('#essai button[type=submit]')]);
  verifier((await admin.locator('.alerte').first().textContent()).includes('non envoyé'), 'essai sans canal : inscrit, non envoyé');
  verifier(un("select concat(statut,'|',evenement) from messages where destinataire='+243970555444' order by id desc limit 1") === 'simule|test', 'essai : journal (simulé)');
  // Les clés sont celles de la plateforme : des réglages sms.* écrits dans l'instance ne rendent pas le canal prêt
  sql(`insert into parametres (cle, valeur) values ('sms.utilisateur','sandbox'),('sms.cle','cle-essai-1234'),('sms.url','${MOCK}/sms')`);
  await admin.goto(B + '/gestion/messagerie');
  verifier((await admin.locator('[data-etat="sms"]').textContent()).includes('Non réglé par la plateforme'), 'clés de l\'instance ignorées : SMS non réglé');
  verifier(!(await admin.content()).includes('cle-essai-1234'), 'aucune clé affichée');
  r = await poster(admin, '/gestion/messagerie/essai', { telephone: '+243 970 555 444' });
  verifier(r.status === 302 && un("select statut from messages where destinataire='+243970555444' order by id desc limit 1") === 'simule', 'essai : toujours inscrit sans envoi');
  verifier(!fs.existsSync(DIR + '/mock-e2e.log') || !fs.readFileSync(DIR + '/mock-e2e.log', 'utf8').includes('555444'), 'rien n\'est parti vers le service de l\'instance');
  sql("delete from parametres where cle like 'sms.%'");
  // Journal : filtres, libellé « inscrit, non envoyé »
  await admin.goto(B + '/gestion/messagerie/journal?periode=tout&statut=simule');
  verifier(await admin.locator('.det-message').count() === Math.min(50, +un("select count(*) from messages where statut='simule'")), 'journal : filtre non envoyés');
  verifier((await admin.locator('.det-message .badge').first().textContent()).includes('Inscrit, non envoyé (messagerie non réglée)'), 'journal : libellé du statut simulé');
  verifier(+(await admin.locator('[data-compte="envoye"]').textContent()) === +un("select count(*) from messages where statut='envoye'"), 'journal : compteur envoyés');
  await admin.goto(B + '/gestion/messagerie/journal?periode=tout&evenement=garant_demande&canal=sms');
  verifier(await admin.locator('.det-message').count() === Math.min(50, +un("select count(*) from messages where evenement='garant_demande' and canal='sms'")), 'journal : filtre événement et canal');
  await admin.goto(B + '/gestion/messagerie/journal?periode=jour');
  verifier(await admin.locator('.det-message').count() === +un("select count(*) from messages where date(cree_le)=curdate()"), 'journal : période du jour');

  // ---------- Rapport des créances ----------
  await admin.goto(B + '/gestion/rapports/creances');
  verifier(proche(nombre(await admin.locator(`[data-total="devise-${CDF}"]`).textContent()), +un(`select sum(reste) from dettes where statut='ouverte' and reste>0 and devise_id=${CDF}`)), 'rapport : total CDF');
  const ref = +un(`select sum(reste) from dettes where statut='ouverte' and reste>0 and devise_id=${USD}`) + +un(`select sum(reste) from dettes where statut='ouverte' and reste>0 and devise_id=${CDF}`) / taux;
  verifier(proche(nombre(await admin.locator('[data-total="reference"]').textContent()), Math.round(ref * 100) / 100, 0.02), 'rapport : équivalent en dollars au taux du jour', ref);
  const nbResp = await admin.locator('[data-groupe="responsable"] td[data-libelle]').allTextContents();
  verifier(nbResp.reduce((s, t) => s + +t, 0) === +un("select count(*) from dettes where statut='ouverte' and reste>0"), 'rapport : par responsable, toutes les dettes');
  const premierResp = await admin.locator('[data-groupe="responsable"] tbody tr').first().getAttribute('data-ligne');
  verifier(premierResp !== null, 'rapport : responsable en tête');
  verifier(await admin.locator('[data-groupe="client"] tbody tr').count() <= 10, 'rapport : dix clients au plus');
  verifier(+(await admin.locator('[data-garanties-attente]').getAttribute('data-garanties-attente')) === +un("select count(*) from dettes where statut='ouverte' and reste>0 and garant_statut='en_attente'"), 'rapport : garanties en attente');
  for (const [tranche, cond] of [['0_30', '<= 30'], ['31_60', 'between 31 and 60'], ['plus_60', '> 60']]) {
    const n = +un(`select count(*) from dettes where statut='ouverte' and reste>0 and datediff('${aujourdhui}', date(cree_le)) ${cond}`);
    verifier((await admin.locator(`li[data-tranche="${tranche}"]`).textContent()).includes(n + (n > 1 ? ' dettes' : ' dette')), 'rapport : tranche ' + tranche, n);
  }
  verifier(await statut(grace, '/gestion/rapports/creances?etablissement=2') === 200 && proche(nombre(await grace.locator(`[data-total="devise-${CDF}"]`).textContent()), +un(`select sum(reste) from dettes where statut='ouverte' and reste>0 and devise_id=${CDF} and etablissement_id=2`)), 'rapport : filtre établissement');

  // ---------- Établissement : délai de paiement d'une ardoise ----------
  await admin.goto(B + '/gestion/etablissements/1#prix');
  verifier(await admin.locator('#etb-credit-delai').inputValue() === '7', 'établissement : délai affiché');
  verifier(await admin.locator('#etb-credit-delai').getAttribute('max') === '365', 'établissement : délai de 365 jours au plus (navigateur)');
  const champsPrix = Object.fromEntries(await admin.locator('#prix form').evaluate(f => [...new FormData(f).entries()]));
  const devisesEtab = await admin.locator('#prix input[name="devises[]"]:checked').evaluateAll(es => es.map(e => e.value));
  const formPrix = new URLSearchParams({ ...champsPrix, credit_delai_jours: '400' });
  formPrix.delete('devises[]');
  devisesEtab.forEach(d => formPrix.append('devises[]', d));
  const repPrix = await admin.request.post(B + '/gestion/etablissements/1/prix', { data: formPrix.toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, maxRedirects: 0 });
  verifier(repPrix.status() === 422 && (await repPrix.text()).includes('etb-credit-delai-erreur') && un('select credit_delai_jours from etablissements where id=1') === '7', 'délai de 400 jours refusé (serveur)', repPrix.status());
  await admin.fill('#etb-credit-delai', '10');
  await Promise.all([admin.waitForNavigation(), admin.click('#prix button[type=submit]')]);
  verifier(un('select credit_delai_jours from etablissements where id=1') === '10', 'délai enregistré');
  sql('update etablissements set credit_delai_jours=7 where id=1');

  // ---------- Fiche d'une vente : part à crédit ----------
  const venteCredit = un("select d.vente_id from dettes d join ventes v on v.id=d.vente_id where v.etablissement_id=1 and d.statut='ouverte' limit 1");
  const detteVente = un(`select id from dettes where vente_id=${venteCredit}`);
  await patrick.goto(B + '/gestion/ventes/' + venteCredit);
  verifier(await patrick.locator('[data-credits]').count() === 1, 'vente : section « À crédit »');
  verifier(await patrick.locator(`[data-credits] a[href="/gestion/dettes/${detteVente}"]`).count() === 1, 'vente : lien vers la dette');
  verifier(await patrick.locator(`[data-credits] a[href="/gestion/clients/${un(`select client_id from dettes where id=${detteVente}`)}"]`).count() === 1, 'vente : lien vers le client');

  // ---------- Langues, thèmes, téléphone, cibles tactiles, captures ----------
  const pages = [
    ['clients', '/gestion/clients'], ['client', '/gestion/clients/' + clientR], ['client-form', `/gestion/clients/${nouveau}/modifier`],
    ['dettes', '/gestion/dettes'], ['dette', '/gestion/dettes/' + echue], ['dette-personnel', '/gestion/dettes/' + nouvelleDette],
    ['remboursements', '/gestion/remboursements?periode=tout'], ['remboursement', '/gestion/remboursements/' + rembId],
    ['remboursement-choisir', '/gestion/remboursements/nouveau?q=ka'], ['remboursement-nouveau', '/gestion/remboursements/nouveau?client=' + clientR],
    ['garanties', '/gestion/garanties'], ['messagerie', '/gestion/messagerie'], ['journal', '/gestion/messagerie/journal?periode=tout'],
    ['creances', '/gestion/rapports/creances'], ['accueil', '/gestion'],
  ];
  // Une garantie en attente pour l'administrateur, pour voir l'écran rempli
  sql(`update dettes set garant_id=1, garant_statut='en_attente', garant_repondu_le=null where id=(select id from (select id from dettes where statut='ouverte' and garant_id is null limit 1) x)`);
  for (const [vue, taille] of [['bureau', { width: 1366, height: 860 }], ['tel', { width: 390, height: 844 }]]) {
    const p = await connecter(navigateur, ADMIN, { viewport: taille, ...(vue === 'tel' ? { isMobile: true, hasTouch: true, deviceScaleFactor: 2 } : {}) });
    const petites = new Set();
    for (const langue of ['fr', 'en']) {
      for (const theme of ['jour', 'nuit']) {
        await p.goto(B + '/gestion?lang=' + langue);
        await p.evaluate(t => localStorage.setItem('gestion.theme', t), theme);
        for (const [nom, url] of pages) {
          const rep = await p.goto(B + url);
          verifier(rep.status() === 200, `${vue} ${langue} ${theme} ${nom} : 200`, rep.status());
          verifier(await p.evaluate(() => document.documentElement.dataset.theme) === theme, `${vue} ${theme} ${nom} : thème`);
          const d = await debordement(p);
          verifier(d <= 0, `${vue} ${langue} ${theme} ${nom} : pas de débordement`, d);
          const cles = await clesBrutes(p);
          verifier(cles.length === 0, `${vue} ${langue} ${nom} : pas de clé brute`, cles);
          if (theme === 'jour' && langue === 'fr') {
            // Cibles tactiles : tout élément interactif qui n'est pas un lien dans une phrase
            const liste = await p.evaluate(() => [...document.querySelectorAll('main a, main button, main select, main input:not([type=hidden]), main summary, main label.coche')]
              .filter(e => e.offsetParent !== null && getComputedStyle(e).display !== 'inline' && !e.closest('label.coche, .tre-moyen') && e.type !== 'checkbox' && e.type !== 'radio')
              .filter(e => e.getBoundingClientRect().height < 43.5)
              .map(e => (e.className || e.tagName) + ':' + Math.round(e.getBoundingClientRect().height)));
            liste.forEach(x => petites.add(nom + ' ' + x));
          }
          if (langue === 'fr' || ['dettes', 'creances', 'messagerie'].includes(nom)) {
            await p.screenshot({ path: `${DIR}/ecran-${nom}-${vue}-${theme}${langue === 'en' ? '-en' : ''}.png`, fullPage: true });
          }
        }
      }
    }
    verifier(petites.size === 0, `${vue} : cibles de 44 px`, [...petites].slice(0, 15));
    verifier(p.erreurs.length === 0, `${vue} : aucune erreur JS`, p.erreurs);
  }
  // Textes anglais
  await admin.goto(B + '/gestion/dettes?lang=en');
  verifier((await admin.locator('h1').textContent()).includes('Debts'), 'EN : Debts');
  await admin.goto(B + '/gestion/rapports/creances');
  verifier((await admin.locator('h1').textContent()).includes('Receivables'), 'EN : Receivables');
  await admin.goto(B + '/gestion/messagerie/journal?periode=tout&statut=simule');
  verifier((await admin.locator('.det-message .badge').first().textContent()).includes('Logged, not sent (messaging not set up)'), 'EN : statut simulé');
  await admin.goto(B + '/gestion?lang=fr');
  for (const p of [admin, patrick, grace, riziki]) verifier(p.erreurs.length === 0, 'erreurs JS', p.erreurs);

  await navigateur.close();
  mock.kill();
  restaurer();
  console.log(`\n${ok} réussis, ${ko} échecs`);
  if (echecs.length) console.log(echecs.join('\n'));
  process.exit(ko ? 1 : 0);
})().catch(e => { console.error(e); try { restaurer(); } catch {} process.exit(2); });
