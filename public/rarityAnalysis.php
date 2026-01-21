<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header("Access-Control-Allow-Headers: X-Requested-With, Authorization, Content-Type");
header('Content-Type: application/json');
ini_set('display_errors', 0);
error_reporting(0);
require_once '../config/db.php';

$api_key = @file_get_contents(__DIR__ . '/../config/mistral-key.txt');
$api_key = $api_key !== false ? trim($api_key) : '';
if ($api_key === '') { http_response_code(500); echo json_encode(['status'=>'error','message'=>'service unavailable']); exit; }

if (!isset($_GET['user']) || !isset($_GET['token'])) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'bad request']); exit; }

$pseudo = trim($_GET['user']);
$token_input = trim($_GET['token']);
$pseudo_hash = hash('sha256', $pseudo);

$stmt = $conn->prepare("SELECT token FROM lookups WHERE username = ? ORDER BY timestamp DESC LIMIT 1");
if (!$stmt) { http_response_code(500); echo json_encode(['status'=>'error','message'=>'service unavailable']); exit; }
$stmt->bind_param("s", $pseudo_hash);
if (!$stmt->execute()) { $stmt->close(); http_response_code(500); echo json_encode(['status'=>'error','message'=>'service unavailable']); exit; }
$stmt->bind_result($db_token_hash);
$found = $stmt->fetch();
$stmt->close();

if (!$found || $db_token_hash === null || $db_token_hash === "0" || !password_verify($token_input, $db_token_hash)) {
    http_response_code(403);
    echo json_encode(['status'=>'error','message'=>'unauthorized']);
    exit;
}

$prompt = "Analyze the rarity of the username '{$pseudo}'. Provide a score from 0 to 10. Rarity is defined only by being an OG/early username: shorter, simpler, and early-created usernames are rarer and more desirable. Give a concise explanation of 30-50 words, do not use markdown.";

$payload = [
    "model" => "mistral-large-2512",
    "messages" => [
        ["role" => "user", "content" => $prompt]
    ]
];

$ch = curl_init("https://api.mistral.ai/v1/chat/completions");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $api_key,
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$response = curl_exec($ch);
curl_close($ch);

$decoded = json_decode($response, true);
$result_text = null;

if (is_array($decoded)) {
    if (isset($decoded['choices'][0]['message']['content']) && is_string($decoded['choices'][0]['message']['content'])) {
        $result_text = $decoded['choices'][0]['message']['content'];
    } elseif (isset($decoded['choices'][0]['content']) && is_array($decoded['choices'][0]['content'])) {
        foreach ($decoded['choices'][0]['content'] as $c) {
            if (is_array($c) && isset($c['type'], $c['text']) && strpos($c['type'],'text')!==false) { $result_text=(string)$c['text']; break; }
            if (is_array($c) && isset($c['text'])) { $result_text=(string)$c['text']; break; }
        }
    } elseif (isset($decoded['outputs'][0]['content'][0]['text'])) {
        $result_text = (string)$decoded['outputs'][0]['content'][0]['text'];
    }
}

if ($result_text === null) {
    if (is_string($response) && $response !== '') { echo json_encode(['status'=>'success','result'=> $decoded]); exit; }
    http_response_code(502);
    echo json_encode(['status'=>'error','message'=>'service unavailable']);
    exit;
}

$update_stmt = $conn->prepare("UPDATE lookups SET token='0' WHERE username=?");
if ($update_stmt) {
    $update_stmt->bind_param("s", $pseudo_hash);
    $update_stmt->execute();
    $update_stmt->close();
}

echo json_encode(['status'=>'success','result'=> $result_text]);
