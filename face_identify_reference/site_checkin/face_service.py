#!/usr/bin/env python3
"""
Face Recognition Service for Staff Attendance
Runs as a systemd service at http://127.0.0.1:5000

Endpoints:
  POST /recognize  - Match a face against ONE named person's encodings (1:1)
  POST /identify   - Find WHO a face belongs to, across staff + GW (1:N)
  POST /enroll     - Register a new face encoding for a staff member
  GET  /health     - Health check
"""

import os
import json
import base64
import logging
import numpy as np
from io import BytesIO
from datetime import datetime

import face_recognition
from flask import Flask, request, jsonify
from PIL import Image, ImageOps
import mysql.connector
from mysql.connector import pooling

# ======================== CONFIG ========================

# Remote MySQL on globportal.com
DB_CONFIG = {
    'host': 'globportal.com',
    'port': 33060,
    'user': 'prog3',
    'password': 'prog3@2023',
    'database': 'mkPortal',
    'connection_timeout': 5,
}

# Face matching threshold: lower = stricter matching
# 0.6 is the default recommended by face_recognition library
# 0.5 = very strict, 0.7 = more lenient
MATCH_TOLERANCE = 0.6

# Flask config
HOST = '127.0.0.1'
PORT = 5000

# Service directory
SERVICE_DIR = '/var/www/html/glob/it/mkPortal/site_checkin/face_services'

# ======================== SETUP ========================

app = Flask(__name__)

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(message)s',
    handlers=[
        logging.FileHandler(os.path.join(SERVICE_DIR, 'face_service.log')),
    ]
)
logger = logging.getLogger(__name__)

# Connection pool for MySQL
db_pool = None

def init_db_pool():
    global db_pool
    try:
        db_pool = pooling.MySQLConnectionPool(
            pool_name='face_service_pool',
            pool_size=5,
            pool_reset_session=True,
            **DB_CONFIG
        )
        logger.info('Database connection pool created (remote: %s:%s)', DB_CONFIG['host'], DB_CONFIG['port'])
    except mysql.connector.Error as e:
        logger.error('Failed to create DB pool: %s', e)
        raise

def get_db_connection(retries=2):
    """Get a connection from the pool, reinitialising it if necessary."""
    global db_pool
    if db_pool is None:
        logger.warning('DB pool is None — attempting reinit')
        init_db_pool()

    last_err = None
    for attempt in range(retries):
        try:
            conn = db_pool.get_connection()
            return conn
        except mysql.connector.Error as e:
            last_err = e
            logger.warning('Pool connection attempt %d/%d failed: %s', attempt + 1, retries, e)
    raise last_err


# ======================== HELPERS ========================

def decode_base64_image(base64_string):
    """Decode a base64 string to a numpy image array for face_recognition."""
    try:
        # Strip data URI prefix if present
        if ',' in base64_string:
            base64_string = base64_string.split(',', 1)[1]

        image_bytes = base64.b64decode(base64_string)
        pil_image = Image.open(BytesIO(image_bytes))

        # Apply EXIF orientation (raw sensor images from vision-camera are rotated)
        pil_image = ImageOps.exif_transpose(pil_image)

        # Downscale large images to prevent gunicorn worker timeouts / OOM kills.
        # Phone cameras send 4000x3000+ (12MP) images; HOG on those can take
        # 30-60s and ~1GB RAM.  800px max keeps it under 2s with no meaningful
        # accuracy loss for a single-face check-in.
        MAX_DIMENSION = 480
        if max(pil_image.size) > MAX_DIMENSION:
            pil_image.thumbnail((MAX_DIMENSION, MAX_DIMENSION), Image.LANCZOS)

        # Convert to RGB (face_recognition requires RGB)
        if pil_image.mode != 'RGB':
            pil_image = pil_image.convert('RGB')

        return np.array(pil_image)
    except Exception as e:
        logger.error('Image decode failed: %s', e)
        return None


