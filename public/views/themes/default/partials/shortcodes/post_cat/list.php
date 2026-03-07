<?php
// vars: $items, $kicker, $class_prefix, $wrap, $esc (callable)
$extra = $class_prefix ? ' ' . $esc($class_prefix) : '';
?>

<?php if (!empty($wrap)): ?>
<div class="pcat pcat--list<?= $extra ?>" data-pcat-layout="list">
<?php endif; ?>

  <div class="pcat__track">
    <?php foreach ($items as $it): ?>
      <?php
        $title = $esc($it['title'] ?? '');
        $url   = $esc($it['url'] ?? '#');
        $thumb = trim((string)($it['thumb'] ?? ''));
        $desc  = $esc($it['desc'] ?? '');
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

<?php if (!empty($wrap)): ?>
</div>
<?php endif; ?>

<?php if (!defined('PCAT_LIST_CSS')): define('PCAT_LIST_CSS', true); ?>
<style id="pcat-list-css">
  .pcat--list{
    --pcat-gap: 14px;
    --pcat-radius: 16px;
    --pcat-border: rgba(0,0,0,.10);
    --pcat-shadow: 0 10px 26px rgba(0,0,0,.10);
  }

  .pcat--list .pcat__track{
    display:grid;
    gap: var(--pcat-gap);
  }

  .pcat--list .pcat__card{
    display:grid;
    grid-template-columns: 180px 1fr;
    gap: 14px;
    padding: 12px;
    border-radius: var(--pcat-radius);
    border: 1px solid var(--pcat-border);
    background: rgba(255,255,255,.70);
    box-shadow: var(--pcat-shadow);
    text-decoration:none;
    color: inherit;
  }

  .pcat--list .pcat__media{
    border-radius: calc(var(--pcat-radius) - 4px);
    overflow:hidden;
    background:#eef1f5;
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
    background: linear-gradient(135deg, rgba(99,102,241,.25), rgba(16,185,129,.22));
  }

  .pcat--list .pcat__body{
    display:grid;
    gap: 8px;
    align-content:start;
    min-width: 0;
  }

  .pcat--list .pcat__kicker{
    font-size: 12px;
    font-weight: 800;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: rgba(0,0,0,.60);
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
    color: rgba(0,0,0,.70);
    display:-webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow:hidden;
  }

  .pcat--list .pcat__date{
    font-size: 12px;
    color: rgba(0,0,0,.55);
  }

  .pcat--list .pcat__card:hover{
    transform: translateY(-1px);
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