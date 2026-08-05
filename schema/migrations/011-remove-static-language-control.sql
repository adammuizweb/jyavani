-- Remove the obsolete static language dropdown from existing Default Theme controls.
UPDATE `theme_zone_items`
SET `config` = JSON_SET(
  `config`,
  '$.html',
  '<select id="themeSelect" class="ctrl-item blur-in onload" data-anime-trigger="load" data-duration="1700" data-delay="760">\n  <option value="light">Light</option>\n  <option value="dark">Dark</option>\n</select>\n<form method="get" action="/">\n  <input type="search" name="s" class="ctrl-item pop" data-anime-trigger="load" data-duration="1000" data-delay="1000" placeholder="Search..." style="width:120px;max-width:140px;">\n</form>'
)
WHERE `theme_folder` = 'default'
  AND `zone_slug` = 'header'
  AND `position` = 'controls'
  AND `type` = 'tz_html'
  AND `config` LIKE '%lang-switch%';
