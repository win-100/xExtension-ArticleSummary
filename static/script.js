if (document.readyState && document.readyState !== 'loading') {
  configureSummarizeButtons();
} else {
  document.addEventListener('DOMContentLoaded', configureSummarizeButtons, false);
}

function configureSummarizeButtons() {
  document.getElementById('global').addEventListener('click', function (e) {
    for (var target = e.target; target && target != this; target = target.parentNode) {
      if (target.matches('.oai-summary-btn')) {
        e.preventDefault();
        e.stopPropagation();
        if (target.dataset.request) {
          summarizeButtonClick(target);
        }
        break;
      }
      if (target.matches('.oai-tts-btn')) {
        e.preventDefault();
        e.stopPropagation();
        if (target.dataset.request) {
          ttsButtonClick(target);
        }
        break;
      }
    }
  }, false);
}

function setOaiState(container, statusType, statusMsg, summaryText) {
  const button = container.querySelector('.oai-summary-btn');
  const moreButton = container.querySelector('.oai-summary-more');
  const content = container.querySelector('.oai-summary-content');
  const log = container.querySelector('.oai-summary-log');
  if (statusMsg !== null) {
    if (statusMsg === 'finish') {
      log.textContent = '';
      log.style.display = 'none';
    } else {
      log.textContent = statusMsg;
      log.style.display = 'block';
    }
  }
  if (statusType === 1) {
    container.classList.add('oai-loading');
    container.classList.remove('oai-error');
    button.disabled = true;
    content.innerHTML = '';
    if (moreButton) moreButton.style.display = 'none';
  } else if (statusType === 2) {
    container.classList.remove('oai-loading');
    container.classList.add('oai-error');
    button.disabled = false;
    content.innerHTML = '';
    if (moreButton) moreButton.style.display = 'none';
  } else {
    container.classList.remove('oai-loading');
    container.classList.remove('oai-error');
    if (statusMsg === 'finish') {
      button.disabled = false;
      if (container.dataset.moreUsed) {
        if (moreButton) {
          moreButton.remove();
        }
        delete container.dataset.moreUsed;
      } else if (moreButton) {
        moreButton.style.display = 'inline-block';
      }
    }
  }

  if (summaryText) {
    content.innerHTML = summaryText;
  }
}

async function summarizeButtonClick(target) {
  var container = target.closest('.oai-summary-wrap');
  if (container.classList.contains('oai-loading')) {
    return;
  }

  if (target.classList.contains('oai-summary-more')) {
    container.dataset.moreUsed = '1';
  }

  container.classList.add('oai-summary-active');

  setOaiState(container, 1, 'Preparing request...', null);

  // This is the address where PHP gets the parameters
  var url = target.dataset.request;
  var data = new URLSearchParams();
  data.append('ajax', 'true');
  data.append('_csrf', context.csrf);

  try {
    const response = await axios.post(url, data, {
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded'
      }
    });

    const xresp = response.data;
    console.log(xresp);

    if (response.status !== 200 || !xresp.response || !xresp.response.data) {
      throw new Error('Request Failed (1)');
    }

    if (xresp.response.error) {
      setOaiState(container, 2, xresp.response.data, null);
    } else {
      const oaiParams = xresp.response.data;
      const oaiProvider = xresp.response.provider;
      setOaiState(container, 1, `Pending answer from ${oaiProvider === 'openai' ? 'OpenAI' : 'Ollama'}...`, null);
      if (oaiProvider === 'openai') {
        await sendOpenAIRequest(container, oaiParams);
      } else {
        await sendOllamaRequest(container, oaiParams);
      }
    }
  } catch (error) {
    console.error(error);
    setOaiState(container, 2, 'Request Failed (2)', null);
  }
}

