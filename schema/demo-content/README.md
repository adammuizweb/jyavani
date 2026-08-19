# Demo Content Source

`manifest.json` version 1 is the canonical category, document, relationship, and preset inventory. `media.json` preserves media row metadata; document bodies are UTF-8 HTML files under `articles/` and `pages/`.

Regenerate with `php tools/build-demo-content.php`. CI or review checks should run `php tools/build-demo-content.php --check` and `php tests/demo_content_contract.php`.