def load_enrolled_encodings(person=None, comp_id=None, mymk_id=None):
    """
    Load face encodings from database.
    Checking order: person + comp_id first, then mymk_id fallback.
    """
    conn = None
    try:
        conn = get_db_connection()
        cursor = conn.cursor(dictionary=True)

        encodings = []

        # Priority: match by person + comp_id
        if person and comp_id:
            cursor.execute(
                "SELECT id, mymk_id, person, comp_id, encoding "
                "FROM staff_face_encodings "
                "WHERE person = %s AND comp_id = %s AND deleted = '0'",
                (person, str(comp_id))
            )
            rows = cursor.fetchall()
            encodings = _parse_encoding_rows(rows)

        # Fallback: match by mymk_id (only if person+comp_id found nothing)
        if not encodings and mymk_id:
            cursor.execute(
                "SELECT id, mymk_id, person, comp_id, encoding "
                "FROM staff_face_encodings "
                "WHERE mymk_id = %s AND deleted = '0'",
                (int(mymk_id),)
            )
            rows = cursor.fetchall()
            encodings = _parse_encoding_rows(rows)

        cursor.close()
        return encodings
    except mysql.connector.Error as e:
        logger.error('DB error loading encodings: %s', e)
        return []
    finally:
        if conn:
            conn.close()


def _parse_encoding_rows(rows):
    """Parse DB rows into encoding dicts."""
    encodings = []
    for row in rows:
        try:
            enc_array = json.loads(row['encoding'])
            encodings.append({
                'id': row['id'],
                'mymk_id': row['mymk_id'],
                'person': row['person'],
                'comp_id': row['comp_id'],
                'encoding': np.array(enc_array, dtype=np.float64)
            })
        except (json.JSONDecodeError, ValueError) as e:
            logger.warning('Bad encoding data for id=%s: %s', row['id'], e)
    return encodings


# GW live only in comp 1, and gw_face_encodings carries no comp_id of its own.
# Same constant staffHierarchy.php calls GW_COMP_ID.
GW_COMP_ID = '1'


def load_identify_gallery(comp_id=None, include_gw=True):
    """
    Every enrolled face to search for 1:N identification — staff and GW together.

    Used ONLY by /identify. /recognize keeps its own loader and its own 1:1
    behaviour, so nothing about the check-in flow changes.

    comp_id=None searches every company. A company filter applies to staff
    directly; GW are included only when the filter is comp 1, because that is
    the only company they exist in.

    Returns dicts shaped like the app's person key: (person, comp_id, is_gw).
    No join to staff_portal2 — the caller already has a directory API for names
    and photos, and keeping this read inside mkPortal means the service needs no
    grant it does not already have.
    """
    conn = None
    try:
        conn = get_db_connection()
        cursor = conn.cursor(dictionary=True)
        gallery = []

        sql = ("SELECT person, comp_id, mymk_id, encoding "
               "FROM staff_face_encodings "
               "WHERE deleted = '0' AND person IS NOT NULL AND person <> ''")
        params = []
        if comp_id:
            sql += " AND comp_id = %s"
            params.append(str(comp_id))
        cursor.execute(sql, tuple(params))

        for row in cursor.fetchall():
            try:
                gallery.append({
                    'person': row['person'],
                    'comp_id': str(row['comp_id']),
                    'mymk_id': row['mymk_id'],
                    'is_gw': 0,
                    'encoding': np.array(json.loads(row['encoding']), dtype=np.float64),
                })
            except (json.JSONDecodeError, ValueError, TypeError) as e:
                logger.warning('Bad staff encoding person=%s: %s', row.get('person'), e)

        if include_gw and (comp_id is None or str(comp_id) == GW_COMP_ID):
            cursor.execute(
                "SELECT monthly_assign_gw_code AS code, monthly_assign_gw_id AS gw_id, encoding "
                "FROM gw_face_encodings "
                "WHERE deleted = '0' AND monthly_assign_gw_code IS NOT NULL "
                "  AND monthly_assign_gw_code <> ''"
            )
            for row in cursor.fetchall():
                try:
                    gallery.append({
                        'person': row['code'],
                        'comp_id': GW_COMP_ID,
                        'mymk_id': row['gw_id'],
                        'is_gw': 1,
                        'encoding': np.array(json.loads(row['encoding']), dtype=np.float64),
                    })
                except (json.JSONDecodeError, ValueError, TypeError) as e:
                    logger.warning('Bad GW encoding code=%s: %s', row.get('code'), e)

        cursor.close()
        return gallery
    except mysql.connector.Error as e:
        logger.error('DB error loading identify gallery: %s', e)
        return []
    finally:
        if conn:
            conn.close()


