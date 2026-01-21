<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header("Access-Control-Allow-Headers: X-Requested-With");
header('Content-Type: application/json');

require_once '../config/db.php';

if (!isset($_GET['user'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Username parameter is missing']);
    exit;
}

$username = trim($_GET['user']);
$username_hash = hash('sha256', $username);
$now = time();
$api_url = "https://accounts.hytale.com/api/account/username-reservations/availability?username=" . urlencode($username);
$cookie_header = '';

$cookie_file = __DIR__ . '/../config/cookies.json';
if (file_exists($cookie_file)) {
    $cookies = json_decode(file_get_contents($cookie_file), true);
    if (is_array($cookies)) {
        foreach ($cookies as $c) {
            if (isset($c['name'], $c['value'])) {
                $cookie_header .= $c['name'] . '=' . $c['value'] . '; ';
            }
        }
        $cookie_header = rtrim($cookie_header, '; ');
    }
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$headers = ['Content-Type: application/json'];
if ($cookie_header !== '') $headers[] = 'Cookie: ' . $cookie_header;
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$status = 'unknown';
if ($http_code === 200 && $response === '') $status = 'available';
if (stripos($response, 'already in use') !== false) $status = 'taken';
if (stripos($response, 'prohibited') !== false) $status = 'banned';

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ip_hash = $ip !== '' ? hash('sha256', $ip) : '';
$key = 'lookup_' . $username_hash . '_' . $ip_hash;
$ttl = 900;
$used_recently = false;

if ($ip_hash !== '') {
    if (function_exists('apcu_fetch')) {
        $used_recently = apcu_fetch($key) !== false;
        if (!$used_recently) apcu_store($key, $now, $ttl);
    } else {
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $key;
        if (file_exists($file)) {
            $mtime = filemtime($file);
            if ($mtime !== false && ($now - $mtime) < $ttl) $used_recently = true;
            else @touch($file);
        } else {
            @file_put_contents($file, (string)$now);
            @chmod($file, 0600);
        }
    }
}

$insert_count = $used_recently ? 0 : 1;

function generateToken($length = 16) {
    return bin2hex(random_bytes($length / 2));
}

$token_plain = generateToken();
$token_hashed = password_hash($token_plain, PASSWORD_BCRYPT);

$stmt = $conn->prepare("INSERT INTO lookups (username, count, timestamp, token) VALUES (?, ?, ?, ?)");
if ($stmt) {
    $stmt->bind_param("siss", $username_hash, $insert_count, $now, $token_hashed);
    $stmt->execute();
    $stmt->close();
}

$month_start = strtotime(date('Y-m-01 00:00:00'));
$year_start = strtotime(date('Y-01-01 00:00:00'));

$total_stmt = $conn->prepare("SELECT COALESCE(SUM(count),0) FROM lookups WHERE username = ?");
$total_stmt->bind_param("s", $username_hash);
$total_stmt->execute();
$total_stmt->bind_result($total_searches);
$total_stmt->fetch();
$total_stmt->close();

$monthly_stmt = $conn->prepare("SELECT COALESCE(SUM(count),0) FROM lookups WHERE username = ? AND timestamp >= ?");
$monthly_stmt->bind_param("si", $username_hash, $month_start);
$monthly_stmt->execute();
$monthly_stmt->bind_result($monthly_searches);
$monthly_stmt->fetch();
$monthly_stmt->close();

$yearly_stmt = $conn->prepare("SELECT COALESCE(SUM(count),0) FROM lookups WHERE username = ? AND timestamp >= ?");
$yearly_stmt->bind_param("si", $username_hash, $year_start);
$yearly_stmt->execute();
$yearly_stmt->bind_result($yearly_searches);
$yearly_stmt->fetch();
$yearly_stmt->close();

$history_stmt = $conn->prepare("
    SELECT FROM_UNIXTIME(timestamp, '%Y-%m-%d') AS date, COALESCE(SUM(count),0) AS count
    FROM lookups
    WHERE username = ?
    GROUP BY date
    ORDER BY date ASC
");
$history_stmt->bind_param("s", $username_hash);
$history_stmt->execute();
$res = $history_stmt->get_result();
$history = [];
while ($row = $res->fetch_assoc()) {
    $history[] = ['date' => $row['date'], 'count' => (int)$row['count']];
}
$history_stmt->close();

echo json_encode([
    'status' => 'success',
    'data' => [
        'username_status' => $status,
        'total_searches' => (int)$total_searches,
        'monthly_searches' => (int)$monthly_searches,
        'yearly_searches' => (int)$yearly_searches,
        'search_history' => $history,
        'generated_token' => $token_plain
    ]
]);
