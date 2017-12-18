<?php

namespace AppBundle\Repository;

use Datetime;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;

/**
 * AssignmentRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class AssignmentRepository extends EntityRepository
{

    /**
     * @param $userId
     * @param $finished
     * @param $individual
     * @return array
     */
    public function findAssignmentsByUser($userId, $finished, $individual)
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = $this->createQueryBuilder('a');
        $queryBuilder
            ->select('a')
            ->innerJoin('a.evaluation', 'e')
            ->andWhere('e.activeAssignment = true');
        if ($individual) {
            $queryBuilder
                ->innerJoin('a.user', 'u')
                ->andWhere('u.id = :id')
                ->andWhere('a.group is null');
        } else {
            $queryBuilder
                ->innerJoin('a.group', 'g')
                ->innerJoin('g.users', 'u', 'WITH', 'u.id = :id')
                ->andWhere('a.user is null');
        }
        if ($finished) {
            $queryBuilder->andWhere(':now > e.dateEndAssignment');
        } else {
            $queryBuilder->andWhere(':now BETWEEN e.dateStartAssignment AND e.dateEndAssignment');
        }
        $params = array();
        $params['id'] = $userId;
        $params['now'] = new Datetime('Europe/Paris');
        $queryBuilder->orderBy('a.dateSubmission', 'ASC');
        $queryBuilder->setParameters($params);
        $query = $queryBuilder->getQuery();
        $results = $query->getResult();
        return $results;
    }

    public function findByUserAndLesson($userId, $lessonId, $individual)
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = $this->createQueryBuilder('a');
        $queryBuilder
            ->select('a')
            ->innerJoin('a.evaluation', 'e')
            ->andWhere(':now BETWEEN e.dateStartAssignment AND e.dateEndAssignment')
            ->andWhere('e.lesson = :lessonId')
            ->andWhere('e.activeAssignment = true');
        if ($individual) {
            $queryBuilder
                ->innerJoin('a.user', 'u')
                ->andWhere('u.id = :userId')
                ->andWhere('a.group is null');
        } else {
            $queryBuilder
                ->innerJoin('a.group', 'g')
                ->innerJoin('g.users', 'u', 'WITH', 'u.id = :userId')
                ->andWhere('a.user is null');
        }
        $params = array();
        $params['userId'] = $userId;
        $params['lessonId'] = $lessonId;
        $params['now'] = new Datetime();
        $queryBuilder->orderBy('a.dateSubmission', 'ASC');
        $queryBuilder->setParameters($params);
        $query = $queryBuilder->getQuery();
        $results = $query->getResult();
        return $results;
    }
}