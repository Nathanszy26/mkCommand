#!/usr/bin/env python3
"""
GW Face Recognition Service for Daily Activities App
Runs as a systemd service at http://127.0.0.1:5001

Endpoints:
  GET  /health     - Health check
  POST /recognize  - Match a face against a specific GW's enrolled encodings
  POST /enroll     - Register a new face encoding + photo for a GW
  GET  /list       - List enrollments for a GW (query: ?gw_id=<int>)
  POST /delete     - Soft-delete an enrollment by id

Deployment:
  Path : /var/www/html/glob/it/mkPortal/dailyActivitiesApp/face_services/face_service.py
  Port : 5001  (staff service owns 5000 — must stay separate)
  DBs  : mkPortal.gw_face_encodings + evaluation.monthly_assign_gw (cross-DB reads)
"""

import os
import json
import base64
import logging
import secrets
import time
from io import BytesIO
from datetime import datetime

import numpy as np
import face_recognition
from flask import Flask, request, jsonify
from PIL import Image, ImageOps
import mysql.connector
from mysql.connector import pooling

# ======================== CONFIG ========================

# Primary DB = mkPortal (where gw_face_encodings lives).
# monthly_assign_gw is referenced via fully-qualified `evaluation.monthly_assign_gw`.
# The `prog3` user must have SELECT access to evaluation.monthly_assign_gw.
DB_CONFIG = {
    'host': 'globportal.com',
    'port': 33060,
    'user': 'prog3',
    'password': 'prog3@2023',
    'database': 'mkPortal',
    'connection_timeout': 5,
}

# Lower = stricter. 0.6 is the face_recognition library default.
MATCH_TOLERANCE = 0.6

HOST = '127.0.0.1'
PORT = 5001

SERVICE_DIR = '/var/www/html/glob/it/mkPortal/dailyActivitiesApp/face_services'
ENROLLED_PHOTOS_DIR = os.path.join(SERVICE_DIR, 'enrolled_photos')

# ======================== SETUP ========================

app = Flask(__name__)

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(message)s',
    handlers=[logging.FileHandler(os.path.join(SERVICE_DIR, 'face_service.log'))],
)
logger = logging.getLogger(__name__)

db_pool = None


def init_db_pool():
    global db_pool
    db_pool = pooling.MySQLConnectionPool(
        pool_name='gw_face_service_pool',
        pool_size=5,
        pool_reset_session=True,
        **DB_CONFIG,
    )
    logger.info('DB pool created (%s:%s/%s)', DB_CONFIG['host'], DB_CONFIG['port'], DB_CONFIG['database'])


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

def decode_base64_image(b64):
    """Decode a base64 string into a numpy RGB array. Returns None on failure."""
    try:
        if ',' in b64:
            b64 = b64.split(',', 1)[1]
        pil = Image.open(BytesIO(base64.b64decode(b64)))
        pil = ImageOps.exif_transpose(pil)

        MAX_DIM = 480
        if max(pil.size) > MAX_DIM:
            pil.thumbnail((MAX_DIM, MAX_DIM), Image.LANCZOS)

        if pil.mode != 'RGB':
            pil = pil.convert('RGB')
        return np.array(pil)
    except Exception as e:
        logger.error('Image decode failed: %s', e)
        return None


def raw_bytes_from_base64(b64):
    """Return raw bytes from a base64 string (with or without data URI prefix)."""
    if ',' in b64:
        b64 = b64.split(',', 1)[1]
    return base64.b64decode(b64)


def detect_and_encode(image_array):
    """
    Detect exactly one face in the image and return its 128-d encoding.
    Returns (encoding, None) on success, or (None, error_message) on failure.
    """
    face_locations = face_recognition.face_locations(image_array, model='hog')
    if len(face_locations) == 0:
        return None, 'No face detected. Please use a clear, well-lit photo with the face fully visible.'
    if len(face_locations) > 1:
        return None, 'Multiple faces detected. Please use a photo with only one face.'

    encodings = face_recognition.face_encodings(image_array, face_locations)
    if len(encodings) == 0:
        return None, 'Could not compute face encoding. Please try a different photo.'

    return encodings[0], None


