#!/usr/bin/env python3
"""Compare a /features payload before and after the cutover.

THE DESIGN, AND WHY IT IS NOT A FIELD-BY-FIELD DIFF
---------------------------------------------------
The first version of this walked the rows comparing the fields it thought
mattered. That is an instrument that can only see the failures its author
thought of, and Abdul-Rahman's challenge — "can the criterion SEE the promise
being broken?" — is exactly the question such a design fails.

So it works the other way round now. The expected change is applied to the
AFTER payload in reverse (each expected `is_available` flip is patched back to
its BEFORE value), and then the two documents must be **wholly equal**. Every
difference the cutover was not licensed to make surfaces by construction:
a renamed row, a normalised apostrophe, a moved icon host, an added key, a
dropped `pivot` object, a changed timestamp format, a different envelope.

Equality is on the PARSED document, not the bytes: key order and whitespace
inside a JSON object change nothing for any client, and asserting them would
produce failures that are noise. Row order IS asserted, because arrays keep
their order through parsing and Play vc13 positions tiles by index.

ONE TRAP THAT DEEP EQUALITY DOES NOT CATCH
------------------------------------------
In Python `True == 1`. So a payload that switched `is_available` from the
integer 1 to the boolean true compares EQUAL here while failing to decode on
Android, whose `FeaturesResponse` declares it an Int. That check is therefore
explicit and separate, and must stay that way.
"""

import json
import sys


def _ids(rows):
    return [r.get("id") for r in rows]


