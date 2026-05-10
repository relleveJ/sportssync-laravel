<?php
$conn = new mysqli("localhost", "root", "", "sportssync");

$name = $_GET['name'];

$stmt = $conn->prepare("SELECT name, team, points, assists FROM players WHERE name LIKE ?");
$search = "%$name%";
$stmt->bind_param("s", $search);
$stmt->execute();

$result = $stmt->get_result();
$data = $result->fetch_assoc();

echo json_encode($data);
?>