async function ttsButtonClick(target) {
  const container = target.closest('.oai-summary-wrap');
  const log = container.querySelector('.oai-summary-log');
  const text = container.querySelector('.oai-summary-content').textContent.trim();
  if (!text) {
    return;
  }

  const url = target.dataset.request;
  const data = new URLSearchParams();
  data.append('ajax', 'true');
  data.append('_csrf', context.csrf);
  data.append('content', text);

  target.disabled = true;
  log.textContent = 'Preparing audio...';
  log.style.display = 'block';

  try {
    const response = await axios.post(url, data, {
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded'
      }
    });

    const xresp = response.data;
    if (response.status !== 200 || !xresp.response || !xresp.response.data || xresp.response.error) {
      throw new Error('Request Failed');
    }

    const params = xresp.response.data;
    const body = {
      model: params.model,
      voice: params.voice,
      input: params.input
    };

    const audioResp = await fetch(params.oai_url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${params.oai_key}`
      },
      body: JSON.stringify(body)
    });

    if (!audioResp.ok) {
      throw new Error('Audio request failed');
    }

    const blob = await audioResp.blob();
    const audioUrl = URL.createObjectURL(blob);
    const audio = new Audio(audioUrl);
    audio.play();
    log.textContent = '';
    log.style.display = 'none';
  } catch (err) {
    console.error(err);
    log.textContent = 'Audio failed';
    log.style.display = 'block';
  } finally {
    target.disabled = false;
  }
}

async function sendOpenAIRequest(container, oaiParams) {
  try {
    let body = JSON.parse(JSON.stringify(oaiParams));
    delete body['oai_url'];
    delete body['oai_key'];
    const response = await fetch(oaiParams.oai_url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${oaiParams.oai_key}`
      },
      body: JSON.stringify(body)
    });

    if (!response.ok) {
      throw new Error('Request Failed (3)');
    }
    setOaiState(container, 1, 'Receiving answer...', null);

    const reader = response.body.getReader();
    const decoder = new TextDecoder('utf-8');
    let text = '';
    let buffer = '';

    while (true) {
      const { done, value } = await reader.read();
      if (done) {
        if (buffer.trim()) {
          try {
            const json = JSON.parse(buffer.trim());
            if (json.output_text) {
              text += json.output_text;
              setOaiState(container, 0, null, marked.parse(text));
            }
          } catch (e) {
            console.error('Error parsing final JSON:', e, 'Chunk:', buffer);
            setOaiState(container, 2, 'Request Failed (4)', null);
          }
        }
        setOaiState(container, 0, 'finish', null);
        break;
      }
      buffer += decoder.decode(value, { stream: true });

      // Split buffer into Server-Sent Events
      let parts = buffer.split(/\n\n/);
      buffer = parts.pop();
      for (let part of parts) {
        const lines = part.trim().split('\n');
        const dataLine = lines.find(l => l.startsWith('data:'));
        if (!dataLine) continue;
        let data = dataLine.slice(5).trim();
        if (data === '[DONE]') {
          setOaiState(container, 0, 'finish', null);
          return;
        }
        try {
          const json = JSON.parse(data);
          if (json.type === 'response.completed') {
            setOaiState(container, 0, 'finish', null);
            return;
          }
          const delta = json.delta || json.output_text || '';
          if (delta) {
            text += delta;
            setOaiState(container, 0, null, marked.parse(text));
          }
        } catch (e) {
          console.error('Error parsing JSON:', e, 'Chunk:', data);
        }
      }
    }
  } catch (error) {
    console.error(error);
    setOaiState(container, 2, 'Request Failed (5)', null);
  }
}


async function sendOllamaRequest(container, oaiParams){
  try {
    const response = await fetch(oaiParams.oai_url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${oaiParams.oai_key}`
      },
      body: JSON.stringify(oaiParams)
    });

    if (!response.ok) {
      throw new Error('Request Failed (6)');
    }
    setOaiState(container, 1, 'Receiving answer...', null);

    const reader = response.body.getReader();
    const decoder = new TextDecoder('utf-8');
    let text = '';
    let buffer = '';

    while (true) {
      const { done, value } = await reader.read();
      if (done) {
        setOaiState(container, 0, 'finish', null);
        break;
      }
      buffer += decoder.decode(value, { stream: true });
      // Try to process complete JSON objects from the buffer
      let endIndex;
      while ((endIndex = buffer.indexOf('\n')) !== -1) {
        const jsonString = buffer.slice(0, endIndex).trim();
        try {
          if (jsonString) {
            const json = JSON.parse(jsonString);
            text += json.response
            setOaiState(container, 0, null, marked.parse(text));
          }
        } catch (e) {
          // If JSON parsing fails, output the error and keep the chunk for future attempts
          console.error('Error parsing JSON:', e, 'Chunk:', jsonString);
        }
        // Remove the processed part from the buffer
        buffer = buffer.slice(endIndex + 1); // +1 to remove the newline character
      }
    }
  } catch (error) {
    console.error(error);
    setOaiState(container, 2, 'Request Failed (7)', null);
  }
}
