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

// Fetch all orders
$ordersApiUrl = "$apiBaseUrl/orders";
$ordersApiResult = callPrestaShopApi($ordersApiUrl, $apiKey);

if ($ordersApiResult["error"] || $ordersApiResult["httpCode"] !== 200) {
    echo json_encode([
        "success" => false,
        "error" => $ordersApiResult["error"] ?? "Failed to fetch orders",
        "httpCode" => $ordersApiResult["httpCode"]
    ]);
    exit;
}

$ordersData = json_decode($ordersApiResult["response"], true);
if (!isset($ordersData['orders']) || !is_array($ordersData['orders'])) {
    echo json_encode([
        "success" => false,
        "error" => "Unexpected orders response format"
    ]);
    exit;
}

// Fetch all customers
$customersApiUrl = "$apiBaseUrl/customers";
$customersApiResult = callPrestaShopApi($customersApiUrl, $apiKey);

if ($customersApiResult["error"] || $customersApiResult["httpCode"] !== 200) {
    echo json_encode([
        "success" => false,
        "error" => $customersApiResult["error"] ?? "Failed to fetch customers",
        "httpCode" => $customersApiResult["httpCode"]
    ]);
    exit;
}

$customersData = json_decode($customersApiResult["response"], true);
if (!isset($customersData['customers']) || !is_array($customersData['customers'])) {
    echo json_encode([
        "success" => false,
        "error" => "Unexpected customers response format"
    ]);
    exit;
}

// Prepare customer details with date_add
$customersList = [];
foreach ($customersData['customers'] as $customer) {
    $id = isset($customer['id']) ? intval($customer['id']) : null;
    if ($id === null) continue;

    // Fetch detailed customer info (date_add) via XML
    $customerDetailUrl = "$apiBaseUrl/customers/$id";
    $customerDetailResult = callPrestaShopApi($customerDetailUrl, $apiKey, "XML");
    if ($customerDetailResult["error"] || $customerDetailResult["httpCode"] !== 200) continue;

    $customerXml = simplexml_load_string($customerDetailResult["response"]);
    if (!$customerXml || !isset($customerXml->customer)) continue;

    $dateAdd = isset($customerXml->customer->date_add) ? (string)$customerXml->customer->date_add : null;

    $customersList[$id] = [
        "id" => $id,
        "date_add" => $dateAdd
    ];
}

// Initialize metrics
$totalRevenue = 0.0;
$orderCount = 0;
$revenueByDay = [];
$revenueByMonth = [];
$ordersByCustomer = [];
$orderStatusCounts = [];
$productSales = []; // product_id => ['quantity' => int, 'revenue' => float, 'name' => string]

// Helper function to fetch order details and products
function getOrderDetails($orderId, $apiBaseUrl, $apiKey) {
    $url = "$apiBaseUrl/orders/$orderId";
    $result = callPrestaShopApi($url, $apiKey, "XML");
    if ($result["error"] || $result["httpCode"] !== 200) return null;

    $xml = simplexml_load_string($result["response"]);
    if (!$xml || !isset($xml->order)) return null;

    $order = $xml->order;
    $products = [];

    if (isset($order->associations->order_rows->order_row)) {
        foreach ($order->associations->order_rows->order_row as $orderRow) {
            $productId = (int)$orderRow->product_id;
            $quantity = (int)$orderRow->product_quantity;
            $price = (float)$orderRow->unit_price_tax_incl ?? 0.0;
            $productName = "";

            // Fetch product name
            $productApiUrl = "$apiBaseUrl/products/$productId";
            $productApiResult = callPrestaShopApi($productApiUrl, $apiKey, "XML");
            if (!$productApiResult["error"] && $productApiResult["httpCode"] === 200) {
                $productXml = simplexml_load_string($productApiResult["response"]);
                if ($productXml && isset($productXml->product->name->language)) {
                    $productName = (string)$productXml->product->name->language;
                }
            }

            $products[] = [
                "product_id" => $productId,
                "product_name" => $productName,
                "quantity" => $quantity,
                "price" => $price
            ];
        }
    }

    return $products;
}

