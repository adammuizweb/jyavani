<?php
/**
 * Shortcode layout template: sliderpage.php
 * Location:
 *   views/partials/shortcodes/post_cat/sliderpage.php
 *
 * Vars from shortcode engine:
 * - $items (array)
 * - $kicker (string)
 * - $class_prefix (string)
 * - $wrap (bool)
 * - $esc (callable)
 * - $attrs (array)
 * - $cat_key (string)
 */

// safe defaults
$items = (isset($items) && is_array($items)) ? $items : [];
$kicker = isset($kicker) ? (string)$kicker : '';
$class_prefix = isset($class_prefix) ? (string)$class_prefix : '';
$wrap = !empty($wrap);
$attrs = (isset($attrs) && is_array($attrs)) ? $attrs : [];
$cat_key = isset($cat_key) ? (string)$cat_key : '';

// safe esc fallback
if (!isset($esc) || !is_callable($esc)) {
    $esc = static function ($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    };
}

$extra = $class_prefix !== '' ? ' ' . $esc($class_prefix) : '';

// Title
$sectionTitle = trim((string)($attrs['section_title'] ?? $attrs['title'] ?? ''));
if ($sectionTitle === '') {
    $sectionTitle = ucwords(str_replace(['-', '_'], ' ', $cat_key !== '' ? $cat_key : 'News'));
}

// Link label + desc toggle
$linkLabel = trim((string)($attrs['link_label'] ?? 'Learn more'));
$showDesc  = !isset($attrs['show_desc']) ? true : ((string)$attrs['show_desc'] !== '0');

// Visible columns (CSS only; data count comes from DB fetch/limit)
$colsDesktop = (int)($attrs['show'] ?? 3);
if ($colsDesktop < 1) $colsDesktop = 3;

$colsTablet = (int)($attrs['show_tablet'] ?? 2);
if ($colsTablet < 1) $colsTablet = 2;

$colsMobile = (int)($attrs['show_mobile'] ?? 1);
if ($colsMobile < 1) $colsMobile = 1;

// icons (rotate)
$icons = [
  '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
     <path d="M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Zm6.1-1.4 4.3 4.3"
           fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
   </svg>',
  '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
     <path d="M4 19V5M4 19h16M8 16v-6m4 6V7m4 9v-4"
           fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
   </svg>',
  '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
     <path d="M7 8V7a5 5 0 0 1 10 0v1m-12 0h14l-1 12H6L5 8Z"
           fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
   </svg>',
  '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
     <path d="M6 7h12M6 12h12M6 17h8"
           fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
   </svg>',
  '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
     <path d="M12 3v18M3 12h18"
           fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
   </svg>',
];

// Always output a root wrapper (so JS works even if wrap=0)
$rootTag = $wrap ? 'section' : 'div';
$bareCls = $wrap ? '' : ' sliderpage-sec--bare';

// root style vars
$rootStyle = sprintf(
  '--sliderpage-cols-desktop:%d;--sliderpage-cols-tablet:%d;--sliderpage-cols-mobile:%d;',
  (int)$colsDesktop,
  (int)$colsTablet,
  (int)$colsMobile
);
?>

<<?= $rootTag ?> class="sliderpage-sec<?= $bareCls ?><?= $extra ?>" data-sliderpage="1" style="<?= $esc($rootStyle) ?>">
  <div class="sliderpage-container">
    <h2 class="sliderpage-title"><?= $esc($sectionTitle) ?></h2>

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
              $icon  = $icons[$i % count($icons)];
            ?>
            <article class="sliderpage-card">
              <div class="sliderpage-icon"><?= $icon ?></div>
              <h3 class="sliderpage-cardTitle"><?= $title ?></h3>

              <?php if ($showDesc && $desc !== ''): ?>
                <p class="sliderpage-desc"><?= $desc ?></p>
              <?php endif; ?>

              <a class="sliderpage-link" href="<?= $url ?>">
                <?= $esc($linkLabel) ?> <span aria-hidden="true">→</span>
              </a>
            </article>
          <?php endforeach; ?>
        </div>
      </div>

      <button class="sliderpage-arrow sliderpage-arrow--right" type="button" aria-label="Next">
        <span aria-hidden="true">›</span>
      </button>
    </div>
  </div>
</<?= $rootTag ?>>

