<?php
// app/helpers/MpesaService.php
// M-Pesa STK push (Safaricom Daraja) for website orders, per account. The
// owner enters their own till/paybill credentials (Website API → Payments);
// a customer paying for a shop_web_orders row triggers a push to their
// phone, and Safaricom's callback (public/api/v1/mpesa-callback.php) marks
// the order paid and takes the stock off — the same "confirmed" effect the
// owner gets from changing an order's status by hand in super/api/.
//
// Tables:
//   mpesa_settings   per account: enabled, environment, shortcode, encrypted
//                     consumer key/secret/passkey, the account's own callback secret
// Columns added to shop_web_orders: payment_status, mpesa_checkout_id,
// mpesa_merchant_id, mpesa_receipt, paid_at.

class MpesaService
{
    // ------------------------------------------------------------- schema

    public static function ensure(): void
    {
        static $done = false;
        if ($done) return;
        $pdo = Database::pdo();
        $flag = Database::marker('mpesa_v1');
        if (!is_file($flag)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS mpesa_settings (
                account_id INT NOT NULL PRIMARY KEY,
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                environment ENUM('sandbox','production') NOT NULL DEFAULT 'sandbox',
                shortcode VARCHAR(20) NULL,
                consumer_key_enc VARCHAR(255) NULL,
                consumer_secret_enc VARCHAR(255) NULL,
                passkey_enc VARCHAR(255) NULL,
                callback_secret VARCHAR(64) NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            @file_put_contents($flag, date('c'));
        }
        $flag2 = Database::marker('mpesa_v2_order_cols');
        if (!is_file($flag2)) {
            $have = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'shop_web_orders'")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('payment_status', $have, true)) $pdo->exec("ALTER TABLE shop_web_orders ADD COLUMN payment_status ENUM('unpaid','pending','paid','failed') NOT NULL DEFAULT 'unpaid'");
            if (!in_array('mpesa_checkout_id', $have, true)) $pdo->exec("ALTER TABLE shop_web_orders ADD COLUMN mpesa_checkout_id VARCHAR(50) NULL");
            if (!in_array('mpesa_merchant_id', $have, true)) $pdo->exec("ALTER TABLE shop_web_orders ADD COLUMN mpesa_merchant_id VARCHAR(50) NULL");
            if (!in_array('mpesa_receipt', $have, true)) $pdo->exec("ALTER TABLE shop_web_orders ADD COLUMN mpesa_receipt VARCHAR(30) NULL");
            if (!in_array('paid_at', $have, true)) $pdo->exec("ALTER TABLE shop_web_orders ADD COLUMN paid_at DATETIME NULL");
            if (!in_array('mpesa_checkout_id', $have, true)) {
                // Stops Safaricom retrying the same callback from double-processing an order.
                try { $pdo->exec('ALTER TABLE shop_web_orders ADD UNIQUE KEY uq_web_orders_checkout (mpesa_checkout_id)'); } catch (Throwable $e) {}
            }
            @file_put_contents($flag2, date('c'));
        }
        $done = true;
    }

    // -------------------------------------------------------------- crypto

    private static function secret(): string
    {
        static $k = null;
        if ($k !== null) return $k;
        $file = ROOT_PATH . '/app/config/secret.php';
        $cfg = is_file($file) ? (include $file) : null;
        if (!is_array($cfg) || empty($cfg['key'])) {
            $cfg = ['key' => base64_encode(random_bytes(32))];
            @file_put_contents($file, "<?php\n// app/config/secret.php — this install's encryption secret. Created automatically; never share or reuse it.\nreturn " . var_export($cfg, true) . ";\n", LOCK_EX);
        }
        return $k = hash('sha256', 'dz-mpesa|' . $cfg['key'], true);
    }

    private static function encrypt(string $plain): ?string
    {
        if ($plain === '' || !function_exists('openssl_encrypt')) return null;
        $iv = random_bytes(12);
        $ct = openssl_encrypt($plain, 'aes-256-gcm', self::secret(), OPENSSL_RAW_DATA, $iv, $tag);
        return $ct === false ? null : base64_encode($iv . $tag . $ct);
    }

    private static function decrypt(?string $sealed): ?string
    {
        if (!$sealed || !function_exists('openssl_decrypt')) return null;
        $raw = base64_decode($sealed, true);
        if ($raw === false || strlen($raw) < 29) return null;
        $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::secret(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return is_string($p) ? $p : null;
    }

    // ------------------------------------------------------------ settings

    public static function settings(int $account): array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM mpesa_settings WHERE account_id = ?');
        $st->execute([$account]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['account_id' => $account, 'enabled' => 0, 'environment' => 'sandbox',
            'shortcode' => null, 'consumer_key_enc' => null, 'consumer_secret_enc' => null, 'passkey_enc' => null, 'callback_secret' => null];
        $row['has_credentials'] = !empty($row['consumer_key_enc']) && !empty($row['consumer_secret_enc']) && !empty($row['passkey_enc']) && !empty($row['shortcode']);
        return $row;
    }

    public static function enabled(int $account): bool
    {
        $s = self::settings($account);
        return (int) $s['enabled'] === 1 && $s['has_credentials'];
    }

    /** Save credentials from the Payments tab. Blank secret/passkey fields keep the stored value (never shown again). */
    public static function save(int $account, array $in): void
    {
        self::ensure();
        $pdo = Database::pdo();
        $current = self::settings($account);
        $secret = $pdo->prepare('SELECT callback_secret FROM mpesa_settings WHERE account_id = ?');
        $secret->execute([$account]);
        $callbackSecret = $secret->fetchColumn() ?: bin2hex(random_bytes(20));
        $fields = [
            'enabled'     => !empty($in['enabled']) ? 1 : 0,
            'environment' => ($in['environment'] ?? 'sandbox') === 'production' ? 'production' : 'sandbox',
            'shortcode'   => trim((string) ($in['shortcode'] ?? '')) ?: null,
            'consumer_key_enc'    => isset($in['consumer_key']) && $in['consumer_key'] !== '' ? self::encrypt(trim($in['consumer_key'])) : $current['consumer_key_enc'],
            'consumer_secret_enc' => isset($in['consumer_secret']) && $in['consumer_secret'] !== '' ? self::encrypt(trim($in['consumer_secret'])) : $current['consumer_secret_enc'],
            'passkey_enc' => isset($in['passkey']) && $in['passkey'] !== '' ? self::encrypt(trim($in['passkey'])) : $current['passkey_enc'],
            'callback_secret' => $callbackSecret,
        ];
        $cols = array_keys($fields);
        $pdo->prepare('INSERT INTO mpesa_settings (account_id, `' . implode('`,`', $cols) . '`) VALUES (?' . str_repeat(',?', count($cols)) . ')
                        ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn($c) => "`$c` = VALUES(`$c`)", $cols)))
            ->execute(array_merge([$account], array_values($fields)));
    }

