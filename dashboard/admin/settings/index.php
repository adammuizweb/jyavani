<?php
declare(strict_types=1);

// /adiwira/admin/pengaturan/?
require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$uid, $role] = adiwira_require_login($pdo, false);

$base = ADMIN_BASE_PATH;
$isAdmin = ($role === 'admin');

$items = [];

// urutan sinkron dengan aside:
// Website (admin), Sidebar (admin), Sidebar Widgets (admin), Menus (admin), Shortcodes (admin), Profile, Users (admin), Bin (admin)
if ($isAdmin) {
    $items[] = [
        'label' => 'Website',
        'href'  => $base . '/?page=admin/settings/site',
        'icon'  => '🌐',
        'desc'  => __('Manage site title and default website host.'),
        'badge' => 'Admin',
    ];

    $items[] = [
        'label' => 'Sidebar',
        'href'  => $base . '/?page=admin/settings/sidebar',
        'icon'  => '📐',
        'desc'  => __('Manage sidebar global enable/disable and default position.'),
        'badge' => 'Admin',
    ];

    $items[] = [
        'label' => 'Sidebar Widgets',
        'href'  => $base . '/?page=admin/sidebar/index',
        'icon'  => '🧩',
        'desc'  => __('Create multiple sidebar zones, manage widgets in each zone, select primary zone.'),
        'badge' => 'Admin',
    ];

    $items[] = [
        'label' => 'Menus',
        'href'  => $base . '/?page=admin/menus/index',
        'icon'  => '📋',
        'desc'  => __('Manage website navigation menus.'),
        'badge' => 'Admin',
    ];

    $items[] = [
        'label' => 'Shortcodes',
        'href'  => $base . '/?page=admin/shortcodes/index&tab=presets',
        'icon'  => '🔌',
        'desc'  => __('Create and manage shortcode presets for widgets.'),
        'badge' => 'Admin',
    ];

    $items[] = [
        'label' => 'Sign Up & Sign In',
        'href'  => $base . '/?page=admin/settings/auth',
        'icon'  => '🔐',
        'desc'  => __('Manage registration, login path, reCAPTCHA, and anti brute-force.'),
        'badge' => 'Admin',
    ];
}

$items[] = [
    'label' => 'Profile',
    'href'  => $base . '/?page=admin/profile/index',
    'icon'  => '🧑',
    'desc'  => __('Manage your profile, photo, bio, and password.'),
    'badge' => null,
];

if ($isAdmin) {
    $items[] = [
        'label' => 'Users',
        'href'  => $base . '/?page=admin/users/index',
        'icon'  => '👥',
        'desc'  => __('Manage user accounts and dashboard access roles.'),
        'badge' => 'Admin',
    ];

    $items[] = [
        'label' => 'Bin',
        'href'  => $base . '/?page=admin/bin/index',
        'icon'  => '🗑️',
        'desc'  => __('View deleted items and manage trash system.'),
        'badge' => 'Admin',
    ];
}
?>

<style>
.settingshub-wrap{
  max-width: 980px;
  margin: 18px auto;
}

.settingshub-head{
  display:flex;
  align-items:flex-end;
  justify-content:space-between;
  gap:16px;
  flex-wrap:wrap;
  margin-bottom:16px;
}

.settingshub-title{
  margin:0;
  color:var(--adam-text);
}

.settingshub-sub{
  margin:.4rem 0 0;
  color:var(--adam-muted);
  line-height:1.55;
}

.settingshub-grid{
  display:grid;
  grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));
  gap:14px;
}

.settingshub-card{
  display:flex;
  flex-direction:column;
  gap:10px;
  min-height:190px;
  padding:16px;
  border-radius:14px;
  border:1px solid var(--adam-border);
  background:linear-gradient(
    180deg,
    var(--adam-card) 0%,
    var(--adam-surface-2) 100%
  );
  box-shadow:var(--adam-shadow);
  text-decoration:none;
  transition:
    transform var(--transition-fast) ease,
    border-color var(--transition-fast) ease,
    background var(--transition-fast) ease,
    box-shadow var(--transition-fast) ease;
}

.settingshub-card:hover{
  transform:translateY(-2px);
  border-color:var(--adam-border-2);
  background:linear-gradient(
    180deg,
    var(--adam-surface-2) 0%,
    var(--adam-surface-3) 100%
  );
}

.settingshub-row{
  display:flex;
  align-items:center;
  gap:10px;
}

.settingshub-icon{
  width:42px;
  height:42px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  border-radius:12px;
  background:var(--adam-primary-soft);
  color:var(--adam-primary);
  font-size:1.15rem;
  flex:0 0 auto;
}

.settingshub-label{
  font-weight:700;
  color:var(--adam-text);
  line-height:1.25;
}

.settingshub-badge{
  margin-left:auto;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-height:28px;
  padding:0 .6rem;
  border-radius:999px;
  background:var(--adam-badge-bg);
  color:var(--adam-badge-text);
  border:1px solid var(--adam-badge-bd);
  font-size:.8rem;
  font-weight:700;
}

.settingshub-desc{
  color:var(--adam-text-3);
  line-height:1.55;
  font-size:.93rem;
  margin-top:2px;
}

.settingshub-arrow{
  margin-top:auto;
  color:var(--adam-link);
  font-weight:700;
  display:inline-flex;
  align-items:center;
  gap:6px;
}

.settingshub-note{
  margin-top:16px;
  padding:12px 14px;
  border-radius:12px;
  border:1px solid var(--adam-border);
  background:var(--adam-surface-3);
  color:var(--adam-text-3);
  line-height:1.55;
  font-size:.92rem;
}

@media (max-width: 640px){
  .settingshub-wrap{
    margin:14px auto;
  }

  .settingshub-card{
    min-height:unset;
  }
}
</style>

<section class="adam-card settingshub-wrap">
  <header class="settingshub-head">
    <div>
      <h1 class="settingshub-title"><?= _e('Settings') ?></h1>
      <p class="settingshub-sub">
        <?=_e('Manage account and system settings from one place.')?>
        <?=_e('The displayed menu follows your account access rights.')?>
      </p>
    </div>
  </header>

  <nav aria-label="<?=_e('Main Settings')?>" class="settingshub-grid">
    <?php foreach ($items as $it): ?>
      <a class="settingshub-card"
         href="<?= htmlspecialchars($it['href'], ENT_QUOTES, 'UTF-8') ?>">
        <div class="settingshub-row">
          <div class="settingshub-icon"><?= htmlspecialchars($it['icon'], ENT_QUOTES, 'UTF-8') ?></div>
          <div class="settingshub-label"><?= htmlspecialchars($it['label'], ENT_QUOTES, 'UTF-8') ?></div>
          <?php if (!empty($it['badge'])): ?>
            <span class="settingshub-badge"><?= htmlspecialchars($it['badge'], ENT_QUOTES, 'UTF-8') ?></span>
          <?php endif; ?>
        </div>

        <div class="settingshub-desc">
          <?= htmlspecialchars($it['desc'], ENT_QUOTES, 'UTF-8') ?>
        </div>

        <div class="settingshub-arrow">
          Buka menu <span aria-hidden="true">→</span>
        </div>
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="settingshub-note">
    Beberapa menu hanya tersedia untuk admin. Susunan menu di halaman ini sudah disamakan dengan grup
    <strong>Settings</strong> pada sidebar agar navigasi lebih konsisten.
  </div>
</section>