<?php
// ------------------------------------------------------------
// PERMISSION INTEGRATION: Determine which menu items to show
// ------------------------------------------------------------
// We assume session is already started and contains:
// $_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['user_permissions'] (for normal users)
$showAll = false;
$allowedPages = [];

if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
    $showAll = true;
} elseif (isset($_SESSION['user_permissions']) && is_array($_SESSION['user_permissions'])) {
    // Normal user: collect page URLs that have can_view = true
    foreach ($_SESSION['user_permissions'] as $url => $perms) {
        if ($perms['can_view']) {
            $allowedPages[] = $url;
        }
    }
}

// Helper function: check if a given page URL should be shown
if (!function_exists('menu_item_allowed')) {
    function menu_item_allowed($page_url, $showAll, $allowedPages) {
        if ($showAll) return true;
        return in_array($page_url, $allowedPages);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Garment ERP - Admin</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=yes" />
  <style>
  /* Color theme – same as before, kept unchanged */
  :root {
    --sidebar-width: 240px;
    --sidebar-collapsed: 70px;
    --dark-bg: #1E1E1E;
    --dark-medium: #2d2d2d;
    --dark-light: #3d3d3d;
    --primary: #F39C12;
    --primary-hover: #FFB347;
    --text-primary: #f1f5f9;
    --text-secondary: #cbd5e1;
    --border-light: rgba(255,255,255,0.08);
    --transition-speed: 0.3s;
  }

  * {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
  }

  body {
    font-family: "Inter", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    background: #f8fafc;
    color: #0f172a;
    overflow-x: hidden;
  }

  /* Top Navbar for Mobile */
  .top-navbar {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    height: 60px;
    background: var(--dark-bg);
    box-shadow: 0 2px 10px rgba(0,0,0,0.2);
    display: none;
    align-items: center;
    justify-content: space-between;
    padding: 0 16px;
    z-index: 1000;
    border-bottom: 1px solid var(--border-light);
  }

  .top-navbar-brand {
    color: var(--text-primary);
    font-size: 1.1rem;
    font-weight: 600;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 10px;
  }

  .top-navbar-brand i {
    color: var(--primary);
    font-size: 1.3rem;
  }

  .menu-toggle-btn {
    background: none;
    border: none;
    color: var(--text-primary);
    font-size: 1.5rem;
    cursor: pointer;
    padding: 10px 14px;
    border-radius: 10px;
    transition: all 0.2s;
  }

  .menu-toggle-btn:hover {
    background: rgba(243, 156, 18, 0.2);
    color: var(--primary);
  }

  /* Desktop Sidebar */
  .hello-sidebar {
    position: fixed;
    top: 0;
    left: 0;
    bottom: 0;
    width: var(--sidebar-width);
    background: var(--dark-bg);
    color: var(--text-primary);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    z-index: 2000;
    box-shadow: 2px 0 15px rgba(0,0,0,0.3);
    transition: all var(--transition-speed) ease;
    overflow-y: auto;
    overflow-x: hidden;
  }

  .hello-sidebar.hello-collapsed {
    width: var(--sidebar-collapsed);
  }

  /* Mobile Offcanvas */
  @media (max-width: 992px) {
    .top-navbar {
      display: flex;
    }

    .hello-sidebar {
      transform: translateX(-100%);
      width: 240px;
      top: 0;
      z-index: 2001;
      box-shadow: none;
      transition: transform 0.3s ease;
    }

    .hello-sidebar.hello-mobile-open {
      transform: translateX(0);
      box-shadow: 2px 0 15px rgba(0,0,0,0.3);
    }

    .hello-content {
      margin-left: 0 !important;
      padding-top: 70px !important;
    }
  }

  /* Sidebar Top Section */
  .hello-sidebar-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 18px 16px;
    border-bottom: 1px solid var(--border-light);
    background: var(--dark-bg);
  }

  .hello-sidebar-brand {
    display: flex;
    align-items: center;
    gap: 10px;
    text-decoration: none;
    color: var(--text-primary);
  }

  .hello-brand-icon {
    font-size: 1.3rem;
    color: var(--primary);
  }

  .hello-brand-text {
    font-weight: 600;
    font-size: 1rem;
    white-space: nowrap;
  }

  .hello-sidebar-toggle {
    background: rgba(255,255,255,0.05);
    border: none;
    color: var(--text-secondary);
    font-size: 1.1rem;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.2s;
  }

  .hello-sidebar-toggle:hover {
    background: var(--primary);
    color: white;
  }

  /* Mobile close button */
  .mobile-close-btn {
    display: none;
    background: rgba(255,255,255,0.05);
    border: none;
    color: var(--text-secondary);
    font-size: 1.2rem;
    width: 36px;
    height: 36px;
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.2s;
  }

  .mobile-close-btn:hover {
    background: rgba(243, 156, 18, 0.2);
    color: var(--primary);
  }

  @media (max-width: 992px) {
    .hello-sidebar-toggle {
      display: none;
    }
    .mobile-close-btn {
      display: flex;
      align-items: center;
      justify-content: center;
    }
  }

  /* Nav area */
  .hello-sidebar-nav {
    padding: 12px 12px;
    flex: 1;
    overflow-y: auto;
  }

  .hello-menu {
    list-style: none;
    margin: 0;
    padding: 0;
  }

  .hello-menu-item {
    margin: 6px 0;
  }

  /* Menu links */
  .hello-menu-link,
  .hello-submenu-link {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    padding: 12px 14px;
    border-radius: 12px;
    color: var(--text-secondary);
    text-decoration: none;
    background: transparent;
    border: none;
    cursor: pointer;
    font-size: 0.9rem;
    font-weight: 500;
    transition: all 0.2s ease;
    text-align: left;
  }

  .hello-menu-icon {
    width: 24px;
    text-align: center;
    font-size: 1rem;
    flex-shrink: 0;
  }

  .hello-menu-text {
    flex: 1;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .hello-menu-link:hover,
  .hello-submenu-link:hover {
    background: rgba(243, 156, 18, 0.15);
    color: white;
  }

  .hello-menu-link:hover .hello-menu-icon,
  .hello-submenu-link:hover i {
    color: var(--primary);
  }

  /* Submenu caret */
  .hello-submenu-caret {
    color: var(--text-secondary);
    font-size: 0.7rem;
    transition: transform 0.2s ease;
    margin-left: auto;
    flex-shrink: 0;
  }

  .hello-menu-item.hello-open .hello-submenu-caret {
    transform: rotate(180deg);
  }

  /* Submenus */
  .hello-submenu {
    list-style: none;
    margin: 0;
    padding: 0;
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.3s ease-out;
    background: rgba(0, 0, 0, 0.2);
    border-radius: 10px;
    margin-top: 4px;
  }

  .hello-menu-item.hello-open > .hello-submenu {
    max-height: 600px;
    transition: max-height 0.4s ease-in;
    padding: 8px 0;
  }

  .hello-submenu-link {
    padding: 10px 12px 10px 46px;
    font-size: 0.85rem;
    font-weight: 400;
    margin: 2px 8px;
    width: auto;
  }

  .hello-submenu-link i {
    width: 22px;
    text-align: center;
    font-size: 0.85rem;
    margin-right: 10px;
    color: var(--text-secondary);
  }

  /* Bottom section */
  .hello-sidebar-bottom {
    padding: 12px 16px;
    border-top: 1px solid var(--border-light);
    margin-top: auto;
  }

  .hello-logout-btn {
    display: flex;
    align-items: center;
    gap: 12px;
    color: var(--text-secondary);
    text-decoration: none;
    padding: 12px 14px;
    border-radius: 12px;
    font-weight: 500;
    font-size: 0.9rem;
    transition: all 0.2s;
  }

  .hello-logout-btn i {
    width: 24px;
    font-size: 1rem;
  }

  .hello-logout-btn:hover {
    background: rgba(220, 53, 69, 0.15);
    color: #dc3545;
  }

  /* Collapsed behavior for desktop */
  .hello-sidebar.hello-collapsed .hello-menu-text,
  .hello-sidebar.hello-collapsed .hello-brand-text,
  .hello-sidebar.hello-collapsed .hello-logout-btn .hello-menu-text,
  .hello-sidebar.hello-collapsed .hello-submenu {
    display: none !important;
  }

  .hello-sidebar.hello-collapsed .hello-menu-link {
    justify-content: center;
    padding: 12px 0;
  }

  .hello-sidebar.hello-collapsed .hello-menu-icon {
    width: auto;
    font-size: 1.2rem;
  }

  .hello-sidebar.hello-collapsed .hello-sidebar-top {
    justify-content: center;
    padding: 18px 0;
  }

  .hello-sidebar.hello-collapsed .hello-sidebar-brand {
    display: none;
  }

  .hello-sidebar.hello-collapsed .hello-sidebar-toggle {
    margin: 0 auto;
  }

  .hello-sidebar.hello-collapsed .hello-logout-btn {
    justify-content: center;
    padding: 12px 0;
  }

  .hello-sidebar.hello-collapsed .hello-logout-btn i {
    margin: 0;
    font-size: 1.2rem;
  }

  /* Content area */
  .hello-content {
    margin-left: var(--sidebar-width);
    padding: 24px 32px;
    transition: margin-left var(--transition-speed) ease;
    min-height: 100vh;
  }

  .hello-sidebar.hello-collapsed ~ .hello-content,
  .hello-sidebar.hello-collapsed + .hello-content {
    margin-left: var(--sidebar-collapsed);
  }

  @media (max-width: 992px) {
    .hello-content {
      margin-left: 0 !important;
      padding: 20px !important;
    }
  }

  /* Overlay for mobile */
  .sidebar-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.6);
    z-index: 1999;
    backdrop-filter: blur(3px);
  }

  .sidebar-overlay.active {
    display: block;
  }

  /* Scrollbar */
  .hello-sidebar-nav::-webkit-scrollbar {
    width: 4px;
  }

  .hello-sidebar-nav::-webkit-scrollbar-track {
    background: transparent;
  }

  .hello-sidebar-nav::-webkit-scrollbar-thumb {
    background: var(--dark-light);
    border-radius: 4px;
  }

  .hello-sidebar-nav::-webkit-scrollbar-thumb:hover {
    background: var(--primary);
  }
  </style>


  <!-- Top Navbar for Mobile -->
  <div class="top-navbar">
    <button class="menu-toggle-btn" id="mobileMenuToggle">
      <i class="fas fa-bars"></i>
    </button>
    <a href="../dashboard/dashboard.php" class="top-navbar-brand">
      <i class="fas fa-cubes"></i>
      <span>Garment ERP</span>
    </a>
    <div style="width: 50px;"></div>
  </div>

  <!-- Overlay -->
  <div class="sidebar-overlay" id="sidebarOverlay"></div>

  <!-- Sidebar -->
  <aside class="hello-sidebar" id="hello-sidebar">
    <div class="hello-sidebar-top">
      <a href="../dashboard/dashboard.php" class="hello-sidebar-brand">
        <span class="hello-brand-icon"><i class="fas fa-cubes"></i></span>
        <span class="hello-brand-text">Zohra fashion</span>
      </a>
      <button id="hello-sidebarToggle" class="hello-sidebar-toggle">
        <i class="fas fa-chevron-left"></i>
      </button>
      <button class="mobile-close-btn" id="mobileCloseBtn">
        <i class="fas fa-times"></i>
      </button>
    </div>

    <nav class="hello-sidebar-nav">
      <ul class="hello-menu">
        <!-- Dashboard -->
        <?php if (menu_item_allowed('dashboard.php', $showAll, $allowedPages)): ?>
        <li class="hello-menu-item">
          <a href="../dashboard/dashboard.php" class="hello-menu-link">
            <i class="fas fa-chart-line hello-menu-icon"></i>
            <span class="hello-menu-text">Dashboard</span>
          </a>
        </li>
        <?php endif; ?>

        <!-- Users (Admin only) -->
        <?php if ($showAll): ?>
        <li class="hello-menu-item">
          <a href="../admin/users.php" class="hello-menu-link">
            <i class="fas fa-users-cog hello-menu-icon"></i>
            <span class="hello-menu-text">Users</span>
          </a>
        </li>
        <?php endif; ?>

        <!-- Jobs -->
        <?php 
        $showJobs = $showAll || menu_item_allowed('jobs/add.php', $showAll, $allowedPages) || menu_item_allowed('jobs/list.php', $showAll, $allowedPages) || menu_item_allowed('jobs/job_details.php', $showAll, $allowedPages);
        if ($showJobs):
        ?>
        <li class="hello-menu-item has-sub">
          <button class="hello-menu-link hello-sub-toggle">
            <i class="fas fa-briefcase hello-menu-icon"></i>
            <span class="hello-menu-text">Jobs</span>
            <i class="fas fa-chevron-down hello-submenu-caret"></i>
          </button>
          <ul class="hello-submenu">
            <?php if ($showAll || menu_item_allowed('jobs/add.php', $showAll, $allowedPages)): ?>
            <li><a href="../jobs/add.php" class="hello-submenu-link"><i class="fas fa-plus-circle"></i> Add Job</a></li>
            <?php endif; ?>
            <?php if ($showAll || menu_item_allowed('jobs/list.php', $showAll, $allowedPages)): ?>
            <li><a href="../jobs/list.php" class="hello-submenu-link"><i class="fas fa-list-ul"></i> Job List</a></li>
            <?php endif; ?>
            <?php if ($showAll || menu_item_allowed('jobs/job_details.php', $showAll, $allowedPages)): ?>
            <li><a href="../jobs/job_details.php" class="hello-submenu-link"><i class="fas fa-info-circle"></i> Job Details</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>

        <!-- Fabric Purchase -->
        <?php 
        $showFabricPurchase = $showAll || menu_item_allowed('fabric/purchase_add.php', $showAll, $allowedPages) || menu_item_allowed('fabric/purchase_list.php', $showAll, $allowedPages);
        if ($showFabricPurchase):
        ?>
        <li class="hello-menu-item has-sub">
          <button class="hello-menu-link hello-sub-toggle">
            <i class="fas fa-shopping-cart hello-menu-icon"></i>
            <span class="hello-menu-text">Fabric Purchase</span>
            <i class="fas fa-chevron-down hello-submenu-caret"></i>
          </button>
          <ul class="hello-submenu">
            <?php if ($showAll || menu_item_allowed('fabric/purchase_add.php', $showAll, $allowedPages)): ?>
            <li><a href="../fabric/purchase_add.php" class="hello-submenu-link"><i class="fas fa-plus-circle"></i> Add Purchase</a></li>
            <?php endif; ?>
            <?php if ($showAll || menu_item_allowed('fabric/purchase_list.php', $showAll, $allowedPages)): ?>
            <li><a href="../fabric/purchase_list.php" class="hello-submenu-link"><i class="fas fa-list-ul"></i> Purchase List</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>

        <!-- Fabric Sale -->
        <?php 
        $showFabricSale = $showAll || menu_item_allowed('fabric/fabric_sale.php', $showAll, $allowedPages) || menu_item_allowed('fabric/fabric_sale_list.php', $showAll, $allowedPages);
        if ($showFabricSale):
        ?>
        <li class="hello-menu-item has-sub">
          <button class="hello-menu-link hello-sub-toggle">
            <i class="fas fa-chart-line hello-menu-icon"></i>
            <span class="hello-menu-text">Fabric Sale</span>
            <i class="fas fa-chevron-down hello-submenu-caret"></i>
          </button>
          <ul class="hello-submenu">
            <?php if ($showAll || menu_item_allowed('fabric/fabric_sale.php', $showAll, $allowedPages)): ?>
            <li><a href="../fabric/fabric_sale.php" class="hello-submenu-link"><i class="fas fa-plus-circle"></i> Add Sale</a></li>
            <?php endif; ?>
            <?php if ($showAll || menu_item_allowed('fabric/fabric_sale_list.php', $showAll, $allowedPages)): ?>
            <li><a href="../fabric/fabric_sale_list.php" class="hello-submenu-link"><i class="fas fa-list-ul"></i> Sale List</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>

        <!-- Material Module -->
        <?php 
        $showMaterial = $showAll || menu_item_allowed('material/raw_material_entry.php', $showAll, $allowedPages) || menu_item_allowed('material/raw_material_list.php', $showAll, $allowedPages);
        if ($showMaterial):
        ?>
        <li class="hello-menu-item has-sub">
          <button class="hello-menu-link hello-sub-toggle">
            <i class="fas fa-boxes hello-menu-icon"></i>
            <span class="hello-menu-text">Material</span>
            <i class="fas fa-chevron-down hello-submenu-caret"></i>
          </button>
          <ul class="hello-submenu">
            <?php if ($showAll || menu_item_allowed('material/raw_material_entry.php', $showAll, $allowedPages)): ?>
            <li><a href="../material/raw_material_entry.php" class="hello-submenu-link"><i class="fas fa-plus-circle"></i> Add Material</a></li>
            <?php endif; ?>
            <?php if ($showAll || menu_item_allowed('material/raw_material_list.php', $showAll, $allowedPages)): ?>
            <li><a href="../material/raw_material_list.php" class="hello-submenu-link"><i class="fas fa-list-ul"></i> Material List</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>

        <!-- Machines -->
        <?php 
        $showMachines = $showAll || menu_item_allowed('machines/add.php', $showAll, $allowedPages) || menu_item_allowed('machines/list.php', $showAll, $allowedPages);
        if ($showMachines):
        ?>
        <li class="hello-menu-item has-sub">
          <button class="hello-menu-link hello-sub-toggle">
            <i class="fas fa-cogs hello-menu-icon"></i>
            <span class="hello-menu-text">Machines</span>
            <i class="fas fa-chevron-down hello-submenu-caret"></i>
          </button>
          <ul class="hello-submenu">
            <?php if ($showAll || menu_item_allowed('machines/add.php', $showAll, $allowedPages)): ?>
            <li><a href="../machines/add.php" class="hello-submenu-link"><i class="fas fa-plus-circle"></i> Add Machine</a></li>
            <?php endif; ?>
            <?php if ($showAll || menu_item_allowed('machines/list.php', $showAll, $allowedPages)): ?>
            <li><a href="../machines/list.php" class="hello-submenu-link"><i class="fas fa-list-ul"></i> Machine List</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>

        <!-- Stitching -->
        <?php 
        $showStitching = $showAll || menu_item_allowed('stitching/entry.php', $showAll, $allowedPages) || menu_item_allowed('stitching/list.php', $showAll, $allowedPages);
        if ($showStitching):
        ?>
        <li class="hello-menu-item has-sub">
          <button class="hello-menu-link hello-sub-toggle">
            <i class="fas fa-tshirt hello-menu-icon"></i>
            <span class="hello-menu-text">Stitching</span>
            <i class="fas fa-chevron-down hello-submenu-caret"></i>
          </button>
          <ul class="hello-submenu">
            <?php if ($showAll || menu_item_allowed('stitching/entry.php', $showAll, $allowedPages)): ?>
            <li><a href="../stitching/entry.php" class="hello-submenu-link"><i class="fas fa-pen-alt"></i> Entry</a></li>
            <?php endif; ?>
            <?php if ($showAll || menu_item_allowed('stitching/list.php', $showAll, $allowedPages)): ?>
            <li><a href="../stitching/list.php" class="hello-submenu-link"><i class="fas fa-list-ul"></i> List</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>

        <!-- Embroidery -->
        <?php 
        $showEmbroidery = $showAll || menu_item_allowed('embroidery/entry.php', $showAll, $allowedPages) || menu_item_allowed('embroidery/list.php', $showAll, $allowedPages) || menu_item_allowed('embroidery/report.php', $showAll, $allowedPages);
        if ($showEmbroidery):
        ?>
        <li class="hello-menu-item has-sub">
          <button class="hello-menu-link hello-sub-toggle">
            <i class="fas fa-palette hello-menu-icon"></i>
            <span class="hello-menu-text">Embroidery</span>
            <i class="fas fa-chevron-down hello-submenu-caret"></i>
          </button>
          <ul class="hello-submenu">
            <?php if ($showAll || menu_item_allowed('embroidery/entry.php', $showAll, $allowedPages)): ?>
            <li><a href="../embroidery/entry.php" class="hello-submenu-link"><i class="fas fa-pen-alt"></i> Entry</a></li>
            <?php endif; ?>
            <?php if ($showAll || menu_item_allowed('embroidery/list.php', $showAll, $allowedPages)): ?>
            <li><a href="../embroidery/list.php" class="hello-submenu-link"><i class="fas fa-list-ul"></i> List</a></li>
            <?php endif; ?>
            <?php if ($showAll || menu_item_allowed('embroidery/report.php', $showAll, $allowedPages)): ?>
            <li><a href="../embroidery/report.php" class="hello-submenu-link"><i class="fas fa-chart-bar"></i> Report</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>

        <!-- Billing -->
        <?php 
        $showBilling = $showAll || menu_item_allowed('billing/post_bill.php', $showAll, $allowedPages) || menu_item_allowed('billing/production_billing_new.php', $showAll, $allowedPages);
        if ($showBilling):
        ?>
        <li class="hello-menu-item has-sub">
          <button class="hello-menu-link hello-sub-toggle">
            <i class="fas fa-file-invoice-dollar hello-menu-icon"></i>
            <span class="hello-menu-text">Billing</span>
            <i class="fas fa-chevron-down hello-submenu-caret"></i>
          </button>
          <ul class="hello-submenu">
            <?php if ($showAll || menu_item_allowed('billing/post_bill.php', $showAll, $allowedPages)): ?>
            <li><a href="../billing/post_bill.php" class="hello-submenu-link"><i class="fas fa-file-invoice"></i> Posted Bills</a></li>
            <?php endif; ?>
            <?php if ($showAll || menu_item_allowed('billing/production_billing_new.php', $showAll, $allowedPages)): ?>
            <li><a href="../billing/production_billing_new.php" class="hello-submenu-link"><i class="fas fa-file-invoice"></i> Production list</a></li>
            <?php endif; ?>
            <!-- NEW: Production Billing List (Admin only or as per permissions) -->
            <?php if ($showAll || menu_item_allowed('billing/production_bill_list.php', $showAll, $allowedPages)): ?>
            <li><a href="../billing/production_billf_list.php" class="hello-submenu-link"><i class="fas fa-list-ul"></i> Production Billing List</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>

        <!-- Ledger -->
        <?php 
        $showLedger = $showAll || menu_item_allowed('ledger/ledger_list.php', $showAll, $allowedPages) || menu_item_allowed('ledger/payment_entry.php', $showAll, $allowedPages) || menu_item_allowed('ledger/transaction_history.php', $showAll, $allowedPages);
        if ($showLedger):
        ?>
        <li class="hello-menu-item has-sub">
          <button class="hello-menu-link hello-sub-toggle">
            <i class="fas fa-book hello-menu-icon"></i>
            <span class="hello-menu-text">Ledger</span>
            <i class="fas fa-chevron-down hello-submenu-caret"></i>
          </button>
          <ul class="hello-submenu">
            <?php if ($showAll || menu_item_allowed('ledger/ledger_list.php', $showAll, $allowedPages)): ?>
            <li><a href="../ledger/ledger_list.php" class="hello-submenu-link"><i class="fas fa-list-ul"></i> Ledger List</a></li>
            <?php endif; ?>
            <?php if ($showAll || menu_item_allowed('ledger/payment_entry.php', $showAll, $allowedPages)): ?>
            <li><a href="../ledger/payment_entry.php" class="hello-submenu-link"><i class="fas fa-credit-card"></i> Payment Entry</a></li>
            <?php endif; ?>
            <?php if ($showAll || menu_item_allowed('ledger/transaction_history.php', $showAll, $allowedPages)): ?>
            <li><a href="../ledger/transaction_history.php" class="hello-submenu-link"><i class="fas fa-history"></i> Transaction History</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>

        <!-- Salary -->
        <?php 
        $showSalary = $showAll || menu_item_allowed('salary/operator_salary.php', $showAll, $allowedPages) || menu_item_allowed('salary/helper_salary.php', $showAll, $allowedPages) || menu_item_allowed('salary/salary_report.php', $showAll, $allowedPages) || menu_item_allowed('salary/salary_list.php', $showAll, $allowedPages) || menu_item_allowed('salary/helper_salary_list.php', $showAll, $allowedPages);
        if ($showSalary):
        ?>
        <li class="hello-menu-item has-sub">
          <button class="hello-menu-link hello-sub-toggle">
            <i class="fas fa-money-bill-wave hello-menu-icon"></i>
            <span class="hello-menu-text">Salary</span>
            <i class="fas fa-chevron-down hello-submenu-caret"></i>
          </button>
          <ul class="hello-submenu">
            <?php if ($showAll || menu_item_allowed('salary/operator_salary.php', $showAll, $allowedPages)): ?>
            <li><a href="../salary/operator_salary.php" class="hello-submenu-link"><i class="fas fa-user-cog"></i> Operator Salary</a></li>
            <?php endif; ?>
            
            <?php if ($showAll || menu_item_allowed('salary/helper_salary.php', $showAll, $allowedPages)): ?>
            <li><a href="../salary/helper_salary.php" class="hello-submenu-link"><i class="fas fa-user-friends"></i> Helper Salary</a></li>
            <?php endif; ?>
      
            <?php if ($showAll || menu_item_allowed('salary/salary_list.php', $showAll, $allowedPages)): ?>
            <li><a href="../salary/salary_list.php" class="hello-submenu-link"><i class="fas fa-list-ul"></i> Salary List</a></li>
            <?php endif; ?>
            <?php if ($showAll || menu_item_allowed('salary/helper_salary_list.php', $showAll, $allowedPages)): ?>
            <li><a href="../salary/helper_salary_list.php" class="hello-submenu-link"><i class="fas fa-list-ul"></i> Helper Salary List</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>

        <!-- Parties -->
        <?php 
        $showParties = $showAll || menu_item_allowed('parties/add.php', $showAll, $allowedPages) || menu_item_allowed('parties/list.php', $showAll, $allowedPages);
        if ($showParties):
        ?>
        <li class="hello-menu-item has-sub">
          <button class="hello-menu-link hello-sub-toggle">
            <i class="fas fa-users hello-menu-icon"></i>
            <span class="hello-menu-text">Parties</span>
            <i class="fas fa-chevron-down hello-submenu-caret"></i>
          </button>
          <ul class="hello-submenu">
            <?php if ($showAll || menu_item_allowed('parties/add.php', $showAll, $allowedPages)): ?>
            <li><a href="../parties/add.php" class="hello-submenu-link"><i class="fas fa-plus-circle"></i> Add Party</a></li>
            <?php endif; ?>
            <?php if ($showAll || menu_item_allowed('parties/list.php', $showAll, $allowedPages)): ?>
            <li><a href="../parties/list.php" class="hello-submenu-link"><i class="fas fa-list-ul"></i> Party List</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>

        <!-- Claims -->
        <?php 
        $showClaims = $showAll || menu_item_allowed('claims/add.php', $showAll, $allowedPages) || menu_item_allowed('claims/list.php', $showAll, $allowedPages);
        if ($showClaims):
        ?>
        <li class="hello-menu-item has-sub">
          <button class="hello-menu-link hello-sub-toggle">
            <i class="fas fa-file-contract hello-menu-icon"></i>
            <span class="hello-menu-text">Claims</span>
            <i class="fas fa-chevron-down hello-submenu-caret"></i>
          </button>
          <ul class="hello-submenu">
            <?php if ($showAll || menu_item_allowed('claims/add.php', $showAll, $allowedPages)): ?>
            <li><a href="../claims/add.php" class="hello-submenu-link"><i class="fas fa-plus-circle"></i> Add Claim</a></li>
            <?php endif; ?>
            <?php if ($showAll || menu_item_allowed('claims/list.php', $showAll, $allowedPages)): ?>
            <li><a href="../claims/list.php" class="hello-submenu-link"><i class="fas fa-list-ul"></i> Claim List</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>

        <!-- Reports -->
        <?php 
        $showReports = $showAll || menu_item_allowed('reports/fabric_report.php', $showAll, $allowedPages) || menu_item_allowed('reports/machine_report.php', $showAll, $allowedPages) || menu_item_allowed('reports/production_report.php', $showAll, $allowedPages);
        if ($showReports):
        ?>
        <li class="hello-menu-item has-sub">
          <button class="hello-menu-link hello-sub-toggle">
            <i class="fas fa-chart-simple hello-menu-icon"></i>
            <span class="hello-menu-text">Reports</span>
            <i class="fas fa-chevron-down hello-submenu-caret"></i>
          </button>
          <ul class="hello-submenu">
            <?php if ($showAll || menu_item_allowed('reports/fabric_report.php', $showAll, $allowedPages)): ?>
            <li><a href="../reports/fabric_report.php" class="hello-submenu-link"><i class="fas fa-box"></i> Fabric Report</a></li>
            <?php endif; ?>
            <?php if ($showAll || menu_item_allowed('reports/machine_report.php', $showAll, $allowedPages)): ?>
            <li><a href="../reports/machine_report.php" class="hello-submenu-link"><i class="fas fa-cogs"></i> Machine Report</a></li>
            <?php endif; ?>
            <?php if ($showAll || menu_item_allowed('reports/production_report.php', $showAll, $allowedPages)): ?>
            <li><a href="../reports/production_report.php" class="hello-submenu-link"><i class="fas fa-industry"></i> Production Report</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>
      </ul>
    </nav>

    <div class="hello-sidebar-bottom">
      <a href="../../logout.php" class="hello-logout-btn">
        <i class="fas fa-sign-out-alt"></i>
        <span class="hello-menu-text">Logout</span>
      </a>
    </div>
  </aside>
  <script>
  (function() {
    const sidebar = document.getElementById('hello-sidebar');
    const desktopToggle = document.getElementById('hello-sidebarToggle');
    const mobileToggle = document.getElementById('mobileMenuToggle');
    const mobileClose = document.getElementById('mobileCloseBtn');
    const overlay = document.getElementById('sidebarOverlay');
    const subToggles = document.querySelectorAll('.hello-sub-toggle');

    const isMobile = () => window.innerWidth <= 992;

    // Function to close mobile sidebar
    const closeMobileMenu = () => {
      sidebar.classList.remove('hello-mobile-open');
      if(overlay) overlay.classList.remove('active');
      document.body.style.overflow = '';
    };

    // Function to open mobile sidebar
    const openMobileMenu = () => {
      sidebar.classList.add('hello-mobile-open');
      if(overlay) overlay.classList.add('active');
      document.body.style.overflow = 'hidden';
    };

    // Initialize - close all submenus on page load
    document.querySelectorAll('.hello-menu-item.hello-open').forEach(item => {
      item.classList.remove('hello-open');
    });

    // Desktop toggle (collapse)
    if (desktopToggle) {
      desktopToggle.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        if (!isMobile()) {
          sidebar.classList.toggle('hello-collapsed');
          const icon = desktopToggle.querySelector('i');
          if (sidebar.classList.contains('hello-collapsed')) {
            icon.classList.remove('fa-chevron-left');
            icon.classList.add('fa-chevron-right');
          } else {
            icon.classList.remove('fa-chevron-right');
            icon.classList.add('fa-chevron-left');
          }
          localStorage.setItem('erp_sidebar_collapsed', sidebar.classList.contains('hello-collapsed'));
        }
      });
    }

    // Restore collapsed state (desktop only)
    if (!isMobile()) {
      const collapsed = localStorage.getItem('erp_sidebar_collapsed') === 'true';
      if (collapsed) {
        sidebar.classList.add('hello-collapsed');
        if (desktopToggle) {
          const icon = desktopToggle.querySelector('i');
          icon.classList.remove('fa-chevron-left');
          icon.classList.add('fa-chevron-right');
        }
      }
    }

    // Mobile: open sidebar
    if (mobileToggle) {
      mobileToggle.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        openMobileMenu();
      });
    }

    // Mobile: close sidebar via close button
    if (mobileClose) {
      mobileClose.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        closeMobileMenu();
      });
    }

    // Mobile: close sidebar via overlay click
    if (overlay) {
      overlay.addEventListener('click', () => {
        closeMobileMenu();
      });
    }

    // Close on window resize
    window.addEventListener('resize', () => {
      if (!isMobile()) {
        closeMobileMenu();
      }
    });

    // Accordion behavior - ONLY ONE OPEN AT A TIME
    subToggles.forEach(btn => {
      btn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const currentItem = this.closest('.hello-menu-item');
        const isCurrentlyOpen = currentItem.classList.contains('hello-open');
        
        // Close ALL open submenus first
        document.querySelectorAll('.hello-menu-item.hello-open').forEach(item => {
          if (item !== currentItem) {
            item.classList.remove('hello-open');
          }
        });
        
        // Toggle current menu
        if (!isCurrentlyOpen) {
          currentItem.classList.add('hello-open');
        } else {
          currentItem.classList.remove('hello-open');
        }
      });
    });

    // Navigation links - NO AUTO CLOSE on desktop, only on mobile
    const allLinks = document.querySelectorAll('.hello-menu-link, .hello-submenu-link');
    allLinks.forEach(link => {
      // Remove any existing href that might cause issues
      if(link.getAttribute('href') === '#') {
        link.setAttribute('href', 'javascript:void(0)');
      }
      
      link.addEventListener('click', (e) => {
        // Only close sidebar on mobile
        if (isMobile() && link.getAttribute('href') && link.getAttribute('href') !== 'javascript:void(0)') {
          setTimeout(closeMobileMenu, 50);
        }
      });
    });
  })();
  </script>
</body>
</html>