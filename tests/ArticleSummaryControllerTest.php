<?php
error_reporting(E_ERROR);
require __DIR__ . '/../Controllers/ArticleSummaryController.php';

// Stub classes and functions required by the controller
class Minz_ActionController {}
class FreshRSS_Context { public static $user_conf; }
class Minz_Request {
    public static function param($name) {
        return null; // No 'more' or 'id' parameter needed for this test
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
