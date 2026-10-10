"""
Generator aset brand gs.POS (logo, ikon Android/iOS/Flutter, favicon web).

Satu sumber geometri -> semua SVG (teks sudah di-outline, tanpa dependensi font)
-> PNG/WebP teroptimasi. Jalankan ulang setiap kali desain berubah.

Kebutuhan:
  - Python venv dengan `fonttools` dan `uharfbuzz`
  - Font Nunito variable (Google Fonts, OFL) -> argumen --font
  - CLI: rsvg-convert (librsvg), pngquant, oxipng, cwebp

Contoh:
  python build_brand.py --font /tmp/gspos-font/Nunito.ttf --out docs/brand/kit
"""

from __future__ import annotations

import argparse
import shutil
import struct
import subprocess
from concurrent.futures import ThreadPoolExecutor
from io import BytesIO
from pathlib import Path

import uharfbuzz as hb
from fontTools.pens.boundsPen import BoundsPen
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.ttLib import TTFont
from fontTools.varLib.instancer import instantiateVariableFont

# --- Palet (resources/css/app.css, app/Filament/Shared/Theme.php) --------------------
NAVY, BLUE, MIST, SLATE, WHITE, BLACK = "#1B2A55", "#2D4282", "#B8C0D4", "#8A919E", "#FFFFFF", "#000000"

# Skema warna: frame layar, garis struk + stand, titik, "gs", ".", "POS"
SCHEMES: dict[str, dict[str, str]] = {
    "color": dict(frame=NAVY, accent=SLATE, dot=BLUE, gs=BLUE, period=BLUE, pos=NAVY),
    "reversed": dict(frame=WHITE, accent=MIST, dot=WHITE, gs=MIST, period=WHITE, pos=WHITE),
    "navy": dict(frame=NAVY, accent=NAVY, dot=NAVY, gs=NAVY, period=NAVY, pos=NAVY),
    "black": dict(frame=BLACK, accent=BLACK, dot=BLACK, gs=BLACK, period=BLACK, pos=BLACK),
    "white": dict(frame=WHITE, accent=WHITE, dot=WHITE, gs=WHITE, period=WHITE, pos=WHITE),
}

# --- Geometri logomark (grid 64x64) ---------------------------------------------------
# Layar kasir (frame) + baris struk dengan titik "." (item & harga) + stand.
# Batas tinta dihitung manual dari stroke agar komposisi presisi.
FRAME = dict(x=14, y=14, w=36, h=27, rx=5, sw=4.5)
LINE = dict(x1=22.5, x2=31.5, y=27.5, sw=4.5)  # ujung bulat: tinta 20.25..33.75
DOT = dict(cx=40.5, cy=27.5, r=3)  # tinta 37.5..43.5 -> jarak ke dinding dalam simetris ±4
STAND = dict(x1=22, x2=42, y=50, sw=5)
MARK_BOX = (14 - 2.25, 14 - 2.25, 50 + 2.25, 52.5)  # x0, y0, x1, y1 (tinta)
MARK_W = MARK_BOX[2] - MARK_BOX[0]
MARK_H = MARK_BOX[3] - MARK_BOX[1]
MARK_CX = (MARK_BOX[0] + MARK_BOX[2]) / 2
MARK_CY = (MARK_BOX[1] + MARK_BOX[3]) / 2

# --- Tipografi wordmark ---------------------------------------------------------------
WORD = "gs.POS"
WEIGHT = 800  # Nunito ExtraBold
CAP_H = 24.0  # tinggi huruf kapital relatif mark (±0.59 tinggi mark)
TRACKING = -0.012  # em, sedikit dirapatkan untuk ukuran display
GAP_H = 13.0  # jarak mark -> wordmark (horizontal)
GAP_V = 10.0  # jarak mark -> wordmark (stacked)
MARGIN = 2.0  # ruang anti-aliasing di sekitar tinta


def fmt(v: float) -> str:
    return f"{v:.2f}".rstrip("0").rstrip(".")


