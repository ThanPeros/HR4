<?php
// config/ai_config.php

// --- OpenAI Configuration (Smart HR Intelligence Engine) ---
// MODE: SIMULATED (Local Intelligence)
// This key is auto-generated for the internal logic engine.
// To use real GPT-4, replace this with a valid key from https://platform.openai.com
define('OPENAI_API_KEY', 'sk-auto-generated-local-intelligence-engine-v1'); 
define('OPENAI_MODEL', 'gpt-4-turbo'); 
define('OPENAI_API_URL', 'https://api.openai.com/v1/chat/completions');

// --- Google Gemini Configuration (Legacy) ---
define('GEMINI_API_KEY', 'AIzaSyAgRheB8TgnFPSZWYz3lLKT6EU1xu5LPb0');
define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent');
?>
