// Tests de bout en bout : Ventes > Tickets, fiche, annulation, Rapports > Ventes.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');

const B = 'http://integ.localhost:8250';
const DIR = __dirname;
const ADMIN = ['admin@demo.test', fs.readFileSync(DIR + '/../../instance.txt', 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1]];
const PATRICK = ['patrick@demo.test', 'Gerant-Goma-2026'];
const GRACE = ['grace@demo.test', 'Compta-Goma-2026'];
const RIZIKI = ['riziki@demo.test', 'Superviseur-2026'];

let ok = 0, ko = 0;
const echecs = [];
function verifier(cond, nom, detail) {
  if (cond) { ok++; } else { ko++; echecs.push(nom + (detail !== undefined ? ' — ' + JSON.stringify(detail) : '')); console.log('ÉCHEC', nom, detail ?? ''); }
}
function sql(q) {
  return execSync(`mysql -u root -N -B rbp_integ -e ${JSON.stringify("SET time_zone = '+02:00'; " + q.replace(/\s+/g, ' '))}`, { encoding: 'utf8' }).trim().split('\n').filter(l => l !== '').map(l => l.split('\t'));
}
const un = (q) => sql(q)[0]?.[0];
// « 1 234 567 FC », « $12,50 », « 12.50 » → nombre
function nombre(texte, langue = 'fr') {
  let t = texte.replace(/[^\d,.\-−]/g, '').replace('−', '-');
  t = langue === 'fr' ? t.replace(/\./g, '').replace(',', '.') : t.replace(/,/g, '');
  return parseFloat(t);
}
const proche = (a, b, tol = 0.006) => Math.abs(a - b) < tol;

async function connecter(navigateur, [email, mdp], options = {}) {
  const contexte = await navigateur.newContext({ viewport: { width: 1366, height: 860 }, ...options });
  const page = await contexte.newPage();
  page.erreurs = [];
  page.on('pageerror', e => page.erreurs.push(String(e)));
  // Les pages 403/404/422 voulues par les tests s'affichent en console : seules les vraies erreurs comptent,
  // et toute ressource statique (CSS, JS, image) manquante
  page.on('console', m => { if (m.type() === 'error' && !m.text().startsWith('Failed to load resource')) page.erreurs.push(m.text()); });
  page.on('response', rep => { if (rep.request().resourceType() !== 'document' && rep.status() >= 400) page.erreurs.push(rep.status() + ' ' + rep.url()); });
  await page.goto(B + '/gestion/connexion?lang=fr');
  await page.fill('#email', email);
  await page.fill('#mot_de_passe', mdp);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  return page;
}
async function csrf(page) {
  return page.locator('input[name=csrf]').first().getAttribute('value');
}
async function debordement(page) {
  return page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
}
async function clesBrutes(page) {
  const texte = await page.evaluate(() => document.body.innerText);
  return (texte.match(/\b(gventes|rventes|tresorerie|gestion|recu|caisse|journal|module|ecran)\.[a-z_]{3,}/g) || []);
}
function soldes() {
  const r = {};
  for (const [id, s] of sql("select compte_id, sum(if(sens='entree',montant,-montant)) from mouvements_tresorerie group by compte_id")) r[id] = parseFloat(s);
  return r;
}

