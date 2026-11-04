<?php
// app/helpers/Quote.php
// "Request a quote" from the landing page. Saved for Support (support/quotes/),
// the customer gets a "we received your request — we'll call you" email, and
// the sales email (Support → Settings, else the support login) gets a copy.

class Quote
{
    /** Add-ons that are quoted per client and are not in the package catalogue. */
    const EXTRAS = ['website_api' => ['Website API (custom)', 0, 'Connect your website to your POS: live products, offers and stock, comments, likes, views and website orders.']];

    /** Package or add-on name for a key. */
    public static function label(string $key): string
    {
        return Features::catalog()[$key][0] ?? self::EXTRAS[$key][0] ?? $key;
    }

    const STATUSES = ['new' => 'New', 'contacted' => 'Contacted', 'won' => 'Became a client', 'closed' => 'Closed'];

    public static function ensureTable(PDO $pdo): void
    {
        static $done = false;
        if ($done) { return; }
        $pdo->exec("CREATE TABLE IF NOT EXISTS quote_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(150) NOT NULL,
            phone VARCHAR(40) NULL,
            package_key VARCHAR(40) NOT NULL,
            shop_name VARCHAR(150) NULL,
            business VARCHAR(500) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'new',
            ip VARCHAR(45) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_quote_status (status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = true;
    }

    /** Save a request and send the emails. Returns an error message, or null when done. */
    public static function submit(array $in): ?string
    {
        $name = trim((string) ($in['name'] ?? ''));
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        $phone = trim((string) ($in['phone'] ?? ''));
        $package = (string) ($in['package'] ?? '');
        $shop = trim((string) ($in['shop_name'] ?? ''));
        $business = trim((string) ($in['business'] ?? ''));
        $catalog = Features::catalog() + self::EXTRAS;
        if ($name === '') { return 'Enter your name.'; }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { return 'Enter a valid email address.'; }
        if (!isset($catalog[$package])) { return 'Choose a package.'; }
        if ($phone !== '' && !preg_match('/^[0-9 +()-]{7,20}$/', $phone)) { return 'Check the phone number.'; }

        $pdo = Database::pdo();
        self::ensureTable($pdo);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $st = $pdo->prepare('SELECT COUNT(*) FROM quote_requests WHERE ip = ? AND created_at >= NOW() - INTERVAL 1 HOUR');
        $st->execute([$ip]);
        if ((int) $st->fetchColumn() >= 5) { return 'We already have your requests — we will call you soon.'; }

        $pdo->prepare('INSERT INTO quote_requests (name, email, phone, package_key, shop_name, business, ip) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([mb_substr($name, 0, 120), $email, $phone ?: null, $package, mb_substr($shop, 0, 150) ?: null, mb_substr($business, 0, 500) ?: null, $ip]);

        $brand = Branding::platform();
        $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $pkg = $catalog[$package][0];
        $mail = new MailService();
        $mail->send($email, "We received your request — {$brand['name']}", self::layout($brand['name'],
            '<p>Hi ' . $e($name) . ',</p>'
            . '<p>Thank you for your interest in <strong>' . $e($pkg) . '</strong>. We have received your quotation request'
            . ($shop !== '' ? ' for <strong>' . $e($shop) . '</strong>' : '') . '.</p>'
            . '<p>One of our team will call you shortly' . ($phone !== '' ? ' on <strong>' . $e($phone) . '</strong>' : '') . ' to talk through your shop and send your quote.</p>'
            . ($brand['phone'] !== '' ? '<p>Need us sooner? Call <strong>' . $e($brand['phone']) . '</strong>.</p>' : '')
            . '<p>— The ' . $e($brand['name']) . ' team</p>'));
        $sales = $brand['email'] !== '' ? $brand['email'] : SupportAccount::email();
        if ($sales !== '') {
            $mail->send($sales, "New quote request: $pkg — $name", self::layout($brand['name'],
                '<p><strong>New quotation request</strong></p><table cellpadding="4">'
                . '<tr><td>Name</td><td>' . $e($name) . '</td></tr><tr><td>Email</td><td>' . $e($email) . '</td></tr>'
                . '<tr><td>Phone</td><td>' . $e($phone ?: '—') . '</td></tr><tr><td>Package</td><td>' . $e($pkg) . '</td></tr>'
                . '<tr><td>Shop</td><td>' . $e($shop ?: '—') . '</td></tr><tr><td>Business</td><td>' . $e($business ?: '—') . '</td></tr></table>'
                . '<p>See all requests in Support → Quotes.</p>'));
        }
        return null;
    }

    private static function layout(string $brand, string $body): string
    {
        return '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;color:#1f2421">'
            . '<div style="background:#1f9a5f;color:#fff;padding:16px 20px;border-radius:10px 10px 0 0;font-weight:bold;font-size:18px">'
            . htmlspecialchars($brand) . '</div><div style="border:1px solid #e5e7eb;border-top:0;padding:20px;border-radius:0 0 10px 10px;line-height:1.6">'
            . $body . '</div></div>';
    }

    public static function all(?string $status = null): array
    {
        $pdo = Database::pdo();
        self::ensureTable($pdo);
        if ($status !== null && isset(self::STATUSES[$status])) {
            $st = $pdo->prepare('SELECT * FROM quote_requests WHERE status = ? ORDER BY id DESC LIMIT 500');
            $st->execute([$status]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        }
        return $pdo->query('SELECT * FROM quote_requests ORDER BY id DESC LIMIT 500')->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function setStatus(int $id, string $status): void
    {
        if (!isset(self::STATUSES[$status])) { return; }
        $pdo = Database::pdo();
        self::ensureTable($pdo);
        $pdo->prepare('UPDATE quote_requests SET status = ? WHERE id = ?')->execute([$status, $id]);
    }

    public static function newCount(): int
    {
        try {
            $pdo = Database::pdo();
            self::ensureTable($pdo);
            return (int) $pdo->query("SELECT COUNT(*) FROM quote_requests WHERE status = 'new'")->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}
