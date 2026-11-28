<?php
// app/models/RecordMgmtModel.php
// Record Management System package (standalone, no shop POS). An account
// records its employees (most of them never log in — only admin, supervisor
// and cashier accounts do), the services it offers, products it has bought
// (used against a service and so deducted from what's owed), and then logs
// each service an employee performs on the Operations sheet: who, what
// service, how much was paid (cash or M-Pesa/Paybill), and any product used.
// Each operation immediately credits half of (amount − product cost) to the
// employee's running "owed" balance — their commission. Paying them debits
// that balance (a partial payment is fine; the rest stays owed).
//
//   rm_roles               role names offered when adding an employee (seeded)
//   rm_employees            the people being tracked; owed = commission balance
//   rm_services             price list of services offered
//   rm_products             products bought by the company; low_stock_level is
//                           per product and owner-adjustable
//   rm_operations           one service performed for a client, by one employee
//   rm_operation_products   products used on an operation (deducted from stock)
//   rm_payments             money paid out to an employee against what's owed
//   rm_expense_categories   expense categories the owner defines
//   rm_expenses             expenses logged under a category, with proof
namespace Models;

class RecordMgmtModel
{
    public const DEFAULT_ROLES = ['Barber', 'Saloon', 'Stylist', 'Cashier', 'Supervisor', 'Manager'];

    private \PDO $db;

    public function __construct(?\PDO $db = null)
    {
        $this->db = $db ?? \Database::pdo();
        \Database::schemaOnce(static::class, __FILE__, fn() => self::ensureSchema($this->db));
    }

