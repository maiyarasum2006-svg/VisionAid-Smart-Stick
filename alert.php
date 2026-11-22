<?php
// api/alert.php
require_once '../config/db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $device_id = $_POST['device_id'] ?? 'ESP32_01';
    $alert_type = $_POST['alert_type'] ?? 'Unknown';
    $alert_message = $_POST['alert_message'] ?? 'No message';

    $stmt = $conn->prepare("INSERT INTO alerts (device_id, alert_type, alert_message) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $device_id, $alert_type, $alert_message);

    if ($stmt->execute()) {
        echo "Alert inserted successfully!";
    } else {
        echo "Error: " . $stmt->error;
    }
    $stmt->close();
} else {
    echo "Invalid Request Method. Use POST.";
}
$conn->close();
?>