import { chromium } from './playwright/node_modules/playwright/index.mjs';

const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH ?? '/usr/bin/google-chrome' });

try {
  const page = await browser.newPage();
  await page.setContent('<base href="https://example.test/"><div id="editor"></div>');
  await page.addScriptTag({ path: new URL('../public/static/vendor/quill/quill.min.js', import.meta.url).pathname });
  await page.addScriptTag({ path: new URL('../public/static/js/edit/quill.js', import.meta.url).pathname });

  const result = await page.evaluate(() => {
    const policy = window.ADIWIRA.quill;
    const editor = new window.Quill('#editor', { theme: null, modules: { toolbar: false } });
    policy.configureInstance(editor);
    const gallery = '<div class="event-gallery custom-grid"><figure class="gallery-card"><img class="photo framed" src="/photo.webp" alt="Photo" width="905" height="1600"><figcaption class="caption">Caption</figcaption></figure></div>';
    const classedList = '<ul class="feature-list"><li class="feature-item">One</li></ul>';
    const classedTable = '<table class="report-table"><tbody class="report-body"><tr class="report-row"><td class="report-cell">Value</td></tr></tbody></table>';
    const html = '<h2 class="hero heading">Title</h2><p class="lead copy">Text <strong class="important loud">bold</strong> <img class="inline-photo framed" src="/inline.webp" alt="Inline"></p>' + gallery + classedList + classedTable + '<p>After</p>';
    editor.setContents(editor.clipboard.convert(html), 'silent');
    return {
      output: policy.getHTML(editor),
      gallery,
      classedList,
      classedTable,
      galleryComplex: policy.isHtmlComplex(gallery),
      figureComplex: policy.isHtmlComplex('<figure class="card"><img src="/one.webp" alt="One"><figcaption>One</figcaption></figure>'),
      classComplex: policy.isHtmlComplex('<p class="any classes-are-kept">Text <span class="accent">inline</span></p>'),
      ordinaryDivComplex: policy.isHtmlComplex('<div class="layout"><p>Unsupported layout</p></div>'),
      unsafeGalleryComplex: policy.isHtmlComplex('<div class="gallery"><figure><img src="javascript:alert(1)"></figure></div>'),
      editorHtml: editor.root.innerHTML
    };
  });

  const failures = [];
  const check = (condition, message) => {
    console.log(`${condition ? 'PASS' : 'FAIL'} ${message}`);
    if (!condition) failures.push(message);
  };

  check(result.galleryComplex === false && result.figureComplex === false, 'safe galleries and figures are eligible for Rich Editor mode');
  check(result.classComplex === false, 'arbitrary class values remain eligible for Rich Editor mode');
  check(result.ordinaryDivComplex === true && result.unsafeGalleryComplex === true, 'unsupported or unsafe div structures remain in Code Editor mode');
  check(result.output.includes('<h2 class="hero heading">Title</h2>'), 'block classes survive the Quill round trip');
  check(result.output.includes('<strong class="important loud">bold</strong>'), 'inline classes survive the Quill round trip');
  check(result.output.includes('class="inline-photo framed"'), 'image classes survive without an added span wrapper');
  check(result.output.includes(result.gallery), 'gallery structure, attributes, and captions survive the Quill round trip');
  check(result.output.includes(result.classedList) && result.output.includes(result.classedTable), 'classes on structural list and table containers survive atomically');
  check(!result.output.includes('data-jyavani-protected-html') && result.editorHtml.includes('data-jyavani-protected-html="1"'), 'canonical export removes only the internal atomic wrapper');

  if (failures.length > 0) throw new Error(`${failures.length} Rich Editor HTML check(s) failed`);
} finally {
  await browser.close();
}
