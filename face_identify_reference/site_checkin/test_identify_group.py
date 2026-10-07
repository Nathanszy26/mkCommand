# -*- coding: utf-8 -*-
"""
Exercise the staged matching of /identify_group against synthetic scores.

assign_faces() is copied line for line from face_service.py; the stage pipeline
below mirrors identify_group()'s use of it. No image or database is involved —
these check the assignment rules, which is where the subtle bugs live.

Run: python test_identify_group.py
"""
import numpy as np

POOL_TOLERANCE = 0.5
OUTSIDE_TOLERANCE = 0.45


# ---- verbatim from face_service.py ----
def assign_faces(face_items, gallery, tolerance, taken):
    if not gallery or not face_items:
        return {}

    gallery_encodings = np.array([g['encoding'] for g in gallery])

    candidates = []
    for face_index, encoding in face_items:
        distances = np.linalg.norm(gallery_encodings - encoding, axis=1)
        best_idx = int(np.argmin(distances))
        candidates.append({
            'face_index': face_index,
            'best_idx': best_idx,
            'distance': float(distances[best_idx]),
        })

    matches = {}
    for cand in sorted(candidates, key=lambda c: c['distance']):
        if cand['distance'] > tolerance:
            continue
        entry = gallery[cand['best_idx']]
        person_key = (entry['person'], entry['comp_id'], entry['is_gw'])
        if person_key in taken:
            continue
        taken.add(person_key)
        matches[cand['face_index']] = (entry, cand['distance'])

    return matches
# ---- end verbatim ----


def run_stages(encodings, pool_gallery, wide_gw, wide_staff, search_outside=True):
    """Mirrors identify_group()'s three-stage pipeline."""
    pending = list(enumerate(encodings))
    taken, found, in_pool = set(), {}, set()

    hits = assign_faces(pending, pool_gallery, POOL_TOLERANCE, taken)
    found.update(hits)
    in_pool.update(hits.keys())
    pending = [i for i in pending if i[0] not in hits]

    if search_outside:
        for gallery in (wide_gw, wide_staff):
            if not pending:
                break
            hits = assign_faces(pending, gallery, OUTSIDE_TOLERANCE, taken)
            found.update(hits)
            pending = [i for i in pending if i[0] not in hits]

    return found, in_pool


# A 1-D "encoding" keeps distances trivially readable: distance = |a - b|.
def enc(x):
    return np.array([float(x)])


def person(name, is_gw, at):
    return {'person': name, 'comp_id': '1', 'is_gw': is_gw, 'encoding': enc(at)}


failures = []


def check(label, got, want):
    ok = got == want
    print(('PASS  ' if ok else 'FAIL  ') + label)
    if not ok:
        failures.append('%s\n   got:  %r\n   want: %r' % (label, got, want))


# Pool: two of the mandor's own GW. Wide: a GW and a staff member from elsewhere.
POOL = [person('PPR-24', 1, 10.0), person('PPR115', 1, 20.0)]
WIDE_GW = [person('BFT126', 1, 10.3), person('SPN101', 1, 30.0)]
WIDE_STAFF = [person('andrewjohnny', 0, 40.0)]


# 1. A face right on a pool member is answered by the pool, not the wide search.
found, in_pool = run_stages([enc(10.1)], POOL, WIDE_GW, WIDE_STAFF)
check('pool member wins over a closer-ranked wide gallery',
      (found[0][0]['person'], 0 in in_pool), ('PPR-24', True))

# 2. The pool is tried FIRST even when a wide-gallery face is nearer.
#    A face at 10.2 is 0.2 from PPR-24 (pool) and 0.1 from BFT126 (wide).
#    The pool still answers it — that is the whole point of the split.
found, in_pool = run_stages([enc(10.2)], POOL, WIDE_GW, WIDE_STAFF)
check('pool is searched before the wide gallery, even if wide is nearer',
      found[0][0]['person'], 'PPR-24')

# 3. A face nobody in the pool is near falls through to the wide GW gallery.
found, in_pool = run_stages([enc(30.1)], POOL, WIDE_GW, WIDE_STAFF)
check('unmatched-by-pool falls through to wide GW',
      (found[0][0]['person'], 0 in in_pool), ('SPN101', False))

# 4. Staff are only reached after GW.
found, in_pool = run_stages([enc(40.1)], POOL, WIDE_GW, WIDE_STAFF)
check('staff gallery is reached last', found[0][0]['person'], 'andrewjohnny')

# 5. The stricter outside tolerance really is stricter.
#    0.47 clears POOL_TOLERANCE (0.5) but not OUTSIDE_TOLERANCE (0.45).
found, _ = run_stages([enc(30.47)], POOL, WIDE_GW, WIDE_STAFF)
check('0.47 away from a wide-gallery face is rejected', found, {})
found, _ = run_stages([enc(10.47)], POOL, WIDE_GW, WIDE_STAFF)
check('0.47 away from a POOL face is accepted', found[0][0]['person'], 'PPR-24')

# 6. One identity cannot be claimed twice, and the closer face wins it.
#    The losing face is NOT dropped — it carries on to the later stages and may
#    well find someone else there. What must never happen is both faces being
#    handed the same name.
found, _ = run_stages([enc(10.4), enc(10.05)], POOL, WIDE_GW, WIDE_STAFF)
check('nearer face keeps the contested identity', found[1][0]['person'], 'PPR-24')
check('the two faces are never given the same name',
      found[0][0]['person'] == found[1][0]['person'], False)

# 6b. With nothing else to find, the losing face is simply left unknown.
found, _ = run_stages([enc(10.4), enc(10.05)], POOL, [], [])
check('contested loser is unknown when no other gallery can place it',
      sorted(found.keys()), [1])

# 7. An identity taken by the pool stage cannot be re-found by the wide stage.
#    Both faces sit near PPR-24; the loser must not resurface as anyone.
found, _ = run_stages([enc(10.02), enc(10.3)], POOL, [person('PPR-24', 1, 10.3)], [])
names = sorted(f[0]['person'] for f in found.values())
check('an identity claimed in stage 1 cannot reappear in stage 2', names, ['PPR-24'])

# 8. search_outside=False confines everything to the pool.
found, _ = run_stages([enc(30.1)], POOL, WIDE_GW, WIDE_STAFF, search_outside=False)
check('search_outside=False never leaves the pool', found, {})

# 9. An empty pool still works - every face goes to the wide search.
found, in_pool = run_stages([enc(30.05)], [], WIDE_GW, WIDE_STAFF)
check('empty pool degrades to a plain wide search',
      (found[0][0]['person'], len(in_pool)), ('SPN101', 0))

# 10. A realistic muster photo: 3 of the mandor's own, 1 genuine outsider,
#     2 strangers nobody has enrolled.
faces = [enc(10.1), enc(20.1), enc(10.9), enc(30.1), enc(55.0), enc(70.0)]
POOL3 = POOL + [person('KKW-270', 1, 10.95)]
found, in_pool = run_stages(faces, POOL3, WIDE_GW, WIDE_STAFF)
check('muster: 3 pool + 1 outside identified', len(found), 4)
check('muster: exactly 3 came from the pool', len(in_pool), 3)
check('muster: 2 faces left unknown', len(faces) - len(found), 2)
check('muster: no duplicate names',
      len(set(f[0]['person'] for f in found.values())), 4)

print()
if failures:
    print('%d FAILURE(S)\n' % len(failures))
    for f in failures:
        print(f)
    raise SystemExit(1)
print('All staged-matching checks passed.')
