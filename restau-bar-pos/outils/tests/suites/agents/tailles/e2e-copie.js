// Scénario de bout en bout de l'intégration. Le nettoyage est fait par nettoyer.php.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const B = 'http://integ.localhost:8250';
const ICI = __dirname;
const RACINE = '/home/user/Eglise/restau-bar-pos';
const MDP = fs.readFileSync(path.join(ICI, '../../instance.txt'), 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
const sql = q => execSync(`mysql -u root -N --default-character-set=utf8mb4 rbp_integ -e ${JSON.stringify(q)}`).toString().trim();
const net = s => (s || '').replace(/[\s  ]+/g, ' ').trim();

let nb = 0; const echecs = [];
function verifier(c, m) { nb++; console.log((c ? 'ok    ' : 'ÉCHEC ') + m); if (!c) echecs.push(m); }

(async () => {
  const nav = await chromium.launch();
  const ctx = await nav.newContext({ viewport: { width: 1366, height: 860 }, locale: 'fr-FR' });
  await ctx.addCookies([{ name: 'lang', value: 'fr', url: B }]);
  const p = await ctx.newPage();
  const erreursJs = [];
  p.on('pageerror', e => erreursJs.push(e.message));
  p.on('dialog', d => { erreursJs.push('dialog ' + d.message()); d.accept(); });
  const v = await ctx.newPage();
  v.on('pageerror', e => erreursJs.push(e.message));

  async function ligneVitrine(slug, requete, nom) {
    await v.goto(`${B}/e/${slug}${requete}`);
    const l = v.locator('li.produit', { hasText: nom });
    return (await l.count()) ? net(await l.first().textContent()) : '';
  }
  async function envoyer(selecteur) { await Promise.all([p.waitForNavigation(), p.click(selecteur)]); }

  try {
    await p.goto(B + '/gestion/connexion');
    await p.fill('#email', 'admin@demo.test'); await p.fill('#mot_de_passe', MDP);
    await envoyer('button[type=submit]');
    verifier(p.url().endsWith('/gestion'), 'connexion');

    // 1. Catégorie
    await p.goto(B + '/gestion/catalogue/categories');
    await p.fill('#nouvelle-nom_fr', 'Intégration');
    await p.fill('#nouvelle-nom_en', 'Integration');
    await envoyer('#nouvelle button[type=submit]');
    const idCat = sql("SELECT id FROM categories WHERE nom_fr='Intégration'");
    verifier(idCat !== '', 'catégorie créée (id ' + idCat + ')');

    // 2. Produit avec photo, premier format et prix USD (CDF et RWF calculés)
    await p.goto(B + '/gestion/catalogue/produits/nouveau?categorie=' + idCat);
    await p.fill('#nom_fr', 'Bière Intégration');
    await p.fill('#nom_en', 'Integration beer');
    await p.selectOption('#unite_base', 'bouteille');
    await p.setInputFiles('#photo-fichier', path.join(ICI, 'produit-test.png'));
    // Adaptation : depuis la médiathèque, la photo est envoyée tout de suite ; on attend son chemin avant d'envoyer la fiche
    await p.waitForFunction(() => (document.querySelector('input[name=photo_media]') || {}).value);
    await p.fill('#photo_credit', 'Photo : test intégration');
    await p.fill('#prix-1', '4');
    await p.locator('#prix-1').dispatchEvent('input');
    verifier(net(await p.getAttribute('#prix-2', 'placeholder')).includes('11 400'), 'création : CDF auto ' + await p.getAttribute('#prix-2', 'placeholder'));
    for (const c of await p.$$('input[name="etablissements[]"]')) { await c.check(); }
    await envoyer('.cat-actions--fin button[type=submit]');
    const idProd = (p.url().match(/produits\/(\d+)/) || [])[1];
    verifier(!!idProd, 'produit créé (id ' + idProd + ')');
    const photo = await p.getAttribute('#photo [data-apercu] img', 'src');
    // Adaptation : la photo passe par la médiathèque (/uploads/integ/mediatheque/…), qui garde jusqu'à 1600 px
    verifier(/^\/uploads\/demo\/mediatheque\/[a-z0-9-]+-[0-9a-f]{16}\.jpg$/.test(photo || '') && fs.existsSync(RACINE + '/public' + photo), 'photo ré-encodée : ' + photo);
    const dims = execSync(`php -r 'echo implode("x", array_slice(getimagesize("${RACINE}/public${photo}"), 0, 2));'`).toString();
    verifier(dims === '1400x1000', 'photo gardée à 1400 × 1000 px : ' + dims);

    // 3. Deuxième format et prix (casier USD 40 et CDF fixe 120 000 ; VIP bouteille 6 USD)
    await p.fill('#nf-nom_fr', 'Casier'); await p.fill('#nf-nom_en', 'Crate'); await p.fill('#nf-quantite', '12');
    await envoyer('#nouveau-format button[type=submit]');
    const formats = await p.$$eval('.cat-format[id^=format-]', els => els.map(e => e.id.replace('format-', '')));
    verifier(formats.length === 2, 'deux formats');
    const [fB, fC] = formats;
    const champ = (f, g, d) => `input[name="prix[${f}][${g}][${d}]"]`;
    await p.fill(champ(fC, 1, 1), '40'); await p.fill(champ(fC, 1, 2), '120 000'); await p.fill(champ(fB, 2, 1), '6');
    await envoyer('form.cat-prix button[type=submit]');
    verifier((await p.textContent('.cat-annonce')).includes('Prix enregistrés'), 'prix enregistrés');

    // 4. Vitrine dans chaque devise
    let t = await ligneVitrine('comptoir-du-lac', '?devise=USD', 'Bière Intégration');
    verifier(t.includes('$4') && t.includes('$40') && t.includes('Casier'), 'vitrine USD : ' + t);
    verifier(t.includes('Photo : test intégration') && !t.includes('Photo : Photo'), 'crédit photo sans « Photo : » répété');
    verifier(await v.locator('li.produit', { hasText: 'Bière Intégration' }).locator('img').count() >= 1, 'vitrine : photo affichée');
    t = await ligneVitrine('comptoir-du-lac', '?devise=CDF', 'Bière Intégration');
    verifier(t.includes('11 400 FC') && t.includes('120 000 FC'), 'vitrine CDF (calculé, fixe) : ' + t);
    t = await ligneVitrine('comptoir-du-lac', '?devise=RWF', 'Bière Intégration');
    verifier(t.includes('5 800 FRw') && t.includes('58 000 FRw'), 'vitrine RWF : ' + t);
    t = await ligneVitrine('volcano-club', '?zone=vip&devise=USD', 'Bière Intégration');
    verifier(t.includes('$6'), 'vitrine VIP : ' + t);
    t = await ligneVitrine('comptoir-du-lac', '?devise=USD&lang=en', 'Integration beer');
    verifier(t.includes('Crate'), 'vitrine anglais : ' + t);
    await v.goto(B + '/?lang=fr');

    // 5. Caisse : tuile, choix du format, ticket
    await v.goto(B + '/caisse?e=comptoir-du-lac');
    const tuile = v.locator('.tuile', { hasText: 'Bière Intégration' }).first();
    verifier(await tuile.count() === 1 && !(await tuile.isDisabled()), 'caisse : tuile présente');
    await tuile.click();
    const choix = (await v.$$eval('#formats-liste .format', els => els.map(e => e.textContent.replace(/[\s  ]+/g, ' ').trim())));
    verifier(choix.length === 2 && choix.some(c => c.includes('Casier') && c.includes('$40')), 'caisse : choix du format ' + choix.join(' | '));
    await v.locator('#formats-liste .format', { hasText: 'Casier' }).click();
    const ticket = net(await v.textContent('#ticket'));
    verifier(ticket.includes('$40') && ticket.includes('120 000 FC'), 'caisse : ticket ' + ticket.slice(0, 160));

    // 6. Épuisé depuis la liste
    await p.goto(B + '/gestion/catalogue?categorie=' + idCat);
    await p.click(`#produit-${idProd} .cat-bascule`);
    await p.waitForFunction(id => document.querySelector(`#produit-${id} .cat-bascule`).getAttribute('aria-pressed') === 'true', idProd);
    await v.goto(B + '/e/comptoir-du-lac');
    verifier(await v.locator('li.produit', { hasText: 'Bière Intégration' }).locator('.badge--epuise').count() === 1, 'épuisé : badge sur la vitrine');
    await v.goto(B + '/caisse?e=comptoir-du-lac');
    verifier(await v.locator('.tuile', { hasText: 'Bière Intégration' }).first().isDisabled(), 'épuisé : tuile désactivée à la caisse');
    await p.click(`#produit-${idProd} .cat-bascule`);
    await p.waitForFunction(id => document.querySelector(`#produit-${id} .cat-bascule`).getAttribute('aria-pressed') === 'false', idProd);

    // 7. Taux du CDF : 2850 → 3000
    await p.goto(B + '/gestion/devises');
    await p.fill('#taux-2', '3000');
    await envoyer('#formulaire-taux button[type=submit]');
    verifier((await p.textContent('main')).includes('Taux enregistré : CDF.'), 'taux CDF 3000 enregistré');
    t = await ligneVitrine('comptoir-du-lac', '?devise=CDF', 'Bière Intégration');
    verifier(t.includes('12 000 FC') && t.includes('120 000 FC'), 'vitrine après taux (converti change, fixe non) : ' + t);
    await v.goto(B + '/caisse?e=comptoir-du-lac');
    if (await v.isEnabled('#vider')) { await v.click('#vider'); }
    await v.locator('.tuile', { hasText: 'Bière Intégration' }).first().click();
    await v.locator('#formats-liste .format', { hasText: 'Bouteille' }).click();
    verifier(net(await v.textContent('#ticket')).includes('12 000 FC'), 'caisse après taux : ' + net(await v.textContent('#ticket')).slice(60, 160));

    // 8. Établissement avec horaires et une zone à la grille VIP
    await p.goto(B + '/gestion/etablissements/nouveau');
    await p.fill('#etb-nom', 'Lounge Intégration');
    await p.check('input[name=type][value=lounge]');
    await p.fill('#etb-slogan-fr', 'Slogan intégration');
    await envoyer('button:has-text("Créer l\'établissement")');
    const idEtab = sql("SELECT id FROM etablissements WHERE slug='lounge-integration'");
    verifier(idEtab !== '', 'établissement créé (id ' + idEtab + ')');
    await p.goto(B + '/gestion/etablissements/' + idEtab);
    const ligne = p.locator('[data-horaire]:visible').nth(0);
    await ligne.locator('input[name$="[service_fr]"]').fill('Lounge');
    await ligne.locator('[data-tous-jours]').click();
    await ligne.locator('[data-ouverture]').fill('18:00');
    await ligne.locator('[data-fermeture]').fill('02:00');
    await envoyer('button:has-text("Enregistrer les horaires")');
    verifier(sql(`SELECT COUNT(*) FROM horaires WHERE etablissement_id=${idEtab}`) === '7', 'horaires : 7 jours');
    await p.fill('#etb-nz-nom-fr', 'Salon privé');
    await p.fill('#etb-nz-nom-en', 'Private lounge');
    await p.selectOption('#etb-nz-grille', { label: 'Prix VIP' });
    await envoyer('button:has-text("Ajouter la zone")');
    const zone = sql(`SELECT CONCAT(id,' ',code) FROM zones WHERE etablissement_id=${idEtab}`).split(' ');
    verifier(zone[1] === 'salon-prive', 'zone créée : ' + zone.join(' '));
    // Le produit est vendu dans le nouvel établissement (section 4 de la fiche)
    await p.goto(B + '/gestion/catalogue/produits/' + idProd);
    await p.check(`#vente input[name="etablissements[]"][value="${idEtab}"]`);
    await envoyer('#vente button[type=submit]');
    await v.goto(B + '/e/lounge-integration');
    t = net(await v.textContent('body'));
    verifier(t.includes('Lounge Intégration') && t.includes('Slogan intégration') && t.includes('18:00') && t.includes('02:00'), 'vitrine du nouvel établissement : nom, slogan, horaires');
    t = await ligneVitrine('lounge-integration', '?devise=USD', 'Bière Intégration');
    verifier(t.includes('$4'), 'nouvel établissement : prix normal ' + t);
    t = await ligneVitrine('lounge-integration', '?zone=salon-prive&devise=USD', 'Bière Intégration');
    verifier(t.includes('$6'), 'nouvel établissement, zone VIP : ' + t);
    verifier(await v.getAttribute('html', 'data-theme') === 'nuit', 'lounge : vitrine en nuit');

    // 9. Code QR pour cette zone
    await p.goto(B + '/gestion/qr');
    await p.selectOption('#etablissement_id', idEtab);
    const zonesProposees = await p.$$eval(`#zone_id optgroup[data-etablissement="${idEtab}"] option`, o => o.map(x => x.value));
    verifier(zonesProposees.includes(zone[0]), 'écran QR : la zone du nouvel établissement est proposée');
    await p.selectOption('#zone_id', zone[0]);
    await p.fill('#libelle', 'QR intégration');
    await envoyer('form[action="/gestion/qr"] button[type=submit]');
    const code = sql(`SELECT code FROM qr_codes WHERE etablissement_id=${idEtab} AND zone_id=${zone[0]}`);
    verifier(code !== '', 'code QR créé : ' + code);
    const rep = await v.goto(B + '/q/' + code);
    verifier(rep.status() === 200 && v.url().includes('/e/lounge-integration') && v.url().includes('zone=salon-prive'), 'redirection QR : ' + v.url());
    verifier(sql(`SELECT IFNULL(SUM(nombre),0) FROM qr_scans s JOIN qr_codes q ON q.id=s.qr_code_id WHERE q.code='${code}'`) === '1', 'scan compté');
    // Caisse du nouvel établissement avec la zone
    await v.goto(B + '/caisse?e=lounge-integration&zone=salon-prive');
    await v.locator('.tuile', { hasText: 'Bière Intégration' }).first().click();
    await v.locator('#formats-liste .format', { hasText: 'Bouteille' }).click();
    verifier(net(await v.textContent('#ticket')).includes('$6'), 'caisse nouvel établissement, zone VIP : $6');
    verifier(await v.getAttribute('html', 'data-theme') === 'nuit', 'caisse lounge : thème nuit');

    fs.writeFileSync(path.join(ICI, 'ids.json'), JSON.stringify({ idCat, idProd, idEtab, code, photo }));
  } catch (e) {
    echecs.push('exception : ' + e.message);
    console.log('EXCEPTION', e);
  }
  verifier(erreursJs.length === 0, 'aucune erreur JS ' + erreursJs.join(' | '));
  await nav.close();
  console.log(`\n${nb - echecs.length}/${nb} vérifications réussies`);
  if (echecs.length) console.log('Échecs :\n- ' + echecs.join('\n- '));
})();
