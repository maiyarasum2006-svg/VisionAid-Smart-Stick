<?php
// api/gps.php
require_once '../config/db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $device_id = $_POST['device_id'] ?? 'ESP32_01';
    $latitude = $_POST['latitude'] ?? 0.0;
    $longitude = $_POST['longitude'] ?? 0.0;

    $stmt = $conn->prepare("INSERT INTO gps_locations (device_id, latitude, longitude) VALUES (?, ?, ?)");
    $stmt->bind_param("sdd", $device_id, $latitude, $longitude);

    if ($stmt->execute()) {
        echo "GPS Data inserted successfully!";
    } else {
        echo "Error: " . $stmt->error;
    }
    $stmt->close();
} else {
    echo "Invalid Request Method. Use POST.";
}
$conn->close();
?>