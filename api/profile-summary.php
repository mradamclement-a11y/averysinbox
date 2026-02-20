<?php
/**
 * Profile summary API: calls Groq to generate a short moral profile summary
 * and an explanation of the laws relevant to the user's decisions.
 * Requires config.php (and .env.php with GROQ_API_KEY) in the project root.
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once dirname(__DIR__) . '/config.php';

if (!GROQ_API_KEY) {
    http_response_code(500);
    echo json_encode(['error' => 'Groq API key not configured. Create .env.php from .env.php.example and set GROQ_API_KEY.']);
    exit;
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);
if (!$data || !isset($data['decisions']) || !is_array($data['decisions'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request: send JSON with decisions array.']);
    exit;
}

$decisions = $data['decisions'];
$counters = isset($data['counters']) ? $data['counters'] : ['report' => 0, 'leak' => 0, 'silence' => 0];
$profileTitle = isset($data['profileTitle']) ? $data['profileTitle'] : 'Your profile';
$lawsEncountered = isset($data['lawsEncountered']) ? $data['lawsEncountered'] : array_values(array_unique(array_column($decisions, 'law')));

$summaryByLaw = [];
foreach ($decisions as $d) {
    $law = $d['law'] ?? 'Unknown';
    if (!isset($summaryByLaw[$law])) $summaryByLaw[$law] = ['report' => 0, 'leak' => 0, 'silence' => 0];
    $summaryByLaw[$law][$d['type']] = ($summaryByLaw[$law][$d['type']] ?? 0) + 1;
}

$scenarioContext = buildScenarioContext($decisions);

$prompt = buildPrompt($profileTitle, $counters, $summaryByLaw, $scenarioContext);

$response = callGroq($prompt);
if ($response === null) {
    http_response_code(502);
    echo json_encode(['error' => 'Groq API request failed. Check key and network.']);
    exit;
}
if (isset($response['error'])) {
    echo json_encode($response);
    exit;
}

echo json_encode($response);

function buildScenarioContext(array $decisions) {
    $path = dirname(__DIR__) . '/scenarios.json';
    if (!is_readable($path)) {
        return '';
    }
    $json = file_get_contents($path);
    $scenarios = json_decode($json, true);
    if (!is_array($scenarios)) {
        return '';
    }
    $byId = [];
    foreach ($scenarios as $sc) {
        $id = isset($sc['id']) ? (int) $sc['id'] : null;
        if ($id !== null) {
            $byId[$id] = isset($sc['subject']) ? trim($sc['subject']) : '';
        }
    }
    $lines = [];
    $maxWords = 10;
    foreach ($decisions as $d) {
        $id = isset($d['scenarioId']) ? (int) $d['scenarioId'] : null;
        $subject = ($id !== null && isset($byId[$id])) ? $byId[$id] : '';
        $words = preg_split('/\s+/', $subject, $maxWords + 1, PREG_SPLIT_NO_EMPTY);
        if (count($words) > $maxWords) {
            $words = array_slice($words, 0, $maxWords);
        }
        $short = implode(' ', $words);
        $letter = (isset($d['type']) && $d['type'] === 'leak') ? 'L' : ((isset($d['type']) && $d['type'] === 'silence') ? 'S' : 'R');
        $lines[] = $id . ' | ' . $short . ' | ' . $letter;
    }
    return implode("\n", $lines);
}

function buildPrompt($profileTitle, $counters, $summaryByLaw, $scenarioContext) {
    $r = $counters['report'] ?? 0;
    $l = $counters['leak'] ?? 0;
    $s = $counters['silence'] ?? 0;
    $total = $r + $l + $s;

    $lawLines = [];
    foreach ($summaryByLaw as $law => $types) {
        $parts = [];
        if (!empty($types['report'])) $parts[] = 'R:' . $types['report'];
        if (!empty($types['leak'])) $parts[] = 'L:' . $types['leak'];
        if (!empty($types['silence'])) $parts[] = 'S:' . $types['silence'];
        $lawLines[] = $law . ' ' . implode(', ', $parts);
    }
    $lawBlock = implode("\n", $lawLines);

    $ctxBlock = $scenarioContext !== ''
        ? "Scenario context (id | subject | R=report L=leak S=silence):\n" . $scenarioContext . "\n\n"
        : '';

    return <<<PROMPT
BTEC IT ethical activity (Avery's Inbox). Student completed {$total} scenarios. Profile: {$profileTitle}. Counts R={$r} L={$l} S={$s}.

{$ctxBlock}By law:
{$lawBlock}

Reply with JSON only (no markdown), two keys: "moralSummary" (short paragraph to student, 3–5 sentences, their moral profile — institutions vs transparency vs risk); "lawsExplanation" (short paragraph, 3–5 sentences, how DPA/ICO, CMA, PIDA, FOI etc. apply to their choices, BTEC Unit 1 D/F2).
PROMPT;
}

function callGroq($userMessage) {
    $url = 'https://api.groq.com/openai/v1/chat/completions';
    $model = defined('GROQ_MODEL') ? GROQ_MODEL : 'llama-3.1-70b-versatile';
    $body = [
        'model' => $model,
        'messages' => [
            ['role' => 'user', 'content' => $userMessage]
        ],
        'temperature' => 0.6,
        'max_tokens' => 1024,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . GROQ_API_KEY,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return null;
    }

    if ($httpCode !== 200) {
        $err = is_string($response) ? json_decode($response, true) : null;
        $msg = isset($err['error']['message']) ? strtolower($err['error']['message']) : '';
        $quotaLike = ($httpCode === 429 || $httpCode === 403)
            || preg_match('/\b(quota|credit|limit|exceeded|expired|rate)\b/', $msg);
        if ($quotaLike) {
            return ['error' => 'AI tokens expired - AI summary currently unavailable'];
        }
        return null;
    }

    $data = json_decode($response, true);
    $content = $data['choices'][0]['message']['content'] ?? '';
    if ($content === '') {
        return null;
    }

    // Strip markdown code block if present
    $content = preg_replace('/^\\s*```(?:json)?\\s*\\n?/', '', $content);
    $content = preg_replace('/\\n?\\s*```\\s*$/', '', $content);
    $parsed = json_decode(trim($content), true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($parsed)) {
        return [
            'moralSummary' => $content,
            'lawsExplanation' => '',
        ];
    }
    return [
        'moralSummary' => $parsed['moralSummary'] ?? '',
        'lawsExplanation' => $parsed['lawsExplanation'] ?? '',
    ];
}
