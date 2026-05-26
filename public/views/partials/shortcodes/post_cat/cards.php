<?php
// /views/partials/shortcodes/post_cat/cards.php
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
<div class="pcat pcat--cards<?= $extra ?>" data-pcat-layout="cards">
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

          <div class="pcat__overlay" aria-hidden="true"></div>

          <div class="pcat__body">
            <div class="pcat__kicker"><?= $esc($kicker) ?></div>
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

<style id="interact-postStyles">
  /* ==========================================================
     LAYOUT 1: CARDS  (template cards.php kamu memakai class pcat)
     Wrapper: .pcat.pcat--cards + class_prefix="interact-cards"
  ========================================================== */
  .interact-cards.pcat--cards{
    --pcat-gap: 16px;
    --pcat-radius: 18px;
    --pcat-card-w: 340px;
    --pcat-card-h: 460px;
  }

  .interact-cards.pcat--cards .pcat__track{
    display:flex;
    gap: var(--pcat-gap);
    overflow-x:auto;
    overscroll-behavior-x:contain;
    scroll-snap-type:x mandatory;
    -webkit-overflow-scrolling:touch;
    padding: 6px 2px 14px;
  }

  .interact-cards.pcat--cards .pcat__track::-webkit-scrollbar{ height: 10px; }
  .interact-cards.pcat--cards .pcat__track::-webkit-scrollbar-thumb{ background: rgba(0,0,0,.18); border-radius: 999px; }
  .interact-cards.pcat--cards .pcat__track::-webkit-scrollbar-track{ background: transparent; }

  .interact-cards.pcat--cards .pcat__item{ flex:0 0 auto; scroll-snap-align:start; }

  .interact-cards.pcat--cards .pcat__card{
    position:relative;
    display:block;
    width: var(--pcat-card-w);
    height: var(--pcat-card-h);
    border-radius: var(--pcat-radius);
    overflow:hidden;
    text-decoration:none;
    color:#fff;
    background:#0b1220;
    box-shadow: 0 12px 40px rgba(0,0,0,.18);
    transform: translateZ(0);
  }

  .interact-cards.pcat--cards .pcat__media{ position:absolute; inset:0; }
  .interact-cards.pcat--cards .pcat__img{
    width:100%; height:100%;
    object-fit:cover;
    display:block;
    transform: scale(1.02);
    transition: transform .55s ease;
  }

  .interact-cards.pcat--cards .pcat__img--placeholder{
    width:100%; height:100%;
    background:
      radial-gradient(1200px 600px at 20% 10%, rgba(255,255,255,.18), transparent 40%),
      linear-gradient(135deg, rgba(99,102,241,.8), rgba(16,185,129,.55));
  }

  .interact-cards.pcat--cards .pcat__overlay{
    position:absolute; inset:0;
    background: linear-gradient(to top, rgba(0,0,0,.74) 0%, rgba(0,0,0,.42) 35%, rgba(0,0,0,0) 70%);
    pointer-events:none;
  }

  .interact-cards.pcat--cards .pcat__body{
    position:absolute;
    left: 18px; right: 18px; bottom: 18px;
    display:grid;
    gap: 10px;
  }

  .interact-cards.pcat--cards .pcat__kicker{
    display:inline-flex;
    align-items:center;
    width: fit-content;
    padding: 6px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: .08em;
    text-transform: uppercase;
    background: rgba(0,0,0,.34);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    border: 1px solid rgba(255,255,255,.14);
  }

  .interact-cards.pcat--cards .pcat__title{
    margin:0;
    font-size: 24px;
    line-height: 1.1;
    font-weight: 900;
    text-shadow: 0 10px 28px rgba(0,0,0,.4);
    text-wrap: balance;
  }

  .interact-cards.pcat--cards .pcat__desc{
    margin:0;
    font-size: 14px;
    line-height: 1.35;
    color: rgba(255,255,255,.86);
    max-width: 36ch;
  }

  .interact-cards.pcat--cards .pcat__date{
    font-size: 12px;
    color: rgba(255,255,255,.78);
  }

  .interact-cards.pcat--cards .pcat__card:hover .pcat__img{ transform: scale(1.08); }
  .interact-cards.pcat--cards .pcat__card:focus-visible{
    outline: 3px solid rgba(99,102,241,.9);
    outline-offset: 3px;
    border-radius: calc(var(--pcat-radius) + 2px);
  }

  @media (max-width: 900px){
    .interact-cards.pcat--cards{ --pcat-card-w:300px; --pcat-card-h:420px; }
  }
  @media (max-width: 520px){
    .interact-cards.pcat--cards{ --pcat-card-w:78vw; --pcat-card-h:420px; }
    .interact-cards.pcat--cards .pcat__track{ gap:12px; }
  }
</style>