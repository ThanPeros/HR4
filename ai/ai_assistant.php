<?php
// ai/ai_assistant.php
session_start();

// -------------------------------------------------------------------------
// 1. AJAX API HANDLER (Process Chat Requests)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'chat') {
    header('Content-Type: application/json');
    
    require_once __DIR__ . '/../config/ai_config.php';

    $userMessage = trim($_POST['message'] ?? '');
    
    if (empty($userMessage)) {
        echo json_encode(['error' => 'Message is empty']);
        exit;
    }

    if (!defined('GEMINI_API_KEY') || strpos(GEMINI_API_KEY, 'YOUR_') !== false) {
        echo json_encode(['error' => 'API Key is missing or invalid. Please configure config/ai_config.php']);
        exit;
    }

    // Prepare payload for Gemini
    $data = [
        "contents" => [
            [
                "parts" => [
                    ["text" => "You are a helpful HR Assistant for the Slate Freight HR System. Your goal is to help HR professionals improve their system, analyze data, and provide strategic advice. If asked about system specifics, assume standard HR practices. User Query: " . $userMessage]
                ]
            ]
        ]
    ];

    $ch = curl_init(GEMINI_API_URL . "?key=" . GEMINI_API_KEY);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        echo json_encode(['error' => 'Curl error: ' . curl_error($ch)]);
        curl_close($ch);
        exit;
    }
    curl_close($ch);

    if ($httpCode === 200) {
        $result = json_decode($response, true);
        if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            $aiReply = $result['candidates'][0]['content']['parts'][0]['text'];
            // Markdown to simple HTML conversion could happen here, or in JS. 
            // For now, return raw text/markdown.
            echo json_encode(['reply' => $aiReply]);
        } else {
            echo json_encode(['error' => 'Invalid response structure from Gemini API']);
        }
    } else {
        echo json_encode(['error' => 'API Error: ' . $httpCode . ' - ' . $response]);
    }
    exit;
}

// -------------------------------------------------------------------------
// 2. PAGE RENDER
// -------------------------------------------------------------------------
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../config/ai_config.php';

