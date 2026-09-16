#!/usr/bin/env python3
"""Break a real payload seventeen ways and check the criterion screams.

Abdul-Rahman's challenge: nobody is asking whether the instrument can SEE the
promise being broken. Reading the comparator cannot answer that — only
mutating a real capture and watching it can.
"""
import copy, json, sys
from compare_features import compare

BEFORE = json.load(open(sys.argv[1]))          # a real staging capture, 11 rows

def mutate(name, fn, expected_flips=(), expect_shape=False, must_differ=True):
    after = copy.deepcopy(BEFORE)
    fn(after)
    # GUARD: a mutation that did not mutate reports a false MISS and quietly
    # tells you the instrument is blind when the test is simply broken. That
    # happened on the first run — an icon-host replace matched nothing because
    # the host is `masjid-staging.`, not `masjid.`.
    # Compared as SERIALISED JSON, not with ==. Python's True == 1 means a
    # payload whose is_available turned from 1 into true compares EQUAL to its
    # original — the very trap this suite exists to prove the comparator sees.
    # The guard was fooled by it on the first run.
    if must_differ and json.dumps(after, sort_keys=True) == json.dumps(BEFORE, sort_keys=True):
        print(f"  {'*** TEST BROKEN':<15} {name}: the mutation changed nothing")
        return False
    problems = compare(BEFORE, after, list(expected_flips), expect_shape)
    verdict = "DETECTED" if problems else "*** MISSED ***"
    print(f"  {verdict:<15} {name}")
    if problems:
        print(f"                  -> {problems[0][:96]}")
    return bool(problems)

rows = lambda d: d["data"]
results = []

# 1. Row ORDER changed, same set of rows (invisible to a set comparison)
results.append(mutate("row order swapped, same rows",
    lambda d: rows(d).insert(0, rows(d).pop(3))))

# 2. A NAME normalised — Qur'an losing its U+2019 to an ASCII apostrophe
results.append(mutate("name normalised (U+2019 -> ASCII)",
    lambda d: rows(d)[0].update(name=rows(d)[0]["name"].replace("’", "'"))))

# 3. An icon URL changing host or path
results.append(mutate("icon URL host changed",
    lambda d: rows(d)[2]["icon"].update(
        original_url="https://cdn.example.net/hijacked/icon.svg")))

# 4. An org GAINING rows it did not have ([] -> eleven). Written as a plain
#    comparison rather than through mutate(), because the BEFORE side is the
#    empty document, not a mutation of the capture. Kept in the same shape as
#    its neighbours: one call, one verdict, no nested lambda.
_gain = compare({"status": "success", "data": []}, BEFORE, [], expect_shape_change=False)
print(f"  {('DETECTED' if _gain else '*** MISSED ***'):<15} org gains rows ([] -> 11)")
if _gain:
    print(f"                  -> {_gain[0][:96]}")
results.append(bool(_gain))

# 5. is_available changed on an UNLICENSED row
results.append(mutate("is_available flipped on an unlicensed id",
    lambda d: rows(d)[6]["pivot"].update(is_available=0)))

# 5b. the licensed flip did NOT happen (a cutover that did nothing)
results.append(mutate("licensed flip did NOT happen (no-op cutover)",
    lambda d: None, expected_flips=[7], must_differ=False))

# 6a. envelope: the pivot object dropped from a row
results.append(mutate("pivot object dropped from a row",
    lambda d: rows(d)[1].pop("pivot")))

# 6b. envelope: a key added to a row
results.append(mutate("new key added to a row",
    lambda d: rows(d)[4].update(sort_order=3)))

# 6c. envelope: timestamp format changed
results.append(mutate("timestamp format changed",
    lambda d: rows(d)[0].update(created_at="2025-03-27 15:03:53")))

# 6d. envelope: top-level status changed
results.append(mutate("top-level status changed",
    lambda d: d.update(status="ok")))

# 6e. THE TRAP: is_available int -> boolean (Python's True == 1 hides this)
results.append(mutate("is_available int -> boolean (True == 1 in Python)",
    lambda d: rows(d)[5]["pivot"].update(is_available=True)))

# 7. icon nulled (the 2026-08-28 drawer-blanking incident)
results.append(mutate("icon.original_url nulled",
    lambda d: rows(d)[3]["icon"].update(original_url=None)))

# 8. ABDULLAH 2026-09-16: a PARTIAL pivot. `writesFor()` returns [] for an
#    individual missing id, so derivation silently governs it with no finding
#    printed. Production has no partial org today (8 orgs at eleven rows, org 17
#    at zero), so this is latent — but a row deleted between now and S2b makes it
#    live, and the harness is the right place to pin it.
results.append(mutate("a pivot row is missing before the cutover",
    lambda d: rows(d).pop(4)))

# 8b. The same hole seen from the shape-change side: an org released by
#     expect_shape_change must still land on a STATED row set, or the release
#     hides every row-set anomaly the way "no change" hid org 17's.
before_empty = {"status": "success", "data": []}
canonical = [r["id"] for r in BEFORE["data"]]
short = copy.deepcopy(BEFORE); short["data"].pop(2)
from compare_features import compare as _c
_p = _c(before_empty, short, [], expect_shape_change=True, expected_row_ids=canonical)
print(f"  {('DETECTED' if _p else '*** MISSED ***'):<15} shape-change org lands on the WRONG row set")
if _p: print(f"                  -> {_p[0][:96]}")
results.append(bool(_p))

# 9. REGRESSION for finding 1 (Abdul-Rahman, 2026-09-16): a top-level envelope
#    key whose VALUE changed, other than `status`. The old code compared only
#    the `data` arrays plus an envelope KEY-SET check, so this passed clean.
#    Tested precisely: a key present in BOTH documents whose VALUE differs.
#    (Adding a key was already caught by the key-set check; the gap was a
#    changed value on an existing non-`status` key. Production's envelope is
#    only {status, data} today, which is why this was latent.)
_env_b = copy.deepcopy(BEFORE); _env_b["meta"] = {"page": 1}
_env_a = copy.deepcopy(BEFORE); _env_a["meta"] = {"page": 2}
_env = compare(_env_b, _env_a, [])
print(f"  {('DETECTED' if _env else '*** MISSED ***'):<15} envelope field VALUE changed (same keys)")
if _env:
    print(f"                  -> {_env[0][:96]}")
results.append(bool(_env))

# 10. REGRESSION for finding 3: a licensed id that moved 0 -> 1, the opposite
#     of what `flips` licenses. The old code absorbed any change on a licensed
#     id and patched it back, licensing the reverse of the documented rule.
def _reverse_flip(d):
    rows(d)[6]["pivot"]["is_available"] = 1        # id 7, after
BEFORE_ZERO = copy.deepcopy(BEFORE)
BEFORE_ZERO["data"][6]["pivot"]["is_available"] = 0  # id 7 starts OFF
_after = copy.deepcopy(BEFORE_ZERO); _reverse_flip(_after)
_rev = compare(BEFORE_ZERO, _after, [7])
print(f"  {('DETECTED' if _rev else '*** MISSED ***'):<15} licensed id moved 0 -> 1 (wrong direction)")
if _rev:
    print(f"                  -> {_rev[0][:96]}")
results.append(bool(_rev))

# CONTROL: an untouched payload must NOT be flagged, or every result above is noise
clean = compare(BEFORE, copy.deepcopy(BEFORE), [])
print(f"  {'CLEAN' if not clean else '*** FALSE POSITIVE ***':<15} control: untouched payload")
results.append(not clean)

print(f"\n  {sum(results)}/{len(results)} checks behaved correctly.")
sys.exit(0 if all(results) else 1)