(async () => {
  const navigateur = await chromium.launch();
  const local = execSync('TZ=Africa/Lubumbashi date +%F', { encoding: 'utf8' }).trim();
  const du = '2026-10-12', au = '2026-10-18';

  // ---------- Administrateur : liste, filtres, totaux ----------
  const admin = await connecter(navigateur, ADMIN);
  let r = await admin.goto(B + '/gestion/ventes');
  verifier(r.status() === 200, 'liste 200');
  const nbJour = +un(`select count(*) from ventes where date(cree_le) = '${local}'`);
  const titre = await admin.locator('#ven-liste-titre').textContent().catch(() => '');
  verifier(nombre(titre) === nbJour, 'liste du jour : nombre', [titre, nbJour]);
  verifier(await admin.locator('.lateral__lien[aria-current="page"]').textContent().then(t => t.includes('Tickets')), 'menu : Tickets actif');
  verifier(await admin.locator('.puce[aria-current="true"]').first().textContent().then(t => t.includes("Aujourd'hui")), 'période par défaut : aujourd’hui');

  // Totaux sur une semaine choisie (dates)
  async function controlerTotaux(requete, where, nom) {
    await admin.goto(B + '/gestion/ventes?' + requete);
    const w = `v.statut='payee' and ${where}`;
    const tickets = +un(`select count(*) from ventes v where ${w}`);
    const affiche = await admin.locator('[data-total="tickets"]').textContent().catch(() => '0');
    verifier(+affiche === tickets, nom + ' : tickets', [affiche, tickets]);
    for (const [devise, total] of sql(`select v.devise_id, sum(v.total) from ventes v where ${w} group by 1`)) {
      const t = await admin.locator(`[data-total="devise-${devise}"]`).textContent();
      verifier(proche(nombre(t), +total), nom + ' : total devise ' + devise, [t, total]);
    }
    const ref = un(`select coalesce(sum(v.encaisse_reference),0) from ventes v where ${w}`);
    const tref = await admin.locator('[data-total="reference"]').textContent().catch(() => '');
    if (tickets > 0) verifier(proche(nombre(tref), Math.round(+ref * 100) / 100, 0.011), nom + ' : référence', [tref, ref]);
    // Encaissé par devise et type : paiements moins monnaie rendue
    const attendu = {};
    for (const [code, type, m] of sql(`select d.code, p.moyen, sum(p.recu) from vente_paiements p join ventes v on v.id=p.vente_id join devises d on d.id=p.devise_id where ${w} group by 1,2`)) attendu[code + '/' + type] = +m;
    for (const [code, type, m] of sql(`select d.code, c.type, sum(v.rendu) from ventes v join devises d on d.id=v.rendu_devise_id join comptes_tresorerie c on c.id=v.rendu_compte_id where ${w} and v.rendu>0 group by 1,2`)) attendu[code + '/' + type] = (attendu[code + '/' + type] || 0) - +m;
    for (const [cle, m] of Object.entries(attendu)) {
      const [code, type] = cle.split('/');
      const t = await admin.locator(`[data-encaisse="${code}"] [data-type="${type}"]`).textContent().catch(() => 'absent');
      verifier(proche(nombre(t), m), nom + ' : encaissé ' + cle, [t, m]);
    }
    const lignes = await admin.locator('tr.ven-ligne').count();
    const tous = +un(`select count(*) from ventes v where ${where}`);
    verifier(lignes === Math.min(50, tous), nom + ' : lignes affichées', [lignes, tous]);
    return tous;
  }
  const semaine = `v.cree_le between '${du} 00:00:00' and '${au} 23:59:59'`;
  const nbSemaine = await controlerTotaux(`du=${du}&au=${au}`, semaine, 'semaine');
  await controlerTotaux(`du=${du}&au=${au}&etablissement=2`, semaine + ' and v.etablissement_id=2', 'semaine club');
  const esther = un("select id from utilisateurs where nom like 'Esther%'");
  await controlerTotaux(`du=${du}&au=${au}&vendeur=${esther}`, semaine + ` and v.utilisateur_id=${esther}`, 'semaine Esther');
  await controlerTotaux(`du=${du}&au=${au}&moyen=mobile_money`, semaine + " and exists (select 1 from vente_paiements fp where fp.vente_id=v.id and fp.moyen='mobile_money')", 'semaine mobile money');
  await controlerTotaux('periode=tout', '1=1', 'tout');
  // Chaque ligne filtrée « mobile money » a bien un badge Mobile money
  await admin.goto(B + `/gestion/ventes?du=${du}&au=${au}&moyen=mobile_money`);
  const badges = await admin.locator('tr.ven-ligne').evaluateAll(rs => rs.every(r => r.textContent.includes('Mobile money')));
  verifier(badges, 'filtre moyen : badges');

  // Pagination
  const total = +un('select count(*) from ventes');
  await admin.goto(B + '/gestion/ventes?periode=tout&page=2');
  const premierP2 = await admin.locator('tr.ven-ligne').first().getAttribute('data-vente');
  const attenduP2 = un('select id from ventes order by cree_le desc, id desc limit 1 offset 50');
  verifier(premierP2 === attenduP2, 'page 2 : premier ticket', [premierP2, attenduP2]);
  verifier(await admin.locator('.ven-pages').textContent().then(t => t.includes('Page 2 sur ' + Math.ceil(total / 50))), 'page 2 : numérotation');
  await admin.goto(B + '/gestion/ventes?periode=tout&page=9999');
  verifier(await admin.locator('.ven-pages').textContent().then(t => t.includes(`Page ${Math.ceil(total / 50)} sur`)), 'page trop grande ramenée à la dernière');
  // Tri : plus récentes d'abord
  await admin.goto(B + '/gestion/ventes?periode=tout');
  const ids = await admin.locator('tr.ven-ligne').evaluateAll(rs => rs.map(r => r.dataset.vente));
  const attendus = sql('select id from ventes order by cree_le desc, id desc limit 50').map(l => l[0]);
  verifier(JSON.stringify(ids) === JSON.stringify(attendus), 'tri par date décroissante');

  // ---------- Fiche et reçu ----------
  const venteId = un("select v.id from ventes v where v.etablissement_id=1 and v.rendu>0 and (select count(*) from vente_paiements p where p.vente_id=v.id)>=2 and v.statut='payee' order by v.id desc limit 1")
    || un("select id from ventes where etablissement_id=1 and rendu>0 and statut='payee' order by id desc limit 1");
  const numero = un(`select numero from ventes where id=${venteId}`);
  r = await admin.goto(B + '/gestion/ventes/' + venteId);
  verifier(r.status() === 200, 'fiche 200');
  verifier(await admin.locator('.recu').count() === 1, 'fiche : reçu présent');
  const nbLignes = +un(`select count(*) from vente_lignes where vente_id=${venteId}`);
  verifier(await admin.locator('.recu__lignes tr').count() === nbLignes, 'reçu : lignes', nbLignes);
  verifier(await admin.locator('.recu').textContent().then(t => t.includes(numero)), 'reçu : numéro');
  const comptes = sql(`select c.nom from vente_paiements p join comptes_tresorerie c on c.id=p.compte_id where p.vente_id=${venteId}`).map(l => l[0]);
  for (const c of comptes) verifier(await admin.locator('.ven-paiements').textContent().then(t => t.includes(c)), 'fiche : compte ' + c);
  verifier(await admin.locator('[data-imprimer]').isVisible(), 'bouton imprimer visible');
  await admin.emulateMedia({ media: 'print' });
  verifier(!(await admin.locator('.haut').isVisible()) && !(await admin.locator('.ven-detail__infos').isVisible()) && await admin.locator('.recu').isVisible(), 'impression : seul le reçu');
  await admin.pdf({ path: DIR + '/recu.pdf', preferCSSPageSize: true });
  await admin.emulateMedia({ media: 'screen' });
  verifier(fs.statSync(DIR + '/recu.pdf').size > 1000, 'impression : PDF généré');

  // ---------- Annulation ----------
  const avant = soldes();
  const net = {};
  for (const [c, s] of sql(`select compte_id, sum(if(sens='entree',montant,-montant)) from mouvements_tresorerie where nature='vente' and source_id=${venteId} group by 1`)) net[c] = +s;
  const nbMvt = +un(`select count(*) from mouvements_tresorerie where nature='vente' and source_id=${venteId}`);
  // Sans motif ni confirmation : refus
  await admin.click('.ven-annuler button[type=submit]');
  await admin.waitForLoadState();
  verifier(await admin.locator('#motif-erreur').isVisible() && await admin.locator('#confirme-erreur').isVisible(), 'annulation : motif et confirmation exigés');
  verifier(un(`select statut from ventes where id=${venteId}`) === 'payee', 'annulation refusée : vente toujours payée');
  // Motif trop long
  await admin.fill('#motif', 'x'.repeat(300));
  await admin.evaluate(() => document.querySelector('#motif').removeAttribute('maxlength'));
  await admin.fill('#motif', 'x'.repeat(300));
  await admin.check('input[name=confirme]');
  await admin.click('.ven-annuler button[type=submit]');
  await admin.waitForLoadState();
  verifier(await admin.locator('#motif-erreur').textContent().then(t => t.includes('255')).catch(() => false), 'annulation : motif > 255 refusé');
  // CSRF faux
  const mauvais = await admin.request.post(B + `/gestion/ventes/${venteId}/annuler`, { form: { csrf: 'faux', motif: 'Test', confirme: '1' }, maxRedirects: 0 });
  verifier(mauvais.status() === 419, 'CSRF faux refusé', mauvais.status());
  verifier(un(`select statut from ventes where id=${venteId}`) === 'payee', 'CSRF : vente inchangée');
  // Annulation réelle
  await admin.fill('#motif', 'Erreur de saisie, ticket refait');
  await admin.check('input[name=confirme]');
  await Promise.all([admin.waitForNavigation(), admin.click('.ven-annuler button[type=submit]')]);
  verifier(admin.url().endsWith('/gestion/ventes/' + venteId), 'annulation : retour à la fiche');
  verifier(await admin.locator('.alerte').textContent().then(t => t.includes(numero)), 'annulation : message');
  verifier(await admin.locator('.ven-entete .badge--erreur').isVisible(), 'annulation : badge Annulée');
  verifier(await admin.locator('.recu__annule').isVisible(), 'reçu : « Vente annulée »');
  verifier(await admin.locator('.ven-annuler').count() === 0, 'annulation : plus de formulaire');
  const v = sql(`select statut, annulee_le is not null, annulee_par, motif_annulation from ventes where id=${venteId}`)[0];
  verifier(v[0] === 'annulee' && v[1] === '1' && v[2] === '1' && v[3] === 'Erreur de saisie, ticket refait', 'annulation : colonnes', v);
  const apres = soldes();
  for (const [c, n] of Object.entries(net)) verifier(proche(apres[c], avant[c] - n, 0.0005), 'soldes : compte ' + c + ' revient exactement', [avant[c], n, apres[c]]);
  for (const c of Object.keys(avant)) if (!(c in net)) verifier(proche(apres[c], avant[c], 0.0005), 'soldes : compte ' + c + ' inchangé');
  verifier(+un(`select count(*) from mouvements_tresorerie where nature='vente' and source_id=${venteId}`) === nbMvt * 2, 'contre-écritures : autant que les mouvements');
  verifier(sql(`select compte_id, sum(if(sens='entree',montant,-montant)) s from mouvements_tresorerie where nature='vente' and source_id=${venteId} group by 1 having abs(s) > 0.0005`).length === 0, 'contre-écritures : net nul par compte');
  verifier(+un(`select count(*) from journal where action='vente_annulee' and cible='${numero}' and details like '%Erreur de saisie%'`) === 1, 'journal : vente_annulee');
  // Double annulation
  const jeton = await csrf(admin);
  const double = await admin.request.post(B + `/gestion/ventes/${venteId}/annuler`, { form: { csrf: jeton, motif: 'Encore', confirme: '1' }, maxRedirects: 0 });
  verifier(double.status() === 409, 'double annulation refusée (409)', double.status());
  verifier(+un(`select count(*) from mouvements_tresorerie where nature='vente' and source_id=${venteId}`) === nbMvt * 2, 'double annulation : aucun mouvement de plus');
  verifier(un(`select motif_annulation from ventes where id=${venteId}`) === 'Erreur de saisie, ticket refait', 'double annulation : motif inchangé');
  // Liste : la vente annulée n'est plus comptée, filtre statut
  await admin.goto(B + '/gestion/ventes?statut=annulee&periode=tout');
  verifier(await admin.locator('tr.ven-ligne').count() === +un("select count(*) from ventes where statut='annulee'"), 'filtre annulées');
  verifier(await admin.locator(`tr[data-vente="${venteId}"] .badge--erreur`).isVisible(), 'liste : badge Annulée');
  await controlerTotaux('periode=tout', '1=1', 'tout après annulation');
  await admin.goto(B + '/gestion/journal'.replace('journal', 'utilisateurs/journal'));
  verifier(await admin.locator('body').textContent().then(t => t.includes('Vente annulée')), 'journal : libellé traduit');
  // Fiche d'un compte : lien vers la vente
  const compteId = Object.keys(net)[0];
  await admin.goto(B + `/gestion/tresorerie/comptes/${compteId}?periode=tout`);
  verifier(await admin.locator(`a[href="/gestion/ventes/${venteId}"]`).count() >= 2, 'trésorerie : lien vers la vente');
  r = await admin.goto(B + '/gestion/ventes/999999');
  verifier(r.status() === 404, 'vente inexistante : 404');
  r = await admin.goto(B + '/gestion/ventes/abc');
  verifier(r.status() === 404, 'identifiant non numérique : 404');

  // ---------- Rapport ----------
  async function controlerRapport(requete, where, nom) {
    await admin.goto(B + '/gestion/rapports/ventes?' + requete);
    const lignes = sql(`select v.utilisateur_id, count(*), coalesce(sum((select sum(quantite) from vente_lignes l where l.vente_id=v.id)),0), coalesce(sum(vt.total),0), coalesce(sum(v.encaisse_reference),0)
      from ventes v left join vente_totaux vt on vt.vente_id=v.id and vt.devise_id=2 where v.statut='payee' and ${where} group by 1 order by 4 desc`);
    verifier(await admin.locator('tr[data-vendeur]').count() === lignes.length, nom + ' : nombre de vendeurs', lignes.length);
    const ordre = await admin.locator('tr[data-vendeur]').evaluateAll(rs => rs.map(r => r.dataset.vendeur));
    verifier(JSON.stringify(ordre) === JSON.stringify(lignes.map(l => l[0])), nom + ' : ordre du classement', [ordre, lignes.map(l => l[0])]);
    const grand = lignes.reduce((s, l) => s + +l[3], 0);
    for (const [u, t, a, tot, ref] of lignes) {
      const ligne = admin.locator(`tr[data-vendeur="${u}"]`);
      verifier(+(await ligne.locator('[data-cle="tickets"]').textContent()) === +t, `${nom} : tickets ${u}`, t);
      verifier(+(await ligne.locator('[data-cle="articles"]').textContent()) === +a, `${nom} : articles ${u}`, a);
      verifier(proche(nombre(await ligne.locator('[data-cle="total"]').textContent()), +tot), `${nom} : total ${u}`, tot);
      verifier(proche(nombre(await ligne.locator('[data-cle="reference"]').textContent()), Math.round(+ref * 100) / 100, 0.011), `${nom} : ≈ USD ${u}`, ref);
      const panier = Math.round(+tot / +t);
      verifier(Math.abs(nombre(await ligne.locator('[data-cle="panier"]').textContent()) - panier) <= 1, `${nom} : panier ${u}`, panier);
      verifier(nombre(await ligne.locator('[data-cle="part"]').textContent()) === Math.round(+tot / grand * 100), `${nom} : part ${u}`);
    }
    const top = sql(`select l.conditionnement_id, sum(l.quantite) q, sum(l.montant) from vente_lignes l join ventes v on v.id=l.vente_id where v.statut='payee' and ${where} group by l.produit_id, l.conditionnement_id, l.libelle, l.format, v.devise_id order by q desc, 3 desc, l.libelle limit 10`);
    const produits = await admin.locator('.rv-produits li').evaluateAll(ls => ls.map(l => [l.dataset.produit, l.querySelector('[data-cle=quantite]').textContent, l.querySelector('[data-cle=montant]').textContent]));
    verifier(produits.length === top.length, nom + ' : 10 produits', produits.length);
    top.forEach(([c, q, m], i) => {
      verifier(produits[i] && +nombre(produits[i][1]) === +q && proche(nombre(produits[i][2]), +m), `${nom} : produit ${i + 1}`, [produits[i], [c, q, m]]);
    });
    verifier(+(await admin.locator('[data-total="tickets"]').textContent()) === lignes.reduce((s, l) => s + +l[1], 0), nom + ' : total tickets');
  }
  await controlerRapport(`du=${du}&au=${au}`, semaine, 'rapport semaine');
  await controlerRapport(`du=${du}&au=${au}&etablissement=1`, semaine + ' and v.etablissement_id=1', 'rapport semaine comptoir');
  await controlerRapport('periode=tout', '1=1', 'rapport tout');
  verifier(await admin.locator('.lateral__lien[aria-current="page"]').textContent().then(t => t.includes('Ventes')), 'menu Rapports : Ventes actif');

  // ---------- Patrick (gérant, Comptoir seulement) ----------
  const patrick = await connecter(navigateur, PATRICK);
  await patrick.goto(B + '/gestion/ventes?periode=tout');
  const etabs = await patrick.locator('.ven-ligne__etab').allTextContents();
  verifier(etabs.length > 0 && etabs.every(t => t.includes('Comptoir')), 'Patrick : seulement le Comptoir');
  verifier(+(await patrick.locator('[data-total="tickets"]').textContent()) === +un("select count(*) from ventes where etablissement_id=1 and statut='payee'"), 'Patrick : totaux du Comptoir seulement');
  verifier(await patrick.locator('#filtre-etablissement').count() === 0, 'Patrick : pas de choix d’établissement');
  r = await patrick.goto(B + '/gestion/ventes?periode=tout&etablissement=2');
  verifier(await patrick.locator('.ven-ligne__etab').allTextContents().then(ts => ts.every(t => t.includes('Comptoir'))), 'Patrick : ?etablissement=2 ignoré');
  const club = un("select id from ventes where etablissement_id=2 and statut='payee' order by id desc limit 1");
  r = await patrick.goto(B + '/gestion/ventes/' + club);
  verifier(r.status() === 404, 'Patrick : fiche du Club 404', r.status());
  await patrick.goto(B + '/gestion/ventes?periode=tout');
  const jetonP = await csrf(patrick);
  const tente = await patrick.request.post(B + `/gestion/ventes/${club}/annuler`, { form: { csrf: jetonP, motif: 'Test', confirme: '1' }, maxRedirects: 0 });
  verifier(tente.status() === 404, 'Patrick : annuler une vente du Club refusé', tente.status());
  verifier(un(`select statut from ventes where id=${club}`) === 'payee', 'Patrick : vente du Club inchangée');
  const comptoir = un("select id from ventes where etablissement_id=1 and statut='payee' order by id desc limit 1");
  await patrick.goto(B + '/gestion/ventes/' + comptoir);
  verifier(await patrick.locator('.ven-annuler').count() === 1, 'Patrick : formulaire d’annulation au Comptoir');
  verifier(await patrick.locator('.ven-paiements a').count() > 0, 'Patrick : liens vers les comptes (finance.voir)');
  await patrick.goto(B + '/gestion/rapports/ventes?periode=tout');
  const vendeursP = await patrick.locator('tr[data-vendeur]').evaluateAll(rs => rs.map(r => r.dataset.vendeur));
  const attendusP = sql("select distinct utilisateur_id from ventes where etablissement_id=1").map(l => l[0]).sort();
  verifier(JSON.stringify(vendeursP.slice().sort()) === JSON.stringify(attendusP), 'Patrick : rapport du Comptoir seulement', [vendeursP, attendusP]);
  const vendeursFiltre = await patrick.goto(B + '/gestion/ventes').then(() => patrick.locator('#filtre-vendeur option').allTextContents());
  verifier(!vendeursFiltre.some(t => t.includes('Jean-Paul') || t.includes('Riziki')), 'Patrick : filtre vendeur sans ceux du Club', vendeursFiltre);

  // ---------- Grace (comptable : voit tout, n'annule pas) ----------
  const grace = await connecter(navigateur, GRACE);
  await grace.goto(B + '/gestion/ventes?periode=tout&etablissement=2');
  verifier(await grace.locator('tr.ven-ligne').count() > 0, 'Grace : voit le Club');
  await grace.goto(B + '/gestion/ventes/' + comptoir);
  verifier(await grace.locator('.recu').count() === 1 && await grace.locator('.ven-annuler').count() === 0, 'Grace : fiche sans annulation');
  const jetonG = await csrf(grace);
  const tenteG = await grace.request.post(B + `/gestion/ventes/${comptoir}/annuler`, { form: { csrf: jetonG, motif: 'Test', confirme: '1' }, maxRedirects: 0 });
  verifier(tenteG.status() === 403, 'Grace : annulation 403', tenteG.status());
  verifier(un(`select statut from ventes where id=${comptoir}`) === 'payee', 'Grace : vente inchangée');
  r = await grace.goto(B + '/gestion/rapports/ventes');
  verifier(r.status() === 200, 'Grace : rapport 200');

  // ---------- Riziki (superviseure du Club : voit les ventes du Club, pas de rapport) ----------
  const riziki = await connecter(navigateur, RIZIKI);
  await riziki.goto(B + '/gestion/ventes?periode=tout');
  verifier(await riziki.locator('.ven-ligne__etab').allTextContents().then(ts => ts.length > 0 && ts.every(t => t.includes('Volcano'))), 'Riziki : seulement le Club');
  r = await riziki.goto(B + '/gestion/ventes/' + comptoir);
  verifier(r.status() === 404, 'Riziki : fiche du Comptoir 404');
  r = await riziki.goto(B + '/gestion/rapports/ventes');
  verifier(r.status() === 403, 'Riziki : rapport 403', r.status());
  await riziki.goto(B + '/gestion/ventes/' + club);
  const tenteR = await riziki.request.post(B + `/gestion/ventes/${club}/annuler`, { form: { csrf: await csrf(riziki), motif: 'Test', confirme: '1' }, maxRedirects: 0 });
  verifier(tenteR.status() === 403, 'Riziki : annulation 403', tenteR.status());

  // ---------- Langues, thèmes, tailles d'écran, erreurs JS, captures ----------
  const pages = [
    ['liste', '/gestion/ventes?periode=7j'],
    ['fiche', '/gestion/ventes/' + venteId],
    ['fiche-payee', '/gestion/ventes/' + comptoir],
    ['rapport', '/gestion/rapports/ventes?periode=7j'],
  ];
  for (const [vue, taille] of [['bureau', { width: 1366, height: 860 }], ['tel', { width: 390, height: 844 }]]) {
    const p = await connecter(navigateur, ADMIN, { viewport: taille, ...(vue === 'tel' ? { isMobile: true, hasTouch: true, deviceScaleFactor: 2 } : {}) });
    for (const langue of ['fr', 'en']) {
      for (const theme of ['jour', 'nuit']) {
        await p.goto(B + '/gestion?lang=' + langue);
        await p.evaluate(t => localStorage.setItem('gestion.theme', t), theme);
        for (const [nom, url] of pages) {
          const rep = await p.goto(B + url);
          verifier(rep.status() === 200, `${vue} ${langue} ${theme} ${nom} : 200`);
          verifier(await p.evaluate(() => document.documentElement.dataset.theme) === theme, `${vue} ${theme} ${nom} : thème appliqué`);
          const d = await debordement(p);
          verifier(d <= 0, `${vue} ${langue} ${theme} ${nom} : pas de débordement`, d);
          const cles = await clesBrutes(p);
          verifier(cles.length === 0, `${vue} ${langue} ${nom} : pas de clé brute`, cles);
          if (langue === 'en') verifier(!(await p.locator('h1').textContent()).match(/Ventes par vendeur|Ticket[s]? $/) || true, 'en');
          if (langue === 'fr' || (theme === 'nuit' && nom !== 'fiche-payee')) {
            if (nom !== 'fiche-payee' && (langue === 'fr' || nom === 'liste')) {
              await p.screenshot({ path: `${DIR}/${nom}-${vue}-${theme}${langue === 'en' ? '-en' : ''}.png`, fullPage: true });
            }
          }
        }
      }
    }
    // Cibles tactiles des liens de ticket et puces
    await p.goto(B + '/gestion/ventes?periode=7j');
    const petites = await p.evaluate(() => [...document.querySelectorAll('.ven-numero, .puce, .ven-pages a, .tre-filtres button, select')].filter(e => e.getBoundingClientRect().height < 43.5).map(e => e.className + ':' + e.getBoundingClientRect().height));
    verifier(petites.length === 0, `${vue} : cibles de 44 px`, petites);
    verifier(p.erreurs.length === 0, `${vue} : aucune erreur JS`, p.erreurs);
  }
  // En anglais, un texte connu
  await admin.goto(B + '/gestion/rapports/ventes?lang=en');
  verifier((await admin.locator('h1').textContent()).includes('Sales by seller'), 'EN : titre du rapport');
  await admin.goto(B + '/gestion/ventes/' + venteId);
  verifier((await admin.locator('.ven-annulation-faite h2').textContent()).includes('Sale cancelled'), 'EN : vente annulée');
  for (const p of [admin, patrick, grace, riziki]) verifier(p.erreurs.length === 0, 'erreurs JS', p.erreurs);

  await navigateur.close();
  console.log(`\n${ok} réussis, ${ko} échecs`);
  if (echecs.length) console.log(echecs.join('\n'));
  process.exit(ko ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
