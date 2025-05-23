<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

include 'config.php';

// Fonction pour appeler l'API PrestaShop
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

function getProductDetails($productId) {
    global $apiBaseUrl, $apiKey;

    if (!$productId) {
        return [
            "success" => false,
            "error" => "ID de produit non spécifié pour getProductDetails"
        ];
    }

    // Appeler l'API pour obtenir les détails du produit
    $productApiUrl = "$apiBaseUrl/products/$productId";
    $productApiResult = callPrestaShopApi($productApiUrl, $apiKey);

    if ($productApiResult["error"]) {
        return [
            "success" => false,
            "error" => "Erreur cURL pour produit $productId: " . $productApiResult["error"]
        ];
    }

    if ($productApiResult["httpCode"] !== 200) {
        return [
            "success" => false,
            "httpCode" => $productApiResult["httpCode"],
            "response" => $productApiResult["response"],
            "error" => "Erreur API pour produit $productId (Code: {$productApiResult["httpCode"]})"
        ];
    }

    // Transformation XML en objet
    $xml = simplexml_load_string($productApiResult["response"]);
    if (!$xml || !isset($xml->product)) { // Vérifier si la structure <product> existe
        return [
            "success" => false,
            "error" => "Erreur de parsing XML du produit $productId ou structure XML inattendue",
            "response_received" => $productApiResult["response"] // Utile pour le débogage
        ];
    }

    $product = $xml->product;

    // Récupérer le nom du fabricant
    $manufacturerName = "";
    if (isset($product->id_manufacturer) && (int)$product->id_manufacturer > 0) { // Vérifier si > 0
        $manufacturerId = (int)$product->id_manufacturer;
        $manufacturerApiUrl = "$apiBaseUrl/manufacturers/$manufacturerId";
        $manufacturerApiResult = callPrestaShopApi($manufacturerApiUrl, $apiKey);
        
        if (!$manufacturerApiResult["error"] && $manufacturerApiResult["httpCode"] === 200) {
            $manufacturerXml = simplexml_load_string($manufacturerApiResult["response"]);
            if ($manufacturerXml && isset($manufacturerXml->manufacturer->name)) {
                $manufacturerName = (string)$manufacturerXml->manufacturer->name;
            }
        }
    }

    // Récupérer les données de la catégorie par défaut
    $categoryName = "";
    $categoryImage = ""; // Note: l'image de catégorie n'est pas directement disponible via l'API pour le produit
    $categoryDatetime = "";
    if (isset($product->id_category_default) && (int)$product->id_category_default > 0) { // Vérifier si > 0
        $categoryId = (int)$product->id_category_default;
        $categoryApiUrl = "$apiBaseUrl/categories/$categoryId";
        $categoryApiResult = callPrestaShopApi($categoryApiUrl, $apiKey);
        
        if (!$categoryApiResult["error"] && $categoryApiResult["httpCode"] === 200) {
            $categoryXml = simplexml_load_string($categoryApiResult["response"]);
            if ($categoryXml && isset($categoryXml->category)) {
                if (isset($categoryXml->category->name->language)) {
                    $categoryNameNode = $categoryXml->category->name->xpath('language[@id="1"]'); // Supposons que la langue 1 est le français
                    if (!empty($categoryNameNode)) {
                        $categoryName = (string)$categoryNameNode[0];
                    } else if (isset($categoryXml->category->name->language[0])) { // Fallback si l'ID de langue n'est pas spécifié
                         $categoryName = (string)$categoryXml->category->name->language[0];
                    }
                }
                if (isset($categoryXml->category->date_add)) {
                    $categoryDatetime = (string)$categoryXml->category->date_add;
                }
            }
        }
    }

    // Récupérer les URL des images
    $imageUrls = [];
    if (isset($product->associations->images->image)) {
        foreach ($product->associations->images->image as $imageNode) {
            if (isset($imageNode->id)) { // Assurez-vous que l'ID existe
                 $imageId = (int)$imageNode->id;
                 // Construction correcte de l'URL de l'image (exemple pour PrestaShop 1.7+)
                 // L'URL de l'image est généralement $apiBaseUrl/images/products/$productId/$imageId
                 // ou via le dossier /img/p/
                 $imageUrl = "http://localhost:8080/img/p/" . implode('/', str_split((string)$productId)) . "/" . $productId . "-" . $imageId . ".jpg"; //  Plus précis, mais nécessite de connaître le format du nom de fichier.
                                                                                                                                     // Alternative plus simple si l'ID de l'image est globalement unique:
                 $imageUrlSimple = "http://localhost:8080/img/p/" . implode('/', str_split((string)$imageId)) . "/$imageId.jpg";
                 // On va essayer l'URL via l'API Images, c'est plus fiable si activé
                 $imageUrlApi = "$apiBaseUrl/images/products/$productId/$imageId";
                 // Pour cet exemple, on garde la structure simple, mais l'API image est meilleure
                 $imageUrls[] = $imageUrlSimple; 
            }
        }
    }
     // S'il n'y a pas d'images dans les associations, essayez de construire l'image de couverture par défaut
    if (empty($imageUrls) && isset($product->id_default_image) && (int)$product->id_default_image > 0) {
        $defaultImageId = (int)$product->id_default_image;
        $imageUrls[] = "http://localhost:8080/img/p/" . implode('/', str_split((string)$defaultImageId)) . "/$defaultImageId.jpg";
    }


    $productImage = "";
    if (!empty($imageUrls)) {
        $imageUrl = $imageUrls[0];
        // Fetch the image content from the URL
        $imageContent = @file_get_contents($imageUrl);
        if ($imageContent !== false) {
            // Encode the image content in base64 with data URI prefix
            $productImage = 'data:image/jpeg;base64,' . base64_encode($imageContent);
        } else {
            // Fallback to URL if image content cannot be fetched
            $productImage = $imageUrl;
        }
    } else {
        $productImage = "http://localhost:8080/img/p/default-image.jpg"; // Fournir une image par défaut
    }

    // Extraire les noms et descriptions pour la langue par défaut (supposons ID 1 pour le français)
    $productName = "";
    if (isset($product->name->language)) {
        $nameNode = $product->name->xpath('language[@id="1"]');
        if (!empty($nameNode)) $productName = (string)$nameNode[0];
        else if (isset($product->name->language[0])) $productName = (string)$product->name->language[0];
    }

    $productDesc = "";
    if (isset($product->description->language)) {
        $descNode = $product->description->xpath('language[@id="1"]');
        if (!empty($descNode)) $productDesc = (string)$descNode[0];
        else if (isset($product->description->language[0])) $productDesc = (string)$product->description->language[0];
    }
    
    $descriptionShort = "";
    if (isset($product->description_short->language)) {
        $shortDescNode = $product->description_short->xpath('language[@id="1"]');
        if (!empty($shortDescNode)) $descriptionShort = (string)$shortDescNode[0];
        else if (isset($product->description_short->language[0])) $descriptionShort = (string)$product->description_short->language[0];
    }

    $availableNow = "";
    if (isset($product->available_now->language)) {
        $availNowNode = $product->available_now->xpath('language[@id="1"]');
        if (!empty($availNowNode)) $availableNow = (string)$availNowNode[0];
        else if (isset($product->available_now->language[0])) $availableNow = (string)$product->available_now->language[0];
    }
    
    $availableLater = "";
    if (isset($product->available_later->language)) {
        $availLaterNode = $product->available_later->xpath('language[@id="1"]');
        if (!empty($availLaterNode)) $availableLater = (string)$availLaterNode[0];
        else if (isset($product->available_later->language[0])) $availableLater = (string)$product->available_later->language[0];
    }

// Fetch stock available quantity for the product
    $stockQuantity = 0;
    $stockApiUrl = "$apiBaseUrl/stock_availables/$productId";
    //echo ($stockApiUrl);
    $stockApiResult = callPrestaShopApi($stockApiUrl, $apiKey);
    if ($stockApiResult["httpCode"] === 200) {
        $stockXml = simplexml_load_string($stockApiResult["response"]);
       // echo json_encode($stockXml);
        if ($stockXml && isset($stockXml->stock_available)) {
            // Directly get the quantity from the single stock_available element
            $stockQuantity = (int)$stockXml->stock_available->quantity;
        }
    }
    
    $productData = [
        "productId" => (int)$product->id,
        "productName" => $productName,
        "productDesc" => $productDesc,
        "productImage" => $productImage,
        "productCount" => $stockQuantity,
        "productActive" => isset($product->active) ? ((int)$product->active == 1) : false,
        "productPrice" => isset($product->price) ? (float)$product->price : 0.0,
        "productDate" => isset($product->date_add) ? (string)$product->date_add : "",
        "productCat" => isset($product->id_category_default) ? (int)$product->id_category_default : null,
        "categoriesId" => isset($product->id_category_default) ? (int)$product->id_category_default : null,
        "categoriesName" => $categoryName,
        "categoriesImage" => $categoryImage,
        "categoriesDatetime" => $categoryDatetime,
        "reference" => isset($product->reference) ? (string)$product->reference : "",
        "condition" => isset($product->condition) ? (string)$product->condition : "",
        "manufacturer" => $manufacturerName,
        "weight" => isset($product->weight) ? (float)$product->weight : 0.0,
        "available_now" => $availableNow,
        "available_later" => $availableLater,
        "description_short" => $descriptionShort,
        "additional_shipping_cost" => isset($product->additional_shipping_cost) ? (float)$product->additional_shipping_cost : 0.0,
        "wholesale_price" => isset($product->wholesale_price) ? (float)$product->wholesale_price : 0.0,
        "unity" => isset($product->unity) ? (string)$product->unity : "",
        "allImages" => $imageUrls
    ];

    return [
        "success" => true,
        "product" => $productData
    ];
}

// Fonction principale pour obtenir les détails de tous les produits
function getAllProductDetails() {
    global $apiBaseUrl, $apiKey;
    $allProductsDetails = [];
    $errorsEncountered = [];

    // 1. Obtenir la liste de tous les ID de produits
    // On demande seulement les IDs pour alléger la première requête
    $productsListUrl = "$apiBaseUrl/products?display=[id]"; 
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

// Exécuter la fonction principale
$finalResult = getAllProductDetails();
echo json_encode($finalResult);

?>