    // ------------------------------------------------------------- daraja

    private static function baseUrl(array $s): string
    {
        return $s['environment'] === 'production' ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke';
    }

    private static function http(string $method, string $url, array $headers, ?array $body = null): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_POSTFIELDS => $body !== null ? json_encode($body) : null,
        ]);
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $json = $res !== false ? json_decode((string) $res, true) : null;
        return [$code, is_array($json) ? $json : ['raw' => $res], $err];
    }

    /** OAuth access token for the account's credentials, cached ~55 min in a marker file. */
    private static function token(int $account, array $s): ?string
    {
        $cacheFile = ROOT_PATH . '/app/storage/mpesa_token_' . $account . '.json';
        if (is_file($cacheFile)) {
            $c = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($c) && !empty($c['expires']) && $c['expires'] > time()) return $c['token'];
        }
        $key = self::decrypt($s['consumer_key_enc']);
        $sec = self::decrypt($s['consumer_secret_enc']);
        if (!$key || !$sec) return null;
        [$code, $json] = self::http('GET', self::baseUrl($s) . '/oauth/v1/generate?grant_type=client_credentials',
            ['Authorization: Basic ' . base64_encode($key . ':' . $sec)]);
        if ($code !== 200 || empty($json['access_token'])) {
            error_log('MpesaService::token failed for account ' . $account . ': ' . json_encode($json));
            return null;
        }
        @mkdir(dirname($cacheFile), 0755, true);
        @file_put_contents($cacheFile, json_encode(['token' => $json['access_token'], 'expires' => time() + 3000]), LOCK_EX);
        return $json['access_token'];
    }

    /**
     * Push an STK prompt to the customer's phone for one shop_web_orders row.
     * Returns ['ok' => bool, 'checkout_id', 'merchant_id', 'error'].
     */
    public static function stkPush(int $account, array $order, string $phone, string $callbackUrl): array
    {
        self::ensure();
        $s = self::settings($account);
        if (!self::enabled($account)) return ['ok' => false, 'error' => 'M-Pesa is not set up for this shop yet.'];
        $tok = self::token($account, $s);
        if (!$tok) return ['ok' => false, 'error' => 'Could not reach M-Pesa — check the shop\'s credentials.'];
        $phone = preg_replace('/\D/', '', $phone);
        if (substr($phone, 0, 1) === '0') $phone = '254' . substr($phone, 1);
        if (substr($phone, 0, 3) !== '254') $phone = '254' . $phone;
        $shortcode = (string) $s['shortcode'];
        $passkey = self::decrypt($s['passkey_enc']);
        $timestamp = date('YmdHis');
        $password = base64_encode($shortcode . $passkey . $timestamp);
        $amount = max(1, (int) round((float) $order['total']));
        [$code, $json] = self::http('POST', self::baseUrl($s) . '/mpesa/stkpush/v1/processrequest', [
            'Authorization: Bearer ' . $tok, 'Content-Type: application/json',
        ], [
            'BusinessShortCode' => $shortcode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => $amount,
            'PartyA' => $phone,
            'PartyB' => $shortcode,
            'PhoneNumber' => $phone,
            'CallBackURL' => $callbackUrl,
            'AccountReference' => $order['number'],
            'TransactionDesc' => 'Order ' . $order['number'],
        ]);
        if ($code !== 200 || empty($json['CheckoutRequestID'])) {
            return ['ok' => false, 'error' => $json['errorMessage'] ?? ('M-Pesa did not accept the request (HTTP ' . $code . ').')];
        }
        return ['ok' => true, 'checkout_id' => $json['CheckoutRequestID'], 'merchant_id' => $json['MerchantRequestID'] ?? null];
    }

    /**
     * Mark a shop_web_orders row paid and take its stock off, in one
     * transaction — idempotent, so a duplicate Safaricom callback for the
     * same CheckoutRequestID is a no-op the second time.
     */
    public static function markPaid(int $orderId, string $receipt): bool
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT * FROM shop_web_orders WHERE id = ? FOR UPDATE');
            $st->execute([$orderId]);
            $o = $st->fetch(PDO::FETCH_ASSOC);
            if (!$o || $o['payment_status'] === 'paid') { // already processed — duplicate callback
                $pdo->commit();
                return true;
            }
            $items = $pdo->prepare('SELECT product_id, quantity FROM shop_web_order_items WHERE order_id = ?');
            $items->execute([$orderId]);
            foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $it) {
                if (!$it['product_id']) continue;
                $dec = $pdo->prepare('UPDATE products SET quantity = quantity - ? WHERE id = ? AND tenant_id = ? AND quantity >= ?');
                $dec->execute([(float) $it['quantity'], (int) $it['product_id'], (int) $o['tenant_id'], (float) $it['quantity']]);
                // Insufficient stock at payment time: let it go negative rather than
                // block a payment that already happened — the owner reconciles stock.
                if ($dec->rowCount() !== 1) {
                    $pdo->prepare('UPDATE products SET quantity = quantity - ? WHERE id = ? AND tenant_id = ?')
                        ->execute([(float) $it['quantity'], (int) $it['product_id'], (int) $o['tenant_id']]);
                }
            }
            $pdo->prepare("UPDATE shop_web_orders SET payment_status = 'paid', status = IF(status = 'new', 'confirmed', status), mpesa_receipt = ?, paid_at = NOW() WHERE id = ?")
                ->execute([$receipt, $orderId]);
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('MpesaService::markPaid failed for order ' . $orderId . ': ' . $e->getMessage());
            return false;
        }
    }

    public static function markFailed(int $orderId): void
    {
        $pdo = Database::pdo();
        $pdo->prepare("UPDATE shop_web_orders SET payment_status = 'failed' WHERE id = ? AND payment_status = 'pending'")->execute([$orderId]);
    }
}