def save_enrollment_photo(b64, gw_id):
    """
    Save raw photo bytes to disk under enrolled_photos/<gw_id>/<YYYY>/<MM>/<DD>/.
    Returns (relative_path, filename, file_size).
    """
    data = raw_bytes_from_base64(b64)

    date_folder = datetime.now().strftime('%Y/%m/%d')
    target_dir = os.path.join(ENROLLED_PHOTOS_DIR, str(gw_id), date_folder)
    os.makedirs(target_dir, exist_ok=True)

    filename = 'gw_{}_{}_{}.jpg'.format(gw_id, int(time.time()), secrets.token_hex(3))
    filepath = os.path.join(target_dir, filename)

    with open(filepath, 'wb') as f:
        f.write(data)

    rel_path = os.path.join('enrolled_photos', str(gw_id), date_folder, filename)
    return rel_path, filename, len(data)


def lookup_gw(gw_id):
    """Return GW row dict from evaluation.monthly_assign_gw, or None."""
    conn = None
    try:
        conn = get_db_connection()
        cur = conn.cursor(dictionary=True)
        cur.execute(
            "SELECT monthly_assign_gw_id, monthly_assign_gw_fullname, monthly_assign_gw_code, "
            "       mymk_daily_check_in_default, mymk_daily_check_in_face "
            "FROM evaluation.monthly_assign_gw WHERE monthly_assign_gw_id = %s LIMIT 1",
            (int(gw_id),),
        )
        return cur.fetchone()
    except mysql.connector.Error as e:
        logger.error('DB error on lookup_gw(%s): %s', gw_id, e)
        return None
    finally:
        if conn:
            conn.close()


def insert_encoding(gw_id, gw_code, encoding_array, photo_path, photo_name):
    """Insert an encoding row into mkPortal.gw_face_encodings. Returns new id or None."""
    conn = None
    try:
        conn = get_db_connection()
        cur = conn.cursor()
        cur.execute(
            "INSERT INTO gw_face_encodings "
            "(monthly_assign_gw_id, monthly_assign_gw_code, encoding, photo_path, photo_name, created_at, deleted) "
            "VALUES (%s, %s, %s, %s, %s, NOW(), '0')",
            (int(gw_id), gw_code, json.dumps(encoding_array.tolist()), photo_path, photo_name),
        )
        conn.commit()
        return cur.lastrowid
    except mysql.connector.Error as e:
        logger.error('DB error on insert_encoding: %s', e)
        return None
    finally:
        if conn:
            conn.close()


def load_encodings_by_code(gw_code):
    """Load all non-deleted encodings for every assignment row sharing this worker code."""
    conn = None
    try:
        conn = get_db_connection()
        cur = conn.cursor(dictionary=True)
        cur.execute(
            "SELECT id, encoding FROM gw_face_encodings "
            "WHERE monthly_assign_gw_code = %s AND deleted = '0'",
            (gw_code,),
        )
        rows = cur.fetchall()
        out = []
        for row in rows:
            try:
                arr = np.array(json.loads(row['encoding']), dtype=np.float64)
                out.append({'id': row['id'], 'encoding': arr})
            except (json.JSONDecodeError, ValueError) as e:
                logger.warning('Bad encoding for id=%s: %s', row['id'], e)
        return out
    except mysql.connector.Error as e:
        logger.error('DB error load_encodings_by_code: %s', e)
        return []
    finally:
        if conn:
            conn.close()


def gw_manager_codes(user_id, comp_id):
    """
    GW-group scope for a scanning user, mirroring getGwSalaryAPI.php's gwManagerScope.

    Returns:
      None  -> user is NOT a group manager (no restriction; whole-company gallery).
      set() -> manager, but no codes for this company (must match nothing).
      set([...]) -> the gw_codes this manager may identify within this company.
    """
    conn = None
    try:
        conn = get_db_connection()
        cur = conn.cursor(dictionary=True)

        # 1) Resolve the scanning user's own (person, comp_id) login identity.
        cur.execute(
            "SELECT person, comp_id FROM staff_portal2.users WHERE id = %s LIMIT 1",
            (int(user_id),),
        )
        u = cur.fetchone()
        if not u:
            return None
        person, user_comp = u['person'], int(u['comp_id'])

        # 2) Is this user flagged as a GW-group manager?
        cur.execute(
            "SELECT 1 FROM mkPortal.face_checkIn_admins "
            "WHERE person = %s AND comp_id = %s AND status = '1' AND group_status = '1' LIMIT 1",
            (person, user_comp),
        )
        if not cur.fetchone():
            return None  # not a manager -> unrestricted

        # 3) The GW codes in this user's group(s) that belong to the scanned company.
        cur.execute(
            "SELECT m.gw_code "
            "FROM mkPortal.gw_group_managers gm "
            "JOIN mkPortal.gw_groups g        ON g.id = gm.group_id AND g.status = '1' "
            "JOIN mkPortal.gw_group_members m ON m.group_id = g.id "
            "WHERE gm.person = %s AND gm.comp_id = %s AND m.comp_id = %s",
            (person, user_comp, int(comp_id)),
        )
        return {row['gw_code'] for row in cur.fetchall()}
    except mysql.connector.Error as e:
        # Fail CLOSED for managers we can't resolve: returning an empty set means
        # "match nothing", which is the safe posture for a scoped identify call.
        logger.error('DB error gw_manager_codes(user=%s comp=%s): %s', user_id, comp_id, e)
        return set()
    finally:
        if conn:
            conn.close()


