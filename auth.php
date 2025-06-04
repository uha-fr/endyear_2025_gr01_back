<?php
header('Content-Type: application/json');
// this is a test for deployment
// Add CORS headers
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
} else {
    header("Access-Control-Allow-Origin: *");
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Origin, Content-Type, Accept, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // Handle preflight request
    http_response_code(200);
    exit;
}

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

function fetchEmployees($apiBaseUrl, $apiKey) {
    $url = $apiBaseUrl . '/api/employees/';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Use HTTP Basic Auth with API key as username and empty password
    curl_setopt($ch, CURLOPT_USERPWD, $apiKey . ':');
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log('cURL error in fetchEmployees: ' . curl_error($ch));
        curl_close($ch);
        return null;
    }
    curl_close($ch);
    // Parse XML response
    $xml = simplexml_load_string($response);
    if ($xml === false) {
        error_log('Failed to parse XML in fetchEmployees');
        return null;
    }
    // Convert XML to array of employee IDs
    $employees = [];
    if (isset($xml->employees->employee)) {
        foreach ($xml->employees->employee as $emp) {
            $attributes = $emp->attributes('xlink', true);
            if ($attributes && isset($attributes['href'])) {
                // Extract ID from href URL
                $href = (string)$attributes['href'];
                preg_match('/\/api\/employees\/(\d+)/', $href, $matches);
                if (isset($matches[1])) {
                    $employees[] = ['id' => $matches[1]];
                }
            }
        }
    }
    return ['employees' => $employees];
}

function fetchEmployeeById($apiBaseUrl, $apiKey, $id) {
    $url = $apiBaseUrl . '/api/employees/' . $id;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Use HTTP Basic Auth with API key as username and empty password
    curl_setopt($ch, CURLOPT_USERPWD, $apiKey . ':');
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log('cURL error in fetchEmployeeById: ' . curl_error($ch));
        curl_close($ch);
        return null;
    }
    curl_close($ch);
    // Parse XML response
    $xml = simplexml_load_string($response);
    if ($xml === false || !isset($xml->employee)) {
        error_log('Failed to parse XML or missing employee in fetchEmployeeById');
        return null;
    }
    // Convert XML employee data to associative array
    $employee = [];
    foreach ($xml->employee->children() as $child) {
        $employee[$child->getName()] = (string)$child;
    }
    return ['employee' => $employee];
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
