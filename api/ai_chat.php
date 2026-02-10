<?php
// api/ai_chat.php
header('Content-Type: application/json');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security Check
if (!isset($_SESSION['user'])) {
    echo json_encode(['error' => 'unauthorized', 'message' => 'Please login first.']);
    exit;
}

// Include configuration
require_once '../config/ai_config.php';

// Check OpenAI API Key
// Note: config might define OPENAI_API_KEY
if (!defined('OPENAI_API_KEY') || empty(OPENAI_API_KEY) || OPENAI_API_KEY === 'YOUR_OPENAI_API_KEY_HERE') {
    // Check if we have Gemini config as fallback? No, let's stick to OpenAI as per latest user state
    echo json_encode([
        'error' => 'configuration_error', 
        'message' => 'AI API Key not configured.'
    ]);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['message']) || empty($input['message'])) {
    echo json_encode([
        'error' => 'invalid_request',
        'message' => 'Message is required.'
    ]);
    exit;
}

$userMessage = $input['message'];

// Determine System Context
$defaultSystemMessage = "You are an intelligent HR assistant for payroll, compensation, and workforce analytics. 
Provide professional, concise, and accurate assistance based on standard HR practices.";

// Allow frontend to override context
$systemMessage = $input['context'] ?? $defaultSystemMessage;

// OpenAI request payload
$data = [
    "model" => defined('OPENAI_MODEL') ? OPENAI_MODEL : 'gpt-3.5-turbo',
    "messages" => [
        [
            "role" => "system",
            "content" => $systemMessage
        ],
        [
            "role" => "user",
            "content" => $userMessage
        ]
    ],
    "temperature" => 0.7
];

// cURL request
$ch = curl_init("https://api.openai.com/v1/chat/completions");

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($data),
    CURLOPT_HTTPHEADER => [
        "Content-Type: application/json",
        "Authorization: Bearer " . OPENAI_API_KEY
    ],
    CURLOPT_TIMEOUT => 30
]);

$response = curl_exec($ch);

if (curl_errno($ch)) {
    echo json_encode([
        'error' => 'curl_error',
        'message' => curl_error($ch)
    ]);
    exit;
}

curl_close($ch);

// Decode OpenAI response
$result = json_decode($response, true);

// Check for API errors in response
if (isset($result['error'])) {
     echo json_encode([
        'error' => 'api_error',
        'message' => $result['error']['message'] ?? 'Unknown OpenAI Error'
    ]);
    exit;
}

// Send AI reply
echo json_encode([
    'reply' => $result['choices'][0]['message']['content'] ?? 'No response from AI'
]);
?>
