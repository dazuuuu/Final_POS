<?php
// app/helpers/StaffDay.php — staff work one calendar day at a time.
// At 12:00 am (the account's own midnight) a staff member still signed in — clocked out or not —
// is clocked out by the system and signed out; tomorrow they clock in again on the staff terminal
// before they can log in. Owners and admins are not affected.
class StaffDay
{
    public static function enforce(): void
    {
        if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'staff') { return; }
        $today = date('Y-m-d');
        if (!isset($_SESSION['staff_day'])) { $_SESSION['staff_day'] = $today; return; }
        if ($_SESSION['staff_day'] === $today) { return; }

        // The day changed while they were signed in.
        try {
            $pdo = Database::pdo();
            (new Models\TimeLogModel($pdo))->autoCloseOverdueForTenant((int) ($_SESSION['tenant_id'] ?? 0));
            UserSessions::end('midnight');
        } catch (\Throwable $e) { /* signing them out matters more */ }
        $slug = ShopUrl::current();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        if (strpos(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/api/') !== false) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'A new day has started — clock in again.']);
            exit;
        }
        $dest = $slug ? rtrim((string) BASE_URL, '/') . '/' . $slug . '/' : public_url('terminal/');
        header('Location: ' . $dest . '?newday=1');
        exit;
    }
}
