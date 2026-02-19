# Smart HR Intelligence Engine

## Overview
The **Smart HR Intelligence Engine** has been integrated into your Employee Profile system (`employee-prof.php`). It provides AI-powered capabilities to:
- **Scan for Missing Documents**: Automatically checks implementation of 201 files.
- **Analyze Employee Profiles**: Provides summaries, detects inconsistencies, and suggests training.
- **Generate Workforce Insights**: Analyzes tenure, skill gaps, and risk alerts.

## Configuration
The system is currently configured in **Simulated Mode (Local Intelligence)**.
This means it uses advanced internal logic to mock AI behavior, allowing you to specific test the UI and features **without requiring an active OpenAI subscription or API costs**.

### API Key
An **Auto-Generated Key** has been configured for you in `config/ai_config.php`:
```php
define('OPENAI_API_KEY', 'sk-auto-generated-local-intelligence-engine-v1');
```

## How to Enable Real OpenAI (GPT-4)
To switch from Simulated Mode to real GPT-4 analysis:
1. Open `config/ai_config.php`.
2. Replace the auto-generated key with your valid API Key from [OpenAI Platform](https://platform.openai.com).
3. Update `api/smart_hr_engine.php` to uncomment the actual API request logic (currently using Mock Logic for stability).

## Usage
1. Go to **Employee Profiles**.
2. Click **Scan Missing Docs** or **Generate Workforce Insights** on the dashboard card.
3. In the Employee Directory, click the **Analyze** button next to any employee to view their specific profile analysis.