def compare(before_doc, after_doc, expected_flips, expect_shape_change=False,
            expected_row_ids=None):
    """Return a list of problems. Empty list means the cutover behaved.

    `expected_flips` are the legacy ids whose `pivot.is_available` is licensed
    to change from 1 to 0. Anything else that moved is a problem.

    `expect_shape_change` releases the BEFORE-to-AFTER row-set assertion for the
    one case where the row set is meant to change (an org with no pivot rows
    today, which serves [] and will serve eleven rows).

    It is NOT a blanket waiver and must not become one. `expected_row_ids` pins
    what the row set must BECOME, so a shape-change org is still asserted, just
    against a stated destination rather than against its own past. Without that
    pin, any row-set anomaly on org 17 would pass unseen — the same blind spot
    as pinning it "no change" was.
    """
    problems = []

    # 1. The envelope. Nothing below looks at it, and a client reads it first.
    if set(before_doc.keys()) != set(after_doc.keys()):
        problems.append(
            f"envelope keys changed: {sorted(before_doc)} -> {sorted(after_doc)}")
    if before_doc.get("status") != after_doc.get("status"):
        problems.append(
            f"status changed: {before_doc.get('status')!r} -> {after_doc.get('status')!r}")

    before = before_doc.get("data") or []
    after = after_doc.get("data") or []

    # 2. Row set and order. Play vc13 takes the first nine rows and routes by
    #    name, so a reorder rewires a drawer on a build nobody can update.
    if not expect_shape_change:
        if _ids(before) != _ids(after):
            problems.append(
                f"row set or order changed: {_ids(before)} -> {_ids(after)}")
            return problems  # nothing below is meaningful once rows moved
    elif not after:
        problems.append("expected this org to gain rows, and it has none")
        return problems
    elif expected_row_ids is not None and _ids(after) != list(expected_row_ids):
        problems.append(
            f"shape-change org did not land on the expected row set: "
            f"{_ids(after)} != {list(expected_row_ids)}")
        return problems

    # 3. is_available must stay an integer. Checked BEFORE the equality test,
    #    because Python's True == 1 would hide a bool here.
    for r in after:
        v = (r.get("pivot") or {}).get("is_available")
        if v is not None and isinstance(v, bool):
            problems.append(
                f"id {r.get('id')}: is_available is a boolean; Android declares it Int")
        elif v is not None and not isinstance(v, int):
            problems.append(
                f"id {r.get('id')}: is_available is {type(v).__name__}, must be int")

    # 4. Every row must carry a usable icon. A null here force-unwraps in the
    #    Flutter drawer and blanks the entire grid (the 2026-08-28 incident).
    for r in after:
        if not (r.get("icon") or {}).get("original_url"):
            problems.append(f"id {r.get('id')}: icon.original_url is null or empty")

    if expect_shape_change:
        # STATED WEAKNESS, not an oversight: with no before-image there is
        # nothing to demand equality against, so a shape-change org is checked
        # for its row set, integer-ness and icons ONLY. Names, keys, timestamps
        # and per-row envelope on its new rows are NOT verified. That makes the
        # criterion weaker for this one org than for the other eight, and a
        # reader should not have to infer it. Binding org 17's eleven resolved
        # values when config/app_feature_cutover.php lands is what closes it.
        return problems

    # 5. Patch the licensed change back out, then demand whole-document equality.
    #    The comparison is on the whole DOCUMENT, not just `data`: an earlier
    #    version compared the row arrays and checked the envelope only by key set
    #    and `status` value, so a top-level key whose VALUE changed passed clean.
    #    Production's envelope is only {status, data} so nothing was exposed, but
    #    a docstring promising more than the code delivers is the failure this
    #    file's own design note warns about.
    patched_doc = json.loads(json.dumps(after_doc))  # deep copy, no aliasing
    patched = patched_doc.get("data") or []
    by_id = {r.get("id"): r for r in patched}
    actually_flipped = []
    for rb, ra in zip(before, after):
        rid = rb.get("id")
        pb = (rb.get("pivot") or {}).get("is_available")
        pa = (ra.get("pivot") or {}).get("is_available")
        if pb != pa:
            actually_flipped.append(rid)
            # Direction is part of the licence. `flips` means "1 -> 0"; a
            # licensed id that went the other way would otherwise be absorbed
            # silently, licensing the opposite of what the file says. No entry
            # today has a licensed id whose before-value is 0, so this is
            # latent — which is precisely when to close it.
            if rid in expected_flips and not (pb == 1 and pa == 0):
                problems.append(
                    f"id {rid}: licensed for 1 -> 0 but went {pb!r} -> {pa!r}")
        if rid in expected_flips and rid in by_id and by_id[rid].get("pivot"):
            by_id[rid]["pivot"]["is_available"] = pb

    unexpected = sorted(set(actually_flipped) - set(expected_flips))
    missing = sorted(set(expected_flips) - set(actually_flipped))
    if unexpected:
        problems.append(f"is_available changed on unlicensed ids {unexpected}")
    if missing:
        problems.append(
            f"expected is_available to change on {missing} and it did not "
            f"— a cutover that did nothing would otherwise pass")

    if patched_doc != before_doc:
        if set(patched_doc) != set(before_doc):
            problems.append(
                f"envelope keys changed: {sorted(before_doc)} -> {sorted(patched_doc)}")
        for k in sorted(set(before_doc) | set(patched_doc)):
            if k != "data" and before_doc.get(k) != patched_doc.get(k):
                problems.append(
                    f"envelope field {k!r} changed "
                    f"{before_doc.get(k)!r} -> {patched_doc.get(k)!r}")
        for rb, rp in zip(before, patched):
            for k in sorted(set(rb) | set(rp)):
                if rb.get(k) != rp.get(k):
                    problems.append(
                        f"id {rb.get('id')}: field {k!r} changed "
                        f"{rb.get(k)!r} -> {rp.get(k)!r}")

    return problems


def main():
    if len(sys.argv) < 5:
        print("usage: compare_features.py <org> <before.json> <after.json> <expected-delta.json>")
        return 2
    org, before_p, after_p, delta_p = sys.argv[1:5]
    try:
        before_doc = json.load(open(before_p))
        after_doc = json.load(open(after_p))
        entry = json.load(open(delta_p))["orgs"][org]
    except KeyError:
        print(f"    FAIL org {org}: no entry in expected-delta.json — "
              f"the acceptance criterion does not cover this org")
        return 1
    except Exception as exc:
        print(f"    FAIL org {org}: unreadable input ({exc})")
        return 1

    problems = compare(
        before_doc, after_doc,
        expected_flips=entry.get("flips", []),
        expect_shape_change=entry.get("expect_shape_change", False),
        expected_row_ids=entry.get("expected_row_ids"),
    )
    if problems:
        for p in problems[:6]:
            print(f"    FAIL org {org}: {p}")
        return 1
    if entry.get("expect_shape_change"):
        print(f"    PASS org {org}: gained rows as expected, shape and icons intact")
    elif entry.get("flips"):
        print(f"    PASS org {org}: exactly the licensed flips {entry['flips']}, "
              f"everything else identical")
    else:
        print(f"    PASS org {org}: wholly identical, order intact")
    return 0


if __name__ == "__main__":
    sys.exit(main())
