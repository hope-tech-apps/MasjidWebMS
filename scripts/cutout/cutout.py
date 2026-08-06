#!/usr/bin/env python3
"""Isolate the subject of a photo and write a transparent PNG.

U^2-Net (u2netp, the 4.4MB variant) predicts a saliency mask; we feather and
slightly erode it so the cut-out doesn't carry a halo of the old background --
that fringe is the single most obvious tell of a bad cut-out.

u2netp rather than the full u2net because this runs on a 2GB/1vCPU droplet
shared with MySQL and PHP-FPM. Measured on that box: ~354MB peak RSS, ~2-4s
wall per image. The full model needs roughly 4x the memory for no gain at
flyer sizes.

  cutout.py <in> <out.png> [--max-edge 1400]

Exit 0 on success. Prints one JSON line to stdout: {ok, ms, w, h, coverage}.
`coverage` is the opaque fraction AFTER cropping to the alpha bbox, so a
frame-filling subject legitimately approaches 1.0 -- callers must not treat a
high value as failure on its own.
"""
import json
import sys
import time

import numpy as np
import onnxruntime as ort
from PIL import Image, ImageFilter

MODEL = "/opt/cutout/models/u2netp.onnx"
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

    t0 = time.time()
    img = Image.open(src).convert("RGB")
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
