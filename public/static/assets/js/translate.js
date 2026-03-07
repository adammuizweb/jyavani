// /static/assets/js/translate.js
(function () {
  console.log('translate.js loaded');

  const select = document.getElementById('lang-switch');
  const title = document.querySelector('[data-translatable-title]') ||
                document.querySelector('[data-translatable]');

  console.log('translate: select=', select, 'title=', title);

  if (!select || !title) {
    console.warn('translate: element not found - aborting');
    return;
  }

  const originalText = title.innerText ? title.innerText.trim() : '';
  console.log('translate: originalText length=', originalText.length);

  let translated = false;

  function markFail(reason) {
    console.warn('translate: failed ->', reason);
    alert('Translation unavailable: ' + reason);
    select.value = 'id';
  }

  select.addEventListener('change', async function () {
    console.log('translate: change event value=', this.value, 'translated=', translated);

    if (this.value !== 'en' || translated) {
      return;
    }

    if (!originalText) {
      markFail('no text to translate');
      return;
    }

    this.disabled = true;
    try {
      console.log('translate: sending request to /static/vendor/translate.php');
      const res = await fetch('/static/vendor/translate.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ text: originalText })
      });

      console.log('translate: fetch returned status', res.status);

      // try parse json safely
      let data;
      try {
        data = await res.json();
      } catch (e) {
        const txt = await res.text();
        console.error('translate: invalid json response ->', txt);
        markFail('invalid json response from server');
        return;
      }
      console.log('translate: response json ->', data);

      // accept multiple possible shapes
      const translatedText = data.text ?? data.translatedText ?? data.result ?? data.translation ?? null;

      if (translatedText && typeof translatedText === 'string') {
        title.innerText = translatedText;
        translated = true;
        console.log('translate: success - title replaced');
      } else {
        console.warn('translate: no translated text in response', data);
        markFail('no translated text in response');
      }
    } catch (err) {
      console.error('translate: fetch error', err);
      markFail('network or server error');
    } finally {
      this.disabled = false;
    }
  });
})();
