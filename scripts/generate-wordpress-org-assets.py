#!/usr/bin/env python3
"""
Generate WordPress.org plugin directory assets (transparent PNGs).

Output: assets/wordpress-org/
Upload these to plugins.svn.wordpress.org/webba-deploy/assets/ (not the plugin zip).
"""

from __future__ import annotations

import argparse
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "assets" / "wordpress-org"

GITHUB_DARK = (36, 41, 47, 255)
ACCENT = (46, 164, 79, 255)
ACCENT_LIGHT = (46, 164, 79, 180)
WP_BLUE = (34, 113, 177, 255)


def draw_download_icon(size: int) -> Image.Image:
    img = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    draw = ImageDraw.Draw(img)

    pad = size * 0.12
    box = (pad, pad, size - pad, size - pad)
    radius = size * 0.18

    draw.rounded_rectangle(box, radius=radius, fill=GITHUB_DARK)

    cx = size / 2
    cy = size / 2
    shaft_w = size * 0.08
    head_h = size * 0.14
    shaft_top = cy - size * 0.12
    shaft_bottom = cy + size * 0.16

    draw.rectangle(
        (cx - shaft_w / 2, shaft_top, cx + shaft_w / 2, shaft_bottom),
        fill=ACCENT,
    )

    tip_y = shaft_bottom + head_h * 0.55
    draw.polygon(
        [
            (cx, tip_y),
            (cx - size * 0.14, shaft_bottom - head_h * 0.15),
            (cx + size * 0.14, shaft_bottom - head_h * 0.15),
        ],
        fill=ACCENT,
    )

    draw.rounded_rectangle(
        (size * 0.22, size * 0.62, size * 0.78, size * 0.72),
        radius=size * 0.04,
        fill=ACCENT_LIGHT,
    )

    return img


def load_font(size: int, bold: bool = False) -> ImageFont.FreeTypeFont | ImageFont.ImageFont:
    candidates = [
        "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf" if bold else "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf",
        "/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf" if bold else "/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf",
    ]
    for path in candidates:
        p = Path(path)
        if p.exists():
            return ImageFont.truetype(str(p), size)
    return ImageFont.load_default()


def draw_banner(width: int, height: int) -> Image.Image:
    img = Image.new("RGBA", (width, height), (0, 0, 0, 0))
    draw = ImageDraw.Draw(img)

    icon_size = int(height * 0.72)
    icon = draw_download_icon(icon_size)
    icon_y = (height - icon_size) // 2
    img.paste(icon, (int(width * 0.06), icon_y), icon)

    title_size = max(22, int(height * 0.19))
    subtitle_size = max(12, int(height * 0.095))
    title_font = load_font(title_size, bold=True)
    subtitle_font = load_font(subtitle_size, bold=False)

    text_x = int(width * 0.06) + icon_size + int(width * 0.05)
    title_y = int(height * 0.28)
    draw.text((text_x, title_y), "Webba Deploy", fill=GITHUB_DARK, font=title_font)
    draw.text(
        (text_x, title_y + title_size + int(height * 0.06)),
        "Deploy plugins & themes from GitHub",
        fill=(*GITHUB_DARK[:3], 200),
        font=subtitle_font,
    )

    badge_y = int(height * 0.68)
    badges = ["Public & private", "One-click GitHub App", "Push-to-deploy"]
    bx = text_x
    for label in badges:
        tw = draw.textlength(label, font=subtitle_font)
        pad_x = int(height * 0.04)
        pad_y = int(height * 0.025)
        draw.rounded_rectangle(
            (bx, badge_y, bx + tw + pad_x * 2, badge_y + subtitle_size + pad_y * 2),
            radius=6,
            outline=ACCENT,
            width=2,
        )
        draw.text((bx + pad_x, badge_y + pad_y), label, fill=ACCENT, font=subtitle_font)
        bx += tw + pad_x * 2 + int(width * 0.02)

    return img


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--force",
        action="store_true",
        help="Overwrite existing PNGs (default: skip files that already exist).",
    )
    args = parser.parse_args()

    OUT.mkdir(parents=True, exist_ok=True)

    for size in (256, 128):
        path = OUT / f"icon-{size}x{size}.png"
        if path.exists() and not args.force:
            print(f"Skipped existing {path.relative_to(ROOT)} (use --force to overwrite)")
            continue
        draw_download_icon(size).save(path, "PNG")
        print(f"Wrote {path.relative_to(ROOT)}")

    for width, height in ((772, 250), (1544, 500)):
        path = OUT / f"banner-{width}x{height}.png"
        if path.exists() and not args.force:
            print(f"Skipped existing {path.relative_to(ROOT)} (use --force to overwrite)")
            continue
        draw_banner(width, height).save(path, "PNG")
        print(f"Wrote {path.relative_to(ROOT)}")


if __name__ == "__main__":
    main()
