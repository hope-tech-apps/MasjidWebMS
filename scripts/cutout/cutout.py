#!/usr/bin/env python3
"""Isolate the subject of a photo and write a transparent PNG.

U^2-Net (u2netp, the 4.4MB variant) predicts a saliency mask; we feather and
slightly erode it so the cut-out doesn't carry a halo of the old background --
that fringe is the single most obvious tell of a bad cut-out.

u2netp rather than the full u2net because this runs on a 2GB/1vCPU droplet
shared with MySQL and PHP-FPM. Measured on that box: ~354MB peak RSS, ~2-4s
wall per image. The full model needs roughly 4x the memory for no gain at
flyer sizes.

  cutout.py <in> <out.png> [--max-edge 1400] [--max-pixels 50000000]

Exit 0 on success. Prints one JSON line to stdout: {ok, ms, w, h, coverage}.
Exit 3 (EXIT_TOO_LARGE) when the source has more pixels than --max-pixels,
printing {ok: false, error: "too_large", w, h, max_pixels}: decided from the
header, before any pixel is decoded, and never worth retrying.
`coverage` is the opaque fraction AFTER cropping to the alpha bbox, so a
frame-filling subject legitimately approaches 1.0 -- callers must not treat a
high value as failure on its own.
"""
import json
import sys
import time

import numpy as np
import onnxruntime as ort
from PIL import Image, ImageFilter, JpegImagePlugin

MODEL = "/opt/cutout/models/u2netp.onnx"
EXIT_TOO_LARGE = 3
SIZE = 320
MEAN = np.array([0.485, 0.456, 0.406], dtype=np.float32)
STD = np.array([0.229, 0.224, 0.225], dtype=np.float32)


def predict_mask(img: Image.Image, sess) -> Image.Image:
    x = np.asarray(img.convert("RGB").resize((SIZE, SIZE), Image.BILINEAR), dtype=np.float32) / 255.0
    x = (x - MEAN) / STD
    x = np.transpose(x, (2, 0, 1))[None].astype(np.float32)
    d0 = sess.run(None, {sess.get_inputs()[0].name: x})[0][0, 0]
    lo, hi = float(d0.min()), float(d0.max())
    d0 = (d0 - lo) / (hi - lo + 1e-8)
    return Image.fromarray((d0 * 255).astype(np.uint8), mode="L")


def main() -> int:
    src, dst = sys.argv[1], sys.argv[2]
    max_edge = 1400
    if "--max-edge" in sys.argv:
        max_edge = int(sys.argv[sys.argv.index("--max-edge") + 1])
    max_pixels = 50_000_000
    if "--max-pixels" in sys.argv:
        max_pixels = int(sys.argv[sys.argv.index("--max-pixels") + 1])

    t0 = time.time()
    # Image.open reads the header only. Judge the size there, before a single
    # pixel is decoded: this runs in the queue worker, which has no memory cap.
    # Pillow's own DecompressionBombError fires first for anything past twice
    # its built-in limit, and means the same thing.
    try:
        img = Image.open(src)
    except Image.DecompressionBombError:
        print(json.dumps({"ok": False, "error": "too_large", "w": None, "h": None, "max_pixels": max_pixels}))
        return EXIT_TOO_LARGE
    w, h = img.size
    if w * h > max_pixels:
        print(json.dumps({"ok": False, "error": "too_large", "w": w, "h": h, "max_pixels": max_pixels}))
        return EXIT_TOO_LARGE
    # A JPEG can be decoded straight at a reduced scale (1/2, 1/4, 1/8): draft()
    # picks the largest reduction that still leaves both sides at least
    # max_edge, so a 4032x3024 phone photo is decoded at 2016x1512. A class
    # check rather than a format check: many phone JPEGs open as MPO, a
    # JpegImageFile subclass. PNG and WebP cannot be reduced, which is what the
    # pixel ceiling above is for.
    if isinstance(img, JpegImagePlugin.JpegImageFile):
        img.draft("RGB", (max_edge, max_edge))
    img = img.convert("RGB")
    # Cap the working size: inference is 320x320 regardless, so a 12MP source
    # only costs memory in the resize back.
    if max(img.size) > max_edge:
        img.thumbnail((max_edge, max_edge), Image.LANCZOS)

    so = ort.SessionOptions()
    so.intra_op_num_threads = 1          # 1 vCPU box: more threads only thrash
    so.inter_op_num_threads = 1
    so.graph_optimization_level = ort.GraphOptimizationLevel.ORT_ENABLE_ALL
    sess = ort.InferenceSession(MODEL, so, providers=["CPUExecutionProvider"])

    mask = predict_mask(img, sess).resize(img.size, Image.LANCZOS)
    # Harden, then pull the edge in ~1px and feather: kills the background fringe.
    m = np.asarray(mask, dtype=np.float32) / 255.0
    m = np.clip((m - 0.30) / 0.40, 0, 1)
    mask = Image.fromarray((m * 255).astype(np.uint8), mode="L")
    mask = mask.filter(ImageFilter.MinFilter(3)).filter(ImageFilter.GaussianBlur(0.8))

    out = img.convert("RGBA")
    out.putalpha(mask)
    bbox = out.getbbox()
    if bbox:
        out = out.crop(bbox)
    out.save(dst, "PNG", optimize=True)

    a = np.asarray(out.getchannel("A"))
    print(json.dumps({
        "ok": True,
        "ms": int((time.time() - t0) * 1000),
        "w": out.width,
        "h": out.height,
        "coverage": round(float((a > 8).mean()), 3),
    }))
    return 0


if __name__ == "__main__":
    sys.exit(main())
