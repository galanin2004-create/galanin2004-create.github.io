<?php
/**
 * Приём заявки на обычном хостинге, где нет Node.
 *
 * Делает то же, что app/api/lead/route.ts: проверяет имя и телефон и
 * пересылает заявку боту в Telegram. Нужен, когда сайт выложен статикой
 * (см. `npm run build:static`) — тогда в NEXT_PUBLIC_LEAD_ENDPOINT
 * ставится `/lead.php`.
 *
 * ТОКЕН ЗДЕСЬ НЕ ХРАНИТСЯ. Скрипт читает его из файла, который лежит
 * ВЫШЕ корня сайта и потому недоступен из браузера. Создайте рядом с
 * папкой сайта файл `lead.secret.php`:
 *
 *     <?php return [
 *         'token'   => '8123456789:AA...',
 *         'chat_id' => '751075998',
 *     ];
 *
 * Если положить его внутрь сайта, токен можно будет скачать по прямой
 * ссылке — тогда бот считается скомпрометированным.
 */

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Только POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Некорректный запрос'], JSON_UNESCAPED_UNICODE);
    exit;
}

$name  = trim((string)($payload['name'] ?? ''));
$phone = preg_replace('/\D/', '', (string)($payload['phone'] ?? ''));

// Валидация на клиенте — удобство, здесь — единственная настоящая.
if ($name === '' || strlen($phone) !== 11) {
    http_response_code(422);
    echo json_encode(['error' => 'Заполните имя и телефон'], JSON_UNESCAPED_UNICODE);
    exit;
}

$secretPath = __DIR__ . '/../lead.secret.php';
$secret = is_readable($secretPath) ? include $secretPath : null;
$token   = is_array($secret) ? ($secret['token'] ?? '')   : '';
$chatId  = is_array($secret) ? ($secret['chat_id'] ?? '') : '';

$text = "<b>Заявка с сайта «Час»</b>\n"
      . 'Имя: ' . htmlspecialchars($name, ENT_NOQUOTES, 'UTF-8') . "\n"
      . 'Телефон: +' . $phone;

if ($token === '' || $chatId === '') {
    // Заявку терять нельзя: пишем в лог, чтобы её можно было достать.
    error_log('[lead] Telegram не настроен, заявка только в логе: ' . $text);
    echo json_encode(['ok' => true, 'delivered' => false], JSON_UNESCAPED_UNICODE);
    exit;
}

$ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode([
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => 'HTML',
    ], JSON_UNESCAPED_UNICODE),
]);

$response = curl_exec($ch);
$status   = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($response === false || $status !== 200) {
    error_log('[lead] Не ушло в Telegram (' . $status . ' ' . $curlErr . '): ' . $text);
    http_response_code(502);
    echo json_encode(['error' => 'Не удалось отправить'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true, 'delivered' => true], JSON_UNESCAPED_UNICODE);
