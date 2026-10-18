<?php
namespace App\Auth;

use App\Models\User;
use PDO;

/**
 * Video access rules (docs/auth-plan.md, "Access rules"):
 * - public: everyone can watch; listed for everyone
 * - unlisted: everyone with the link can watch; listed with collection access, or once the
 *   viewer has opened an unlisted link of that collection ("link collections", kept in the session)
 * - private: only with collection access
 * Collection access: admin group, or a group granted the collection (group_collections).
 */
class Access
{
    /** @var int[]|null collection ids with full access; null = all (admin) */
    private ?array $granted = null;
    private bool $resolved = false;

    /** @param int[] $linkCollections collections whose unlisted videos this viewer reached by link */
    public function __construct(private PDO $db, private ?User $user, private array $linkCollections = [])
    {
    }

    public function addLinkCollection(int $collectionId): void
    {
        if (!in_array($collectionId, $this->linkCollections, true)) {
            $this->linkCollections[] = $collectionId;
        }
    }

    public function canView(array $video): bool
    {
        return $video['visibility'] !== 'private' || $this->hasCollectionAccess((int) $video['collection_id']);
    }

    public function hasCollectionAccess(int $collectionId): bool
    {
        $granted = $this->grantedCollections();
        return $granted === null || in_array($collectionId, $granted, true);
    }

    /**
     * SQL condition selecting the videos this viewer may see in listings.
     * @return array{0: string, 1: array} [condition on $alias, positional parameters]
     */
    public function listableCondition(string $alias = 'v'): array
    {
        $granted = $this->grantedCollections();
        if ($granted === null) {
            return ['1 = 1', []];
        }
        $conditions = ["$alias.visibility = 'public'"];
        $params = [];
        if ($granted) {
            $conditions[] = "$alias.collection_id IN (" . self::placeholders($granted) . ')';
            $params = array_merge($params, $granted);
        }
        $linked = array_values(array_diff($this->linkCollections, $granted));
        if ($linked) {
            $conditions[] = "($alias.visibility = 'unlisted' AND $alias.collection_id IN (" . self::placeholders($linked) . '))';
            $params = array_merge($params, $linked);
        }
        return ['(' . implode(' OR ', $conditions) . ')', $params];
    }

    private static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    /** @return int[]|null */
    private function grantedCollections(): ?array
    {
        if ($this->resolved) {
            return $this->granted;
        }
        $this->resolved = true;
        if ($this->user === null || $this->user->groups === []) {
            return $this->granted = [];
        }
        if ($this->user->isAdmin()) {
            return $this->granted = null;
        }
        $groupIds = array_keys($this->user->groups);
        $placeholders = implode(', ', array_fill(0, count($groupIds), '?'));
        $sth = $this->db->prepare("SELECT DISTINCT collection_id FROM group_collections WHERE group_id IN ($placeholders)");
        $sth->execute($groupIds);
        return $this->granted = array_map('intval', $sth->fetchAll(PDO::FETCH_COLUMN));
    }
}
