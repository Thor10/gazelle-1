<?php

namespace Gazelle\Manager;

class TGroup extends \Gazelle\BaseManager {
    protected const ID_KEY = 'zz_tg_%d';

    const CACHE_KEY_FEATURED = 'featured_%d';

    const FEATURED_AOTM     = 0;
    const FEATURED_SHOWCASE = 1;

    protected \Gazelle\User $viewer;

    /**
     * Set the viewer context, for snatched indicators etc.
     * If this is set, and Torrent object created will have it set
     */
    public function setViewer(\Gazelle\User $viewer) {
        $this->viewer = $viewer;
        return $this;
    }

    public function findById(int $tgroupId): ?\Gazelle\TGroup {
        $key = sprintf(self::ID_KEY, $tgroupId);
        $id = self::$cache->get_value($key);
        if ($id === false) {
            $id = self::$db->scalar("
                SELECT ID FROM torrents_group WHERE ID = ?
                ", $tgroupId
            );
            if (!is_null($id)) {
                self::$cache->cache_value($key, $id, 7200);
            }
        }
        if (!$id) {
            return null;
        }
        $tgroup = new \Gazelle\TGroup($id);
        if (isset($this->viewer)) {
            $tgroup->setViewer($this->viewer);
        }
        return $tgroup;
    }

    /**
     * Map a torrenthash to a group id
     */
    public function findByTorrentInfohash(string $hash): ?\Gazelle\TGroup {
        $id = self::$db->scalar("
            SELECT GroupID FROM torrents WHERE info_hash = UNHEX(?)
            ", $hash
        );
        return $id ? new \Gazelle\TGroup($id) : null;
    }

    public function findRandom(): ?\Gazelle\TGroup {
        return $this->findById(
            (int)self::$db->scalar("
                SELECT r1.ID
                FROM torrents_group AS r1
                INNER JOIN torrents t ON (r1.ID = t.GroupID)
                INNER JOIN torrents_leech_stats tls ON (tls.TorrentID = t.ID AND tls.Seeders >= ?),
                (SELECT rand() * max(ID) AS ID FROM torrents_group) AS r2
                WHERE r1.ID >= r2.ID
                LIMIT 1
                ", RANDOM_TORRENT_MIN_SEEDS
            )
        );
    }

