<?php
require_once __DIR__ . '/config.php';

// Disable execution time limit
set_time_limit(0);
ini_set('max_execution_time', 0);

// Hata ayıklama
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');

// Log fonksiyonu
function logMessage($message) {
    error_log(date('Y-m-d H:i:s') . " - " . $message . "\n", 3, __DIR__ . '/debug.log');
}

logMessage("İstek başladı");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    logMessage("Hata: POST değil");
    http_response_code(405);
    echo json_encode(['error' => 'Yalnızca POST desteklenir']);
    exit;
}

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    logMessage("Hata: Dosya yükleme hatası - " . print_r($_FILES, true));
    http_response_code(400);
    echo json_encode(['error' => 'Dosya bulunamadı veya yükleme hatası']);
    exit;
}

$uploadsDir = __DIR__ . '/uploads';
if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);

$originalName = $_FILES['image']['name'];
$tmpPath = $_FILES['image']['tmp_name'];
$ext = pathinfo($originalName, PATHINFO_EXTENSION);
$filename = uniqid('img_', true) . ($ext ? '.' . $ext : '');
$savePath = $uploadsDir . '/' . $filename;

if (!move_uploaded_file($tmpPath, $savePath)) {
    logMessage("Dosya kaydedilemedi");
    http_response_code(500);
    echo json_encode(['error' => 'Dosya kaydedilemedi']);
    exit;
}

// Public URL oluştur (dizin köküne göre ayarla)
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$fileUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . ($scriptDir === '' ? '' : $scriptDir) . '/uploads/' . $filename;
logMessage("Dosya yüklendi: " . $fileUrl);
// --- Yeni akış: önce PlantNet ile isim tespiti, sonra RapidAPI ile detaylı bilgi ---

logMessage("Başlangıç akışı: PlantNet -> RapidAPI (fallback: image)");

// PlantNet çağrısı (öncelikle PLANTNET_API_KEY'in tanımlı olduğundan emin olun)
$plantName = null;
if (defined('PLANTNET_API_KEY') && PLANTNET_API_KEY) {
    $pnUrl = 'https://my.plantnet.org/v2/identify/all?api-key=' . urlencode(PLANTNET_API_KEY);
    $pnPayload = [
        'images' => [$fileUrl]
        // 'organs' => ['leaf'] // isteğe bağlı
    ];
    $pnBody = json_encode($pnPayload);

    // Log PlantNet request summary (truncate to avoid huge logs)
    logMessage("PlantNet isteği oluşturuldu. URL: {$pnUrl}");
    logMessage("PlantNet payload preview: " . substr($pnBody, 0, 1000));

    $ch2 = curl_init($pnUrl);
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $pnBody,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10
    ]);

    $pnResp = curl_exec($ch2);
    $pnErr = curl_error($ch2);
    $pnCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);

    logMessage("PlantNet yanıt kodu: " . $pnCode);
    logMessage("PlantNet ham yanıt (ilk 2000 char): " . substr($pnResp ?? '', 0, 2000));

    if (!$pnErr && $pnResp) {
        $pnDecoded = json_decode($pnResp, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            // Deneysel: birden fazla format olabilir; birkaç olasılığı kontrol edelim
            if (!empty($pnDecoded['results']) && is_array($pnDecoded['results'])) {
                $first = $pnDecoded['results'][0] ?? null;
                // Çoklu olası alanlar
                if ($first) {
                    // Log a short summary of the first result
                    $firstSummary = [];
                    if (isset($first['score'])) $firstSummary['score'] = $first['score'];
                    if (!empty($first['species'])) $firstSummary['species'] = $first['species'];
                    logMessage('PlantNet first result summary: ' . json_encode($firstSummary, JSON_UNESCAPED_UNICODE));

                    // commonNames preview
                    if (!empty($first['species']['commonNames'])) {
                        $cn = $first['species']['commonNames'];
                        if (is_array($cn)) $cn = array_slice($cn,0,3);
                        logMessage('PlantNet commonNames preview: ' . json_encode($cn, JSON_UNESCAPED_UNICODE));
                    }

                    if (!empty($first['species']['scientificNameWithoutAuthor'])) {
                        $plantName = $first['species']['scientificNameWithoutAuthor'];
                    } elseif (!empty($first['species']['scientificName'])) {
                        $plantName = $first['species']['scientificName'];
                    } elseif (!empty($first['species']['commonNames'][0])) {
                        $plantName = $first['species']['commonNames'][0];
                    } elseif (!empty($first['species']['commonNames'])) {
                        $plantName = is_array($first['species']['commonNames']) ? $first['species']['commonNames'][0] : $first['species']['commonNames'];
                    }
                    logMessage('PlantNet extracted plantName: ' . ($plantName ?? 'null'));
                }
            }
        }
    } else {
        logMessage("PlantNet CURL hatası: " . $pnErr);
    }
}

