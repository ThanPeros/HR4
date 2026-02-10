<?php
// api/ai_analysis.php
header('Content-Type: application/json');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security Check
if (!isset($_SESSION['user'])) {
    echo json_encode(['error' => 'unauthorized', 'message' => 'Please login first.']);
    exit;
}

require_once '../config/ai_config.php';

// Check API Key
if (!defined('OPENAI_API_KEY') || empty(OPENAI_API_KEY) || OPENAI_API_KEY === 'YOUR_OPENAI_API_KEY_HERE') {
    echo json_encode(['error' => 'configuration_error', 'message' => 'AI API Key not configured.']);
    exit;
}

// Get Input
$input = json_decode(file_get_contents('php://input'), true);
if (!isset($input['data']) || !isset($input['type'])) {
    echo json_encode(['error' => 'invalid_request', 'message' => 'Data and Analysis Type required.']);
    exit;
}

$type = $input['type'];
$dataContent = is_string($input['data']) ? $input['data'] : json_encode($input['data']);

// Define Prompt based on Type
$systemPrompt = "You are an expert HR Data Analyst. Your goal is to analyze data and provide structured insights in valid JSON format ONLY. Do not include markdown formatting (like ```json).";
$userPrompt = "";

switch ($type) {
    case 'payroll_audit':
        $systemPrompt .= " Analyze the provided payroll records for anomalies.";
        $userPrompt = "Analyze this payroll data: $dataContent.
        
        Check for:
        1. Net pay < 0
        2. Overtime > 40 hours
        3. Excessive deductions (>50% of gross)
        4. Any other irregularities
        
        Return JSON structure:
        {
            \"anomalies\": [
                {\"employee\": \"Name\", \"issue\": \"Description of issue\", \"severity\": \"High/Medium/Low\"}
            ],
            \"summary\": \"Brief summary of findings (max 1 sentence)\",
            \"status\": \"Clean\" or \"Issues Found\"
        }";
        break;

    case 'compensation_recommendation':
        $systemPrompt .= " Recommend salary adjustments based on data.";
        $userPrompt = "Based on this employee data: $dataContent.
        
        Recommend a merit increase percentage (0-10%) based on current salary and market trends (assume standard inflation 4%).
        
        Return JSON structure:
        {
            \"recommended_percentage\": 5.5,
            \"reason\": \" justification based on market inflation and base salary...\",
            \"market_comparison\": \"Below/At/Above Market\"
        }";
        break;

    default:
        $userPrompt = "Analyze: $dataContent";
        break;
}

// Call OpenAI
$payload = [
    "model" => defined('OPENAI_MODEL') ? OPENAI_MODEL : 'gpt-3.5-turbo',
    "messages" => [
        ["role" => "system", "content" => $systemPrompt],
        ["role" => "user", "content" => $userPrompt]
    ],
    "temperature" => 0.3
];

$ch = curl_init("https://api.openai.com/v1/chat/completions");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        "Content-Type: application/json",
        "Authorization: Bearer " . OPENAI_API_KEY
    ],
    CURLOPT_TIMEOUT => 45
]);

$response = curl_exec($ch);
if (curl_errno($ch)) {
    echo json_encode(['error' => 'connection_error', 'message' => curl_error($ch)]);
    exit;
}
curl_close($ch);

$result = json_decode($response, true);
$content = $result['choices'][0]['message']['content'] ?? '{}';

// Clean content if it has markdown code blocks
$content = str_replace(['```json', '```'], '', $content);

echo $content;
?>
