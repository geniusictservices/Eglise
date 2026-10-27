// Tests de bout en bout : tableau de bord de la direction, rapport du personnel, événements, exports CSV,
// impression, export comptable. Base de copie rbp_integ, serveur http://integ.localhost:8250.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');

const B = 'http://integ.localhost:8250';
const DIR = __dirname;
const DB = 'rbp_integ';
const ADMIN = ['admin@demo.test', 'Essai-Integ-2026'];
const PATRICK = ['patrick@demo.test', 'Gerant-Goma-2026'];
const GRACE = ['grace@demo.test', 'Compta-Goma-2026'];
const RIZIKI = ['riziki@demo.test', 'Superviseur-2026'];

let ok = 0, ko = 0;
const echecs = [];
function verifier(cond, nom, detail) {
  if (cond) { ok++; } else { ko++; echecs.push(nom + (detail !== undefined ? ' — ' + JSON.stringify(detail) : '')); console.log('ÉCHEC', nom, detail ?? ''); }
}
function sql(q) {
  return execSync(`mysql -u root --default-character-set=utf8mb4 -N -B ${DB} -e ${JSON.stringify("SET time_zone = '+02:00'; " + q.replace(/\s+/g, ' '))}`, { encoding: 'utf8' }).trim().split('\n').filter(l => l !== '').map(l => l.split('\t'));
}
const un = (q) => sql(q)[0]?.[0];
function nombre(texte, langue = 'fr') {
  let t = String(texte).replace(/[^\d,.\-−]/g, '').replace('−', '-');
  t = langue === 'fr' ? t.replace(/\./g, '').replace(',', '.') : t.replace(/,/g, '');
  return parseFloat(t);
}
const proche = (a, b, tol = 0.6) => Math.abs(a - b) <= tol;