    public static function ensureSchema(\PDO $db): void
    {
        static $done = false;
        if ($done) { return; }
        $db->exec("CREATE TABLE IF NOT EXISTS rm_roles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_id INT NOT NULL,
            name VARCHAR(80) NOT NULL,
            UNIQUE KEY uq_rm_role (account_id, name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS rm_employees (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_id INT NOT NULL,
            first_name VARCHAR(80) NOT NULL,
            middle_name VARCHAR(80) NULL,
            last_name VARCHAR(80) NULL,
            phone VARCHAR(30) NOT NULL,
            id_no VARCHAR(30) NULL,
            role VARCHAR(80) NOT NULL,
            owed DECIMAL(12,2) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_rm_emp_account (account_id, active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS rm_services (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_id INT NOT NULL,
            name VARCHAR(150) NOT NULL,
            charge_type ENUM('fixed','percent') NOT NULL DEFAULT 'fixed',
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            description VARCHAR(255) NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_rm_svc_account (account_id, active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS rm_products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_id INT NOT NULL,
            name VARCHAR(150) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
            cost DECIMAL(12,2) NOT NULL DEFAULT 0,
            low_stock_level DECIMAL(12,2) NOT NULL DEFAULT 5,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_rm_prod_account (account_id, active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS rm_operations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_id INT NOT NULL,
            employee_id INT NOT NULL,
            service_id INT NULL,
            service_name VARCHAR(150) NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            payment_mode ENUM('cash','mpesa') NOT NULL DEFAULT 'cash',
            mpesa_name VARCHAR(100) NULL,
            mpesa_code VARCHAR(40) NULL,
            cash_given DECIMAL(12,2) NULL,
            change_due DECIMAL(12,2) NULL,
            product_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
            net_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            commission DECIMAL(12,2) NOT NULL DEFAULT 0,
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_rm_ops_account (account_id, created_at),
            KEY idx_rm_ops_employee (employee_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS rm_operation_products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            operation_id INT NOT NULL,
            product_id INT NOT NULL,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 1,
            cost DECIMAL(12,2) NOT NULL DEFAULT 0,
            KEY idx_rm_opprod_op (operation_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS rm_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_id INT NOT NULL,
            employee_id INT NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            method VARCHAR(10) NOT NULL DEFAULT 'cash',
            note VARCHAR(255) NULL,
            paid_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_rm_pay_employee (account_id, employee_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS rm_expense_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            UNIQUE KEY uq_rm_cat (account_id, name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS rm_expenses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_id INT NOT NULL,
            category_id INT NOT NULL,
            description VARCHAR(255) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            proof_path VARCHAR(255) NULL,
            proof_note VARCHAR(255) NULL,
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_rm_exp_account (account_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS rm_settings (
            account_id INT NOT NULL PRIMARY KEY,
            day_start_hour TINYINT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = true;
    }

    private function account(): int
    {
        return \Features::accountId((int) \TenantContext::tenantId());
    }

    /**
     * The business day's start hour (0-23). Default 0 = midnight-to-midnight
     * (unchanged behaviour). An owner running round the clock sets this to,
     * say, 7 so "today" always means 7am to 7am, never resetting at midnight.
     */
    public function dayStartHour(): int
    {
        $st = $this->db->prepare('SELECT day_start_hour FROM rm_settings WHERE account_id = ?');
        $st->execute([$this->account()]);
        $h = $st->fetchColumn();
        return $h !== false ? max(0, min(23, (int) $h)) : 0;
    }

    public function setDayStartHour(int $hour): void
    {
        $hour = max(0, min(23, $hour));
        $this->db->prepare('INSERT INTO rm_settings (account_id, day_start_hour) VALUES (?, ?) ON DUPLICATE KEY UPDATE day_start_hour = VALUES(day_start_hour)')
            ->execute([$this->account(), $hour]);
    }

    /**
     * The datetime (Y-m-d H:i:s) the CURRENT business day started — e.g. with
     * a 7am start hour, at 2am it's still "yesterday 07:00", not today
     * midnight. Pass a calendar date to get that date's business-day start
     * instead of "right now"'s.
     */
    public function businessDayStart(?string $calendarDate = null): string
    {
        $hour = $this->dayStartHour();
        if ($hour === 0) {
            return ($calendarDate ?: date('Y-m-d')) . ' 00:00:00';
        }
        if ($calendarDate !== null) {
            return $calendarDate . ' ' . str_pad((string) $hour, 2, '0', STR_PAD_LEFT) . ':00:00';
        }
        $now = new \DateTime();
        $cutoff = (clone $now)->setTime($hour, 0, 0);
        if ($now < $cutoff) { $cutoff->modify('-1 day'); }
        return $cutoff->format('Y-m-d H:i:s');
    }

    /** The business day before the current one — e.g. a 1am sale under a 7am cutoff shows up here, not under "today". */
    public function businessYesterdayStart(): string
    {
        $todayCalendarDate = substr($this->businessDayStart(), 0, 10);
        return $this->businessDayStart(date('Y-m-d', strtotime($todayCalendarDate . ' -1 day')));
    }

    /** Seeds the default roles the first time an account opens Employees. */
    public function ensureDefaultRoles(): void
    {
        $acc = $this->account();
        $st = $this->db->prepare('SELECT COUNT(*) FROM rm_roles WHERE account_id = ?');
        $st->execute([$acc]);
        if ((int) $st->fetchColumn() > 0) { return; }
        $ins = $this->db->prepare('INSERT IGNORE INTO rm_roles (account_id, name) VALUES (?, ?)');
        foreach (self::DEFAULT_ROLES as $r) { $ins->execute([$acc, $r]); }
    }

    public function roles(): array
    {
        $this->ensureDefaultRoles();
        $st = $this->db->prepare('SELECT * FROM rm_roles WHERE account_id = ? ORDER BY name');
        $st->execute([$this->account()]);
        return $st->fetchAll();
    }

    public function addRole(string $name): void
    {
        $name = trim($name);
        if ($name === '') { return; }
        $this->db->prepare('INSERT IGNORE INTO rm_roles (account_id, name) VALUES (?, ?)')->execute([$this->account(), $name]);
    }

    // ===== Employees =====================================================

    public function employees(bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM rm_employees WHERE account_id = ?' . ($activeOnly ? ' AND active = 1' : '');
        $st = $this->db->prepare($sql . ' ORDER BY first_name, last_name');
        $st->execute([$this->account()]);
        return $st->fetchAll();
    }

    public function employee(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM rm_employees WHERE id = ? AND account_id = ?');
        $st->execute([$id, $this->account()]);
        $r = $st->fetch();
        return $r ?: null;
    }

    public function saveEmployee(array $in, ?int $id = null): array
    {
        $first = trim((string) ($in['first_name'] ?? ''));
        $phone = trim((string) ($in['phone'] ?? ''));
        $role = trim((string) ($in['role'] ?? ''));
        if ($first === '' || $phone === '' || $role === '') {
            return ['ok' => false, 'error' => 'First name, phone number and role are required.'];
        }
        $data = [
            'account_id' => $this->account(),
            'first_name' => $first,
            'middle_name' => trim((string) ($in['middle_name'] ?? '')) ?: null,
            'last_name' => trim((string) ($in['last_name'] ?? '')) ?: null,
            'phone' => $phone,
            'id_no' => trim((string) ($in['id_no'] ?? '')) ?: null,
            'role' => $role,
        ];
        if ($id) {
            $st = $this->db->prepare('UPDATE rm_employees SET first_name=?, middle_name=?, last_name=?, phone=?, id_no=?, role=? WHERE id = ? AND account_id = ?');
            $st->execute([$data['first_name'], $data['middle_name'], $data['last_name'], $data['phone'], $data['id_no'], $data['role'], $id, $this->account()]);
        } else {
            $st = $this->db->prepare('INSERT INTO rm_employees (account_id, first_name, middle_name, last_name, phone, id_no, role) VALUES (?,?,?,?,?,?,?)');
            $st->execute([$data['account_id'], $data['first_name'], $data['middle_name'], $data['last_name'], $data['phone'], $data['id_no'], $data['role']]);
            $id = (int) $this->db->lastInsertId();
        }
        return ['ok' => true, 'id' => $id];
    }

    public function setEmployeeActive(int $id, bool $active): void
    {
        $this->db->prepare('UPDATE rm_employees SET active = ? WHERE id = ? AND account_id = ?')->execute([$active ? 1 : 0, $id, $this->account()]);
    }

    // ===== Services =======================================================

    public function services(bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM rm_services WHERE account_id = ?' . ($activeOnly ? ' AND active = 1' : '');
        $st = $this->db->prepare($sql . ' ORDER BY name');
        $st->execute([$this->account()]);
        return $st->fetchAll();
    }

    public function saveService(array $in, ?int $id = null): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') { return ['ok' => false, 'error' => 'Service name is required.']; }
        $type = ($in['charge_type'] ?? 'fixed') === 'percent' ? 'percent' : 'fixed';
        $amount = (float) ($in['amount'] ?? 0);
        $desc = trim((string) ($in['description'] ?? '')) ?: null;
        if ($id) {
            $this->db->prepare('UPDATE rm_services SET name=?, charge_type=?, amount=?, description=? WHERE id=? AND account_id=?')
                ->execute([$name, $type, $amount, $desc, $id, $this->account()]);
        } else {
            $this->db->prepare('INSERT INTO rm_services (account_id, name, charge_type, amount, description) VALUES (?,?,?,?,?)')
                ->execute([$this->account(), $name, $type, $amount, $desc]);
            $id = (int) $this->db->lastInsertId();
        }
        return ['ok' => true, 'id' => $id];
    }

    public function setServiceActive(int $id, bool $active): void
    {
        $this->db->prepare('UPDATE rm_services SET active=? WHERE id=? AND account_id=?')->execute([$active ? 1 : 0, $id, $this->account()]);
    }

    // ===== Products ========================================================

    public function products(bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM rm_products WHERE account_id = ?' . ($activeOnly ? ' AND active = 1' : '');
        $st = $this->db->prepare($sql . ' ORDER BY name');
        $st->execute([$this->account()]);
        return $st->fetchAll();
    }

    public function lowStock(): array
    {
        $st = $this->db->prepare('SELECT * FROM rm_products WHERE account_id = ? AND active = 1 AND quantity <= low_stock_level ORDER BY name');
        $st->execute([$this->account()]);
        return $st->fetchAll();
    }

    public function saveProduct(array $in, ?int $id = null): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') { return ['ok' => false, 'error' => 'Product name is required.']; }
        $qty = (float) ($in['quantity'] ?? 0);
        $cost = (float) ($in['cost'] ?? 0);
        $low = (float) ($in['low_stock_level'] ?? 5);
        if ($id) {
            $this->db->prepare('UPDATE rm_products SET name=?, quantity=?, cost=?, low_stock_level=? WHERE id=? AND account_id=?')
                ->execute([$name, $qty, $cost, $low, $id, $this->account()]);
        } else {
            $this->db->prepare('INSERT INTO rm_products (account_id, name, quantity, cost, low_stock_level) VALUES (?,?,?,?,?)')
                ->execute([$this->account(), $name, $qty, $cost, $low]);
            $id = (int) $this->db->lastInsertId();
        }
        return ['ok' => true, 'id' => $id];
    }

    public function setProductActive(int $id, bool $active): void
    {
        $this->db->prepare('UPDATE rm_products SET active=? WHERE id=? AND account_id=?')->execute([$active ? 1 : 0, $id, $this->account()]);
    }

    // ===== Operations ======================================================

    /**
     * Records one service performed by $employeeId. $products: [[product_id, quantity], ...]
     * Deducts product stock, works out product cost, commission = (amount − product cost) / 2,
     * and credits that to the employee's owed balance.
     */
    public function recordOperation(int $employeeId, array $in, array $products = []): array
    {
        $employee = $this->employee($employeeId);
        if (!$employee) { return ['ok' => false, 'error' => 'Employee not found.']; }
        $serviceId = (int) ($in['service_id'] ?? 0) ?: null;
        $serviceName = trim((string) ($in['service_name'] ?? ''));
        if ($serviceId) {
            $st = $this->db->prepare('SELECT name FROM rm_services WHERE id=? AND account_id=?');
            $st->execute([$serviceId, $this->account()]);
            $serviceName = (string) ($st->fetchColumn() ?: $serviceName);
        }
        if ($serviceName === '') { return ['ok' => false, 'error' => 'Choose a service.']; }
        $amount = (float) ($in['amount'] ?? 0);
        if ($amount <= 0) { return ['ok' => false, 'error' => 'Enter the amount paid.']; }
        $mode = ($in['payment_mode'] ?? 'cash') === 'mpesa' ? 'mpesa' : 'cash';
        $mpesaName = $mpesaCode = null;
        $cashGiven = $changeDue = null;
        if ($mode === 'mpesa') {
            $mpesaName = trim((string) ($in['mpesa_name'] ?? '')) ?: null;
            $mpesaCode = trim((string) ($in['mpesa_code'] ?? '')) ?: null;
        } else {
            $cashGiven = isset($in['cash_given']) && $in['cash_given'] !== '' ? (float) $in['cash_given'] : $amount;
            $changeDue = round($cashGiven - $amount, 2);
        }

        $this->db->beginTransaction();
        try {
            $productCost = 0.0;
            $used = [];
            foreach ($products as $p) {
                $pid = (int) ($p['product_id'] ?? 0);
                $qty = (float) ($p['quantity'] ?? 0);
                if (!$pid || $qty <= 0) { continue; }
                $st = $this->db->prepare('SELECT * FROM rm_products WHERE id=? AND account_id=? FOR UPDATE');
                $st->execute([$pid, $this->account()]);
                $prod = $st->fetch();
                if (!$prod) { continue; }
                $lineCost = round((float) $prod['cost'] * $qty, 2);
                $productCost += $lineCost;
                $this->db->prepare('UPDATE rm_products SET quantity = quantity - ? WHERE id = ?')->execute([$qty, $pid]);
                $used[] = ['product_id' => $pid, 'quantity' => $qty, 'cost' => $lineCost];
            }
            $netAmount = max(0, round($amount - $productCost, 2));
            $commission = round($netAmount / 2, 2);

            $st = $this->db->prepare('INSERT INTO rm_operations
                (account_id, employee_id, service_id, service_name, amount, payment_mode, mpesa_name, mpesa_code,
                 cash_given, change_due, product_cost, net_amount, commission, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $st->execute([
                $this->account(), $employeeId, $serviceId, $serviceName, $amount, $mode, $mpesaName, $mpesaCode,
                $cashGiven, $changeDue, $productCost, $netAmount, $commission, \TenantContext::userId(),
            ]);
            $opId = (int) $this->db->lastInsertId();
            foreach ($used as $u) {
                $this->db->prepare('INSERT INTO rm_operation_products (operation_id, product_id, quantity, cost) VALUES (?,?,?,?)')
                    ->execute([$opId, $u['product_id'], $u['quantity'], $u['cost']]);
            }
            $this->db->prepare('UPDATE rm_employees SET owed = owed + ? WHERE id = ?')->execute([$commission, $employeeId]);
            $this->db->commit();
            return ['ok' => true, 'id' => $opId, 'commission' => $commission, 'change_due' => $changeDue];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return ['ok' => false, 'error' => 'Could not save: ' . $e->getMessage()];
        }
    }

    public function operations(string $from, string $to, ?int $employeeId = null): array
    {
        $sql = 'SELECT o.*, e.first_name, e.last_name, e.role FROM rm_operations o
                JOIN rm_employees e ON e.id = o.employee_id
                WHERE o.account_id = ? AND o.created_at >= ? AND o.created_at < ? + INTERVAL 1 DAY';
        $args = [$this->account(), $from, $to];
        if ($employeeId) { $sql .= ' AND o.employee_id = ?'; $args[] = $employeeId; }
        $sql .= ' ORDER BY o.created_at DESC';
        $st = $this->db->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    }

    // ===== Commissions & payments =========================================

    /** Per employee: earned (gross), commission and paid in the range, plus their current owed balance. */
    public function commissionSummary(string $from, string $to): array
    {
        $st = $this->db->prepare('SELECT e.id, e.first_name, e.middle_name, e.last_name, e.role, e.owed,
                COALESCE(SUM(o.net_amount),0) earned, COALESCE(SUM(o.commission),0) commission, COUNT(o.id) jobs
            FROM rm_employees e
            LEFT JOIN rm_operations o ON o.employee_id = e.id AND o.account_id = e.account_id AND o.created_at >= ? AND o.created_at < ? + INTERVAL 1 DAY
            WHERE e.account_id = ? AND e.active = 1
            GROUP BY e.id ORDER BY commission DESC');
        $st->execute([$from, $to, $this->account()]);
        return $st->fetchAll();
    }

    public function companyTotals(string $from, string $to): array
    {
        $st = $this->db->prepare('SELECT COALESCE(SUM(amount),0) revenue, COALESCE(SUM(net_amount),0) net,
                COALESCE(SUM(commission),0) commissions, COUNT(*) jobs
            FROM rm_operations WHERE account_id = ? AND created_at >= ? AND created_at < ? + INTERVAL 1 DAY');
        $st->execute([$this->account(), $from, $to]);
        return $st->fetch() ?: ['revenue' => 0, 'net' => 0, 'commissions' => 0, 'jobs' => 0];
    }

    /** Pays an employee against what they're owed; amount may be partial. */
    public function payEmployee(int $employeeId, float $amount, string $method, string $note): array
    {
        $employee = $this->employee($employeeId);
        if (!$employee) { return ['ok' => false, 'error' => 'Employee not found.']; }
        if ($amount <= 0) { return ['ok' => false, 'error' => 'Enter an amount to pay.']; }
        if ($amount > (float) $employee['owed'] + 0.01) {
            return ['ok' => false, 'error' => 'That is more than they are owed (' . number_format((float) $employee['owed'], 0) . ').'];
        }
        $this->db->prepare('INSERT INTO rm_payments (account_id, employee_id, amount, method, note, paid_by) VALUES (?,?,?,?,?,?)')
            ->execute([$this->account(), $employeeId, $amount, $method, $note ?: null, \TenantContext::userId()]);
        $this->db->prepare('UPDATE rm_employees SET owed = GREATEST(owed - ?, 0) WHERE id = ?')->execute([$amount, $employeeId]);
        return ['ok' => true];
    }

    public function payments(int $limit = 100): array
    {
        $st = $this->db->prepare('SELECT p.*, e.first_name, e.last_name FROM rm_payments p
            JOIN rm_employees e ON e.id = p.employee_id WHERE p.account_id = ? ORDER BY p.created_at DESC LIMIT ' . (int) $limit);
        $st->execute([$this->account()]);
        return $st->fetchAll();
    }

    // ===== Expenses ========================================================

    public function expenseCategories(): array
    {
        $st = $this->db->prepare('SELECT * FROM rm_expense_categories WHERE account_id = ? ORDER BY name');
        $st->execute([$this->account()]);
        return $st->fetchAll();
    }

    public function addExpenseCategory(string $name): array
    {
        $name = trim($name);
        if ($name === '') { return ['ok' => false, 'error' => 'Category name is required.']; }
        $this->db->prepare('INSERT IGNORE INTO rm_expense_categories (account_id, name) VALUES (?,?)')->execute([$this->account(), $name]);
        return ['ok' => true];
    }

    public function addExpense(array $in): array
    {
        $catId = (int) ($in['category_id'] ?? 0);
        $desc = trim((string) ($in['description'] ?? ''));
        $amount = (float) ($in['amount'] ?? 0);
        if (!$catId || $desc === '' || $amount <= 0) {
            return ['ok' => false, 'error' => 'Category, description and amount are required.'];
        }
        $this->db->prepare('INSERT INTO rm_expenses (account_id, category_id, description, amount, proof_path, proof_note, created_by)
            VALUES (?,?,?,?,?,?,?)')->execute([
                $this->account(), $catId, $desc, $amount,
                $in['proof_path'] ?? null, trim((string) ($in['proof_note'] ?? '')) ?: null, \TenantContext::userId(),
            ]);
        return ['ok' => true];
    }

    public function expenses(string $from, string $to): array
    {
        $st = $this->db->prepare('SELECT ex.*, c.name category_name FROM rm_expenses ex
            JOIN rm_expense_categories c ON c.id = ex.category_id
            WHERE ex.account_id = ? AND ex.created_at >= ? AND ex.created_at < ? + INTERVAL 1 DAY ORDER BY ex.created_at DESC');
        $st->execute([$this->account(), $from, $to]);
        return $st->fetchAll();
    }

    public function expensesByCategory(string $from, string $to): array
    {
        $st = $this->db->prepare('SELECT c.name, COALESCE(SUM(ex.amount),0) total, COUNT(ex.id) items
            FROM rm_expense_categories c
            LEFT JOIN rm_expenses ex ON ex.category_id = c.id AND ex.created_at >= ? AND ex.created_at < ? + INTERVAL 1 DAY
            WHERE c.account_id = ? GROUP BY c.id ORDER BY total DESC');
        $st->execute([$from, $to, $this->account()]);
        return $st->fetchAll();
    }

    // ===== Employee tracker ================================================

    /** One row per employee per day in range: jobs, revenue, commission — for the tracker table/chart. */
    public function trackerRows(string $from, string $to): array
    {
        $st = $this->db->prepare("SELECT e.id employee_id, CONCAT(e.first_name, ' ', COALESCE(e.last_name,'')) name, e.role,
                DATE(o.created_at) day, COUNT(o.id) jobs, COALESCE(SUM(o.net_amount),0) revenue, COALESCE(SUM(o.commission),0) commission
            FROM rm_employees e
            JOIN rm_operations o ON o.employee_id = e.id AND o.created_at >= ? AND o.created_at < ? + INTERVAL 1 DAY
            WHERE e.account_id = ? GROUP BY e.id, DATE(o.created_at) ORDER BY day DESC, revenue DESC");
        $st->execute([$from, $to, $this->account()]);
        return $st->fetchAll();
    }

    /** Totals per employee over the range, with the account-wide average revenue for red/green coding. */
    public function trackerTotals(string $from, string $to): array
    {
        $st = $this->db->prepare("SELECT e.id employee_id, CONCAT(e.first_name, ' ', COALESCE(e.last_name,'')) name, e.role,
                COUNT(o.id) jobs, COALESCE(SUM(o.net_amount),0) revenue, COALESCE(SUM(o.commission),0) commission
            FROM rm_employees e
            LEFT JOIN rm_operations o ON o.employee_id = e.id AND o.created_at >= ? AND o.created_at < ? + INTERVAL 1 DAY
            WHERE e.account_id = ? AND e.active = 1 GROUP BY e.id ORDER BY revenue DESC");
        $st->execute([$from, $to, $this->account()]);
        $rows = $st->fetchAll();
        $avg = $rows ? array_sum(array_column($rows, 'revenue')) / count($rows) : 0;
        return ['rows' => $rows, 'average' => $avg];
    }

    // ===== Clean records ====================================================
    // Lets the owner see everything recorded on the account — employees,
    // services, products, operations, payments, expenses — and delete the
    // specific entries they pick (not a date sweep). Deleting an operation or
    // payment reverses its effect on the employee's owed balance, since this
    // is meant for correcting mistakes. Owner-only.

    public const RECORD_TYPES = ['employees', 'services', 'products', 'operations', 'payments', 'expenses'];

    /** Every record of every type, newest/most-recent first within each type, for the Clean records browser. */
    public function allRecords(): array
    {
        $acc = $this->account();
        $out = [];
        $out['employees'] = $this->employees(false);
        $out['services'] = $this->services(false);
        $out['products'] = $this->products(false);
        $st = $this->db->prepare("SELECT o.*, CONCAT(e.first_name, ' ', COALESCE(e.last_name,'')) employee_name
            FROM rm_operations o JOIN rm_employees e ON e.id = o.employee_id
            WHERE o.account_id = ? ORDER BY o.created_at DESC LIMIT 500");
        $st->execute([$acc]);
        $out['operations'] = $st->fetchAll();
        $out['payments'] = $this->payments(500);
        $st = $this->db->prepare('SELECT ex.*, c.name category_name FROM rm_expenses ex
            JOIN rm_expense_categories c ON c.id = ex.category_id
            WHERE ex.account_id = ? ORDER BY ex.created_at DESC LIMIT 500');
        $st->execute([$acc]);
        $out['expenses'] = $st->fetchAll();
        return $out;
    }

    /** How many operations/payments reference this employee — if any, deleting is blocked (deactivate instead). */
    public function employeeUsage(int $id): int
    {
        $acc = $this->account();
        $st = $this->db->prepare('SELECT (SELECT COUNT(*) FROM rm_operations WHERE employee_id = ? AND account_id = ?)
            + (SELECT COUNT(*) FROM rm_payments WHERE employee_id = ? AND account_id = ?)');
        $st->execute([$id, $acc, $id, $acc]);
        return (int) $st->fetchColumn();
    }

    public function serviceUsage(int $id): int
    {
        $st = $this->db->prepare('SELECT COUNT(*) FROM rm_operations WHERE service_id = ? AND account_id = ?');
        $st->execute([$id, $this->account()]);
        return (int) $st->fetchColumn();
    }

    public function productUsage(int $id): int
    {
        $st = $this->db->prepare('SELECT COUNT(*) FROM rm_operation_products op
            JOIN rm_operations o ON o.id = op.operation_id WHERE op.product_id = ? AND o.account_id = ?');
        $st->execute([$id, $this->account()]);
        return (int) $st->fetchColumn();
    }

    /**
     * Deletes the chosen ids of one $type (one of RECORD_TYPES). Employees,
     * services and products that are referenced by any operation are skipped
     * (use Deactivate instead) — the response says how many were skipped.
     * Deleting an operation reverses its commission from the employee's owed
     * balance; deleting a payment adds the amount back to what's owed.
     */
    public function deleteRecords(string $type, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_filter($ids, fn($id) => $id > 0);
        if (!in_array($type, self::RECORD_TYPES, true) || !$ids) {
            return ['ok' => false, 'error' => 'Choose at least one record to delete.'];
        }
        $acc = $this->account();
        $in = implode(',', array_fill(0, count($ids), '?'));
        $removed = 0; $skipped = 0;

        try {
            if ($type === 'employees') {
                foreach ($ids as $id) {
                    if ($this->employeeUsage($id) > 0) { $skipped++; continue; }
                    $this->db->prepare('DELETE FROM rm_employees WHERE id = ? AND account_id = ?')->execute([$id, $acc]);
                    $removed++;
                }
            } elseif ($type === 'services') {
                foreach ($ids as $id) {
                    if ($this->serviceUsage($id) > 0) { $skipped++; continue; }
                    $this->db->prepare('DELETE FROM rm_services WHERE id = ? AND account_id = ?')->execute([$id, $acc]);
                    $removed++;
                }
            } elseif ($type === 'products') {
                foreach ($ids as $id) {
                    if ($this->productUsage($id) > 0) { $skipped++; continue; }
                    $this->db->prepare('DELETE FROM rm_products WHERE id = ? AND account_id = ?')->execute([$id, $acc]);
                    $removed++;
                }
            } elseif ($type === 'operations') {
                $this->db->beginTransaction();
                $st = $this->db->prepare("SELECT * FROM rm_operations WHERE id IN ($in) AND account_id = ?");
                $st->execute([...$ids, $acc]);
                foreach ($st->fetchAll() as $op) {
                    $this->db->prepare('UPDATE rm_employees SET owed = GREATEST(owed - ?, 0) WHERE id = ?')
                        ->execute([(float) $op['commission'], (int) $op['employee_id']]);
                    $this->db->prepare('DELETE FROM rm_operation_products WHERE operation_id = ?')->execute([$op['id']]);
                    $this->db->prepare('DELETE FROM rm_operations WHERE id = ?')->execute([$op['id']]);
                    $removed++;
                }
                $this->db->commit();
            } elseif ($type === 'payments') {
                $this->db->beginTransaction();
                $st = $this->db->prepare("SELECT * FROM rm_payments WHERE id IN ($in) AND account_id = ?");
                $st->execute([...$ids, $acc]);
                foreach ($st->fetchAll() as $p) {
                    $this->db->prepare('UPDATE rm_employees SET owed = owed + ? WHERE id = ?')
                        ->execute([(float) $p['amount'], (int) $p['employee_id']]);
                    $this->db->prepare('DELETE FROM rm_payments WHERE id = ?')->execute([$p['id']]);
                    $removed++;
                }
                $this->db->commit();
            } elseif ($type === 'expenses') {
                $st = $this->db->prepare("DELETE FROM rm_expenses WHERE id IN ($in) AND account_id = ?");
                $st->execute([...$ids, $acc]);
                $removed = $st->rowCount();
            }
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            return ['ok' => false, 'error' => 'Could not delete: ' . $e->getMessage()];
        }
        return ['ok' => true, 'removed' => $removed, 'skipped' => $skipped];
    }

    /**
     * Reset site: wipes every Record Management record for this account —
     * employees, services, products, operations (+ their product usage),
     * payments and expenses (+ categories). Default roles are reseeded so
     * the account isn't left with no roles to pick from. Irreversible.
     */
    public function resetAll(): array
    {
        $acc = $this->account();
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE op FROM rm_operation_products op
                JOIN rm_operations o ON o.id = op.operation_id WHERE o.account_id = ?')->execute([$acc]);
            foreach (['rm_operations', 'rm_payments', 'rm_expenses', 'rm_expense_categories',
                      'rm_products', 'rm_services', 'rm_employees', 'rm_roles'] as $table) {
                $this->db->prepare("DELETE FROM $table WHERE account_id = ?")->execute([$acc]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return ['ok' => false, 'error' => 'Could not reset: ' . $e->getMessage()];
        }
        $this->ensureDefaultRoles();
        return ['ok' => true];
    }

    /**
     * One row per day for the last $days days (oldest first): revenue and
     * commissions earned (from Operations), expenses logged, and amounts
     * paid out to employees. Used by the dashboard's performance graph and
     * cash flow — "cash in" is revenue, "cash out" is expenses + payouts.
     */
    public function dailyTrend(int $days = 7): array
    {
        $acc = $this->account();
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $to = date('Y-m-d');

        $rev = $this->db->prepare("SELECT DATE(created_at) d, COALESCE(SUM(amount),0) revenue, COALESCE(SUM(commission),0) commissions
            FROM rm_operations WHERE account_id = ? AND created_at >= ? AND created_at < ? + INTERVAL 1 DAY GROUP BY DATE(created_at)");
        $rev->execute([$acc, $from, $to]);
        $revByDay = array_column($rev->fetchAll(), null, 'd');

        $exp = $this->db->prepare("SELECT DATE(created_at) d, COALESCE(SUM(amount),0) expenses
            FROM rm_expenses WHERE account_id = ? AND created_at >= ? AND created_at < ? + INTERVAL 1 DAY GROUP BY DATE(created_at)");
        $exp->execute([$acc, $from, $to]);
        $expByDay = array_column($exp->fetchAll(), null, 'd');

        $pay = $this->db->prepare("SELECT DATE(created_at) d, COALESCE(SUM(amount),0) paid
            FROM rm_payments WHERE account_id = ? AND created_at >= ? AND created_at < ? + INTERVAL 1 DAY GROUP BY DATE(created_at)");
        $pay->execute([$acc, $from, $to]);
        $payByDay = array_column($pay->fetchAll(), null, 'd');

        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $revenue = (float) ($revByDay[$d]['revenue'] ?? 0);
            $commissions = (float) ($revByDay[$d]['commissions'] ?? 0);
            $expenses = (float) ($expByDay[$d]['expenses'] ?? 0);
            $paid = (float) ($payByDay[$d]['paid'] ?? 0);
            $out[] = [
                'date' => $d,
                'revenue' => $revenue,
                'commissions' => $commissions,
                'expenses' => $expenses,
                'paid' => $paid,
                'cash_in' => $revenue,
                'cash_out' => $expenses + $paid,
                'net' => $revenue - $expenses - $paid,
            ];
        }
        return $out;
    }

    // ===== Reports ==========================================================
    // Printable reports — Employees report (what each employee earned and was
    // paid) and Staff report (what each logged-in user — admin/supervisor/
    // cashier — recorded on the Operations sheet). Either one employee/staff
    // member or every one of them, over a date range.

    /** One row per employee (or just $employeeId): jobs, revenue, commission earned, paid out in range, and current owed. */
    public function employeeReport(?int $employeeId, string $from, string $to): array
    {
        $acc = $this->account();
        $sql = "SELECT e.id, e.first_name, e.middle_name, e.last_name, e.role, e.owed,
                COUNT(o.id) jobs, COALESCE(SUM(o.net_amount),0) revenue, COALESCE(SUM(o.commission),0) commission,
                (SELECT COALESCE(SUM(p.amount),0) FROM rm_payments p WHERE p.employee_id = e.id AND p.account_id = e.account_id AND p.created_at >= ? AND p.created_at < ? + INTERVAL 1 DAY) paid
            FROM rm_employees e
            LEFT JOIN rm_operations o ON o.employee_id = e.id AND o.account_id = e.account_id AND o.created_at >= ? AND o.created_at < ? + INTERVAL 1 DAY
            WHERE e.account_id = ?";
        $args = [$from, $to, $from, $to, $acc];
        if ($employeeId) { $sql .= ' AND e.id = ?'; $args[] = $employeeId; }
        $sql .= ' GROUP BY e.id ORDER BY e.first_name, e.last_name';
        $st = $this->db->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    }

    /** Every service an employee (or all) performed in range — the detail behind the Employees report. */
    public function employeeReportDetail(?int $employeeId, string $from, string $to): array
    {
        $sql = "SELECT o.*, CONCAT(e.first_name, ' ', COALESCE(e.last_name,'')) employee_name
            FROM rm_operations o JOIN rm_employees e ON e.id = o.employee_id
            WHERE o.account_id = ? AND o.created_at >= ? AND o.created_at < ? + INTERVAL 1 DAY";
        $args = [$this->account(), $from, $to];
        if ($employeeId) { $sql .= ' AND o.employee_id = ?'; $args[] = $employeeId; }
        $sql .= ' ORDER BY o.created_at';
        $st = $this->db->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    }

    /** Every login (admin/supervisor/cashier) who has recorded anything for this account — for the report picker. */
    public function staffUsers(): array
    {
        $acc = $this->account();
        $st = $this->db->prepare("SELECT DISTINCT u.id, u.username FROM users u
            WHERE u.id IN (
                SELECT created_by FROM rm_operations WHERE account_id = ? AND created_by IS NOT NULL
                UNION SELECT paid_by FROM rm_payments WHERE account_id = ? AND paid_by IS NOT NULL
                UNION SELECT created_by FROM rm_expenses WHERE account_id = ? AND created_by IS NOT NULL
            ) ORDER BY u.username");
        $st->execute([$acc, $acc, $acc]);
        return $st->fetchAll();
    }

    /** One row per staff login (or just $userId): operations recorded, revenue processed, payments made, expenses logged. */
    public function staffReport(?int $userId, string $from, string $to): array
    {
        $acc = $this->account();
        $users = $this->staffUsers();
        if ($userId) { $users = array_values(array_filter($users, fn($u) => (int) $u['id'] === $userId)); }
        $out = [];
        foreach ($users as $u) {
            $uid = (int) $u['id'];
            $st = $this->db->prepare('SELECT COUNT(*) jobs, COALESCE(SUM(amount),0) revenue, COALESCE(SUM(commission),0) commission
                FROM rm_operations WHERE account_id = ? AND created_by = ? AND created_at >= ? AND created_at < ? + INTERVAL 1 DAY');
            $st->execute([$acc, $uid, $from, $to]);
            $ops = $st->fetch();
            $st = $this->db->prepare('SELECT COUNT(*) n, COALESCE(SUM(amount),0) total FROM rm_payments
                WHERE account_id = ? AND paid_by = ? AND created_at >= ? AND created_at < ? + INTERVAL 1 DAY');
            $st->execute([$acc, $uid, $from, $to]);
            $pay = $st->fetch();
            $st = $this->db->prepare('SELECT COUNT(*) n, COALESCE(SUM(amount),0) total FROM rm_expenses
                WHERE account_id = ? AND created_by = ? AND created_at >= ? AND created_at < ? + INTERVAL 1 DAY');
            $st->execute([$acc, $uid, $from, $to]);
            $exp = $st->fetch();
            $out[] = [
                'user_id' => $uid, 'username' => $u['username'],
                'jobs' => (int) $ops['jobs'], 'revenue' => (float) $ops['revenue'], 'commission' => (float) $ops['commission'],
                'payments_made' => (int) $pay['n'], 'payments_total' => (float) $pay['total'],
                'expenses_logged' => (int) $exp['n'], 'expenses_total' => (float) $exp['total'],
            ];
        }
        return $out;
    }
}
