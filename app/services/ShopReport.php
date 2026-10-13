<?php
// app/services/ShopReport.php
// Per-shop money for a date range, addressed by tenant id (not the session),
// so the owner's dashboard and Shops page can show every shop side by side.
// Revenue/cost follow the same rules as the dashboard: completed direct
// sales by created_at + paid credit tabs by paid_at; cost = qty × buying price.

class ShopReport
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** @return array{revenue:float,cost:float,gross:float,expenses:float,net:float,count:int,owed:float} */
    public function forShop(int $tenantId, string $from, string $to): array
    {
        $saleRev = $this->row(
            "SELECT COUNT(*) AS n, COALESCE(SUM(total),0) AS rev FROM sales
              WHERE tenant_id = ? AND status = 'completed' AND DATE(created_at) BETWEEN ? AND ?", [$tenantId, $from, $to]);
        $orderRev = $this->row(
            "SELECT COUNT(*) AS n, COALESCE(SUM(total),0) AS rev FROM orders
              WHERE tenant_id = ? AND status = 'paid' AND DATE(paid_at) BETWEEN ? AND ?", [$tenantId, $from, $to]);
        $saleCost = (float) $this->scalar(
            "SELECT COALESCE(SUM(si.quantity * COALESCE(p.buying_price,0)),0)
               FROM sale_items si JOIN sales s ON s.id = si.sale_id AND s.tenant_id = si.tenant_id
          LEFT JOIN products p ON p.id = si.product_id AND p.tenant_id = si.tenant_id
              WHERE si.tenant_id = ? AND s.status = 'completed' AND DATE(s.created_at) BETWEEN ? AND ?", [$tenantId, $from, $to]);
        $orderCost = (float) $this->scalar(
            "SELECT COALESCE(SUM(oi.quantity * COALESCE(p.buying_price,0)),0)
               FROM order_items oi JOIN orders o ON o.id = oi.order_id AND o.tenant_id = oi.tenant_id
          LEFT JOIN products p ON p.id = oi.product_id AND p.tenant_id = oi.tenant_id
              WHERE oi.tenant_id = ? AND o.status = 'paid' AND DATE(o.paid_at) BETWEEN ? AND ?", [$tenantId, $from, $to]);
        $expenses = (new Models\FinanceModel($this->db))->expenseTotal($tenantId, $from, $to);

        $revenue = round((float) $saleRev['rev'] + (float) $orderRev['rev'], 2);
        $cost = round($saleCost + $orderCost, 2);
        $gross = round($revenue - $cost, 2);
        return [
            'revenue'  => $revenue,
            'cost'     => $cost,
            'gross'    => $gross,
            'expenses' => $expenses,
            'net'      => round($gross - $expenses, 2),
            'count'    => (int) $saleRev['n'] + (int) $orderRev['n'],
            'owed'     => $this->owed($tenantId),
        ];
    }

    /** Everything still owed to a shop: open credit tabs + the manual credit book. */
    public function owed(int $tenantId): float
    {
        $tabs = (float) $this->scalar(
            "SELECT COALESCE(SUM(GREATEST(total - COALESCE(amount_paid,0), 0)),0) FROM orders WHERE tenant_id = ? AND status = 'open'", [$tenantId]);
        return round($tabs + Models\ManualCreditModel::outstandingFor($this->db, $tenantId), 2);
    }

    public function stockValue(int $tenantId): float
    {
        return round((float) $this->scalar(
            "SELECT COALESCE(SUM(quantity * buying_price),0) FROM products WHERE tenant_id = ? AND status <> 'archived'", [$tenantId]), 2);
    }

    public function staffCount(int $tenantId): int
    {
        return (int) $this->scalar(
            "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE u.tenant_id = ? AND r.role_name = 'staff' AND u.is_active = 1", [$tenantId]);
    }

    /**
     * One row per shop plus a combined total, for the given range.
     * @param array $shops tenant rows (id, name, …)
     */
    public function compare(array $shops, string $from, string $to): array
    {
        $rows = [];
        $total = ['revenue' => 0.0, 'cost' => 0.0, 'gross' => 0.0, 'expenses' => 0.0, 'net' => 0.0, 'count' => 0, 'owed' => 0.0];
        foreach ($shops as $s) {
            $r = $this->forShop((int) $s['id'], $from, $to) + ['shop' => $s];
            $rows[] = $r;
            foreach ($total as $k => $_) { $total[$k] += $r[$k]; }
        }
        foreach ($total as $k => $v) { $total[$k] = is_float($v) ? round($v, 2) : $v; }
        return ['rows' => $rows, 'total' => $total];
    }

    private function row(string $sql, array $args): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetch() ?: [];
    }

    private function scalar(string $sql, array $args)
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchColumn();
    }
}
