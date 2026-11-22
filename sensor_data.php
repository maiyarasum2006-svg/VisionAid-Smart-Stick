<?php
require_once '../config/db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $device_id = $_POST['device_id'] ?? 'ESP32_01';
    $distance = floatval($_POST['obstacle_distance'] ?? 400);
    $water = $_POST['water_status'] ?? 'Safe';
    $tilt = $_POST['tilt_status'] ?? 'Safe';
    $sos = $_POST['sos'] ?? '0';
    $lat = $_POST['lat'] ?? '13.0827';
    $lng = $_POST['lng'] ?? '80.2707';
    
    $user_id = 1; 

    // 1. Update Database
    $stmt1 = $conn->prepare("INSERT INTO sensor_data (device_id, obstacle_distance, water_status, tilt_status, latitude, longitude) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt1->bind_param("sdssss", $device_id, $distance, $water, $tilt, $lat, $lng);
    $stmt1->execute();
    $stmt1->close();

    $conn->query("UPDATE devices SET status='Active', last_connected=NOW() WHERE device_id='ESP32_01'");
    
    // 2. SEND EMERGENCY EMAIL LOGIC
    if ($sos == '1') {
        $last_email_file = 'last_email_time.txt';
        $last_time = file_exists($last_email_file) ? intval(file_get_contents($last_email_file)) : 0;
        $current_time = time();

        // Only send 1 email every 60 seconds to prevent spam
        if (($current_time - $last_time) > 60) {
            
            // 🚨 UPDATE WITH YOUR FAMILY'S EMAIL ADDRESS 🚨
            $to = "YOUR EMAIL ID"; 
            $subject = "URGENT: VisionAid SOS Alert";
            $message = "The VisionAid SOS Button was pressed!\n\nLive Location: https://maps.google.com/?q=$lat,$lng";
            $headers = "From: visionaid_system@localhost";

            mail($to, $subject, $message, $headers);
            file_put_contents($last_email_file, $current_time); // Reset the 60-second timer
        }
    }
    
    echo "OK";
    exit;
}

// Dashboard Data Feed
header('Content-Type: application/json');
$result = $conn->query("SELECT * FROM sensor_data ORDER BY id DESC LIMIT 1");
if ($row = $result->fetch_assoc()) {
    echo json_encode($row);
}
$conn->close();
?>