<?php
// Role permissions configuration
$roles_permissions = [
    'Super Admin' => [
        'can_access_admin' => true,
        'can_manage_staff' => true,
        'can_manage_reports' => true,  // Pastikan true
        'can_export' => true,
        'show_in_staff_list' => false,
        'redirect_after_login' => 'admin/dashboard.php'
    ],
    'Koordinator IT' => [
        'can_access_admin' => true,
        'can_manage_staff' => true,    // Harus true untuk kelola akun
        'can_manage_reports' => true,  // Harus true untuk kelola laporan
        'can_export' => true,
        'show_in_staff_list' => true,
        'redirect_after_login' => 'admin/dashboard.php'
    ],
    'Staff IT' => [
        // 'can_access_admin' => false,
        // 'can_manage_staff' => false,
        // 'can_manage_reports' => false,
        // 'can_export' => false,
        // 'show_in_staff_list' => true,
        // 'redirect_after_login' => 'form.php'

        'can_access_admin' => true,   // ⬅️ INI KUNCINYA
        'can_manage_staff' => false,
        'can_manage_reports' => false,
        'can_export' => false,
        'show_in_staff_list' => true,
        'redirect_after_login' => 'admin/dashboard.php'
    ]
];

function canAccessAdmin($jabatan) {
    global $roles_permissions;
    
    foreach ($roles_permissions as $role => $perms) {
        if (strtolower($role) === strtolower($jabatan)) {
            return $perms['can_access_admin'];
        }
    }
    
    return false;
}

function canManageStaff($jabatan) {
    global $roles_permissions;
    
    foreach ($roles_permissions as $role => $perms) {
        if (strtolower($role) === strtolower($jabatan)) {
            return $perms['can_manage_staff'];
        }
    }
    
    return false;
}

function canManageReports($jabatan) {
    global $roles_permissions;
    
    foreach ($roles_permissions as $role => $perms) {
        if (strtolower($role) === strtolower($jabatan)) {
            return $perms['can_manage_reports'];
        }
    }
    
    return false;
}

function showInStaffList($jabatan) {
    global $roles_permissions;
    
    foreach ($roles_permissions as $role => $perms) {
        if (strtolower($role) === strtolower($jabatan)) {
            return $perms['show_in_staff_list'];
        }
    }
    
    return true;
}

function getRedirectAfterLogin($jabatan) {
    global $roles_permissions;
    
    foreach ($roles_permissions as $role => $perms) {
        if (strtolower($role) === strtolower($jabatan)) {
            return $perms['redirect_after_login'];
        }
    }
    
    return 'form.php';
}
?>
