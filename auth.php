<?php
header('Content-Type: application/json');

require_once 'config.php';

// Get the raw POST data
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['email']) || !isset($input['password'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Email and password are required']);
    exit;
}

$email = $input['email'];
$password = $input['password'];

// Function to fetch all employees from the external API
function fetchEmployees($apiBaseUrl, $apiKey) {
    $url = $apiBaseUrl . '/api/employees/?output_format=JSON';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // If API key is needed in headers or query, add here
    // curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $apiKey]);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        curl_close($ch);
        return null;
    }
    curl_close($ch);
    return json_decode($response, true);
}

// Function to fetch employee details by ID
function fetchEmployeeById($apiBaseUrl, $apiKey, $id) {
    $url = $apiBaseUrl . '/api/employees/' . $id . '/?output_format=JSON';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $apiKey]);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        curl_close($ch);
        return null;
    }
    curl_close($ch);
    return json_decode($response, true);
}

// Fetch all employees
$employeesData = fetchEmployees($apiBaseUrl, $apiKey);
if ($employeesData === null || !isset($employeesData['employees'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch employees data']);
    exit;
}

// Find employee by email
$employeeId = null;
foreach ($employeesData['employees'] as $emp) {
    $empDetails = fetchEmployeeById($apiBaseUrl, $apiKey, $emp['id']);
    if ($empDetails === null || !isset($empDetails['employee'])) {
        continue;
    }
    if (isset($empDetails['employee']['email']) && strtolower($empDetails['employee']['email']) === strtolower($email)) {
        $employeeId = $emp['id'];
        $employeeData = $empDetails['employee'];
        break;
    }
}

if ($employeeId === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid email or password']);
    exit;
}

// Verify password
$hashedPassword = $employeeData['passwd'] ?? '';
if (!password_verify($password, $hashedPassword)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid email or password']);
    exit;
}

// Authentication successful
unset($employeeData['passwd']); // Remove password hash from response
echo json_encode([
    'success' => true,
    'employee' => $employeeData
]);
exit;
?>
