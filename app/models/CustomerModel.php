<?php
namespace Models;

class CustomerModel extends Model
{
    public function __construct(?\PDO $db = null)
    {
        parent::__construct($db);
        $this->ensureSchema();
        new OrderModel($this->db); // balances read orders.amount_paid, which OrderModel adds on old installs
    }

    public function listForTenant(int $limit = 200): array
    {
        $stmt = $this->db->prepare('SELECT * FROM customers WHERE tenant_id = ? ORDER BY name ASC LIMIT ' . (int) $limit);
        $stmt->execute([\TenantContext::tenantId()]);
        return $stmt->fetchAll();
    }

    public function search(string $q, int $limit = 10): array
    {
        $q = trim($q);
        if ($q === '') { return []; }
        $stmt = $this->db->prepare(
            'SELECT * FROM customers
              WHERE tenant_id = ?
                AND (name LIKE ? OR company_name LIKE ? OR email LIKE ? OR phone LIKE ?)
           ORDER BY (name LIKE ?) DESC, name ASC
              LIMIT ' . (int) $limit
        );
        $like = '%' . $q . '%';
        $prefix = $q . '%';
        $stmt->execute([\TenantContext::tenantId(), $like, $like, $like, $like, $prefix]);
        return $stmt->fetchAll();
    }