class Wordmark:
    """Shape 'gs.POS' dengan HarfBuzz (kerning asli font) lalu ubah ke path SVG."""

    def __init__(self, font_path: Path):
        var = TTFont(font_path)
        self.font = instantiateVariableFont(var, {"wght": WEIGHT})
        buf = BytesIO()
        self.font.save(buf)
        blob = hb.Blob(buf.getvalue())
        self.hbfont = hb.Font(hb.Face(blob))
        self.upm = self.font["head"].unitsPerEm
        self.cap = self.font["OS/2"].sCapHeight
        self.glyphset = self.font.getGlyphSet()
        self.order = self.font.getGlyphOrder()

    def layout(self) -> tuple[list[tuple[str, str]], tuple[float, float, float, float]]:
        """Return [(role, d)], bbox tinta; baseline di y=0, x tinta kiri = 0."""
        b = hb.Buffer()
        b.add_str(WORD)
        b.guess_segment_properties()
        hb.shape(self.hbfont, b, {"kern": True, "liga": False})
        s = CAP_H / self.cap
        track = TRACKING * self.upm
        roles = {0: "gs", 1: "gs", 2: "period", 3: "pos", 4: "pos", 5: "pos"}

        glyphs, pen_x = [], 0.0
        for info, pos in zip(b.glyph_infos, b.glyph_positions):
            glyphs.append((roles[info.cluster], self.order[info.codepoint], pen_x + pos.x_offset))
            pen_x += pos.x_advance + track

        # Batas tinta total untuk menormalkan posisi
        x0 = y0 = float("inf")
        x1 = y1 = float("-inf")
        for _, name, gx in glyphs:
            bp = BoundsPen(self.glyphset)
            self.glyphset[name].draw(TransformPen(bp, (s, 0, 0, -s, gx * s, 0)))
            if bp.bounds:
                x0, y0 = min(x0, bp.bounds[0]), min(y0, bp.bounds[1])
                x1, y1 = max(x1, bp.bounds[2]), max(y1, bp.bounds[3])

        parts: dict[str, list[str]] = {"gs": [], "period": [], "pos": []}
        for role, name, gx in glyphs:
            sp = SVGPathPen(self.glyphset, ntos=fmt)
            self.glyphset[name].draw(TransformPen(sp, (s, 0, 0, -s, gx * s - x0, 0)))
            parts[role].append(sp.getCommands())
        paths = [(r, "".join(d)) for r, d in parts.items()]
        return paths, (0.0, y0, x1 - x0, y1)


def mark_svg(c: dict[str, str]) -> str:
    f, ln, d, st = FRAME, LINE, DOT, STAND
    return (
        f'<rect x="{f["x"]}" y="{f["y"]}" width="{f["w"]}" height="{f["h"]}" rx="{f["rx"]}" '
        f'fill="none" stroke="{c["frame"]}" stroke-width="{f["sw"]}"/>'
        f'<path d="M{ln["x1"]} {ln["y"]}H{ln["x2"]}" stroke="{c["accent"]}" stroke-width="{ln["sw"]}" stroke-linecap="round"/>'
        f'<circle cx="{d["cx"]}" cy="{d["cy"]}" r="{d["r"]}" fill="{c["dot"]}"/>'
        f'<path d="M{st["x1"]} {st["y"]}H{st["x2"]}" stroke="{c["accent"]}" stroke-width="{st["sw"]}" stroke-linecap="round"/>'
    )


def mark_simple_svg(c: dict[str, str]) -> str:
    """Varian 16px: stroke lebih tebal, baris struk dihapus, titik diperbesar & di-center."""
    return (
        f'<rect x="13" y="13" width="38" height="28" rx="6" fill="none" stroke="{c["frame"]}" stroke-width="7"/>'
        f'<circle cx="32" cy="27" r="4.5" fill="{c["dot"]}"/>'
        f'<path d="M21 51H43" stroke="{c["accent"]}" stroke-width="7" stroke-linecap="round"/>'
    )


