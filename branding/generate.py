"""Génère tous les fichiers du logo Waumini (piste H : les mains en coupe).

Usage : python3 branding/generate.py
Dépendances : fonttools, cairosvg, pillow
"""
from pathlib import Path

import cairosvg
from fontTools.pens.boundsPen import BoundsPen
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.ttLib import TTFont
from PIL import Image

ROOT = Path(__file__).resolve().parent
FONT = ROOT / 'fonts' / 'Outfit-Bold.ttf'

INK = '#173F4E'    # bleu-vert profond : confiance, sérieux
OCHRE = '#E09A2D'  # ocre : soleil, chaleur
TERRA = '#B5532F'  # terre cuite : la terre, les racines
CREAM = '#FBF8F2'  # fond clair
WHITE = '#FFFFFF'

# Le symbole est dessiné dans un repère de 120 unités ; sa boîte utile est
# centrée sur (60, 65) et tient dans un carré de 108 unités.
HANDS = 'M16,30 C16,74 29,100 45,100 C55,100 60,91 60,78 C60,91 65,100 75,100 C91,100 104,74 104,30'
SYMBOL_BOX = (6, 11, 108, 108)


def symbol(hands=INK, center=OCHRE, sides=TERRA):
    return (
        f'<path d="{HANDS}" fill="none" stroke="{hands}" stroke-width="15" '
        f'stroke-linecap="round" stroke-linejoin="round"/>'
        f'<circle cx="40" cy="56" r="9" fill="{sides}"/>'
        f'<circle cx="60" cy="44" r="10.5" fill="{center}"/>'
        f'<circle cx="80" cy="56" r="9" fill="{sides}"/>'
    )


def wordmark(size, color=INK, dots=OCHRE, tracking=1.0):
    """Le mot « waumini » vectorisé ; les points des i sont des têtes ocre."""
    font = TTFont(FONT)
    glyphs, cmap = font.getGlyphSet(), font.getBestCmap()
    scale = size / font['head'].unitsPerEm
    paths, circles, x = [], [], 0.0
    for ch in 'waumini':
        name = cmap[0x131] if ch == 'i' else cmap[ord(ch)]
        pen = SVGPathPen(glyphs)
        glyphs[name].draw(TransformPen(pen, (scale, 0, 0, -scale, x, 0)))
        paths.append(pen.getCommands())
        if ch == 'i':
            bounds = BoundsPen(glyphs)
            glyphs[cmap[ord('i')]].draw(bounds)
            xmin, _, xmax, ymax = bounds.bounds
            r = (xmax - xmin) * scale / 2 * 1.08
            circles.append((x + (xmin + xmax) * scale / 2, -ymax * scale + r * 0.95, r))
        x += glyphs[name].width * scale + tracking
    svg = f'<path d="{" ".join(paths)}" fill="{color}"/>'
    svg += ''.join(f'<circle cx="{cx:.2f}" cy="{cy:.2f}" r="{r:.2f}" fill="{dots}"/>' for cx, cy, r in circles)
    return svg, x - tracking


def doc(view_box, body, width=None, height=None):
    size = f' width="{width}" height="{height}"' if width else ''
    return f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="{view_box}"{size}>{body}</svg>\n'


def horizontal(hands=INK, word=INK):
    text, w = wordmark(62, color=word)
    body = f'<g transform="translate(-8,-22)">{symbol(hands=hands)}</g>'
    body += f'<g transform="translate(122,78)">{text}</g>'
    return doc(f'0 0 {122 + w + 2:.0f} 86', body)


def vertical(hands=INK, word=INK):
    text, w = wordmark(46, color=word)
    width = max(104, w) + 8
    body = f'<g transform="translate({width / 2 - 60:.1f},-22)">{symbol(hands=hands)}</g>'
    body += f'<g transform="translate({(width - w) / 2:.1f},140)">{text}</g>'
    return doc(f'0 0 {width:.0f} 152', body)


def app_icon(radius=24, scale=0.78, bg=INK):
    """Icône carrée : mains blanches sur fond bleu-vert."""
    s = scale
    inner = f'<g transform="translate({60 - 60 * s:.2f},{60 - 65 * s:.2f}) scale({s})">{symbol(hands=WHITE)}</g>'
    rect = f'<rect width="120" height="120" rx="{radius}" fill="{bg}"/>'
    return doc('0 0 120 120', rect + inner)


def write(path, svg):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(svg)


def png(svg, path, width, height=None):
    path.parent.mkdir(parents=True, exist_ok=True)
    cairosvg.svg2png(bytestring=svg.encode(), write_to=str(path), output_width=width, output_height=height)


def wide_tile(width, height, bg=INK):
    """Tuile Windows rectangulaire : symbole centré sur fond plein."""
    s = height / 120 * 0.7
    body = f'<rect width="{width}" height="{height}" fill="{bg}"/>'
    body += f'<g transform="translate({width / 2 - 60 * s:.2f},{height / 2 - 65 * s:.2f}) scale({s:.4f})">{symbol(hands=WHITE)}</g>'
    return doc(f'0 0 {width} {height}', body)


