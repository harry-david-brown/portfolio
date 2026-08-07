<?php
/* api.php — back end for admin.html
 * Endpoints (all under ?action=):
 *   GET  me      -> {auth:bool}
 *   POST login   -> {auth:true}            body {password}
 *   POST logout  -> {auth:false}
 *   GET  works   -> {works:[...], rev:"sha1"}
 *   POST save    -> {rev:"sha1"}           body {rev, works}   409 if rev is stale
 *   POST upload  -> {src, master}          multipart: web, master(optional), name
 *
 * State-changing calls must send the header  X-Admin: 1
 * Browsers will not send a custom header cross-origin without a CORS preflight,
 * so this is the CSRF guard. Cheap, and it means no token plumbing.
 *
 * Set PASSWORD_HASH before deploying. Generate one with:
 *   php -r 'echo password_hash("your-password", PASSWORD_DEFAULT), "\n";'
 */

// ---------------------------------------------------------------- config
define('PASSWORD_HASH', '$2y$10$kcwezy7MOKCBbvRNXOvR0OQfF2HdWhTsrePjp5/gDG2BO7M/wBhY2');
define('WORKS_FILE', __DIR__ . '/works.json');
define('IMAGE_DIR',  __DIR__ . '/images');
define('MASTER_DIR', __DIR__ . '/images/masters');
define('MAX_UPLOAD', 40 * 1024 * 1024);   // per file

// ---------------------------------------------------------------- boot
if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
} else {
    session_set_cookie_params(0, '/', '', false, true);
}
session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function body_json() {
    $raw = file_get_contents('php://input');
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}
function require_auth() {
    if (empty($_SESSION['auth'])) out(['error' => 'Sign in to continue.'], 401);
}
function require_header() {
    $h = isset($_SERVER['HTTP_X_ADMIN']) ? $_SERVER['HTTP_X_ADMIN'] : '';
    if ($h !== '1') out(['error' => 'Missing X-Admin header.'], 400);
}
function rev_of($path) {
    return file_exists($path) ? sha1_file($path) : 'empty';
}

$action = isset($_GET['action']) ? $_GET['action'] : '';
$post   = $_SERVER['REQUEST_METHOD'] === 'POST';

// ---------------------------------------------------------------- auth
if ($action === 'me') {
    out(['auth' => !empty($_SESSION['auth'])]);
}

if ($action === 'login' && $post) {
    require_header();
    $in = body_json();
    $pw = isset($in['password']) ? (string)$in['password'] : '';
    if (!password_verify($pw, PASSWORD_HASH)) {
        usleep(400000);                       // blunt the brute-force edge
        out(['error' => 'That password did not match.'], 401);
    }
    session_regenerate_id(true);
    $_SESSION['auth'] = true;
    out(['auth' => true]);
}

if ($action === 'logout' && $post) {
    require_header();
    $_SESSION = [];
    session_destroy();
    out(['auth' => false]);
}

// ---------------------------------------------------------------- read
if ($action === 'works') {
    $works = [];
    if (file_exists(WORKS_FILE)) {
        $decoded = json_decode(file_get_contents(WORKS_FILE), true);
        if (is_array($decoded)) $works = $decoded;
    }
    out(['works' => $works, 'rev' => rev_of(WORKS_FILE)]);
}

