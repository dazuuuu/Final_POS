<?php
// app/helpers/WhatsApp.php — receipts and credit overviews to a client's WhatsApp.
// The message opens, ready to send, in the WhatsApp of whoever is using the POS (their own number —
// owner or staff), addressed to the client's number. Each user can save their own WhatsApp number
// (shown on the message as the number to reply to). No third-party service is involved.

class WhatsApp
{
    private static function ensure(): void
    {
        static $done = false;
        if ($done) { return; }
        $flag = Database::marker('user_whatsapp_v1');
        if (!is_file($flag)) {
            Database::pdo()->exec('CREATE TABLE IF NOT EXISTS user_whatsapp (
                user_id INT NOT NULL PRIMARY KEY, number VARCHAR(20) NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            @file_put_contents($flag, date('c'));
        }
        $done = true;
    }

    /** Digits only, with the country code (Kenya 254 when a local 07… / 7… number is given). '' if it can't be a number. */
    public static function normalize(?string $n, string $cc = '254'): string
    {
        $d = preg_replace('/\D+/', '', (string) $n);
        if ($d === '') { return ''; }
        if (strpos($d, '00') === 0) { $d = substr($d, 2); }
        elseif (strpos($d, '0') === 0) { $d = $cc . substr($d, 1); }
        elseif (strlen($d) <= 9) { $d = $cc . $d; }
        return strlen($d) >= 10 && strlen($d) <= 15 ? $d : '';
    }

    /** The signed-in user's own WhatsApp number ('' if not set). */
    public static function mine(): string
    {
        try {
            self::ensure();
            $st = Database::pdo()->prepare('SELECT number FROM user_whatsapp WHERE user_id = ?');
            $st->execute([(int) TenantContext::userId()]);
            return (string) ($st->fetchColumn() ?: '');
        } catch (\Throwable $e) { return ''; }
    }

    public static function saveMine(string $number): bool
    {
        self::ensure();
        $n = self::normalize($number);
        $uid = (int) TenantContext::userId();
        if ($number === '') { Database::pdo()->prepare('DELETE FROM user_whatsapp WHERE user_id = ?')->execute([$uid]); return true; }
        if ($n === '') { return false; }
        Database::pdo()->prepare('INSERT INTO user_whatsapp (user_id, number) VALUES (?, ?) ON DUPLICATE KEY UPDATE number = VALUES(number)')->execute([$uid, $n]);
        return true;
    }

    private static function money(string $cur, $n): string { return $cur . ' ' . number_format((float) $n, 2); }

    private static function footer(string $shopPhone = ''): string
    {
        $me = self::mine();
        return $me !== '' ? "\nQuestions? WhatsApp +" . $me : ($shopPhone !== '' ? "\nQuestions? Call " . $shopPhone : '');
    }

    /** A paid sale. $items: product_name, quantity, line_total. */
    public static function saleText(array $sale, array $items, string $shop, string $cur, string $staff = '', string $shopPhone = ''): string
    {
        $l = ["*$shop*", 'Receipt ' . ($sale['receipt_number'] ?? '') . ' · ' . date('j M Y, g:i a', strtotime($sale['created_at'] ?? 'now')), ''];
        foreach ($items as $it) {
            $q = rtrim(rtrim(number_format((float) $it['quantity'], 2), '0'), '.');
            $l[] = "• $q × " . $it['product_name'] . ' — ' . number_format((float) $it['line_total'], 2);
        }
        if ((float) ($sale['discount_amount'] ?? 0) > 0) { $l[] = 'Discount: -' . number_format((float) $sale['discount_amount'], 2); }
        $l[] = '';
        $l[] = '*Total: ' . self::money($cur, $sale['total']) . '*';
        $pm = ['cash' => 'Cash', 'mpesa' => 'M-Pesa', 'split' => 'Cash + M-Pesa'][$sale['payment_method'] ?? 'cash'] ?? '';
        if ($pm !== '') { $l[] = 'Paid by: ' . $pm; }
        if ($staff !== '') { $l[] = 'Served by: ' . $staff; }
        $l[] = '';
        $l[] = 'Thank you for your business!' . self::footer($shopPhone);
        return implode("\n", $l);
    }

    /**
     * A credit sale: what was taken, what is paid, what is owed on it, and — when the customer is
     * on file — everything they owe in total with each open invoice.
     * $overview: rows from CustomerCreditModel::openInvoices().
     */
    public static function creditText(array $order, array $items, string $shop, string $cur, float $paid, float $balance, array $overview = [], string $shopPhone = ''): string
    {
        $l = ["*$shop*", '*CREDIT SALE* ' . ($order['receipt_number'] ?? '') . ' · ' . date('j M Y', strtotime($order['created_at'] ?? 'now')), ''];
        foreach ($items as $it) {
            $q = rtrim(rtrim(number_format((float) $it['quantity'], 2), '0'), '.');
            $l[] = "• $q × " . $it['product_name'] . ' — ' . number_format((float) $it['line_total'], 2);
        }
        $l[] = '';
        $l[] = 'Total: ' . self::money($cur, $order['total']);
        $l[] = 'Paid so far: ' . self::money($cur, $paid);
        $l[] = '*Balance on this sale: ' . self::money($cur, $balance) . '*';
        if (count($overview) > 0) {
            $tot = array_sum(array_column($overview, 'balance'));
            $l[] = '';
            $l[] = '*Your credit with us*';
            foreach (array_slice($overview, 0, 12) as $o) {
                $l[] = '• ' . $o['label'] . ' (' . date('j M', strtotime($o['date'])) . ') — ' . number_format((float) $o['balance'], 2);
            }
            if (count($overview) > 12) { $l[] = '… and ' . (count($overview) - 12) . ' more'; }
            $l[] = '*Total outstanding: ' . self::money($cur, $tot) . '*';
        }
        $l[] = '';
        $l[] = 'Please settle the balance at your earliest convenience. Thank you!' . self::footer($shopPhone);
        return implode("\n", $l);
    }
}
