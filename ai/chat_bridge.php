<?php
// ai/chat_bridge.php
header('Content-Type: application/json');
require_once '../config/db.php';

// 1. RECEIVE USER QUERY
$data = json_decode(file_get_contents('php://input'), true);
$userQuestion = $data['message'] ?? '';
$apiKey = $data['apiKey'] ?? ''; // In a real app, store this securely in env/db!

// 2. GATHER DATABASE CONTEXT (The "Knowledge")
// We aggregate high-level stats to feed the AI
$context = [];

// Payroll Context
$context['payroll_current'] = $pdo->query("SELECT SUM(total_amount) FROM payroll_periods WHERE status = 'Released' AND MONTH(end_date) = MONTH(CURRENT_DATE())")->fetchColumn() ?: 0;
$context['payroll_last'] = $pdo->query("SELECT SUM(total_amount) FROM payroll_periods WHERE status = 'Released' AND MONTH(end_date) = MONTH(CURRENT_DATE() - INTERVAL 1 MONTH)")->fetchColumn() ?: 0;

// Employee Context
$context['active_employees'] = $pdo->query("SELECT COUNT(*) FROM employees WHERE status = 'Active'")->fetchColumn();
$context['departments'] = $pdo->query("SELECT department, COUNT(*) as count FROM employees WHERE status = 'Active' GROUP BY department")->fetchAll(PDO::FETCH_KEY_PAIR);

// Compliance Context
$context['uninsured'] = $pdo->query("SELECT COUNT(*) FROM employees e LEFT JOIN employee_benefit_profiles b ON e.id = b.employee_id AND b.benefit_type = 'HMO Coverage' WHERE e.status = 'Active' AND b.id IS NULL")->fetchColumn();

// 3. CONSTRUCT OPENAI PROMPT
$systemPrompt = "You are an expert HR Data Analyst for 'Slate Freight'. 
Current Data: " . json_encode($context) . ". 
User Question: \"$userQuestion\"
Analyze the data and answer briefly. If the user asks for suggestions, provide actionable advice based on the data.";

// 4. CALL OPENAI API
if (!empty($apiKey)) {
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer $apiKey"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model' => 'gpt-3.5-turbo',
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userQuestion]
        ],
        'max_tokens' => 150
    ]));
    
    $result = curl_exec($ch);
    $response = json_decode($result, true);
    curl_close($ch);
    
    $reply = $response['choices'][0]['message']['content'] ?? "Error connecting to AI Provider.";
} else {
    // FALLBACK SIMULATION (If no API Key provided)
    // We simulate a response based on keywords
    $q = strtolower($userQuestion);
    if (strpos($q, 'payroll') !== false) {
        $diff = $context['payroll_current'] - $context['payroll_last'];
        $trend = $diff > 0 ? "increased" : "decreased";
        $reply = "Based on the database, the current payroll is ₱" . number_format($context['payroll_current']) . ". It has $trend by ₱" . number_format(abs($diff)) . " compared to last month.";
    } elseif (strpos($q, 'employee') !== false || strpos($q, 'count') !== false) {
        $reply = "There are currently " . $context['active_employees'] . " active employees. The largest department is " . array_search(max($context['departments']), $context['departments']) . ".";
    } elseif (strpos($q, 'hmo') !== false || strpos($q, 'benefit') !== false) {
        $reply = "I found " . $context['uninsured'] . " active employees who are currently not enrolled in the HMO plan. You should review their eligibility.";
    } else {
        $reply = "I can analyze your HR data. Ask me about Payroll trends, Employee counts, or Benefit coverage.";
    }
}

echo json_encode(['reply' => $reply]);
?>