$apiKeyConfigured = (defined('GEMINI_API_KEY') && strpos(GEMINI_API_KEY, 'YOUR_') === false);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>AI HR Assistant | Slate Freight</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Markdown Parser -->
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <style>
        :root { 
            --primary: #4e73df; 
            --light-bg: #f8f9fc;
            --chat-bg: #ffffff;
            --user-msg-bg: #e3f2fd; /* Light Blue */
            --ai-msg-bg: #f1f3f5;   /* Light Gray */
        }
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: var(--light-bg);
        }
        .main-content { 
            padding: 2rem; 
            margin-top: 60px; 
            /* Adjust for sidebar based on state, controlled by sidebar.php styles */
        }
        
        .chat-container {
            max-width: 900px;
            margin: 0 auto;
            background: var(--chat-bg);
            border-radius: 12px;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.1);
            display: flex;
            flex-direction: column;
            height: calc(100vh - 140px); /* Fill remaining height */
            min-height: 500px;
        }

        .chat-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e3e6f0;
            background: linear-gradient(135deg, #4e73df 0%, #224abe 100%);
            color: white;
            border-radius: 12px 12px 0 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .chat-messages {
            flex: 1;
            padding: 1.5rem;
            overflow-y: auto;
            background-color: #f8f9fc;
        }

        .message-wrapper {
            margin-bottom: 1.5rem;
            display: flex;
            flex-direction: column;
        }

        .message {
            max-width: 80%;
            padding: 1rem 1.25rem;
            border-radius: 1rem;
            position: relative;
            font-size: 0.95rem;
            line-height: 1.6;
        }

        .message.user {
            align-self: flex-end;
            background-color: var(--primary);
            color: white;
            border-bottom-right-radius: 0.25rem;
        }

        .message.ai {
            align-self: flex-start;
            background-color: white;
            color: #333;
            border: 1px solid #e3e6f0;
            border-bottom-left-radius: 0.25rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .message-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 0.25rem;
            font-size: 0.8rem;
        }
        
        .ai-avatar {
            background: #fff;
            color: var(--primary);
            border: 1px solid #e3e6f0;
            align-self: flex-start;
        }
        
        .user-avatar-sm {
            background: var(--primary);
            color: white;
            align-self: flex-end;
        }
        
        .chat-input-area {
            padding: 1.5rem;
            border-top: 1px solid #e3e6f0;
            background: white;
            border-radius: 0 0 12px 12px;
        }

        .typing-indicator {
            display: none;
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
            color: #858796;
            font-style: italic;
        }
        
        /* Markdown Styles for AI Response */
        .message.ai b, .message.ai strong { font-weight: 700; }
        .message.ai ul, .message.ai ol { margin-left: 1.5rem; margin-bottom: 0.5rem; }
        .message.ai p { margin-bottom: 0.5rem; }
        .message.ai p:last-child { margin-bottom: 0; }
        
    </style>
</head>
<body class="<?php echo $currentTheme === 'dark' ? 'dark-mode' : ''; ?>">

<div class="main-content">
    
    <div class="chat-container">
        <!-- Header -->
        <div class="chat-header">
            <div class="d-flex align-items-center gap-3">
                <div class="bg-white text-primary rounded-circle d-flex align-items-center justify-content-center" style="width: 45px; height: 45px;">
                    <i class="fas fa-robot fa-lg"></i>
                </div>
                <div>
                    <h5 class="mb-0 fw-bold">HR Assistant (Gemini AI)</h5>
                    <small class="opacity-75">Ask about HR optimization, data analysis, or system improvements.</small>
                </div>
            </div>
            <?php if (!$apiKeyConfigured): ?>
                <span class="badge bg-warning text-dark"><i class="fas fa-exclamation-triangle"></i> API Key Not Configured</span>
            <?php else: ?>
                <span class="badge bg-success bg-opacity-25 text-white border border-white border-opacity-25"><i class="fas fa-check-circle"></i> Online</span>
            <?php endif; ?>
        </div>

        <!-- Chat Area -->
        <div class="chat-messages" id="chatbox">
            <!-- Intro Message -->
            <div class="message-wrapper">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <i class="fas fa-robot text-primary"></i> <small class="text-muted fw-bold">Gemini Assistant</small>
                </div>
                <div class="message ai">
                    Hello! I am your AI HR Assistant powered by Google Gemini. How can I help you improve your HR system or analyze your workforce data today?
                </div>
            </div>
        </div>

        <!-- Loading Indicator -->
        <div class="typing-indicator" id="typingIndicator">
            <i class="fas fa-spinner fa-spin me-2"></i> Gemini is thinking...
        </div>

        <!-- Input Area -->
        <div class="chat-input-area">
            <form id="chatForm" class="d-flex gap-2">
                <div class="input-group">
                    <input type="text" id="userInput" class="form-control form-control-lg border-0 bg-light" 
                           placeholder="<?php echo $apiKeyConfigured ? 'Type your message request here...' : 'Configure API Key in config/ai_config.php to start chatting...'; ?>" 
                           <?php echo !$apiKeyConfigured ? 'disabled' : ''; ?>
                           autocomplete="off">
                    <button type="submit" class="btn btn-primary px-4" <?php echo !$apiKeyConfigured ? 'disabled' : ''; ?>>
                        <i class="fas fa-paper-plane"></i> Send
                    </button>
                </div>
            </form>
            <?php if (!$apiKeyConfigured): ?>
                <div class="mt-2 text-center text-danger small">
                    <i class="fas fa-info-circle"></i> Please open <code>config/ai_config.php</code> and add your free Gemini API Key.
                </div>
            <?php endif; ?>
            <div class="mt-2 text-muted text-center" style="font-size: 0.75rem;">
                <i class="fas fa-shield-alt me-1"></i> AI can make mistakes. Verify important HR information.
            </div>
        </div>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const chatForm = document.getElementById('chatForm');
    const userInput = document.getElementById('userInput');
    const chatbox = document.getElementById('chatbox');
    const typingIndicator = document.getElementById('typingIndicator');

    chatForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const message = userInput.value.trim();
        if (!message) return;

        // 1. Add User Message
        appendMessage('user', message);
        userInput.value = '';
        
        // 2. Show Typing Indicator
        typingIndicator.style.display = 'block';
        chatbox.scrollTop = chatbox.scrollHeight;

        // 3. Send to Backend
        const formData = new FormData();
        formData.append('action', 'chat');
        formData.append('message', message);

        fetch('ai_assistant.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            typingIndicator.style.display = 'none';
            if (data.error) {
                appendMessage('ai', '<strong>Error:</strong> ' + data.error);
            } else {
                appendMessage('ai', data.reply); // Markdown parsing handled in appendMessage
            }
        })
        .catch(err => {
            typingIndicator.style.display = 'none';
            appendMessage('ai', '<strong>System Error:</strong> Failed to connect to AI server.');
            console.error(err);
        });
    });

    function appendMessage(sender, text) {
        const wrapper = document.createElement('div');
        wrapper.className = 'message-wrapper';
        
        const header = document.createElement('div');
        header.className = 'd-flex align-items-center gap-2 mb-1 ' + (sender === 'user' ? 'justify-content-end' : '');
        
        if (sender === 'ai') {
            header.innerHTML = '<i class="fas fa-robot text-primary"></i> <small class="text-muted fw-bold">Gemini Assistant</small>';
        } else {
            header.innerHTML = '<small class="text-muted fw-bold">You</small> <i class="fas fa-user text-secondary"></i>';
        }

        const msgDiv = document.createElement('div');
        msgDiv.className = 'message ' + sender;
        
        // Parse Markdown if AI
        if (sender === 'ai') {
            msgDiv.innerHTML = marked.parse(text);
        } else {
            msgDiv.textContent = text;
        }

        wrapper.appendChild(header);
        wrapper.appendChild(msgDiv);
        chatbox.appendChild(wrapper);
        
        // Auto scroll
        chatbox.scrollTop = chatbox.scrollHeight;
    }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