def save_encoding(person, comp_id, mymk_id, encoding_array, source_note=None):
    """Save a face encoding to the database."""
    conn = None
    try:
        conn = get_db_connection()
        cursor = conn.cursor()

        encoding_json = json.dumps(encoding_array.tolist())

        cursor.execute(
            "INSERT INTO staff_face_encodings "
            "(mymk_id, person, comp_id, encoding, source_note, created_at) "
            "VALUES (%s, %s, %s, %s, %s, NOW())",
            (int(mymk_id) if mymk_id else None, person, str(comp_id), encoding_json, source_note)
        )

        conn.commit()
        new_id = cursor.lastrowid
        cursor.close()

        return new_id
    except mysql.connector.Error as e:
        logger.error('DB error saving encoding: %s', e)
        return None
    finally:
        if conn:
            conn.close()


# ======================== ENDPOINTS ========================

@app.route('/health', methods=['GET'])
def health():
    """Health check endpoint."""
    # Also test DB connection
    db_ok = False
    try:
        conn = get_db_connection()
        cursor = conn.cursor()
        cursor.execute("SELECT 1")
        cursor.fetchone()
        cursor.close()
        conn.close()
        db_ok = True
    except Exception as e:
        logger.error('Health check DB test failed: %s', e)

    return jsonify({
        'status': 'ok',
        'service': 'face-recognition',
        'database': 'connected' if db_ok else 'disconnected',
        'timestamp': datetime.now().isoformat()
    })


@app.route('/recognize', methods=['POST'])
def recognize():
    """
    Match a face against enrolled encodings.

    Request JSON:
    {
        "image": "<base64 encoded image>",
        "person": "person_value",
        "comp_id": "123",
        "mymk_id": "456"
    }
    """
    data = request.get_json()

    if not data or 'image' not in data:
        return jsonify({'error': 'Missing "image" field'}), 400

    # Decode the submitted image
    image = decode_base64_image(data['image'])
    if image is None:
        return jsonify({'error': 'Invalid image data'}), 400

    # Detect faces in the submitted image
    face_locations = face_recognition.face_locations(image, model='hog')

    if len(face_locations) == 0:
        logger.warning('Recognition failed: no face detected, person=%s, comp_id=%s',
                        data.get('person'), data.get('comp_id'))
        return jsonify({
            'matched': False,
            'confidence': 0,
            'error': 'No face detected in the image. Please try again with better lighting and face the camera directly.'
        })

    if len(face_locations) > 1:
        logger.warning('Recognition failed: multiple faces detected, person=%s, comp_id=%s',
                        data.get('person'), data.get('comp_id'))
        return jsonify({
            'matched': False,
            'confidence': 0,
            'error': 'Multiple faces detected. Please ensure only your face is visible.'
        })

    # Compute encoding for the detected face
    submitted_encodings = face_recognition.face_encodings(image, face_locations)
    if len(submitted_encodings) == 0:
        logger.warning('Recognition failed: could not compute encoding, person=%s', data.get('person'))
        return jsonify({
            'matched': False,
            'confidence': 0,
            'error': 'Could not process face. Please try again.'
        })

    submitted_encoding = submitted_encodings[0]

    # Load enrolled encodings using person+comp_id or mymk_id
    person = data.get('person')
    comp_id = data.get('comp_id')
    mymk_id = data.get('mymk_id')

    enrolled = load_enrolled_encodings(person=person, comp_id=comp_id, mymk_id=mymk_id)

    if len(enrolled) == 0:
        msg = 'No face enrolled for this staff member.'
        logger.warning('Recognition failed: no enrolled face, person=%s, comp_id=%s, mymk_id=%s',
                        person, comp_id, mymk_id)
        return jsonify({
            'matched': False,
            'confidence': 0,
            'error': msg
        })

    # Compare against all enrolled encodings
    enrolled_arrays = [e['encoding'] for e in enrolled]
    distances = face_recognition.face_distance(enrolled_arrays, submitted_encoding)

    # Find the best (smallest distance) match
    best_idx = int(np.argmin(distances))
    best_distance = float(distances[best_idx])

    # Convert distance to confidence (0-1 where 1 = perfect match)
    confidence = max(0.0, 1.0 - best_distance)

    matched = best_distance <= MATCH_TOLERANCE
    matched_entry = enrolled[best_idx]

    if matched:
        logger.info(
            'Recognition result: matched=True, confidence=%.4f, distance=%.4f, '
            'person=%s, comp_id=%s, mymk_id=%s',
            confidence, best_distance,
            matched_entry['person'], matched_entry['comp_id'], matched_entry['mymk_id']
        )
    else:
        logger.warning(
            'Recognition result: matched=False, confidence=%.4f, distance=%.4f, '
            'requested person=%s, comp_id=%s, mymk_id=%s',
            confidence, best_distance, person, comp_id, mymk_id
        )

    result = {
        'matched': matched,
        'confidence': round(confidence, 4),
        'distance': round(best_distance, 4),
    }

    if matched:
        result['mymk_id'] = str(matched_entry['mymk_id']) if matched_entry['mymk_id'] else None

    return jsonify(result)


