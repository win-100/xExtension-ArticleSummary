if (document.readyState && document.readyState !== 'loading') {
  configureSummarizeButtons();
} else {
  document.addEventListener('DOMContentLoaded', configureSummarizeButtons, false);
}

function configureSummarizeButtons() {
  document.getElementById('global').addEventListener('click', function(e) {
    for (var target = e.target; target && target != this; target = target.parentNode) {
      if (target.matches('.oai-summary-btn')) {
        e.preventDefault();
        e.stopPropagation();
        if (target.dataset.request) {
          summarizeButtonClick(target);
        }
        break;
      }
    }
  }, false);
}

function setOaiState(container, statusType, statusMsg, summaryText) {
  var content = container.querySelector('.oai-summary-content');
  console.log('set...', content);
  content.innerHTML = 'asdfsdfsdfs'
  
  switch(statusType) {
    case 0:
      container.classList.remove('oai-loading');
      container.classList.remove('oai-error');
      content.innerHTML = '';
      break;
    case 1:
      container.classList.add('oai-loading');
      container.classList.remove('oai-error');
      content.innerHTML = statusMsg;
      break;
    case 2:
      container.classList.remove('oai-loading');
      container.classList.add('oai-error');
      content.innerHTML = statusMsg;
      break;
  }

  if (summaryText) {
    content.innerHTML = summaryText.replace(/(?:\r\n|\r|\n)/g, '<br>');
  }
}

function summarizeButtonClick(target) {
  var container = target.parentNode;
  if (container.classList.contains('oai-loading')) {
    return;
  }

  setOaiState(container, 1, '加载中', null);

  var url = target.dataset.request;
  var request = new XMLHttpRequest();
  request.open('POST', url, true);
  request.responseType = 'json';

  request.onload = function(e) {
    console.log(this, e);
    
    if (this.status != 200) {
      return request.onerror(e);
    }

    var xresp = xmlHttpRequestJson(this);
    console.log(xresp);
    
    if (!xresp) {
      return request.onerror(e);
    }

    if (xresp.status !== 200 || !xresp.response || !xresp.response.output_text) {
      return request.onerror(e);
    }

    if (xresp.response.error) {
      setOaiState(container, 2, xresp.response.output_text, null);
    } else {
      setOaiState(container, 0, null, xresp.response.output_text);
    }
  }

  request.onerror = function(e) {
    setOaiState(container, 2, '请求失败', null);
  }

  request.setRequestHeader('Content-Type', 'application/json');
  request.send(JSON.stringify({
    ajax: true,
    _csrf: context.csrf
  }));
}
