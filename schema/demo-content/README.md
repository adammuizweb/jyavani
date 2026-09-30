# Demo Content Source

`manifest.json` version 2 is the canonical category, document, relationship, and preset inventory. `media.json` preserves media row metadata; document bodies are UTF-8 HTML files under `articles/` and `pages/`.

Preset declarations may bind an example to a sidebar widget or a registered Theme Section. The `demo_home_posts` / `home.preset-posts` identity is intentionally checked across this manifest, generated SQL, the default-theme registry, its renderer, and homepage composition. Generated ownership uses `@jyavani_demo_owner_id`, which Pondasi sets to the actual initial Site Owner before import.

Regenerate with `php tools/build-demo-content.php`. CI or review checks should run `php tools/build-demo-content.php --check` and `php tests/demo_content_contract.php`.
