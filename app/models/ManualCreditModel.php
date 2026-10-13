<?php
// app/models/ManualCreditModel.php
// The credit book: "X owes the shop N" entries recorded by hand, with no
// products attached (old debts, cash lent, goods taken before the POS, …).
// Kept apart from credit SALES (orders), which do carry products and stock.
namespace Models;

class ManualCreditModel
{
    private \PDO $db;

    public function __construct(?\PDO $db = null)
    {
        $this->db = $db ?? \Database::pdo();
        \ShopContext::ensureSchema($this->db);
    }

    private function tid(): int
    {
        $tid = \TenantContext::tenantId();
        if ($tid === null) {
            throw new \RuntimeException('No shop in context for the credit book.');
        }
        return $tid;
    }

    public function create(array $in): array
    {
        $name = trim((string) ($in['customer_name'] ?? ''));
        $amount = round((float) ($in['amount'] ?? 0), 2);
        if ($name === '') { return ['ok' => false, 'error' => 'Enter the customer\'s name.']; }
        if ($amount <= 0) { return ['ok' => false, 'error' => 'Enter how much they owe.']; }
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['credit_date'] ?? '') ? $in['credit_date'] : date('Y-m-d');
        $this->db->prepare(
            'INSERT INTO manual_credits (tenant_id, customer_name, phone, amount, notes, credit_date, created_by) VALUES (?,?,?,?,?,?,?)'
        )->execute([$this->tid(), $name, trim((string) ($in['phone'] ?? '')) ?: null, $amount,
                    trim((string) ($in['notes'] ?? '')) ?: null, $date, \TenantContext::userId()]);
        return ['ok' => true, 'error' => null];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM manual_credits WHERE id = ? AND tenant_id = ? LIMIT 1');
        $stmt->execute([$id, $this->tid()]);
        return $stmt->fetch() ?: null;
    }

    /** @param string $status 'open', 'paid' or 'all' */
    public function list(string $status = 'open', int $limit = 200): array
    {
        $sql = 'SELECT c.*, u.username AS by_name FROM manual_credits c LEFT JOIN users u ON u.id = c.created_by WHERE c.tenant_id = ?';
        $args = [$this->tid()];
        if ($status !== 'all') { $sql .= ' AND c.status = ?'; $args[] = $status; }
        $sql .= ' ORDER BY c.credit_date DESC, c.id DESC LIMIT ' . (int) $limit;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll();
    }

    public function payments(int $creditId): array
    {
        $stmt = $this->db->prepare(
            'SELECT p.*, u.username AS by_name FROM manual_credit_payments p LEFT JOIN users u ON u.id = p.received_by
              WHERE p.credit_id = ? AND p.tenant_id = ? ORDER BY p.created_at DESC'
        );
        $stmt->execute([$creditId, $this->tid()]);
        return $stmt->fetchAll();
    }

    public function recordPayment(int $creditId, float $amount, string $method): array
    {
        $amount = round($amount, 2);
        $method = in_array($method, ['cash', 'mpesa', 'bank'], true) ? $method : 'cash';
        if ($amount <= 0) { return ['ok' => false, 'error' => 'Enter the amount received.']; }
        $tid = $this->tid();
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM manual_credits WHERE id = ? AND tenant_id = ? FOR UPDATE');
            $stmt->execute([$creditId, $tid]);
            $c = $stmt->fetch();
            if (!$c || $c['status'] !== 'open') { $this->db->rollBack(); return ['ok' => false, 'error' => 'That credit is not open.']; }
            $balance = round((float) $c['amount'] - (float) $c['amount_paid'], 2);
            if ($amount > $balance + 0.004) { $this->db->rollBack(); return ['ok' => false, 'error' => 'That is more than the balance of ' . number_format($balance, 2) . '.']; }
            $this->db->prepare('INSERT INTO manual_credit_payments (tenant_id, credit_id, amount, method, received_by) VALUES (?,?,?,?,?)')
                ->execute([$tid, $creditId, $amount, $method, \TenantContext::userId()]);
            $paid = round((float) $c['amount_paid'] + $amount, 2);
            $this->db->prepare('UPDATE manual_credits SET amount_paid = ?, status = ? WHERE id = ?')
                ->execute([$paid, $paid + 0.004 >= (float) $c['amount'] ? 'paid' : 'open', $creditId]);
            $this->db->commit();
            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return ['ok' => false, 'error' => 'Could not record the payment.'];
        }
    }

    /** Only an entry with no payments yet can be deleted (a typo fix). */
    public function delete(int $id): array
    {
        $c = $this->find($id);
        if (!$c) { return ['ok' => false, 'error' => 'Credit not found.']; }
        if ((float) $c['amount_paid'] > 0) { return ['ok' => false, 'error' => 'Payments were already received on this credit — it can\'t be deleted.']; }
        $this->db->prepare('DELETE FROM manual_credits WHERE id = ? AND tenant_id = ?')->execute([$id, $this->tid()]);
        return ['ok' => true, 'error' => null];
    }

    /** Total still owed in the credit book for a shop. */
    public static function outstandingFor(\PDO $db, int $tenantId): float
    {
        \ShopContext::ensureSchema($db);
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount - amount_paid),0) FROM manual_credits WHERE tenant_id = ? AND status = 'open'");
        $stmt->execute([$tenantId]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /** Credit-book money collected on a given day. */
    public static function collectedOn(\PDO $db, int $tenantId, string $date): float
    {
        \ShopContext::ensureSchema($db);
        $stmt = $db->prepare('SELECT COALESCE(SUM(amount),0) FROM manual_credit_payments WHERE tenant_id = ? AND DATE(created_at) = ?');
        $stmt->execute([$tenantId, $date]);
        return round((float) $stmt->fetchColumn(), 2);
    }
}