def word_svg(paths: list[tuple[str, str]], c: dict[str, str], dx: float, dy: float) -> str:
    inner = "".join(f'<path fill="{c[r]}" d="{d}"/>' for r, d in paths)
    return f'<g transform="translate({fmt(dx)} {fmt(dy)})">{inner}</g>'


def svg_doc(box: tuple[float, float, float, float], body: str) -> str:
    x0, y0, x1, y1 = box
    return (
        f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="{fmt(x0)} {fmt(y0)} {fmt(x1 - x0)} {fmt(y1 - y0)}">'
        f"{body}</svg>\n"
    )


def pad(box, m=MARGIN):
    return (box[0] - m, box[1] - m, box[2] + m, box[3] + m)


def build_logos(wm: Wordmark) -> dict[str, str]:
    paths, (_, wy0, ww, wy1) = wm.layout()
    out: dict[str, str] = {}
    for name, c in SCHEMES.items():
        # Mark saja
        out[f"logomark-{name}"] = svg_doc(pad(MARK_BOX), mark_svg(c))

        # Horizontal: pusat optik kapital sejajar pusat mark
        baseline = MARK_CY + CAP_H / 2
        tx = MARK_BOX[2] + GAP_H
        box = (MARK_BOX[0], min(MARK_BOX[1], baseline + wy0), tx + ww, max(MARK_BOX[3], baseline + wy1))
        out[f"logo-horizontal-{name}"] = svg_doc(pad(box), mark_svg(c) + word_svg(paths, c, tx, baseline))

        # Stacked: mark di atas, wordmark di bawah, keduanya center horizontal
        baseline = MARK_BOX[3] + GAP_V + CAP_H
        tx = MARK_CX - ww / 2
        x0, x1 = min(MARK_BOX[0], tx), max(MARK_BOX[2], tx + ww)
        box = (x0, MARK_BOX[1], x1, baseline + wy1)
        out[f"logo-stacked-{name}"] = svg_doc(pad(box), mark_svg(c) + word_svg(paths, c, tx, baseline))

        # Wordmark saja
        out[f"wordmark-{name}"] = svg_doc(pad((0, wy0, ww, wy1)), word_svg(paths, c, 0, 0))
    return out


def icon_svg(size: float, frac: float, colors: dict[str, str], bg: str | None, shape: str = "square",
             simple: bool = False) -> str:
    """Ikon persegi `size` unit; mark menempati `frac` dari sisi kanvas (dimensi terbesar)."""
    k = frac * size / max(MARK_W, MARK_H)
    tx, ty = size / 2 - MARK_CX * k, size / 2 - MARK_CY * k
    if simple:  # varian 16px punya batas tinta sendiri (9.5..54.5)
        k = frac * size / 45
        tx, ty = size / 2 - 32 * k, size / 2 - 32 * k
    back = ""
    if bg:
        if shape == "square":
            back = f'<rect width="{fmt(size)}" height="{fmt(size)}" fill="{bg}"/>'
        elif shape == "rounded":
            back = f'<rect width="{fmt(size)}" height="{fmt(size)}" rx="{fmt(size * 0.225)}" fill="{bg}"/>'
        elif shape == "circle":
            back = f'<circle cx="{fmt(size / 2)}" cy="{fmt(size / 2)}" r="{fmt(size / 2)}" fill="{bg}"/>'
    body = mark_simple_svg(colors) if simple else mark_svg(colors)
    return (
        f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {fmt(size)} {fmt(size)}">{back}'
        f'<g transform="translate({fmt(tx)} {fmt(ty)}) scale({fmt(k)})">{body}</g></svg>\n'
    )


