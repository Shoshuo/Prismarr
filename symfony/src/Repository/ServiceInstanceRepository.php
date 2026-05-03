<?php

namespace App\Repository;

use App\Entity\ServiceInstance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServiceInstance>
 */
class ServiceInstanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceInstance::class);
    }

    /**
     * Every instance of $type, ordered by user-chosen position then by id.
     *
     * @return list<ServiceInstance>
     */
    public function findByType(string $type): array
    {
        /** @var list<ServiceInstance> */
        return $this->createQueryBuilder('s')
            ->andWhere('s.type = :type')
            ->setParameter('type', $type)
            ->orderBy('s.position', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Like findByType() but skips instances the user has toggled off.
     * Used by the sidebar, the dashboard widgets and HealthService probes.
     *
     * @return list<ServiceInstance>
     */
    public function findEnabledByType(string $type): array
    {
        /** @var list<ServiceInstance> */
        return $this->createQueryBuilder('s')
            ->andWhere('s.type = :type')
            ->andWhere('s.enabled = true')
            ->setParameter('type', $type)
            ->orderBy('s.position', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns the default instance for $type, or null if none flagged.
     * Used by call sites that don't carry an explicit instance slug
     * (legacy routes, quick-add fallback, dashboard hero, …).
     */
    public function findDefaultForType(string $type): ?ServiceInstance
    {
        /** @var ServiceInstance|null */
        return $this->createQueryBuilder('s')
            ->andWhere('s.type = :type')
            ->andWhere('s.isDefault = true')
            ->setParameter('type', $type)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneBySlug(string $type, string $slug): ?ServiceInstance
    {
        return $this->findOneBy(['type' => $type, 'slug' => $slug]);
    }

    public function countByType(string $type): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.type = :type')
            ->setParameter('type', $type)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function save(ServiceInstance $instance, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($instance);
        if ($flush) {
            $em->flush();
        }
    }

    public function remove(ServiceInstance $instance, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->remove($instance);
        if ($flush) {
            $em->flush();
        }
    }

    /**
     * Returns the smallest unused integer suffix for an auto-generated slug
     * of the given type. Used at instance creation: if "radarr-1" exists,
     * picks "radarr-2", and so on. Caller is responsible for combining with
     * the type prefix.
     */
    public function nextSlugSuffix(string $type): int
    {
        $existing = $this->createQueryBuilder('s')
            ->select('s.slug')
            ->andWhere('s.type = :type')
            ->setParameter('type', $type)
            ->getQuery()
            ->getArrayResult();

        $taken = [];
        $prefix = $type . '-';
        foreach ($existing as $row) {
            $slug = (string) ($row['slug'] ?? '');
            if (str_starts_with($slug, $prefix)) {
                $suffix = substr($slug, strlen($prefix));
                if (ctype_digit($suffix)) {
                    $taken[(int) $suffix] = true;
                }
            }
        }

        $n = 1;
        while (isset($taken[$n])) {
            $n++;
        }
        return $n;
    }
}
