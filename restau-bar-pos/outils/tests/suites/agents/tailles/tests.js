// Tests des sous-catégories et des tailles (serveur sur 8120, base rbp_integ).
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const B = 'http://integ.localhost:8250';
const ICI = __dirname;
const RACINE = '/home/user/Eglise/restau-bar-pos';
const MDP = fs.readFileSync(path.join(ICI, '../../instance.txt'), 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
const sql = q => execSync(`mysql -u root -N --default-character-set=utf8mb4 rbp_integ -e ${JSON.stringify("SET time_zone = '+02:00'; " + q)}`).toString().trim();
const TAILLE_JOURNAL = (() => { try { return fs.statSync(RACINE + '/storage/logs/php-erreurs.log').size; } catch (e) { return 0; } })();
const net = s => (s || '').replace(/[\s  ]+/g, ' ').trim();

let nb = 0; const echecs = [];
function verifier(c, m) { nb++; console.log((c ? 'ok    ' : 'ÉCHEC ') + m); if (!c) echecs.push(m); }

(async () => {
  const nav = await chromium.launch();
  const ctx = await nav.newContext({ viewport: { width: 1366, height: 860 }, locale: 'fr-FR' });
  await ctx.addCookies([{ name: 'lang', value: 'fr', url: B }]);
  const p = await ctx.newPage();
  const erreursJs = [];
  const suivre = pg => {
    pg.on('pageerror', e => erreursJs.push(pg.url() + ' ' + e.message));
    // Les réponses 422 (saisie refusée, voulue par le test) ne sont pas des erreurs
    pg.on('console', m => { if (m.type() === 'error' && !m.text().includes('status of 422')) erreursJs.push(pg.url() + ' console ' + m.text()); });
    pg.on('dialog', d => { erreursJs.push('dialog ' + d.message()); d.accept(); });
  };
  suivre(p);
  const v = await ctx.newPage(); suivre(v);
  const envoyer = async (pg, sel) => { await Promise.all([pg.waitForNavigation(), pg.click(sel)]); };
  const jeton = async () => p.getAttribute('input[name=csrf]', 'value');
  const poster = async (url, form) => p.request.post(B + url, { form, maxRedirects: 0 });
  const ligneVitrine = async (requete, nom) => {
    await v.goto(`${B}/e/${requete}`);
    const l = v.locator('li.produit', { has: v.locator('.produit__nom', { hasText: nom }) });
    return (await l.count()) ? net(await l.first().textContent()) : '';
  };

  try {
    await p.goto(B + '/gestion/connexion');
    await p.fill('#email', 'admin@demo.test'); await p.fill('#mot_de_passe', MDP);
    await envoyer(p, 'button[type=submit]');
    verifier(p.url().endsWith('/gestion'), 'connexion');

    // ---------- 1. Catégories : arbre ----------
    await p.goto(B + '/gestion/catalogue/categories');
    verifier(await p.locator('#categorie-1 > ol.cat-categories--sous > li#categorie-8').count() === 1, 'arbre : Locales sous Bières');
    verifier(await p.locator('#categorie-1 > ol.cat-categories--sous > li#categorie-9').count() === 1, 'arbre : Importées sous Bières');
    verifier(await p.locator('#categorie-5 > ol.cat-categories--sous > li').count() === 2, 'arbre : Whisky et Cognac sous Spiritueux');
    verifier(net(await p.textContent('#categorie-8 .cat-categorie__texte')).includes('2 produits'), 'Locales : 2 produits (tailles non comptées) : ' + net(await p.textContent('#categorie-8 .cat-categorie__texte')));
    verifier(net(await p.textContent('#categorie-1 > .cat-categorie__ligne')).includes('2 sous-catégories'), 'Bières : 2 sous-catégories');
    verifier(await p.locator('#categorie-1 > details.cat-confirmer').count() === 0, 'Bières : pas de bouton Supprimer');

    // Création d'une sous-catégorie sous Cocktails, puis d'une seconde
    await p.fill('#nouvelle-nom_fr', 'Sans alcool'); await p.fill('#nouvelle-nom_en', 'Alcohol-free');
    await p.selectOption('#nouvelle-parent', '3');
    await envoyer(p, '#nouvelle button[type=submit]');
    const idSA = sql("SELECT id FROM categories WHERE nom_fr='Sans alcool'");
    verifier(sql(`SELECT parent_id FROM categories WHERE id=${idSA}`) === '3', 'sous-catégorie créée sous Cocktails (id ' + idSA + ')');
    await p.fill('#nouvelle-nom_fr', 'Signatures'); await p.fill('#nouvelle-nom_en', 'Signatures');
    await p.selectOption('#nouvelle-parent', '3');
    await envoyer(p, '#nouvelle button[type=submit]');
    const idSig = sql("SELECT id FROM categories WHERE nom_fr='Signatures'");
    let ordre = await p.$$eval('#categorie-3 > ol > li', l => l.map(x => x.id));
    verifier(JSON.stringify(ordre) === JSON.stringify([`categorie-${idSA}`, `categorie-${idSig}`]), 'ordre initial des sous-catégories : ' + ordre);
    // Monter « Signatures » à l'intérieur de Cocktails
    await envoyer(p, `#categorie-${idSig} form[action$="/monter"] button`);
    ordre = await p.$$eval('#categorie-3 > ol > li', l => l.map(x => x.id));
    verifier(JSON.stringify(ordre) === JSON.stringify([`categorie-${idSig}`, `categorie-${idSA}`]), 'monter dans le niveau : ' + ordre);
    verifier(await p.isDisabled(`#categorie-${idSig} form[action$="/monter"] button`), 'première du niveau : ↑ désactivé');
    const racines = await p.$$eval('ol.cat-categories:not(.cat-categories--sous) > li', l => l.map(x => x.id));
    // La démo a aussi Ingrédients, Emballages et Préparations (stock) : l'ordre attendu vient de la base
    const racinesBase = sql('SELECT id FROM categories WHERE parent_id IS NULL ORDER BY position, id').split('\n').map(i => 'categorie-' + i.trim());
    verifier(racines.join(',') === racinesBase.join(',') && racines.slice(0, 7).join(',') === 'categorie-1,categorie-2,categorie-3,categorie-4,categorie-5,categorie-6,categorie-7', 'catégories principales inchangées : ' + racines);
    // Déplacer « Sans alcool » de Cocktails vers Sucreries et eaux (formulaire Modifier)
    await p.click(`#categorie-${idSA} details.cat-depliant summary`);
    await p.selectOption(`#c${idSA}-parent`, '2');
    await envoyer(p, `#categorie-${idSA} form.cat-formulaire button[type=submit]`);
    verifier(await p.locator(`#categorie-2 > ol > li#categorie-${idSA}`).count() === 1, 'déplacement : Sans alcool sous Sucreries et eaux');
    // Remettre « Signatures » en catégorie principale
    await p.click(`#categorie-${idSig} details.cat-depliant summary`);
    await p.selectOption(`#c${idSig}-parent`, '');
    await envoyer(p, `#categorie-${idSig} form.cat-formulaire button[type=submit]`);
    verifier(sql(`SELECT IFNULL(parent_id,'NULL') FROM categories WHERE id=${idSig}`) === 'NULL' && await p.locator(`ol.cat-categories:not(.cat-categories--sous) > li#categorie-${idSig}`).count() === 1, 'Signatures redevient principale (en fin de liste)');

    // Refus : 3e niveau par le formulaire (Bières, qui a des sous-catégories, sous Plats)
    await p.click('#categorie-1 > details.cat-depliant summary');
    await p.selectOption('#c1-parent', '6');
    await Promise.all([p.waitForNavigation(), p.click('#categorie-1 > details.cat-depliant button[type=submit]')]);
    let msg = net(await p.textContent('#categorie-1 > .cat-erreurs').catch(() => ''));
    verifier(msg.includes('a déjà des sous-catégories') && sql('SELECT IFNULL(parent_id,0) FROM categories WHERE id=1') === '0', 'refus 3e niveau (Bières sous Plats) : ' + msg);
    const options = await p.$$eval('#nouvelle-parent option', o => o.map(x => x.textContent.trim()));
    verifier(!options.some(o => o.includes('Locales')), 'les sous-catégories ne sont pas proposées comme parente');
    const t = await jeton();
    // Refus : 3e niveau en créant sous une sous-catégorie (requête forgée)
    let r = await poster('/gestion/catalogue/categories', { csrf: t, nom_fr: 'Niveau 3', nom_en: '', parent_id: '8', visible_public: '1' });
    let corps = net(await r.text());
    verifier(r.status() === 422 && corps.includes('est déjà une sous-catégorie') && sql("SELECT COUNT(*) FROM categories WHERE nom_fr='Niveau 3'") === '0', 'refus 3e niveau (création sous Locales) : ' + r.status());
    // Refus : boucle (Bières dans Locales, sa sous-catégorie)
    r = await poster('/gestion/catalogue/categories/1', { csrf: t, nom_fr: 'Bières', nom_en: 'Beers', parent_id: '8', visible_public: '1' });
    corps = net(await r.text());
    verifier(r.status() === 422 && corps.includes('une de ses propres sous-catégories'), 'refus boucle (Bières dans Locales) : ' + r.status());
    // Refus : elle-même
    r = await poster(`/gestion/catalogue/categories/${idSA}`, { csrf: t, nom_fr: 'Sans alcool', nom_en: '', parent_id: idSA, visible_public: '1' });
    verifier(r.status() === 422 && net(await r.text()).includes('dans elle-même'), 'refus : catégorie dans elle-même');
    // Refus de suppression : sous-catégories, puis produits actifs
    r = await poster('/gestion/catalogue/categories/1/supprimer', { csrf: t });
    corps = net(await r.text());
    verifier(r.status() === 422 && corps.includes('contient des sous-catégories (Locales, Importées)'), 'refus suppression de Bières (sous-catégories)');
    r = await poster('/gestion/catalogue/categories/8/supprimer', { csrf: t });
    corps = net(await r.text());
    verifier(r.status() === 422 && corps.includes('contient encore des produits'), 'refus suppression de Locales (produits actifs)');
    verifier(sql('SELECT COUNT(*) FROM categories WHERE id IN (1,8) AND actif=1') === '2', 'Bières et Locales toujours là');
    // Suppression permise d'une sous-catégorie vide
    await p.goto(B + '/gestion/catalogue/categories');
    await p.click(`#categorie-${idSA} details.cat-confirmer summary`);
    await envoyer(p, `#categorie-${idSA} details.cat-confirmer button[type=submit]`);
    verifier(sql(`SELECT COUNT(*) FROM categories WHERE id=${idSA}`) === '0', 'sous-catégorie vide supprimée');

    // Listes déroulantes en arbre
    await p.goto(B + '/gestion/catalogue');
    const filtre = await p.$$eval('#filtre-categorie option', o => o.map(x => x.textContent.trim()));
    verifier(filtre.indexOf('— Locales') === filtre.indexOf('Bières') + 1 && filtre.includes('— Cognac'), 'filtre du catalogue en arbre : ' + filtre.slice(0, 5).join(' | '));
    await p.goto(B + '/gestion/catalogue/produits/nouveau');
    const choix = await p.$$eval('#categorie_id option', o => o.map(x => x.textContent.trim()));
    verifier(choix.includes('— Importées') && choix.includes('— Whisky'), 'catégorie de la fiche en arbre');

    // ---------- 2. Liste du catalogue : tailles sous leur produit ----------
    await p.goto(B + '/gestion/catalogue');
    verifier(await p.locator('#categorie-8.cat-groupe--sous').count() === 1, 'liste : groupe Locales en retrait');
    let ids = await p.$$eval('#categorie-8 .cat-liste > li', l => l.map(x => x.id + (x.classList.contains('cat-produit--taille') ? '(t)' : '')));
    verifier(ids.join(',') === 'produit-1,produit-19(t),produit-20(t),produit-2,produit-21(t),produit-22(t)', 'liste : tailles sous Primus et Mützig : ' + ids);
    verifier(net(await p.textContent('#produit-1 .cat-produit__prix')).includes('2 tailles'), 'liste : « 2 tailles » sur Primus');
    verifier(net(await p.textContent('#produit-19')).includes('Petite 33 cl') && net(await p.textContent('#produit-19')).includes('$1.50') === false, 'liste : ligne Petite 33 cl : ' + net(await p.textContent('#produit-19 .cat-produit__texte')));
    await p.goto(B + '/gestion/catalogue?categorie=1');
    ids = await p.$$eval('.cat-groupe', l => l.map(x => x.id));
    verifier(ids.join(',') === 'categorie-8,categorie-9', 'filtre Bières : ses sous-catégories : ' + ids);
    await p.goto(B + '/gestion/catalogue?q=Petite');
    ids = await p.$$eval('.cat-liste > li:not(.cat-produit--taille)', l => l.map(x => x.id));
    // Les deux produits qui ont une taille « Petite », et ceux dont le nom contient « petite » (Casier vide Bralima (24 petites))
    const nomsPetite = sql("SELECT id FROM produits WHERE parent_id IS NULL AND nom_fr LIKE '%petite%' AND id NOT IN (1, 2)").split('\n').filter(Boolean).map(i => 'produit-' + i.trim());
    verifier(ids.slice(0, 2).join(',') === 'produit-1,produit-2' && ids.slice(2).sort().join(',') === nomsPetite.sort().join(','), 'recherche « Petite » dans la gestion : ' + ids);

    // ---------- 3. Fiche Primus : section Tailles ----------
    await p.goto(B + '/gestion/catalogue/produits/1');
    const tailles = await p.$$eval('#tailles .cat-taille', l => l.map(x => x.textContent.replace(/[\s  ]+/g, ' ').trim()));
    verifier(tailles.length === 2 && tailles[0].includes('Petite 33 cl') && tailles[0].includes('$1,50') && tailles[1].includes('Grande 72 cl') && tailles[1].includes('$2,00'), 'fiche Primus : tailles ' + tailles.join(' | '));
    verifier(net(await p.textContent('.cat-sommaire')).includes('3. Tailles'), 'sommaire : 3. Tailles');
    verifier(net(await p.textContent('main')).includes('Bières › Locales'), 'fiche : catégorie Bières › Locales');
    // Ajouter une taille « Magnum 1 l »
    await p.fill('#nt-taille_fr', 'Magnum'); await p.fill('#nt-taille_en', 'Magnum'); await p.fill('#nt-contenance', '1 l');
    await envoyer(p, '#nouvelle-taille button[type=submit]');
    const idMag = (p.url().match(/produits\/(\d+)/) || [])[1];
    verifier(idMag && idMag !== '1', 'taille créée, sa fiche est ouverte (id ' + idMag + ')');
    const ligne = sql(`SELECT CONCAT_WS('|', parent_id, categorie_id, nom_fr, nom_en, taille_fr, contenance, unite_base, badges, photo) FROM produits WHERE id=${idMag}`);
    verifier(ligne === '1|8|Primus Magnum|Primus Magnum|Magnum|1 l|bouteille|populaire|/assets/img/demo/produits/1-primus.svg', 'taille : reprend catégorie, unité, badges, photo : ' + ligne);
    verifier(sql(`SELECT GROUP_CONCAT(etablissement_id ORDER BY etablissement_id) FROM produit_etablissements WHERE produit_id=${idMag}`) === '1,2', 'taille : mêmes établissements');
    const bandeau = net(await p.textContent('.cat-bandeau-taille'));
    verifier(bandeau.startsWith('Taille Magnum de Primus') && await p.getAttribute('.cat-bandeau-taille a', 'href') === '/gestion/catalogue/produits/1#tailles', 'bandeau : ' + bandeau.slice(0, 40));
    verifier(await p.locator('#tailles').count() === 0 && await p.locator('#categorie_id').count() === 0, 'fiche taille : ni section Tailles ni choix de catégorie');
    verifier(net(await p.textContent('h1')).startsWith('Primus Magnum 1 l'), 'titre de la fiche taille : ' + net(await p.textContent('h1')));
    const formats = await p.$$eval('.cat-format[id^=format-]', els => els.map(e => e.id.replace('format-', '')));
    verifier(formats.length === 1, 'format Bouteille créé avec la taille');
    const f = formats[0];
    await p.fill(`input[name="prix[${f}][1][1]"]`, '3');
    await p.fill(`input[name="prix[${f}][1][2]"]`, '8 000');
    await p.fill(`input[name="prix[${f}][2][1]"]`, '4');
    await envoyer(p, 'form.cat-prix button[type=submit]');
    verifier(net(await p.textContent('.cat-annonce')).includes('Prix enregistrés'), 'prix de la taille enregistrés');
    // Casier de 6 (acheté seulement)
    await p.fill('#nf-nom_fr', 'Casier'); await p.fill('#nf-nom_en', 'Crate'); await p.fill('#nf-quantite', '6');
    await p.uncheck('#nouveau-format input[name=vendable]'); await p.check('#nouveau-format input[name=achetable]');
    await envoyer(p, '#nouveau-format button[type=submit]');
    // Modifier la contenance depuis la fiche de la taille : le nom suit
    await p.fill('#taille-contenance', '1,5 l');
    await envoyer(p, '#infos button[type=submit]');
    verifier(sql(`SELECT CONCAT(nom_fr,'|',contenance) FROM produits WHERE id=${idMag}`) === 'Primus Magnum|1,5 l', 'contenance modifiée');
    await p.fill('#taille-contenance', '1 l');
    await envoyer(p, '#infos button[type=submit]');
    // Renommer le produit principal : les noms des tailles suivent
    await p.goto(B + '/gestion/catalogue/produits/1');
    await p.fill('#nom_fr', 'Primus Kivu');
    await envoyer(p, '#infos button[type=submit]');
    verifier(sql(`SELECT GROUP_CONCAT(nom_fr ORDER BY position) FROM produits WHERE parent_id=1`) === 'Primus Kivu Petite,Primus Kivu Grande,Primus Kivu Magnum', 'renommage suivi par les tailles');
    await p.fill('#nom_fr', 'Primus');
    await envoyer(p, '#infos button[type=submit]');
    verifier(sql(`SELECT GROUP_CONCAT(nom_fr ORDER BY position) FROM produits WHERE parent_id=1`) === 'Primus Petite,Primus Grande,Primus Magnum', 'nom rétabli');
    // Ordre des tailles : monter Magnum
    await envoyer(p, `#taille-${idMag} form[action$="/monter"] button`);
    let ordreT = await p.$$eval('#tailles .cat-taille', l => l.map(x => x.id));
    verifier(ordreT.join(',') === `taille-19,taille-${idMag},taille-20`, 'ordre des tailles après ↑ : ' + ordreT);
    await v.goto(B + '/e/comptoir-du-lac?devise=USD');
    let rangs = await v.$$eval('#cat-8 li.produit:first-child .prix__format', l => l.map(x => x.textContent.trim()));
    verifier(rangs.join(',') === 'Petite 33 cl,Magnum 1 l,Grande 72 cl', 'vitrine suit l\'ordre des tailles : ' + rangs);
    await envoyer(p, `#taille-${idMag} form[action$="/descendre"] button`);
    ordreT = await p.$$eval('#tailles .cat-taille', l => l.map(x => x.id));
    verifier(ordreT.join(',') === `taille-19,taille-20,taille-${idMag}`, 'ordre des tailles rétabli : ' + ordreT);
    // Erreur de saisie : taille sans nom
    await p.fill('#nt-taille_fr', ''); await p.fill('#nt-contenance', '20 cl');
    await p.evaluate(() => document.querySelector('#nt-taille_fr').removeAttribute('required'));
    await Promise.all([p.waitForNavigation(), p.click('#nouvelle-taille button[type=submit]')]);
    verifier(net(await p.textContent('#tailles .cat-erreurs')).includes('Écrivez le nom de la taille'), 'erreur : taille sans nom');

    // ---------- 4. Vitrine ----------
    let l = await ligneVitrine('comptoir-du-lac?devise=USD', 'Primus');
    verifier(l.includes('Petite 33 cl $1,50') && l.includes('Grande 72 cl $2') && l.includes('Magnum 1 l $3'), 'vitrine USD : ' + l);
    verifier(await v.locator('li.produit', { has: v.locator('.produit__nom', { hasText: /^\s*Primus/ }) }).count() === 1, 'vitrine : Primus sur une seule ligne');
    verifier(await v.locator('#cat-1 > .sous-categorie').count() === 2 && net(await v.textContent('#cat-8 .sous-categorie__titre')) === 'Locales', 'vitrine : intertitres Locales / Importées');
    verifier(await v.locator('.categories__lien').count() === 7 && !(net(await v.textContent('.categories')).includes('Locales')), 'vitrine : barre des catégories au niveau 1');
    l = await ligneVitrine('comptoir-du-lac?devise=CDF', 'Primus');
    verifier(l.includes('Petite 33 cl 4 300 FC') && l.includes('Grande 72 cl 5 500 FC') && l.includes('Magnum 1 l 8 000 FC'), 'vitrine CDF (4 275 → 4 300, fixes 5 500 et 8 000) : ' + l);
    l = await ligneVitrine('comptoir-du-lac?devise=RWF', 'Primus');
    verifier(l.includes('Petite 33 cl 2 200 FRw') && l.includes('Grande 72 cl 2 900 FRw') && l.includes('Magnum 1 l 4 350 FRw'), 'vitrine RWF (2 175 → 2 200, 2 900, 4 350) : ' + l);
    l = await ligneVitrine('comptoir-du-lac?devise=USD', 'Vin rouge');
    verifier(l.includes('Verre 15 cl $5') && l.includes('Carafe 30 cl $9') && l.includes('Bouteille 75 cl $28'), 'vitrine : vin en cl ' + l);
    l = await ligneVitrine('comptoir-du-lac?devise=USD', 'Johnnie');
    verifier(l.includes('Dose 4 cl $5') && l.includes('Double 8 cl $9') && l.includes('Bouteille 70 cl $75'), 'vitrine : whisky en cl ' + l);
    verifier(await v.locator('#cat-10 li.produit', { hasText: 'Johnnie' }).count() === 1 && await v.locator('#cat-11 li.produit', { hasText: 'Hennessy' }).count() === 1, 'vitrine : Whisky / Cognac');
    l = await ligneVitrine('volcano-club?zone=vip&devise=USD', 'Primus');
    verifier(l.includes('Petite 33 cl $1,50') && l.includes('Grande 72 cl $3') && l.includes('Magnum 1 l $4'), 'vitrine VIP : ' + l);
    l = await ligneVitrine('volcano-club?zone=vip&devise=USD', 'Hennessy');
    verifier(l.includes('Double 8 cl $11') && l.includes('Bouteille 70 cl $110'), 'vitrine VIP Hennessy : ' + l);
    l = await ligneVitrine('volcano-club?zone=vip&devise=USD', 'Mützig');
    verifier(l.includes('Grande 65 cl $3,50') && l.includes('Petite 33 cl $1,80'), 'vitrine VIP Mützig : ' + l);
    l = await ligneVitrine('comptoir-du-lac?devise=USD&lang=en', 'Primus');
    verifier(l.includes('Small 33 cl $1.50') && l.includes('Large 72 cl $2'), 'vitrine anglais : ' + l);
    await v.goto(B + '/e/comptoir-du-lac?lang=fr');
    // Recherche par le nom d'une taille
    await v.fill('#recherche', 'magnum');
    let visibles = await v.$$eval('li.produit:not([hidden]) .produit__nom', l => l.map(x => x.childNodes[0].textContent.trim()));
    verifier(visibles.join(',') === 'Primus', 'vitrine : recherche « magnum » : ' + visibles);
    verifier(await v.isHidden('#cat-9') && await v.isVisible('#cat-8'), 'vitrine : intertitre Importées caché pendant la recherche');
    await v.fill('#recherche', 'petite');
    visibles = await v.$$eval('li.produit:not([hidden]) .produit__nom', l => l.map(x => x.childNodes[0].textContent.trim()));
    verifier(visibles.join(',') === 'Primus,Mützig', 'vitrine : recherche « petite » : ' + visibles);
    await v.fill('#recherche', '');

    // ---------- 5. Caisse ----------
    await v.goto(B + '/caisse?e=comptoir-du-lac');
    await v.evaluate(() => sessionStorage.clear());
    await v.reload();
    const onglets = await v.$$eval('#onglets .onglet', l => l.map(x => x.textContent));
    verifier(onglets.join(',') === 'Tout,Bières,Sucreries et eaux,Cocktails,Vins et champagnes,Spiritueux,Plats,À grignoter', 'caisse : onglets de niveau 1 : ' + onglets);
    verifier(await v.isHidden('#sous-onglets'), 'caisse : pas de sous-catégories sur « Tout »');
    await v.click('#onglets .onglet:has-text("Bières")');
    const sous = await v.$$eval('#sous-onglets .sous-onglet', l => l.map(x => x.textContent));
    verifier(sous.join(',') === 'Toutes,Locales,Importées', 'caisse : rangée de sous-catégories : ' + sous);
    let tuilesV = await v.$$eval('.tuile:not([hidden]) .tuile__nom', l => l.map(x => x.textContent));
    verifier(tuilesV.join(',') === 'Primus,Mützig,Heineken,Guinness', 'caisse : Bières, Toutes : ' + tuilesV);
    await v.click('#sous-onglets .sous-onglet:has-text("Importées")');
    tuilesV = await v.$$eval('.tuile:not([hidden]) .tuile__nom', l => l.map(x => x.textContent));
    verifier(tuilesV.join(',') === 'Heineken,Guinness', 'caisse : Importées : ' + tuilesV);
    await v.click('#sous-onglets .sous-onglet:has-text("Locales")');
    tuilesV = await v.$$eval('.tuile:not([hidden]) .tuile__nom', l => l.map(x => x.textContent));
    verifier(tuilesV.join(',') === 'Primus,Mützig', 'caisse : Locales : ' + tuilesV);
    const colonnes = await v.$eval('#grille', g => getComputedStyle(g).gridTemplateColumns.split(' ').length);
    verifier(colonnes === 5, 'caisse : 5 tuiles par ligne');
    const tuile = v.locator('.tuile', { has: v.locator('.tuile__nom', { hasText: /^Primus$/ }) });
    const texteTuile = net(await tuile.textContent());
    verifier(texteTuile.includes('3 tailles') && texteTuile.includes('4 300 FC+'), 'tuile Primus : ' + texteTuile);
    await tuile.click();
    const lignesChoix = await v.$$eval('#formats-liste .format', l => l.map(x => x.textContent.replace(/[\s  ]+/g, ' ').trim()));
    verifier(lignesChoix.join(' | ') === 'Petite 33 cl · Bouteille4 300 FC | Grande 72 cl · Bouteille5 500 FC | Magnum 1 l · Bouteille8 000 FC', 'fenêtre de choix : ' + lignesChoix.join(' | '));
    await v.click('#formats-liste .format:has-text("Grande")');
    await tuile.click(); await v.click('#formats-liste .format:has-text("Petite")');
    await tuile.click(); await v.click('#formats-liste .format:has-text("Petite")');
    await tuile.click(); await v.click('#formats-liste .format:has-text("Magnum")');
    const noms = await v.$$eval('#lignes .ligne__nom', l => l.map(x => x.textContent.replace(/\u00a0/g, ' ')));
    verifier(noms.join(',') === 'Primus Grande 72 cl,Primus Petite 33 cl,Primus Magnum 1 l', 'lignes du ticket : ' + noms);
    verifier(await v.locator('#lignes .ligne__format').count() === 0, 'ticket : pas de « Bouteille » répété');
    verifier(net(await v.textContent('#total')) === '22 100 FC', 'total CDF 5 500 + 2×4 300 + 8 000 = 22 100 FC : ' + net(await v.textContent('#total')));
    verifier(net(await v.textContent('#autres-devises')) === '$8 · 11 650 FRw', 'dollar en deuxième : USD 2 + 2×1,50 + 3 = $8 ; RWF 2 900 + 2×2 200 + 4 350 = 11 650 : ' + net(await v.textContent('#autres-devises')));
    verifier(net(await tuile.locator('.tuile__qte').textContent()) === '4', 'pastille de quantité de Primus : 4');
    // Whisky : Spiritueux > Whisky, double
    await v.click('#onglets .onglet:has-text("Spiritueux")');
    verifier((await v.$$eval('#sous-onglets .sous-onglet', l => l.map(x => x.textContent))).join(',') === 'Toutes,Whisky,Cognac', 'Spiritueux : Whisky, Cognac');
    await v.click('#sous-onglets .sous-onglet:has-text("Whisky")');
    tuilesV = await v.$$eval('.tuile:not([hidden]) .tuile__nom', l => l.map(x => x.textContent));
    verifier(tuilesV.join(',') === 'Johnnie Walker Black Label', 'Whisky : ' + tuilesV);
    await v.click('.tuile:has-text("Johnnie")');
    const choixJW = await v.$$eval('#formats-liste .format', l => l.map(x => x.textContent.replace(/[\s  ]+/g, ' ').trim()));
    verifier(choixJW.join(' | ') === 'Dose 4 cl14 300 FC | Double 8 cl25 700 FC | Bouteille 70 cl213 800 FC', 'choix Johnnie Walker : ' + choixJW.join(' | '));
    await v.click('#formats-liste .format:has-text("Double")');
    verifier(net(await v.textContent('#lignes li:last-child')).includes('Double 8 cl'), 'ligne Johnnie Walker · Double 8 cl');
    verifier(net(await v.textContent('#total')) === '47 800 FC', 'total CDF 22 100 + 25 700 (25 650 arrondi) = 47 800 FC');
    verifier(net(await v.textContent('#autres-devises')) === '$17 · 24 700 FRw', 'dollar en deuxième : USD 8 + 9 = $17 ; RWF 11 650 + 13 050 : ' + net(await v.textContent('#autres-devises')));
    // Le ticket survit au rechargement
    await v.reload();
    verifier(net(await v.textContent('#total')) === '47 800 FC' && await v.locator('#lignes .ligne').count() === 4, 'ticket gardé après rechargement');
    // Ticket ancien ou abîmé : lignes inconnues retirées, contenu illisible vidé
    await v.evaluate(() => sessionStorage.setItem('caisse.ticket.comptoir-du-lac.1', JSON.stringify([{ id: 99999, qte: 1 }, { id: 1, qte: 2 }, { id: 3, qte: -1 }, { id: 26, qte: 'x' }])));
    await v.reload();
    verifier(net(await v.textContent('#lignes')).startsWith('Primus Grande 72 cl') && await v.locator('#lignes .ligne').count() === 1 && net(await v.textContent('#total')) === '11 000 FC', 'ancien ticket : 2 × format 1 (Primus Grande) gardé, le reste retiré');
    await v.evaluate(() => sessionStorage.setItem('caisse.ticket.comptoir-du-lac.1', '{abîmé'));
    await v.reload();
    verifier(await v.locator('#lignes .ligne').count() === 0 && await v.evaluate(() => sessionStorage.getItem('caisse.ticket.comptoir-du-lac.1')) === null, 'ticket illisible vidé proprement');
    // Recherche par taille
    await v.fill('#recherche', 'magnum');
    tuilesV = await v.$$eval('.tuile:not([hidden]) .tuile__nom', l => l.map(x => x.textContent));
    verifier(tuilesV.join(',') === 'Primus', 'caisse : recherche « magnum » : ' + tuilesV);
    await v.press('#recherche', 'Enter');
    verifier(await v.isVisible('#formats') && await v.locator('#lignes .ligne').count() === 0, 'Entrée sur un seul résultat : fenêtre des tailles, rien ajouté d\'office');
    await v.keyboard.press('Escape');
    await v.fill('#recherche', '');

    // Club, carré VIP (USD et CDF). La démo met le Volcano Club en « bloquer » (stock négatif) et la Magnum y est à 0 :
    // on vérifie les prix VIP en mode « vendre » (épuisé n'afficherait pas son prix)
    sql("UPDATE stock_parametres SET mode_negatif = 'vendre' WHERE etablissement_id = 2");
    await v.goto(B + '/caisse?e=volcano-club&zone=vip');
    await v.evaluate(() => sessionStorage.clear()); await v.reload();
    const tuileClub = v.locator('.tuile', { has: v.locator('.tuile__nom', { hasText: /^Primus$/ }) });
    await tuileClub.click();
    const choixVip = await v.$$eval('#formats-liste .format', l => l.map(x => x.textContent.replace(/[\s  ]+/g, ' ').trim()));
    verifier(choixVip.join(' | ') === 'Petite 33 cl · Bouteille4 300 FC | Grande 72 cl · Bouteille8 600 FC | Magnum 1 l · Bouteille11 400 FC', 'VIP : choix ' + choixVip.join(' | '));
    await v.click('#formats-liste .format:has-text("Grande")');
    await tuileClub.click(); await v.click('#formats-liste .format:has-text("Magnum")');
    await v.click('.tuile:has-text("Mützig")'); await v.click('#formats-liste .format:has-text("Grande")');
    verifier(net(await v.textContent('#total')) === '30 000 FC', 'VIP : total CDF 8 600 (8 550) + 11 400 + 10 000 (9 975) = 30 000 FC : ' + net(await v.textContent('#total')));
    verifier(net(await v.textContent('#autres-devises')) === '$10,50', 'VIP : dollar en deuxième, USD 3 + 4 + 3,50 = $10,50 : ' + net(await v.textContent('#autres-devises')));
    verifier(await v.getAttribute('html', 'data-theme') === 'nuit', 'caisse du club : thème nuit');

    // ---------- 6. Épuisé d'une seule taille ----------
    await p.goto(B + '/gestion/catalogue?categorie=8');
    await p.click('#produit-19 .cat-bascule');
    await p.waitForFunction(() => document.querySelector('#produit-19 .cat-bascule').getAttribute('aria-pressed') === 'true');
    verifier(sql('SELECT CONCAT(disponible, (SELECT disponible FROM produits WHERE id=1)) FROM produits WHERE id=19') === '01', 'Petite épuisée, Primus disponible');
    l = await ligneVitrine('comptoir-du-lac?devise=USD', 'Primus');
    const ligneP = v.locator('#cat-8 li.produit').first();
    verifier(!(await ligneP.evaluate(x => x.classList.contains('produit--epuise'))) && await ligneP.locator('.prix--epuise').count() === 1 && net(await ligneP.locator('.prix--epuise').textContent()).includes('Petite 33 cl'), 'vitrine : seule la Petite est épuisée : ' + l);
    await v.goto(B + '/caisse?e=comptoir-du-lac');
    const tp = v.locator('.tuile', { has: v.locator('.tuile__nom', { hasText: /^Primus$/ }) });
    verifier(!(await tp.isDisabled()), 'caisse : tuile Primus toujours active');
    await tp.click();
    verifier(await v.locator('#formats-liste .format:has-text("Petite")').isDisabled() && net(await v.textContent('#formats-liste .format:has-text("Petite")')).includes('Épuisé') && !(await v.locator('#formats-liste .format:has-text("Grande")').isDisabled()), 'caisse : Petite épuisée dans la fenêtre, Grande disponible');
    await v.keyboard.press('Escape');
    await p.click('#produit-19 .cat-bascule');
    await p.waitForFunction(() => document.querySelector('#produit-19 .cat-bascule').getAttribute('aria-pressed') === 'false');
    // Produit principal épuisé : toutes ses tailles
    await p.click('#produit-2 .cat-bascule');
    await p.waitForFunction(() => document.querySelector('#produit-2 .cat-bascule').getAttribute('aria-pressed') === 'true');
    await v.goto(B + '/caisse?e=comptoir-du-lac');
    verifier(await v.locator('.tuile', { hasText: 'Mützig' }).isDisabled(), 'Mützig épuisée : tuile désactivée');
    await p.click('#produit-2 .cat-bascule');
    await p.waitForFunction(() => document.querySelector('#produit-2 .cat-bascule').getAttribute('aria-pressed') === 'false');

    // ---------- 7. Désactivation ----------
    await p.goto(B + `/gestion/catalogue/produits/${idMag}`);
    await p.click('#desactiver summary');
    await envoyer(p, '#desactiver button[type=submit]');
    verifier(p.url().endsWith('/gestion/catalogue/produits/1#tailles') && sql(`SELECT actif FROM produits WHERE id=${idMag}`) === '0' && sql('SELECT actif FROM produits WHERE id=1') === '1', 'désactiver une taille seule : retour à Primus');
    await p.goto(B + '/gestion/catalogue/produits/1');
    verifier(net(await p.textContent('#desactiver')).includes('Ses 2 tailles seront aussi désactivées'), 'explication : les tailles suivent');
    await p.click('#desactiver summary');
    await envoyer(p, '#desactiver button[type=submit]');
    verifier(sql('SELECT GROUP_CONCAT(actif) FROM produits WHERE id IN (1,19,20)') === '0,0,0', 'désactiver Primus désactive ses tailles');
    verifier(net(await p.textContent('.cat-annonce')).includes('et ses 2 tailles sont désactivés'), 'message : ' + net(await p.textContent('.cat-annonce')));
    await v.goto(B + '/e/comptoir-du-lac');
    verifier(await v.locator('.produit__nom', { hasText: /^\s*Primus/ }).count() === 0, 'vitrine : Primus disparu');
    await p.goto(B + '/gestion/catalogue?desactives=1');
    const desact = await p.$$eval('.cat-liste > li', l => l.map(x => x.id + (x.classList.contains('cat-produit--taille') ? '(t)' : '')));
    verifier(desact.includes('produit-1') && desact.includes('produit-19(t)') && desact.includes(`produit-${idMag}(t)`), 'liste des désactivés : Primus et ses tailles : ' + desact);
    await p.goto(B + '/gestion/catalogue/produits/1');
    await envoyer(p, '#reactiver button[type=submit]');
    verifier(sql(`SELECT GROUP_CONCAT(actif) FROM produits WHERE id IN (1,19,20,${idMag})`) === '1,1,1,1', 'réactiver Primus réactive ses tailles');

    // ---------- 8. Anglais, thèmes, largeur, clés brutes ----------
    const pages = ['/gestion/catalogue/categories', '/gestion/catalogue', '/gestion/catalogue/produits/1', `/gestion/catalogue/produits/${idMag}`, '/e/comptoir-du-lac', '/e/volcano-club?zone=vip', '/caisse?e=comptoir-du-lac', '/caisse?e=volcano-club&zone=vip'];
    const cles = /\b(catalogue|categories|tailles|caisse|vitrine|badge|unite|gestion)\.[a-z_]+\b/;
    for (const lang of ['fr', 'en']) {
      for (const [w, h, appareil] of [[1366, 860, 'ordi'], [390, 844, 'tel']]) {
        for (const theme of ['jour', 'nuit']) {
          const c2 = await nav.newContext({ viewport: { width: w, height: h }, locale: lang === 'fr' ? 'fr-FR' : 'en-GB' });
          await c2.addCookies([{ name: 'lang', value: lang, url: B }]);
          await c2.addInitScript(th => { try { ['gestion.theme', 'vitrine.theme', 'caisse.theme.comptoir-du-lac', 'caisse.theme.volcano-club'].forEach(k => localStorage.setItem(k, th)); } catch (e) {} }, theme);
          const pg = await c2.newPage(); suivre(pg);
          await pg.goto(B + '/gestion/connexion');
          await pg.fill('#email', 'admin@demo.test'); await pg.fill('#mot_de_passe', MDP);
          await Promise.all([pg.waitForNavigation(), pg.click('button[type=submit]')]);
          // Le compte a une langue préférée : on force celle du test
          await pg.goto(B + '/gestion?lang=' + lang);
          const problemes = [];
          for (const u of pages) {
            await pg.goto(B + u + (u.includes('?') ? '&' : '?') + 'lang=' + lang);
            if (u.startsWith('/caisse')) {
              await pg.click('#onglets .onglet:nth-child(2)');
              if (appareil === 'tel') { await pg.click('.tuile:has-text("Primus")'); await pg.keyboard.press('Escape'); }
            }
            const etat = await pg.evaluate(() => ({ large: document.documentElement.scrollWidth - document.documentElement.clientWidth, theme: document.documentElement.dataset.theme, texte: document.body.innerText, langue: document.documentElement.lang }));
            if (etat.large > 0) problemes.push(u + ' déborde de ' + etat.large + ' px');
            if (etat.theme !== theme) problemes.push(u + ' thème ' + etat.theme);
            const brute = etat.texte.match(cles);
            if (brute) problemes.push(u + ' clé brute ' + brute[0]);
            if (lang === 'en' && /Ajouter une taille|Sous-catégorie de|Toutes|Locales ›/.test(etat.texte) && !u.startsWith('/e/')) problemes.push(u + ' texte français en anglais');
          }
          verifier(problemes.length === 0, `${lang} ${appareil} ${theme} : ${pages.length} pages sans débordement ni clé brute ${problemes.join(' ; ')}`);
          await c2.close();
        }
      }
    }
    fs.writeFileSync(path.join(ICI, 'ids.json'), JSON.stringify({ idMag, idSig }));
  } catch (e) {
    echecs.push('exception : ' + e.message);
    console.log('EXCEPTION', e);
  }
  verifier(erreursJs.length === 0, 'aucune erreur JS ' + erreursJs.join(' | '));
  // Le journal est commun à toutes les instances du poste : seules les lignes écrites pendant ce test comptent
  const journal = fs.readFileSync(RACINE + '/storage/logs/php-erreurs.log').subarray(TAILLE_JOURNAL).toString('utf8');
  verifier(journal.trim() === '', 'journal PHP vide ' + journal.slice(0, 300));
  await nav.close();
  console.log(`\n${nb - echecs.length}/${nb} vérifications réussies`);
  if (echecs.length) console.log('Échecs :\n- ' + echecs.join('\n- '));
})();