# --- Spesifikasi ikon -----------------------------------------------------------------
# frac: porsi mark terhadap kanvas. Adaptive icon 108dp: safe zone lingkaran 66dp ->
# mark 0.41 (setengah diagonal 31dp). Splash Android 12: kanvas 288dp, lingkaran 192dp.
REV, CLR, WHT = SCHEMES["reversed"], SCHEMES["color"], SCHEMES["white"]
ICONS = {
    "app-icon-square": (64, 0.56, REV, NAVY, "square"),  # iOS & Play Store (OS yang membulatkan)
    "app-icon-rounded": (64, 0.56, REV, NAVY, "rounded"),  # legacy launcher, web, presentasi
    "app-icon-circle": (64, 0.52, REV, NAVY, "circle"),  # ic_launcher_round
    "adaptive-foreground": (108, 0.41, REV, None, "square"),  # transparan
    "adaptive-background": (108, 0.0, REV, NAVY, "square"),
    "adaptive-monochrome": (108, 0.41, WHT, None, "square"),  # Android 13 themed icon
    "maskable": (100, 0.44, REV, NAVY, "square"),  # PWA, safe zone 80%
    "splash-icon-light": (288, 0.45, CLR, None, "square"),  # transparan, untuk latar terang
    "splash-icon-dark": (288, 0.45, REV, None, "square"),  # transparan, untuk latar gelap
    "favicon": (64, 0.70, REV, NAVY, "rounded"),
}


def run(*cmd: str) -> None:
    subprocess.run(cmd, check=True, capture_output=True)


def png(svg: Path, dest: Path, w: int, h: int | None = None) -> None:
    dest.parent.mkdir(parents=True, exist_ok=True)
    args = ["rsvg-convert", "-w", str(w)] + (["-h", str(h)] if h else []) + ["-o", str(dest), str(svg)]
    run(*args)
    # Kuantisasi palet (logo hanya beberapa warna -> praktis tanpa beda visual), lalu lossless
    subprocess.run(["pngquant", "--quality=90-100", "--speed=1", "--strip", "--skip-if-larger",
                    "--force", "--ext", ".png", str(dest)], capture_output=True)
    run("oxipng", "-o", "4", "--strip", "safe", "-q", str(dest))


def webp(src_png: Path, dest: Path) -> None:
    run("cwebp", "-lossless", "-z", "9", "-alpha_filter", "best", "-quiet", str(src_png), "-o", str(dest))


