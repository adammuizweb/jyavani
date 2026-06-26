<?php
$items = (isset($items) && is_array($items)) ? $items : [];
$kicker = isset($kicker) ? (string)$kicker : '';
$class_prefix = isset($class_prefix) ? (string)$class_prefix : '';
$wrap = !empty($wrap);
$attrs = (isset($attrs) && is_array($attrs)) ? $attrs : [];

if (!isset($esc) || !is_callable($esc)) {
    $esc = static function ($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    };
}

$extra = $class_prefix !== '' ? ' ' . $esc($class_prefix) : '';

// Title
$sectionTitle = trim((string)($attrs['section_title'] ?? $attrs['title'] ?? ''));
if ($sectionTitle === '') {
    $sectionTitle = 'Artikel Terbaru';
}

// Show excerpt
$showDesc  = !isset($attrs['show_desc']) ? true : ((string)$attrs['show_desc'] !== '0');

// Visible columns
$colsDesktop = max(1, (int)($attrs['show'] ?? 3));
$colsTablet  = max(1, (int)($attrs['show_tablet'] ?? 2));
$colsMobile  = max(1, (int)($attrs['show_mobile'] ?? 1));

// root style vars
$rootStyle = sprintf(
  '--sliderpage-cols-desktop:%d;--sliderpage-cols-tablet:%d;--sliderpage-cols-mobile:%d;',
  $colsDesktop, $colsTablet, $colsMobile
);
?>

<?php if ($wrap): ?>
<section class="pcat pcat--sliderpage<?= $extra ?>" data-pcat-layout="sliderpage" data-sliderpage="1" style="<?= $esc($rootStyle) ?>">
<?php else: ?>
<div class="pcat pcat--sliderpage<?= $extra ?>" data-pcat-layout="sliderpage" data-sliderpage="1" style="<?= $esc($rootStyle) ?>">
<?php endif; ?>

  <div class="sliderpage-container">
    <?php if ($sectionTitle !== ''): ?>
      <h2 class="sliderpage-title"><?= $esc($sectionTitle) ?></h2>
    <?php endif; ?>

    <div class="sliderpage-wrap">
      <button class="sliderpage-arrow sliderpage-arrow--left" type="button" aria-label="Previous">
        <span aria-hidden="true">‹</span>
      </button>

      <div class="sliderpage-viewport" aria-roledescription="carousel">
        <div class="sliderpage-track">
          <?php foreach ($items as $i => $it): ?>
            <?php
              if (!is_array($it)) continue;

              $title = $esc($it['title'] ?? '');
              $url   = $esc($it['url'] ?? '#');
              $desc  = $esc($it['desc'] ?? '');
              $thumb = trim((string)($it['thumb'] ?? ''));
              $dateLabel = $esc($it['date_label'] ?? '');
            ?>
            <article class="sliderpage-card">
              <?php if ($thumb !== ''): ?>
                <div class="sliderpage-thumb">
                  <img src="<?= $esc($thumb) ?>" alt="" loading="lazy" decoding="async">
                </div>
              <?php endif; ?>
              <div class="sliderpage-card-body">
                <h3 class="sliderpage-cardTitle"><?= $title ?></h3>
                <?php if ($showDesc && $desc !== ''): ?>
                  <p class="sliderpage-desc"><?= $desc ?></p>
                <?php endif; ?>
                <?php if ($dateLabel !== ''): ?>
                  <time class="sliderpage-date"><?= $dateLabel ?></time>
                <?php endif; ?>
                <a class="sliderpage-link" href="<?= $url ?>">
                  Baca selengkapnya <span aria-hidden="true">→</span>
                </a>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </div>

      <button class="sliderpage-arrow sliderpage-arrow--right" type="button" aria-label="Next">
        <span aria-hidden="true">›</span>
      </button>
    </div>
  </div>

<?php if ($wrap): ?>
</section>
<?php else: ?>
</div>
<?php endif; ?>