// Eğer PlantNet bir isim verdiyse, RapidAPI'ye isimle soru sormayı dene; yoksa eski image-based fallback kullan
$rapidResponseText = null;
$rapidHttpCode = null;
$rapidErr = null;

// RapidAPI sadece varsa kullanılır
if (defined('RAPIDAPI_KEY') && RAPIDAPI_KEY) {
    $rapidUrl = 'https://chatgpt-vision1.p.rapidapi.com/matagvision2';

    // system ve user prompt'u, eğer plantName varsa ismi kullanacak şekilde düzenle
    $userText = '';
    if ($plantName) {
        $userText = "Bu bitki için detaylı bilgi ver. Bitkinin adı: {$plantName}. Lütfen sadece ve kesinlikle JSON formatında yanıtla ve Türkçe yaz. Format:\n{\"treeName\":\"<ağaç türü>\",\"regions\":\"<yaygın bölgeler>\",\"conditions\":\"<optimum yaşam koşulları>\",\"radius\":\"<gövde yarıçapı cm>\",\"age\":\"<tahmini yaş>\",\"confidence\":\"<güven skoru %>\"}";
        $userContent = [ ['type' => 'text', 'text' => $userText] ];
    } else {
        // fallback: gönderilen resimle aynı prompt'ı kullan
        $userText = "Bu görüntüyü analiz et ve sadece aşağıdaki anahtarlarla geçerli bir JSON döndür:\n{\"treeName\":\"<ağaç türü>\",\"regions\":\"<yaygın bölgeler>\",\"conditions\":\"<optimum yaşam koşulları>\",\"radius\":\"<gövde yarıçapı cm>\",\"age\":\"<tahmini yaş>\",\"confidence\":\"<güven skoru %>\"}\n\nEğer kesin bilgi yoksa alanı 'Belirtilmedi' olarak doldur. Sadece JSON döndür, başka hiçbir şey yazma.";
        $userContent = [ ['type' => 'text', 'text' => $userText], ['type' => 'image', 'url' => $fileUrl] ];
    }

    $body = [
        'messages' => [
            [ 'role' => 'system', 'content' => 'Sen bir ormancılık uzmanı ve ağaç tanıma modelisin. Cevaplarını yalnızca ve kesinlikle JSON formatında, başka hiçbir metin, başlık veya yorum olmadan ver. Türkçe yaz.' ],
            [ 'role' => 'user', 'content' => $userContent ]
        ],
        'web_access' => false
    ];

    // Log RapidAPI request summary (don't include API key)
    logMessage('RapidAPI isteği hazırlandı. usingPlantName=' . ($plantName ? 'true' : 'false') . ' ; userText preview: ' . substr($userText,0,300));

    $ch3 = curl_init($rapidUrl);
    curl_setopt_array($ch3, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-rapidapi-host: chatgpt-vision1.p.rapidapi.com',
            'x-rapidapi-key: ' . RAPIDAPI_KEY
        ],
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 10
    ]);

    $rapidResp = curl_exec($ch3);
    $rapidErr = curl_error($ch3);
    $rapidHttpCode = curl_getinfo($ch3, CURLINFO_HTTP_CODE);
    curl_close($ch3);

    logMessage("RapidAPI yanıt kodu: " . $rapidHttpCode);
    logMessage("RapidAPI ham yanıtı (ilk 4000 char): " . substr($rapidResp ?? '', 0, 4000));

    if ($rapidErr) {
        logMessage("RapidAPI CURL hatası: " . $rapidErr);
    }

    $rapidResponseText = $rapidResp;
}

