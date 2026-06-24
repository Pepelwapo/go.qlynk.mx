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
    http_response_code(404);
    exit('Código no especificado.');
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
    http_response_code(404);
    exit('QR no encontrado.');
}

// 2. Verificar límite de escaneos del plan
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

if ($usage && $usage['scans_limit'] > 0 && (int)$usage['total_scans'] >= (int)$usage['scans_limit']) {
    http_response_code(403);
    exit('Este QR ha alcanzado el límite de escaneos del plan.');
}

// 3. Verificar expiración
if (!empty($qr['expires_at']) && strtotime($qr['expires_at']) < time()) {
    http_response_code(410);
    exit('Este QR ha expirado.');
}

// 4. Sumar escaneo
$pdo->prepare("
    UPDATE qr_codes
    SET scan_count = scan_count + 1
    WHERE id = ?
")->execute([$qr['id']]);

// 5. Redirigir
$url = trim($qr['target_url']);

if (empty($url)) {
    http_response_code(500);
    exit('URL de destino no configurada.');
}

if (!preg_match('#^https?://#i', $url)) {
    $url = 'https://' . $url;
}

if (!filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(500);
    exit('URL inválida: ' . htmlspecialchars($url));
}

header("Location: {$url}", true, 301);
exit;