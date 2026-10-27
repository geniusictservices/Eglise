<?php
// Faux Africa's Talking et faux WhatsApp Cloud : note chaque requête, répond comme le vrai service
$journal = __DIR__ . '/mock-e2e.log';
$corps = file_get_contents('php://input');
$entetes = function_exists('getallheaders') ? getallheaders() : [];
file_put_contents($journal, json_encode(['chemin' => $_SERVER['REQUEST_URI'], 'entetes' => $entetes, 'corps' => $corps], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
header('Content-Type: application/json');
$chemin = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($chemin === '/sms') {
    parse_str($corps, $champs);
    http_response_code(201);
    echo json_encode(['SMSMessageData' => ['Message' => 'Sent to 1/1', 'Recipients' => [['statusCode' => 101, 'number' => $champs['to'] ?? '', 'status' => 'Success', 'cost' => 'USD 0.0200', 'messageId' => 'ATPid_' . substr(md5($corps), 0, 10)]]]]);
} elseif ($chemin === '/sms-ko') {
    http_response_code(201);
    echo json_encode(['SMSMessageData' => ['Message' => 'Sent to 0/1', 'Recipients' => [['statusCode' => 405, 'number' => '', 'status' => 'InsufficientBalance', 'cost' => '0', 'messageId' => 'None']]]]);
} elseif ($chemin === '/wa') {
    echo json_encode(['messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '243']], 'messages' => [['id' => 'wamid.' . substr(md5($corps), 0, 12)]]]);
} else {
    http_response_code(400);
    echo json_encode(['error' => ['message' => '(#132001) Template name does not exist in the translation', 'code' => 132001]]);
}
