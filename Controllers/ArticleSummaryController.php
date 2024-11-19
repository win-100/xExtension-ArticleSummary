<?php

class FreshExtension_ArticleSummary_Controller extends Minz_ActionController
{
  public function summarizeAction()
  {
    $this->view->_layout(false);
    // 设置响应头为 JSON
    header('Content-Type: application/json');

    $oai_url = FreshRSS_Context::$user_conf->oai_url;
    $oai_key = FreshRSS_Context::$user_conf->oai_key;
    $oai_model = FreshRSS_Context::$user_conf->oai_model;
    $oai_prompt = FreshRSS_Context::$user_conf->oai_prompt;

    if (
      $this->isEmpty($oai_url)
      || $this->isEmpty($oai_key)
      || $this->isEmpty($oai_model)
      || $this->isEmpty($oai_prompt)
    ) {
      echo json_encode(array(
        'response' => array(
          'output_text' => '未完成配置',
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

    $content = $entry->content(); // 替换为你的文章内容

    // 初始化 cURL 会话
    $ch = curl_init();

    $full_url = rtrim($oai_url, '/') . '/completions';

    // 设置 cURL 选项
    curl_setopt($ch, CURLOPT_URL, $full_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // 返回结果而不是直接输出
    curl_setopt($ch, CURLOPT_POST, true); // 设置请求方法为 POST
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      "Content-Type: application/json",
      "Authorization: Bearer " . $oai_key
    ]);

    // 设置请求体
    $requestBody = json_encode([
      "model" => $oai_model,
      "prompt" => $oai_prompt . "\n" . $this->htmlToMarkdown($content),
      "max_tokens" => 2048, // 你可以根据需要调整总结的长度
      "temperature" => 0.7, // 你可以根据需要调整生成文本的随机性
      "n" => 1 // 生成一个总结
    ]);

    // 临时测试
    // $successResponse = array(
    //   'response' => array(
    //     'output_text' => $oai_prompt . "\n" . $this->htmlToMarkdown($content),
    //     'error' => null
    //   ),
    //   'status' => 200
    // );
    // echo json_encode($successResponse);
    // return;

    // 打印请求体以进行调试
    error_log("Request Body: " . $requestBody);

    curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);

    // 设置超时时间
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // 设置超时时间为 30 秒

    // 允许重定向
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    // 执行 cURL 请求
    $response = curl_exec($ch);

    // 检查是否有错误
    if (curl_errno($ch)) {
      $errorResponse = array(
        'response' => array(
          'output_text' => '请求失败',
          'error' => 'cURL error: ' . curl_error($ch)
        ),
        'status' => 500
      );
      // 打印 cURL 错误信息以进行调试
      error_log("cURL Error: " . curl_error($ch));
      echo json_encode($errorResponse);
    } else {
      // 打印响应内容以进行调试
      error_log("Response: " . $response);

      // 解析响应
      $responseData = json_decode($response, true);

      // 检查响应是否成功
      if (isset($responseData['choices'][0]['text'])) {
        $summary = $responseData['choices'][0]['text'];
        $successResponse = array(
          'response' => array(
            'output_text' => $summary,
            'error' => null
          ),
          'status' => 200
        );
        echo json_encode($successResponse);
      } else {
        $errorResponse = array(
          'response' => array(
            'output_text' => '请求失败',
            'error' => 'API error: ' . print_r($responseData, true)
          ),
          'status' => 500
        );
        // 打印 API 错误信息以进行调试
        error_log("API Error: " . print_r($responseData, true));
        echo json_encode($errorResponse);
      }
    }

    // 关闭 cURL 会话
    curl_close($ch);
    // 终止脚本执行
    return;
  }

  private function isEmpty($item)
  {
    return $item === null || trim($item) === '';
  }

  private function htmlToMarkdown($content)
  {
    // 创建 DOMDocument 对象
    $dom = new DOMDocument();
    libxml_use_internal_errors(true); // 忽略 HTML 解析错误
    $dom->loadHTML('<?xml encoding="UTF-8">' . $content);
    libxml_clear_errors();

    // 创建 XPath 对象
    $xpath = new DOMXPath($dom);

    // 定义一个匿名函数来处理节点
    $processNode = function ($node, $indentLevel = 0) use (&$processNode, $xpath) {
      $markdown = '';

      // 处理文本节点
      if ($node->nodeType === XML_TEXT_NODE) {
        $markdown .= trim($node->nodeValue);
      }

      // 处理元素节点
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
            // 未考虑到的标签，只保留内部文字内容
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child);
            }
            break;
        }
      }

      return $markdown;
    };

    // 获取所有节点
    $nodes = $xpath->query('//body/*');

    // 处理所有节点
    $markdown = '';
    foreach ($nodes as $node) {
      $markdown .= $processNode($node);
    }

    // 去除多余的换行符
    $markdown = preg_replace('/(\n){3,}/', "\n\n", $markdown);

    // 调试信息
    error_log("Processed Markdown:\n" . $markdown);

    return $markdown;
  }

}