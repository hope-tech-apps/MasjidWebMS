#!/usr/bin/env bash
#
# Provision the server-side flyer background-removal ("cutout") stack.
#
# This installs the Python side of App\Services\Flyer\ImageCutout: an isolated
# venv holding onnxruntime + pillow + numpy, the u2netp ONNX model (U^2-Net
# small, 4.4MB), and cutout.py itself from scripts/cutout/cutout.py in this repo
# — the repo copy is the reference, so provisioning a box means putting THAT
# there rather than hoping someone remembered to scp it.
#
# A droplet copy that differs from the repo copy is reported and the run stops.
# It is never overwritten silently: divergence means either someone hot-patched
# production or the repo moved on, and both need a human to look before the
# proven script is replaced.
#
# Everything here is idempotent: re-running it on a provisioned box installs
# nothing and simply re-verifies. Run it after any Python or model change, and
# whenever you want proof the box can still do a cutout.
#
#     sudo scripts/provision-cutout.sh
#
# Why a venv and not system pip: this is Debian/Ubuntu, where pip into the system
# interpreter is refused (PEP 668) — and even where it is not, onnxruntime pulling
# its own numpy underneath the system packages is a bad trade on a box that also
# has to keep MySQL and PHP-FPM alive.
#
set -euo pipefail

CUTOUT_DIR="${CUTOUT_DIR:-/opt/cutout}"
VENV_DIR="${VENV_DIR:-$CUTOUT_DIR/venv}"
PYTHON="$VENV_DIR/bin/python"
SCRIPT_PATH="${SCRIPT_PATH:-$CUTOUT_DIR/cutout.py}"
# The reference copy, resolved relative to this script so it works from any cwd.
REPO_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_SCRIPT="${REPO_SCRIPT:-$REPO_DIR/cutout/cutout.py}"
# Set CUTOUT_OVERWRITE_SCRIPT=1 to replace a drifted droplet copy with the repo's.
OVERWRITE_SCRIPT="${CUTOUT_OVERWRITE_SCRIPT:-0}"
# Whoever runs the queue worker — masjid-queue.service has User=www-data — is who
# the verification must impersonate. Root can read files www-data cannot.
RUNTIME_USER="${CUTOUT_RUNTIME_USER:-www-data}"
MODEL_DIR="${MODEL_DIR:-$CUTOUT_DIR/models}"
MODEL_PATH="$MODEL_DIR/u2netp.onnx"
MODEL_URL="${CUTOUT_MODEL_URL:-https://github.com/danielgatis/rembg/releases/download/v0.0.0/u2netp.onnx}"
# Optional: export CUTOUT_MODEL_SHA256=<hex> to pin the exact weights.
MODEL_SHA256="${CUTOUT_MODEL_SHA256:-}"

# Pinned exactly, not ranged. This trio is the one measured in production
# (354MB peak RSS, ~3.2s wall on Python 3.12), and the whole point of this file
# is to reproduce that box rather than to resolve a fresh dependency set on it —
# an onnxruntime/numpy pairing that resolves fine but abort-on-imports is a
# failure mode this app would only ever see as a queued job that never finishes.
# Override only after checking the pairing on a box you can afford to break.
ONNXRUNTIME_SPEC="${ONNXRUNTIME_SPEC:-onnxruntime==1.28.0}"
PILLOW_SPEC="${PILLOW_SPEC:-pillow==12.3.0}"
NUMPY_SPEC="${NUMPY_SPEC:-numpy==2.5.1}"

say() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }
note() { printf '    %s\n' "$1"; }
warn() { printf '\033[33m    WARNING: %s\033[0m\n' "$1"; }
die() { printf '\n\033[31m    %s\033[0m\n\n' "$1" >&2; exit 1; }

if [ "$(id -u)" -ne 0 ]; then
    die "Run this as root: sudo scripts/provision-cutout.sh"
fi

