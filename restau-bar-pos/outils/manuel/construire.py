#!/usr/bin/env python3
"""Assemble docs/manuel/index.html depuis src/*.html : numéros de chapitre, sommaire collant (écran),
sommaire imprimé avec les numéros de page (pages.json, rempli par pdf.js), dimensions des images.
Signale les images manquantes et celles du dossier captures/ qui ne servent pas (--nettoyer les supprime)."""
import glob, html, json, os, re, sys
from PIL import Image

ICI = os.path.dirname(os.path.abspath(__file__))
MANUEL = '/home/user/Eglise/restau-bar-pos/docs/manuel'
CAPTURES = os.path.join(MANUEL, 'captures')

morceaux = sorted(glob.glob(os.path.join(ICI, 'src', '*.html')))
tete = open(morceaux[0], encoding='utf-8').read()
corps = ''.join(open(f, encoding='utf-8').read() for f in morceaux[1:])

# Numéros de chapitre
n = 0
chapitres = []  # (partie, id, numéro, titre)
partie = None
jetons = re.finditer(r'<div class="partie" id="([^"]+)">.*?<h2>(.*?)</h2>|<section class="chapitre" id="([^"]+)">\s*<h2>(.*?)</h2>', corps, re.S)
remplacements = []
for m in jetons:
    if m.group(1):
        partie = (m.group(1), m.group(2))
        chapitres.append(('partie', m.group(1), None, m.group(2)))
    else:
        n += 1
        chapitres.append(('chapitre', m.group(3), n, m.group(4)))
        remplacements.append((m.start(4), f'<span class="num">{n}</span>'))
for pos, texte in reversed(remplacements):
    corps = corps[:pos] + texte + corps[pos:]

def sans_balises(t):
    return re.sub(r'<[^>]+>', '', t)

# Sommaire collant (écran)
nav = ['<nav class="sommaire" aria-label="Sommaire">']
ouvert = False
for genre, ident, num, titre in chapitres:
    if genre == 'partie':
        if ouvert: nav.append('</ol>')
        nav.append(f'<p><a href="#{ident}">{titre}</a></p><ol>')
        ouvert = True
    else:
        nav.append(f'<li><a href="#{ident}"><span class="n">{num}</span>{titre}</a></li>')
if ouvert: nav.append('</ol>')
nav.append('</nav>')

# Sommaire imprimé
pages = {}
fichier_pages = os.path.join(ICI, 'pages.json')
if os.path.exists(fichier_pages):
    pages = json.load(open(fichier_pages))
imp = ['<nav class="sommaire-impression" aria-hidden="true"><h2>Sommaire</h2>']
ouvert = False
for genre, ident, num, titre in chapitres:
    if genre == 'partie':
        if ouvert: imp.append('</ol>')
        imp.append(f'<p>{titre}</p><ol>')
        ouvert = True
    else:
        page = pages.get(ident, '')
        imp.append(f'<li><a href="#{ident}"><span class="n">{num}</span><span>{titre}</span><span class="points"></span><span class="page" data-ancre="{ident}">{page}</span></a></li>')
if ouvert: imp.append('</ol>')
imp.append('</nav>')
corps = corps.replace('<!--SOMMAIRE-IMPRESSION-->', '\n'.join(imp))

# Dimensions des images, images manquantes
utilisees = set()
manquantes = []
def dimensions(m):
    src = m.group(1)
    nom = os.path.basename(src)
    chemin = os.path.join(MANUEL, src)
    utilisees.add(nom)
    if not os.path.exists(chemin):
        manquantes.append(src)
        return m.group(0)
    w, h = Image.open(chemin).size
    return m.group(0).replace(f'src="{src}"', f'src="{src}" width="{w}" height="{h}"', 1)
corps = re.sub(r'<img src="([^"]+)"', dimensions, corps)

page = tete + '\n<div class="cadre">\n' + '\n'.join(nav) + '\n<main>\n' + corps + '\n</main>\n</div>\n</body>\n</html>\n'
open(os.path.join(MANUEL, 'index.html'), 'w', encoding='utf-8').write(page)

presentes = {os.path.basename(f) for f in glob.glob(os.path.join(CAPTURES, '*'))}
inutiles = sorted(presentes - utilisees)
print(f'{n} chapitres, {len(utilisees)} images utilisées')
if manquantes: print('MANQUANTES :', ' '.join(manquantes))
if inutiles:
    print('INUTILISÉES :', ' '.join(inutiles))
    if '--nettoyer' in sys.argv:
        for f in inutiles: os.remove(os.path.join(CAPTURES, f))
        print(f'{len(inutiles)} supprimées')
