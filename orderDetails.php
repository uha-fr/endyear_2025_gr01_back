<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

include 'config.php';

// Récupérer l'ID de la commande depuis la requête
$orderId = isset($_GET['id']) ? intval($_GET['id']) : null;

if (!$orderId) {
    echo json_encode([
        "success" => false,
        "error" => "ID de commande non spécifié"
    ]);
    exit;
}
 

// Fonction pour appeler l'API PrestaShop
function callPrestaShopApi($url, $apiKey) {
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => "$apiKey:",
        CURLOPT_HTTPHEADER => array(
            "Accept: application/xml",
            "Output-Format: XML"
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

// Appeler l'API pour obtenir les détails de la commande
$orderApiUrl = "$apiBaseUrl/orders/$orderId";
$orderApiResult = callPrestaShopApi($orderApiUrl, $apiKey);

if ($orderApiResult["error"]) {
    echo json_encode([
        "success" => false,
        "error" => $orderApiResult["error"]
    ]);
    exit;
}

if ($orderApiResult["httpCode"] !== 200) {
    echo json_encode([
        "success" => false,
        "httpCode" => $orderApiResult["httpCode"],
        "response" => $orderApiResult["response"]
    ]);
    exit;
}

// Transformation XML en objet
$xml = simplexml_load_string($orderApiResult["response"]);
if (!$xml) {
    echo json_encode([
        "success" => false,
        "error" => "Erreur de parsing XML de la commande"
    ]);
    exit;
}

// Extraction des données pertinentes selon le modèle OrderModel de Flutter
$order = $xml->order;

// Récupérer le nom du client
$customerName = "";
if (isset($order->id_customer) && !empty($order->id_customer)) {
    $customerId = (int)$order->id_customer;
    $customerApiUrl = "$apiBaseUrl/customers/$customerId";
    
    $customerApiResult = callPrestaShopApi($customerApiUrl, $apiKey);
    
    if (!$customerApiResult["error"] && $customerApiResult["httpCode"] === 200) {
        $customerXml = simplexml_load_string($customerApiResult["response"]);
        if ($customerXml) {
            $firstname = (string)$customerXml->customer->firstname;
            $lastname = (string)$customerXml->customer->lastname;
            $customerName = trim($firstname . " " . $lastname);
        }
    }
}

// Récupérer le nom de l'état de la commande
$orderStateName = "";
if (isset($order->current_state) && !empty($order->current_state)) {
    $orderStateId = (int)$order->current_state;
    $orderStateApiUrl = "$apiBaseUrl/order_states/$orderStateId";
    
    $orderStateApiResult = callPrestaShopApi($orderStateApiUrl, $apiKey);
    
    if (!$orderStateApiResult["error"] && $orderStateApiResult["httpCode"] === 200) {
        $orderStateXml = simplexml_load_string($orderStateApiResult["response"]);
        if ($orderStateXml && isset($orderStateXml->order_state->name->language)) {
            $orderStateName = (string)$orderStateXml->order_state->name->language;
        }
    }
}

// Récupérer l'adresse de livraison
$deliveryAddress = null;
if (isset($order->id_address_delivery) && !empty($order->id_address_delivery)) {
    $deliveryAddressId = (int)$order->id_address_delivery;
    $addressApiUrl = "$apiBaseUrl/addresses/$deliveryAddressId";
    
    $addressApiResult = callPrestaShopApi($addressApiUrl, $apiKey);
    
    if (!$addressApiResult["error"] && $addressApiResult["httpCode"] === 200) {
        $addressXml = simplexml_load_string($addressApiResult["response"]);
        if ($addressXml) {
            $address = $addressXml->address;
            
            // Récupérer le nom du pays
            $countryName = "";
            if (isset($address->id_country) && !empty($address->id_country)) {
                $countryId = (int)$address->id_country;
                $countryApiUrl = "$apiBaseUrl/countries/$countryId";
                
                $countryApiResult = callPrestaShopApi($countryApiUrl, $apiKey);
                
                if (!$countryApiResult["error"] && $countryApiResult["httpCode"] === 200) {
                    $countryXml = simplexml_load_string($countryApiResult["response"]);
                    if ($countryXml && isset($countryXml->country->name->language)) {
                        $countryName = (string)$countryXml->country->name->language;
                    }
                }
            }
            
            $deliveryAddress = [
                "address1" => (string)$address->address1,
                "address2" => (string)$address->address2,
                "postcode" => (string)$address->postcode,
                "city" => (string)$address->city,
                "country" => $countryName
            ];
        }
    }
}

// Récupérer l'adresse de facturation
$invoiceAddress = null;
if (isset($order->id_address_invoice) && !empty($order->id_address_invoice)) {
    $invoiceAddressId = (int)$order->id_address_invoice;
    
    // Si l'adresse de facturation est différente de l'adresse de livraison
    if ($invoiceAddressId != (int)$order->id_address_delivery) {
        $addressApiUrl = "$apiBaseUrl/addresses/$invoiceAddressId";
        $addressApiResult = callPrestaShopApi($addressApiUrl, $apiKey);
        
        if (!$addressApiResult["error"] && $addressApiResult["httpCode"] === 200) {
            $addressXml = simplexml_load_string($addressApiResult["response"]);
            if ($addressXml) {
                $address = $addressXml->address;
                
                // Récupérer le nom du pays
                $countryName = "";
                if (isset($address->id_country) && !empty($address->id_country)) {
                    $countryId = (int)$address->id_country;
                    $countryApiUrl = "$apiBaseUrl/countries/$countryId";
                    
                    $countryApiResult = callPrestaShopApi($countryApiUrl, $apiKey);
                    
                    if (!$countryApiResult["error"] && $countryApiResult["httpCode"] === 200) {
                        $countryXml = simplexml_load_string($countryApiResult["response"]);
                        if ($countryXml && isset($countryXml->country->name->language)) {
                            $countryName = (string)$countryXml->country->name->language;
                        }
                    }
                }
                
                $invoiceAddress = [
                    "address1" => (string)$address->address1,
                    "address2" => (string)$address->address2,
                    "postcode" => (string)$address->postcode,
                    "city" => (string)$address->city,
                    "country" => $countryName
                ];
            }
        }
    } else {
        // Si c'est la même adresse, on réutilise celle déjà récupérée
        $invoiceAddress = $deliveryAddress;
    }
}

// Mapper les données selon le modèle OrderModel

function getProductDetails($productId, $apiBaseUrl, $apiKey) {
    $productApiUrl = "$apiBaseUrl/products/$productId";
    $productApiResult = callPrestaShopApi($productApiUrl, $apiKey);

    if ($productApiResult["error"] || $productApiResult["httpCode"] !== 200) {
        return [
            "product_name" => "",
            "product_price" => 0.0
        ];
    }

    $productXml = simplexml_load_string($productApiResult["response"]);
    if (!$productXml) {
        return [
            "product_name" => "",
            "product_price" => 0.0
        ];
    }

    $productName = "";
    $productPrice = 0.0;

    if (isset($productXml->product->name->language)) {
        $productName = (string)$productXml->product->name->language;
    }

    if (isset($productXml->product->price)) {
        $productPrice = (float)$productXml->product->price;
    }

    return [
        "product_name" => $productName,
        "product_price" => $productPrice
    ];
}

$products = [];
if (isset($order->associations->order_rows->order_row)) {
    foreach ($order->associations->order_rows->order_row as $orderRow) {
        $productId = (int)$orderRow->product_id;
        $productDetails = getProductDetails($productId, $apiBaseUrl, $apiKey);
        $products[] = [
            "product_id" => $productId,
            "product_name" => $productDetails["product_name"],
            "product_price" => $productDetails["product_price"],
            "product_quantity" => (int)$orderRow->product_quantity
        ];
    }
}

$orderData = [
    "id" => (int)$order->id,
    "reference" => (string)$order->reference,
    "idCustomer" => (int)$order->id_customer,
    "customerName" => $customerName,
    "currentStateName" => $orderStateName,
    "payment" => (string)$order->payment,
    "module" => (string)$order->module,
    "totalPaidTaxIncl" => (float)$order->total_paid_tax_incl,
    "dateAdd" => (string)$order->date_add,
    "dateUpd" => (string)$order->date_upd,
    "valid" => ((int)$order->valid == 1),
    "recyclable" => ((int)$order->recyclable == 1),
    "gift" => ((int)$order->gift == 1),
    "giftMessage" => (string)$order->gift_message,
    "deliveryAddress" => $deliveryAddress,
    "invoiceAddress" => $invoiceAddress,
    "products" => $products
];

// Réponse finale
echo json_encode([
    "success" => true,
    "order" => $orderData
]);