def load_company_encodings(comp_id, allowed_codes=None):
    """
    Load every non-deleted encoding for a company's active GW roster, each tagged
    with the worker's code, representative assignment id, and fullname.
    Used for 1:N identification (the salary 'scan' flow).

    allowed_codes:
      None       -> no restriction (whole-company gallery).
      empty set  -> restrict to nothing (returns []).
      non-empty  -> restrict the gallery to these gw_codes only.
    """
    if allowed_codes is not None and len(allowed_codes) == 0:
        return []  # scoped manager with nothing in this company -> no candidates

    conn = None
    try:
        conn = get_db_connection()
        cur = conn.cursor(dictionary=True)

        params = [str(comp_id)]
        code_filter = ""
        if allowed_codes:
            placeholders = ','.join(['%s'] * len(allowed_codes))
            code_filter = " AND e.monthly_assign_gw_code IN ({})".format(placeholders)
            params.extend(allowed_codes)

        cur.execute(
            "SELECT e.id AS enc_id, e.monthly_assign_gw_code AS code, e.encoding, "
            "       g.gw_id, g.fullname "
            "FROM gw_face_encodings e "
            "JOIN ( "
            "   SELECT monthly_assign_gw_code AS code, "
            "          MIN(monthly_assign_gw_id) AS gw_id, "
            "          MAX(monthly_assign_gw_fullname) AS fullname "
            "   FROM evaluation.monthly_assign_gw "
            "   WHERE comp_id = %s AND monthly_assign_gw_status = '1' "
            "   GROUP BY monthly_assign_gw_code "
            ") g ON g.code = e.monthly_assign_gw_code "
            "WHERE e.deleted = '0'" + code_filter,
            tuple(params),
        )
        rows = cur.fetchall()
        out = []
        for row in rows:
            try:
                arr = np.array(json.loads(row['encoding']), dtype=np.float64)
                out.append({
                    'enc_id': row['enc_id'],
                    'code': row['code'],
                    'gw_id': row['gw_id'],
                    'fullname': row['fullname'],
                    'encoding': arr,
                })
            except (json.JSONDecodeError, ValueError) as e:
                logger.warning('Bad encoding for enc_id=%s: %s', row['enc_id'], e)
        return out
    except mysql.connector.Error as e:
        logger.error('DB error load_company_encodings: %s', e)
        return []
    finally:
        if conn:
            conn.close()


# ======================== ENDPOINTS ========================

@app.route('/health', methods=['GET'])
def health():
    db_ok = False
    try:
        conn = get_db_connection()
        cur = conn.cursor()
        cur.execute("SELECT 1")
        cur.fetchone()
        cur.close()
        conn.close()
        db_ok = True
    except Exception as e:
        logger.error('Health DB test failed: %s', e)

    return jsonify({
        'status': 'ok',
        'service': 'gw-face-recognition',
        'database': 'connected' if db_ok else 'disconnected',
        'timestamp': datetime.now().isoformat(),
    })