// ---------------------------------------------------------------- write
if ($action === 'save' && $post) {
    require_header();
    require_auth();
    $in = body_json();

    if (!isset($in['works']) || !is_array($in['works'])) {
        out(['error' => 'No works array in the request.'], 400);
    }
    $sent = isset($in['rev']) ? (string)$in['rev'] : '';
    $have = rev_of(WORKS_FILE);
    if ($sent !== $have) {
        out([
            'error' => 'works.json changed since this page loaded. Reload to pick up the newer version.',
            'rev'   => $have
        ], 409);
    }

    // whitelist fields — nothing the admin page does not own gets written
    $clean = [];
    foreach ($in['works'] as $w) {
        if (!is_array($w)) continue;
        $title = isset($w['title']) ? trim((string)$w['title']) : '';
        $src   = isset($w['src'])   ? trim((string)$w['src'])   : '';
        if ($title === '' && $src === '') continue;         // skip blank rows
        $status = isset($w['status']) ? (string)$w['status'] : 'available';
        if (!in_array($status, ['available', 'sold', 'nfs'], true)) $status = 'available';
        $price = (isset($w['price']) && $w['price'] !== null && $w['price'] !== '')
            ? max(0, (int)$w['price'])                      // integer cents
            : null;
        // placeholder aspect ratio [w,h] — only kept while src is empty; two positive numbers or fall back
        $aspect = [4, 5];
        if (isset($w['aspect']) && is_array($w['aspect']) && count($w['aspect']) === 2) {
            $aw = (float)$w['aspect'][0];
            $ah = (float)$w['aspect'][1];
            if ($aw > 0 && $ah > 0) $aspect = [$aw, $ah];
        }
        $clean[] = [
            'title'       => $title,
            'year'        => isset($w['year']) && $w['year'] !== '' ? (int)$w['year'] : '',
            'medium'      => isset($w['medium']) ? trim((string)$w['medium']) : '',
            'dims'        => isset($w['dims']) ? trim((string)$w['dims']) : '',
            'src'         => $src,
            'master'      => isset($w['master']) ? trim((string)$w['master']) : '',
            'aspect'      => $aspect,
            'price'       => $price,
            'status'      => $status,
            'stripe_link' => isset($w['stripe_link']) ? trim((string)$w['stripe_link']) : '',
            'drop'        => !empty($w['drop']) ? trim((string)$w['drop']) : null,
        ];
    }

    $json = json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) out(['error' => 'Could not encode that data as JSON.'], 500);

    // atomic: write a sibling temp file, then rename over the target
    $tmp = WORKS_FILE . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        out(['error' => 'Could not write to the site directory. Check folder permissions.'], 500);
    }
    if (!rename($tmp, WORKS_FILE)) {
        @unlink($tmp);
        out(['error' => 'Could not replace works.json.'], 500);
    }
    @chmod(WORKS_FILE, 0644);
    clearstatcache(true, WORKS_FILE);

    out(['rev' => rev_of(WORKS_FILE), 'count' => count($clean)]);
}

// ---------------------------------------------------------------- upload
if ($action === 'upload' && $post) {
    require_header();
    require_auth();

    if (!isset($_FILES['web'])) out(['error' => 'No image was received.'], 400);
    foreach ([IMAGE_DIR, MASTER_DIR] as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            out(['error' => 'Could not create the images folder.'], 500);
        }
    }

    // slug from the original filename, plus a short suffix so re-uploads never collide
    $raw  = isset($_POST['name']) ? (string)$_POST['name'] : $_FILES['web']['name'];
    $stem = strtolower(pathinfo($raw, PATHINFO_FILENAME));
    $stem = preg_replace('/[^a-z0-9]+/', '-', $stem);
    $stem = trim($stem, '-');
    if ($stem === '') $stem = 'work';
    $stem = substr($stem, 0, 48) . '-' . bin2hex(random_bytes(3));

    $store = function ($key, $dir, $name) {
        if (!isset($_FILES[$key]) || $_FILES[$key]['error'] !== UPLOAD_ERR_OK) return '';
        if ($_FILES[$key]['size'] > MAX_UPLOAD) out(['error' => 'That file is over the size limit.'], 413);
        $info = @getimagesize($_FILES[$key]['tmp_name']);
        if ($info === false) out(['error' => 'That file is not a readable image.'], 415);
        $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
        if (!isset($ext[$info[2]])) out(['error' => 'Use a JPEG, PNG or WebP.'], 415);
        $file = $name . '.' . $ext[$info[2]];
        if (!move_uploaded_file($_FILES[$key]['tmp_name'], $dir . '/' . $file)) {
            out(['error' => 'Could not save the uploaded file.'], 500);
        }
        @chmod($dir . '/' . $file, 0644);
        return $file;
    };

    $web    = $store('web',    IMAGE_DIR,  $stem);
    $master = $store('master', MASTER_DIR, $stem . '-master');

    out([
        'src'    => 'images/' . $web,
        'master' => $master ? 'images/masters/' . $master : ''
    ]);
}

out(['error' => 'Unknown action.'], 404);