@app.route('/identify', methods=['POST'])
def identify():
    """
    1:N identification: given a probe image, return the best-matching enrolled
    person across staff AND GW.

    Purely additive. /recognize (1:1 check-in) and /enroll are untouched and
    keep their own code paths — this endpoint shares only the image decoder.

    Request JSON:
    {
        "image": "<base64 encoded image>",
        "comp_id": "1",        (optional — omit to search every company)
        "include_gw": true     (optional, default true)
    }

    Replies with the identity only: (person, comp_id, is_gw) is the key the app
    already uses everywhere, so it resolves the name, photo and department
    through the staff directory it already talks to.
    """
    data = request.get_json(silent=True) or {}

    if 'image' not in data:
        return jsonify({'matched': False, 'error': 'Missing "image" field'}), 400

    image = decode_base64_image(data['image'])
    if image is None:
        return jsonify({'matched': False, 'error': 'Invalid image data'}), 400

    face_locations = face_recognition.face_locations(image, model='hog')

    if len(face_locations) == 0:
        return jsonify({
            'matched': False,
            'confidence': 0,
            'error': 'No face detected in the photo. Use a clearer, well-lit picture of one face.'
        })

    if len(face_locations) > 1:
        return jsonify({
            'matched': False,
            'confidence': 0,
            'error': 'More than one face in the photo. Use a picture with a single face.'
        })

    submitted_encodings = face_recognition.face_encodings(image, face_locations)
    if len(submitted_encodings) == 0:
        return jsonify({
            'matched': False,
            'confidence': 0,
            'error': 'Could not read that face. Try another photo.'
        })

    submitted = submitted_encodings[0]

    comp_id = data.get('comp_id')
    if comp_id in (None, '', 0, '0'):
        comp_id = None
    include_gw = data.get('include_gw', True)

    gallery = load_identify_gallery(comp_id=comp_id, include_gw=bool(include_gw))
    if len(gallery) == 0:
        return jsonify({
            'matched': False,
            'confidence': 0,
            'error': 'No staff photos to search.'
        })

    distances = face_recognition.face_distance([g['encoding'] for g in gallery], submitted)
    best_idx = int(np.argmin(distances))
    best_distance = float(distances[best_idx])
    confidence = max(0.0, 1.0 - best_distance)
    matched = best_distance <= MATCH_TOLERANCE
    best = gallery[best_idx]

    log_fn = logger.info if matched else logger.warning
    log_fn('identify: matched=%s confidence=%.4f distance=%.4f person=%s comp_id=%s is_gw=%s '
           'gallery=%d scope=%s',
           matched, confidence, best_distance, best['person'], best['comp_id'], best['is_gw'],
           len(gallery), comp_id or 'all')

    if not matched:
        return jsonify({
            'matched': False,
            'confidence': round(confidence, 4),
            'distance': round(best_distance, 4),
            'searched': len(gallery),
            'error': 'No matching staff found.'
        })

    return jsonify({
        'matched': True,
        'confidence': round(confidence, 4),
        'distance': round(best_distance, 4),
        'searched': len(gallery),
        'person': best['person'],
        'comp_id': best['comp_id'],
        'is_gw': best['is_gw'],
        'mymk_id': str(best['mymk_id']) if best['mymk_id'] else None,
    })

