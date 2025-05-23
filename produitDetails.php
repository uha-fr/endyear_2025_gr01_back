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

function getProductDetails($productId) {
    global $apiBaseUrl, $apiKey;

    if (!$productId) {
        return [
            "success" => false,
            "error" => "ID de produit non spécifié"
        ];
    }

    // Appeler l'API pour obtenir les détails du produit
    $productApiUrl = "$apiBaseUrl/products/$productId";
    $productApiResult = callPrestaShopApi($productApiUrl, $apiKey);

    if ($productApiResult["error"]) {
        return [
            "success" => false,
            "error" => $productApiResult["error"]
        ];
    }

    if ($productApiResult["httpCode"] !== 200) {
        return [
            "success" => false,
            "httpCode" => $productApiResult["httpCode"],
            "response" => $productApiResult["response"]
        ];
    }

    // Transformation XML en objet
    $xml = simplexml_load_string($productApiResult["response"]);
    if (!$xml) {
        return [
            "success" => false,
            "error" => "Erreur de parsing XML du produit"
        ];
    }

    // Extraction des données du produit
    $product = $xml->product;

    // Récupérer le nom du fabricant
    $manufacturerName = "";
    if (isset($product->id_manufacturer) && !empty($product->id_manufacturer)) {
        $manufacturerId = (int)$product->id_manufacturer;
        $manufacturerApiUrl = "$apiBaseUrl/manufacturers/$manufacturerId";
        
        $manufacturerApiResult = callPrestaShopApi($manufacturerApiUrl, $apiKey);
        
        if (!$manufacturerApiResult["error"] && $manufacturerApiResult["httpCode"] === 200) {
            $manufacturerXml = simplexml_load_string($manufacturerApiResult["response"]);
            if ($manufacturerXml) {
                $manufacturerName = (string)$manufacturerXml->manufacturer->name;
            }
        }
    }

    // Récupérer les données de la catégorie par défaut
    $categoryName = "";
    $categoryImage = "";
    $categoryDatetime = "";
    if (isset($product->id_category_default) && !empty($product->id_category_default)) {
        $categoryId = (int)$product->id_category_default;
        $categoryApiUrl = "$apiBaseUrl/categories/$categoryId";
        
        $categoryApiResult = callPrestaShopApi($categoryApiUrl, $apiKey);
        
        if (!$categoryApiResult["error"] && $categoryApiResult["httpCode"] === 200) {
            $categoryXml = simplexml_load_string($categoryApiResult["response"]);
            if ($categoryXml) {
                if (isset($categoryXml->category->name->language)) {
                    $categoryName = (string)$categoryXml->category->name->language;
                }
                $categoryDatetime = (string)$categoryXml->category->date_add;
                // Note: l'image de catégorie n'est pas directement disponible dans l'API PrestaShop par défaut
            }
        }
    }

    // Récupérer les URL des images
    $imageUrls = [];
    if (isset($xml->product->associations->images->image)) {
        foreach ($xml->product->associations->images->image as $image) {
            $imageId = (int)$image->id;
            $imageUrl = "http://localhost:8080/img/p/" . implode('/', str_split((string)$imageId)) . "/$imageId.jpg";
            $imageUrls[] = $imageUrl;
        }
    }

    // URL de l'image par défaut
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

    // Mapper les données selon votre modèle Flutter
    $productData = [
        "productId" => (int)$product->id,
        "productName" => (string)$product->name->language,
        "productDesc" => (string)$product->description->language,
        "productImage" => $productImage,
        "productCount" => $stockQuantity,
        "productActive" => ((int)$product->active == 1),
        "productPrice" => (float)$product->price,
        "productDate" => (string)$product->date_add,
        "productCat" => (int)$product->id_category_default,
        "categoriesId" => (int)$product->id_category_default,
        "categoriesName" => $categoryName,
        "categoriesImage" => $categoryImage, // Généralement vide car pas fourni directement par l'API
        "categoriesDatetime" => $categoryDatetime,
        // Informations supplémentaires utiles
        "reference" => (string)$product->reference,
        "condition" => (string)$product->condition,
        "manufacturer" => $manufacturerName,
        "weight" => (float)$product->weight,
        "available_now" => (string)$product->available_now->language,
        "available_later" => (string)$product->available_later->language,
        "description_short" => (string)$product->description_short->language,
        "additional_shipping_cost" => (float)$product->additional_shipping_cost,
        "wholesale_price" => (float)$product->wholesale_price,
        "unity" => (string)$product->unity,
        "allImages" => $imageUrls
    ];

    return [
        "success" => true,
        "product" => $productData
    ];
}

// Récupérer l'ID du produit depuis la requête
$productId = isset($_GET['id']) ? intval($_GET['id']) : null;

if (!$productId) {
    echo json_encode([
        "success" => false,
        "error" => "ID de produit non spécifié"
    ]);
    exit;
}

$result = getProductDetails($productId);

echo json_encode($result);
