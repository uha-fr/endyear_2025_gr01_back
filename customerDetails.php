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

function getCountryName($countryId, $apiBaseUrl, $apiKey) {
    $countryApiUrl = "$apiBaseUrl/api/countries/$countryId";
    $countryApiResult = callPrestaShopApi($countryApiUrl, $apiKey);

    if ($countryApiResult["error"] || $countryApiResult["httpCode"] !== 200) {
        return "";
    }

    $countryXml = simplexml_load_string($countryApiResult["response"]);
    if (!$countryXml || !isset($countryXml->country->name->language)) {
        return "";
    }

    return (string)$countryXml->country->name->language;
}

function getAllAddresses($apiBaseUrl, $apiKey) {
    $addressesApiUrl = "$apiBaseUrl/api/addresses";
    $addressesApiResult = callPrestaShopApi($addressesApiUrl, $apiKey);

    if ($addressesApiResult["error"] || $addressesApiResult["httpCode"] !== 200) {
        return [];
    }

    $addressesXml = simplexml_load_string($addressesApiResult["response"]);
    if (!$addressesXml || !isset($addressesXml->addresses->address)) {
        return [];
    }

    $addresses = [];
    foreach ($addressesXml->addresses->address as $address) {
        $addresses[] = [
            "id" => (int)$address['id'],
            "href" => (string)$address['xlink:href']
        ];
    }
    return $addresses;
}

function getAddressDetails($addressId, $apiBaseUrl, $apiKey) {
    $addressApiUrl = "$apiBaseUrl/api/addresses/$addressId";
    $addressApiResult = callPrestaShopApi($addressApiUrl, $apiKey);

    if ($addressApiResult["error"] || $addressApiResult["httpCode"] !== 200) {
        return null;
    }

    $addressXml = simplexml_load_string($addressApiResult["response"]);
    if (!$addressXml || !isset($addressXml->address)) {
        return null;
    }

    $address = $addressXml->address;

    $countryName = "";
    if (isset($address->id_country) && !empty($address->id_country)) {
        $countryName = getCountryName((int)$address->id_country, $apiBaseUrl, $apiKey);
    }

    return [
        "id" => (int)$address->id,
        "alias" => (string)$address->alias,
        "address1" => (string)$address->address1,
        "address2" => (string)$address->address2,
        "postcode" => (string)$address->postcode,
        "city" => (string)$address->city,
        "country" => $countryName,
        "phone" => (string)$address->phone,
    ];
}

$ordersApiUrl = "$apiBaseUrl:8080/endyear_2025_gr01_back/orders.php";
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

// Get all addresses
$allAddresses = getAllAddresses($apiBaseUrl, $apiKey);

// Filter addresses for current customer
$customerAddresses = [];
foreach ($allAddresses as $addr) {
    $addressDetails = getAddressDetails($addr['id'], $apiBaseUrl, $apiKey);
    if ($addressDetails && isset($addressDetails['id'])) {
        // Check if address belongs to current customer
        $addressApiUrl = "$apiBaseUrl/api/addresses/" . $addressDetails['id'];
        $addressApiResult = callPrestaShopApi($addressApiUrl, $apiKey);
        if ($addressApiResult["error"] || $addressApiResult["httpCode"] !== 200) {
            continue;
        }
        $addressXml = simplexml_load_string($addressApiResult["response"]);
        if (!$addressXml || !isset($addressXml->address->id_customer)) {
            continue;
        }
        $idCustomerInAddress = (int)$addressXml->address->id_customer;
        if ($idCustomerInAddress === $customerId) {
            $customerAddresses[] = $addressDetails;
        }
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
        "gender" => (intval($customerData->id_gender) === 1) ? "Homme" : ((intval($customerData->id_gender) === 2) ? "Femme" : "unknown"),
        "birthday" => (string)$customerData->birthday,
        "active" => intval($customerData->active),
        "date_add" => (string)$customerData->date_add,
        "date_upd" => (string)$customerData->date_upd,
        "addresses" => $customerAddresses
    ],
    "order_ids" => $customerOrderIds
]);
