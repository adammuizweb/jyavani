<?php
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
<div class="pcat pcat--list<?= $extra ?>" data-pcat-layout="list">
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

<?php if (!defined('PCAT_LIST_CSS')): define('PCAT_LIST_CSS', true); ?>
<style id="pcat-list-css">
  .pcat--list{
    --pcat-gap: var(--space-4, 14px);
    --pcat-radius: var(--radius-lg, 16px);
    --pcard-bg: var(--bg, #fff);
    --pcard-shadow: var(--shadow, 0 8px 24px rgba(2,6,23,0.08));
    --pcard-media: var(--surface-hover, #f7fafc);
    --pcard-text: var(--text, #0b1220);
    --pcard-muted: var(--muted, #6b7280);
    --pcard-border: var(--border, #e6eef2);
    --pcard-accent: var(--accent, #00A89E);
  }

  .pcat--list .pcat__track{
    display:grid;
    gap: var(--pcat-gap);
  }

  .pcat--list .pcat__card{
    display:grid;
    grid-template-columns: 180px 1fr;
    gap: var(--space-4, 14px);
    padding: var(--space-3, 12px);
    border-radius: var(--pcat-radius);
    border: 1px solid var(--pcard-border);
    background: var(--pcard-bg);
    box-shadow: var(--pcard-shadow);
    text-decoration:none;
    color: var(--pcard-text);
    transition: transform .15s ease, box-shadow .15s ease;
  }

  .pcat--list .pcat__card:hover{
    transform: translateY(-2px);
    box-shadow: 0 12px 30px rgba(2,6,23,0.10);
  }

  .pcat--list .pcat__media{
    border-radius: calc(var(--pcat-radius) - 4px);
    overflow:hidden;
    background: var(--pcard-media);
    min-height: 110px;
  }

  .pcat--list .pcat__img{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
  }

  .pcat--list .pcat__img--placeholder{
    width:100%;
    height:100%;
    background: var(--pcard-media);
  }

  .pcat--list .pcat__body{
    display:grid;
    gap: var(--space-2, 8px);
    align-content:start;
    min-width: 0;
  }

  .pcat--list .pcat__kicker{
    font-size: 12px;
    font-weight: 800;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: var(--pcard-muted);
  }

  .pcat--list .pcat__title{
    margin:0;
    font-size: 18px;
    line-height: 1.15;
    font-weight: 900;
  }

  .pcat--list .pcat__desc{
    margin:0;
    font-size: 14px;
    line-height: 1.45;
    color: var(--pcard-muted);
    display:-webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow:hidden;
  }

  .pcat--list .pcat__date{
    font-size: 12px;
    color: var(--pcard-muted);
    opacity: .75;
  }

  .pcat--list .pcat__card:focus-visible{
    outline: 2px solid var(--pcard-accent);
    outline-offset: 2px;
  }

  @media (max-width: 640px){
    .pcat--list .pcat__card{
      grid-template-columns: 1fr;
    }
    .pcat--list .pcat__media{
      aspect-ratio: 16/9;
      min-height: 0;
    }
  }
</style>
<?php endif; ?>
