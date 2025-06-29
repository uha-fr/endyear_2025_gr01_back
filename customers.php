<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

include 'config.php';

// Function to call PrestaShop API
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

// Call PrestaShop API to get list of customers (only IDs)
$customersApiUrl = "$apiBaseUrl/api/customers";
$customersApiResult = callPrestaShopApi($customersApiUrl, $apiKey);

if ($customersApiResult["error"]) {
    echo json_encode([
        "success" => false,
        "error" => $customersApiResult["error"]
    ]);
    exit;
}

if ($customersApiResult["httpCode"] !== 200) {
    echo json_encode([
        "success" => false,
        "httpCode" => $customersApiResult["httpCode"],
        "response" => $customersApiResult["response"]
    ]);
    exit;
}

// Parse JSON response to get customer IDs
$customersData = json_decode($customersApiResult["response"], true);

if (!isset($customersData['customers']) || !is_array($customersData['customers'])) {
    echo json_encode([
        "success" => false,
        "error" => "Unexpected response format for customers"
    ]);
    exit;
}

// For each customer ID, call detailed customer API to get full info
$customersList = [];
foreach ($customersData['customers'] as $customer) {
    $id = isset($customer['id']) ? intval($customer['id']) : null;
    if ($id === null) {
        continue;
    }

    $customerDetailUrl = "$apiBaseUrl/api/customers/$id";
    $customerDetailResult = callPrestaShopApi($customerDetailUrl, $apiKey, "XML");

    if ($customerDetailResult["error"] || $customerDetailResult["httpCode"] !== 200) {
        // Skip this customer on error
        continue;
    }

    $customerXml = simplexml_load_string($customerDetailResult["response"]);
    if (!$customerXml || !isset($customerXml->customer)) {
        continue;
    }

    $customerNode = $customerXml->customer;
    $firstname = isset($customerNode->firstname) ? (string)$customerNode->firstname : "";
    $lastname = isset($customerNode->lastname) ? (string)$customerNode->lastname : "";
    $dateAdd = isset($customerNode->date_add) ? (string)$customerNode->date_add : "";
    $genderId = isset($customerNode->id_gender) ? intval($customerNode->id_gender) : 0;
    $gender = ($genderId === 1) ? "Homme" : (($genderId === 2) ? "Femme" : "unknown");

    // Fetch orders for this customer to get order IDs
    $ordersApiUrlForCustomer = "$apiBaseUrl/api/orders?filter[id_customer]=$id";
    $ordersApiResultForCustomer = callPrestaShopApi($ordersApiUrlForCustomer, $apiKey);

    $orderIds = [];
    if (!$ordersApiResultForCustomer["error"] && $ordersApiResultForCustomer["httpCode"] === 200) {
        $ordersDataForCustomer = json_decode($ordersApiResultForCustomer["response"], true);
        if (isset($ordersDataForCustomer['orders']) && is_array($ordersDataForCustomer['orders'])) {
            foreach ($ordersDataForCustomer['orders'] as $order) {
                if (isset($order['id'])) {
                    $orderIds[] = intval($order['id']);
                }
            }
        }
    }

    $customersList[] = [
        "id" => $id,
        "firstname" => $firstname,
        "lastname" => $lastname,
        "date_add" => $dateAdd,
        "gender" => $gender,
        "order_ids" => $orderIds
    ];
}

// Return JSON response
echo json_encode([
    "success" => true,
    "customers" => $customersList
]);