@app.route('/recognize', methods=['POST'])
def recognize():
    data = request.get_json(silent=True) or {}
    if 'image' not in data or 'gw_id' not in data:
        return jsonify({'matched': False, 'error': 'Missing required fields: gw_id, image'}), 400

    try:
        gw_id = int(data['gw_id'])
    except (TypeError, ValueError):
        return jsonify({'matched': False, 'error': 'Invalid gw_id'}), 400

    image = decode_base64_image(data['image'])
    if image is None:
        return jsonify({'matched': False, 'error': 'Invalid image data'}), 400

    submitted, err = detect_and_encode(image)
    if err:
        logger.warning('recognize: %s (gw_id=%s)', err, gw_id)
        return jsonify({'matched': False, 'confidence': 0, 'error': err})

    gw = lookup_gw(gw_id)
    if not gw:
        return jsonify({'matched': False, 'error': 'GW ID {} not found'.format(gw_id)}), 404

    enrolled = load_encodings_by_code(gw['monthly_assign_gw_code'])
    if len(enrolled) == 0:
        return jsonify({
            'matched': False, 'confidence': 0,
            'error': 'No face enrolled for this GW. Please enroll a face first.',
        })

    distances = face_recognition.face_distance([e['encoding'] for e in enrolled], submitted)
    best_idx = int(np.argmin(distances))
    best_dist = float(distances[best_idx])
    confidence = max(0.0, 1.0 - best_dist)
    matched = best_dist <= MATCH_TOLERANCE

    log_fn = logger.info if matched else logger.warning
    log_fn('gw_id=%s matched=%s confidence=%.4f distance=%.4f encoding_id=%s',
           gw_id, matched, confidence, best_dist, enrolled[best_idx]['id'])

    return jsonify({
        'matched': matched,
        'confidence': round(confidence, 4),
        'distance': round(best_dist, 4),
    })


@app.route('/identify', methods=['POST'])
def identify():
    """
    1:N identification: given a company and a probe image, return the best-matching
    GW across that company's enrolled faces. Additive — does not affect /recognize.
    """
    data = request.get_json(silent=True) or {}
    if 'image' not in data or 'comp_id' not in data:
        return jsonify({'matched': False, 'error': 'Missing required fields: comp_id, image'}), 400

    comp_id = str(data['comp_id']).strip()
    if comp_id == '':
        return jsonify({'matched': False, 'error': 'Invalid comp_id'}), 400

    image = decode_base64_image(data['image'])
    if image is None:
        return jsonify({'matched': False, 'error': 'Invalid image data'}), 400

    submitted, err = detect_and_encode(image)
    if err:
        logger.warning('identify: %s (comp_id=%s)', err, comp_id)
        return jsonify({'matched': False, 'confidence': 0, 'error': err})

    allowed_codes = None
    user_id = data.get('user_id')
    if user_id not in (None, '', 0, '0'):
        try:
            allowed_codes = gw_manager_codes(int(user_id), comp_id)
        except (TypeError, ValueError):
            allowed_codes = None  # bad user_id -> unrestricted; claim API still guards

    candidates = load_company_encodings(comp_id, allowed_codes)
    if len(candidates) == 0:
        # Distinguish "scoped to an empty group" from "company has no enrollments".
        msg = ('No workers in your assigned group have an enrolled face.'
               if allowed_codes is not None
               else 'No enrolled faces for this company.')
        return jsonify({'matched': False, 'confidence': 0, 'error': msg})

    distances = face_recognition.face_distance([c['encoding'] for c in candidates], submitted)
    best_idx = int(np.argmin(distances))
    best_dist = float(distances[best_idx])
    confidence = max(0.0, 1.0 - best_dist)
    matched = best_dist <= MATCH_TOLERANCE
    best = candidates[best_idx]

    log_fn = logger.info if matched else logger.warning
    log_fn('identify comp_id=%s matched=%s confidence=%.4f distance=%.4f code=%s',
           comp_id, matched, confidence, best_dist, best['code'])

    if not matched:
        return jsonify({
            'matched': False,
            'confidence': round(confidence, 4),
            'distance': round(best_dist, 4),
            'error': 'No matching worker found. Try again or use manual capture.',
        })

    return jsonify({
        'matched': True,
        'confidence': round(confidence, 4),
        'distance': round(best_dist, 4),
        'gw_id': best['gw_id'],
        'code': best['code'],
        'fullname': best['fullname'],
    })


