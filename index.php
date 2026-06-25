<?php

require_once '../app.qlynk.mx/core/config.php';
require_once '../app.qlynk.mx/core/db.php';

$code = strtoupper(
    trim(
        parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
        '/'
    )
);

if (empty($code)) {
    serve404();
    exit;
}

// 1. Buscar QR
$stmt = $pdo->prepare("
    SELECT *
    FROM qr_codes
    WHERE short_code = ?
    AND active = 1
    AND deleted_at IS NULL
    LIMIT 1
");
$stmt->execute([$code]);
$qr = $stmt->fetch();

if (!$qr) {
    serve404();
    exit;
}

// 2. Verificar limite de escaneos del plan
$stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(q.scan_count), 0) AS total_scans,
        p.scans_limit
    FROM users u
    INNER JOIN plans p ON p.id = u.plan_id
    LEFT JOIN qr_codes q
        ON q.user_id = u.id
        AND q.deleted_at IS NULL
    WHERE u.id = ?
    GROUP BY p.scans_limit
");
$stmt->execute([$qr['user_id']]);
$usage = $stmt->fetch();

if ($usage && (int)$usage['scans_limit'] > 0 && (int)$usage['scans_limit'] < 99999999
    && (int)$usage['total_scans'] >= (int)$usage['scans_limit']) {
    serveMessage('Limite de escaneos', 'Este QR ha alcanzado el limite de escaneos del plan. Contacta al propietario.', 403);
    exit;
}

// 3. Verificar expiracion
if (!empty($qr['expires_at']) && strtotime($qr['expires_at']) < time()) {
    serveMessage('QR Expirado', 'Este codigo QR ha expirado y ya no esta disponible.', 410);
    exit;
}

// 4. Registrar escaneo
$pdo->prepare("UPDATE qr_codes SET scan_count = scan_count + 1 WHERE id = ?")->execute([$qr['id']]);

// Intentar insertar en qr_scans (puede no existir aun)
try {
    $ip      = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua      = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300);
    $referer = substr($_SERVER['HTTP_REFERER']    ?? '', 0, 300);
    $pdo->prepare("
        INSERT INTO qr_scans (qr_id, ip_address, user_agent, referer, scanned_at)
        VALUES (?, ?, ?, ?, NOW())
    ")->execute([$qr['id'], $ip, $ua, $referer]);
} catch (Exception $e) {
    // qr_scans no existe aun -- ignorar
}

// 5. Redirigir / mostrar segun tipo de contenido
$url  = trim($qr['target_url']);
$name = trim($qr['name'] ?? 'QR');

if (empty($url)) {
    serveMessage('Sin destino', 'Este QR no tiene un destino configurado.', 500);
    exit;
}