# ======================== GROUP IDENTIFY ========================
#
# Everything below is additive. /recognize, /identify and /enroll are untouched:
# a group photo needs a different image pipeline from a check-in selfie, so it
# gets its own decoder and its own route rather than loosening the ones the
# check-in flow depends on.

# A check-in selfie is one face filling the frame, so decode_base64_image()
# shrinks to 480px and HOG still sees it. A muster photo is 8-20 people in a
# row, where every pixel of face matters. Today's uploads arrive at 1600x1200
# (the timestamp-camera app downscales before upload), so this cap is usually
# not even reached — it is here so that a higher-resolution upload, if that app
# is ever reconfigured, is NOT thrown away.
GROUP_MAX_DIMENSION = 2600

# Two thresholds, because the two stages make claims of very different strength.
#
# Pool stage: the candidate set is one mandor's own roster, a dozen people. The
# prior that a face belongs to one of them is high and there is almost no room
# for a stranger to win, so the normal tolerance is safe.
POOL_TOLERANCE = 0.5
# Outside stage: "a GW from another district was in this photo" is a far stronger
# assertion, made against a gallery ~200x larger where a lookalike is ~200x more
# likely. It has to clear a higher bar. This is exactly the failure the pool
# split exists to stop: scanning all 3,200 enrolled faces for a 40px masked face
# returned GWs from Beaufort and Sipitang standing in a Papar road crew.
OUTSIDE_TOLERANCE = 0.45

# Guard against a crowd shot (or a false-positive storm) pinning a worker.
GROUP_MAX_FACES = 40

# GW exist only in company 1, and gw_face_encodings carries no comp_id column.
GROUP_COMP_ID = '1'


def decode_base64_image_group(base64_string):
    """
    Decode a base64 string to a numpy array, preserving enough resolution for
    the small faces in a group photo. Same EXIF handling and RGB conversion as
    decode_base64_image(), but capped at GROUP_MAX_DIMENSION instead of 480.
    """
    try:
        if ',' in base64_string:
            base64_string = base64_string.split(',', 1)[1]

        image_bytes = base64.b64decode(base64_string)
        pil_image = Image.open(BytesIO(image_bytes))
        pil_image = ImageOps.exif_transpose(pil_image)

        if max(pil_image.size) > GROUP_MAX_DIMENSION:
            pil_image.thumbnail((GROUP_MAX_DIMENSION, GROUP_MAX_DIMENSION), Image.LANCZOS)

        if pil_image.mode != 'RGB':
            pil_image = pil_image.convert('RGB')

        return np.array(pil_image)
    except Exception as e:
        logger.error('Group image decode failed: %s', e)
        return None


def _rows_to_gallery(rows, is_gw):
    """Shared row -> gallery-entry mapping, so every loader below agrees on shape."""
    gallery = []
    for row in rows:
        try:
            gallery.append({
                'person': row['person'],
                'comp_id': str(row['comp_id']),
                'mymk_id': row['mymk_id'],
                'is_gw': is_gw,
                'encoding': np.array(json.loads(row['encoding']), dtype=np.float64),
            })
        except (json.JSONDecodeError, ValueError, TypeError) as e:
            logger.warning('Bad encoding for %s: %s', row.get('person'), e)
    return gallery


