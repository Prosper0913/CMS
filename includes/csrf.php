<?php
// ============================================================
//  includes/csrf.php  —  CSRF protection (session-based token)
//
//  Protection is CENTRAL: includes/auth.php calls csrf_enforce() so every
//  logged-in page (teacher / student / admin) rejects any POST that does not
//  carry the session's token. The token is added to forms and fetch() calls
//  automatically by a tiny script (csrf_inject_script), so individual pages
//  do not each have to remember it.
//
//  Manual use (still supported):
//    In a form:      echo csrf_field();
//    In a handler:   if (!csrf_verify()) { ...reject... }
//    Delete links:   append  "&" . csrf_query()  to the link's URL (central check
//                    in csrf_enforce() verifies it for the delete* parameters).
// ============================================================

function csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="'
         . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

/** "csrf_token=abc..." for appending to a state-changing link. */
function csrf_query(): string {
    return 'csrf_token=' . rawurlencode(csrf_token());
}

function _csrf_sent_token(): string {
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($sent) ? $sent : '';
}

function csrf_verify(): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $sent = _csrf_sent_token();
    return $sent !== '' && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $sent);
}

/** For state-changing GET links (delete buttons). */
function csrf_verify_get(): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $sent = $_GET['csrf_token'] ?? '';
    return is_string($sent) && $sent !== '' && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $sent);
}

function _csrf_wants_json(): bool {
    $uri    = $_SERVER['REQUEST_URI'] ?? '';
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $ctype  = $_SERVER['CONTENT_TYPE'] ?? '';
    return strpos($uri, '/api/') !== false
        || stripos($accept, 'application/json') !== false
        || stripos($ctype, 'application/json') !== false
        || isset($_SERVER['HTTP_X_CSRF_TOKEN']);
}

/** Stop the request with a 403 (JSON for fetch/API callers, a small page otherwise). */
function csrf_reject(): void {
    http_response_code(403);
    if (_csrf_wants_json()) {
        header('Content-Type: application/json');
        $m = 'Your session expired. Please reload the page and try again.';
        echo json_encode(['success' => false, 'status' => 'error', 'error' => $m, 'message' => $m]);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<title>Session expired</title>'
           . '<body style="font-family:system-ui,sans-serif;max-width:460px;margin:12vh auto;padding:0 16px;text-align:center">'
           . '<h2>Your session expired</h2>'
           . '<p>For your security this action was not completed. Go back, reload the page, and try again.</p>'
           . '<p><a href="javascript:history.back()">&larr; Go back</a></p></body>';
    }
    exit;
}

/** Reject any POST that lacks the session's CSRF token. Safe methods pass. */
function csrf_enforce(): void {
    if (!headers_sent()) {
        // Keep the (session) token out of cross-site Referer headers.
        header('Referrer-Policy: same-origin');
    }
    $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($m === 'GET') {
        // The few delete links that are plain GET requests must carry the token too.
        foreach (['delete', 'del_score', 'del_announcement', 'del_attendance'] as $k) {
            if (isset($_GET[$k]) && !csrf_verify_get()) csrf_reject();
        }
        return;
    }
    if ($m === 'HEAD' || $m === 'OPTIONS') return;
    if (!csrf_verify()) csrf_reject();
}

/**
 * Output-buffer callback: on full HTML pages, put the token in a <meta> tag
 * and add a small script that (a) adds the token to every POST form when it
 * is submitted and (b) sends it as X-CSRF-Token on same-origin fetch() calls.
 * JSON / CSV / fragment responses are left untouched.
 */
function csrf_inject_buffer(string $html): string {
    foreach (headers_list() as $h) {
        if (stripos($h, 'content-type:') === 0 && stripos($h, 'text/html') === false) return $html;
    }
    $pos = stripos($html, '</head>');
    if ($pos === false) return $html;
    $t = htmlspecialchars(csrf_token(), ENT_QUOTES);
    $js = <<<JS
<meta name="csrf-token" content="{$t}">
<script>
(function(){
  var T = document.querySelector('meta[name="csrf-token"]').content;
  document.addEventListener('submit', function(e){
    var f = e.target;
    if (f && f.tagName === 'FORM' && (f.method || '').toLowerCase() === 'post' && !f.querySelector('input[name="csrf_token"]')) {
      var i = document.createElement('input');
      i.type = 'hidden'; i.name = 'csrf_token'; i.value = T;
      f.appendChild(i);
    }
  }, true);
  var of = window.fetch;
  if (of) window.fetch = function(u, o){
    try {
      o = o || {};
      var m = String(o.method || 'GET').toUpperCase();
      var s = (typeof u === 'string') ? u : '';
      var same = s !== '' && (new URL(s, location.href)).origin === location.origin;
      if (same && m !== 'GET' && m !== 'HEAD') {
        if (typeof Headers !== 'undefined' && o.headers instanceof Headers) o.headers.set('X-CSRF-Token', T);
        else o.headers = Object.assign({}, o.headers || {}, {'X-CSRF-Token': T});
      }
    } catch (err) {}
    return of.call(this, u, o);
  };
})();
</script>
JS;
    return substr($html, 0, $pos) . $js . substr($html, $pos);
}
