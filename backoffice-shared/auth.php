<?php
/**
 * Auth multi-tenant backoffice: 1 identitas User global bisa jadi anggota
 * banyak Organization dengan Role beda-beda (lihat DOKUMENTASI_ARSITEKTUR.md
 * bagian 3). Session nyimpen 2 lapis: user yang login, DAN organisasi aktif
 * yang lagi dipilih (kalau user cuma 1 org, langsung auto-aktif).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/modules.php';

function start_uo_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']),
        ]);
        session_start();
    }
}

function current_user(): ?array
{
    start_uo_session();
    return $_SESSION['uo_user'] ?? null;
}

function current_org(): ?array
{
    start_uo_session();
    return $_SESSION['uo_active'] ?? null;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        header('Location: login.php');
        exit;
    }
    return $user;
}

function require_org(): array
{
    require_login();
    $org = current_org();
    if (!$org) {
        header('Location: select-org.php');
        exit;
    }
    return $org;
}

/**
 * Semua User (username) & UserOrganizationRole cuma valid di database
 * ini — TIDAK ADA hubungannya sama admin_users di CMS Svashta, sengaja
 * dipisah total (lihat backoffice-shared/config.php).
 */
function attempt_login(string $email, string $password): bool
{
    $stmt = db()->prepare('SELECT id, name, email, password_hash, status FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || $user['status'] !== 'active' || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    start_uo_session();
    session_regenerate_id(true);
    $_SESSION['uo_user'] = ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email']];
    unset($_SESSION['uo_active']);
    return true;
}

/**
 * Self-signup + create-org: user baru langsung jadi Owner organisasi baru,
 * dengan Role "Owner" yang otomatis dikasih akses penuh ke semua modul.
 */
function register_user_and_org(string $name, string $email, string $password, string $orgName): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (?,?,?)')
            ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        $userId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO organizations (legal_name, created_by) VALUES (?,?)')
            ->execute([$orgName, $userId]);
        $orgId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO roles (organization_id, name, is_owner_role) VALUES (?,?,1)')
            ->execute([$orgId, 'Owner']);
        $roleId = (int) $pdo->lastInsertId();

        $accessStmt = $pdo->prepare('INSERT INTO role_module_access (role_id, module_key, can_view, can_create, can_edit, can_delete, can_print) VALUES (?,?,1,1,1,1,1)');
        foreach (array_keys(MODULES) as $moduleKey) {
            $accessStmt->execute([$roleId, $moduleKey]);
        }

        $pdo->prepare('INSERT INTO user_organization_roles (user_id, organization_id, role_id) VALUES (?,?,?)')
            ->execute([$userId, $orgId, $roleId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    start_uo_session();
    session_regenerate_id(true);
    $_SESSION['uo_user'] = ['id' => $userId, 'name' => $name, 'email' => $email];
    switch_org($userId, $orgId);
    return $orgId;
}

/** Daftar semua organisasi (+ role) yang diikuti seorang user. */
function list_memberships(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT o.id AS organization_id, o.legal_name, r.id AS role_id, r.name AS role_name
         FROM user_organization_roles uor
         JOIN organizations o ON o.id = uor.organization_id
         JOIN roles r ON r.id = uor.role_id
         WHERE uor.user_id = ? AND uor.status = "active"
         ORDER BY o.legal_name'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/** Pindah konteks organisasi aktif — validasi keanggotaan dulu, lalu cache matrix akses ke session. */
function switch_org(int $userId, int $organizationId): bool
{
    $stmt = db()->prepare(
        'SELECT o.id AS organization_id, o.legal_name, r.id AS role_id, r.name AS role_name
         FROM user_organization_roles uor
         JOIN organizations o ON o.id = uor.organization_id
         JOIN roles r ON r.id = uor.role_id
         WHERE uor.user_id = ? AND uor.organization_id = ? AND uor.status = "active"
         LIMIT 1'
    );
    $stmt->execute([$userId, $organizationId]);
    $membership = $stmt->fetch();
    if (!$membership) {
        return false;
    }

    $accessStmt = db()->prepare('SELECT module_key, can_view, can_create, can_edit, can_delete, can_print FROM role_module_access WHERE role_id = ?');
    $accessStmt->execute([$membership['role_id']]);
    $access = [];
    foreach ($accessStmt->fetchAll() as $row) {
        $access[$row['module_key']] = $row;
    }

    start_uo_session();
    $_SESSION['uo_active'] = $membership;
    $_SESSION['uo_access'] = $access;
    return true;
}

function has_access(string $moduleKey, string $action = 'can_view'): bool
{
    start_uo_session();
    return !empty($_SESSION['uo_access'][$moduleKey][$action]);
}

/** Dipanggil di awal tiap module page — mati kalau role aktif gak punya akses. */
function require_module_access(string $moduleKey, string $action = 'can_view'): void
{
    if (!has_access($moduleKey, $action)) {
        http_response_code(403);
        exit('Kamu tidak punya akses ke modul ini. Hubungi Owner organisasi buat minta akses.');
    }
}

function logout(): void
{
    start_uo_session();
    $_SESSION = [];
    session_destroy();
}

// --- CSRF token, pola sama kayak shared/auth.php CMS ---
function csrf_token(): string
{
    start_uo_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function require_csrf(): void
{
    start_uo_session();
    $token = $_POST['csrf_token'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        exit('Sesi tidak valid, silakan reload halaman dan coba lagi.');
    }
}