@app.route('/enroll', methods=['POST'])
def enroll():
    data = request.get_json(silent=True) or {}
    if 'image' not in data or 'gw_id' not in data:
        return jsonify({'success': False, 'error': 'Missing required fields: gw_id, image'}), 400

    try:
        gw_id = int(data['gw_id'])
    except (TypeError, ValueError):
        return jsonify({'success': False, 'error': 'Invalid gw_id'}), 400

    gw = lookup_gw(gw_id)
    if not gw:
        return jsonify({'success': False, 'error': 'GW ID {} not found'.format(gw_id)}), 404

    image = decode_base64_image(data['image'])
    if image is None:
        return jsonify({'success': False, 'error': 'Invalid image data'}), 400

    encoding, err = detect_and_encode(image)
    if err:
        return jsonify({'success': False, 'error': err})

    try:
        rel_path, filename, size = save_enrollment_photo(data['image'], gw_id)
    except Exception as e:
        logger.error('Failed to save photo: %s', e)
        return jsonify({'success': False, 'error': 'Failed to save photo'}), 500

    enc_id = insert_encoding(gw_id, gw['monthly_assign_gw_code'], encoding, rel_path, filename)
    if not enc_id:
        return jsonify({'success': False, 'error': 'Failed to save encoding'}), 500

    logger.info('Enrolled gw_id=%s fullname=%s encoding_id=%s file=%s',
                gw_id, gw['monthly_assign_gw_fullname'], enc_id, filename)

    return jsonify({
        'success': True,
        'encoding_id': enc_id,
        'gw_id': gw_id,
        'gw_fullname': gw['monthly_assign_gw_fullname'],
        'photo_path': rel_path,
        'photo_name': filename,
        'file_size': size,
    })


@app.route('/list', methods=['GET'])
def list_enrollments():
    gw_id = request.args.get('gw_id')
    if not gw_id:
        return jsonify({'success': False, 'error': 'Missing gw_id'}), 400

    try:
        gw_id_int = int(gw_id)
    except ValueError:
        return jsonify({'success': False, 'error': 'Invalid gw_id'}), 400

    gw = lookup_gw(gw_id_int)
    if not gw:
        return jsonify({'success': False, 'error': 'GW ID {} not found'.format(gw_id_int)}), 404

    conn = None
    try:
        conn = get_db_connection()
        cur = conn.cursor(dictionary=True)
        cur.execute(
            "SELECT id, monthly_assign_gw_id, photo_path, photo_name, created_at "
            "FROM gw_face_encodings "
            "WHERE monthly_assign_gw_id = %s AND deleted = '0' "
            "ORDER BY created_at DESC",
            (gw_id_int,),
        )
        rows = cur.fetchall()
        for row in rows:
            if row.get('created_at') and hasattr(row['created_at'], 'strftime'):
                row['created_at'] = row['created_at'].strftime('%Y-%m-%d %H:%M:%S')

        return jsonify({
            'success': True,
            'gw_id': gw_id_int,
            'gw_fullname': gw['monthly_assign_gw_fullname'],
            'enrollments': rows,
        })
    except mysql.connector.Error as e:
        logger.error('DB error on list: %s', e)
        return jsonify({'success': False, 'error': 'Database error'}), 500
    finally:
        if conn:
            conn.close()


@app.route('/delete', methods=['POST'])
def delete_enrollment():
    data = request.get_json(silent=True) or {}
    enc_id = data.get('id')
    if not enc_id:
        return jsonify({'success': False, 'error': 'Missing id'}), 400

    try:
        enc_id_int = int(enc_id)
    except ValueError:
        return jsonify({'success': False, 'error': 'Invalid id'}), 400

    conn = None
    try:
        conn = get_db_connection()
        cur = conn.cursor()
        cur.execute("UPDATE gw_face_encodings SET deleted = '1' WHERE id = %s", (enc_id_int,))
        conn.commit()
        deleted = cur.rowcount > 0
        logger.info('Soft-deleted encoding id=%s rows=%s', enc_id_int, cur.rowcount)
        return jsonify({'success': deleted})
    except mysql.connector.Error as e:
        logger.error('DB error on delete: %s', e)
        return jsonify({'success': False, 'error': 'Database error'}), 500
    finally:
        if conn:
            conn.close()


# Initialize DB pool at import time for gunicorn workers
try:
    init_db_pool()
except Exception as e:
    logger.error('Failed to init DB pool at import: %s', e)


if __name__ == '__main__':
    logger.info('Starting GW Face Recognition Service on %s:%s', HOST, PORT)
    if db_pool is None:
        init_db_pool()
    app.run(host=HOST, port=PORT, debug=False)