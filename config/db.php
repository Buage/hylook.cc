<?php
$db_host = "localhost";
$db_user = "root"; 
$db_pass = "";
$db_name = "";
$port = 3306;

//require_once __DIR__ . '/../rateLimit.php';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name, $port);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");
$IP_HASH_PEPPER = "HASH_HERE"

?>