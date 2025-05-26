<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

include 'config.php';

// Function to call produitDetails.php and get product details
function getProductDetails($productId) {
    if (!$productId) {
        return [
            "success" => false,
            "error" => "ID de produit non spécifié"
        ];
    }

    $url = "http://localhost/xampp/endyear_2025_gr01_back/produitDetails.php?id=" . urlencode($productId);

    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array(
            "Accept: application/json"
        )
    ));

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_errno($curl) ? curl_error($curl) : null;

    curl_close($curl);

    if ($error) {
        return [
            "success" => false,
            "error" => "Erreur cURL: " . $error
        ];
    }

    if ($httpCode !== 200) {
        return [
            "success" => false,
            "httpCode" => $httpCode,
            "error" => "Erreur HTTP lors de l'appel à produitDetails.php (Code: $httpCode)"
        ];
    }

    $data = json_decode($response, true);
    if ($data === null) {
        return [
            "success" => false,
            "error" => "Erreur de décodage JSON de la réponse de produitDetails.php"
        ];
    }

    return $data;
}

// Fonction principale pour obtenir les détails de tous les produits
function getAllProductDetails() {
    global $apiBaseUrl, $apiKey;
    $allProductsDetails = [];
    $errorsEncountered = [];

    // 1. Obtenir la liste de tous les ID de produits
    // On demande seulement les IDs pour alléger la première requête
    $productsListUrl = "$apiBaseUrl/api/products?display=[id]"; 
    $productListResult = callPrestaShopApi($productsListUrl, $apiKey);

    if ($productListResult["error"]) {
        return [
            "success" => false,
            "error" => "Erreur cURL en récupérant la liste des produits: " . $productListResult["error"]
        ];
    }

    if ($productListResult["httpCode"] !== 200) {
        return [
            "success" => false,
            "httpCode" => $productListResult["httpCode"],
            "error" => "Erreur API en récupérant la liste des produits (Code: {$productListResult["httpCode"]})",
            "response" => $productListResult["response"]
        ];
    }

    $xmlProductList = simplexml_load_string($productListResult["response"]);
    if (!$xmlProductList || !isset($xmlProductList->products->product)) {
        // Il se peut qu'il n'y ait aucun produit, ce n'est pas nécessairement une erreur de parsing
        if (isset($xmlProductList->products) && count($xmlProductList->products->children()) == 0) {
             return [
                "success" => true,
                "message" => "Aucun produit trouvé.",
                "products" => []
            ];
        }
        return [
            "success" => false,
            "error" => "Erreur de parsing XML de la liste des produits ou structure inattendue.",
            "response_received" => $productListResult["response"]
        ];
    }

    // 2. Itérer sur chaque ID et obtenir les détails
    foreach ($xmlProductList->products->product as $productNode) {
        $productId = (int)$productNode->id;
        if ($productId > 0) {
            $detailResult = getProductDetails($productId);
            if ($detailResult["success"]) {
                $allProductsDetails[] = $detailResult["product"];
            } else {
                // Enregistrer l'erreur pour ce produit spécifique mais continuer avec les autres
                $errorsEncountered[] = [
                    "productId" => $productId,
                    "error" => $detailResult["error"] ?? "Erreur inconnue",
                    "httpCode" => $detailResult["httpCode"] ?? null,
                    "response" => $detailResult["response"] ?? null
                ];
            }
        }
    }

    if (empty($allProductsDetails) && !empty($errorsEncountered)) {
        return [
            "success" => false,
            "error" => "Échec de la récupération des détails pour tous les produits.",
            "detailed_errors" => $errorsEncountered
        ];
    }
    
    return [
        "success" => true,
        "products" => $allProductsDetails,
        "fetch_errors" => $errorsEncountered // Inclut les erreurs pour les produits individuels si certains ont échoué
    ];
}

// Function to call PrestaShop API
function callPrestaShopApi($url, $apiKey) {
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => "$apiKey:",
        CURLOPT_HTTPHEADER => array(
            "Accept: application/xml", // Nous allons travailler avec XML pour la cohérence
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

// Exécuter la fonction principale
$finalResult = getAllProductDetails();
echo json_encode($finalResult);

?>
