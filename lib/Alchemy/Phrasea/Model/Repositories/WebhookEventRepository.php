<?php

/*
 * This file is part of Phraseanet
 *
 * (c) 2005-2014 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Alchemy\Phrasea\Model\Repositories;

use Alchemy\Phrasea\Model\Entities\WebhookEvent;
use Doctrine\ORM\EntityRepository;

/**
 * WebhookEventRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class WebhookEventRepository extends EntityRepository
{
    public function findUnprocessedEvents()
    {
        $qb = $this->createQueryBuilder('e');

        $qb->where($qb->expr()->eq('e.processed', $qb->expr()->literal(false)));

        return $qb->getQuery()->getResult();
    }
}