def main():
    logo = ROOT / 'logo'
    icons = ROOT / 'icons'
    x, y, w, h = SYMBOL_BOX
    box = f'{x} {y} {w} {h}'

    svgs = {
        'waumini-symbole.svg': doc(box, symbol()),
        'waumini-symbole-blanc.svg': doc(box, symbol(hands=WHITE)),
        'waumini-symbole-mono.svg': doc(box, symbol(hands=INK, center=INK, sides=INK)),
        'waumini-horizontal.svg': horizontal(),
        'waumini-horizontal-blanc.svg': horizontal(hands=WHITE, word=WHITE),
        'waumini-vertical.svg': vertical(),
        'waumini-vertical-blanc.svg': vertical(hands=WHITE, word=WHITE),
        'waumini-icone-app.svg': app_icon(),
    }
    for name, svg in svgs.items():
        write(logo / name, svg)

    # Aperçus PNG du logo
    png(svgs['waumini-horizontal.svg'], logo / 'png' / 'waumini-horizontal.png', 1200)
    png(svgs['waumini-vertical.svg'], logo / 'png' / 'waumini-vertical.png', 800)
    png(svgs['waumini-symbole.svg'], logo / 'png' / 'waumini-symbole.png', 1024)

    # Web et PWA
    icon = app_icon()
    full = app_icon(radius=0)                 # iOS arrondit lui-même
    maskable = app_icon(radius=0, scale=0.6)  # zone de sécurité de 80 %
    write(icons / 'favicon.svg', icon)
    for size in (16, 32, 48, 192, 512):
        png(icon, icons / f'icon-{size}.png', size)
    png(maskable, icons / 'icon-maskable-512.png', 512)
    png(full, icons / 'apple-touch-icon.png', 180)
    Image.open(icons / 'icon-48.png').save(
        icons / 'favicon.ico', sizes=[(16, 16), (32, 32), (48, 48)],
        append_images=[Image.open(icons / f'icon-{s}.png') for s in (16, 32)])

    # Windows : application installée (PWA / MSIX) et tuiles du menu Démarrer
    win = icons / 'windows'
    for size in (44, 50, 71, 150, 310):
        png(full, win / f'Square{size}x{size}Logo.png', size)
    png(wide_tile(310, 150), win / 'Wide310x150Logo.png', 310, 150)
    png(wide_tile(620, 300), win / 'SplashScreen.png', 620, 300)
    win_ico = [256, 64, 48, 32, 24, 16]
    for s in win_ico:
        png(icon, win / f'.tmp-{s}.png', s)
    Image.open(win / '.tmp-256.png').save(
        win / 'waumini.ico', sizes=[(s, s) for s in win_ico],
        append_images=[Image.open(win / f'.tmp-{s}.png') for s in win_ico[1:]])
    for s in win_ico:
        (win / f'.tmp-{s}.png').unlink()

    # Planche de présentation
    sheet = (ROOT / 'planche-logo.svg')
    write(sheet, presentation(svgs))
    png(sheet.read_text(), ROOT / 'planche-logo.png', 1400)


def presentation(svgs):
    def inner(svg):
        return svg.split('>', 1)[1].rsplit('</svg>', 1)[0]

    def vb(svg):
        return [float(v) for v in svg.split('viewBox="')[1].split('"')[0].split()]

    def place(svg, x, y, width):
        bx, by, bw, bh = vb(svg)
        s = width / bw
        return f'<g transform="translate({x},{y}) scale({s:.4f}) translate({-bx},{-by})">{inner(svg)}</g>'

    body = f'<rect width="1400" height="900" fill="{CREAM}"/>'
    body += f'<rect x="760" y="0" width="640" height="900" fill="{INK}"/>'
    body += place(svgs['waumini-horizontal.svg'], 70, 90, 600)
    body += place(svgs['waumini-vertical.svg'], 220, 360, 300)
    body += place(svgs['waumini-horizontal-blanc.svg'], 830, 90, 500)
    body += f'<rect x="839" y="379" width="202" height="202" rx="41" fill="none" stroke="#3C6676" stroke-width="2"/>'
    body += place(svgs['waumini-icone-app.svg'], 840, 380, 200)
    body += place(svgs['waumini-symbole-blanc.svg'], 1110, 390, 180)
    for i, (col, name) in enumerate([(INK, '#173F4E'), (OCHRE, '#E09A2D'), (TERRA, '#B5532F'), (CREAM, '#FBF8F2')]):
        body += f'<rect x="{70 + i * 160}" y="740" width="140" height="90" rx="12" fill="{col}" stroke="#E2DACB"/>'
    for i, size in enumerate((64, 32, 16)):
        x = 840 + i * 110
        body += f'<rect x="{x - 1}" y="699" width="{size + 2}" height="{size + 2}" rx="{size * 0.2 + 1:.1f}" fill="none" stroke="#3C6676" stroke-width="1.5"/>'
        body += place(svgs['waumini-icone-app.svg'], x, 700, size)
    return doc('0 0 1400 900', body, 1400, 900)


if __name__ == '__main__':
    main()
