<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>MRL Database Column Check</h2>";

$host = "localhost";
$db   = "u809830586_MRL_DB";
$user = "u809830586_MRL_DB";
$pass = "7neGYdSZkFpR";

try {
    $conn = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<p style='color:green;'>✔ Connected successfully!</p>";

    echo "<h3>Columns in 'teams' table:</h3>";

    $stmt = $conn->query("SHOW COLUMNS FROM teams");
    echo "<pre>";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        print_r($row);
    }
    echo "</pre>";

} catch (PDOException $e) {
    echo "<p style='color:red;'>❌ Error: " . $e->getMessage() . "</p>";
}
