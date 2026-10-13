<?php
// app/models/CatalogueFeedbackModel.php
// Likes and comments left by visitors on the public, read-only catalogue
// (public/catalogue.php). Visitors aren't logged in, so every method takes
// the tenant id explicitly instead of reading TenantContext. A visitor is
// recognised by a random cookie token, which keeps "like" a toggle.
namespace Models;

class CatalogueFeedbackModel
{
    private \PDO $db;

    public function __construct(?\PDO $db = null)
    {
        $this->db = $db ?? \Database::pdo();
        $this->ensureSchema();
    }

    /** Like counts per product, plus which ones this visitor liked. */
    public function likeSummary(int $tenantId, string $visitor): array
    {
        $stmt = $this->db->prepare(
            "SELECT product_id, COUNT(*) AS n, MAX(visitor_token = ?) AS mine
               FROM catalogue_feedback
              WHERE tenant_id = ? AND kind = 'like'
           GROUP BY product_id"
        );
        $stmt->execute([$visitor, $tenantId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['product_id']] = ['likes' => (int) $r['n'], 'mine' => (bool) $r['mine']];
        }
        return $out;
    }

    /** Visible comment counts per product. */
    public function commentCounts(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            "SELECT product_id, COUNT(*) AS n FROM catalogue_feedback
              WHERE tenant_id = ? AND kind = 'comment' AND is_hidden = 0 GROUP BY product_id"
        );
        $stmt->execute([$tenantId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) { $out[(int) $r['product_id']] = (int) $r['n']; }
        return $out;
    }

    public function comments(int $tenantId, int $productId, int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, name, body, created_at FROM catalogue_feedback
              WHERE tenant_id = ? AND product_id = ? AND kind = 'comment' AND is_hidden = 0
           ORDER BY created_at DESC, id DESC LIMIT " . (int) $limit
        );
        $stmt->execute([$tenantId, $productId]);
        return $stmt->fetchAll();
    }

    /** Toggle this visitor's like. Returns the new state and count. */
    public function toggleLike(int $tenantId, int $productId, string $visitor): array
    {
        $del = $this->db->prepare("DELETE FROM catalogue_feedback WHERE tenant_id = ? AND product_id = ? AND kind = 'like' AND visitor_token = ?");
        $del->execute([$tenantId, $productId, $visitor]);
        $liked = false;
        if ($del->rowCount() === 0) {
            $this->db->prepare("INSERT INTO catalogue_feedback (tenant_id, product_id, kind, visitor_token) VALUES (?,?,'like',?)")
                ->execute([$tenantId, $productId, $visitor]);
            $liked = true;
        }
        $cnt = $this->db->prepare("SELECT COUNT(*) FROM catalogue_feedback WHERE tenant_id = ? AND product_id = ? AND kind = 'like'");
        $cnt->execute([$tenantId, $productId]);
        return ['liked' => $liked, 'likes' => (int) $cnt->fetchColumn()];
    }

    public function addComment(int $tenantId, int $productId, string $visitor, string $name, string $body): array
    {
        $name = mb_substr(trim(strip_tags($name)), 0, 60);
        $body = mb_substr(trim(strip_tags($body)), 0, 500);
        if ($body === '') {
            return ['ok' => false, 'error' => 'Write a comment first.'];
        }
        // Light flood control: at most 5 comments a visitor per 10 minutes.
        $recent = $this->db->prepare("SELECT COUNT(*) FROM catalogue_feedback WHERE tenant_id = ? AND visitor_token = ? AND kind = 'comment' AND created_at > (NOW() - INTERVAL 10 MINUTE)");
        $recent->execute([$tenantId, $visitor]);
        if ((int) $recent->fetchColumn() >= 5) {
            return ['ok' => false, 'error' => 'You\'re commenting a lot — please wait a few minutes.'];
        }
        $this->db->prepare("INSERT INTO catalogue_feedback (tenant_id, product_id, kind, visitor_token, name, body) VALUES (?,?,'comment',?,?,?)")
            ->execute([$tenantId, $productId, $visitor, $name !== '' ? $name : null, $body]);
        return ['ok' => true, 'error' => null];
    }

    /** Owner view: newest comments across the catalogue, hidden ones included. */
    public function recentForOwner(int $tenantId, int $limit = 100): array
    {
        $stmt = $this->db->prepare(
            "SELECT f.*, p.name AS product_name FROM catalogue_feedback f
          LEFT JOIN products p ON p.id = f.product_id AND p.tenant_id = f.tenant_id
              WHERE f.tenant_id = ? AND f.kind = 'comment'
           ORDER BY f.created_at DESC, f.id DESC LIMIT " . (int) $limit
        );
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll();
    }

    /** Most-liked products, for the owner's catalogue page. */
    public function topLiked(int $tenantId, int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            "SELECT p.id, p.name, COUNT(*) AS likes FROM catalogue_feedback f
               JOIN products p ON p.id = f.product_id AND p.tenant_id = f.tenant_id
              WHERE f.tenant_id = ? AND f.kind = 'like'
           GROUP BY p.id, p.name ORDER BY likes DESC LIMIT " . (int) $limit
        );
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll();
    }

    public function setHidden(int $tenantId, int $id, bool $hidden): void
    {
        $this->db->prepare("UPDATE catalogue_feedback SET is_hidden = ? WHERE id = ? AND tenant_id = ?")->execute([$hidden ? 1 : 0, $id, $tenantId]);
    }

    public function delete(int $tenantId, int $id): void
    {
        $this->db->prepare("DELETE FROM catalogue_feedback WHERE id = ? AND tenant_id = ?")->execute([$id, $tenantId]);
    }

    private function ensureSchema(): void
    {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS catalogue_feedback (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT NOT NULL,
                product_id INT NOT NULL,
                kind ENUM('like','comment') NOT NULL,
                visitor_token CHAR(32) NOT NULL,
                name VARCHAR(60) NULL,
                body VARCHAR(500) NULL,
                is_hidden TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_catfb_product (tenant_id, product_id, kind),
                KEY idx_catfb_visitor (tenant_id, visitor_token)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\PDOException $ignored) {
        }
    }
}
