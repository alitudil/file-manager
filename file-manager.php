<?php
// 1. Inject "📁 File Manager" into Admin Sidebar
add_action('admin_sidebar_menu', function($activePage) {
    $isActive = ($activePage === 'file_manager') ? 'active' : '';
    echo '<li class="' . $isActive . '"><a href="' . BASE_URL . '/plugins/file-manager/admin_page.php">📁 File Manager</a></li>';
});