<?php if (!defined('SLIDERPAGE_PCAT_ASSETS')): define('SLIDERPAGE_PCAT_ASSETS', true); ?>
<style id="sliderpage-styles">
  .sliderpage-sec{
    --sliderpage-bg: #f3eee8;
    --sliderpage-ink: #062b3e;
    --sliderpage-muted: rgba(6,43,62,.70);
    --sliderpage-line: #1bb2c5;
    --sliderpage-icon-bg: #062b3e;
    --sliderpage-card-bg: #ffffff;
    --sliderpage-maxw: 1220px;
    --sliderpage-gap: 26px;
    --sliderpage-cols: var(--sliderpage-cols-desktop, 3);
    background: var(--sliderpage-bg);
    padding: clamp(28px, 5vw, 64px) 0;
  }
  .sliderpage-sec *{ box-sizing: border-box; }

  .sliderpage-sec--bare{
    background: transparent;
    padding: 0;
  }

  @media (max-width: 980px){
    .sliderpage-sec{
      --sliderpage-cols: var(--sliderpage-cols-tablet, 2);
      --sliderpage-gap: 18px;
    }
  }
  @media (max-width: 620px){
    .sliderpage-sec{
      --sliderpage-cols: var(--sliderpage-cols-mobile, 1);
      --sliderpage-gap: 14px;
    }
  }

  .sliderpage-container{
    max-width: var(--sliderpage-maxw);
    margin: 0 auto;
    padding: 0 18px;
  }

  .sliderpage-title{
    margin: 0 0 clamp(18px, 3vw, 28px);
    text-align: center;
    font-family: ui-serif, Georgia, "Times New Roman", serif;
    font-size: clamp(34px, 4vw, 54px);
    font-weight: 800;
    color: var(--sliderpage-ink);
    letter-spacing: .4px;
  }

  .sliderpage-wrap{
    position: relative;
    display: grid;
    grid-template-columns: 56px 1fr 56px;
    align-items: center;
    gap: 14px;
  }

  .sliderpage-arrow{
    width: 56px;
    height: 56px;
    border: 0;
    background: transparent;
    color: rgba(6,43,62,.65);
    cursor: pointer;
    display: grid;
    place-items: center;
    border-radius: 999px;
    font-size: 40px;
    line-height: 1;
    user-select: none;
  }
  .sliderpage-arrow:hover{
    background: rgba(6,43,62,.06);
    color: rgba(6,43,62,.92);
  }
  .sliderpage-arrow[disabled]{ opacity: .35; cursor: default; }
  .sliderpage-arrow.is-hidden{
    opacity: 0;
    pointer-events: none;
  }

  @media (max-width: 980px){
    .sliderpage-wrap{ grid-template-columns: 44px 1fr 44px; }
    .sliderpage-arrow{ width: 44px; height: 44px; font-size: 34px; }
  }

  .sliderpage-viewport{
    overflow: hidden;
    width: 100%;
    touch-action: pan-y;
  }

  .sliderpage-track{
    display: grid;
    grid-auto-flow: column;
    gap: var(--sliderpage-gap);
    grid-auto-columns: calc(
      (100% - (var(--sliderpage-cols) - 1) * var(--sliderpage-gap))
      / var(--sliderpage-cols)
    );
    align-items: stretch;
    transition: transform .35s ease;
    will-change: transform;
  }

  .sliderpage-card{
    background: var(--sliderpage-card-bg);
    border: 2px solid var(--sliderpage-line);
    padding: 28px 28px 26px;
    min-height: 280px;
    display: flex;
    flex-direction: column;
    gap: 14px;
  }
  @media (max-width: 620px){
    .sliderpage-card{ min-height: 260px; padding: 22px; }
  }

  .sliderpage-icon{
    width: 48px;
    height: 48px;
    border-radius: 999px;
    background: var(--sliderpage-icon-bg);
    color: #fff;
    display: grid;
    place-items: center;
    margin-bottom: 6px;
    flex: 0 0 auto;
  }

  .sliderpage-cardTitle{
    margin: 0;
    color: var(--sliderpage-ink);
    font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
    font-size: 22px;
    font-weight: 800;
  }

  .sliderpage-desc{
    margin: 0;
    color: var(--sliderpage-muted);
    font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
    line-height: 1.6;
    max-width: 44ch;
  }

  .sliderpage-link{
    margin-top: auto;
    color: var(--sliderpage-ink);
    font-weight: 800;
    font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
    font-size: 13px;
    letter-spacing: .2px;
    text-decoration: none;
    display: inline-flex;
    gap: 10px;
    align-items: center;
  }
  .sliderpage-link:hover{ text-decoration: underline; }
</style>

<script id="sliderpage-script">
(function(){
  function initOne(root){
    const track = root.querySelector('.sliderpage-track');
    const viewport = root.querySelector('.sliderpage-viewport');
    const prev = root.querySelector('.sliderpage-arrow--left');
    const next = root.querySelector('.sliderpage-arrow--right');
    if (!track || !viewport || !prev || !next) return;

    let index = 0;

    function colsPerView(){
      const v = parseInt(getComputedStyle(root).getPropertyValue('--sliderpage-cols'), 10);
      return (Number.isFinite(v) && v > 0) ? v : 3;
    }

    function stepWidth(){
      const first = track.children[0];
      if (!first) return 0;
      const gap = parseFloat(getComputedStyle(track).gap || '0') || 0;
      return first.getBoundingClientRect().width + gap;
    }

    function render(){
      const cols = colsPerView();
      const total = track.children.length;
      const mx = Math.max(0, total - cols);

      if (index > mx) index = mx;
      if (index < 0) index = 0;

      const step = stepWidth();
      track.style.transform = step ? `translateX(${-index * step}px)` : 'translateX(0px)';

      const hideArrows = (mx === 0);
      prev.classList.toggle('is-hidden', hideArrows);
      next.classList.toggle('is-hidden', hideArrows);

      prev.disabled = (index === 0);
      next.disabled = (index === mx);
    }

    prev.addEventListener('click', () => { index -= 1; render(); });
    next.addEventListener('click', () => { index += 1; render(); });

    let sx = 0, dx = 0, isDown = false, pid = null;

    viewport.addEventListener('pointerdown', (e) => {
      isDown = true;
      pid = e.pointerId;
      sx = e.clientX;
      dx = 0;
      try { viewport.setPointerCapture(pid); } catch(_){}
    });

    viewport.addEventListener('pointermove', (e) => {
      if (!isDown) return;
      dx = e.clientX - sx;
    });

    function endSwipe(){
      if (!isDown) return;
      isDown = false;
      if (Math.abs(dx) > 40) index += (dx < 0 ? 1 : -1);
      render();
    }

    viewport.addEventListener('pointerup', endSwipe);
    viewport.addEventListener('pointercancel', endSwipe);

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