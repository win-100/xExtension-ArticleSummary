<?php
error_reporting(E_ERROR);
require __DIR__ . '/../Controllers/ArticleSummaryController.php';

// Stub classes and functions required by the controller
class Minz_ActionController {}
class FreshRSS_Context { public static $user_conf; }
class Minz_Request {
    public static $params = [];
    public static function param($name) {
        return self::$params[$name] ?? null; // No 'more' or 'id' parameter needed for this test
    }
}
class FreshRSS_Factory {
    public static function createEntryDao() {
        return new class {
            public function searchById($id) {
                return new class {
                    public function content() {
                        return 'Example content';
                    }
                };
            }
        };
    }
}

// Set up configuration with a specific model name
FreshRSS_Context::$user_conf = (object) [
    'oai_url' => 'https://api.example.com',
    'oai_key' => 'test-key',
    'oai_model' => 'my-configured-model',
    'oai_prompt' => 'prompt',
    'oai_prompt_2' => 'prompt2',
    'oai_provider' => 'openai',
    'oai_tts_model' => 'my-tts-model',
    'oai_voice' => 'my-voice',
];

// Capture the output of summarizeAction()
ob_start();
$controller = new FreshExtension_ArticleSummary_Controller();
$controller->view = new class {
    public function _layout($layout) {}
};
$controller->summarizeAction();
$output = ob_get_clean();

// Decode the JSON response
$data = json_decode($output, true);
$model = $data['response']['data']['model'] ?? null;

// Simple assertion
if ($model !== 'my-configured-model') {
    echo "Model mismatch: expected my-configured-model, got {$model}\n";
    exit(1);
}

echo "Model matches configuration\n";

// Test fetchTtsParamsAction()
ob_start();
$controller->fetchTtsParamsAction();
$ttsOutput = ob_get_clean();
$ttsData = json_decode($ttsOutput, true);
$voice = $ttsData['response']['data']['voice'] ?? null;

if ($voice !== 'my-voice') {
    echo "Voice mismatch: expected my-voice, got {$voice}\n";
    exit(1);
}

echo "Voice matches configuration\n";

// Test speakAction()
Minz_Request::$params = ['content' => 'Speak me'];
ob_start();
$controller->speakAction();
$speakOutput = ob_get_clean();
$speakData = json_decode($speakOutput, true);
$input = $speakData['response']['data']['input'] ?? null;

if ($input !== 'Speak me') {
    echo "Input mismatch: expected Speak me, got {$input}\n";
    exit(1);
}

echo "Speak action returns input\n";