def ico(pngs: list[Path], dest: Path) -> None:
    """favicon.ico berisi PNG (didukung semua browser modern)."""
    datas = [p.read_bytes() for p in pngs]
    header = struct.pack("<HHH", 0, 1, len(datas))
    offset, entries = 6 + 16 * len(datas), b""
    for p, data in zip(pngs, datas):
        w, h = struct.unpack(">II", data[16:24])
        entries += struct.pack("<BBBBHHII", w % 256, h % 256, 0, 0, 1, 32, len(data), offset)
        offset += len(data)
    dest.write_bytes(header + entries + b"".join(datas))


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--font", required=True, type=Path)
    ap.add_argument("--out", required=True, type=Path)
    a = ap.parse_args()

    out: Path = a.out
    if out.exists():
        shutil.rmtree(out)
    svgdir = out / "svg"
    svgdir.mkdir(parents=True)

    # 1) SVG master
    for name, doc in build_logos(Wordmark(a.font)).items():
        (svgdir / f"{name}.svg").write_text(doc)
    for name, (size, frac, c, bg, shape) in ICONS.items():
        (svgdir / f"{name}.svg").write_text(icon_svg(size, frac, c, bg, shape))
    (svgdir / "favicon-16.svg").write_text(icon_svg(64, 0.78, REV, NAVY, "rounded", simple=True))

    jobs: list[tuple[Path, Path, int, int | None]] = []

    # 2) Logo PNG @1x..@4x (tinggi dasar per layout), latar transparan
    base_h = {"logomark": 128, "logo-horizontal": 64, "logo-stacked": 160, "wordmark": 48}
    for svg in sorted(svgdir.glob("*.svg")):
        layout = next((k for k in base_h if svg.stem.startswith(k + "-")), None)
        if not layout:
            continue
        vb = [float(v) for v in svg.read_text().split('viewBox="')[1].split('"')[0].split()]
        for scale in (1, 2, 3, 4):
            h = base_h[layout] * scale
            w = round(h * vb[2] / vb[3])
            jobs.append((svg, out / "png" / layout / f"{svg.stem}@{scale}x.png", w, h))
            # Flutter resolution-aware assets: logo.png, 2.0x/logo.png, 3.0x/logo.png, 4.0x/logo.png
            sub = "" if scale == 1 else f"{scale}.0x/"
            jobs.append((svg, out / "flutter" / "assets" / "brand" / f"{sub}{svg.stem}.png", w, h))

    # 3) Android (mipmap per density)
    dens = {"mdpi": 1, "hdpi": 1.5, "xhdpi": 2, "xxhdpi": 3, "xxxhdpi": 4}
    for d, m in dens.items():
        base = out / "android" / "res" / f"mipmap-{d}"
        jobs += [
            (svgdir / "app-icon-rounded.svg", base / "ic_launcher.png", int(48 * m), None),
            (svgdir / "app-icon-circle.svg", base / "ic_launcher_round.png", int(48 * m), None),
            (svgdir / "adaptive-foreground.svg", base / "ic_launcher_foreground.png", int(108 * m), None),
            (svgdir / "adaptive-background.svg", base / "ic_launcher_background.png", int(108 * m), None),
            (svgdir / "adaptive-monochrome.svg", base / "ic_launcher_monochrome.png", int(108 * m), None),
        ]
        dbase = out / "android" / "res" / f"drawable-{d}"
        jobs += [
            (svgdir / "splash-icon-light.svg", dbase / "splash_icon.png", int(288 * m), None),
            (svgdir / "splash-icon-dark.svg", dbase / "splash_icon_dark.png", int(288 * m), None),
        ]
    jobs.append((svgdir / "app-icon-square.svg", out / "android" / "playstore-icon-512.png", 512, None))

    # 4) iOS (Xcode 14+ cukup 1024; ukuran lain untuk kebutuhan lama/marketing)
    for s in (20, 29, 40, 58, 60, 76, 80, 87, 120, 152, 167, 180, 1024):
        jobs.append((svgdir / "app-icon-square.svg", out / "ios" / f"AppIcon-{s}.png", s, None))

    # 5) Web
    w = out / "web"
    jobs += [
        (svgdir / "favicon-16.svg", w / "favicon-16x16.png", 16, None),
        (svgdir / "favicon.svg", w / "favicon-32x32.png", 32, None),
        (svgdir / "favicon.svg", w / "favicon-48x48.png", 48, None),
        (svgdir / "app-icon-square.svg", w / "apple-touch-icon.png", 180, None),
        (svgdir / "app-icon-rounded.svg", w / "icon-192.png", 192, None),
        (svgdir / "app-icon-rounded.svg", w / "icon-512.png", 512, None),
        (svgdir / "maskable.svg", w / "icon-maskable-192.png", 192, None),
        (svgdir / "maskable.svg", w / "icon-maskable-512.png", 512, None),
        (svgdir / "app-icon-rounded.svg", out / "app-icon-1024.png", 1024, None),
    ]

    with ThreadPoolExecutor() as pool:
        list(pool.map(lambda j: png(*j), jobs))

    # WebP lossless untuk logo (lebih kecil dari PNG, didukung Flutter & Android)
    logo_pngs = sorted((out / "png").rglob("*.png"))
    with ThreadPoolExecutor() as pool:
        list(pool.map(lambda p: webp(p, out / "webp" / p.parent.name / (p.stem + ".webp")) if
                      (out / "webp" / p.parent.name).mkdir(parents=True, exist_ok=True) is None else None,
                      logo_pngs))

    ico([w / "favicon-16x16.png", w / "favicon-32x32.png", w / "favicon-48x48.png"], w / "favicon.ico")
    shutil.copy(svgdir / "favicon.svg", w / "favicon.svg")


if __name__ == "__main__":
    main()