def load_pool_gallery(gw_codes, staff_keys):
    """
    Every enrolled face of ONE mandor's attendance pool: the GW assigned or
    allocated to them on the report date, plus the staff standing with them.

    This is the gallery that should answer almost every face in a muster photo,
    and it is ~200x smaller than the full one, which is the entire point.
    """
    if not gw_codes and not staff_keys:
        return []

    conn = None
    try:
        conn = get_db_connection()
        cursor = conn.cursor(dictionary=True)
        gallery = []

        if gw_codes:
            placeholders = ','.join(['%s'] * len(gw_codes))
            cursor.execute(
                "SELECT monthly_assign_gw_code AS person, '" + GROUP_COMP_ID + "' AS comp_id, "
                "       monthly_assign_gw_id AS mymk_id, encoding "
                "FROM gw_face_encodings "
                "WHERE deleted = '0' AND monthly_assign_gw_code IN (" + placeholders + ")",
                tuple(gw_codes))
            gallery.extend(_rows_to_gallery(cursor.fetchall(), 1))

        if staff_keys:
            clause = ' OR '.join(['(person = %s AND comp_id = %s)'] * len(staff_keys))
            params = []
            for k in staff_keys:
                params.extend([k.get('person'), str(k.get('comp_id', GROUP_COMP_ID))])
            cursor.execute(
                "SELECT person, comp_id, mymk_id, encoding "
                "FROM staff_face_encodings "
                "WHERE deleted = '0' AND (" + clause + ")",
                tuple(params))
            gallery.extend(_rows_to_gallery(cursor.fetchall(), 0))

        cursor.close()
        return gallery
    except mysql.connector.Error as e:
        logger.error('DB error loading pool gallery: %s', e)
        return []
    finally:
        if conn:
            conn.close()


def load_wide_gw_gallery():
    """Every enrolled GW. GW exist only in company 1, so no comp filter applies."""
    conn = None
    try:
        conn = get_db_connection()
        cursor = conn.cursor(dictionary=True)
        cursor.execute(
            "SELECT monthly_assign_gw_code AS person, '" + GROUP_COMP_ID + "' AS comp_id, "
            "       monthly_assign_gw_id AS mymk_id, encoding "
            "FROM gw_face_encodings "
            "WHERE deleted = '0' AND monthly_assign_gw_code IS NOT NULL "
            "  AND monthly_assign_gw_code <> ''")
        gallery = _rows_to_gallery(cursor.fetchall(), 1)
        cursor.close()
        return gallery
    except mysql.connector.Error as e:
        logger.error('DB error loading wide GW gallery: %s', e)
        return []
    finally:
        if conn:
            conn.close()


def load_wide_staff_gallery(comp_id=GROUP_COMP_ID):
    """Enrolled staff of one company. Searched only after the GW gallery."""
    conn = None
    try:
        conn = get_db_connection()
        cursor = conn.cursor(dictionary=True)
        cursor.execute(
            "SELECT person, comp_id, mymk_id, encoding "
            "FROM staff_face_encodings "
            "WHERE deleted = '0' AND person IS NOT NULL AND person <> '' "
            "  AND comp_id = %s", (str(comp_id),))
        gallery = _rows_to_gallery(cursor.fetchall(), 0)
        cursor.close()
        return gallery
    except mysql.connector.Error as e:
        logger.error('DB error loading wide staff gallery: %s', e)
        return []
    finally:
        if conn:
            conn.close()


def assign_faces(face_items, gallery, tolerance, taken):
    """
    Match a set of faces against one gallery, at most one face per identity.

    face_items : list of (face_index, encoding) still looking for a name
    taken      : set of (person, comp_id, is_gw) already claimed by an EARLIER
                 stage; mutated here so a later stage cannot name the same
                 person twice. Without it the wide search happily re-finds
                 someone the pool search already placed.

    Scoring runs for every face before anything is assigned, because the same
    person is often the nearest neighbour of two different faces and only the
    full score table shows which of the two is the real one. The closer face
    keeps the name; the other is left for the next stage, or for "unknown".
    """
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


