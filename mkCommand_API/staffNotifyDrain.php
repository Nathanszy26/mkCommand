<?php
header('Content-Type: application/json; charset=UTF-8');

/**
 * The sending half of the memo / merit / demerit notifier.
 *
 * staffNotify.php writes queue rows while a record is being saved. This is
 * what turns them into pushes and emails, and it is reached three ways:
 *
 *   1. staff_notify_kick(), fired and forgotten by whoever just saved the
 *      record. This is the normal path -- it is why a memo to five people is
 *      delivered within a second or two of being written.
 *   2. a cron tick, which is the safety net. Nothing is lost if a kick never
 *      arrives (curl off, the app server down, PHP-FPM busy); the rows simply
 *      wait until the next tick:
 *
 *        * * * * * /usr/bin/php -q \
 *            /home/<account>/public_html/accounts/mkPortal/mkCommand/staffNotifyDrain.php
 *
 *   3. a browser, with ?key=..., when you want to watch it work.
 *
 * ignore_user_abort() is the point of the whole arrangement: the kick hangs
 * up after 400ms, and this keeps going.
 *
 * PHP 5.4+, same as staffNotify.php.
 */

ignore_user_abort(true);
set_time_limit(120);

require_once __DIR__ . '/staffNotify.php';

/**
 * Who may run it. A drain sends real email to real people, so an open URL
 * would be a way for anybody to make the server mail on their behalf --
 * even though they cannot choose the wording or the recipients.
 *
 * CLI needs no key: being able to run php on the box is already more
 * authority than this endpoint has.
 */
function staff_notify_drain_authorized()
{
    if (PHP_SAPI === 'cli') {
        return true;
    }

    $key = isset($_REQUEST['key']) ? (string)$_REQUEST['key'] : '';

    // hash_equals is PHP 5.6; this still has to run on 5.4, so the constant
    // -time comparison is spelt out rather than assumed.
    if (function_exists('hash_equals')) {
        return hash_equals(STAFF_NOTIFY_DRAIN_KEY, $key);
    }

    $expected = STAFF_NOTIFY_DRAIN_KEY;
    if (strlen($expected) !== strlen($key)) {
        return false;
    }

    $diff = 0;
    for ($i = 0; $i < strlen($expected); $i++) {
        $diff |= ord($expected[$i]) ^ ord($key[$i]);
    }

    return $diff === 0;
}

if (!staff_notify_drain_authorized()) {
    http_response_code(403);
    echo json_encode(array('success' => false, 'message' => 'Not authorized'));
    exit;
}

/**
 * How much to take on in one pass.
 *
 * The budget is what keeps this inside a normal request: the loop stops when
 * it runs out of seconds and asks for another drain, so a 720-recipient memo
 * is delivered over a handful of short passes rather than one long one that
 * the web server would kill halfway through.
 */
$limit  = isset($_REQUEST['limit'])  ? (int)$_REQUEST['limit']  : 300;
$budget = isset($_REQUEST['budget']) ? (int)$_REQUEST['budget'] : 45;

$stats = staff_notify_drain($limit, max(5, min(90, $budget)));

if (PHP_SAPI === 'cli') {
    echo 'staff_notify drain: ' . json_encode($stats) . PHP_EOL;
    exit;
}

echo json_encode(array('success' => true) + $stats);
