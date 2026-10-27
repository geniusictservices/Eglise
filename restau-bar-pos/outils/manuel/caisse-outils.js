// Fonctions de caisse communes aux scripts de captures
const o = require('./outils');
const { shot, aller, pret } = o;
async function partager(ctx, slug, nomAppareil, id = 'patrick@demo.test', mdp = 'Gerant-Goma-2026') {
  const p = await o.connecter(ctx, id, mdp);
  await aller(p, '/caisse?e=' + slug); await p.waitForTimeout(1200);
  await plusTard(p);
  await p.$eval('#quitter', b => b.click()); await p.waitForTimeout(250);
  await p.click('.partager > summary:has-text("caisse partagée")');
  await p.fill('#nom-appareil', nomAppareil);
  return p;
}
async function autoriser(p) {
  await Promise.all([p.waitForNavigation(), p.click('.partager__form button[type=submit]')]);
  await p.waitForSelector('#qui'); await pret(p);
}
async function plusTard(p) {
  if (await p.locator('#fenetre-garanties[open]').count()) { await p.click('#fenetre-garanties button:has-text("Plus tard")'); await p.waitForTimeout(200); }
}
async function entrer(p, nom, pin) {
  if (!(await p.locator('#qui').count())) { await fermerDialogues(p); await verrouiller(p); }
  await o.pinCaisse(p, nom, pin); await plusTard(p);
}
async function verrouiller(p) {
  await p.$eval('#quitter', b => b.click()); await p.waitForTimeout(200);
  await Promise.all([p.waitForNavigation(), p.click('#verrouiller')]); await p.waitForSelector('#qui'); await pret(p);
}
async function remonter(p) {
  await p.evaluate(() => { document.querySelectorAll('#grille, .catalogue, .grille, main, body, html').forEach(e => e.scrollTop = 0); window.scrollTo(0, 0); });
  await p.waitForTimeout(150);
}
// Capture d'une fenêtre haute : la fenêtre du navigateur s'agrandit le temps de la capture
async function grande(p, nom, sel, hauteur = 1500) {
  const v = p.viewportSize();
  await p.setViewportSize({ width: v.width, height: hauteur }); await p.waitForTimeout(250);
  await shot(p, nom, { sel });
  await p.setViewportSize(v); await p.waitForTimeout(150);
}
async function fermerDialogues(p) { await p.keyboard.press('Escape'); await p.waitForTimeout(200); }

module.exports = { partager, autoriser, plusTard, entrer, verrouiller, remonter, grande, fermerDialogues };