@app.route('/identify_group', methods=['POST'])
def identify_group():
    """
    1:N identification for EVERY face in one photo - the muster/attendance
    group shot a mandor uploads.

    /identify answers "who is this one face"; it rejects a second face on
    purpose, because a check-in must never be satisfied by whoever else walked
    into frame. This endpoint answers "who is in this crowd", so many faces are
    the point.

    Matching runs in three stages, narrowest first:
      1. the mandor's own pool (their GW + the staff with them)   tolerance 0.50
      2. every enrolled GW, company 1                             tolerance 0.45
      3. every enrolled staff member, company 1                   tolerance 0.45
    GW come before staff in the wide search because these photos are mostly GW.
    A face answered at an earlier stage never reaches a later one, and an
    identity claimed at any stage cannot be claimed again.

    Request JSON:
    {
        "image": "<base64 encoded image>",
        "pool": {
            "gw_codes": ["PPR-24", "PPR115"],              (optional)
            "staff":    [{"person": "andrewjohnny", "comp_id": "1"}]   (optional)
        },
        "search_outside": true     (optional, default true)
    }

    An empty pool is allowed and simply means every face goes to the wide
    search, which is the old whole-gallery behaviour.
    """
    data = request.get_json(silent=True) or {}

    if 'image' not in data:
        return jsonify({'success': False, 'error': 'Missing "image" field'}), 400

    image = decode_base64_image_group(data['image'])
    if image is None:
        return jsonify({'success': False, 'error': 'Invalid image data'}), 400

    img_h, img_w = image.shape[0], image.shape[1]

    # upsample=1 roughly doubles the image before scanning, which is what pulls
    # the back row of a group shot above HOG's minimum face size.
    face_locations = face_recognition.face_locations(image, number_of_times_to_upsample=1, model='hog')

    if len(face_locations) == 0:
        return jsonify({
            'success': True, 'faces_detected': 0, 'matched_count': 0,
            'unmatched_count': 0, 'results': [],
            'error': 'No face detected in this photo.'
        })

    # Biggest faces first, so a truncated crowd shot keeps the people actually
    # in frame rather than whichever specks HOG happened to list first.
    if len(face_locations) > GROUP_MAX_FACES:
        logger.warning('identify_group: %d faces detected, capping at %d',
                       len(face_locations), GROUP_MAX_FACES)
        face_locations = sorted(
            face_locations,
            key=lambda b: (b[2] - b[0]) * (b[1] - b[3]),
            reverse=True)[:GROUP_MAX_FACES]

    encodings = face_recognition.face_encodings(image, face_locations)
    if len(encodings) == 0:
        return jsonify({
            'success': True, 'faces_detected': len(face_locations), 'matched_count': 0,
            'unmatched_count': len(face_locations), 'results': [],
            'error': 'Faces were found but none could be read. Try a sharper photo.'
        })

    pool = data.get('pool') or {}
    gw_codes = [c for c in (pool.get('gw_codes') or []) if c]
    staff_keys = [s for s in (pool.get('staff') or []) if s and s.get('person')]
    search_outside = bool(data.get('search_outside', True))

    pending = list(enumerate(encodings))
    taken = set()
    found = {}        # face_index -> (gallery entry, distance)
    in_pool = set()   # face indexes answered by the pool stage

    # ---- Stage 1: the mandor's own pool ----
    pool_gallery = load_pool_gallery(gw_codes, staff_keys)
    if pool_gallery:
        hits = assign_faces(pending, pool_gallery, POOL_TOLERANCE, taken)
        found.update(hits)
        in_pool.update(hits.keys())
        pending = [item for item in pending if item[0] not in hits]

    # ---- Stage 2: every enrolled GW ----
    wide_gw = 0
    if pending and search_outside:
        gallery = load_wide_gw_gallery()
        wide_gw = len(gallery)
        hits = assign_faces(pending, gallery, OUTSIDE_TOLERANCE, taken)
        found.update(hits)
        pending = [item for item in pending if item[0] not in hits]

    # ---- Stage 3: every enrolled staff member of company 1 ----
    wide_staff = 0
    if pending and search_outside:
        gallery = load_wide_staff_gallery(GROUP_COMP_ID)
        wide_staff = len(gallery)
        hits = assign_faces(pending, gallery, OUTSIDE_TOLERANCE, taken)
        found.update(hits)
        pending = [item for item in pending if item[0] not in hits]

    results = []
    for face_index, box in enumerate(face_locations):
        if face_index >= len(encodings):
            break
        top, right, bottom, left = box
        entry = {
            'face_index': face_index,
            # Pixel coordinates in the PROCESSED image, sent with its size so a
            # caller can scale the box onto whatever copy it is displaying.
            'box': {'top': int(top), 'right': int(right),
                    'bottom': int(bottom), 'left': int(left)},
        }
        if face_index in found:
            best, distance = found[face_index]
            entry.update({
                'matched': True,
                'in_pool': face_index in in_pool,
                'person': best['person'],
                'comp_id': best['comp_id'],
                'is_gw': best['is_gw'],
                'mymk_id': str(best['mymk_id']) if best['mymk_id'] else None,
                'confidence': round(max(0.0, 1.0 - distance), 4),
                'distance': round(distance, 4),
            })
        else:
            entry.update({
                'matched': False, 'in_pool': False, 'person': None, 'comp_id': None,
                'is_gw': None, 'mymk_id': None, 'confidence': 0, 'distance': None,
            })
        results.append(entry)

    # Reading order (left to right) so the list lines up with the photo.
    results.sort(key=lambda r: r['box']['left'])

    matched_count = sum(1 for r in results if r['matched'])
    pool_count = sum(1 for r in results if r.get('in_pool'))

    logger.info('identify_group: faces=%d matched=%d (pool=%d outside=%d) '
                'pool_gallery=%d wide_gw=%d wide_staff=%d',
                len(results), matched_count, pool_count, matched_count - pool_count,
                len(pool_gallery), wide_gw, wide_staff)

    return jsonify({
        'success': True,
        'faces_detected': len(results),
        'matched_count': matched_count,
        'pool_matched_count': pool_count,
        'outside_matched_count': matched_count - pool_count,
        'unmatched_count': len(results) - matched_count,
        'pool_size': len(pool_gallery),
        'searched_outside': wide_gw + wide_staff,
        'pool_tolerance': POOL_TOLERANCE,
        'outside_tolerance': OUTSIDE_TOLERANCE,
        'image_width': int(img_w),
        'image_height': int(img_h),
        'results': results,
    })