// Process each order
foreach ($ordersData['orders'] as $orderItem) {
    if (!isset($orderItem['id'])) continue;
    $orderId = $orderItem['id'];

    // Fetch detailed order info
    $orderDetailUrl = "$apiBaseUrl/orders/$orderId";
    $orderDetailResult = callPrestaShopApi($orderDetailUrl, $apiKey);
    if ($orderDetailResult["error"] || $orderDetailResult["httpCode"] !== 200) continue;

    $orderDetail = json_decode($orderDetailResult["response"], true);
    if (!isset($orderDetail['order'])) continue;

    $order = $orderDetail['order'];

    $totalPaid = isset($order['total_paid_tax_incl']) ? (float)$order['total_paid_tax_incl'] : 0.0;
    $dateAdd = isset($order['date_add']) ? $order['date_add'] : null;
    $customerId = isset($order['id_customer']) ? (int)$order['id_customer'] : null;
    $currentState = isset($order['current_state']) ? $order['current_state'] : null;

    // Aggregate total revenue and order count
    $totalRevenue += $totalPaid;
    $orderCount++;

    // Revenue trends by day and month
    if ($dateAdd) {
        $day = substr($dateAdd, 0, 10);
        $month = substr($dateAdd, 0, 7);
        if (!isset($revenueByDay[$day])) $revenueByDay[$day] = 0.0;
        if (!isset($revenueByMonth[$month])) $revenueByMonth[$month] = 0.0;
        $revenueByDay[$day] += $totalPaid;
        $revenueByMonth[$month] += $totalPaid;
    }

    // Count orders per customer for repeat customer rate
    if ($customerId !== null) {
        if (!isset($ordersByCustomer[$customerId])) $ordersByCustomer[$customerId] = 0;
        $ordersByCustomer[$customerId]++;
    }

    // Count orders by status
    if ($currentState !== null) {
        if (!isset($orderStatusCounts[$currentState])) $orderStatusCounts[$currentState] = 0;
        $orderStatusCounts[$currentState]++;
    }

    // Aggregate product sales
    $products = getOrderDetails($orderId, $apiBaseUrl, $apiKey);
    if ($products !== null) {
        foreach ($products as $product) {
            $pid = $product['product_id'];
            if (!isset($productSales[$pid])) {
                $productSales[$pid] = [
                    "quantity" => 0,
                    "revenue" => 0.0,
                    "name" => $product['product_name']
                ];
            }
            $productSales[$pid]['quantity'] += $product['quantity'];
            $productSales[$pid]['revenue'] += $product['quantity'] * $product['price'];
        }
    }
}

// Calculate Average Order Value (AOV)
$aov = $orderCount > 0 ? $totalRevenue / $orderCount : 0.0;

// Calculate Repeat Customer Rate
$totalCustomers = count($customersList);
$repeatCustomers = 0;
foreach ($ordersByCustomer as $custId => $count) {
    if ($count > 1) $repeatCustomers++;
}
$repeatCustomerRate = $totalCustomers > 0 ? ($repeatCustomers / $totalCustomers) * 100 : 0.0;

// Prepare order status names by fetching from API
$orderStateNames = [];
foreach (array_keys($orderStatusCounts) as $stateId) {
    $stateApiUrl = "$apiBaseUrl/order_states/$stateId";
    $stateApiResult = callPrestaShopApi($stateApiUrl, $apiKey, "XML");
    if (!$stateApiResult["error"] && $stateApiResult["httpCode"] === 200) {
        $stateXml = simplexml_load_string($stateApiResult["response"]);
        if ($stateXml && isset($stateXml->order_state->name->language)) {
            $orderStateNames[$stateId] = (string)$stateXml->order_state->name->language;
        }
    }
}

// Prepare customer acquisition growth by date (daily)
$customerAcquisition = [];
foreach ($customersList as $customer) {
    $dateAdd = $customer['date_add'];
    if ($dateAdd) {
        $day = substr($dateAdd, 0, 10);
        if (!isset($customerAcquisition[$day])) $customerAcquisition[$day] = 0;
        $customerAcquisition[$day]++;
    }
}

// Sort top performing products by revenue descending
usort($productSales, function($a, $b) {
    return $b['revenue'] <=> $a['revenue'];
});

// Limit top products to top 10
$topProducts = array_slice($productSales, 0, 10);

// Output final statistics
echo json_encode([
    "success" => true,
    "revenu_total" => $totalRevenue,
    "tendances_revenu" => [
        "quotidien" => $revenueByDay,
        "mensuel" => $revenueByMonth
    ],
    "valeur_moyenne_commande" => $aov,
    "acquisition_clients" => $customerAcquisition,
    "meilleurs_produits" => $topProducts,
    "taux_clients_fideles" => $repeatCustomerRate,
    "conversion_commandes_par_statut" => array_map(function($stateId) use ($orderStatusCounts, $orderStateNames) {
        return [
            "id_statut" => $stateId,
            "nom_statut" => $orderStateNames[$stateId] ?? "Inconnu",
            "nombre_commandes" => $orderStatusCounts[$stateId]
        ];
    }, array_keys($orderStatusCounts))
]);
?>
