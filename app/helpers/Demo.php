<?php
// app/helpers/Demo.php
// Live demo from the landing page. A visitor gives their email and picks a
// package; we create a private throwaway shop for them (its own account, the
// chosen package switched on, sample stock / services), sign them in as its
// owner and let them use the real POS. Signing out — or the time running out —
// deletes that demo shop completely. Each email gets MAX_PER_EMAIL demos.
//
// While in a demo:
//   • outgoing email is not sent (MailService skips it) — no spam to real
//     addresses typed into staff or customer forms
//   • a few pages that make no sense for a throwaway shop are closed (data
//     import, backups, licence / upgrade)
//   • a bar at the top shows the time left and "End demo"
//
//   demo_sessions        one row per demo started: email, package, demo shop, ip, times
//   tenants.demo_expires_at  set on demo shops (they are hidden from Support)

class Demo
{
    const MAX_PER_EMAIL = 3;
    const MAX_PER_IP_DAY = 10;
    const MINUTES = 45;

    /** Pages closed in a demo (path fragments). */
    const CLOSED = ['/super/import', '/super/backup', '/super/upgrade', '/auth/activate-account', '/super/billing'];

    public static function ensureSchema(?PDO $pdo = null): void
    {
        static $done = false;
        if ($done) { return; }
        $pdo ??= Database::pdo();
        $flag = Database::marker('demo_v2');
        if (!is_file($flag)) {
            $has = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'tenants' AND column_name = 'demo_expires_at'")->fetchColumn();
            if (!$has) {
                $pdo->exec('ALTER TABLE tenants ADD COLUMN demo_expires_at DATETIME NULL');
            }
            $pdo->exec("CREATE TABLE IF NOT EXISTS demo_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(150) NOT NULL,
                package_key VARCHAR(40) NOT NULL,
                demo_shop_id INT NULL,
                ip VARCHAR(45) NULL,
                started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ended_at DATETIME NULL,
                KEY idx_demo_email (email), KEY idx_demo_ip (ip, started_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            // Not "account_id": AccountDeletion clears every row with that column when the
            // demo shop is deleted, which would reset the visitor's demo count.
            $old = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'demo_sessions' AND column_name = 'account_id'")->fetchColumn();
            if ($old) {
                $pdo->exec('ALTER TABLE demo_sessions CHANGE account_id demo_shop_id INT NULL');
            }
            @file_put_contents($flag, date('c'));
        }
        $done = true;
    }

    /** Packages that can be tried: all of them. */
    public static function packages(): array
    {
        return Features::catalog();
    }

    private static function isRestaurant(string $package): bool
    {
        return in_array($package, [Features::RESTAURANT_POS, Features::RESTAURANT_ROOMS], true);
    }

    public static function used(string $email): int
    {
        self::ensureSchema();
        $st = Database::pdo()->prepare('SELECT COUNT(*) FROM demo_sessions WHERE email = ?');
        $st->execute([strtolower(trim($email))]);
        return (int) $st->fetchColumn();
    }

    public static function left(string $email): int
    {
        return max(0, self::MAX_PER_EMAIL - self::used($email));
    }

    public static function active(): bool
    {
        return !empty($_SESSION['demo']['account']);
    }

    /** Start a demo and sign the visitor in. Returns an error message, or null when signed in. */
    public static function start(string $email, string $package): ?string
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { return 'Enter a valid email address.'; }
        $packages = self::packages();
        if (!isset($packages[$package])) { return 'Choose a package to try.'; }
        $pdo = Database::pdo();
        self::ensureSchema($pdo);
        self::cleanup();
        if (self::used($email) >= self::MAX_PER_EMAIL) {
            return 'This email has used all ' . self::MAX_PER_EMAIL . ' demos. Request a quote and we will set you up properly.';
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $st = $pdo->prepare('SELECT COUNT(*) FROM demo_sessions WHERE ip = ? AND started_at >= NOW() - INTERVAL 1 DAY');
        $st->execute([$ip]);
        if ((int) $st->fetchColumn() >= self::MAX_PER_IP_DAY) { return 'Too many demos from this network today. Please try again tomorrow.'; }

        $label = $packages[$package][0];
        $tag = bin2hex(random_bytes(3));
        // Basic packages come with the matching shop base, Pro ones with the full shop POS (as when support sets
        // them up); restaurants run on their own. Restaurant + Rooms includes the restaurant POS.
        $keys = in_array($package, [Features::SHOP_BASIC, Features::SERVICES_BASIC], true)
            ? array_unique([Features::SHOP_BASIC, $package])
            : (self::isRestaurant($package) ? array_unique([Features::RESTAURANT_POS, $package]) : array_unique([Features::SHOP_POS, $package]));
        try {
            $account = Signup::invite('Demo ' . $label . ' ' . $tag, $keys);
            $pdo->prepare('UPDATE tenants SET name = ?, demo_expires_at = NOW() + INTERVAL ' . (int) self::MINUTES . ' MINUTE WHERE id = ?')
                ->execute([self::shopName($package), $account]);
            $roleId = (int) $pdo->query("SELECT id FROM roles WHERE role_name = 'tenant_owner'")->fetchColumn();
            $pdo->prepare(
                'INSERT INTO users (tenant_id, username, email, password_hash, role_id, is_active, email_verified, activated_at)
                 VALUES (?, ?, ?, ?, ?, 1, 1, NOW())'
            )->execute([$account, 'demo_' . $tag, 'demo.' . $tag . '@demo.local', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $roleId]);
            $userId = (int) $pdo->lastInsertId();
            Signup::ensureColumns();
            $pdo->prepare('UPDATE tenants SET owner_user_id = ?, signup_completed_at = NOW(), license_activated_at = NOW() WHERE id = ?')->execute([$userId, $account]);
            $pdo->prepare('INSERT INTO demo_sessions (email, package_key, demo_shop_id, ip) VALUES (?, ?, ?, ?)')->execute([$email, $package, $account, $ip]);
            $demoId = (int) $pdo->lastInsertId();

            $st = $pdo->prepare('SELECT u.*, r.role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?');
            $st->execute([$userId]);
            $user = $st->fetch(PDO::FETCH_ASSOC);
            session_regenerate_id(true);
            TenantContext::establish($pdo, $user);
            $_SESSION['username']     = 'Demo';
            $_SESSION['logged_in']    = true;
            $_SESSION['otp_verified'] = true;
            $_SESSION['must_reset']   = false;
            $_SESSION['shop_slug']    = (string) $pdo->query('SELECT slug FROM tenants WHERE id = ' . $account)->fetchColumn();
            $_SESSION['demo'] = ['account' => $account, 'id' => $demoId, 'email' => $email, 'package' => $package,
                                 'expires' => time() + self::MINUTES * 60];
            SingleSession::claim($pdo, $user);
            // Seeded after sign-in: the restaurant's own setup code runs as this demo owner.
            self::isRestaurant($package) ? self::seedRestaurant($pdo, $account, $userId, $package) : self::seed($pdo, $account, $package);
            return null;
        } catch (Throwable $e) {
            error_log('Demo::start: ' . $e->getMessage());
            if (!empty($account)) { try { AccountDeletion::delete($account); } catch (Throwable $e2) {} }
            if (!empty($_SESSION['demo'])) { $_SESSION = []; TenantContext::reset(); }
            return 'The demo could not be started right now. Please try again in a minute.';
        }
    }

    /** End this visitor's demo: delete the demo shop and sign out. Returns demos left for their email. */
    public static function end(): int
    {
        $d = $_SESSION['demo'] ?? null;
        if (!$d) { return 0; }
        try {
            $pdo = Database::pdo();
            self::ensureSchema($pdo);
            $pdo->prepare('UPDATE demo_sessions SET ended_at = NOW() WHERE id = ?')->execute([(int) $d['id']]);
            self::destroy((int) $d['account']);
        } catch (Throwable $e) {
            error_log('Demo::end: ' . $e->getMessage());
        }
        $_SESSION = [];
        session_regenerate_id(true);
        TenantContext::reset();
        $_SESSION['demo_done'] = ['email' => $d['email'], 'left' => self::left($d['email'])];
        return $_SESSION['demo_done']['left'];
    }

    /** Delete a demo shop (only ever a demo shop). */
    private static function destroy(int $account): void
    {
        $st = Database::pdo()->prepare('SELECT demo_expires_at FROM tenants WHERE id = ?');
        $st->execute([$account]);
        if ($st->fetchColumn()) { AccountDeletion::delete($account); }
    }

    /** Remove demo shops whose time ran out and nobody ended (closed tab, lost phone). */
    public static function cleanup(): void
    {
        try {
            $pdo = Database::pdo();
            self::ensureSchema($pdo);
            $old = $pdo->query('SELECT id FROM tenants WHERE demo_expires_at IS NOT NULL AND demo_expires_at < NOW() - INTERVAL 10 MINUTE LIMIT 5')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($old as $id) { AccountDeletion::delete((int) $id); }
        } catch (Throwable $e) {
            error_log('Demo::cleanup: ' . $e->getMessage());
        }
    }

    /** Every request (app.php): end an expired demo and keep demo users off closed pages. */
    public static function enforce(): void
    {
        if (!self::active()) { return; }
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        if (time() > (int) $_SESSION['demo']['expires']) {
            self::end();
            header('Location: ' . public_url('?demo=ended#demo'));
            exit;
        }
        foreach (self::CLOSED as $frag) {
            if (strpos($script, $frag) !== false) {
                $_SESSION['flash']['error'] = 'That part is switched off in the demo.';
                header('Location: ' . public_url('super/dashboard/'));
                exit;
            }
        }
    }

    public static function minutesLeft(): int
    {
        return self::active() ? max(0, (int) ceil(((int) $_SESSION['demo']['expires'] - time()) / 60)) : 0;
    }

    private static function shopName(string $package): string
    {
        return [
            Features::SERVICES => 'Demo Salon & Spa', Features::SERVICES_BASIC => 'Demo Barber Studio',
            Features::ELECTRONICS => 'Demo Phones & Electronics',
            Features::RESTAURANT_POS => 'Demo Restaurant & Bar', Features::RESTAURANT_ROOMS => 'Demo Hotel & Restaurant',
        ][$package] ?? 'Demo Mini Mart';
    }

    // ------------------------------------------------------------------ seed

    /** Sample stock, customers and (for services) a price list, so the demo isn't empty. */
    private static function seed(PDO $pdo, int $account, string $package): void
    {
        $cols = function (string $t) use ($pdo): array {
            $st = $pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?');
            $st->execute([$t]);
            return array_flip($st->fetchAll(PDO::FETCH_COLUMN));
        };
        $insert = function (string $t, array $row) use ($pdo, $cols) {
            static $have = [];
            $have[$t] ??= $cols($t);
            $row = array_intersect_key($row, $have[$t]);
            if (!$row) return 0;
            $pdo->prepare("INSERT INTO `$t` (`" . implode('`,`', array_keys($row)) . '`) VALUES (' . rtrim(str_repeat('?,', count($row)), ',') . ')')
                ->execute(array_values($row));
            return (int) $pdo->lastInsertId();
        };

        $electronics = $package === Features::ELECTRONICS;
        $services = in_array($package, [Features::SERVICES, Features::SERVICES_BASIC], true);
        $stock = $electronics ? [
            'Phones'      => [['Samsung Galaxy A15 128GB', 15500, 18999, 8], ['Tecno Spark 20', 11800, 14500, 12], ['iPhone 13 128GB (refurb)', 52000, 61000, 3]],
            'Accessories' => [['USB-C fast charger 25W', 650, 1200, 40], ['Wireless earbuds', 1300, 2500, 25], ['Tempered glass protector', 80, 300, 120]],
            'Computing'   => [['HP 250 G9 Laptop i5', 48000, 56500, 4], ['32GB flash drive', 380, 800, 60], ['Wireless mouse', 450, 950, 30]],
        ] : ($services ? [
            'Hair care'   => [['Leave-in conditioner 250ml', 420, 750, 18], ['Edge control gel', 180, 350, 30], ['Argan oil serum', 600, 1100, 12]],
            'Nails'       => [['Gel polish (assorted)', 350, 700, 24], ['Nail glue', 60, 150, 40]],
        ] : [
            'Groceries'   => [['Maize flour 2kg', 165, 199, 60], ['Sugar 1kg', 150, 185, 45], ['Cooking oil 1L', 290, 345, 30], ['Rice 1kg', 160, 210, 40]],
            'Drinks'      => [['Soda 500ml', 45, 70, 96], ['Mineral water 1L', 40, 80, 72], ['Fresh milk 500ml', 55, 65, 30]],
            'Household'   => [['Bar soap 800g', 140, 180, 36], ['Tissue 10-pack', 340, 420, 20], ['Detergent 1kg', 230, 290, 25]],
        ]);
        foreach ($stock as $cat => $items) {
            $cid = $insert('categories', ['tenant_id' => $account, 'name' => $cat, 'status' => 'active']);
            foreach ($items as [$name, $buy, $sell, $qty]) {
                $insert('products', ['tenant_id' => $account, 'category_id' => $cid, 'name' => $name, 'quantity' => $qty, 'unit' => 'piece',
                    'buying_price' => $buy, 'selling_price' => $sell, 'retail_price' => $sell, 'wholesale_price' => round($sell * .93),
                    'low_stock_threshold' => 5, 'status' => 'active', 'is_serialized' => $electronics && $cat === 'Phones' ? 1 : 0,
                    'warranty_months' => $electronics && $cat !== 'Accessories' ? 12 : 0]);
            }
        }
        foreach ([['Mary Wanjiku', '0712 345 678'], ['John Otieno', '0722 111 222'], ['Amina Hassan', '0733 444 555']] as [$n, $p]) {
            $insert('customers', ['tenant_id' => $account, 'name' => $n, 'phone' => $p]);
        }
        if ($services) {
            new Models\ServiceDeskModel($pdo); // creates the svc_* tables when missing
            $list = $package === Features::SERVICES_BASIC
                ? [['Haircut', 300, 30, 40], ['Beard trim', 150, 15, 40], ['Haircut + shave', 450, 45, 40], ['Kids cut', 200, 20, 40]]
                : [['Box braids', 3500, 240, null, 1200], ['Wash & blow-dry', 800, 45, 30], ['Manicure', 700, 40, 40], ['Pedicure', 900, 50, 40], ['Wig installation', 3000, 120, null, 1200], ['Facial', 1500, 60, 35]];
            foreach ($list as $s) {
                $sid = $insert('svc_services', ['account_id' => $account, 'name' => $s[0], 'price' => $s[1], 'duration_min' => $s[2],
                    'commission_pct' => $s[3], 'commission_fixed' => $s[4] ?? null, 'active' => 1]);
                $pdo->prepare('INSERT IGNORE INTO svc_service_shops (service_id, tenant_id) VALUES (?, ?)')->execute([$sid, $account]);
            }
        }
    }

    /** Insert a row with only the columns the live table has. */
    private static function put(PDO $pdo, string $t, array $row): int
    {
        static $have = [];
        if (!isset($have[$t])) {
            $st = $pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?');
            $st->execute([$t]);
            $have[$t] = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
        }
        $row = array_intersect_key($row, $have[$t]);
        $pdo->prepare("INSERT INTO `$t` (`" . implode('`,`', array_keys($row)) . '`) VALUES (' . rtrim(str_repeat('?,', count($row)), ',') . ')')
            ->execute(array_values($row));
        return (int) $pdo->lastInsertId();
    }

    /**
     * Restaurant demo: the main restaurant and a second outlet (a rooftop bar)
     * with its own stock and its own prices. Each shop gets:
     *   store      goods received (crates, cartons, sacks…) with the delivery logged
     *   kitchen    some store stock moved to the kitchen as recipe ingredients
     *   counter    drinks moved to the shelf / bar to sell, per type and price
     *   menu       categories and dishes with recipes
     *   orders     a table bill waiting for payment and a takeaway on hold
     * Restaurant + Rooms adds rooms and a family platter at the main restaurant.
     */
    private static function seedRestaurant(PDO $pdo, int $account, int $userId, string $package): void
    {
        // The restaurant pages create their tables on first use; load the same code.
        require_once PUBLIC_ROOT . '/super/restaurant/_common.php';
        if ($package === Features::RESTAURANT_ROOMS) {
            require_once PUBLIC_ROOT . '/super/restaurant/_hospitality.php';
        }

        Features::setMultiShop($account, true);
        ShopContext::ensureSchema($pdo);
        $T = new Models\TenantModel($pdo);
        $pdo->prepare("INSERT INTO tenants (name, slug, owner_user_id, parent_tenant_id, status) VALUES (?, ?, ?, ?, 'active')")
            ->execute(['Rooftop Bar', $T->uniqueSlug('rooftop-bar-' . bin2hex(random_bytes(2))), $userId, $account]);
        $bar = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE tenants SET address = ? WHERE id = ?')->execute(['Ground floor, main road', $account]);
        $pdo->prepare('UPDATE tenants SET address = ? WHERE id = ?')->execute(['4th floor terrace', $bar]);

        // [name, unit, pieces per unit, cost per unit, received, [to kitchen qty] | [to counter: [type, pieces, price]...]]
        $main = [
            'store' => [
                ['Soda crate 500ml', 'crates', 24, 1150, 6, 'counter', [['Coca-Cola 500ml', 24, 80], ['Fanta Orange 500ml', 24, 80]]],
                ['Beer carton (Tusker)', 'cartons', 24, 3900, 5, 'counter', [['Tusker Lager 500ml', 24, 250]]],
                ['Water carton 500ml', 'cartons', 24, 600, 4, 'counter', [['Mineral water 500ml', 24, 50]]],
                ['Rice (pishori)', 'kg', null, 160, 50, 'kitchen', 12],
                ['Beef', 'kg', null, 620, 20, 'kitchen', 6],
                ['Whole chicken', 'pcs', null, 580, 15, 'kitchen', 6],
                ['Potatoes', 'kg', null, 75, 60, 'kitchen', 20],
                ['Cooking oil', 'l', null, 310, 20, 'kitchen', 5],
                ['Onions', 'kg', null, 90, 15, 'kitchen', 5],
                ['Tomatoes', 'kg', null, 110, 12, 'kitchen', 4],
            ],
            'menu' => [
                'Mains'     => [['Beef stew & rice', 480, ['Rice (pishori)' => .25, 'Beef' => .2, 'Onions' => .05, 'Tomatoes' => .05]],
                                ['Pilau beef', 520, ['Rice (pishori)' => .3, 'Beef' => .15, 'Onions' => .05, 'Cooking oil' => .03]]],
                'Grill'     => [['Quarter chicken & chips', 650, ['Whole chicken' => .25, 'Potatoes' => .3, 'Cooking oil' => .05]],
                                ['Half chicken', 1100, ['Whole chicken' => .5]]],
                'Fast food' => [['Chips (fries)', 220, ['Potatoes' => .35, 'Cooking oil' => .05]],
                                ['Masala chips', 280, ['Potatoes' => .35, 'Cooking oil' => .05, 'Tomatoes' => .05]]],
            ],
        ];
        // The bar: its own store and its own (higher) prices; a small grill menu.
        $rooftop = [
            'store' => [
                ['Beer carton (Tusker)', 'cartons', 24, 3900, 6, 'counter', [['Tusker Lager 500ml', 24, 320]]],
                ['Beer carton (Guinness)', 'cartons', 24, 5200, 3, 'counter', [['Guinness 500ml', 24, 380]]],
                ['Soda crate 500ml', 'crates', 24, 1150, 3, 'counter', [['Coca-Cola 500ml', 24, 120]]],
                ['Spirits (Gilbey\'s 750ml)', 'pcs', null, 1450, 12, 'counter', [['Gilbey\'s Gin 750ml', 6, 2200]]],
                ['Chicken wings', 'kg', null, 540, 10, 'kitchen', 4],
                ['Potatoes', 'kg', null, 75, 25, 'kitchen', 10],
                ['Cooking oil', 'l', null, 310, 10, 'kitchen', 3],
            ],
            'menu' => [
                'Bites' => [['Chicken wings (6 pcs)', 650, ['Chicken wings' => .3, 'Cooking oil' => .03]],
                            ['Chips (fries)', 300, ['Potatoes' => .35, 'Cooking oil' => .05]]],
            ],
        ];

        foreach ([$account => $main, $bar => $rooftop] as $tid => $plan) {
            $ing = [];
            $counter = [];
            foreach ($plan['store'] as [$name, $unit, $ppu, $cost, $received, $outlet, $move]) {
                $sid = self::put($pdo, 'rest_stock', ['tenant_id' => $tid, 'name' => $name, 'unit' => $unit, 'pieces_per_unit' => $ppu,
                    'quantity' => $received, 'cost_price' => $cost]);
                self::put($pdo, 'rest_stock_receipts', ['tenant_id' => $tid, 'stock_id' => $sid, 'quantity' => $received, 'cost_price' => $cost,
                    'user_id' => $userId, 'created_at' => date('Y-m-d H:i:s', strtotime('-2 days'))]);
                if ($outlet === 'kitchen') { // store → kitchen: becomes a recipe ingredient
                    $ing[$name] = self::put($pdo, 'rest_ingredients', ['tenant_id' => $tid, 'stock_id' => $sid, 'name' => $name, 'quantity' => $move,
                        'unit' => $unit, 'cost_price' => $cost, 'unit_cost' => $cost]);
                    $storeQty = $move;
                    $breakdown = null;
                } else { // store → counter / bar shelf, one line per type with its price
                    $storeQty = 0;
                    $breakdown = [];
                    foreach ($move as [$type, $pieces, $price]) {
                        $counter[] = [self::put($pdo, 'rest_outlet_items', ['tenant_id' => $tid, 'outlet' => 'counter', 'stock_id' => $sid,
                            'type_name' => $type, 'quantity' => $pieces, 'selling_price' => $price]), $type, $price];
                        $storeQty += $ppu ? $pieces / $ppu : $pieces;
                        $breakdown[] = ['type' => $type, 'quantity' => $pieces];
                    }
                }
                $pdo->prepare('UPDATE rest_stock SET quantity = quantity - ? WHERE id = ?')->execute([$storeQty, $sid]);
                self::put($pdo, 'rest_transfers', ['tenant_id' => $tid, 'stock_id' => $sid, 'outlet' => $outlet, 'store_quantity' => $storeQty,
                    'breakdown' => $breakdown ? json_encode($breakdown) : null, 'user_id' => $userId, 'created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))]);
            }
            $dishes = [];
            $sort = 0;
            foreach ($plan['menu'] as $cat => $list) {
                $cid = self::put($pdo, 'rest_categories', ['tenant_id' => $tid, 'name' => $cat, 'sort_order' => $sort++]);
                foreach ($list as [$dish, $price, $recipe]) {
                    $did = self::put($pdo, 'rest_dishes', ['tenant_id' => $tid, 'name' => $dish, 'category_id' => $cid, 'category' => $cat, 'price' => $price, 'is_active' => 1]);
                    $dishes[] = [$did, $dish, $price];
                    foreach ($recipe as $iname => $qty) {
                        if (!isset($ing[$iname])) continue;
                        $u = $pdo->query('SELECT unit FROM rest_ingredients WHERE id = ' . (int) $ing[$iname])->fetchColumn();
                        self::put($pdo, 'rest_dish_ingredients', ['dish_id' => $did, 'ingredient_id' => $ing[$iname], 'quantity' => $qty,
                            'measure' => $u, 'base_quantity' => $qty]);
                    }
                }
            }
            // Orders in progress: a table bill waiting at the Payments desk, and a takeaway on hold.
            $n = 0;
            foreach ([['open', 'dine_in', 'Table 4', 'Walk-in', [[$dishes[0], 2], [$counter[0], 2]]],
                      ['held', 'takeaway', null, 'Kevin', [[$dishes[count($dishes) > 2 ? 2 : 1], 1], [$counter[count($counter) - 1], 1]]]] as [$status, $type, $table, $cust, $lines]) {
                $total = 0;
                foreach ($lines as [$it, $q]) { $total += $it[2] * $q; }
                $oid = self::put($pdo, 'rest_orders', ['tenant_id' => $tid, 'order_no' => sprintf('%04d', ++$n), 'order_type' => $type, 'table_label' => $table,
                    'customer_name' => $cust, 'status' => $status, 'subtotal' => $total, 'total' => $total, 'user_id' => $userId, 'source' => 'pos']);
                foreach ($lines as $li => [$it, $q]) {
                    self::put($pdo, 'rest_order_items', ['order_id' => $oid, 'item_type' => $li === 0 ? 'dish' : 'counter', 'ref_id' => $it[0],
                        'name' => $it[1], 'unit_price' => $it[2], 'quantity' => $q, 'line_total' => $it[2] * $q]);
                }
            }
        }

        if ($package === Features::RESTAURANT_ROOMS) {
            foreach ([['Room 101 · Standard', '1st floor', '1 double bed', 3500, 9500, 21000, 'none', null],
                      ['Room 102 · Deluxe', '1st floor', '1 king bed, sofa', 5500, 15000, 34000, 'free', null],
                      ['Room 201 · Family suite', '2nd floor', '1 king + 2 single beds', 8000, 22500, 50000, 'paid', 500]] as [$name, $floor, $beds, $night, $d3, $week, $svc, $svcPrice]) {
                self::put($pdo, 'room_rooms', ['tenant_id' => $account, 'name' => $name, 'floor' => $floor, 'beds' => $beds, 'bathroom' => 'En-suite, hot shower',
                    'amenities' => 'Wi-Fi, TV, towels', 'food_included' => 'Breakfast', 'room_service' => $svc, 'room_service_price' => $svcPrice,
                    'price_night' => $night, 'price_3days' => $d3, 'price_week' => $week, 'status' => 'available', 'is_active' => 1]);
            }
            $pid = self::put($pdo, 'rest_platters', ['tenant_id' => $account, 'name' => 'Family platter', 'description' => 'For the family on a weekend',
                'serves' => '4 people', 'price' => 3200, 'is_active' => 1]);
            foreach ([['Half chicken', 2, 0, 0], ['Chips (fries)', 2, 0, 0], ['Pilau beef', 1, 0, 0], ['Coca-Cola 500ml', 4, 1, 1]] as [$n2, $q, $free, $drink]) {
                self::put($pdo, 'rest_platter_items', ['platter_id' => $pid, 'name' => $n2, 'quantity' => $q, 'is_free' => $free, 'is_drink' => $drink]);
            }
        }
    }
}
