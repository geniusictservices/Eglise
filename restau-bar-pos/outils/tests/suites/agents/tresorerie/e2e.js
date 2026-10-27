// Test de bout en bout du module Trésorerie, comptes à plusieurs devises : un compte tient une ou plusieurs
// devises (solde par devise), devise ajoutée plus tard, devise retirée (refusée si elle a des mouvements),
// transferts (même devise, change dans une même caisse, deux comptes dans deux devises, avec taux),
// dépense payée depuis un compte dans deux devises, découvert par (compte, devise), erreurs 422, CSRF,
// FR/EN, jour/nuit, ordinateur 1366×860 et téléphone 390×844 sans défilement horizontal, aucune erreur JavaScript.
// La base est remise dans son état d'avant (avant-tests.sql) par lancer-tests.sh.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');

const B = 'http://integ.localhost:8250';
const CAPTURES = __dirname + '/multidevises';
const MDP = fs.readFileSync(__dirname + '/../../instance.txt', 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
const sql = (q) => execSync(`mysql -u root --default-character-set=utf8mb4 rbp_integ -N -B -e ${JSON.stringify("SET time_zone = '+02:00'; " + q)}`, { encoding: 'utf8' }).trim();
const ESPACES = /[  ]/g;
const net = (t) => (t || '').replace(ESPACES, ' ').replace(/\s+/g, ' ').trim();
const CLE_BRUTE = /\b(tresorerie|transferts|depenses|categories_depenses|gestion|devises)\.[a-z][a-z0-9_]+\b/;
const DEV = { USD: 1, CDF: 2 };

let ok = 0, echecs = 0;
function verifier(condition, message) {
  console.log((condition ? 'OK   ' : 'ECHEC') + ' ' + message);
  if (condition) ok++; else echecs++;
}
// Solde d'un compte dans une devise, depuis le journal
const solde = (nom, code) => parseFloat(sql(`SELECT COALESCE(SUM(IF(m.sens='entree', m.montant, -m.montant)), 0) FROM comptes_tresorerie c LEFT JOIN mouvements_tresorerie m ON m.compte_id = c.id AND m.devise_id = ${DEV[code]} WHERE c.nom = '${nom}'`));
const idCompte = (nom) => sql(`SELECT id FROM comptes_tresorerie WHERE nom = '${nom}'`);
// Montant au format français de l'application : « 1 864 500 FC », « $5 164,10 », « −$975 »
function fmt(n, code) {
  const neg = n < -0.0005;
  const a = Math.abs(n);
  const entier = Math.abs(a - Math.round(a)) < 0.005;
  const [e, d] = (entier ? Math.round(a).toFixed(0) : a.toFixed(2)).split('.');
  const nombre = e.replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + (d ? ',' + d : '');
  return (neg ? '−' : '') + (code === 'USD' ? '$' + nombre : nombre + ' FC');
}

async function connecter(p, email = 'admin@demo.test', mdp = MDP) {
  await p.goto(B + '/gestion/connexion?lang=fr');
  await p.fill('input[name=email]', email);
  await p.fill('input[name=mot_de_passe]', mdp);
  await Promise.all([p.waitForNavigation(), p.click('.connexion button[type=submit]')]);
}
async function envoyer(p, selecteur) {
  const [reponse] = await Promise.all([p.waitForNavigation(), p.click(selecteur)]);
  return reponse;
}
const texte = async (p, sel = 'main') => net(await p.textContent(sel));

(async () => {
  const b = await chromium.launch();
  const c = await b.newContext({ viewport: { width: 1366, height: 860 }, locale: 'fr-FR' });
  const p = await c.newPage();
  const erreursJs = [];
  p.on('pageerror', (e) => erreursJs.push(e.message));
  // Les réponses 419/422 attendues apparaissent comme « Failed to load resource » : ce ne sont pas des erreurs JS
  p.on('console', (m) => { if (m.type() === 'error' && !/status of (419|422)/.test(m.text())) erreursJs.push(m.text()); });

  try {
    // ---------- Accès ----------
    const anonyme = await b.newPage();
    await anonyme.goto(B + '/gestion/tresorerie');
    verifier(anonyme.url().endsWith('/gestion/connexion'), 'sans connexion : renvoi vers la page de connexion');
    await anonyme.close();

    await connecter(p);
    await p.goto(B + '/gestion/depenses');
    verifier((await p.locator('.lateral__lien[aria-current="page"]').textContent()).trim() === 'Dépenses' && (await p.locator('.lateral__titre').textContent()).trim() === 'Finance', 'menu : module Finance, écran « Dépenses » marqué');

    // ---------- Vue d'ensemble : types, comptes à plusieurs devises, totaux par devise ----------
    await p.goto(B + '/gestion/tresorerie');
    const chiffres = await p.locator('.tre-totaux .chiffre__libelle').allTextContents();
    verifier(/CDF/.test(chiffres[0]) && /USD/.test(chiffres[1]), 'totaux : devise locale (CDF) en premier, dollar en second');
    let t = await texte(p);
    const totalCdf = parseFloat(sql("SELECT SUM(IF(m.sens='entree', m.montant, -m.montant)) FROM mouvements_tresorerie m JOIN comptes_tresorerie c ON c.id = m.compte_id WHERE c.active = 1 AND m.devise_id = 2"));
    const totalUsd = parseFloat(sql("SELECT SUM(IF(m.sens='entree', m.montant, -m.montant)) FROM mouvements_tresorerie m JOIN comptes_tresorerie c ON c.id = m.compte_id WHERE c.active = 1 AND m.devise_id = 1"));
    verifier(t.includes(fmt(totalCdf, 'CDF')) && t.includes(fmt(totalUsd, 'USD')), `totaux = journal : ${fmt(totalCdf, 'CDF')} et ${fmt(totalUsd, 'USD')}`);
    verifier(t.includes('Total général en CDF, au taux du jour') && /≈ \$[\d ]+,\d\d/.test(t), 'total général en devise locale d\'abord, équivalent en dollars ensuite');
    const groupes = await p.locator('.tre-groupe h2').allTextContents();
    verifier(groupes.map(net).join('|') === 'Caisse|Mobile money|Banque', 'comptes groupés par type : Caisse, Mobile money, Banque');
    verifier(!/Espèces/.test(t), 'plus de type « Espèces »');
    const cartes = await p.locator('.tre-compte').evaluateAll((liens) => liens.map((a) => [a.querySelector('.tre-compte__nom').textContent.trim(), [...a.querySelectorAll('.tre-compte__solde')].map((s) => [s.dataset.devise, s.textContent.replace(/[\s\u00a0\u202f]+/g, ' ').trim().replace(/^(CDF|USD) :\s*/, '')])]));
    const carte = Object.fromEntries(cartes);
    // 7e compte de la démo : « Caisse cuisine (chef) », fonds du marché (bin/demo-stock-suite.php), position 3 des caisses
    verifier(cartes.length === 7 && JSON.stringify(cartes.map((x) => x[0])) === JSON.stringify(['Caisse du Comptoir', 'Caisse du Club', 'Caisse cuisine (chef)', 'M-Pesa', 'Airtel Money', 'Rawbank', 'Equity BCDC']), 'sept comptes de démonstration, dans l\'ordre des types et positions');
    verifier(JSON.stringify(carte['Caisse du Comptoir'].map((x) => x[0])) === '["CDF","USD"]' && JSON.stringify(carte['Airtel Money'].map((x) => x[0])) === '["CDF"]' && JSON.stringify(carte['Rawbank'].map((x) => x[0])) === '["USD"]', 'carte d\'un compte : un solde par devise, CDF d\'abord, USD ensuite');
    const soldesOk = Object.entries(carte).every(([nom, liste]) => liste.every(([code, v]) => v === fmt(solde(nom, code), code)));
    verifier(soldesOk, 'vue d\'ensemble : chaque solde par devise = journal (' + carte['Caisse du Comptoir'].map((x) => x[1]).join(' · ') + ')');

    // ---------- Création d'un compte : erreurs puis succès ----------
    await p.goto(B + '/gestion/tresorerie/comptes/nouveau');
    verifier(await p.isChecked('input[name="devises[2][tenue]"]') && !(await p.isChecked('input[name="devises[1][tenue]"]')), 'nouveau compte : devise locale (CDF) cochée d\'office');
    verifier(await p.locator('#devise-1-solde').isHidden(), 'nouveau compte : solde initial d\'une devise non cochée masqué');
    await p.check('input[name=type][value=mobile_money]', { force: true });
    await p.fill('#nom', '');
    await p.fill('#devise-2-solde', '12,5');
    let r = await envoyer(p, '#formulaire-compte button[type=submit]');
    t = await texte(p);
    verifier(r.status() === 422, 'compte invalide : réponse 422');
    verifier(t.includes('Donnez un nom au compte') && t.includes("Solde initial en CDF : Les montants en CDF n'ont pas de décimales"), 'compte invalide : nom manquant et décimales en CDF refusés');
    verifier(await p.getAttribute('#nom', 'aria-invalid') === 'true' && await p.getAttribute('#devise-2-solde', 'aria-invalid') === 'true', 'compte invalide : champs marqués aria-invalid');
    await p.uncheck('input[name="devises[2][tenue]"]');
    await p.fill('#nom', 'Caisse du Club');
    r = await envoyer(p, '#formulaire-compte button[type=submit]');
    t = await texte(p);
    verifier(r.status() === 422 && t.includes('Un compte s\'appelle déjà « Caisse du Club »') && t.includes('Cochez au moins une devise'), 'compte : nom déjà pris et aucune devise refusés (422)');
    await p.check('input[name="devises[2][tenue]"]');
    await p.fill('#devise-2-solde', '100000');
    await p.fill('#nom', 'Orange Money test');
    await p.fill('#operateur', 'Orange Money');
    await p.fill('#numero', '+243 89 000 0000');
    r = await envoyer(p, '#formulaire-compte button[type=submit]');
    t = await texte(p);
    verifier(r.status() === 200 && /\/gestion\/tresorerie\/comptes\/\d+$/.test(p.url()), 'compte créé : fiche du compte affichée');
    verifier(t.includes('Compte « Orange Money test » créé') && net(await p.textContent('.tre-solde[data-devise=CDF] .tre-solde__valeur')) === '100 000 FC' && await p.locator('.tre-solde').count() === 1, 'compte créé avec une devise : solde initial 100 000 FC');
    verifier(sql("SELECT COUNT(*) FROM mouvements_tresorerie m JOIN comptes_tresorerie c ON c.id = m.compte_id WHERE c.nom = 'Orange Money test' AND m.nature = 'solde_initial' AND m.devise_id = 2 AND m.montant = 100000 AND m.contre_valeur = 35.09") === '1', 'compte créé : ligne « solde initial » en CDF au journal, contre-valeur 35,09 $');
    const orange = idCompte('Orange Money test');

    // ---------- Ajouter une devise plus tard ----------
    await p.goto(B + `/gestion/tresorerie/comptes/${orange}/modifier`);
    await p.check('input[name="devises[1][tenue]"]');
    verifier(await p.locator('#devise-1-solde').isVisible(), 'devise cochée : son solde initial apparaît');
    await p.fill('#devise-1-solde', '25');
    await envoyer(p, '#formulaire-compte button[type=submit]');
    t = await texte(p);
    verifier(t.includes('Compte « Orange Money test » enregistré') && net(await p.textContent('.tre-solde[data-devise=USD] .tre-solde__valeur')) === '$25' && net(await p.textContent('.tre-solde[data-devise=CDF] .tre-solde__valeur')) === '100 000 FC', 'USD ajouté plus tard : soldes 100 000 FC et $25');
    verifier(sql(`SELECT GROUP_CONCAT(CONCAT(devise_id, ':', ROUND(solde_initial)) ORDER BY devise_id) FROM compte_devises WHERE compte_id = ${orange}`) === '1:25,2:100000' && sql(`SELECT COUNT(*) FROM mouvements_tresorerie WHERE compte_id = ${orange} AND nature = 'solde_initial' AND devise_id = 1 AND montant = 25`) === '1', 'devise ajoutée : compte_devises et ligne « solde initial » en USD au journal');
    // Retirer une devise sans mouvement (hors solde initial) : permis, sa ligne d'ouverture disparaît
    await p.goto(B + `/gestion/tresorerie/comptes/${orange}/modifier`);
    await p.uncheck('input[name="devises[1][tenue]"]');
    await envoyer(p, '#formulaire-compte button[type=submit]');
    verifier(sql(`SELECT COUNT(*) FROM compte_devises WHERE compte_id = ${orange}`) === '1' && sql(`SELECT COUNT(*) FROM mouvements_tresorerie WHERE compte_id = ${orange} AND devise_id = 1`) === '0', 'devise sans mouvement retirée : plus tenue, ligne d\'ouverture supprimée');
    await p.goto(B + `/gestion/tresorerie/comptes/${orange}/modifier`);
    await p.check('input[name="devises[1][tenue]"]');
    await p.fill('#devise-1-solde', '25');
    await envoyer(p, '#formulaire-compte button[type=submit]');
    verifier(solde('Orange Money test', 'USD') === 25, 'USD remis avec 25 $');

    // Retirer une devise qui a des mouvements : refusé (422), la case est verrouillée
    const comptoir = idCompte('Caisse du Comptoir');
    await p.goto(B + `/gestion/tresorerie/comptes/${comptoir}/modifier`);
    verifier(await p.isDisabled('input[type=checkbox][name="devises[1][tenue]"]') && (await texte(p, '#devises')).includes('Cette devise a déjà des mouvements'), 'devise avec mouvements : case verrouillée et expliquée');
    const jetonCompte = await p.getAttribute('#formulaire-compte input[name=csrf]', 'value');
    let rep = await p.request.post(B + `/gestion/tresorerie/comptes/${comptoir}/modifier`, { form: {
      csrf: jetonCompte, nom: 'Caisse du Comptoir', type: 'caisse', etablissement_id: '1', solde_initial_le: '2026-10-01', position: '1',
      'devises[2][tenue]': '1', 'devises[2][solde_initial]': '1850000', 'devises[1][solde_initial]': '1450',
    } });
    let html = net(await rep.text());
    verifier(rep.status() === 422 && html.includes('« Caisse du Comptoir » a déjà des mouvements en USD : cette devise ne peut pas être retirée'), 'retirer USD de « Caisse du Comptoir » (avec mouvements) refusé (422)');
    verifier(sql(`SELECT COUNT(*) FROM compte_devises WHERE compte_id = ${comptoir}`) === '2', 'refus : le compte tient toujours ses deux devises');

    // ---------- Transfert dans la même devise ----------
    const comptoirCdf = solde('Caisse du Comptoir', 'CDF');
    await p.goto(B + '/gestion/tresorerie/transferts');
    await p.selectOption('#compte_source_id', comptoir);
    verifier(JSON.stringify(await p.locator('#devise_source_id option').allTextContents()) === '["CDF","USD"]', 'transfert : devises proposées = celles du compte source (CDF, USD)');
    await p.selectOption('#devise_source_id', '2');
    await p.selectOption('#compte_destination_id', orange);
    await p.selectOption('#devise_destination_id', '2');
    verifier(await p.locator('[data-bloc-destination]').isHidden(), 'transfert même devise : montant reçu masqué');
    await p.fill('#montant_source', '50 000');
    verifier(net(await p.textContent('[data-solde="source"]')) === 'Solde : ' + fmt(comptoirCdf, 'CDF'), 'transfert : solde de la devise choisie affiché');
    await p.fill('#motif', 'Test même devise');
    await envoyer(p, '#formulaire-transfert button[type=submit]');
    verifier((await texte(p)).includes('Transfert de 50 000 FC enregistré'), 'transfert même devise enregistré');
    verifier(solde('Caisse du Comptoir', 'CDF') === comptoirCdf - 50000 && solde('Orange Money test', 'CDF') === 150000, 'transfert même devise : soldes CDF −50 000 / +50 000');
    verifier(sql("SELECT COUNT(*) FROM mouvements_tresorerie m JOIN transferts_tresorerie t ON t.id = m.source_id AND m.nature = 'transfert' WHERE t.motif = 'Test même devise' AND m.devise_id = 2") === '2', 'transfert : deux lignes au journal, en CDF');

    // ---------- Change dans une même caisse : 100 $ → FC dans « Caisse du Club » ----------
    const club = idCompte('Caisse du Club');
    const clubUsd = solde('Caisse du Club', 'USD'), clubCdf = solde('Caisse du Club', 'CDF');
    await p.goto(B + '/gestion/tresorerie/transferts');
    await p.selectOption('#compte_source_id', club);
    await p.selectOption('#devise_source_id', '1');
    await p.selectOption('#compte_destination_id', club);
    verifier(await p.inputValue('#devise_destination_id') === '2', 'change : même compte en destination → l\'autre devise (CDF) choisie d\'office');
    await p.fill('#montant_source', '100');
    verifier(await p.inputValue('#montant_destination') === '285000', 'change : montant reçu proposé 285 000 FC au taux du jour');
    verifier(net(await p.textContent('[data-proposition]')).includes('1 USD = 2 850 CDF'), 'change : taux du jour affiché');
    await p.fill('#montant_destination', '284000');
    verifier(net(await p.textContent('[data-taux-applique]')) === 'Taux appliqué : 1 USD = 2 840 CDF', 'change : taux appliqué recalculé (2 840)');
    verifier(await p.locator('[data-reprendre]').isVisible(), 'change : bouton « Reprendre le montant proposé »');
    await p.fill('#motif', 'Test change caisse');
    await envoyer(p, '#formulaire-transfert button[type=submit]');
    verifier((await texte(p)).includes('Change de $100 en 284 000 FC enregistré dans « Caisse du Club »'), 'change dans la caisse enregistré');
    const tr = sql("SELECT CONCAT_WS('|', compte_source_id, compte_destination_id, devise_source_id, devise_destination_id, montant_source, montant_destination, taux) FROM transferts_tresorerie WHERE motif = 'Test change caisse'");
    verifier(tr === `${club}|${club}|1|2|100.000|284000.000|2840.000000`, 'change : même compte, USD → CDF, taux appliqué 2 840 enregistré');
    verifier(sql("SELECT contre_valeur FROM mouvements_tresorerie m JOIN transferts_tresorerie t ON t.id = m.source_id AND m.nature = 'transfert' WHERE t.motif = 'Test change caisse' AND m.sens = 'entree'") === '99.650', 'change : contre-valeur du côté CDF au taux du jour (99,65 $)');
    verifier(solde('Caisse du Club', 'USD') === clubUsd - 100 && solde('Caisse du Club', 'CDF') === clubCdf + 284000, 'change : Caisse du Club −$100 / +284 000 FC');
    t = await texte(p, '#liste');
    verifier(t.includes('Change USD → CDF') && t.includes('1 USD = 2 840 CDF'), 'liste des transferts : change repéré, taux appliqué affiché');
    await p.goto(B + `/gestion/tresorerie/comptes/${club}?periode=jour`);
    verifier((await texte(p, '.tre-journal')).includes('Change USD → CDF'), 'fiche du compte : ligne « Change USD → CDF »');

    // ---------- Transfert entre deux comptes dans deux devises : Rawbank USD → Equity BCDC CDF ----------
    const raw = solde('Rawbank', 'USD'), equity = solde('Equity BCDC', 'CDF');
    await p.goto(B + '/gestion/tresorerie/transferts');
    await p.selectOption('#compte_source_id', idCompte('Rawbank'));
    verifier(JSON.stringify(await p.locator('#devise_source_id option').allTextContents()) === '["USD"]', 'Rawbank : seule la devise USD est proposée');
    await p.selectOption('#compte_destination_id', idCompte('Equity BCDC'));
    await p.selectOption('#devise_destination_id', '2');
    await p.fill('#montant_source', '200');
    verifier(await p.inputValue('#montant_destination') === '570000', 'Rawbank USD → Equity CDF : 570 000 FC proposés');
    await p.fill('#motif', 'Test deux comptes deux devises');
    await envoyer(p, '#formulaire-transfert button[type=submit]');
    verifier(solde('Rawbank', 'USD') === raw - 200 && solde('Equity BCDC', 'CDF') === equity + 570000 && sql("SELECT CONCAT(devise_source_id, devise_destination_id) FROM transferts_tresorerie WHERE motif = 'Test deux comptes deux devises'") === '12', 'Rawbank −$200, Equity BCDC +570 000 FC, devises enregistrées');

    // Sans JavaScript (montant reçu vide) : le serveur propose le montant
    const jeton = await p.getAttribute('#formulaire-transfert input[name=csrf]', 'value');
    rep = await p.request.post(B + '/gestion/tresorerie/transferts', { form: {
      csrf: jeton, compte_source_id: comptoir, devise_source_id: '1', compte_destination_id: comptoir, devise_destination_id: '2', montant_source: '10', montant_destination: '', date: '', motif: '',
    } });
    html = net(await rep.text());
    verifier(rep.status() === 200 && html.includes('Montant reçu proposé au taux du jour : 28 500 FC') && html.includes('value="28500"'), 'sans JS : montant reçu proposé par le serveur (28 500 FC)');
    // Taux très éloigné : confirmation demandée
    rep = await p.request.post(B + '/gestion/tresorerie/transferts', { form: {
      csrf: jeton, compte_source_id: comptoir, devise_source_id: '1', compte_destination_id: comptoir, devise_destination_id: '2', montant_source: '10', montant_destination: '2850000', date: '', motif: 'Faute de frappe',
    } });
    html = net(await rep.text());
    verifier(rep.status() === 200 && html.includes('est très loin du taux du jour') && sql("SELECT COUNT(*) FROM transferts_tresorerie WHERE motif = 'Faute de frappe'") === '0', 'taux aberrant : avertissement, rien enregistré sans confirmation');
    // Même compte et même devise : refusé
    rep = await p.request.post(B + '/gestion/tresorerie/transferts', { form: {
      csrf: jeton, compte_source_id: club, devise_source_id: '2', compte_destination_id: club, devise_destination_id: '2', montant_source: '0', date: '', motif: '',
    } });
    html = net(await rep.text());
    verifier(rep.status() === 422 && html.includes('Pour un change dans « Caisse du Club », choisissez une autre devise reçue') && html.includes('Indiquez un montant supérieur à 0'), 'transfert : même compte et même devise, montant 0 refusés (422)');
    // Devise que le compte ne tient pas
    rep = await p.request.post(B + '/gestion/tresorerie/transferts', { form: {
      csrf: jeton, compte_source_id: idCompte('Rawbank'), devise_source_id: '2', compte_destination_id: comptoir, devise_destination_id: '2', montant_source: '1000', date: '', motif: '',
    } });
    html = net(await rep.text());
    verifier(rep.status() === 422 && html.includes('« Rawbank » ne tient pas cette devise : choisissez parmi USD'), 'transfert : devise que le compte ne tient pas refusée (422)');
    // Montant non numérique
    await p.goto(B + '/gestion/tresorerie/transferts');
    await p.selectOption('#compte_source_id', orange);
    await p.selectOption('#devise_source_id', '1');
    await p.selectOption('#compte_destination_id', comptoir);
    await p.selectOption('#devise_destination_id', '1');
    await p.fill('#montant_source', 'beaucoup');
    r = await envoyer(p, '#formulaire-transfert button[type=submit]');
    verifier(r.status() === 422 && (await texte(p)).includes('« beaucoup » n\'est pas un montant'), 'transfert : montant non numérique refusé (422)');

    // Découvert par (compte, devise) : Orange USD n'a que 25 $, son solde CDF n'y change rien
    await p.fill('#montant_source', '1000');
    await p.fill('#motif', 'Test découvert');
    r = await envoyer(p, '#formulaire-transfert button[type=submit]');
    t = await texte(p);
    verifier(r.status() === 200 && t.includes('« Orange Money test » n\'a que $25 : après cette opération, il sera à −$975'), 'découvert en USD : avertissement avec le solde de la devise après opération');
    verifier(sql("SELECT COUNT(*) FROM transferts_tresorerie WHERE motif = 'Test découvert'") === '0', 'découvert : rien enregistré avant confirmation');
    await envoyer(p, '#avertissement button[name=confirme]');
    verifier(solde('Orange Money test', 'USD') === -975 && solde('Orange Money test', 'CDF') === 150000, 'découvert confirmé : −$975, le solde CDF ne bouge pas');
    await p.goto(B + '/gestion/tresorerie');
    const negatifs = await p.locator('.tre-compte__solde.tre-negatif').evaluateAll((l) => l.map((s) => s.dataset.devise));
    verifier(JSON.stringify(negatifs) === '["USD"]', 'vue d\'ensemble : seul le solde USD négatif est en rouge');
    await p.goto(B + `/gestion/tresorerie/comptes/${orange}`);
    verifier(await p.locator('.tre-solde[data-devise=USD] .tre-negatif').count() === 1 && await p.locator('.tre-solde[data-devise=CDF] .tre-negatif').count() === 0, 'fiche du compte : solde USD négatif en rouge, CDF normal');
    await p.goto(B + '/gestion/tresorerie/transferts');
    const idDecouvert = sql("SELECT id FROM transferts_tresorerie WHERE motif = 'Test découvert'");
    await p.click(`#transfert-${idDecouvert} summary`);
    await envoyer(p, `#transfert-${idDecouvert} button[type=submit]`);
    verifier(solde('Orange Money test', 'USD') === 25 && sql(`SELECT COUNT(*) FROM mouvements_tresorerie WHERE nature = 'transfert' AND source_id = ${idDecouvert}`) === '0', 'transfert annulé : lignes du journal supprimées, solde rétabli');

    // ---------- Dépense payée depuis un compte dans deux devises ----------
    const usd0 = solde('Caisse du Club', 'USD'), cdf0 = solde('Caisse du Club', 'CDF');
    await p.goto(B + '/gestion/depenses/nouvelle');
    verifier(await p.inputValue('#paiement-0-compte') === comptoir && await p.inputValue('#paiement-0-devise') === '2', 'nouvelle dépense : Caisse du Comptoir en CDF proposée');
    const groupesComptes = await p.locator('#paiement-0-compte optgroup').evaluateAll((g) => g.map((x) => x.label));
    verifier(JSON.stringify(groupesComptes) === '["Caisse","Mobile money","Banque"]', 'paiement : comptes groupés par type');
    await p.selectOption('#paiement-0-compte', idCompte('Airtel Money'));
    verifier(JSON.stringify(await p.locator('#paiement-0-devise option').allTextContents()) === '["CDF"]', 'paiement : Airtel Money ne propose que CDF');
    await p.fill('#libelle', 'Test groupe électrogène');
    await p.selectOption('#categorie_id', { label: 'Carburant du groupe électrogène' });
    await p.selectOption('#etablissement_id', { label: 'Volcano Club' });
    await p.selectOption('#paiement-0-compte', club);
    await p.selectOption('#paiement-0-devise', '1');
    await p.fill('#paiement-0-montant', '40');
    verifier(net(await p.textContent('[data-paiement] >> nth=0 >> [data-solde-paiement]')) === 'Solde : ' + fmt(usd0, 'USD'), 'paiement : solde de Caisse du Club en USD affiché');
    await p.click('[data-ajouter-paiement]');
    verifier(await p.locator('[data-paiement]').count() === 2, 'paiement : seconde ligne ajoutée sans recharger la page');
    const ligne2 = p.locator('[data-paiement]').nth(1);
    verifier(await ligne2.locator('[data-compte-paiement]').inputValue() === club && await ligne2.locator('[data-devise-paiement]').inputValue() === '2', 'seconde ligne : même compte (Caisse du Club), dans l\'autre devise (CDF)');
    await ligne2.locator('[data-montant-paiement]').fill('114000');
    verifier(net(await p.textContent('[data-resume-montants]')) === '114 000 FC + $40' && net(await p.textContent('[data-resume-equivalent]')) === '≈ $80', 'résumé : 114 000 FC + $40 ≈ $80 (devise locale d\'abord)');
    await envoyer(p, '#formulaire-depense button.bouton[type=submit]:not([name])');
    t = await texte(p);
    verifier(p.url().endsWith('/gestion/depenses') && t.includes('Dépense « Test groupe électrogène » enregistrée : $40 + 114 000 FC'), 'dépense en deux devises enregistrée');
    const idDep = sql("SELECT id FROM depenses WHERE libelle = 'Test groupe électrogène'");
    verifier(sql(`SELECT GROUP_CONCAT(CONCAT(compte_id, ':', devise_id) ORDER BY position) FROM depense_paiements WHERE depense_id = ${idDep}`) === `${club}:1,${club}:2` && sql(`SELECT COUNT(*) FROM mouvements_tresorerie WHERE nature = 'depense' AND source_id = ${idDep}`) === '2', 'dépense : deux paiements depuis le même compte (USD, CDF), deux sorties au journal');
    verifier(solde('Caisse du Club', 'USD') === usd0 - 40 && solde('Caisse du Club', 'CDF') === cdf0 - 114000, 'dépense : Caisse du Club −$40 et −114 000 FC');
    const carteDep = net(await p.textContent(`#depense-${idDep}`));
    verifier(carteDep.includes('Caisse du Club $40') && carteDep.includes('Caisse du Club 114 000 FC') && carteDep.includes('≈ $80'), 'liste : les deux paiements et l\'équivalent $80');

    // Filtres : type de compte, catégorie, établissement
    await p.goto(B + '/gestion/depenses?moyen=banque');
    t = await texte(p);
    verifier(!t.includes('Test groupe électrogène') && t.includes('Loyer d\'octobre') && t.includes('Gardiennage'), 'filtre « Banque » : seules les dépenses payées par banque');
    await p.goto(B + '/gestion/depenses?moyen=caisse');
    verifier((await texte(p)).includes('Test groupe électrogène') && (await p.locator('.tre-puces .puce[aria-current="true"]').last().textContent()).includes('Caisse'), 'filtre « Caisse »');
    await p.goto(B + '/gestion/depenses?categorie=3&etablissement=2');
    t = await texte(p);
    verifier(t.includes('Test groupe électrogène') && t.includes('Gasoil du groupe électrogène') && !t.includes('Recharge prépayée SNEL'), 'filtres catégorie + établissement');
    t = net(await p.textContent('.tre-totaux'));
    verifier(t.includes('541 500 FC') && t.includes('$190') && t.includes('≈ $380'), 'filtres : totaux 541 500 FC, $190, ≈ $380');

    // Modification : montant changé, soldes recalculés
    await p.goto(B + `/gestion/depenses/${idDep}`);
    verifier(await p.locator('[data-paiement]').count() === 2 && await p.inputValue('#paiement-0-montant') === '40' && await p.inputValue('#paiement-1-devise') === '2', 'fiche dépense : deux paiements relus (compte, devise, montant)');
    await p.fill('#paiement-0-montant', '50');
    await envoyer(p, '#formulaire-depense button.bouton[type=submit]:not([name])');
    verifier(solde('Caisse du Club', 'USD') === usd0 - 50 && sql(`SELECT COUNT(*) FROM mouvements_tresorerie WHERE nature = 'depense' AND source_id = ${idDep}`) === '2', 'dépense modifiée : solde recalculé, toujours deux lignes au journal');

    // Erreurs de saisie (422)
    await p.goto(B + '/gestion/depenses/nouvelle');
    await p.fill('#libelle', '');
    await p.fill('#paiement-0-montant', 'abc');
    r = await envoyer(p, '#formulaire-depense button.bouton[type=submit]:not([name])');
    t = await texte(p);
    verifier(r.status() === 422 && t.includes('Décrivez la dépense') && t.includes('Choisissez une catégorie') && t.includes('Paiement 1 : « abc » n\'est pas un montant'), 'dépense invalide : libellé, catégorie et montant refusés (422)');
    verifier(await p.getAttribute('#paiement-0-montant', 'aria-invalid') === 'true', 'dépense invalide : montant marqué aria-invalid');
    // Même compte et même devise sur deux lignes
    await p.fill('#libelle', 'Test doublon');
    await p.selectOption('#categorie_id', { label: 'Divers' });
    await p.selectOption('#paiement-0-compte', club);
    await p.selectOption('#paiement-0-devise', '2');
    await p.fill('#paiement-0-montant', '1000');
    await p.click('[data-ajouter-paiement]');
    await p.locator('[data-paiement]').nth(1).locator('[data-compte-paiement]').selectOption(club);
    await p.locator('[data-paiement]').nth(1).locator('[data-devise-paiement]').selectOption('2');
    await p.locator('[data-paiement]').nth(1).locator('[data-montant-paiement]').fill('2000');
    r = await envoyer(p, '#formulaire-depense button.bouton[type=submit]:not([name])');
    verifier(r.status() === 422 && (await texte(p)).includes('« Caisse du Club » en CDF est déjà utilisé par un autre paiement'), 'dépense : même compte et même devise sur deux lignes refusé (422)');
    verifier(sql("SELECT COUNT(*) FROM depenses WHERE libelle = 'Test doublon'") === '0', 'dépense refusée : rien en base');
    const jetonDep = await p.getAttribute('#formulaire-depense input[name=csrf]', 'value');
    // Devise que le compte ne tient pas
    rep = await p.request.post(B + '/gestion/depenses/nouvelle', { form: {
      csrf: jetonDep, libelle: 'Test devise', categorie_id: '12', 'paiements[0][compte_id]': idCompte('Rawbank'), 'paiements[0][devise_id]': '2', 'paiements[0][montant]': '5000',
    } });
    verifier(rep.status() === 422 && net(await rep.text()).includes('Paiement 1 : « Rawbank » ne tient pas cette devise : choisissez parmi USD'), 'dépense : devise que le compte ne tient pas refusée (422)');
    // Découvert dans une devise : avertissement, rien sans confirmation
    rep = await p.request.post(B + '/gestion/depenses/nouvelle', { form: {
      csrf: jetonDep, libelle: 'Test découvert dépense', categorie_id: '12', 'paiements[0][compte_id]': orange, 'paiements[0][devise_id]': '1', 'paiements[0][montant]': '30',
      'paiements[1][compte_id]': orange, 'paiements[1][devise_id]': '2', 'paiements[1][montant]': '1000',
    } });
    html = net(await rep.text()).replace(/&#039;/g, "'");
    verifier(rep.status() === 200 && html.includes('« Orange Money test » n\'a que $25 : après cette opération, il sera à −$5') && !html.includes('n\'a que 150 000 FC') && sql("SELECT COUNT(*) FROM depenses WHERE libelle = 'Test découvert dépense'") === '0', 'dépense : découvert signalé pour (Orange, USD) seulement, rien enregistré');
    // Sans JavaScript : « Ajouter un paiement » ajoute le même compte dans l'autre devise
    rep = await p.request.post(B + '/gestion/depenses/nouvelle', { form: {
      csrf: jetonDep, action: 'ajouter_ligne', libelle: 'Sans JS', 'paiements[0][compte_id]': club, 'paiements[0][devise_id]': '2', 'paiements[0][montant]': '5000',
    } });
    html = await rep.text();
    const ligneSansJs = html.slice(html.indexOf('name="paiements[1][compte_id]"'));
    verifier(rep.status() === 200 && ligneSansJs.length > 0 && new RegExp(`value="${club}"[^>]*selected`).test(ligneSansJs.slice(0, ligneSansJs.indexOf('</select>')))
      && /value="1"\s+selected>USD/.test(ligneSansJs.slice(ligneSansJs.indexOf('paiements[1][devise_id]'))) && sql("SELECT COUNT(*) FROM depenses WHERE libelle = 'Sans JS'") === '0', 'sans JS : ligne ajoutée avec le même compte dans l\'autre devise (USD), rien enregistré');

    // Suppression : lignes du journal supprimées, soldes rétablis
    await p.goto(B + `/gestion/depenses/${idDep}`);
    await p.click('.tre-supprimer summary');
    await envoyer(p, '.tre-supprimer button[type=submit]');
    verifier(solde('Caisse du Club', 'USD') === usd0 && solde('Caisse du Club', 'CDF') === cdf0 && sql(`SELECT COUNT(*) FROM mouvements_tresorerie WHERE nature = 'depense' AND source_id = ${idDep}`) === '0' && sql(`SELECT COUNT(*) FROM depense_paiements WHERE depense_id = ${idDep}`) === '0', 'dépense supprimée : paiements et journal supprimés, soldes rétablis');

    // ---------- Fiche d'un compte : colonne devise, filtre de devise, solde par devise ----------
    await p.goto(B + `/gestion/tresorerie/comptes/${comptoir}?periode=tout`);
    const devisesLignes = await p.locator('.tre-journal tbody tr[data-devise]').evaluateAll((l) => [...new Set(l.map((x) => x.dataset.devise))].sort());
    verifier(JSON.stringify(devisesLignes) === '["CDF","USD"]' && await p.locator('.tre-journal thead th').nth(1).textContent() === 'Devise', 'fiche : colonne « Devise », mouvements en CDF et en USD');
    const derniereUsd = net(await p.locator('.tre-journal tbody tr[data-devise=USD]').last().locator('.tre-journal__solde').textContent());
    const derniereCdf = net(await p.locator('.tre-journal tbody tr[data-devise=CDF]').last().locator('.tre-journal__solde').textContent());
    verifier(derniereUsd === net(await p.textContent('.tre-solde[data-devise=USD] .tre-solde__valeur')) && derniereCdf === net(await p.textContent('.tre-solde[data-devise=CDF] .tre-solde__valeur')) && derniereCdf === fmt(solde('Caisse du Comptoir', 'CDF'), 'CDF'), `fiche : dernière ligne de chaque devise = son solde (${derniereCdf}, ${derniereUsd})`);
    await p.click('.tre-filtre-devise a:has-text("USD")');
    await p.waitForURL(/devise=1/);
    const usdSeul = await p.locator('.tre-journal tbody tr[data-devise]').evaluateAll((l) => l.map((x) => x.dataset.devise));
    verifier(usdSeul.length > 0 && usdSeul.every((x) => x === 'USD') && await p.locator('.tre-journal tfoot tr').count() === 1 && p.url().includes('periode=tout'), 'filtre USD : seulement les mouvements en dollars, un seul total, période gardée');
    await p.goto(B + `/gestion/tresorerie/comptes/${club}?du=2026-10-05&au=2026-10-15`);
    const avantCdf = parseFloat(sql(`SELECT COALESCE(SUM(IF(sens='entree', montant, -montant)), 0) FROM mouvements_tresorerie WHERE compte_id = ${club} AND devise_id = 2 AND date_operation < '2026-10-05'`));
    const finUsd = parseFloat(sql(`SELECT COALESCE(SUM(IF(sens='entree', montant, -montant)), 0) FROM mouvements_tresorerie WHERE compte_id = ${club} AND devise_id = 1 AND date_operation <= '2026-10-15 23:59:59'`));
    const reports = await p.locator('.tre-journal__report').evaluateAll((l) => l.map((x) => x.textContent.replace(/[\s  ]+/g, ' ').trim()));
    t = await texte(p, '.tre-journal tfoot');
    verifier(reports.length === 2 && reports[0].includes('CDF') && reports[0].includes(fmt(avantCdf, 'CDF')) && reports[1].includes('USD') && t.includes(fmt(finUsd, 'USD')), `fiche Caisse du Club du 05 au 15/10 : report par devise (${fmt(avantCdf, 'CDF')}) et solde USD au 15/10 (${fmt(finUsd, 'USD')})`);

    // ---------- Désactivation d'un compte avec soldes, transfert vers un compte inactif ----------
    await p.goto(B + `/gestion/tresorerie/comptes/${orange}/modifier`);
    await envoyer(p, `form[action$="/${orange}/desactiver"] button`);
    verifier((await texte(p)).includes('Ce compte contient encore 150 000 FC · $25'), 'désactivation : confirmation avec les soldes de chaque devise');
    await envoyer(p, '#confirmation button[type=submit]');
    verifier(sql(`SELECT active FROM comptes_tresorerie WHERE id = ${orange}`) === '0', 'compte désactivé après confirmation');
    rep = await p.request.post(B + '/gestion/tresorerie/transferts', { form: {
      csrf: jeton, compte_source_id: comptoir, devise_source_id: '2', compte_destination_id: orange, devise_destination_id: '2', montant_source: '1000', montant_destination: '', date: '', motif: '',
    } });
    verifier(rep.status() === 422 && (await rep.text()).includes('Ce compte est désactivé'), 'transfert vers un compte désactivé refusé (422)');
    await p.goto(B + '/gestion/depenses/nouvelle');
    verifier(await p.locator(`#paiement-0-compte option[value="${orange}"]`).count() === 0, 'compte désactivé : plus proposé pour les dépenses');

    // ---------- CSRF ----------
    const avant = sql('SELECT COUNT(*) FROM transferts_tresorerie');
    for (const [url, form] of [
      ['/gestion/tresorerie/transferts', { csrf: 'faux', compte_source_id: comptoir, devise_source_id: '1', compte_destination_id: idCompte('Rawbank'), devise_destination_id: '1', montant_source: '1' }],
      ['/gestion/depenses/nouvelle', { csrf: '', libelle: 'x' }],
      ['/gestion/tresorerie/comptes/nouveau', { nom: 'Sans jeton', 'devises[2][tenue]': '1' }],
      [`/gestion/depenses/categories`, { csrf: 'x', nom_fr: 'Sans jeton' }],
    ]) {
      rep = await p.request.post(B + url, { form });
      verifier(rep.status() === 419, `CSRF : ${url} refusé (419) sans jeton valide`);
    }
    verifier(sql('SELECT COUNT(*) FROM transferts_tresorerie') === avant && sql("SELECT COUNT(*) FROM comptes_tresorerie WHERE nom = 'Sans jeton'") === '0', 'CSRF : rien enregistré');

    // ---------- Catégories ----------
    await p.goto(B + '/gestion/depenses/categories');
    await p.fill('#nouvelle-nom-fr', 'Loyer');
    r = await envoyer(p, 'form[action="/gestion/depenses/categories"] button[type=submit]');
    verifier(r.status() === 422 && (await texte(p)).includes('La catégorie « Loyer » existe déjà'), 'catégorie : nom déjà pris refusé (422)');
    await p.fill('#nouvelle-nom-fr', 'Publicité test');
    await p.fill('#nouvelle-nom-en', 'Advertising test');
    await envoyer(p, 'form[action="/gestion/depenses/categories"] button[type=submit]');
    const idCat = sql("SELECT id FROM categories_depenses WHERE nom_fr = 'Publicité test'");
    verifier(idCat !== '' && (await texte(p)).includes('Catégorie « Publicité test » ajoutée'), 'catégorie ajoutée');
    await envoyer(p, `#categorie-${idCat} form[action$="/supprimer"] button`);
    verifier(sql(`SELECT COUNT(*) FROM categories_depenses WHERE id = ${idCat}`) === '0', 'catégorie inutilisée supprimée');
    rep = await p.request.post(B + '/gestion/depenses/categories/1/supprimer', { form: { csrf: jeton } });
    verifier(rep.status() === 422 && sql('SELECT COUNT(*) FROM categories_depenses WHERE id = 1') === '1', 'catégorie utilisée (Loyer) : suppression refusée (422)');

    // ---------- Gérant et comptable ----------
    for (const [email, mdp, nom] of [['patrick@demo.test', 'Gerant-Goma-2026', 'Patrick'], ['grace@demo.test', 'Compta-Goma-2026', 'Grace']]) {
      const ctx = await b.newContext({ viewport: { width: 1366, height: 860 } });
      const q = await ctx.newPage();
      const errs = [];
      q.on('pageerror', (e) => errs.push(e.message));
      await connecter(q, email, mdp);
      const statuts = [];
      for (const u of ['/gestion/tresorerie', `/gestion/tresorerie/comptes/${comptoir}`, '/gestion/tresorerie/transferts', '/gestion/depenses', '/gestion/depenses/nouvelle']) {
        statuts.push((await q.goto(B + u)).status());
      }
      verifier(errs.length === 0 && statuts.every((s) => s === 200 || s === 403), `${nom} : pages de la trésorerie sans erreur (${statuts.join(', ')})`);
      await ctx.close();
    }

    // ---------- FR / EN, jour / nuit, ordinateur / téléphone ----------
    const pages = [
      '/gestion/tresorerie', `/gestion/tresorerie/comptes/${comptoir}?periode=7j`, `/gestion/tresorerie/comptes/${comptoir}/modifier`,
      '/gestion/tresorerie/comptes/nouveau', `/gestion/tresorerie/transferts?source=${club}`, '/gestion/depenses', '/gestion/depenses?moyen=caisse',
      '/gestion/depenses/nouvelle', '/gestion/depenses/2', '/gestion/depenses/categories',
    ];
    fs.mkdirSync(CAPTURES, { recursive: true });
    for (const [nomVue, vue] of [['ordinateur', { width: 1366, height: 860 }], ['téléphone', { width: 390, height: 844 }]]) {
      for (const theme of ['jour', 'nuit']) {
        for (const langue of ['fr', 'en']) {
          const ctx = await b.newContext({ viewport: vue });
          await ctx.addInitScript((th) => { try { localStorage.setItem('gestion.theme', th); } catch (e) {} }, theme);
          const q = await ctx.newPage();
          const erreursPage = [];
          q.on('pageerror', (e) => erreursPage.push(e.message));
          q.on('console', (m) => { if (m.type() === 'error') erreursPage.push(m.text()); });
          await connecter(q);
          await q.goto(B + '/gestion?lang=' + langue);
          const debordements = [], cles = [], themes = [], langues = [], petites = [], especes = [];
          for (const u of pages) {
            await q.goto(B + u);
            const info = await q.evaluate(() => ({
              debord: document.documentElement.scrollWidth - innerWidth,
              texte: document.body.innerText,
              theme: document.documentElement.dataset.theme,
              langue: document.documentElement.lang,
              fond: getComputedStyle(document.body).backgroundColor,
              // Un montant coupé (plus large que sa boîte) ?
              coupes: [...document.querySelectorAll('.tre-solde__valeur, .chiffre__valeur, .tre-compte__solde')].filter((e) => e.scrollWidth > e.clientWidth + 1).length,
            }));
            if (info.debord > 0 || info.coupes > 0) debordements.push(`${u} (+${info.debord}px, ${info.coupes} montant(s) coupé(s))`);
            const cle = info.texte.match(CLE_BRUTE);
            if (cle) cles.push(`${u} : ${cle[0]}`);
            if (/Espèces|Cash box/.test(info.texte)) especes.push(u);
            if (info.theme !== theme || info.fond !== (theme === 'nuit' ? 'rgb(15, 17, 19)' : 'rgb(244, 243, 241)')) themes.push(u);
            if (info.langue !== langue) langues.push(u);
            // Cibles tactiles de 44 px au moins (sauf liens dans le texte d'un tableau)
            const petitesIci = await q.evaluate(() => [...document.querySelectorAll('main a, main button, main summary, main select, main input:not([type=hidden]):not([type=radio]):not([type=checkbox])')]
              .filter((el) => { const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0 && r.height < 43.5 && !el.matches('.visuellement-cache, td a'); })
              .map((el) => el.tagName + '.' + el.className + ' ' + Math.round(el.getBoundingClientRect().height)));
            petites.push(...petitesIci.map((x) => u + ' ' + x));
          }
          const etiquette = `${nomVue}, ${theme}, ${langue.toUpperCase()}`;
          verifier(debordements.length === 0, `${etiquette} : pas de défilement horizontal ni de montant coupé${debordements.length ? ' — ' + debordements.join(', ') : ''}`);
          verifier(cles.length === 0, `${etiquette} : aucune clé de traduction brute${cles.length ? ' — ' + cles.join(', ') : ''}`);
          verifier(especes.length === 0, `${etiquette} : aucun type « Espèces » / « Cash box »${especes.length ? ' — ' + especes.join(', ') : ''}`);
          verifier(themes.length === 0 && langues.length === 0, `${etiquette} : thème et langue appliqués sur les ${pages.length} pages`);
          verifier(petites.length === 0, `${etiquette} : cibles tactiles de 44 px au moins${petites.length ? ' — ' + petites.slice(0, 5).join(', ') : ''}`);
          verifier(erreursPage.length === 0, `${etiquette} : aucune erreur JavaScript${erreursPage.length ? ' — ' + erreursPage.join(' | ') : ''}`);
          if (langue === 'en' && theme === 'jour') {
            await q.goto(B + '/gestion/tresorerie');
            const en = net(await q.textContent('main'));
            verifier(en.includes('Treasury') && en.includes('Cash till') && en.includes('Grand total in CDF') && /\d{1,3}(,\d{3})+ FC/.test(en) && /\$\d{1,3}(,\d{3})*\.\d{2}/.test(en), `${nomVue} EN : textes (« Cash till ») et montants en anglais`);
            await q.goto(B + `/gestion/tresorerie/comptes/${comptoir}`);
            const enFiche = net(await q.textContent('main'));
            verifier(enFiche.includes('Balance in CDF') && enFiche.includes('Balance in USD') && enFiche.includes('All currencies'), `${nomVue} EN : fiche « Balance in CDF / USD », « All currencies »`);
            await q.goto(B + '/gestion/depenses/nouvelle');
            await q.fill('#paiement-0-montant', '12.5');
            const [rr] = await Promise.all([q.waitForNavigation(), q.click('#formulaire-depense button.bouton[type=submit]:not([name])')]);
            const enErr = net(await q.textContent('main'));
            verifier(rr.status() === 422 && enErr.includes('Describe the expense') && enErr.includes('have no decimals'), `${nomVue} EN : messages d'erreur en anglais (422)`);
            const repEn = await q.request.post(B + `/gestion/tresorerie/comptes/${comptoir}/modifier`, { form: {
              csrf: await q.getAttribute('#formulaire-depense input[name=csrf]', 'value'), nom: 'Caisse du Comptoir', type: 'caisse', etablissement_id: '1', solde_initial_le: '2026-10-01', position: '1', 'devises[2][tenue]': '1',
            } });
            verifier(repEn.status() === 422 && net(await repEn.text()).includes('“Caisse du Comptoir” already has movements in USD: this currency cannot be removed.'), `${nomVue} EN : retrait d'une devise avec mouvements refusé en anglais`);
          }
          if (langue === 'fr') {
            const nom = `${nomVue === 'ordinateur' ? 'bureau' : 'tel'}-${theme}`;
            for (const [n, u, plein] of [
              ['tresorerie', '/gestion/tresorerie', true],
              ['compte', `/gestion/tresorerie/comptes/${comptoir}?periode=7j`, false],
              ['compte-form', `/gestion/tresorerie/comptes/${comptoir}/modifier`, true],
              ['transfert', `/gestion/tresorerie/transferts?source=${club}#nouveau-transfert`, false],
              ['depense', '/gestion/depenses/2', true],
            ]) {
              await q.goto(B + u);
              if (n === 'transfert') {
                // Change dans la caisse du Club : 100 $ → FC
                await q.selectOption('#devise_source_id', '1');
                await q.selectOption('#compte_destination_id', club);
                await q.fill('#montant_source', '100');
                await q.evaluate(() => window.scrollTo(0, 0));
              }
              await q.screenshot({ path: `${CAPTURES}/${n}-${nom}.png`, fullPage: plein });
            }
          }
          await ctx.close();
        }
      }
    }
    verifier(erreursJs.length === 0, 'parcours principal : aucune erreur JavaScript' + (erreursJs.length ? ' — ' + erreursJs.join(' | ') : ''));
  } catch (erreur) {
    verifier(false, 'exception : ' + erreur.message);
  }
  await b.close();
  console.log(`\n${ok} vérifications réussies, ${echecs} échecs`);
  process.exit(echecs ? 1 : 0);
})();
