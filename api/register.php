<?php
/**
 * Registration endpoint for the Webketoan Academy landing page.
 * Secrets are read only from Hostinger environment variables.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function env_value(string $name): string
{
    $value = getenv($name);
    return is_string($value) ? trim($value) : '';
}

function post_json(string $url, array $payload, array $headers = []): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Không thể khởi tạo kết nối dịch vụ.');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $error !== '') {
        throw new RuntimeException('Không kết nối được dịch vụ bên ngoài.');
    }

    return ['status' => $status, 'body' => (string) $body];
}

function post_form(string $url, array $payload): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Không thể khởi tạo kết nối Sendy.');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload, '', '&'),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $error !== '') {
        throw new RuntimeException('Không kết nối được Sendy.');
    }

    return ['status' => $status, 'body' => trim((string) $body)];
}

function sendgrid_email(string $apiKey, string $fromEmail, string $fromName, string $toEmail, string $name, array $wants): array
{
    $selected = $wants !== [] ? implode(', ', $wants) : 'Thông tin khóa học';
    $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeSelected = htmlspecialchars($selected, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $payload = [
        'personalizations' => [[
            'to' => [['email' => $toEmail, 'name' => $name]],
        ]],
        'from' => ['email' => $fromEmail, 'name' => $fromName],
        'subject' => 'Đã ghi nhận đăng ký — Webketoan Academy',
        'content' => [
            [
                'type' => 'text/plain',
                'value' => "Xin chào {$name},\n\nWebketoan Academy đã ghi nhận đăng ký của bạn.\nNội dung bạn chọn nhận: {$selected}.\n\nChúng tôi sẽ gửi thông tin tiếp theo qua email này.\n\nWebketoan Academy",
            ],
            [
                'type' => 'text/html',
                'value' => "<p>Xin chào <strong>{$safeName}</strong>,</p><p>Webketoan Academy đã ghi nhận đăng ký của bạn.</p><p>Nội dung bạn chọn nhận: <strong>{$safeSelected}</strong>.</p><p>Chúng tôi sẽ gửi thông tin tiếp theo qua email này.</p><p>Webketoan Academy</p>",
            ],
        ],
    ];

    return post_json(
        'https://api.sendgrid.com/v3/mail/send',
        $payload,
        ['Authorization: Bearer ' . $apiKey]
    );
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'Method not allowed']);
}

$raw = file_get_contents('php://input');
$input = json_decode($raw ?: '', true);
if (!is_array($input)) {
    respond(400, ['ok' => false, 'error' => 'Dữ liệu đăng ký không hợp lệ.']);
}

$fullName = trim((string) ($input['full_name'] ?? ''));
$email = strtolower(trim((string) ($input['email'] ?? '')));
$wants = is_array($input['wants'] ?? null) ? array_values(array_map('strval', $input['wants'])) : [];
$consent = filter_var($input['consent'] ?? false, FILTER_VALIDATE_BOOLEAN);

if ($fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$consent) {
    respond(422, ['ok' => false, 'error' => 'Vui lòng kiểm tra họ tên, email và đồng ý nhận thông tin.']);
}

$appsScriptUrl = env_value('GOOGLE_APPS_SCRIPT_URL') ?: 'https://script.google.com/macros/s/AKfycbylngHgWM5De67CFX8zQN6kV3Clj_0qKdYSN5Rr6BKBMxSAWUTMe-Kf53rfipLSzVUD/exec';
$webhookSecret = env_value('WEBHOOK_SECRET');
$sendyApiKey = env_value('SENDY_API_KEY');
$sendyListId = env_value('SENDY_LIST_ID');
$sendgridApiKey = env_value('SENDGRID_API_KEY');
$sendgridFromEmail = env_value('SENDGRID_FROM_EMAIL');
$sendgridFromName = env_value('SENDGRID_FROM_NAME') ?: 'Webketoan Academy';

if ($appsScriptUrl === '' || $webhookSecret === '' || $sendyApiKey === '' || $sendyListId === '' || $sendgridApiKey === '' || $sendgridFromEmail === '') {
    respond(500, ['ok' => false, 'error' => 'Dịch vụ đăng ký chưa được cấu hình đầy đủ.']);
}

$submissionId = trim((string) ($input['submission_id'] ?? ''));
if ($submissionId === '') {
    $submissionId = bin2hex(random_bytes(16));
}

$record = [
    'webhook_secret' => $webhookSecret,
    'submission_id' => $submissionId,
    'full_name' => $fullName,
    'email' => $email,
    'phone' => trim((string) ($input['phone'] ?? '')),
    'role' => trim((string) ($input['role'] ?? '')),
    'industry' => trim((string) ($input['industry'] ?? '')),
    'ai_level' => trim((string) ($input['ai_level'] ?? '')),
    'use_case' => trim((string) ($input['use_case'] ?? '')),
    'wants' => $wants,
    'consent' => true,
    'source_url' => trim((string) ($input['source_url'] ?? '')),
];

$steps = [];
try {
    $sheet = post_json($appsScriptUrl, $record);
    if ($sheet['status'] < 200 || $sheet['status'] >= 300) {
        throw new RuntimeException('Google Sheets không ghi nhận được dữ liệu.');
    }
    $sheetBody = json_decode($sheet['body'], true);
    if (!is_array($sheetBody) || ($sheetBody['ok'] ?? false) !== true) {
        throw new RuntimeException('Google Sheets từ chối dữ liệu.');
    }
    $steps['sheets'] = 'ok';

    $sendyBase = env_value('SENDY_URL');
    $sendyParsed = parse_url($sendyBase);
    if (!is_array($sendyParsed) || empty($sendyParsed['scheme']) || empty($sendyParsed['host'])) {
        throw new RuntimeException('SENDY_URL không hợp lệ.');
    }
    $sendySubscribeUrl = $sendyParsed['scheme'] . '://' . $sendyParsed['host'] . '/subscribe';
    $sendy = post_form($sendySubscribeUrl, [
        'api_key' => $sendyApiKey,
        'name' => $fullName,
        'email' => $email,
        'list' => $sendyListId,
        'boolean' => 'true',
        'referrer' => trim((string) ($input['source_url'] ?? '')),
        'gdpr' => 'true',
    ]);
    $sendyOk = $sendy['status'] >= 200 && $sendy['status'] < 300 && preg_match('/^(true|success: true|already subscribed\.?)/i', $sendy['body']) === 1;
    if (!$sendyOk) {
        throw new RuntimeException('Sendy không ghi nhận được email.');
    }
    $steps['sendy'] = 'ok';

    $mail = sendgrid_email($sendgridApiKey, $sendgridFromEmail, $sendgridFromName, $email, $fullName, $wants);
    if ($mail['status'] !== 202) {
        throw new RuntimeException('SendGrid không chấp nhận email.');
    }
    $steps['sendgrid'] = 'ok';

    respond(200, ['ok' => true, 'submission_id' => $submissionId, 'steps' => $steps]);
} catch (Throwable $error) {
    error_log('registration_failed ' . $submissionId . ': ' . $error->getMessage());
    respond(502, ['ok' => false, 'submission_id' => $submissionId, 'steps' => $steps, 'error' => 'Đã ghi nhận lỗi khi xử lý đăng ký. Vui lòng thử lại sau.']);
}
