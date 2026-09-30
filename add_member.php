<?php
require 'db.php';

$group_id = 1;
$name = $_POST['name'];

$stmt = $conn->prepare("INSERT INTO members (group_id, name) VALUES (?, ?)");
$stmt->bind_param("is", $group_id, $name);
$stmt->execute();

header("Location: index.php");
exit;