async function connecter(nav, [email, mdp], options = {}) {
  const ctx = await nav.newContext({ viewport: { width: 1366, height: 860 }, acceptDownloads: true, ...options });
  const page = await ctx.newPage();
  page.erreurs = [];
  page.on('pageerror', e => page.erreurs.push(String(e)));
  page.on('console', m => { if (m.type() === 'error' && !m.text().startsWith('Failed to load resource')) page.erreurs.push(m.text()); });
  page.on('response', r => {
    // gestion-personnel.css : feuille du module Personnel (autre agent), pas encore écrite
    if (r.request().resourceType() !== 'document' && r.status() >= 400 && !r.url().includes('gestion-personnel.css')) page.erreurs.push(r.status() + ' ' + r.url());
  });
  await page.goto(B + '/gestion/connexion?lang=fr');
  await page.fill('#email', email);
  await page.fill('#mot_de_passe', mdp);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  return page;
}
const csrf = (page) => page.locator('input[name=csrf]').first().getAttribute('value');
const debordement = (page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
async function clesBrutes(page) {
  const texte = await page.evaluate(() => document.body.innerText);
  return (texte.match(/\b(rapports|rtableau|rpersonnel|rcompta|evenements|gventes|gdettes|tresorerie|gestion|module|ecran|perm)\.[a-z_]{3,}/g) || []);
}
async function texte(page, sel) { return (await page.locator(sel).first().textContent()).trim(); }
// Télécharge un CSV avec les cookies de la page ; renvoie { brut, lignes }
async function csv(page, chemin) {
  const r = await page.request.get(B + chemin);
  const corps = await r.body();
  const brut = corps.toString('utf8');
  const lignes = brut.replace(/^﻿/, '').trim().split('\r\n').length > 1 ? brut.replace(/^﻿/, '').trim().split('\r\n') : brut.replace(/^﻿/, '').trim().split('\n');
  return { statut: r.status(), type: r.headers()['content-type'], disposition: r.headers()['content-disposition'], octets: corps, brut, lignes,
    cellules: lignes.map(l => l.split(';').map(c => c.replace(/^"|"$/g, ''))) };
}
function jour(d) { return d.toISOString().slice(0, 10); }
// Journée d'exploitation (fin de journée de l'établissement)
const JOURNEE = "DATE(SUBTIME(v.cree_le, e.fin_journee))";

(async () => {
  const nav = await chromium.launch();
  const LOCAL = execSync('TZ=Africa/Lubumbashi date +%F', { encoding: 'utf8' }).trim();
  // Dates relatives au jour de Goma (la démo a trois semaines de ventes jusqu'au jour de sa création) ; créée le 27/10 :
  // période du 12 au 18/10, précédente du 05 au 11/10, journées du 15, 16 et 17/10
  const J = n => execSync(`TZ=Africa/Lubumbashi date -d '${LOCAL} ${n} days' +%F`, { encoding: 'utf8' }).trim();
  const du = J(-15), au = J(-9), duAvant = J(-22), auAvant = J(-16), J15 = J(-12), J16 = J(-11), J17 = J(-10);
  // Coût des marchandises vendues (devise de référence), comme RapportRepository::ajouterCouts()
  const cogs = ids => ids.length ? parseFloat(un(`select coalesce(sum(-quantite * coalesce(cout_unitaire, 0)), 0) from stock_mouvements where type in ('vente', 'recette') and source_id in (${ids.join(',')})`)) : 0;
  const LOCALE = un("select id from devises where est_locale=1"), REF = un("select id from devises where est_reference=1");
  const taux = parseFloat(un(`select taux from taux_change where devise_id=${LOCALE} and valable_depuis <= now() order by valable_depuis desc, id desc limit 1`));

  // ================= Tableau de bord =================
  const admin = await connecter(nav, ADMIN);
  let r = await admin.goto(`${B}/gestion/tableau-de-bord?du=${du}&au=${au}`);
  verifier(r.status() === 200, 'tableau 200');
  const wv = (a, b, extra = '') => `v.statut='payee' and ${JOURNEE} between '${a}' and '${b}' ${extra}`;
  const caL = parseFloat(un(`select coalesce(sum(vt.total),0) from ventes v join etablissements e on e.id=v.etablissement_id join vente_totaux vt on vt.vente_id=v.id and vt.devise_id=${LOCALE} where ${wv(du, au)}`));
  const caR = parseFloat(un(`select coalesce(sum(v.total_reference),0) from ventes v join etablissements e on e.id=v.etablissement_id where ${wv(du, au)}`));
  const tickets = +un(`select count(*) from ventes v join etablissements e on e.id=v.etablissement_id where ${wv(du, au)}`);
  verifier(proche(nombre(await texte(admin, '[data-kpi=ca] .rap-kpi__valeur')), caL), 'tableau : CA local = SQL', [await texte(admin, '[data-kpi=ca] .rap-kpi__valeur'), caL]);
  verifier(proche(nombre(await texte(admin, '[data-kpi=ca] .rap-kpi__second'), 'fr'), caR, 0.01), 'tableau : CA en $ = SQL', [await texte(admin, '[data-kpi=ca] .rap-kpi__second'), caR]);
  verifier((await texte(admin, '[data-kpi=ca] .rap-kpi__pied')).includes(`${tickets} tickets`), 'tableau : nombre de tickets', tickets);
  // Part à crédit : montant de chaque ardoise rapporté au total du ticket dans sa devise
  const lignesCredit = sql(`select v.id, vt.total, d.montant, vd.total from ventes v join etablissements e on e.id=v.etablissement_id join vente_totaux vt on vt.vente_id=v.id and vt.devise_id=${LOCALE}
      join dettes d on d.vente_id=v.id join vente_totaux vd on vd.vente_id=v.id and vd.devise_id=d.devise_id where ${wv(du, au)}`);
  const parVente = {};
  for (const [id, tl, m, td] of lignesCredit) parVente[id] = { tl: +tl, part: Math.min(1, (parVente[id]?.part || 0) + (+m) / (+td)) };
  const creditL = Object.values(parVente).reduce((s, x) => s + x.tl * x.part, 0);
  const creditAffiche = +(await admin.locator('[data-kpi=encaisse] [data-credit]').getAttribute('data-credit'));
  verifier(proche(creditAffiche, creditL, 1), 'tableau : part à crédit = SQL', [creditAffiche, creditL]);
  verifier(proche(nombre(await texte(admin, '[data-kpi=encaisse] .rap-kpi__valeur')), caL - creditL, 100), 'tableau : encaissé = CA − crédit', [await texte(admin, '[data-kpi=encaisse] .rap-kpi__valeur'), caL - creditL]);
  // Dépenses : contre-valeurs du journal (dollars) ; résultat = CA − dépenses (pas de coûts d'achat dans la démo)
  const depR = parseFloat(un(`select coalesce(sum(coalesce(m.contre_valeur, 0)),0) from depenses d join depense_paiements p on p.depense_id=d.id
      left join mouvements_tresorerie m on m.nature='depense' and m.source_id=d.id and m.compte_id=p.compte_id and m.devise_id=p.devise_id
      where d.date_depense >= '${du} 00:00:00' and d.date_depense < date_add('${au}', interval 1 day)`));
  verifier(proche(nombre(await texte(admin, '[data-kpi=depenses] .rap-kpi__second')), depR, 0.02), 'tableau : dépenses en $ = SQL', [await texte(admin, '[data-kpi=depenses] .rap-kpi__second'), depR]);
  const res = +(await admin.locator('[data-kpi=resultat] [data-reference]').getAttribute('data-reference'));
  // La démo a maintenant le stock et ses coûts (CUMP) : résultat = CA − coût des marchandises − dépenses, et la marge est montrée
  const idsPeriode = sql(`select v.id from ventes v join etablissements e on e.id=v.etablissement_id where ${wv(du, au)}`).map(l => l[0]);
  const cogsPeriode = cogs(idsPeriode);
  verifier(cogsPeriode > 0, 'démo : coût des marchandises connu sur la période', cogsPeriode);
  verifier(proche(res, caR - cogsPeriode - depR, 0.05), 'tableau : résultat = CA − coût des marchandises − dépenses', [res, caR - cogsPeriode - depR]);
  verifier(await admin.locator('[data-kpi=marge].rap-kpi--vide').count() === 0 && proche(nombre(await texte(admin, '[data-kpi=marge] .rap-kpi__valeur')), caR - cogsPeriode, 0.05), 'tableau : marge = CA − coût des marchandises', [await texte(admin, '[data-kpi=marge] .rap-kpi__valeur'), caR - cogsPeriode]);
  // Comparaison avec la semaine précédente
  const caAvant = parseFloat(un(`select coalesce(sum(vt.total),0) from ventes v join etablissements e on e.id=v.etablissement_id join vente_totaux vt on vt.vente_id=v.id and vt.devise_id=${LOCALE} where ${wv(duAvant, auAvant)}`));
  const attendu = Math.round((caL - caAvant) / caAvant * 100);
  const ecartTexte = await texte(admin, '[data-kpi=ca] .rap-ecart');
  verifier(Math.abs(nombre(ecartTexte)) === Math.abs(attendu) && (attendu >= 0 ? ecartTexte.includes('+') : ecartTexte.includes('−')), 'tableau : écart avec la période précédente', [ecartTexte, attendu]);
  // Par établissement
  for (const etab of ['1', '2']) {
    const ca = parseFloat(un(`select coalesce(sum(vt.total),0) from ventes v join etablissements e on e.id=v.etablissement_id join vente_totaux vt on vt.vente_id=v.id and vt.devise_id=${LOCALE} where ${wv(du, au, 'and v.etablissement_id=' + etab)}`));
    verifier(proche(nombre(await texte(admin, `[data-tableau=etablissements] tr[data-etablissement="${etab}"] [data-cle=ca]`)), ca), 'tableau : CA établissement ' + etab, ca);
  }
  // Par jour : 7 colonnes, données = SQL
  verifier(await admin.locator('#graphique-jours .rap-colonne').count() === 7, 'tableau : 7 colonnes pour 7 jours');
  const ligne15 = (await admin.locator('#graphique-jours .rap-donnees tbody tr').nth(3).locator('td').last().textContent());
  const ca15 = parseFloat(un(`select coalesce(sum(vt.total),0) from ventes v join etablissements e on e.id=v.etablissement_id join vente_totaux vt on vt.vente_id=v.id and vt.devise_id=${LOCALE} where ${wv(J15, J15)}`));
  const v15 = nombre(ligne15) * (ligne15.includes('M') ? 1e6 : ligne15.includes('k') ? 1e3 : 1);
  verifier(Math.abs(v15 - ca15) <= Math.max(50000, ca15 * 0.05), 'tableau : total du 15/10 (compact) proche du SQL', [ligne15, ca15]);
  // Une vente à 2 h du matin compte pour la veille (journée d'exploitation)
  const nuit = sql(`select v.id, date(v.cree_le) from ventes v where v.etablissement_id=2 and hour(v.cree_le) < 5 and date(v.cree_le) = '${J17}' limit 1`)[0];
  if (nuit) {
    const n16 = +un(`select count(*) from ventes v join etablissements e on e.id=v.etablissement_id where ${wv(J16, J16, 'and v.etablissement_id=2')}`);
    await admin.goto(`${B}/gestion/tableau-de-bord?du=${J16}&au=${J16}&etablissement=2`);
    verifier((await texte(admin, '[data-kpi=ca] .rap-kpi__pied')).includes(`${n16} tickets`), 'journée d’exploitation : ventes de 0 h à 5 h dans la veille', n16);
  }
  // Moins vendus : un produit jamais vendu apparaît avec « Aucune vente »
  await admin.goto(`${B}/gestion/tableau-de-bord?du=${du}&au=${au}`);
  const moins = await admin.locator('[data-liste=moins] li').allTextContents();
  const jamais = un(`select count(*) from produits p where p.actif=1 and exists (select 1 from conditionnements c where c.produit_id=p.id and c.vendable=1)
      and not exists (select 1 from produits t where t.parent_id=p.id and t.actif=1)
      and not exists (select 1 from vente_lignes l join ventes v on v.id=l.vente_id join etablissements e on e.id=v.etablissement_id left join conditionnements c on c.id=l.conditionnement_id
                       where coalesce(c.produit_id,l.produit_id)=p.id and ${wv(du, au)})`);
  verifier(+jamais === 0 || moins[0].includes('Aucune vente'), 'tableau : produit jamais vendu en tête des moins vendus', [jamais, moins[0]]);
  // Plus rentables = CA décroissant sans coûts
  const rent = await admin.locator('[data-liste=rentables] li .rap-classement__chiffres strong').allTextContents();
  verifier(rent.map(t => nombre(t)).every((v, i, a) => i === 0 || a[i - 1] >= v), 'tableau : produits classés par CA décroissant', rent);
  // Serveuse de la semaine = meilleur CA des 7 dernières journées
  const aujourdhui = execSync("TZ=Africa/Lubumbashi date -d '-5 hours' +%F", { encoding: 'utf8' }).trim();
  const lundi7 = execSync(`date -d '${aujourdhui} -6 days' +%F`, { encoding: 'utf8' }).trim();
  const top = sql(`select v.utilisateur_id, sum(vt.total) s from ventes v join etablissements e on e.id=v.etablissement_id join vente_totaux vt on vt.vente_id=v.id and vt.devise_id=${LOCALE}
       where ${wv(lundi7, aujourdhui)} group by v.utilisateur_id order by s desc limit 1`)[0];
  verifier(top && (await admin.locator('[data-vedette]').getAttribute('data-vedette')) === top[0], 'tableau : serveuse ou serveur de la semaine', top);
  verifier(proche(nombre((await texte(admin, '[data-vedette] [data-cle=ca]')).split('FC')[0]), +top[1], 100), 'tableau : CA de la vedette', top);
  // Créances : total en $ au taux du jour
  const creances = sql(`select devise_id, sum(reste) from dettes where statut='ouverte' and reste>0 group by devise_id`);
  let crRef = 0;
  for (const [d, m] of creances) crRef += d === REF ? +m : (+m) / parseFloat(un(`select taux from taux_change where devise_id=${d} and valable_depuis<=now() order by valable_depuis desc, id desc limit 1`));
  verifier(proche(nombre((await texte(admin, '[data-cle=creances]')).split('·')[1]), crRef, 0.05), 'tableau : créances en $', [await texte(admin, '[data-cle=creances]'), crRef]);
  verifier(await admin.locator('[data-bloc=presence]').count() === 1, 'tableau : présence (module Personnel installé)');
  verifier(admin.erreurs.length === 0, 'tableau : aucune erreur JS', admin.erreurs);

  // CSV du tableau de bord
  let c = await csv(admin, `/gestion/tableau-de-bord?du=${du}&au=${au}&export=csv`);
  verifier(c.statut === 200 && c.type.startsWith('text/csv') && c.disposition.includes(`tableau-de-bord-${du}_${au}.csv`), 'CSV tableau : en-têtes HTTP', [c.type, c.disposition]);
  verifier(c.octets[0] === 0xEF && c.octets[1] === 0xBB && c.octets[2] === 0xBF, 'CSV tableau : BOM UTF-8');
  verifier(c.cellules[0][0] === 'Jour' && c.cellules[0].length >= 9 && c.lignes[0].includes(';'), 'CSV tableau : séparateur « ; » et en-tête', c.lignes[0]);
  verifier(c.lignes.length === 1 + 7 + 1, 'CSV tableau : 7 journées + total', c.lignes.length);
  const totalCsv = c.cellules[c.cellules.length - 1];
  verifier(totalCsv[0] === 'Total' && +totalCsv[1] === tickets && proche(nombre(totalCsv[3]), caL) && proche(parseFloat(totalCsv[4].replace(',', '.')), caR, 0.01), 'CSV tableau : ligne de total = SQL', totalCsv);
  verifier(!/\d \d/.test(c.cellules[1][3]) && /^\d+$/.test(c.cellules[1][3]), 'CSV tableau : montant en nombre simple', c.cellules[1][3]);

  // Marges : coûts d'achat simulés sur les ventes du 15/10 (mouvements de stock « vente » au CUMP)
  const ventes15 = sql(`select v.id from ventes v join etablissements e on e.id=v.etablissement_id where ${wv(J15, J15)}`).map(l => l[0]);
  const lignes15 = sql(`select l.vente_id, coalesce(c.produit_id, l.produit_id), l.quantite * coalesce(c.quantite_base, 1), l.conditionnement_id from vente_lignes l left join conditionnements c on c.id=l.conditionnement_id where l.vente_id in (${ventes15.join(',')})`);
  const lieu = un("select id from lieux_stock limit 1");
  let cout15 = cogs(ventes15);   // coûts déjà enregistrés par le stock de la démo
  const valeurs = lignes15.map(([v, p, q, cond]) => { const cu = 0.4 + (p % 5) * 0.1; cout15 += q * cu; return `(${lieu},${p},'vente',${-q},${cu},${v},'e2e-rapports',now(),${cond === 'NULL' ? 'NULL' : cond})`; });
  sql(`insert into stock_mouvements (lieu_id, produit_id, type, quantite, cout_unitaire, source_id, motif, date_mouvement, conditionnement_id) values ${valeurs.join(',')}`);
  sql(`insert ignore into stock_couts (produit_id, cump, devise_id, maj_le) select distinct produit_id, 0.5, ${REF}, now() from stock_mouvements where motif='e2e-rapports'`);
  await admin.goto(`${B}/gestion/tableau-de-bord?du=${J15}&au=${J15}`);
  const caR15 = parseFloat(un(`select sum(v.total_reference) from ventes v where v.id in (${ventes15.join(',')})`));
  verifier(proche(nombre(await texte(admin, '[data-kpi=marge] .rap-kpi__valeur')), caR15 - cout15, 0.02), 'marge : CA − coût des marchandises', [await texte(admin, '[data-kpi=marge] .rap-kpi__valeur'), caR15 - cout15]);
  verifier((await admin.locator('[data-liste=rentables]').count()) === 1 && (await texte(admin, '#rt-rentables')).includes('rentables'), 'marge : produits les plus rentables');
  const patrickMarge = await connecter(nav, PATRICK);
  await patrickMarge.goto(`${B}/gestion/tableau-de-bord?du=${J15}&au=${J15}`);
  verifier(await patrickMarge.locator('[data-kpi=marge]').count() === 0, 'marge : cachée sans rapports.marges (gérant)');
  // Gérant limité au Comptoir du Lac : seulement ses ventes
  const caP = parseFloat(un(`select coalesce(sum(vt.total),0) from ventes v join etablissements e on e.id=v.etablissement_id join vente_totaux vt on vt.vente_id=v.id and vt.devise_id=${LOCALE} where ${wv(J15, J15, 'and v.etablissement_id=1')}`));
  verifier(proche(nombre(await texte(patrickMarge, '[data-kpi=ca] .rap-kpi__valeur')), caP), 'portée : le gérant ne voit que son établissement', caP);
  await patrickMarge.goto(`${B}/gestion`);
  verifier(await patrickMarge.locator('a[href="/gestion/evenements"]').count() === 0, 'modules : pas d’Événements pour le gérant d’un restaurant-bar');
  await patrickMarge.context().close();
  sql("delete from stock_mouvements where motif='e2e-rapports'");
  sql("delete from stock_couts where produit_id not in (select produit_id from stock_mouvements) and maj_le >= curdate() - interval 1 day");

  // ================= Rapport du personnel =================
  await admin.goto(`${B}/gestion/rapports/personnel?du=${du}&au=${au}`);
  const parVendeur = sql(`select v.utilisateur_id, sum(vt.total), count(*) from ventes v join etablissements e on e.id=v.etablissement_id join vente_totaux vt on vt.vente_id=v.id and vt.devise_id=${LOCALE}
      where ${wv(du, au)} group by v.utilisateur_id order by sum(vt.total) desc`);
  const lignesRang = await admin.locator('[data-tableau=classement] tbody tr').evaluateAll(trs => trs.map(tr => [tr.dataset.utilisateur, tr.querySelector('[data-cle=retenu] strong').textContent, tr.querySelector('[data-cle=tickets]').textContent]));
  verifier(lignesRang.length === parVendeur.length, 'personnel : une ligne par vendeur', [lignesRang.length, parVendeur.length]);
  verifier(lignesRang.every((l, i) => l[0] === parVendeur[i][0] && proche(nombre(l[1]), +parVendeur[i][1]) && +l[2] === +parVendeur[i][2]), 'personnel : classement par CA = SQL', [lignesRang, parVendeur]);
  verifier((await admin.locator('[data-premier]').getAttribute('data-premier')) === parVendeur[0][0], 'personnel : serveuse ou serveur de la période');
  // Critère : tickets
  await admin.goto(`${B}/gestion/rapports/personnel?du=${du}&au=${au}&critere=tickets`);
  const ordreT = await admin.locator('[data-tableau=classement] tbody tr [data-cle=tickets]').allTextContents();
  verifier(ordreT.map(Number).every((v, i, a) => i === 0 || a[i - 1] >= v), 'personnel : critère tickets', ordreT);
  await admin.goto(`${B}/gestion/rapports/personnel?du=${du}&au=${au}&critere=panier`);
  const ordreP = await admin.locator('[data-tableau=classement] tbody tr [data-cle=panier]').allTextContents();
  verifier(ordreP.map(t => nombre(t)).every((v, i, a) => i === 0 || a[i - 1] >= v - 100), 'personnel : critère panier moyen', ordreP);
  // Option : une vente à crédit ne compte qu'une fois remboursée
  await admin.goto(`${B}/gestion/rapports/personnel?du=${du}&au=${au}&credit_rembourse=1`);
  const dettesV = sql(`select v.id, v.utilisateur_id, vt.total, d.montant, d.reste, d.statut, vd.total, coalesce((select sum(c.montant-c.reste) from dettes c where c.origine_id=d.id),0)
      from ventes v join etablissements e on e.id=v.etablissement_id join vente_totaux vt on vt.vente_id=v.id and vt.devise_id=${LOCALE}
      join dettes d on d.vente_id=v.id join vente_totaux vd on vd.vente_id=v.id and vd.devise_id=d.devise_id where ${wv(du, au)}`);
  const nonRemb = {};
  const ventesCredit = {};
  for (const [id, u, tl, m, reste, st, td, enfant] of dettesV) {
    const vc = ventesCredit[id] ??= { u, tl: +tl, credit: 0, remb: 0 };
    const part = (+m) / (+td);
    let rem = Math.max(0, (+m) - (+reste)); if (st === 'transferee') rem += +enfant;
    vc.credit = Math.min(1, vc.credit + part);
    vc.remb = Math.min(vc.credit, vc.remb + Math.min(part, rem / (+td)));
  }
  for (const v of Object.values(ventesCredit)) nonRemb[v.u] = (nonRemb[v.u] || 0) + v.tl * (v.credit - v.remb);
  const avecOption = await admin.locator('[data-tableau=classement] tbody tr').evaluateAll(trs => trs.map(tr => [tr.dataset.utilisateur, tr.querySelector('[data-cle=retenu] strong').textContent]));
  let optionOk = true;
  const detailOption = [];
  for (const [u, txt] of avecOption) {
    const ca = +parVendeur.find(l => l[0] === u)[1];
    const att = ca - (nonRemb[u] || 0);
    detailOption.push([u, nombre(txt), att]);
    if (!proche(nombre(txt), att, 100)) optionOk = false;
  }
  verifier(optionOk, 'personnel : option crédit remboursé (CA − crédit non remboursé)', detailOption);
  verifier(Object.keys(nonRemb).some(u => nonRemb[u] > 1), 'personnel : la période a du crédit non remboursé (le test compte)', nonRemb);
  const valeursOpt = avecOption.map(l => nombre(l[1]));
  verifier(valeursOpt.every((v, i, a) => i === 0 || a[i - 1] >= v), 'personnel : classement refait avec l’option', valeursOpt);
  // Comparaison avec la période précédente
  await admin.goto(`${B}/gestion/rapports/personnel?du=${du}&au=${au}`);
  const avantV = sql(`select v.utilisateur_id, sum(vt.total) from ventes v join etablissements e on e.id=v.etablissement_id join vente_totaux vt on vt.vente_id=v.id and vt.devise_id=${LOCALE} where ${wv(duAvant, auAvant)} group by v.utilisateur_id`);
  const premierU = parVendeur[0][0];
  const avantPremier = avantV.find(l => l[0] === premierU);
  const evo = await texte(admin, `[data-tableau=classement] tr[data-utilisateur="${premierU}"] [data-cle=evolution]`);
  if (avantPremier) {
    const pct = Math.round((+parVendeur[0][1] - +avantPremier[1]) / +avantPremier[1] * 100);
    verifier(evo.includes(Math.abs(pct) + ' %'), 'personnel : écart avec la période précédente', [evo, pct]);
  } else verifier(evo.includes('nouveau'), 'personnel : nouveau vendeur', evo);
  // Crédits accordés
  const accordes = sql(`select d.accorde_par, count(*) from dettes d join etablissements e on e.id=d.etablissement_id join ventes v on v.id=d.vente_id
      where v.statut='payee' and DATE(SUBTIME(d.cree_le, e.fin_journee)) between '${du}' and '${au}' group by d.accorde_par`);
  for (const [u, n] of accordes) {
    const cell = admin.locator(`[data-tableau=classement] tr[data-utilisateur="${u}"] [data-cle=credits-accordes]`);
    if (await cell.count()) verifier(+(await cell.getAttribute('data-nombre')) === +n, 'personnel : crédits accordés de ' + u, n);
  }
  // Détail d'une personne : graphique jour par jour
  await admin.goto(`${B}/gestion/rapports/personnel?du=${du}&au=${au}&utilisateur=${premierU}`);
  verifier(await admin.locator('#graphique-personne .rap-colonne').count() === 7, 'personnel : évolution jour par jour (7 jours)');
  // Dettes par responsable
  const dettesResp = sql(`select d.responsable_id, d.devise_id, sum(d.reste), count(distinct d.client_id) from dettes d join clients c on c.id=d.client_id where d.statut='ouverte' and d.reste>0 and c.type<>'personnel' group by d.responsable_id, d.devise_id`);
  const parResp = {};
  for (const [u, dv, m, nc] of dettesResp) { (parResp[u] ??= { ref: 0 }); parResp[u].ref += dv === REF ? +m : (+m) / taux; }
  for (const [u, x] of Object.entries(parResp)) {
    const cell = admin.locator(`[data-tableau=dettes] tr[data-responsable="${u}"] [data-cle=reste] strong`);
    verifier(await cell.count() === 1 && proche(nombre(await cell.textContent()), x.ref, 0.02), 'personnel : reste dû du responsable ' + u, [x.ref, await cell.count() ? await cell.textContent() : null]);
  }
  const clientsU = un(`select count(distinct d.client_id) from dettes d join clients c on c.id=d.client_id where d.statut='ouverte' and d.reste>0 and c.type<>'personnel' and d.responsable_id=4`);
  verifier(parseInt(await admin.locator('[data-tableau=dettes] tr[data-responsable="4"] [data-cle=clients]').evaluate(td => td.firstChild.textContent)) === +clientsU, 'personnel : clients concernés (Aline)', clientsU);
  const rembAline = parseFloat(un(`select coalesce(sum(rd.montant),0) from remboursement_dettes rd join remboursements r on r.id=rd.remboursement_id join dettes d on d.id=rd.dette_id join clients c on c.id=d.client_id
      where r.statut='valide' and r.cree_le >= '${du}' and r.cree_le < date_add('${au}', interval 1 day) and d.responsable_id=4 and c.type<>'personnel' and d.devise_id=${LOCALE}`));
  const rembTxt = await texte(admin, '[data-tableau=dettes] tr[data-responsable="4"] [data-cle=rembourse]');
  verifier(rembAline === 0 || rembTxt.replace(/\s/g, '').includes(String(Math.round(rembAline)).replace(/\B(?=(\d{3})+(?!\d))/g, '')), 'personnel : remboursé sur la période (Aline)', [rembTxt, rembAline]);
  await admin.goto(`${B}/gestion/rapports/personnel?du=${du}&au=${au}&retard=1`);
  const enRetardSql = +un(`select count(*) from dettes d join clients c on c.id=d.client_id where d.statut='ouverte' and d.reste>0 and d.echeance < '${LOCAL}' and c.type<>'personnel'`);
  const nbRetard = await admin.locator('[data-detail-responsable] [data-dette]').count();
  verifier(nbRetard === enRetardSql, 'personnel : filtre « en retard seulement »', [nbRetard, enRetardSql]);
  await admin.goto(`${B}/gestion/rapports/personnel?du=${du}&au=${au}&devise=${REF}`);
  const nbUsd = await admin.locator('[data-detail-responsable] [data-dette]').count();
  verifier(nbUsd === +un(`select count(*) from dettes d join clients c on c.id=d.client_id where d.statut='ouverte' and d.reste>0 and c.type<>'personnel' and d.devise_id=${REF}`), 'personnel : filtre devise', nbUsd);
  // CSV du classement et des dettes
  c = await csv(admin, `/gestion/rapports/personnel?du=${du}&au=${au}&export=csv`);
  verifier(c.octets[0] === 0xEF && c.cellules[0][0] === 'Rang' && c.lignes.length === parVendeur.length + 1, 'CSV personnel : BOM, en-tête, une ligne par vendeur', c.lignes.length);
  verifier(proche(nombre(c.cellules[1][4]), +parVendeur[0][1]) && c.cellules[1][4].match(/^\d+$/), 'CSV personnel : CA du premier = SQL', c.cellules[1]);
  c = await csv(admin, `/gestion/rapports/personnel?du=${du}&au=${au}&export=csv&tableau=dettes`);
  const ouvertes = +un("select count(*) from dettes where statut='ouverte' and reste>0");
  verifier(c.cellules[0][0] === 'Groupe' && c.lignes.length === ouvertes + 1, 'CSV dettes du personnel : une ligne par dette ouverte', [c.lignes.length, ouvertes]);

  // Riziki (superviseure, sans rapports.voir) : seulement ses résultats
  const riziki = await connecter(nav, RIZIKI);
  r = await riziki.goto(`${B}/gestion/rapports/personnel?du=${du}&au=${au}&utilisateur=6`);
  verifier(r.status() === 200 && await riziki.locator('[data-portee=moi]').count() === 1, 'portée : Riziki voit le rapport (ses résultats)');
  const lignesRiziki = await riziki.locator('[data-tableau=classement] tbody tr').evaluateAll(trs => trs.map(tr => tr.dataset.utilisateur));
  verifier(lignesRiziki.length <= 1 && lignesRiziki.every(u => u === '8'), 'portée : Riziki ne voit que sa ligne', lignesRiziki);
  verifier(!(await riziki.content()).includes('Jean-Paul Bahati'), 'portée : aucun autre nom');
  const caRiziki = un(`select coalesce(sum(vt.total),0) from ventes v join etablissements e on e.id=v.etablissement_id join vente_totaux vt on vt.vente_id=v.id and vt.devise_id=${LOCALE} where ${wv(du, au, 'and v.utilisateur_id=8')}`);
  if (lignesRiziki.length) verifier(proche(nombre(await texte(riziki, '[data-cle=retenu] strong')), +caRiziki), 'portée : CA de Riziki', caRiziki);
  const respRiziki = await riziki.locator('[data-tableau=dettes] tbody tr').evaluateAll(trs => trs.map(tr => tr.dataset.responsable));
  verifier(respRiziki.every(u => u === '8'), 'portée : Riziki ne voit que ses dettes', respRiziki);
  verifier(await riziki.locator('.lateral__lien[href="/gestion/rapports/personnel"]').count() === 1, 'modules : « Personnel » dans la barre de Riziki');
  r = await riziki.goto(`${B}/gestion/tableau-de-bord`);
  verifier(r.status() === 403, 'permissions : Riziki n’a pas le tableau de bord', r.status());
  r = await riziki.goto(`${B}/gestion/rapports/export-comptable`);
  verifier(r.status() === 403, 'permissions : Riziki n’a pas l’export comptable', r.status());
  r = await riziki.goto(`${B}/gestion/rapports/ventes?export=csv`);
  verifier(r.status() === 403, 'permissions : pas de CSV sans le droit de l’écran', r.status());

  // ================= Événements =================
  r = await riziki.goto(`${B}/gestion/evenements`);
  verifier(r.status() === 200 && await riziki.locator('.evt-carte').count() >= 4, 'événements : Riziki voit les soirées du club');
  verifier(await riziki.locator('a[href="/gestion/evenements/nouveau"]').count() === 0, 'événements : pas de « Nouvelle soirée » sans evenements.gerer');
  const jetonR = await riziki.locator('input[name=csrf]').first().getAttribute('value');
  const rp = await riziki.request.post(`${B}/gestion/evenements/nouveau`, { form: { csrf: jetonR, titre: 'X', etablissement_id: '2', debut: '2026-11-06T22:00', fin: '2026-11-07T05:00' } });
  verifier(rp.status() === 403, 'événements : création refusée sans evenements.gerer', rp.status());

  const blanche = un("select id from evenements where titre='Soirée blanche'");
  const ev = sql(`select debut, fin, etablissement_id from evenements where id=${blanche}`)[0];
  await admin.goto(`${B}/gestion/evenements/${blanche}`);
  const caEv = parseFloat(un(`select coalesce(sum(vt.total),0) from ventes v join vente_totaux vt on vt.vente_id=v.id and vt.devise_id=${LOCALE} where v.statut='payee' and v.etablissement_id=${ev[2]} and v.cree_le >= '${ev[0]}' and v.cree_le < '${ev[1]}'`));
  const caEvR = parseFloat(un(`select coalesce(sum(v.total_reference),0) from ventes v where v.statut='payee' and v.etablissement_id=${ev[2]} and v.cree_le >= '${ev[0]}' and v.cree_le < '${ev[1]}'`));
  const coutsEv = parseFloat(un(`select coalesce(sum(m.contre_valeur),0) from depenses d join depense_paiements p on p.depense_id=d.id join mouvements_tresorerie m on m.nature='depense' and m.source_id=d.id and m.compte_id=p.compte_id and m.devise_id=p.devise_id where d.evenement_id=${blanche}`));
  verifier(proche(nombre(await texte(admin, '[data-kpi=recettes] .rap-kpi__valeur')), caEv), 'événement : recettes = ventes de la nuit', caEv);
  verifier(proche(nombre(await texte(admin, '[data-kpi=couts] .rap-kpi__valeur')), coutsEv, 0.02), 'événement : coûts = dépenses rattachées', coutsEv);
  const idsEv = sql(`select v.id from ventes v where v.statut='payee' and v.etablissement_id=${ev[2]} and v.cree_le >= '${ev[0]}' and v.cree_le < '${ev[1]}'`).map(l => l[0]);
  const marchandisesEv = cogs(idsEv);
  verifier(proche(+(await admin.locator('[data-kpi=resultat] [data-reference]').getAttribute('data-reference')), caEvR - marchandisesEv - coutsEv, 0.05), 'événement : résultat = recettes − marchandises − coûts', caEvR - marchandisesEv - coutsEv);
  verifier((await texte(admin, '[data-cle=budget]')).includes(Math.round(coutsEv / 900 * 100) + ' %'), 'événement : part du budget', await texte(admin, '[data-cle=budget]'));
  // Rattacher puis détacher une dépense
  const candidate = await admin.locator('[data-liste=candidates] li').first().getAttribute('data-depense');
  await admin.check(`[data-liste=candidates] li[data-depense="${candidate}"] input`);
  await Promise.all([admin.waitForNavigation(), admin.click('#couts button[type=submit]')]);
  verifier(un(`select evenement_id from depenses where id=${candidate}`) === blanche, 'événement : dépense rattachée');
  await admin.check(`[data-liste=rattachees] li[data-depense="${candidate}"] input`);
  await Promise.all([admin.waitForNavigation(), admin.click('#couts button[type=submit]')]);
  verifier(un(`select evenement_id from depenses where id=${candidate}`) === 'NULL', 'événement : dépense détachée');
  // Création avec erreurs, puis valide
  await admin.goto(`${B}/gestion/evenements/nouveau`);
  await admin.fill('#titre', 'Nuit e2e');
  await admin.selectOption('#etablissement_id', '2');
  await admin.fill('#debut', '2026-11-13T22:00');
  await admin.fill('#fin', '2026-11-13T20:00');
  await admin.fill('#budget', 'abc');
  r = await Promise.all([admin.waitForNavigation(), admin.click('main form[action^="/gestion/evenements"] button[type=submit]')]).then(x => x[0]);
  verifier(r.status() === 422 && await admin.locator('#fin[aria-invalid=true]').count() === 1 && await admin.locator('#budget[aria-invalid=true]').count() === 1, 'événement : erreurs de saisie (fin avant début, budget)', r.status());
  await admin.fill('#fin', '2026-11-14T05:00');
  await admin.fill('#budget', '450');
  await admin.fill('#artistes', 'DJ Test');
  await Promise.all([admin.waitForNavigation(), admin.click('main form[action^="/gestion/evenements"] button[type=submit]')]);
  const nouveau = un("select id from evenements where titre='Nuit e2e'");
  verifier(nouveau && admin.url().endsWith('/gestion/evenements/' + nouveau), 'événement : créé, fiche ouverte');
  verifier(un(`select concat(debut,'|',fin,'|',budget) from evenements where id=${nouveau}`) === '2026-11-13 22:00:00|2026-11-14 05:00:00|450.000', 'événement : dates et budget enregistrés');
  // Une dépense saisie dans Finance avec le champ « Soirée »
  await admin.goto(`${B}/gestion/depenses/nouvelle?evenement=${nouveau}`);
  verifier((await admin.locator('#evenement_id').inputValue()) === nouveau && (await admin.locator('#etablissement_id').inputValue()) === '2', 'dépense : soirée et établissement proposés');
  await admin.fill('#libelle', 'Cachet DJ Test e2e');
  await admin.selectOption('#categorie_id', { label: 'Artistes et DJ' });
  await admin.fill('#paiement-0-montant', '120');
  await Promise.all([admin.waitForNavigation(), admin.click('#formulaire-depense .tre-actions button[type=submit]')]);
  if (admin.url().includes('/gestion/depenses')) {
    // Avertissement de découvert : confirmer
    const conf = admin.locator('button[name=confirme]');
    if (await conf.count()) { await Promise.all([admin.waitForNavigation(), conf.click()]); }
  }
  const depE2e = un("select id from depenses where libelle='Cachet DJ Test e2e'");
  verifier(depE2e && un(`select evenement_id from depenses where id=${depE2e}`) === nouveau, 'dépense : rattachée à la soirée depuis Finance', admin.url());
  verifier(admin.url().includes(`/gestion/evenements/${nouveau}`), 'dépense : retour à la soirée', admin.url());
  // Supprimer : refusé avec une dépense, possible sans
  const jeton = await csrf(admin);
  let rs = await admin.request.post(`${B}/gestion/evenements/${nouveau}/supprimer`, { form: { csrf: jeton } });
  verifier(rs.status() === 409, 'événement : suppression refusée avec une dépense', rs.status());
  rs = await admin.request.post(`${B}/gestion/evenements/${nouveau}/supprimer`, { form: { csrf: 'faux' } });
  verifier(rs.status() === 419, 'événement : CSRF vérifié', rs.status());
  sql(`update depenses set evenement_id=null where id=${depE2e}`);
  rs = await admin.request.post(`${B}/gestion/evenements/${nouveau}/supprimer`, { form: { csrf: jeton }, maxRedirects: 0 });
  verifier(rs.status() === 302 && un(`select count(*) from evenements where id=${nouveau}`) === '0', 'événement : supprimé sans dépense', rs.status());
  // Annuler puis rétablir
  rs = await admin.request.post(`${B}/gestion/evenements/${blanche}/statut`, { form: { csrf: jeton, statut: 'annule' }, maxRedirects: 0 });
  verifier(un(`select statut from evenements where id=${blanche}`) === 'annule', 'événement : annulé');
  await admin.goto(`${B}/gestion/evenements/rentabilite?periode=tout`);
  verifier(await admin.locator(`[data-tableau=rentabilite] tr[data-evenement="${blanche}"]`).count() === 0, 'rentabilité : soirée annulée exclue');
  await admin.request.post(`${B}/gestion/evenements/${blanche}/statut`, { form: { csrf: jeton, statut: 'prevu' } });
  await admin.goto(`${B}/gestion/evenements/rentabilite?periode=tout`);
  const resultats = await admin.locator('[data-tableau=rentabilite] [data-cle=resultat]').allTextContents();
  verifier(resultats.length === 3 && resultats.map(t => nombre(t)).every((v, i, a) => i === 0 || a[i - 1] >= v), 'rentabilité : 3 soirées passées, par résultat décroissant', resultats);
  c = await csv(admin, `/gestion/evenements/rentabilite?periode=tout&export=csv`);
  verifier(c.lignes.length === 4 && c.cellules[0].includes('Titre'), 'CSV rentabilité', c.lignes.length);
  c = await csv(admin, `/gestion/evenements/${blanche}?export=csv`);
  verifier(c.cellules.some(l => l[0] === 'Résultat') && c.cellules.some(l => l[0] === 'Coûts de la soirée'), 'CSV fiche d’une soirée');
  // Module visible : barre latérale des événements
  await admin.goto(`${B}/gestion`);
  verifier(await admin.locator('a[href="/gestion/evenements"]').count() >= 1, 'modules : « Événements » sur l’accueil (nightclub présent)');

  // ================= Exports des écrans existants =================
  const pV = `periode=7j`;
  c = await csv(admin, `/gestion/ventes?${pV}&export=csv`);
  const debut7 = execSync("TZ=Africa/Lubumbashi date -d '-6 days' +%F", { encoding: 'utf8' }).trim();
  const nb7 = +un(`select count(*) from ventes where cree_le >= '${debut7} 00:00:00'`);
  verifier(c.octets[0] === 0xEF && c.cellules[0][0] === 'Ticket' && c.lignes.length - 1 === nb7, 'CSV tickets : tous les tickets des filtres (pas une page)', [c.lignes.length - 1, nb7]);
  c = await csv(admin, `/gestion/ventes?periode=mois&etablissement=2&export=csv`);
  verifier(c.cellules.slice(1).every(l => l[2] === 'Volcano Club'), 'CSV tickets : filtre établissement');
  c = await csv(admin, `/gestion/rapports/ventes?du=${du}&au=${au}&export=csv`);
  const totalV = c.cellules[c.cellules.length - 1];
  const caCal = parseFloat(un(`select sum(vt.total) from ventes v join vente_totaux vt on vt.vente_id=v.id and vt.devise_id=${LOCALE} where v.statut='payee' and v.cree_le between '${du} 00:00:00' and '${au} 23:59:59'`));
  verifier(totalV[1] === 'Total' && proche(nombre(totalV[4]), caCal), 'CSV ventes par vendeur : total = SQL', [totalV, caCal]);
  c = await csv(admin, `/gestion/rapports/creances?export=csv`);
  verifier(c.lignes.length === ouvertes + 2 && proche(nombre(c.cellules[c.cellules.length - 1][9]), crRef, 0.05), 'CSV créances : dettes ouvertes + total', [c.lignes.length, ouvertes]);
  verifier(c.brut.includes(";'+243"), 'CSV : un texte commençant par + est neutralisé (pas de formule)');
  c = await csv(admin, `/gestion/tresorerie?export=csv`);
  const solde2 = parseFloat(un(`select sum(if(sens='entree',montant,-montant)) from mouvements_tresorerie where compte_id=2 and devise_id=${LOCALE}`));
  const ligneSolde = c.cellules.find(l => l[1] === 'Caisse du Club' && l[4] === 'CDF');
  verifier(ligneSolde && proche(nombre(ligneSolde[5]), solde2), 'CSV trésorerie : solde = journal', [ligneSolde, solde2]);
  c = await csv(admin, `/gestion/tresorerie/comptes/2?periode=mois&export=csv`);
  const nbMvt = +un(`select count(*) from mouvements_tresorerie where compte_id=2 and date_operation between date_format('${LOCAL}','%Y-%m-01') and last_day('${LOCAL}') + interval 1 day - interval 1 second`);
  verifier(c.lignes.length - 1 === nbMvt, 'CSV mouvements d’un compte', [c.lignes.length - 1, nbMvt]);
  c = await csv(admin, `/gestion/depenses?periode=mois&export=csv`);
  const nbPaie = +un(`select count(*) from depenses d join depense_paiements p on p.depense_id=d.id where d.date_depense between date_format('${LOCAL}','%Y-%m-01') and last_day('${LOCAL}') + interval 1 day - interval 1 second`);
  verifier(c.lignes.length - 1 === nbPaie, 'CSV dépenses : une ligne par paiement', [c.lignes.length - 1, nbPaie]);
  // Export comptable
  const grace = await connecter(nav, GRACE);
  r = await grace.goto(`${B}/gestion/rapports/export-comptable?du=${du}&au=${au}`);
  verifier(r.status() === 200, 'export comptable : la comptable y a accès');
  c = await csv(grace, `/gestion/rapports/export-comptable?du=${du}&au=${au}&export=csv&journal=tresorerie`);
  const nbJ = +un(`select count(*) from mouvements_tresorerie where date_operation between '${du} 00:00:00' and '${au} 23:59:59'`);
  verifier(c.lignes.length - 1 === nbJ && c.cellules[0][2] === 'Pièce', 'export comptable : journal de trésorerie complet', [c.lignes.length - 1, nbJ]);
  const entrees = c.cellules.slice(1).filter(l => l[7] === 'CDF').reduce((s, l) => s + (l[8] ? nombre(l[8]) : 0), 0);
  const entreesSql = parseFloat(un(`select sum(montant) from mouvements_tresorerie where sens='entree' and devise_id=${LOCALE} and date_operation between '${du} 00:00:00' and '${au} 23:59:59'`));
  verifier(proche(entrees, entreesSql), 'export comptable : entrées en CDF = journal', [entrees, entreesSql]);
  verifier(c.cellules.slice(1).filter(l => l[1] === 'Vente').every(l => /^[A-Z]{2,6}-\d{6}$/.test(l[2])), 'export comptable : pièce = numéro du ticket');
  c = await csv(grace, `/gestion/rapports/export-comptable?du=${du}&au=${au}&export=csv&journal=ventes`);
  verifier(c.lignes.length - 1 === +un(`select count(*) from ventes where cree_le between '${du} 00:00:00' and '${au} 23:59:59'`), 'export comptable : journal des ventes');
  c = await csv(grace, `/gestion/rapports/export-comptable?du=${du}&au=${au}&export=csv&journal=depenses`);
  verifier(c.cellules[0][6] === 'Soirée' && c.lignes.length - 1 === +un(`select count(*) from depenses d join depense_paiements p on p.depense_id=d.id where d.date_depense between '${du} 00:00:00' and '${au} 23:59:59'`), 'export comptable : journal des dépenses (avec la soirée)');
  r = await grace.goto(`${B}/gestion/tableau-de-bord?du=${du}&au=${au}`);
  verifier(r.status() === 200 && await grace.locator('[data-kpi=marge]').count() === 1, 'comptable : tableau de bord avec la marge (rapports.marges)');
  // CSV en anglais : point décimal
  c = await csv(grace, `/gestion/tableau-de-bord?du=${du}&au=${au}&export=csv&lang=en`);
  verifier(c.cellules[0][0] === 'Day' && /^\d+\.\d{2}$/.test(c.cellules[c.cellules.length - 1][4]), 'CSV en anglais : en-tête et point décimal', c.cellules[c.cellules.length - 1]);
  await grace.goto(`${B}/gestion/tableau-de-bord?lang=fr`);
  await grace.context().close();

  // ================= Impression =================
  for (const chemin of [`/gestion/tableau-de-bord?du=${du}&au=${au}`, `/gestion/rapports/personnel?du=${du}&au=${au}`, `/gestion/evenements/${blanche}`, '/gestion/ventes', '/gestion/rapports/creances', '/gestion/depenses']) {
    await admin.goto(B + chemin);
    await admin.emulateMedia({ media: 'print' });
    const etat = await admin.evaluate(() => ({
      haut: getComputedStyle(document.querySelector('.haut')).display,
      lateral: document.querySelector('.lateral') ? getComputedStyle(document.querySelector('.lateral')).display : 'none',
      bas: document.querySelector('.barre-bas') ? getComputedStyle(document.querySelector('.barre-bas')).display : 'none',
      export: getComputedStyle(document.querySelector('.rap-export')).display,
      entete: document.querySelector('.rap-impression-entete').offsetParent !== null ? 'flex' : 'cache',
      fond: getComputedStyle(document.body).backgroundColor,
    }));
    verifier(etat.haut === 'none' && etat.lateral === 'none' && etat.bas === 'none' && etat.export === 'none' && etat.entete === 'flex' && etat.fond === 'rgb(255, 255, 255)', 'impression sans navigation : ' + chemin, etat);
    await admin.emulateMedia({ media: 'screen' });
  }
  await admin.goto(`${B}/gestion/tableau-de-bord?du=${du}&au=${au}`);
  await admin.emulateMedia({ media: 'print' });
  await admin.pdf({ path: DIR + '/tableau-de-bord.pdf', format: 'A4', printBackground: true, preferCSSPageSize: true });
  await admin.emulateMedia({ media: 'screen' });
  verifier(fs.statSync(DIR + '/tableau-de-bord.pdf').size > 20000, 'PDF A4 du tableau de bord');
  // Sans JavaScript : ?imprimer=1 donne la présentation d'impression
  r = await admin.goto(`${B}/gestion/rapports/personnel?du=${du}&au=${au}&imprimer=1`);
  verifier(await admin.evaluate(() => getComputedStyle(document.querySelector('.lateral')).display) === 'none' && await admin.locator('.rap-retour-impression').isVisible(), 'impression sans JavaScript (?imprimer=1)');
  // Le bouton appelle window.print()
  await admin.goto(`${B}/gestion/tableau-de-bord?du=${du}&au=${au}`);
  await admin.evaluate(() => { window.__imprime = 0; window.print = () => { window.__imprime++; }; });
  await admin.click('[data-imprimer]');
  verifier(await admin.evaluate(() => window.__imprime) === 1 && admin.url().indexOf('imprimer=1') < 0, 'bouton Imprimer / PDF : fenêtre d’impression');

  // ================= Langues, thèmes, tailles, cibles =================
  const pages = [`/gestion/tableau-de-bord?du=${du}&au=${au}`, `/gestion/rapports/personnel?du=${du}&au=${au}&utilisateur=${premierU}`, '/gestion/evenements', `/gestion/evenements/${blanche}`,
    '/gestion/evenements/rentabilite?periode=tout', '/gestion/evenements/nouveau', '/gestion/rapports/export-comptable', '/gestion/rapports/ventes', '/gestion/rapports/creances', '/gestion/ventes', '/gestion/depenses', '/gestion/tresorerie', '/gestion/tresorerie/comptes/2'];
  for (const [l, h] of [[1366, 860], [390, 844]]) {
    for (const theme of ['jour', 'nuit']) {
      const ctx = await nav.newContext({ viewport: { width: l, height: h } });
      const p = await ctx.newPage();
      p.erreurs = [];
      p.on('pageerror', e => p.erreurs.push(String(e)));
      p.on('console', m => { if (m.type() === 'error' && !m.text().startsWith('Failed to load resource')) p.erreurs.push(m.text()); });
      p.on('response', rep => { if (rep.request().resourceType() !== 'document' && rep.status() >= 400 && !rep.url().includes('gestion-personnel.css')) p.erreurs.push(rep.status() + ' ' + rep.url()); });
      await p.goto(B + '/gestion/connexion?lang=' + (theme === 'nuit' ? 'en' : 'fr'));
      await p.fill('#email', ADMIN[0]); await p.fill('#mot_de_passe', ADMIN[1]);
      await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
      await p.addInitScript(t => { try { localStorage.setItem('gestion.theme', t); } catch (e) {} }, theme);
      for (const chemin of pages) {
        await p.goto(B + chemin + (chemin.includes('?') ? '&' : '?') + 'lang=' + (theme === 'nuit' ? 'en' : 'fr'));
        const deb = await debordement(p);
        verifier(deb <= 0, `pas de défilement horizontal ${l} ${theme} ${chemin}`, deb);
        const brutes = await clesBrutes(p);
        verifier(brutes.length === 0, `textes traduits ${theme === 'nuit' ? 'EN' : 'FR'} ${chemin}`, brutes.slice(0, 5));
        if (theme === 'nuit') verifier(await p.evaluate(() => getComputedStyle(document.body).backgroundColor) !== 'rgb(244, 243, 241)', 'thème nuit appliqué ' + chemin);
        // Cibles : boutons d'export, puces, liens d'action, résumés, champs
        const petites = await p.evaluate(() => [...document.querySelectorAll('main .rap-export a, main .puce, main .bouton, main .rap-lien, main summary, main select, main input:not([type=hidden]):not([type=checkbox]), main .evt-carte, main .rap-personne a')]
          .filter(el => el.offsetParent !== null).map(el => [el.className || el.tagName, el.getBoundingClientRect().height]).filter(([, hh]) => hh < 44));
        verifier(petites.length === 0, `cibles ≥ 44 px ${l} ${chemin}`, petites.slice(0, 4));
        if (l === 390 && theme === 'jour') await p.screenshot({ path: `${DIR}/ecran-${chemin.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '').slice(0, 60)}-390-jour.png`, fullPage: true });
        if (l === 1366 && theme === 'nuit') await p.screenshot({ path: `${DIR}/ecran-${chemin.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '').slice(0, 60)}-1366-nuit-en.png`, fullPage: true });
      }
      verifier(p.erreurs.length === 0, `aucune erreur JS ${l} ${theme}`, p.erreurs.slice(0, 5));
      await ctx.close();
    }
  }
  // Anglais : quelques libellés
  await admin.goto(`${B}/gestion/tableau-de-bord?du=${du}&au=${au}&lang=en`);
  verifier((await texte(admin, '[data-kpi=ca] .rap-kpi__libelle')) === 'Sales' && (await texte(admin, '#rt-vedette')) === 'Server of the week', 'anglais : tableau de bord');
  await admin.goto(`${B}/gestion/evenements?lang=en`);
  verifier((await texte(admin, 'h1')) === 'Nights', 'anglais : événements');
  await admin.goto(`${B}/gestion/tableau-de-bord?lang=fr`);
  verifier(admin.erreurs.length === 0, 'administrateur : aucune erreur JS', admin.erreurs.slice(0, 5));
  verifier(riziki.erreurs.length === 0, 'Riziki : aucune erreur JS', riziki.erreurs.slice(0, 5));

  // Nettoyage : la dépense de test et ses lignes du journal
  sql("delete m from mouvements_tresorerie m join depenses d on d.id=m.source_id and m.nature='depense' where d.libelle='Cachet DJ Test e2e'");
  sql("delete from depenses where libelle='Cachet DJ Test e2e'");
  await nav.close();
  console.log(`\n${ok} réussis, ${ko} échoués`);
  fs.writeFileSync(DIR + '/resultats.txt', `${ok} réussis, ${ko} échoués\n` + echecs.join('\n') + '\n');
  process.exit(ko ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
