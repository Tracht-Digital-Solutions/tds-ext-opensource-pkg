<?php
declare(strict_types=1);

namespace Tds\Ext\Opensource\Domain;

use PDO;

/**
 * Every query on `oss_donation`.
 *
 * Times come from the database clock on both sides of every comparison:
 * rows get `CURRENT_TIMESTAMP`, and a window's cutoff is computed from the
 * database's own `CURRENT_TIMESTAMP` ({@see cutoff}). The host's PHP and its
 * MySQL session do not share a zone, and mixing them shifts the window by
 * hours.
 */
final class DonationRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function open(int $projectId, string $sessionId, int $amount, string $currency, string $lang, ?string $ipHash): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO oss_donation (project_id, stripe_session_id, amount, currency, lang, status, ip_hash) '
            . "VALUES (:p, :s, :a, :c, :l, 'open', :h)"
        );
        $stmt->execute([':p' => $projectId, ':s' => $sessionId, ':a' => $amount, ':c' => $currency, ':l' => $lang, ':h' => $ipHash]);
    }

    /**
     * Applies a webhook outcome. Idempotent: Stripe retries, and `paid` is
     * final — an `expired` arriving late never undoes it.
     */
    public function settle(string $sessionId, string $status): bool
    {
        if ($status === 'paid') {
            $stmt = $this->pdo->prepare(
                "UPDATE oss_donation SET status = 'paid', paid_at = COALESCE(paid_at, CURRENT_TIMESTAMP) "
                . 'WHERE stripe_session_id = :s'
            );
        } else {
            $stmt = $this->pdo->prepare(
                "UPDATE oss_donation SET status = :st WHERE stripe_session_id = :s AND status <> 'paid'"
            );
            $stmt->bindValue(':st', $status);
        }
        $stmt->bindValue(':s', $sessionId);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    /** Checkout sessions one IP hash started in the last hour. */
    public function recentFromIp(string $ipHash): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM oss_donation WHERE ip_hash = :h AND created_at >= :cut'
        );
        $stmt->execute([':h' => $ipHash, ':cut' => $this->cutoff(3600)]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Paid totals per currency, overall and for the last 30 days.
     *
     * @return list<array{currency:string,count:int,total:int,last30:int}>
     */
    public function summary(?int $projectId = null): array
    {
        $where = "status = 'paid'" . ($projectId !== null ? ' AND project_id = :p' : '');
        $stmt = $this->pdo->prepare(
            'SELECT currency, COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS total, '
            . 'COALESCE(SUM(CASE WHEN paid_at >= :cut THEN amount ELSE 0 END), 0) AS last30 '
            . "FROM oss_donation WHERE {$where} GROUP BY currency ORDER BY currency"
        );
        $params = [':cut' => $this->cutoff(30 * 86400)];
        if ($projectId !== null) {
            $params[':p'] = $projectId;
        }
        $stmt->execute($params);
        return array_map(static fn (array $r): array => [
            'currency' => (string) $r['currency'],
            'count' => (int) $r['cnt'],
            'total' => (int) $r['total'],
            'last30' => (int) $r['last30'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * The newest donations for the panel list. No ip_hash, no session id.
     *
     * @return list<array<string,mixed>>
     */
    public function recent(int $limit, ?string $status): array
    {
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT d.id, p.slug AS project, d.amount, d.currency, d.lang, d.status, d.created_at, d.paid_at '
            . 'FROM oss_donation d LEFT JOIN oss_project p ON p.id = d.project_id '
            . ($status !== null ? 'WHERE d.status = :st ' : '')
            . "ORDER BY d.id DESC LIMIT {$limit}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($status !== null ? [':st' => $status] : []);
        return array_map(static function (array $r): array {
            $r['id'] = (int) $r['id'];
            $r['amount'] = (int) $r['amount'];
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * The database's "now" minus a window, as a DATETIME string. Read from the
     * database, so the comparison never mixes clocks; the subtraction happens
     * in PHP only because `INTERVAL` is not portable SQL.
     */
    private function cutoff(int $seconds): string
    {
        $now = (string) $this->pdo->query('SELECT CURRENT_TIMESTAMP')?->fetchColumn();
        $ts = strtotime($now);
        return date('Y-m-d H:i:s', ($ts === false ? time() : $ts) - $seconds);
    }
}
