<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once 'SquidStatsParser.php';

$parser = new SquidStatsParser('/var/log/squid/access.log');

$forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] === 'true';
$stats = $parser->getStats($forceRefresh);

echo json_encode($stats, JSON_PRETTY_PRINT);
?>
