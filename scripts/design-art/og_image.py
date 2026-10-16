"""Builds the Open Graph share images (Paper board "R3 · OG images").

Usage: og_image.py <tag png> <input webp> <output jpg> [<input webp> <output jpg> ...]

Each image is the page's hero art scaled to 1200 wide and centre-cropped to 1200 x 630, with
the Voltikka wordmark tag (design/logo/og-wordmark-tag.png, rendered at 2x) in the upper-left
sky and a torn paper bottom edge under the tag. Called by scripts/design-art/og.sh.
"""

import sys

from PIL import Image, ImageDraw

WIDTH, HEIGHT = 1200, 630
TAG_LEFT, TAG_TOP = 56, 48
EDGE_HEIGHT = 10
INK = (22, 32, 46, 255)
PAPER = (255, 255, 255, 255)


def torn_edge(width: int) -> Image.Image:
    """The bill-form zigzag under the tag, drawn at 4x and scaled down for smooth lines."""
    scale = 4
    points = [(1, 0), (1, 5)]
    x, low = 7, True
    while x < width - 1:
        points.append((x, 9 if low else 4))
        x += 7
        low = not low
    points += [(width - 1, 5), (width - 1, 0)]
    big = [(px * scale, py * scale) for px, py in points]

    layer = Image.new("RGBA", (width * scale, EDGE_HEIGHT * scale), (0, 0, 0, 0))
    draw = ImageDraw.Draw(layer)
    draw.polygon(big, fill=PAPER)
    draw.line(big[1:-1], fill=INK, width=2 * scale, joint="curve")

    return layer.resize((width, EDGE_HEIGHT), Image.LANCZOS)


def build(tag: Image.Image, edge: Image.Image, source: str, target: str) -> None:
    art = Image.open(source).convert("RGB")
    art = art.resize((WIDTH, round(art.height * WIDTH / art.width)), Image.LANCZOS)
    if art.height < HEIGHT:
        raise SystemExit(f"{source}: too short for a 1200 x 630 crop")
    top = (art.height - HEIGHT) // 2
    canvas = art.crop((0, top, WIDTH, top + HEIGHT)).convert("RGBA")

    canvas.alpha_composite(tag, (TAG_LEFT, TAG_TOP))
    canvas.alpha_composite(edge, (TAG_LEFT, TAG_TOP + tag.height))
    canvas.convert("RGB").save(target, "JPEG", quality=72, progressive=True, optimize=True)


def main() -> None:
    tag_2x = Image.open(sys.argv[1]).convert("RGBA")
    tag = tag_2x.resize((tag_2x.width // 2, tag_2x.height // 2), Image.LANCZOS)
    edge = torn_edge(tag.width)

    pairs = sys.argv[2:]
    for source, target in zip(pairs[::2], pairs[1::2]):
        build(tag, edge, source, target)


if __name__ == "__main__":
    main()