    /**
     * Customer lookup for the till: saved (loyal) customers first, then names
     * typed on earlier sales that were never saved — each with what they owe
     * on open credit sales. An empty query lists the most recent ones.
     */
    public function searchForSale(string $q, int $limit = 10): array
    {
        $tid = \TenantContext::tenantId();
        $q = trim($q);
        $like = '%' . $q . '%';
        $owedSql = "(SELECT COALESCE(SUM(o.total - o.amount_paid), 0) FROM orders o
                      WHERE o.tenant_id = c.tenant_id AND o.status = 'open'
                        AND (o.customer_id = c.id OR (o.customer_id IS NULL AND LOWER(TRIM(o.table_name)) = LOWER(TRIM(c.name)))))";
        if ($q === '') {
            $stmt = $this->db->prepare("SELECT c.*, {$owedSql} AS owed FROM customers c WHERE c.tenant_id = ? ORDER BY c.created_at DESC LIMIT " . (int) $limit);
            $stmt->execute([$tid]);
        } else {
            $stmt = $this->db->prepare(
                "SELECT c.*, {$owedSql} AS owed FROM customers c
                  WHERE c.tenant_id = ? AND (c.name LIKE ? OR c.company_name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)
               ORDER BY (c.name LIKE ?) DESC, c.name ASC LIMIT " . (int) $limit
            );
            $stmt->execute([$tid, $like, $like, $like, $like, $q . '%']);
        }
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) { $r['saved'] = true; }
        unset($r);

        if ($q !== '' && count($rows) < $limit) {
            $known = array_map(fn($r) => mb_strtolower(trim($r['name'])), $rows);
            $stmt = $this->db->prepare(
                "SELECT TRIM(o.table_name) AS name, MAX(o.customer_phone) AS phone, MAX(o.customer_email) AS email,
                        SUM(CASE WHEN o.status = 'open' THEN o.total - o.amount_paid ELSE 0 END) AS owed
                   FROM orders o
                  WHERE o.tenant_id = ? AND o.status <> 'void' AND o.table_name LIKE ?
                    AND o.table_name NOT IN ('Walk-in Customer', 'Walk-in')
               GROUP BY TRIM(o.table_name)
               ORDER BY MAX(o.created_at) DESC LIMIT " . (int) $limit
            );
            $stmt->execute([$tid, $like]);
            foreach ($stmt->fetchAll() as $r) {
                if (in_array(mb_strtolower($r['name']), $known, true) || count($rows) >= $limit) { continue; }
                $rows[] = ['id' => 0, 'name' => $r['name'], 'phone' => $r['phone'], 'email' => $r['email'], 'company_name' => '', 'location' => '', 'owed' => $r['owed'], 'saved' => false];
            }
        }
        return $rows;
    }

    /** Saved customers with their running totals: bought, owed on credit, last visit. */
    public function listWithBalances(int $limit = 500): array
    {
        $stmt = $this->db->prepare(
            "SELECT c.*,
                    COALESCE(SUM(CASE WHEN o.status <> 'void' THEN o.total ELSE 0 END), 0) AS total_bought,
                    COALESCE(SUM(CASE WHEN o.status = 'open' THEN o.total - o.amount_paid ELSE 0 END), 0) AS owed,
                    COUNT(CASE WHEN o.status <> 'void' THEN 1 END) AS visits,
                    MAX(o.created_at) AS last_visit
               FROM customers c
          LEFT JOIN orders o ON o.tenant_id = c.tenant_id
                            AND (o.customer_id = c.id OR (o.customer_id IS NULL AND LOWER(TRIM(o.table_name)) = LOWER(TRIM(c.name))))
              WHERE c.tenant_id = ?
           GROUP BY c.id
           ORDER BY owed DESC, c.name ASC
              LIMIT " . (int) $limit
        );
        $stmt->execute([\TenantContext::tenantId()]);
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM customers WHERE id = ? AND tenant_id = ? LIMIT 1');
        $stmt->execute([$id, \TenantContext::tenantId()]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function save(array $in): array
    {
        $tid = \TenantContext::tenantId();
        $name = trim((string) ($in['name'] ?? ''));
        $email = trim((string) ($in['email'] ?? ''));
        if ($name === '') { return ['ok' => false, 'error' => 'Enter the customer name.']; }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { return ['ok' => false, 'error' => 'Enter a valid email address.']; }

        $id = (int) ($in['id'] ?? 0);
        $args = [
            $name,
            trim((string) ($in['company_name'] ?? '')) ?: null,
            $email !== '' ? $email : null,
            trim((string) ($in['phone'] ?? '')) ?: null,
            trim((string) ($in['location'] ?? '')) ?: null,
            trim((string) ($in['notes'] ?? '')) ?: null,
        ];
        if ($id > 0 && $this->find($id)) {
            $stmt = $this->db->prepare('UPDATE customers SET name = ?, company_name = ?, email = ?, phone = ?, location = ?, notes = ? WHERE id = ? AND tenant_id = ?');
            $stmt->execute([...$args, $id, $tid]);
            return ['ok' => true, 'id' => $id, 'error' => null];
        }
        $stmt = $this->db->prepare('INSERT INTO customers (tenant_id, name, company_name, email, phone, location, notes) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$tid, ...$args]);
        return ['ok' => true, 'id' => (int) $this->db->lastInsertId(), 'error' => null];
    }

    public function findOrCreateByContact(string $name, string $email = '', string $phone = '', string $company = '', string $location = ''): ?array
    {
        $name = trim($name);
        if ($name === '') { return null; }
        $tid = \TenantContext::tenantId();
        if ($email !== '') {
            $stmt = $this->db->prepare('SELECT * FROM customers WHERE tenant_id = ? AND email = ? LIMIT 1');
            $stmt->execute([$tid, $email]);
            $row = $stmt->fetch();
            if ($row) { return $row; }
        }
        if ($phone !== '') {
            $stmt = $this->db->prepare('SELECT * FROM customers WHERE tenant_id = ? AND phone = ? LIMIT 1');
            $stmt->execute([$tid, $phone]);
            $row = $stmt->fetch();
            if ($row) { return $row; }
        }
        $stmt = $this->db->prepare('SELECT * FROM customers WHERE tenant_id = ? AND LOWER(TRIM(name)) = LOWER(?) LIMIT 1');
        $stmt->execute([$tid, $name]);
        $row = $stmt->fetch();
        if ($row) { return $row; }
        $res = $this->save(['name' => $name, 'email' => $email, 'phone' => $phone, 'company_name' => $company, 'location' => $location]);
        return $res['ok'] ? $this->find((int) $res['id']) : null;
    }

    private function ensureSchema(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS customers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            name VARCHAR(160) NOT NULL,
            company_name VARCHAR(160) NULL,
            email VARCHAR(255) NULL,
            phone VARCHAR(40) NULL,
            location VARCHAR(160) NULL,
            notes VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_customers_tenant_name (tenant_id, name),
            KEY idx_customers_tenant_email (tenant_id, email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
