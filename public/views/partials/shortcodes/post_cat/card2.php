<?php
// /views/partials/shortcodes/post_cat/card2.php
// vars: $items, $kicker, $class_prefix, $wrap, $esc (callable)

// safe defaults
$items = (isset($items) && is_array($items)) ? $items : [];
$kicker = isset($kicker) ? (string)$kicker : '';
$class_prefix = isset($class_prefix) ? (string)$class_prefix : '';
$wrap = !empty($wrap);

// safe esc fallback
if (!isset($esc) || !is_callable($esc)) {
    $esc = static function ($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    };
}

$extra = $class_prefix !== '' ? ' ' . $esc($class_prefix) : '';
?>

<?php if ($wrap): ?>
<div class="pcat pcat--slider-p<?= $extra ?>" data-pcat-layout="slider-p">
<?php endif; ?>

  <div class="pcat__track" role="list">
    <?php foreach ($items as $it): ?>
      <?php
        if (!is_array($it)) continue;

        $title = $esc($it['title'] ?? '');
        $url = $esc($it['url'] ?? '#');
        $thumb = trim((string)($it['thumb'] ?? ''));
        $dateIso = $esc($it['date_iso'] ?? '');
        $dateLabel = $esc($it['date_label'] ?? '');
      ?>
      <article class="pcat__item" role="listitem">
        <a class="pcat__card" href="<?= $url ?>" aria-label="<?= $title ?>">
          <div class="pcat__media">
            <?php if ($thumb !== ''): ?>
              <img class="pcat__img" src="<?= $esc($thumb) ?>" alt="" loading="lazy" decoding="async">
            <?php else: ?>
              <div class="pcat__img pcat__img--placeholder" aria-hidden="true"></div>
            <?php endif; ?>
          </div>

          <div class="pcat__body">
            <div class="pcat__kicker"><?= $esc($kicker) ?></div>
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

<?php if (!defined('PCAT_SLIDER_P_CSS')): define('PCAT_SLIDER_P_CSS', true); ?>
<style id="pcat-slider-p-css">
  .pcat--slider-p{
    --pcat-gap: 14px;
    --pcat-radius: 18px;
    --pcat-w: 240px;
    --pcat-h: 360px;
    --pcat-shadow: 0 14px 34px rgba(0,0,0,.14);
  }

  .pcat--slider-p .pcat__track{
    display:flex;
    gap: var(--pcat-gap);
    overflow-x:auto;
    padding: 6px 2px 14px;
    scroll-snap-type: x mandatory;
    overscroll-behavior-x: contain;
    -webkit-overflow-scrolling: touch;
  }

  .pcat--slider-p .pcat__item{
    flex: 0 0 auto;
    scroll-snap-align: start;
  }

  .pcat--slider-p .pcat__card{
    display:grid;
    grid-template-rows: 1fr auto;
    width: var(--pcat-w);
    height: var(--pcat-h);
    border-radius: var(--pcat-radius);
    overflow:hidden;
    text-decoration:none;
    color:#fff;
    background:#0b1220;
    box-shadow: var(--pcat-shadow);
  }

  .pcat--slider-p .pcat__media{
    position:relative;
    background:#111827;
  }

  .pcat--slider-p .pcat__img{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
    transform: scale(1.02);
    transition: transform .45s ease;
  }

  .pcat--slider-p .pcat__img--placeholder{
    width:100%;
    height:100%;
    background: radial-gradient(900px 450px at 20% 10%, rgba(255,255,255,.16), transparent 45%),
                linear-gradient(135deg, rgba(99,102,241,.75), rgba(16,185,129,.55));
  }

  .pcat--slider-p .pcat__body{
    padding: 12px 12px 14px;
    display:grid;
    gap: 6px;
    background: linear-gradient(to top, rgba(0,0,0,.75), rgba(0,0,0,.25));
  }

  .pcat--slider-p .pcat__kicker{
    font-size: 11px;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
    opacity:.85;
  }

  .pcat--slider-p .pcat__title{
    margin:0;
    font-size: 16px;
    line-height: 1.15;
    font-weight: 900;
    display:-webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow:hidden;
  }

  .pcat--slider-p .pcat__date{
    font-size: 12px;
    opacity: .80;
  }

  .pcat--slider-p .pcat__card:hover .pcat__img{
    transform: scale(1.08);
  }

  @media (max-width: 520px){
    .pcat--slider-p{ --pcat-w: 72vw; --pcat-h: 380px; }
  }
</style>
<?php endif; ?>