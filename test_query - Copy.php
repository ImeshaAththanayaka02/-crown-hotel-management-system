<?php
require_once 'config/database.php';

// Test case 1: No dates
echo "TEST 1: No dates\n";
$chk_in = '';
$chk_out = '';
$filter_category = '';

$where_clauses = ["r.status != 'Maintenance'"];
$query_params = [];

if ($filter_category !== '') {
    $where_clauses[] = "r.type_id = :type_id";
    $query_params['type_id'] = $filter_category;
}

$has_dates = ($chk_in !== '' && $chk_out !== '') ? 1 : 0;
$query_params['has_dates1'] = $has_dates;
$query_params['has_dates2'] = $has_dates;
$query_params['check_in'] = $chk_in;
$query_params['check_out'] = $chk_out;

$where_sql = implode(" AND ", $where_clauses);
$rooms_stmt = $pdo->prepare("
    SELECT r.*, rt.name AS type_name, rt.description AS type_desc, rt.max_occupancy,
           (CASE 
                WHEN :has_dates1 = 1 AND r.id IN (
                    SELECT room_id FROM reservations 
                    WHERE status IN ('Confirmed', 'Pending') 
                      AND NOT (check_out_date <= :check_in OR check_in_date >= :check_out)
                ) THEN 0
                WHEN :has_dates2 = 0 AND r.status != 'Available' THEN 0
                ELSE 1
           END) AS is_available
    FROM rooms r 
    JOIN room_types rt ON r.type_id = rt.id 
    WHERE $where_sql
    ORDER BY r.price ASC
");
$rooms_stmt->execute($query_params);
$rooms_all = $rooms_stmt->fetchAll();

foreach ($rooms_all as $r) {
    echo "Room " . $r['room_number'] . " (" . $r['type_name'] . "): Status=" . $r['status'] . ", Available=" . $r['is_available'] . "\n";
}

// Test case 2: Dates provided
echo "\nTEST 2: Dates provided (2026-06-11 to 2026-06-13)\n";
$chk_in = '2026-06-11';
$chk_out = '2026-06-13';
$query_params['has_dates1'] = 1;
$query_params['has_dates2'] = 1;
$query_params['check_in'] = $chk_in;
$query_params['check_out'] = $chk_out;

$rooms_stmt->execute($query_params);
$rooms_all = $rooms_stmt->fetchAll();

foreach ($rooms_all as $r) {
    echo "Room " . $r['room_number'] . " (" . $r['type_name'] . "): Status=" . $r['status'] . ", Available=" . $r['is_available'] . "\n";
}
?>