@app.route('/enroll', methods=['POST'])
def enroll():
    """
    Enroll a new face for a staff member.

    Request JSON:
    {
        "image": "<base64 encoded image>",
        "person": "person_value",
        "comp_id": "123",
        "mymk_id": 456,
        "source_note": "front-facing, no glasses"  (optional)
    }
    """
    data = request.get_json()

    if not data or 'image' not in data:
        return jsonify({
            'success': False,
            'message': 'Missing required fields: image'
        }), 400

    # At least one identifier required
    person = data.get('person')
    comp_id = data.get('comp_id')
    mymk_id = data.get('mymk_id')

    if not person and not mymk_id:
        return jsonify({
            'success': False,
            'message': 'Missing required identifier: person+comp_id or mymk_id'
        }), 400

    source_note = data.get('source_note', None)

    # Decode image
    image = decode_base64_image(data['image'])
    if image is None:
        return jsonify({'success': False, 'message': 'Invalid image data'}), 400

    # Detect faces
    face_locations = face_recognition.face_locations(image, model='hog')

    if len(face_locations) == 0:
        return jsonify({
            'success': False,
            'message': 'No face detected. Please use a clear, well-lit photo with the face fully visible.'
        })

    if len(face_locations) > 1:
        return jsonify({
            'success': False,
            'message': 'Multiple faces detected. Please use a photo with only one face.'
        })

    # Compute encoding
    encodings = face_recognition.face_encodings(image, face_locations)
    if len(encodings) == 0:
        return jsonify({
            'success': False,
            'message': 'Could not compute face encoding. Please try a different photo.'
        })

    encoding = encodings[0]

    # Save to database
    encoding_id = save_encoding(person, comp_id, mymk_id, encoding, source_note)

    if encoding_id is None:
        return jsonify({
            'success': False,
            'message': 'Failed to save encoding to database.'
        }), 500

    logger.info('Enrolled face: person=%s, comp_id=%s, mymk_id=%s, encoding_id=%s',
                person, comp_id, mymk_id, encoding_id)

    return jsonify({
        'success': True,
        'encoding_id': encoding_id,
        'message': 'Face enrolled successfully'
    })


# Initialize DB pool for gunicorn (runs outside __main__)
try:
    init_db_pool()
    logger.info('DB pool initialized for gunicorn')
except Exception as e:
    logger.error('Failed to init DB pool: %s', e)
    

# ======================== MAIN ========================

if __name__ == '__main__':
    logger.info('Starting Face Recognition Service...')
    init_db_pool()
    logger.info('Service ready at http://%s:%s', HOST, PORT)
    app.run(host=HOST, port=PORT, debug=False)