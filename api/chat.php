<?php
// PHP proxy for DeepSeek API - runs on standard PHP hosting like Hostinger.
header("Content-Type: application/json; charset=utf-8");

// Allow only POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Method not allowed"]);
    exit;
}

// 1. Load environment variables from .env file (if exists)
$apiKey = null;
$envPath = __DIR__ . '/../.env';
if (!file_exists($envPath)) {
    $envPath = __DIR__ . '/../.env.local';
}

if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        // Remove quotes if present
        $value = trim($value, '"\'');
        if ($name === 'DEEPSEEK_API_KEY') {
            $apiKey = $value;
            break;
        }
    }
}

// Fallback to getenv if set via server configuration
if (!$apiKey) {
    $apiKey = getenv('DEEPSEEK_API_KEY');
}

if (!$apiKey) {
    http_response_code(500);
    echo json_encode(["error" => "Server chưa cấu hình DEEPSEEK_API_KEY trong tệp .env"]);
    exit;
}

// 2. Load Knowledge Base
$kb = "";
$kbPath = __DIR__ . '/../chatbot_data.txt';
if (file_exists($kbPath)) {
    $kb = file_get_contents($kbPath);
}

// 3. Parse input JSON
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);
$messages = isset($input['messages']) && is_array($input['messages']) ? $input['messages'] : [];

if (empty($messages)) {
    http_response_code(400);
    echo json_encode(["error" => "Thiếu nội dung tin nhắn."]);
    exit;
}

// 4. Build System Prompt
$systemPrompt = implode("\n", [
    "Bạn là trợ lý AI tư vấn độc quyền của Tâm An CFO, đơn vị cung cấp dịch vụ CFO Thuê Ngoài cho doanh nghiệp SME tại Việt Nam.",
    "Bạn CHỈ được trả lời dựa trên Cơ sở tri thức (Knowledge Base) dưới đây. Không được bịa đặt thông tin ngoài phạm vi này.",
    "",
    "--- KNOWLEDGE BASE ---",
    $kb,
    "--- HẾT KNOWLEDGE BASE ---",
    "",
    "Quy tắc trả lời:",
    "1. Luôn trả lời bằng tiếng Việt, giọng văn thân thiện, chuyên nghiệp, chào hỏi lịch sự.",
    "2. Định dạng câu trả lời bằng Markdown rõ ràng: dùng danh sách, in đậm từ khóa quan trọng, xuống dòng hợp lý.",
    "3. Luôn kết thúc câu trả lời bằng một câu mời khách hỏi thêm hoặc để lại thông tin liên hệ.",
    "4. Nếu câu hỏi nằm ngoài phạm vi Knowledge Base, hãy từ chối nhẹ nhàng và hướng dẫn khách liên hệ trực tiếp qua thông tin liên hệ trong Knowledge Base."
]);

// Build messages payload
$payloadMessages = [
    ["role" => "system", "content" => $systemPrompt]
];
foreach ($messages as $msg) {
    $payloadMessages[] = [
        "role" => $msg['role'],
        "content" => $msg['content']
    ];
}

$payload = [
    "model" => "deepseek-chat",
    "messages" => $payloadMessages,
    "temperature" => 0.7,
    "max_tokens" => 4096,
    "stream" => false
];

// 5. Call DeepSeek API using cURL
$ch = curl_init("https://api.deepseek.com/chat/completions");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Authorization: Bearer " . $apiKey
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    http_response_code(500);
    echo json_encode(["error" => "Lỗi kết nối API: " . $curlError]);
    exit;
}

$responseData = json_decode($response, true);

if ($httpCode >= 400) {
    http_response_code($httpCode);
    $errorMessage = isset($responseData['error']['message']) ? $responseData['error']['message'] : "Lỗi từ dịch vụ AI.";
    echo json_encode(["error" => $errorMessage]);
    exit;
}

$reply = isset($responseData['choices'][0]['message']['content']) ? $responseData['choices'][0]['message']['content'] : "";

echo json_encode(["reply" => $reply]);
?>
