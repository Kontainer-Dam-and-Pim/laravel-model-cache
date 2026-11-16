import re
import sys
from pathlib import Path

from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.ttLib import TTFont
from fontTools.varLib.instancer import instantiateVariableFont

WIDTH, HEIGHT = 1280, 320
ICON_HEIGHT = 236
TEXT_X = 380
TITLE = ('Laravel Model Cache', 600, 68, 150)
TAGLINE = (
    ('Automatic Redis query cache for Eloquent and the query builder,', 400, 26, 206),
    ('invalidated by writes, transactions, foreign keys, views and triggers.', 400, 26, 242),
)
THEMES = {
    'dark': {'surface': '#14151A', 'title': '#F5F5F4', 'tagline': '#A3A4AC'},
    'light': {'surface': '#F6F8FA', 'title': '#14151A', 'tagline': '#55565E'},
}


def instance(font_path: Path, weight: int) -> TTFont:
    return instantiateVariableFont(TTFont(font_path), {'wght': weight, 'opsz': 32}, inplace=False)


def text_path(font: TTFont, text: str, size: int, x: float, baseline: float) -> str:
    glyph_set = font.getGlyphSet()
    cmap = font.getBestCmap()
    scale = size / font['head'].unitsPerEm
    pen = SVGPathPen(glyph_set, ntos=lambda number: f'{number:.1f}'.rstrip('0').rstrip('.'))
    advance = 0.0

    for character in text:
        name = cmap[ord(character)]
        glyph_set[name].draw(TransformPen(pen, (scale, 0, 0, -scale, x + advance * scale, baseline)))
        advance += glyph_set[name].width

    return pen.getCommands()


def icon_shapes(icon_svg: str, scale: float, dx: float, dy: float, surface: str) -> str:
    body = icon_svg[icon_svg.index('<g'):icon_svg.rindex('</svg>')]
    body = re.sub(r'\s+id="[^"]*"', '', body).replace('fill="#14151A"', f'fill="{surface}"')

    return f'<g transform="translate({dx:.2f} {dy:.2f}) scale({scale:.4f})">{body}</g>'


def banner(theme: dict[str, str], fonts: dict[int, TTFont], icon_svg: str) -> str:
    top, bottom, left = 71.5, 440.5, 113.97
    scale = ICON_HEIGHT / (bottom - top)
    dy = (HEIGHT - ICON_HEIGHT) / 2 - top * scale
    dx = 96 - left * scale
    text, weight, size, baseline = TITLE
    title = text_path(fonts[weight], text, size, TEXT_X, baseline)
    tagline = ''.join(
        f'<path fill="{theme["tagline"]}" d="{text_path(fonts[weight], text, size, TEXT_X + 2, baseline)}"/>'
        for text, weight, size, baseline in TAGLINE
    )

    return (
        f'<svg xmlns="http://www.w3.org/2000/svg" width="{WIDTH}" height="{HEIGHT}" viewBox="0 0 {WIDTH} {HEIGHT}" role="img">'
        '<title>Laravel Model Cache</title>'
        f'<rect width="{WIDTH}" height="{HEIGHT}" rx="24" fill="{theme["surface"]}"/>'
        f'{icon_shapes(icon_svg, scale, dx, dy, theme["surface"])}'
        f'<path fill="{theme["title"]}" d="{title}"/>{tagline}</svg>\n'
    )


def main(font_path: str) -> None:
    art = Path(__file__).parent
    icon_svg = (art / 'icon.svg').read_text()
    fonts = {weight: instance(Path(font_path), weight) for weight in {TITLE[1], *(line[1] for line in TAGLINE)}}

    for name, theme in THEMES.items():
        target = art / f'banner-{name}.svg'
        temporary = target.with_suffix('.svg.tmp')
        temporary.write_text(banner(theme, fonts, icon_svg))
        temporary.replace(target)


if __name__ == '__main__':
    main(sys.argv[1])
