<?php

namespace App\Repository\Media;

use App\Entity\Media\WatchlistItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WatchlistItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WatchlistItem::class);
    }

    public function findByTmdb(int $tmdbId, string $mediaType): ?WatchlistItem
    {
        return $this->findOneBy(['tmdbId' => $tmdbId, 'mediaType' => $mediaType]);
    }

    /** @return WatchlistItem[] */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('w')
            ->orderBy('w.addedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** Returns the watchlist tmdbIds as a set: ['movie_123' => true, 'tv_456' => true] */
    public function getWatchlistIndex(): array
    {
        $items = $this->createQueryBuilder('w')
            ->select('w.tmdbId, w.mediaType')
            ->getQuery()
            ->getArrayResult();

        $index = [];
        foreach ($items as $row) {
            $index[$row['mediaType'] . '_' . $row['tmdbId']] = true;
        }
        return $index;
    }
}