<?php if (!defined('SLIDERPAGE_PCAT_ASSETS')): define('SLIDERPAGE_PCAT_ASSETS', true); ?>
<style id="sliderpage-styles">
  .pcat--sliderpage{
    --sp-bg: var(--bg, #fff);
    --sp-text: var(--text, #0b1220);
    --sp-muted: var(--muted, #6b7280);
    --sp-border: var(--border, #e6eef2);
    --sp-surface: var(--surface, #fff);
    --sp-accent: var(--accent, #00A89E);
    --sp-shadow: var(--shadow, 0 8px 24px rgba(2,6,23,0.08));
    --sp-radius: var(--radius-md, 12px);
    --sp-container: min(1100px, 100% - 36px);
    --sp-gap: var(--space-6, 24px);
    --sp-cols: var(--sliderpage-cols-desktop, 3);

    background: var(--sp-bg);
    padding: clamp(28px, 5vw, 64px) 0;
    container-type: inline-size;
  }

  .pcat--sliderpage .sliderpage-container{
    max-width: var(--sp-container);
    margin: 0 auto;
    padding: 0 18px;
  }

  .pcat--sliderpage .sliderpage-title{
    margin: 0 0 clamp(18px, 3vw, 28px);
    text-align: center;
    font-size: clamp(24px, 3.5vw, 42px);
    font-weight: 800;
    color: var(--sp-text);
    letter-spacing: -.02em;
  }

  .pcat--sliderpage .sliderpage-wrap{
    position: relative;
    display: grid;
    grid-template-columns: 44px 1fr 44px;
    align-items: center;
    gap: 12px;
  }

  .pcat--sliderpage .sliderpage-arrow{
    width: 44px;
    height: 44px;
    border: 1px solid var(--sp-border);
    background: var(--sp-surface);
    color: var(--sp-text);
    cursor: pointer;
    display: grid;
    place-items: center;
    border-radius: 999px;
    font-size: 32px;
    line-height: 1;
    user-select: none;
    transition: background .15s, color .15s;
    box-shadow: var(--sp-shadow);
  }
  .pcat--sliderpage .sliderpage-arrow:hover{
    background: var(--sp-accent);
    color: #fff;
    border-color: var(--sp-accent);
  }
  .pcat--sliderpage .sliderpage-arrow[disabled]{ opacity: .3; cursor: default; }
  .pcat--sliderpage .sliderpage-arrow.is-hidden{
    opacity: 0;
    pointer-events: none;
  }

  @container (max-width: 640px){
    .pcat--sliderpage .sliderpage-wrap{
      grid-template-columns: 36px 1fr 36px;
      gap: 8px;
    }
    .pcat--sliderpage .sliderpage-arrow{
      width: 36px;
      height: 36px;
      font-size: 24px;
    }
  }

  .pcat--sliderpage .sliderpage-viewport{
    overflow: hidden;
    width: 100%;
    touch-action: pan-y;
  }

  .pcat--sliderpage .sliderpage-track{
    display: grid;
    grid-auto-flow: column;
    gap: var(--sp-gap);
    grid-auto-columns: calc(
      (100% - (var(--sp-cols) - 1) * var(--sp-gap))
      / var(--sp-cols)
    );
    align-items: stretch;
    transition: transform .35s ease;
    will-change: transform;
  }

  .pcat--sliderpage .sliderpage-card{
    background: var(--sp-surface);
    border: 1px solid var(--sp-border);
    border-radius: var(--sp-radius);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: var(--sp-shadow);
    transition: box-shadow .2s ease;
  }

  .pcat--sliderpage .sliderpage-card:hover{
    box-shadow: 0 12px 32px rgba(2,6,23,0.12);
  }

  .pcat--sliderpage .sliderpage-thumb{
    aspect-ratio: 16/9;
    overflow: hidden;
    background: var(--sp-muted);
  }

  .pcat--sliderpage .sliderpage-thumb img{
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: transform .4s ease;
  }

  .pcat--sliderpage .sliderpage-card:hover .sliderpage-thumb img{
    transform: scale(1.06);
  }

  .pcat--sliderpage .sliderpage-card-body{
    padding: var(--space-5, 20px);
    display: flex;
    flex-direction: column;
    gap: var(--space-3, 12px);
    flex: 1;
  }

  .pcat--sliderpage .sliderpage-cardTitle{
    margin: 0;
    font-size: 18px;
    font-weight: 800;
    line-height: 1.2;
    color: var(--sp-text);
  }

  .pcat--sliderpage .sliderpage-desc{
    margin: 0;
    font-size: 14px;
    line-height: 1.55;
    color: var(--sp-muted);
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }

  .pcat--sliderpage .sliderpage-date{
    font-size: 12px;
    color: var(--sp-muted);
    opacity: .8;
  }

  .pcat--sliderpage .sliderpage-link{
    margin-top: auto;
    color: var(--sp-accent);
    font-weight: 700;
    font-size: 13px;
    text-decoration: none;
    display: inline-flex;
    gap: 6px;
    align-items: center;
    padding-top: var(--space-2, 8px);
    border-top: 1px solid var(--sp-border);
  }

  .pcat--sliderpage .sliderpage-link:hover{
    text-decoration: underline;
  }

  .pcat--sliderpage .sliderpage-card:focus-within{
    outline: 2px solid var(--sp-accent);
    outline-offset: 2px;
  }
</style>

<script id="sliderpage-script">
(function(){
  function initOne(root){
    var track = root.querySelector('.sliderpage-track');
    var viewport = root.querySelector('.sliderpage-viewport');
    var prev = root.querySelector('.sliderpage-arrow--left');
    var next = root.querySelector('.sliderpage-arrow--right');
    if (!track || !viewport || !prev || !next) return;

    var index = 0;

    function colsPerView(){
      var style = getComputedStyle(root);
      var v = parseInt(style.getPropertyValue('--sp-cols'), 10);
      return (Number.isFinite(v) && v > 0) ? v : 3;
    }

    function stepWidth(){
      var first = track.children[0];
      if (!first) return 0;
      var style = getComputedStyle(track);
      var gap = parseFloat(style.gap || '0') || 0;
      return first.getBoundingClientRect().width + gap;
    }

    function render(){
      var cols = colsPerView();
      var total = track.children.length;
      var mx = Math.max(0, total - cols);

      if (index > mx) index = mx;
      if (index < 0) index = 0;

      var step = stepWidth();
      track.style.transform = step ? 'translateX(' + (-index * step) + 'px)' : 'translateX(0px)';

      var hideArrows = (mx === 0);
      prev.classList.toggle('is-hidden', hideArrows);
      next.classList.toggle('is-hidden', hideArrows);

      prev.disabled = (index === 0);
      next.disabled = (index === mx);
    }

    prev.addEventListener('click', function(){ index -= 1; render(); });
    next.addEventListener('click', function(){ index += 1; render(); });

    var sx = 0, dx = 0, isDown = false, pid = null;

    viewport.addEventListener('pointerdown', function(e){
      isDown = true;
      pid = e.pointerId;
      sx = e.clientX;
      dx = 0;
      try { viewport.setPointerCapture(pid); } catch(_){}
    });

    viewport.addEventListener('pointermove', function(e){
      if (!isDown) return;
      dx = e.clientX - sx;
    });

    viewport.addEventListener('pointerup', function(){
      if (!isDown) return;
      isDown = false;
      if (Math.abs(dx) > 40) index += (dx < 0 ? 1 : -1);
      render();
    });

    viewport.addEventListener('pointercancel', function(){
      isDown = false;
      render();
    });

    window.addEventListener('resize', render);
    render();
  }

  function initAll(){
    document.querySelectorAll('[data-sliderpage]').forEach(initOne);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }
})();
</script>
<?php endif; ?>
