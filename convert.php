<?php
/**
 * 阪急ランドオペレーター CSVコンバーター - Gemini API Proxy
 * K列・L列のテキストをAIで要約してM列・N列に追加
 */

// CORS headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// .envファイルからAPIキーを読み込み
function loadEnv($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

loadEnv(__DIR__ . '/.env');

$apiKey = $_ENV['GEMINI_API_KEY'] ?? '';
if (empty($apiKey) || $apiKey === 'YOUR_GEMINI_API_KEY_HERE') {
    http_response_code(500);
    echo json_encode(['error' => 'GEMINI_API_KEY が .env に設定されていません。'], JSON_UNESCAPED_UNICODE);
    exit;
}

// POSTリクエストのみ受付
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST method required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['texts'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request body. Expected JSON with "texts" array.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$texts = $input['texts']; // [{index, colK, colL}, ...]

$results = [];

foreach ($texts as $item) {
    $index = $item['index'];
    $colK = $item['colK'] ?? '';
    $colL = $item['colL'] ?? '';

    $summaryK = '';
    $summaryL = '';

    // K列の要約
    if (!empty(trim($colK))) {
        $summaryK = callGemini($apiKey, $colK);
    }

    // L列の要約
    if (!empty(trim($colL))) {
        $summaryL = callGemini($apiKey, $colL);
    }

    $results[] = [
        'index' => $index,
        'summaryK' => $summaryK,
        'summaryL' => $summaryL
    ];
}

echo json_encode(['results' => $results], JSON_UNESCAPED_UNICODE);

/**
 * Gemini APIを呼び出してテキストを要約する
 * 503エラー時は最大3回リトライ（指数バックオフ: 2s, 4s, 8s）
 */
function callGemini($apiKey, $promptText) {
    $model = 'gemini-3-flash-preview';
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

    $systemPrompt = <<<EOT
以下の旅行業務テキストを、下記のルールに従って要約してください。

【要約ルール】
1. 必ず記載する項目：
   - 「アレルギー」「車椅子での参加」「医療機器」など具体的支援ニーズ
   - お客からの要望（RQ）
   - 特別依頼：「ハネムーン」「結婚記念日」

2. 削除する項目：
   - 一般的な持参薬案内
   - 確定（HK）や変更履歴（例：TWN SGL→）
   - 金銭/保険/旅券/連絡先変更/JR/社内進行
   - 「☆☆ダミー記録です☆☆」のようなプレフィックス

3. 出力形式：
   - 簡潔にまとめる（最大80文字以内）
   - 重要な情報のみを抽出
   - 必ず完結した文章で出力する（途中で切れないように注意）
   - 何も記載すべき内容がない場合は、何も出力せずに空白のままにする（「（空文字列）」や「空文字列」などのテキストを出力しない）

テキスト：
{$promptText}

要約：
EOT;

    $payload = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $systemPrompt]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.3,
            'maxOutputTokens' => 1000
        ]
    ];

    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $maxRetries = 3;
    $retryDelay = 2; // 秒（指数バックオフの基準値）

    for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
        if ($attempt > 0) {
            $waitTime = $retryDelay * pow(2, $attempt - 1);
            sleep($waitTime);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            error_log("Gemini API cURL error (attempt {$attempt}): {$curlError}");
            if ($attempt === $maxRetries) return '';
            continue;
        }

        if ($httpCode === 503 || $httpCode === 429) {
            error_log("Gemini API returned {$httpCode} (attempt {$attempt}), retrying...");
            if ($attempt === $maxRetries) return '';
            continue;
        }

        if ($httpCode !== 200) {
            error_log("Gemini API error (HTTP {$httpCode}): {$response}");
            return '';
        }

        $data = json_decode($response, true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        // 不要な出力をクリーンアップ
        $text = trim($text);
        // 「（空文字列）」などの不要出力を空にする
        if (preg_match('/^[\s]*[（(]?空文字列[）)]?[\s]*$/u', $text)) {
            $text = '';
        }

        return $text;
    }

    return '';
}
