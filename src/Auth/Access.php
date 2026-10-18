<?php
namespace App\Auth;

use App\Models\User;
use PDO;

/**
 * Video access rules (docs/auth-plan.md, "Access rules"):
 * - public: everyone can watch; listed for everyone
 * - unlisted: everyone with the link can watch; listed with collection access, and on the page
 *   of another unlisted video of the same collection (sharing an unlisted collection by link)
 * - private: only with collection access
 * Collection access: admin group, or a group granted the collection (group_collections).
 */
class Access
{
    /** @var int[]|null collection ids with full access; null = all (admin) */
    private ?array $granted = null;
    private bool $resolved = false;

    public function __construct(private PDO $db, private ?User $user)
    {
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
     * $unlistedCollection: also list the unlisted videos of this collection (used on the page of
     * an unlisted video, so that a shared link opens up its collection; nothing is remembered).
     * @return array{0: string, 1: array} [condition on $alias, positional parameters]
     */
    public function listableCondition(string $alias = 'v', ?int $unlistedCollection = null): array
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
        if ($unlistedCollection !== null && !in_array($unlistedCollection, $granted, true)) {
            $conditions[] = "($alias.visibility = 'unlisted' AND $alias.collection_id = ?)";
            $params[] = $unlistedCollection;
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