    public function merge(\Gazelle\TGroup $old, \Gazelle\TGroup $new, \Gazelle\User $user, \Gazelle\Log $log): bool {
        // Votes ninjutsu. This is so annoyingly complicated.
        // 1. Get a list of everybody who voted on the old group and clear their cache keys
        self::$db->prepared_query("
            SELECT concat('voted_albums_', UserID)
            FROM users_votes
            WHERE GroupID = ?
            ", $old->id()
        );
        self::$cache->deleteMulti(self::$db->collect(0, false));

        self::$db->begin_transaction();

        // 2. Update the existing votes where possible, clear out the duplicates left by key
        // conflicts, and update the torrents_votes table
        self::$db->prepared_query("
            UPDATE IGNORE users_votes SET
                GroupID = ?
            WHERE GroupID = ?
            ", $new->id(), $old->id()
        );
        self::$db->prepared_query("
            DELETE FROM users_votes WHERE GroupID = ?
            ", $old->id()
        );
        self::$db->prepared_query("
            INSERT INTO torrents_votes (GroupId, Ups, Total, Score)
            SELECT                      ?,       Ups, Total, 0
            FROM (
                SELECT
                    ifnull(sum(if(Type = 'Up', 1, 0)), 0) As Ups,
                    count(*) AS Total
                FROM users_votes
                WHERE GroupID = ?
                GROUP BY GroupID
            ) AS a
            ON DUPLICATE KEY UPDATE
                Ups = a.Ups,
                Total = a.Total
            ", $new->id(), $old->id()
        );
        if (self::$db->affected_rows()) {
            // recompute score
            self::$db->prepared_query("
                UPDATE torrents_votes SET
                    Score = IFNULL(binomial_ci(Ups, Total), 0)
                WHERE GroupID = ?
                ", $new->id()
            );
        }

        // 3. Clear the votes_pairs keys
        self::$db->prepared_query("
            SELECT concat('vote_pairs_', v2.GroupId)
            FROM users_votes AS v1
            INNER JOIN users_votes AS v2 USING (UserID)
            WHERE (v1.Type = 'Up' OR v2.Type = 'Up')
                AND (v1.GroupId     IN (?, ?))
                AND (v2.GroupId NOT IN (?, ?))
            ", $old->id(), $new->id(), $old->id(), $new->id()
        );
        self::$cache->deleteMulti(self::$db->collect(0, false));

        // GroupIDs
        self::$db->prepared_query("SELECT ID FROM torrents WHERE GroupID = ?", $old->id());
        $cacheKeys = [];
        while ([$TorrentID] = self::$db->next_row()) {
            $cacheKeys[] = 'torrent_download_' . $TorrentID;
            $cacheKeys[] = 'tid_to_group_' . $TorrentID;
        }
        self::$cache->deleteMulti($cacheKeys);
        unset($cacheKeys);

        self::$db->prepared_query("
            UPDATE torrents SET
                GroupID = ?
            WHERE GroupID = ?
            ", $new->id(), $old->id()
        );
        self::$db->prepared_query("
            UPDATE wiki_torrents SET
                PageID = ?
            WHERE PageID = ?
            ", $new->id(), $old->id()
        );

        (new \Gazelle\Manager\Bookmark)->merge($old->id(), $new->id());
        (new \Gazelle\Manager\Comment)->merge('torrents', $old->id(), $new->id());

        // Collages
        self::$db->prepared_query("
            SELECT CollageID FROM collages_torrents WHERE GroupID = ?
            ", $old->id()
        );
        $collageList = self::$db->collect(0, false);
        self::$db->prepared_query("
            UPDATE IGNORE collages_torrents SET
                GroupID = ?
            WHERE GroupID = ?
            ", $new->id(), $old->id()
        );
        self::$db->prepared_query("
            DELETE FROM collages_torrents WHERE GroupID = ?
                ", $old->id()
        );
        self::$cache->deleteMulti(array_map(
            fn ($id) => sprintf(\Gazelle\Collage::CACHE_KEY, $id), $collageList
        ));

        // Requests
        self::$db->prepared_query("
            SELECT concat('request_', ID) FROM requests WHERE GroupID = ?
            ", $old->id()
        );
        self::$cache->deleteMulti(self::$db->collect(0, false));
        self::$db->prepared_query("
            UPDATE requests SET
                GroupID = ?
            WHERE GroupID = ?
            ", $new->id(), $old->id()
        );

        self::$db->prepared_query("
            UPDATE group_log SET
                GroupID = ?
            WHERE GroupID = ?
            ", $new->id(), $old->id()
        );

        $old->remove($user);
        self::$db->commit();

        $new->refresh();

        self::$cache->deleteMulti([
            "requests_group_" . $new->id(),
            "torrent_collages_" . $new->id(),
            "torrent_collages_personal_" . $new->id(),
            "votes_" . $new->id(),
        ]);
        return true;
    }

    protected function featuredAlbum(int $type): array {
        $key = sprintf(self::CACHE_KEY_FEATURED, $type);
        if (($featured = self::$cache->get_value($key)) === false) {
            $featured = self::$db->rowAssoc("
                SELECT fa.GroupID,
                    tg.Name,
                    tg.WikiImage,
                    fa.ThreadID,
                    fa.Title
                FROM featured_albums AS fa
                INNER JOIN torrents_group AS tg ON (tg.ID = fa.GroupID)
                WHERE Ended IS NULL AND type = ?
                ", $type
            );
            if (!is_null($featured)) {
                global $Viewer; // FIXME this wrong
                $featured['artist_name'] = \Artists::display_artists(\Artists::get_artist($featured['GroupID']), false, false);
                $featured['image']       = (new \Gazelle\Util\ImageProxy($Viewer))->process($featured['WikiImage']);
            }
            self::$cache->cache_value($key, $featured, 86400 * 7);
        }
        return $featured ?? [];
    }

    public function featuredAlbumAotm(): array {
        return $this->featuredAlbum(self::FEATURED_AOTM);
    }

    public function featuredAlbumShowcase(): array {
        return $this->featuredAlbum(self::FEATURED_SHOWCASE);
    }

    public function groupLog(int $groupId): array {
        self::$db->prepared_query("
            SELECT gl.TorrentID        AS torrent_id,
                gl.UserID              AS user_id,
                gl.Info                AS info,
                gl.Time                AS created,
                t.Media                AS media,
                t.Format               AS format,
                t.Encoding             AS encoding,
                if(t.ID IS NULL, 1, 0) AS deleted
            FROM group_log gl
            LEFT JOIN torrents t ON (t.ID = gl.TorrentID)
            WHERE gl.GroupID = ?
            ORDER BY gl.Time DESC
            ", $groupId
        );
        return self::$db->to_array(false, MYSQLI_ASSOC, false);
    }
}