# Installing is root's job. PROVING the install works is not: root reads files
# regardless of their mode, so a root-only verification passes just as happily on
# a box where the queue worker cannot open the model at all. Everything below
# that mimics runtime is run through this.
RUN_AS=()
if ! id -u "$RUNTIME_USER" >/dev/null 2>&1; then
    warn "No '$RUNTIME_USER' user here; verifying as root, which does NOT prove the queue worker can run a cutout."
elif command -v runuser >/dev/null 2>&1; then
    RUN_AS=(runuser -u "$RUNTIME_USER" --)
elif command -v sudo >/dev/null 2>&1; then
    RUN_AS=(sudo -n -u "$RUNTIME_USER" --)
else
    warn "Neither runuser nor sudo is here; verifying as root, which does NOT prove the queue worker can run a cutout."
fi

as_runtime_user() {
    if [ ${#RUN_AS[@]} -eq 0 ]; then
        "$@"
    else
        "${RUN_AS[@]}" "$@"
    fi
}

say "System packages"
MISSING=()
for pkg in python3 python3-venv curl; do
    if ! dpkg-query -W -f='${Status}' "$pkg" 2>/dev/null | grep -q 'ok installed'; then
        MISSING+=("$pkg")
    fi
done

if [ ${#MISSING[@]} -eq 0 ]; then
    note "Already present: python3, python3-venv, curl"
else
    note "Installing: ${MISSING[*]}"
    DEBIAN_FRONTEND=noninteractive apt-get update -qq
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "${MISSING[@]}"
fi

say "Virtualenv at $VENV_DIR"
mkdir -p "$CUTOUT_DIR"
FRESH_VENV=0
if [ -x "$PYTHON" ]; then
    note "Reusing existing venv ($("$PYTHON" --version 2>&1))"
else
    python3 -m venv "$VENV_DIR"
    FRESH_VENV=1
    note "Created ($("$PYTHON" --version 2>&1))"
fi

say "Python packages"
# pip itself is only upgraded on a brand-new venv. On a re-run there is nothing
# to gain from moving it, and a working box should not drift because someone ran
# the provisioner again.
if [ "$FRESH_VENV" -eq 1 ]; then
    "$PYTHON" -m pip install --quiet --no-cache-dir --upgrade pip >/dev/null
fi

# --no-cache-dir because a 2GB box should not be storing wheel caches, and pip's
# cache is one more thing that silently fills the disk this app writes uploads to.
# With exact pins, this is a no-op on an already-provisioned host.
"$PYTHON" -m pip install --quiet --no-cache-dir \
    "$ONNXRUNTIME_SPEC" "$PILLOW_SPEC" "$NUMPY_SPEC"
"$PYTHON" - <<'PY'
import numpy, onnxruntime, PIL
print(f"    onnxruntime {onnxruntime.__version__}, pillow {PIL.__version__}, numpy {numpy.__version__}")
PY

say "Model at $MODEL_PATH"
mkdir -p "$MODEL_DIR"
if [ -f "$MODEL_PATH" ] && [ "$(stat -c %s "$MODEL_PATH")" -gt 4000000 ]; then
    note "Already downloaded ($(stat -c %s "$MODEL_PATH") bytes)"
else
    note "Fetching u2netp.onnx"
    TMP_MODEL="$(mktemp "$MODEL_DIR/.u2netp.XXXXXX")"
    # Download to a temp name and move into place only once complete, so an
    # interrupted run cannot leave a truncated model that loads as garbage.
    curl -fsSL --retry 3 --retry-delay 2 -o "$TMP_MODEL" "$MODEL_URL" \
        || { rm -f "$TMP_MODEL"; die "Could not download the model from $MODEL_URL"; }

    SIZE="$(stat -c %s "$TMP_MODEL")"
    if [ "$SIZE" -lt 4000000 ] || [ "$SIZE" -gt 8000000 ]; then
        rm -f "$TMP_MODEL"
        die "Downloaded model is $SIZE bytes; u2netp.onnx should be ~4.4MB. Check $MODEL_URL."
    fi

    mv "$TMP_MODEL" "$MODEL_PATH"
    note "Downloaded ($SIZE bytes)"
fi

if [ -n "$MODEL_SHA256" ]; then
    ACTUAL="$(sha256sum "$MODEL_PATH" | cut -d' ' -f1)"
    [ "$ACTUAL" = "$MODEL_SHA256" ] || die "Model checksum mismatch. Expected $MODEL_SHA256, got $ACTUAL."
    note "Checksum matches"
fi

say "Cutout script at $SCRIPT_PATH"
if [ ! -f "$REPO_SCRIPT" ]; then
    die "The reference script is missing from the repo at $REPO_SCRIPT.
    Run this from a checkout, or point REPO_SCRIPT at the copy to install."
fi

if [ ! -f "$SCRIPT_PATH" ]; then
    # install(1) rather than cp: one call that also fixes mode and ownership, and
    # it writes through a temp file, so an interrupted run cannot leave the worker
    # a half-written script.
    install -m 0644 -o root -g root "$REPO_SCRIPT" "$SCRIPT_PATH"
    note "Installed from $REPO_SCRIPT"
elif cmp -s "$REPO_SCRIPT" "$SCRIPT_PATH"; then
    note "Already installed, byte-for-byte identical to the repo copy"
elif [ "$OVERWRITE_SCRIPT" = "1" ]; then
    install -m 0644 -o root -g root "$REPO_SCRIPT" "$SCRIPT_PATH"
    warn "Overwrote a DIFFERENT $SCRIPT_PATH with the repo copy (CUTOUT_OVERWRITE_SCRIPT=1)."
else
    # Not overwritten. The droplet copy is what has actually been serving cutouts;
    # if it differs, either it was hot-patched or the repo has moved on, and
    # silently replacing it is how a production fix gets lost.
    die "$SCRIPT_PATH differs from the repo copy at $REPO_SCRIPT.

    Nothing was changed. Compare them, decide which is right, then either commit
    the droplet's version to the repo or re-run with:

        sudo CUTOUT_OVERWRITE_SCRIPT=1 scripts/provision-cutout.sh

    To see the difference:

        diff -u $SCRIPT_PATH $REPO_SCRIPT"
fi

say "Permissions"
# The queue worker runs as $RUNTIME_USER and only ever reads these.
chown -R root:root "$CUTOUT_DIR"
chmod -R a+rX "$CUTOUT_DIR"
note "root-owned, world-readable"

say "Verification workspace"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT
# The helpers below are written here by root and RUN by $RUNTIME_USER, who also
# writes the cutout's output into this directory — so the directory has to be
# theirs (mktemp -d hands root a 0700 one). Everything is passed by ARGV rather
# than environment or stdin: sudo resets the environment, and relying on either
# surviving the switch is one more thing that can quietly not work.
if [ ${#RUN_AS[@]} -ne 0 ]; then
    chown "$RUNTIME_USER" "$WORK_DIR"
fi
note "$WORK_DIR"

cat > "$WORK_DIR/check_model.py" <<'PY'
import sys
import onnxruntime

session = onnxruntime.InferenceSession(sys.argv[1], providers=["CPUExecutionProvider"])
shape = session.get_inputs()[0].shape
print(f"    Loaded. input '{session.get_inputs()[0].name}' {shape}, {len(session.get_outputs())} output(s).")
PY

cat > "$WORK_DIR/make_test_image.py" <<'PY'
import sys
from PIL import Image, ImageDraw

# A high-contrast subject on a plain field. Enough to exercise the whole path;
# not a real photograph, so its coverage number is reported below but never
# treated as pass/fail — that would fail working installs on a synthetic input.
img = Image.new("RGB", (900, 1200), (18, 32, 64))
draw = ImageDraw.Draw(img)
draw.ellipse((250, 300, 650, 900), fill=(242, 226, 190))
draw.ellipse((330, 430, 400, 500), fill=(30, 30, 30))
draw.ellipse((500, 430, 570, 500), fill=(30, 30, 30))
img.save(sys.argv[1])
PY

say "Verifying the model loads (as $RUNTIME_USER)"
as_runtime_user "$PYTHON" "$WORK_DIR/check_model.py" "$MODEL_PATH"

say "Verifying an end-to-end cutout (as $RUNTIME_USER)"
as_runtime_user "$PYTHON" "$WORK_DIR/make_test_image.py" "$WORK_DIR/in.png"

# Invoked exactly the way ImageCutout invokes it: argv, no shell — and as the
# user the queue worker runs as, so this proves the thing that has to be true in
# production rather than the thing that is trivially true for root.
OUTPUT="$(as_runtime_user "$PYTHON" "$SCRIPT_PATH" "$WORK_DIR/in.png" "$WORK_DIR/out.png" --max-edge 1400)" \
    || die "The cutout script exited non-zero as $RUNTIME_USER. Output: $OUTPUT"

note "stdout: $OUTPUT"

printf '%s\n' "$OUTPUT" > "$WORK_DIR/stdout.txt"

cat > "$WORK_DIR/check_result.py" <<'PY'
import json, os, sys
from PIL import Image

with open(sys.argv[1]) as handle:
    lines = [l for l in handle.read().splitlines() if l.strip()]

payload = None
for line in reversed(lines):
    try:
        payload = json.loads(line)
        break
    except ValueError:
        continue

if not isinstance(payload, dict):
    sys.exit("    The script printed no JSON line. ImageCutout cannot read this.")
if payload.get("ok") is not True:
    sys.exit(f"    The script reported failure: {payload}")
for key in ("ms", "w", "h", "coverage"):
    if key not in payload:
        sys.exit(f"    The JSON line is missing '{key}'. ImageCutout needs ms, w, h and coverage.")

out = sys.argv[2]
if not os.path.exists(out) or os.path.getsize(out) == 0:
    sys.exit("    The script exited 0 but wrote no output image.")

image = Image.open(out)
if image.mode not in ("RGBA", "LA"):
    sys.exit(f"    Output has mode {image.mode}; a cutout must carry an alpha channel.")

coverage = float(payload["coverage"])
print(f"    OK — {payload['w']}x{payload['h']} in {payload['ms']}ms, coverage {coverage:.3f}, alpha present.")
# Only the LOW end is a rejection in ImageCutout (min_coverage in
# config/flyer.php). Coverage is measured after the crop to the alpha bbox, so a
# high value means "the subject fills its own box" and is not a fault.
if coverage < 0.02:
    print(f"    NOTE: coverage {coverage:.3f} is below the floor this app accepts,")
    print("          which is possible on this synthetic test image. Confirm with a real photo.")
PY

as_runtime_user "$PYTHON" "$WORK_DIR/check_result.py" "$WORK_DIR/stdout.txt" "$WORK_DIR/out.png"

say "Ready"
cat <<EOF
    Add to the application's .env, then reload config and restart the worker:

        FLYER_CUTOUT_ENABLED=true
        FLYER_CUTOUT_PYTHON=$PYTHON
        FLYER_CUTOUT_SCRIPT=$SCRIPT_PATH

        php artisan config:cache && systemctl restart masjid-queue.service
EOF

# Swap is not optional here: a cutout peaks around 354MB RSS on a 2GB box that is
# already carrying MySQL and PHP-FPM, and the OOM killer's usual first choice is
# MySQL.
SWAP_KB="$(awk '/^SwapTotal:/ {print $2}' /proc/meminfo 2>/dev/null || true)"
if [ "${SWAP_KB:-0}" -lt 1000000 ]; then
    warn "This host has $(( ${SWAP_KB:-0} / 1024 ))MB of swap. Production runs 2GB; add swap before enabling cutouts."
fi
