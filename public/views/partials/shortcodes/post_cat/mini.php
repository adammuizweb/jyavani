<?php
// Compact horizontal list — ideal for sidebar widgets.
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
<div class="pcat pcat--mini<?= $extra ?>" data-pcat-layout="mini">
<?php endif; ?>

  <div class="pcat__track">
    <?php foreach ($items as $it): ?>
      <?php
        if (!is_array($it)) continue;

        $title = $esc($it['title'] ?? '');
        $url = $esc($it['url'] ?? '#');
        $thumb = trim((string)($it['thumb'] ?? ''));
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

<?php if (!defined('PCAT_MINI_CSS')): define('PCAT_MINI_CSS', true); ?>
<style id="pcat-mini-css">
  .pcat--mini{
    --pcat-gap: var(--space-3, 10px);
    --pcat-radius: var(--radius-md, 10px);
    --pcard-bg: var(--bg, #fff);
    --pcard-media: var(--surface-hover, #f7fafc);
    --pcard-text: var(--text, #0b1220);
    --pcard-muted: var(--muted, #6b7280);
    --pcard-border: var(--border, #e6eef2);
    --pcard-accent: var(--accent, #00A89E);
  }

  .pcat--mini .pcat__track{
    display:grid;
    gap: var(--pcat-gap);
  }

  .pcat--mini .pcat__card{
    display:flex;
    align-items:flex-start;
    gap: 10px;
    padding: 8px;
    border-radius: var(--pcat-radius);
    border: 1px solid var(--pcard-border);
    background: var(--pcard-bg);
    text-decoration:none;
    color: var(--pcard-text);
    transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
  }

  .pcat--mini .pcat__card:hover{
    transform: translateY(-1px);
    box-shadow: 0 8px 22px rgba(2,6,23,0.08);
    border-color: var(--pcard-accent);
  }

  .pcat--mini .pcat__media{
    flex: 0 0 auto;
    width: 56px;
    height: 56px;
    border-radius: calc(var(--pcat-radius) - 2px);
    overflow:hidden;
    background: var(--pcard-media);
  }

  .pcat--mini .pcat__img{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
  }

  .pcat--mini .pcat__img--placeholder{
    width:100%;
    height:100%;
    background: linear-gradient(135deg, var(--pcard-accent), color-mix(in srgb, var(--pcard-accent) 60%, #000));
    opacity:.35;
  }

  .pcat--mini .pcat__body{
    flex: 1 1 auto;
    min-width: 0;
    display:grid;
    gap: 2px;
    align-content:center;
  }

  .pcat--mini .pcat__kicker{
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: var(--pcard-accent);
  }

  .pcat--mini .pcat__title{
    margin:0;
    font-size: .92rem;
    line-height: 1.35;
    font-weight: 600;
    display:-webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow:hidden;
  }

  .pcat--mini .pcat__date{
    font-size: .78rem;
    color: var(--pcard-muted);
  }

  .pcat--mini .pcat__card:focus-visible{
    outline: 2px solid var(--pcard-accent);
    outline-offset: 2px;
  }
</style>
<?php endif; ?>
