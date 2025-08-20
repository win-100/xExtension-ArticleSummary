<?php

class FreshExtension_ArticleSummary_Controller extends Minz_ActionController
{
  public function summarizeAction()
  {
    $this->view->_layout(false);
    // JSON - Set response header to JSON
    header('Content-Type: application/json');

    $oai_url = FreshRSS_Context::$user_conf->oai_url;
    $oai_key = FreshRSS_Context::$user_conf->oai_key;
    $oai_model = FreshRSS_Context::$user_conf->oai_model;
    $oai_prompt = FreshRSS_Context::$user_conf->oai_prompt;
    $oai_provider = FreshRSS_Context::$user_conf->oai_provider;

    if (
      $this->isEmpty($oai_url)
      || $this->isEmpty($oai_key)
      || $this->isEmpty($oai_model)
      || $this->isEmpty($oai_prompt)
    ) {
      echo json_encode(array(
        'response' => array(
          'data' => 'missing config',
          'error' => 'configuration'
        ),
        'status' => 200
      ));
      return;
    }

    $entry_id = Minz_Request::param('id');
    $entry_dao = FreshRSS_Factory::createEntryDao();
    $entry = $entry_dao->searchById($entry_id);

    if ($entry === null) {
      echo json_encode(array('status' => 404));
      return;
    }

    $content = $entry->content(); // Replace with article content

    // Convert HTML to Markdown and check for empty or image-only content
    $markdownContent = $this->htmlToMarkdown($content);
    /*$trimmedContent = trim($markdownContent);
    $withoutImages = trim(preg_replace('/img: `[^`]*`/', '', $trimmedContent));
    if ($trimmedContent === '' || $withoutImages === '') {
      // Fallback to description when main content is empty or contains only images
      $content = $entry->description();
      $markdownContent = $this->htmlToMarkdown($content);
    }*/

    // $oai_url
    $oai_url = rtrim($oai_url, '/'); // Remove the trailing slash
    // Ollama doesn't use versioned endpoints, so avoid appending /v1 for that provider
    if ($oai_provider !== 'ollama' && !preg_match('/\/v\d+\/?$/', $oai_url)) {
        $oai_url .= '/v1'; // If there is no version information, add /v1
    }
    // Open AI Input
    $successResponse = array(
      'response' => array(
        'data' => array(
          // Determine whether the URL ends with a version. If it does, no version information is added. If not, /v1 is added by default.
          "oai_url" => $oai_url . '/chat/completions',
          "oai_key" => $oai_key,
          "model" => $oai_model,
          "messages" => [
            [
              "role" => "system",
              "content" => $oai_prompt
          ],
          [
            "role" => "user",
            "content" => "input: \n" . $markdownContent,
          ]
        ],
        // `max_tokens` is deprecated; use `max_completion_tokens` instead.
        "max_completion_tokens" => 2048, // You can adjust the length of the summary as needed.
        "temperature" => 1, // gpt-5-nano expects 1
        "n" => 1 // Generate summary
      ),
      'provider' => 'openai',
      'error' => null
      ),
      'status' => 200
    );

    // Ollama API Input
    if ($oai_provider === "ollama") {
      $successResponse = array(
        'response' => array(
          'data' => array(
            "oai_url" => rtrim($oai_url, '/') . '/api/generate',
            "oai_key" => $oai_key,
            "model" => $oai_model,
            "system" => $oai_prompt,
            "prompt" =>  $markdownContent,
            "stream" => true,
          ),
          'provider' => 'ollama',
          'error' => null
        ),
        'status' => 200
      );
    }
    echo json_encode($successResponse);
    return;
  }

  private function isEmpty($item)
  {
    return $item === null || trim($item) === '';
  }

  private function htmlToMarkdown($content)
  {
    // Ensure DOM extension is available; otherwise fall back to plain text
    if (!class_exists('DOMDocument') || !class_exists('DOMXPath')) {
      return trim(strip_tags($content));
    }

    // Creating DOMDocument objects
    $dom = new DOMDocument();
    libxml_use_internal_errors(true); // Ignore HTML parsing errors
    $dom->loadHTML('<?xml encoding="UTF-8">' . $content);
    libxml_clear_errors();

    // Create XPath objects
    $xpath = new DOMXPath($dom);

    // Define an anonymous function to process the node
    $processNode = function ($node, $indentLevel = 0) use (&$processNode, $xpath) {
      $markdown = '';

      // Processing text nodes
      if ($node->nodeType === XML_TEXT_NODE) {
        $markdown .= trim($node->nodeValue);
      }

      // Processing element nodes
      if ($node->nodeType === XML_ELEMENT_NODE) {
        switch ($node->nodeName) {
          case 'p':
          case 'div':
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child);
            }
            $markdown .= "\n\n";
            break;
          case 'h1':
            $markdown .= "# ";
            $markdown .= $processNode($node->firstChild);
            $markdown .= "\n\n";
            break;
          case 'h2':
            $markdown .= "## ";
            $markdown .= $processNode($node->firstChild);
            $markdown .= "\n\n";
            break;
          case 'h3':
            $markdown .= "### ";
            $markdown .= $processNode($node->firstChild);
            $markdown .= "\n\n";
            break;
          case 'h4':
            $markdown .= "#### ";
            $markdown .= $processNode($node->firstChild);
            $markdown .= "\n\n";
            break;
          case 'h5':
            $markdown .= "##### ";
            $markdown .= $processNode($node->firstChild);
            $markdown .= "\n\n";
            break;
          case 'h6':
            $markdown .= "###### ";
            $markdown .= $processNode($node->firstChild);
            $markdown .= "\n\n";
            break;
          case 'a':
            // $markdown .= "[";
            // $markdown .= $processNode($node->firstChild);
            // $markdown .= "](" . $node->getAttribute('href') . ")";
            $markdown .= "`";
            $markdown .= $processNode($node->firstChild);
            $markdown .= "`";
            break;
          case 'img':
            $alt = $node->getAttribute('alt');
            $markdown .= "img: `" . $alt . "`";
            break;
          case 'strong':
          case 'b':
            $markdown .= "**";
            $markdown .= $processNode($node->firstChild);
            $markdown .= "**";
            break;
          case 'em':
          case 'i':
            $markdown .= "*";
            $markdown .= $processNode($node->firstChild);
            $markdown .= "*";
            break;
          case 'ul':
          case 'ol':
            $markdown .= "\n";
            foreach ($node->childNodes as $child) {
              if ($child->nodeName === 'li') {
                $markdown .= str_repeat("  ", $indentLevel) . "- ";
                $markdown .= $processNode($child, $indentLevel + 1);
                $markdown .= "\n";
              }
            }
            $markdown .= "\n";
            break;
          case 'li':
            $markdown .= str_repeat("  ", $indentLevel) . "- ";
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child, $indentLevel + 1);
            }
            $markdown .= "\n";
            break;
          case 'br':
            $markdown .= "\n";
            break;
          case 'audio':
          case 'video':
            $alt = $node->getAttribute('alt');
            $markdown .= "[" . ($alt ? $alt : 'Media') . "]";
            break;
          default:
            // Tags not considered, only the text inside is kept
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child);
            }
            break;
        }
      }

      return $markdown;
    };

    // Get all nodes
    $nodes = $xpath->query('//body/*');

    // Process all nodes
    $markdown = '';
    foreach ($nodes as $node) {
      $markdown .= $processNode($node);
    }

    // Remove extra line breaks
    $markdown = preg_replace('/(\n){3,}/', "\n\n", $markdown);
    
    return $markdown;
  }

}
