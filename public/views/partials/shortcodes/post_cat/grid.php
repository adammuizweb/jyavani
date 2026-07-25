<?php
// Responsive grid of cards — ideal for homepage sections.
$items = (isset($items) && is_array($items)) ? $items : [];
$kicker = isset($kicker) ? (string)$kicker : '';
$class_prefix = isset($class_prefix) ? (string)$class_prefix : '';
$wrap = !empty($wrap);

if (!isset($esc) || !is_callable($esc)) {
    $esc = static function ($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    };
}

$extra = $class_prefix !== '' ? ' ' . $esc($class_prefix) : '';
?>

<?php if ($wrap): ?>
<div class="pcat pcat--grid<?= $extra ?>" data-pcat-layout="grid">
<?php endif; ?>

  <div class="pcat__track">
    <?php foreach ($items as $it): ?>
      <?php
        if (!is_array($it)) continue;

        $title = $esc($it['title'] ?? '');
        $url = $esc($it['url'] ?? '#');
        $thumb = trim((string)($it['thumb'] ?? ''));
        $desc = $esc($it['desc'] ?? '');
        $dateIso = $esc($it['date_iso'] ?? '');
        $dateLabel = $esc($it['date_label'] ?? '');
      ?>
      <article class="pcat__item">
        <a class="pcat__card" href="<?= $url ?>" aria-label="<?= $title ?>">
          <div class="pcat__media">
            <?php if ($thumb !== ''): ?>
              <img class="pcat__img" src="<?= $esc($thumb) ?>" alt="" loading="lazy" decoding="async">
            <?php else: ?>
              <div class="pcat__img pcat__img--placeholder" aria-hidden="true"></div>
            <?php endif; ?>
          </div>

          <div class="pcat__body">
            <?php if ($kicker !== ''): ?>
              <div class="pcat__kicker"><?= $esc($kicker) ?></div>
            <?php endif; ?>
            <h3 class="pcat__title"><?= $title ?></h3>
            <?php if ($desc !== ''): ?>
              <p class="pcat__desc"><?= $desc ?></p>
            <?php endif; ?>
            <?php if ($dateIso !== '' && $dateLabel !== ''): ?>
              <time class="pcat__date" datetime="<?= $dateIso ?>"><?= $dateLabel ?></time>
            <?php endif; ?>
          </div>
        </a>
      </article>
    <?php endforeach; ?>
  </div>

<?php if ($wrap): ?>
</div>
<?php endif; ?>

<?php if (!defined('PCAT_GRID_CSS')): define('PCAT_GRID_CSS', true); ?>
<style id="pcat-grid-css">
  .pcat--grid{
    --pcat-gap: var(--space-4, 18px);
    --pcat-radius: var(--radius-lg, 16px);
    --pcard-bg: var(--bg, #fff);
    --pcard-media: var(--surface-hover, #f7fafc);
    --pcard-text: var(--text, #0b1220);
    --pcard-muted: var(--muted, #6b7280);
    --pcard-border: var(--border, #e6eef2);
    --pcard-accent: var(--accent, #00A89E);
  }

  .pcat--grid .pcat__track{
    display:grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: var(--pcat-gap);
  }

  .pcat--grid .pcat__card{
    display:grid;
    grid-template-rows: 180px auto;
    height: 100%;
    border-radius: var(--pcat-radius);
    overflow:hidden;
    border: 1px solid var(--pcard-border);
    background: var(--pcard-bg);
    text-decoration:none;
    color: var(--pcard-text);
    box-shadow: 0 8px 26px rgba(2,6,23,0.06);
    transition: transform .18s ease, box-shadow .18s ease;
  }

  .pcat--grid .pcat__card:hover{
    transform: translateY(-3px);
    box-shadow: 0 14px 38px rgba(2,6,23,0.10);
  }

  .pcat--grid .pcat__media{
    overflow:hidden;
    background: var(--pcard-media);
  }

  .pcat--grid .pcat__img{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
    transition: transform .45s ease;
  }

  .pcat--grid .pcat__card:hover .pcat__img{
    transform: scale(1.05);
  }

  .pcat--grid .pcat__img--placeholder{
    width:100%;
    height:100%;
    background:
      radial-gradient(1200px 600px at 20% 10%, rgba(255,255,255,.18), transparent 40%),
      linear-gradient(135deg, var(--pcard-accent), color-mix(in srgb, var(--pcard-accent) 60%, #000));
  }

  .pcat--grid .pcat__body{
    display:grid;
    gap: 8px;
    padding: 16px;
    align-content:start;
  }

  .pcat--grid .pcat__kicker{
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .07em;
    text-transform: uppercase;
    color: var(--pcard-accent);
  }

  .pcat--grid .pcat__title{
    margin:0;
    font-size: 1.1rem;
    line-height: 1.25;
    font-weight: 700;
    display:-webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow:hidden;
  }

  .pcat--grid .pcat__desc{
    margin:0;
    font-size: .9rem;
    line-height: 1.45;
    color: var(--pcard-muted);
    display:-webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow:hidden;
  }

  .pcat--grid .pcat__date{
    font-size: .8rem;
    color: var(--pcard-muted);
  }

  .pcat--grid .pcat__card:focus-visible{
    outline: 2px solid var(--pcard-accent);
    outline-offset: 2px;
  }

  @media (max-width: 520px){
    .pcat--grid .pcat__track{
      grid-template-columns: 1fr;
    }
  }
</style>
<?php endif; ?>