// Normalize edip front-end'e gönder
$finalData = null;
if ($rapidResponseText) {
    // Try parse wrappers first
    $decoded = json_decode($rapidResponseText, true);
    $content = null;
    if (json_last_error() === JSON_ERROR_NONE) {
        if (isset($decoded['choices'][0]['message']['content'])) {
            $content = $decoded['choices'][0]['message']['content'];
        } elseif (isset($decoded['result'])) {
            $content = $decoded['result'];
        } elseif (isset($decoded['data'])) {
            $content = is_string($decoded['data']) ? $decoded['data'] : json_encode($decoded['data']);
        } else {
            $content = is_string($rapidResponseText) ? $rapidResponseText : json_encode($rapidResponseText);
        }
    } else {
        $content = $rapidResponseText;
    }

    // Extract first JSON object from content
    $foundJson = null;
    if (preg_match('/\{[\s\S]*\}/', $content, $m)) {
        $candidate = $m[0];
        $assoc = json_decode($candidate, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $foundJson = $assoc;
        }
    }

    if ($foundJson) {
        $finalData = [
            'treeName' => $foundJson['treeName'] ?? ($foundJson['name'] ?? ($plantName ?? 'Belirlenemedi')),
            'regions'  => $foundJson['regions'] ?? ($foundJson['habitats'] ?? 'Belirlenemedi'),
            'conditions'=> $foundJson['conditions'] ?? ($foundJson['optimal_conditions'] ?? 'Belirlenemedi'),
            'radius'   => $foundJson['radius'] ?? ($foundJson['trunk_radius'] ?? 'Belirtilmedi'),
            'age'      => $foundJson['age'] ?? ($foundJson['estimated_age'] ?? 'Belirtilmedi'),
            'confidence'=> $foundJson['confidence'] ?? ($foundJson['score'] ?? 'Belirtilmedi'),
            'rawResponse' => $candidate
        ];
        // Log final normalized data summary
        logMessage('Final normalized data (from RapidAPI): ' . json_encode(['treeName'=>$finalData['treeName'],'confidence'=>$finalData['confidence']], JSON_UNESCAPED_UNICODE));
        echo json_encode(['status' => true, 'data' => $finalData]);
    } else {
        // RapidAPI döndü ama JSON parse edilemedi
        logMessage('RapidAPI yanıtından JSON çıkarılamadı. content preview: ' . substr($content ?? '', 0, 2000));
        echo json_encode(['status' => false, 'error' => 'Model beklenen JSON formatında yanıt vermedi.', 'rawResponse' => $content]);
    }
} else {
    // Eğer RapidAPI yoksa ama PlantNet isim verebildiyse, döndürülebilecek basit bilgi
    if ($plantName) {
        $simple = [
            'treeName' => $plantName,
            'regions' => 'Belirtilmedi',
            'conditions' => 'Belirtilmedi',
            'radius' => 'Belirtilmedi',
            'age' => 'Belirtilmedi',
            'confidence' => 'Belirtilmedi',
            'rawResponse' => $pnResp ?? ''
        ];
        echo json_encode(['status' => true, 'data' => $simple]);
        logMessage('Final normalized data (PlantNet-only): ' . json_encode(['treeName'=>$simple['treeName']], JSON_UNESCAPED_UNICODE));
    } else {
        logMessage('Her iki servis de yanıt veremedi. PlantNet raw preview: ' . substr($pnResp ?? '',0,1000));
        echo json_encode(['status' => false, 'error' => 'Ne PlantNet ne de RapidAPI tarafından kullanılabilir bir yanıt alınamadı.', 'rawResponse' => $pnResp ?? '']);
    }
}

// Temp dosyayı sil
@unlink($savePath);

logMessage("İşlem tamamlandı");