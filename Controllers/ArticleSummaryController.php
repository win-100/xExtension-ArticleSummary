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

    $content = $entry->content();
    $link = $entry->link();
    if (!$this->isEmpty($link)) {
      $html = $this->downloadHtml($link);
      if ($html !== false) {
        $extracted = $this->extractMainContent($html);
        if (!empty($extracted)) {
          $content = $extracted;
        }
      }
    }

    $markdown = $this->htmlToMarkdown($content);

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
            "content" => "input: \n" . $markdown,
          ]
        ],
        // `max_tokens` 已弃用，使用 `max_completion_tokens` -
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
            "prompt" =>  $markdown,
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

  private function downloadHtml($url)
  {
    $ch = curl_init($url);
    if ($ch === false) {
      return false;
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    $html = curl_exec($ch);
    if ($html === false || curl_errno($ch)) {
      curl_close($ch);
      return false;
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status >= 400) {
      return false;
    }
    return $html;
  }

  private function extractMainContent($html)
  {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $node = null;
    $nodes = $xpath->query('//article');
    if ($nodes->length > 0) {
      $node = $nodes->item(0);
    } else {
      $nodes = $xpath->query('//body');
      if ($nodes->length > 0) {
        $node = $nodes->item(0);
      }
    }

    if ($node === null) {
      return null;
    }

    foreach ($xpath->query('.//script|.//style', $node) as $bad) {
      $bad->parentNode->removeChild($bad);
    }

    return $dom->saveHTML($node);
  }

}
