<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

 
include 'config.php';

// Fonction pour appeler l'API PrestaShop
function callPrestaShopApi($url, $apiKey, $format = "JSON") {
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

// Récupérer la liste des commandes
$ordersApiUrl = "$apiBaseUrl/api/orders";
$ordersApiResult = callPrestaShopApi($ordersApiUrl, $apiKey);

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

// Transformation JSON en tableau PHP
$ordersData = json_decode($ordersApiResult["response"], true);

if (!isset($ordersData['orders']) || !is_array($ordersData['orders'])) {
    echo json_encode([
        "success" => false,
        "error" => "Format de réponse inattendu"
    ]);
    exit;
}

// Cache pour les noms des clients et des états
$customerCache = [];
$orderStateCache = [];

// Préparer la liste des commandes avec les champs demandés
$ordersList = [];

foreach ($ordersData['orders'] as $orderItem) {
    // Si c'est juste une référence à une commande, récupérer les détails
    if (isset($orderItem['id'])) {
        $orderId = $orderItem['id'];
        $orderDetailUrl = "$apiBaseUrl/api/orders/$orderId";
        $orderDetailResult = callPrestaShopApi($orderDetailUrl, $apiKey);
        
        if (!$orderDetailResult["error"] && $orderDetailResult["httpCode"] === 200) {
            $orderDetail = json_decode($orderDetailResult["response"], true);
            
            if (isset($orderDetail['order'])) {
                $order = $orderDetail['order'];
                
                // Récupérer le nom du client
                $customerName = "";
                if (isset($order['id_customer']) && !empty($order['id_customer'])) {
                    $customerId = $order['id_customer'];
                    
                    // Utiliser le cache si disponible
                    if (isset($customerCache[$customerId])) {
                        $customerName = $customerCache[$customerId];
                    } else {
                        $customerApiUrl = "$apiBaseUrl/api/customers/$customerId";
                        $customerApiResult = callPrestaShopApi($customerApiUrl, $apiKey);
                        
                        if (!$customerApiResult["error"] && $customerApiResult["httpCode"] === 200) {
                            $customerData = json_decode($customerApiResult["response"], true);
                            if (isset($customerData['customer'])) {
                                $firstname = $customerData['customer']['firstname'];
                                $lastname = $customerData['customer']['lastname'];
                                $customerName = trim($firstname . " " . $lastname);
                                
                                // Mettre en cache
                                $customerCache[$customerId] = $customerName;
                            }
                        }
                    }
                }
                
                // Récupérer le nom de l'état de la commande
                $orderStateName = "";
                if (isset($order['current_state']) && !empty($order['current_state'])) {
                    $orderStateId = $order['current_state'];
                    
                    // Utiliser le cache si disponible
                    if (isset($orderStateCache[$orderStateId])) {
                        $orderStateName = $orderStateCache[$orderStateId];
                    } else {
                        $orderStateApiUrl = "$apiBaseUrl/api/order_states/$orderStateId";
                        // Pour le statut de commande, utiliser XML qui est plus facile à parser pour les langues
                        $orderStateApiResult = callPrestaShopApi($orderStateApiUrl, $apiKey, "XML");
                        
                        if (!$orderStateApiResult["error"] && $orderStateApiResult["httpCode"] === 200) {
                            $orderStateXml = simplexml_load_string($orderStateApiResult["response"]);
                            if ($orderStateXml && isset($orderStateXml->order_state->name->language)) {
                                // Prendre la première langue disponible
                                $orderStateName = (string)$orderStateXml->order_state->name->language;
                                
                                // Mettre en cache
                                $orderStateCache[$orderStateId] = $orderStateName;
                            }
                        }
                    }
                }
                
                // Ajouter à la liste des commandes
                $ordersList[] = [
                    "id" => (int)$order['id'],
                    "reference" => $order['reference'],
                    "totalPaidTaxIncl" => (float)$order['total_paid_tax_incl'],
                    "customerId" => isset($order['id_customer']) ? (int)$order['id_customer'] : null,
                    "customerName" => $customerName,
                    "currentStateName" => $orderStateName,
                    "dateAdd" => $order['date_add']
                ];
            }
        }
    }
}

// Handle POST request to update currentStateName of an order with orderId in URL path
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Extract orderId from URL path
    $requestUri = $_SERVER['REQUEST_URI'];
    $scriptName = $_SERVER['SCRIPT_NAME'];
    $path = substr($requestUri, strlen($scriptName));
    $path = trim($path, '/');
    $orderIdFromPath = null;
    if (!empty($path)) {
        $parts = explode('/', $path);
        $orderIdFromPath = intval($parts[0]);
    }

    if (!$orderIdFromPath) {
        echo json_encode([
            "success" => false,
            "error" => "Missing orderId in URL path"
        ]);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['newStateId'])) {
        echo json_encode([
            "success" => false,
            "error" => "Missing newStateId in request body"
        ]);
        exit;
    }

    $orderId = $orderIdFromPath;
    $newStateId = intval($input['newStateId']);

    // Get current order data
    $orderDetailUrl = "$apiBaseUrl/api/orders/$orderId";
    $orderDetailResult = callPrestaShopApi($orderDetailUrl, $apiKey);

    if ($orderDetailResult["error"] || $orderDetailResult["httpCode"] !== 200) {
        echo json_encode([
            "success" => false,
            "error" => "Failed to fetch order details",
            "details" => $orderDetailResult["response"]
        ]);
        exit;
    }

    $orderDetail = json_decode($orderDetailResult["response"], true);
    if (!isset($orderDetail['order'])) {
        echo json_encode([
            "success" => false,
            "error" => "Order data not found"
        ]);
        exit;
    }

    $order = $orderDetail['order'];
    // Update current_state
    $order['current_state'] = $newStateId;

    // Prepare XML payload for update (PrestaShop API expects XML for updates)
    $xml = new SimpleXMLElement('<prestashop/>');
    $orderXml = $xml->addChild('order');
    foreach ($order as $key => $value) {
        // Only add scalar values to XML
        if (is_scalar($value)) {
            $orderXml->addChild($key, htmlspecialchars($value));
        }
    }
    $xmlPayload = $xml->asXML();

    // Initialize cURL for PUT request
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $orderDetailUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => "PUT",
        CURLOPT_USERPWD => "$apiKey:",
        CURLOPT_HTTPHEADER => array(
            "Content-Type: application/xml",
            "Content-Length: " . strlen($xmlPayload)
        ),
        CURLOPT_POSTFIELDS => $xmlPayload
    ));

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_errno($curl) ? curl_error($curl) : null;
    curl_close($curl);

    if ($error) {
        echo json_encode([
            "success" => false,
            "error" => $error
        ]);
        exit;
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        echo json_encode([
            "success" => true,
            "message" => "Order state updated successfully"
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "httpCode" => $httpCode,
            "response" => $response
        ]);
    }
    exit;
}

// Réponse finale
echo json_encode([
    "success" => true,
    "orders" => $ordersList
]);
