<?php

declare(strict_types=1);

namespace XC\MCP\Model\Repo;

class McpChat extends \XLite\Model\Repo\ARepo
{
    /**
     * @return list<\XC\MCP\Model\McpChat>
     */
    public function findForProfile(int $profileId, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('c')
            ->where('c.profile_id = :pid')
            ->setParameter('pid', $profileId)
            ->orderBy('c.updated_at', 'DESC')
            ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }
}
