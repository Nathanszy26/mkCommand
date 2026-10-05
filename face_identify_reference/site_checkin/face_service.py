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