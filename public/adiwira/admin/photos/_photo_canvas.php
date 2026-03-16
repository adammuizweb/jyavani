<?php
// /adiwira/admin/photos/_photo_canvas.php
require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}
if (!function_exists('e')) {
  function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// expects variables from index.php:
// $canvas_id, $input_id, $add_btn_id
?>
<div class="phtc-wrap">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:10px">
    <div>
      <div class="phtc-title">Foto</div>
      <div class="phtc-sub">Drag untuk urutkan. Foto pertama otomatis jadi cover.</div>
    </div>
    <div style="display:flex;align-items:center;gap:8px">
      <span id="<?= e($canvas_id) ?>-count" class="phtc-count">0</span>
      <button type="button" id="<?= e($add_btn_id) ?>" class="btn btn-insert">Tambah Foto</button>
    </div>
  </div>

  <input type="hidden" id="<?= e($input_id) ?>" value="[]">

  <div id="<?= e($canvas_id) ?>-empty" class="phtc-empty">
    Belum ada foto. Klik “Tambah Foto”.
  </div>

  <div id="<?= e($canvas_id) ?>" class="phtc-grid" style="margin-top:10px;display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px"></div>

<style>
  .phtc-wrap{
    border:1px solid var(--adam-border);
    border-radius:12px;
    padding:12px;
    background: var(--adam-card);
  }
  .phtc-item{
    position:relative;
    border:1px solid var(--adam-border);
    border-radius:12px;
    overflow:hidden;
    background: var(--adam-card);
  }
  .phtc-img{
    width:100%;
    height:120px;
    object-fit:cover;
    display:block;
    background: var(--adam-surface-3);
  }
  .phtc-remove{
    position:absolute;
    top:8px;right:8px;
    border:0;
    background: rgba(0,0,0,.55);
    color:#fff;
    border-radius:10px;
    width:32px;height:32px;
    cursor:pointer;
  }
  .phtc-badge{
    position:absolute;
    left:8px;top:8px;
    background: var(--adam-primary);
    color:#fff;
    font-size:11px;
    font-weight:900;
    border-radius:999px;
    padding:4px 8px;
  }

  /* Pager (theme-aware) */
  #pPager{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
    padding:10px 12px;
    border-top:1px solid var(--adam-border);
    background: var(--adam-card);
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
  }
  #pPager > div{ display:flex; align-items:center; gap:6px; }

  #pPager button{
    appearance:none;
    border:1px solid var(--adam-border-2);
    background: var(--adam-card);
    color: var(--adam-text-2);
    padding:6px 10px;
    border-radius:8px;
    font-size:13px;
    cursor:pointer;
    font-weight:900;
  }
  #pPager button:hover{ background: var(--adam-hover); }

  #pNext{
    background: var(--adam-primary);
    border-color: var(--adam-primary);
    color:#fff;
  }
  #pNext:hover{ background: var(--adam-primary-600); }

  #pPager button:disabled{ opacity:.45; cursor:not-allowed; }

  #pPageInfo, #pPager span{ font-size:12px; color: var(--adam-muted); }
  #pPer{
    padding:6px 8px;
    border-radius:8px;
    border:1px solid var(--adam-border-2);
    background: var(--adam-card);
    color: var(--adam-text-2);
    font-size:13px;
  }

  @media (max-width: 520px){
    #pPager{ flex-direction:column; align-items:stretch; gap:10px; }
    #pPager > div{ justify-content:space-between; }
    #pPageInfo{ text-align:right; }
  }
      .phtc-title{ font-weight:900; color: var(--adam-text-2); }
    .phtc-sub{ font-size:12px; color: var(--adam-muted); margin-top:2px; }
    .phtc-count{
      display:inline-flex;
      min-width:28px;
      justify-content:center;
      align-items:center;
      padding:4px 10px;
      border-radius:999px;
      background: var(--adam-primary);
      color:#fff;
      font-weight:900;
      font-size:12px;
    }
    .phtc-empty{ margin-top:10px; color: var(--adam-muted); font-size:12px; }
</style>
</div>
