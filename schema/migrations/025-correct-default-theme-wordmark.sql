-- Correct untouched legacy Default Theme logo gadgets without overwriting custom HTML.
UPDATE `theme_zone_items`
SET `config` = JSON_SET(
  `config`,
  '$.html',
  REPLACE(
    JSON_UNQUOTE(JSON_EXTRACT(`config`, '$.html')),
    '<span class="letter base" data-word="Your">y</span>\n    <span class="letter accent" data-word="Visiting">v</span>',
    '<span class="letter base" data-word="Your">y</span>\n    <span class="letter base" data-word="Available">a</span>\n    <span class="letter accent" data-word="Visiting">v</span>'
  )
)
WHERE `theme_folder` = 'default'
  AND `type` = 'tz_html'
  AND JSON_VALID(`config`)
  AND (
    (`zone_slug` = 'header' AND `position` = 'logo'
      AND SHA2(JSON_UNQUOTE(JSON_EXTRACT(`config`, '$.html')), 256) = '1ce141aa442df82224f430e14b6c7cb1e45fafca319005372d8b1d35372fbaa4')
    OR
    (`zone_slug` = 'footer' AND `position` = 'about'
      AND SHA2(JSON_UNQUOTE(JSON_EXTRACT(`config`, '$.html')), 256) IN (
        '1ce141aa442df82224f430e14b6c7cb1e45fafca319005372d8b1d35372fbaa4',
        'a1c891a85a03950882ac89f9f5924e5e9992cee386f543eba2728ed5040616f1'
      ))
  );