// Determinar como manejar por protocolo
if (preg_match('#^https?://#i', $url)) {
    header("Location: {$url}", true, 301);
    exit;

} elseif (preg_match('#^mailto:#i', $url)) {
    serveJsRedirect($url, $name, '&#128231;', 'Tu app de correo deberia abrirse automaticamente.');
    exit;

} elseif (preg_match('#^tel:#i', $url)) {
    serveJsRedirect($url, $name, '&#128222;', 'Tu telefono iniciara la llamada automaticamente.');
    exit;

} elseif (preg_match('#^sms:#i', $url)) {
    serveJsRedirect($url, $name, '&#9993;', 'Tu app de mensajes deberia abrirse automaticamente.');
    exit;

} elseif (preg_match('#^WIFI:S:#', $url)) {
    serveWifi($url, $name);
    exit;

} elseif (preg_match('#^BEGIN:VCARD#', $url)) {
    header('Content-Type: text/vcard; charset=utf-8');
    header('Content-Disposition: attachment; filename="contacto.vcf"');
    echo $url;
    exit;

} elseif (preg_match('#^BEGIN:VEVENT#', $url)) {
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="evento.ics"');
    echo "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Qlynk//QR//EN\r\n" . $url . "\r\nEND:VCALENDAR";
    exit;

} else {
    $tryUrl = 'https://' . $url;
    if (filter_var($tryUrl, FILTER_VALIDATE_URL)) {
        header("Location: {$tryUrl}", true, 301);
    } else {
        serveMessage('Destino invalido', 'La URL configurada en este QR no es valida. Contacta al propietario.', 422);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════
//  Funciones de respuesta HTML
// ══════════════════════════════════════════════════════════════

function serve404() {
    http_response_code(404);
    echo '<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>QR No Encontrado - Qlynk</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#070714;min-height:100vh;display:flex;align-items:center;justify-content:center;color:#fff;overflow:hidden}
.bg{position:fixed;inset:0;background:radial-gradient(ellipse 80% 60% at 50% -10%,rgba(26,26,78,.3),transparent),radial-gradient(ellipse 50% 40% at 80% 100%,rgba(45,10,106,.2),transparent);pointer-events:none}
.wrap{position:relative;text-align:center;padding:40px 24px;max-width:500px;width:100%}
.qr-box{width:96px;height:96px;margin:0 auto 28px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:22px;display:flex;align-items:center;justify-content:center;font-size:44px;box-shadow:0 20px 60px rgba(0,0,0,.5)}
.code{font-size:clamp(80px,20vw,130px);font-weight:900;line-height:1;color:rgba(255,255,255,.06);letter-spacing:-6px;margin-bottom:4px}
h1{font-size:clamp(18px,4vw,22px);font-weight:700;margin-bottom:10px;letter-spacing:-.3px}
p{color:rgba(255,255,255,.4);font-size:13px;line-height:1.7;max-width:320px;margin:0 auto 8px}
.badge{display:inline-flex;align-items:center;gap:8px;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.2);border-radius:30px;padding:6px 16px;font-size:12px;color:rgba(255,120,120,.8);margin-top:28px}
.dot{width:6px;height:6px;border-radius:50%;background:#ef4444;display:inline-block}
.brand{margin-top:48px;font-size:11px;color:rgba(255,255,255,.12);letter-spacing:1px;text-transform:uppercase}
</style>
</head>
<body>
<div class="bg"></div>
<div class="wrap">
  <div class="qr-box">&#9724;</div>
  <div class="code">404</div>
  <h1>QR No Encontrado</h1>
  <p>Este codigo QR no existe, fue eliminado o esta desactivado.<br>Contacta al propietario si crees que es un error.</p>
  <div class="badge"><span class="dot"></span>Codigo no disponible</div>
  <div class="brand">Powered by Qlynk</div>
</div>
</body>
</html>';
}

function serveMessage($title, $msg, $code) {
    http_response_code($code);
    $t = htmlspecialchars($title);
    $m = htmlspecialchars($msg);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $t . ' - Qlynk</title><style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:-apple-system,sans-serif;background:#070714;min-height:100vh;display:flex;align-items:center;justify-content:center;color:#fff}.wrap{text-align:center;padding:40px 24px;max-width:440px}h1{font-size:22px;font-weight:700;margin-bottom:12px}p{color:rgba(255,255,255,.45);font-size:14px;line-height:1.6}.brand{margin-top:48px;font-size:11px;color:rgba(255,255,255,.12);letter-spacing:1px;text-transform:uppercase}</style></head><body><div class="wrap"><h1>' . $t . '</h1><p>' . $m . '</p><div class="brand">Powered by Qlynk</div></div></body></html>';
}

function serveJsRedirect($url, $name, $ico, $sub) {
    $safeUrl  = htmlspecialchars($url, ENT_QUOTES);
    $safeName = htmlspecialchars($name);
    $safeSub  = htmlspecialchars($sub);
    $jsUrl    = json_encode($url);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Abriendo - Qlynk</title><style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:-apple-system,sans-serif;background:#070714;min-height:100vh;display:flex;align-items:center;justify-content:center;color:#fff}.wrap{text-align:center;padding:40px 24px;max-width:480px}.ico{font-size:56px;margin-bottom:20px;display:block}h1{font-size:20px;font-weight:700;margin-bottom:8px}.sub{color:rgba(255,255,255,.4);font-size:13px;margin-bottom:32px;line-height:1.5}.spinner{width:44px;height:44px;border:3px solid rgba(255,255,255,.08);border-top-color:rgba(255,255,255,.6);border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 28px}@keyframes spin{to{transform:rotate(360deg)}}.btn{display:inline-block;padding:14px 36px;background:#fff;color:#0a0a1a;border-radius:30px;text-decoration:none;font-weight:700;font-size:14px;margin-top:8px}.hint{font-size:11px;color:rgba(255,255,255,.18);margin-top:16px}.brand{font-size:11px;color:rgba(255,255,255,.12);letter-spacing:1px;text-transform:uppercase;margin-top:48px}</style><script>window.onload=function(){try{window.location.href=' . $jsUrl . ';}catch(e){}};</script></head><body><div class="wrap"><span class="ico">' . $ico . '</span><div class="spinner"></div><h1>' . $safeName . '</h1><p class="sub">' . $safeSub . '</p><a href="' . $safeUrl . '" class="btn">Abrir ahora</a><p class="hint">Si no se abre automaticamente, toca el boton</p><div class="brand">Powered by Qlynk</div></div></body></html>';
}

function serveWifi($url, $name) {
    preg_match('#WIFI:S:(.*?);T:(.*?);P:(.*?);;#', $url, $m);
    $ssid = isset($m[1]) ? $m[1] : '--';
    $sec  = isset($m[2]) ? $m[2] : 'WPA';
    $pass = isset($m[3]) ? $m[3] : '';
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>WiFi - ' . htmlspecialchars($name) . '</title><style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:-apple-system,sans-serif;background:#070714;min-height:100vh;display:flex;align-items:center;justify-content:center;color:#fff}.wrap{text-align:center;padding:40px 24px;max-width:460px;width:100%}.ico{font-size:64px;margin-bottom:16px;display:block}h1{font-size:22px;font-weight:700;margin-bottom:6px}.sub{color:rgba(255,255,255,.35);font-size:13px;margin-bottom:28px}.card{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:18px;padding:4px 0;margin-bottom:24px;text-align:left}.row{display:flex;justify-content:space-between;align-items:center;padding:14px 20px;border-bottom:1px solid rgba(255,255,255,.06)}.row:last-child{border:0}.lbl{font-size:11px;color:rgba(255,255,255,.3);text-transform:uppercase;letter-spacing:.8px}.val{font-size:14px;font-weight:600;max-width:220px;text-align:right;word-break:break-all}.copy-btn{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);color:#fff;border-radius:8px;padding:5px 12px;cursor:pointer;font-size:12px;margin-left:10px}.brand{font-size:11px;color:rgba(255,255,255,.12);letter-spacing:1px;text-transform:uppercase;margin-top:40px}</style></head><body><div class="wrap"><span class="ico">&#128246;</span><h1>' . htmlspecialchars($name) . '</h1><p class="sub">Datos de conexion WiFi</p><div class="card"><div class="row"><span class="lbl">Red (SSID)</span><span class="val">' . htmlspecialchars($ssid) . '</span></div><div class="row"><span class="lbl">Seguridad</span><span class="val">' . htmlspecialchars($sec) . '</span></div><div class="row"><span class="lbl">Contrasena</span><div style="display:flex;align-items:center"><span class="val" id="pwd">' . htmlspecialchars($pass) . '</span><button class="copy-btn" onclick="copyPass()">Copiar</button></div></div></div><div class="brand">Powered by Qlynk</div></div><script>function copyPass(){var t=document.getElementById("pwd").textContent;if(navigator.clipboard){navigator.clipboard.writeText(t).then(function(){alert("Copiado!");});}else{var e=document.createElement("textarea");e.value=t;document.body.appendChild(e);e.select();document.execCommand("copy");document.body.removeChild(e);alert("Copiado!");}}</script></body></html>';
}