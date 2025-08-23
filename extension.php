<?php
class ArticleSummaryExtension extends Minz_Extension
{


  protected array $csp_policies = [
    'default-src' => '*',
  ];

  public function init()
  {
    $this->registerHook('entry_before_display', array($this, 'addSummaryButton'));
    $this->registerController('ArticleSummary');
    Minz_View::appendStyle($this->getFileUrl('style.css', 'css'));
    Minz_View::appendScript($this->getFileUrl('axios.js', 'js'));
    Minz_View::appendScript($this->getFileUrl('marked.js', 'js'));
    Minz_View::appendScript($this->getFileUrl('script.js', 'js'));
  }

  public function addSummaryButton($entry)
  {
    $url_summary = Minz_Url::display(array(
      'c' => 'ArticleSummary',
      'a' => 'summarize',
      'params' => array(
        'id' => $entry->id()
      )
    ));
    $url_more = Minz_Url::display(array(
      'c' => 'ArticleSummary',
      'a' => 'summarize',
      'params' => array(
        'id' => $entry->id(),
        'more' => 1
      )
    ));
    $has_more = trim((string)FreshRSS_Context::$user_conf->oai_prompt_2) !== '';

    $url_tts = Minz_Url::display(array(
      'c' => 'ArticleSummary',
      'a' => 'speak'
    ));
    $icon_tts_play = str_replace('<svg ', '<svg class="oai-tts-icon oai-tts-play" ', file_get_contents(__DIR__ . '/static/img/play.svg'));
    $icon_tts_pause = str_replace('<svg ', '<svg class="oai-tts-icon oai-tts-pause" ', file_get_contents(__DIR__ . '/static/img/pause.svg'));
    $icon = str_replace('<svg ', '<svg class="oai-summary-icon" ', file_get_contents(__DIR__ . '/static/img/summary.svg'));

    $entry->_content(
      '<div class="oai-summary-wrap">'
      . '<button data-request="' . $url_tts . '" class="oai-tts-btn btn btn-small" aria-label="Lire" title="Lire">' . $icon_tts_play . $icon_tts_pause . '</button>'
      . '<button data-request="' . $url_summary . '" class="oai-summary-btn btn btn-small" aria-label="Résumer" title="Résumer">'
      . $icon . '</button>'
      . '<div class="oai-summary-box">'
      . '<div class="oai-summary-loader"></div>'
      . '<div class="oai-summary-log"></div>'
      . '<div class="oai-summary-content"></div>'
      . ($has_more ? '<button data-request="' . $url_more . '" class="oai-summary-btn oai-summary-more btn btn-small" aria-label="Résumé plus long" title="Résumé plus long">+</button>' : '')
      . '</div>'
      . '</div>'
      . $entry->content()
    );
    return $entry;
  }

  public function handleConfigureAction()
  {
    if (Minz_Request::isPost()) {
      FreshRSS_Context::$user_conf->oai_url = Minz_Request::param('oai_url', '');
      FreshRSS_Context::$user_conf->oai_key = Minz_Request::param('oai_key', '');
      FreshRSS_Context::$user_conf->oai_model = Minz_Request::param('oai_model', '');
      FreshRSS_Context::$user_conf->oai_prompt = Minz_Request::param('oai_prompt', '');
      FreshRSS_Context::$user_conf->oai_prompt_2 = Minz_Request::param('oai_prompt_2', '');
      FreshRSS_Context::$user_conf->oai_provider = Minz_Request::param('oai_provider', '');
      FreshRSS_Context::$user_conf->oai_tts_model = Minz_Request::param('oai_tts_model', '');
      FreshRSS_Context::$user_conf->oai_voice = Minz_Request::param('oai_voice', '');
      FreshRSS_Context::$user_conf->save();
    }
  }
}
