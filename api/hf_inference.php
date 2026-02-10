<?php
// api/hf_inference.php
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
require_once '../config/huggingface_config.php';

// Check API Key
if (!defined('HUGGINGFACE_API_KEY') || HUGGINGFACE_API_KEY === 'YOUR_HUGGINGFACE_API_KEY' || empty(HUGGINGFACE_API_KEY)) {
    echo json_encode([
        'error' => 'configuration_error', 
        'message' => 'Hugging Face API Key is not configured. Please open "config/huggingface_config.php" and add your Token.'
    ]);
    exit;
}

// Get JSON Input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['task']) || !isset($input['input'])) {
    echo json_encode(['error' => 'invalid_input', 'message' => 'Task and input required.']);
    exit;
}

$task = $input['task'];
$textInput = $input['input'];

$model = '';
$apiUrl = '';

switch ($task) {
    case 'chat':
        if (defined('HF_MODEL_CHAT')) {
            $model = HF_MODEL_CHAT;
            // Hugging Face might not support direct "chat" endpoint for some models (requires formatting), but `text-generation` is standard.
            // Mistral uses chat template format: [INST] user_prompt [/INST]
            $textInput = "[INST] You are a helpful HR Assistant. Provide concise answers.\nUser: " . $textInput . " [/INST]";
            $apiUrl = "https://router.huggingface.co/hf-inference/models/$model";
        }
        break;
    
    case 'classification':
        // Sentiment / Feedback
        // Task: text-classification
        if (defined('HF_MODEL_CLASSIFICATION')) {
            $model = HF_MODEL_CLASSIFICATION;
            $apiUrl = "https://router.huggingface.co/hf-inference/models/$model";
        }
        break;
        
    case 'ner': // Skills/Entities Extraction
        // Task: token-classification
        if (defined('HF_MODEL_NER')) {
            $model = HF_MODEL_NER;
            $apiUrl = "https://router.huggingface.co/hf-inference/models/$model";
        }
        break;
        
    case 'embeddings': // Semantic Search (Embedding generation only)
        // Task: feature-extraction
        if (defined('HF_MODEL_EMBEDDINGS')) {
            $model = HF_MODEL_EMBEDDINGS;
            $apiUrl = "https://router.huggingface.co/hf-inference/models/$model";
        }
        break;
        
    default:
        // Use default Chat model if unknown
        $model = HF_MODEL_CHAT ?? 'mistralai/Mistral-7B-Instruct-v0.2';
        $apiUrl = "https://router.huggingface.co/hf-inference/models/$model";
        break;
}

// Prepare Request Payload
// Standard keys for most HF endpoints: inputs, parameters (for text-generation)
$payload = ['inputs' => $textInput];

if ($task === 'chat') {
    $payload['parameters'] = [
        'max_new_tokens' => 250,
        'temperature' => 0.7,
        'return_full_text' => false
    ];
}

// Init cURL
$ch = curl_init($apiUrl);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        "Content-Type: application/json",
        "Authorization: Bearer " . HUGGINGFACE_API_KEY
    ],
    CURLOPT_TIMEOUT => 30 // HF free tier can be slow initially (cold start)
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if (curl_errno($ch)) {
    echo json_encode(['error' => 'connection_error', 'message' => curl_error($ch)]);
} else {
    $decodedResponse = json_decode($response, true);
    
    // Check for HF specific error structures
    if (isset($decodedResponse['error'])) {
        // e.g., {'error': 'Model is currently loading', 'estimated_time': 20.0}
        if (strpos(strtolower($decodedResponse['error']), 'loading') !== false) {
             echo json_encode([
                 'error' => 'model_loading',
                 'message' => 'Model is loading (cold start). Please retry in ' . number_format($decodedResponse['estimated_time'] ?? 20, 0) . ' seconds.'
             ]);
        } else {
             echo json_encode(['error' => 'api_error', 'message' => $decodedResponse['error']]);
        }
    } else {
        // Format response based on task
        $formattedResponse = ['raw' => $decodedResponse];
        
        switch ($task) {
            case 'chat':
                // Usually returns array of generated_text
                if (isset($decodedResponse[0]['generated_text'])) {
                    $formattedResponse['output'] = trim($decodedResponse[0]['generated_text']);
                } else {
                    $formattedResponse['output'] = 'No response generated.';
                }
                break;
                
            case 'classification':
                // Usually returns nested array: [[{'label':X, 'score':Y}, ...]]
                // Simplify for frontend
                if (isset($decodedResponse[0]) && is_array($decodedResponse[0])) {
                    // Sorting by score desc if not already
                    usort($decodedResponse[0], function($a, $b) { return $b['score'] <=> $a['score']; });
                    $formattedResponse['output'] = $decodedResponse[0];
                }
                break;
                
            case 'ner':
                // Returns array of objects with 'entity_group'/'entity', 'word', 'score', etc.
                if (is_array($decodedResponse)) {
                    $formattedResponse['output'] = $decodedResponse;
                }
                break;

            case 'embeddings':
                 // Returns array of floats
                 $formattedResponse['output'] = "Embedding generated (" . count($decodedResponse) . " dimensions)";
                 break;
                 
            default:
                $formattedResponse['output'] = $response;
        }
        
        echo json_encode($formattedResponse);
    }
}

curl_close($ch);
?>
