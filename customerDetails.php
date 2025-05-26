<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

include 'config.php';

// Récupérer l'ID du client depuis la requête
$customerId = isset($_GET['id']) ? intval($_GET['id']) : null;

if (!$customerId) {
    echo json_encode([
        "success" => false,
        "error" => "ID de client non spécifié"
    ]);
    exit;
}

// Fonction pour appeler l'API PrestaShop
function callPrestaShopApi($url, $apiKey, $format = "XML") {
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => "$apiKey:",
        CURLOPT_HTTPHEADER => array(
            "Accept: application/$format",
            "Output-Format: $format"
        )
    ));
    
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_errno($curl) ? curl_error($curl) : null;
    
    curl_close($curl);
    
    return [
        "response" => $response,
        "httpCode" => $httpCode,
        "error" => $error
    ];
}

// Appeler l'API pour obtenir les détails du client
$customerApiUrl = "$apiBaseUrl/api/customers/$customerId";
$customerApiResult = callPrestaShopApi($customerApiUrl, $apiKey);

if ($customerApiResult["error"]) {
    echo json_encode([
        "success" => false,
        "error" => $customerApiResult["error"]
    ]);
    exit;
}

if ($customerApiResult["httpCode"] !== 200) {
    echo json_encode([
        "success" => false,
        "httpCode" => $customerApiResult["httpCode"],
        "response" => $customerApiResult["response"]
    ]);
    exit;
}

// Parser la réponse XML du client
$customerXml = simplexml_load_string($customerApiResult["response"]);
if (!$customerXml) {
    echo json_encode([
        "success" => false,
        "error" => "Erreur de parsing XML du client"
    ]);
    exit;
}

// Extraire les données du client
$customerData = $customerXml->customer;

// Appeler l'API locale orders.php pour obtenir la liste des commandes
$ordersApiUrl = "http://localhost/xampp/endyear_2025_gr01_back/orders.php";
$ordersApiResult = callPrestaShopApi($ordersApiUrl, $apiKey, "JSON");

if ($ordersApiResult["error"]) {
    echo json_encode([
        "success" => false,
        "error" => $ordersApiResult["error"]
    ]);
    exit;
}

if ($ordersApiResult["httpCode"] !== 200) {
    echo json_encode([
        "success" => false,
        "httpCode" => $ordersApiResult["httpCode"],
        "response" => $ordersApiResult["response"]
    ]);
    exit;
}

// Parser la réponse JSON des commandes
$ordersData = json_decode($ordersApiResult["response"], true);

if (!isset($ordersData['orders']) || !is_array($ordersData['orders'])) {
    echo json_encode([
        "success" => false,
        "error" => "Format de réponse inattendu pour les commandes"
    ]);
    exit;
}

// Filtrer les commandes par ID client
$customerOrderIds = [];
foreach ($ordersData['orders'] as $orderItem) {
    if (isset($orderItem['customerId']) && intval($orderItem['customerId']) === $customerId) {
        $customerOrderIds[] = intval($orderItem['id']);
    }
}

// Réponse finale
echo json_encode([
    "success" => true,
    "customer" => [
        "id" => intval($customerData->id),
        "lastname" => (string)$customerData->lastname,
        "firstname" => (string)$customerData->firstname,
        "email" => (string)$customerData->email,
    "gender" => (intval($customerData->id_gender) === 1) ? "Homme" : ((intval($customerData->id_gender) === 2) ? "femme" : "unknown"),
        "birthday" => (string)$customerData->birthday,
        "active" => intval($customerData->active),
        "date_add" => (string)$customerData->date_add,
        "date_upd" => (string)$customerData->date_upd
    ],
    "order_ids" => $customerOrderIds
]);
