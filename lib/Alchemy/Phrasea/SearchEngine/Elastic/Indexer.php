<?php

/*
 * This file is part of Phraseanet
 *
 * (c) 2005-2014 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Alchemy\Phrasea\SearchEngine\Elastic;

use Alchemy\Phrasea\Model\RecordInterface;
use Alchemy\Phrasea\SearchEngine\Elastic\Indexer\BulkOperation;
use Alchemy\Phrasea\SearchEngine\Elastic\Indexer\RecordIndexer;
use Alchemy\Phrasea\SearchEngine\Elastic\Indexer\TermIndexer;
use Alchemy\Phrasea\SearchEngine\Elastic\Indexer\RecordQueuer;
use appbox;
use Closure;
use Elasticsearch\Client;
use Psr\Log\LoggerInterface;
use igorw;
use Psr\Log\NullLogger;
use record_adapter;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Stopwatch\Stopwatch;
use SplObjectStorage;
use Symfony\Component\Validator\Constraints\DateTime;


class Indexer
{
    const THESAURUS = 1;
    const RECORDS   = 2;

    const WITH_ALIAS = true;
    const WITHOUT_ALIAS = false;

    /**
     * @var \Elasticsearch\Client
     */
    private $client;

    /**
     * @var LoggerInterface|null
     */
    private $logger;

    /**
     * @var SplObjectStorage|RecordInterface[]
     */
    private $indexQueue;

    /**
     * @var SplObjectStorage|RecordInterface[]
     */
    private $deleteQueue;

    /**
     * @var RecordIndexer
     */
    private $recordIndexer;

    /**
     * @var TermIndexer
     */
    private $termIndexer;

    /**
     * @var Index
     */
    private $index;

    public function __construct(
        Client $client,
        Index $index,
        TermIndexer $termIndexer,
        RecordIndexer $recordIndexer,
        LoggerInterface $logger = null
    )
    {
        $this->client = $client;
        $this->index = $index;
        $this->recordIndexer = $recordIndexer;
        $this->termIndexer = $termIndexer;
        $this->logger = $logger ?: new NullLogger();

        $this->indexQueue = new SplObjectStorage();
        $this->deleteQueue = new SplObjectStorage();
    }

    /**
     * @return Index
     */
    public function getIndex()
    {
        return $this->index;
    }

    public function createIndex($indexName = null)
    {
        $aliasName = $this->index->getName();
        if($indexName === null) {
            $indexName = $aliasName;
        }

        $now = sprintf("%s.%06d", Date('YmdHis'), 1000000*explode(' ', microtime())[0]) ;
        $indexName .=  ('_' . $now);

        $params = [
            'index' => $indexName,
            'body'  => [
                'settings' => [
                    'number_of_shards'   => $this->index->getOptions()->getShards(),
                    'number_of_replicas' => $this->index->getOptions()->getReplicas(),
                    'analysis'           => $this->index->getAnalysis()
                ],
                'mappings' => [
                    RecordIndexer::TYPE_NAME => $this->index->getRecordIndex()->getMapping()->export(),
                    TermIndexer::TYPE_NAME   => $this->index->getTermIndex()->getMapping()->export()
                ]
                //    ,
                //    'aliases' => [
                //        $aliasName => []
                //    ]
            ]
        ];

        $this->client->indices()->create($params);

        $params = [
            'body' => [
                'actions' => [
                    [
                        'add' => [
                            'index' => $indexName,
                            'alias' => $aliasName
                        ]
                    ]
                ]
            ]
        ];

        $this->client->indices()->updateAliases($params);

        return [
            'index' => $indexName,
            'alias' => $aliasName
        ];
    }

    public function updateMapping()
    {
        $params = [];
        $params['index'] = $this->index->getName();
        $params['type'] = RecordIndexer::TYPE_NAME;
        $params['body'][RecordIndexer::TYPE_NAME] = $this->index->getRecordIndex()->getMapping()->export();
        $params['body'][TermIndexer::TYPE_NAME]   = $this->index->getTermIndex()->getMapping()->export();

        // @todo This must throw a new indexation if a mapping is edited
        $this->client->indices()->putMapping($params);
    }

    public function deleteIndex()
    {
        $this->client->indices()->delete([
            'index' => $this->index->getName()
        ]);
    }

    public function indexExists()
    {
        return $this->client->indices()->exists([
            'index' => $this->index->getName()
        ]);
    }

    public function replaceIndex($newIndexName, $newAliasName)
    {
        $oldIndexes = $this->client->indices()->getAlias(
            [
                'index' => $this->index->getName()
            ]
        );

        // delete old alias(es), only one alias on one index should exist
        foreach($oldIndexes as $oldIndexName => $data) {
            foreach($data['aliases'] as $oldAliasName => $data2) {
                $params['body']['actions'][] = [
                    'remove' => [
                        'index' => $oldIndexName,
                        'alias' => $oldAliasName
                    ]
                ];
            }
        }
        //
        $params['body']['actions'][] = [
            'remove' => [
                'index' => $newIndexName,
                'alias' => $newAliasName
            ]
        ];
        // create new alias
        $params['body']['actions'][] = [
            'add' => [
                'index' => $newIndexName,
                'alias' => $this->index->getName()
            ]
        ];

        $this->client->indices()->updateAliases($params);

        // delete old index(es), only one index should exist
        $params = [
            'index' => []
        ];
        foreach($oldIndexes as $oldIndexName => $data) {
            $params['index'][] = $oldIndexName;
        }
        $this->client->indices()->delete(
            $params
        );
    }

    public function populateIndex($what, \databox $databox)
    {
        $stopwatch = new Stopwatch();
        $stopwatch->start('populate');

        $this->apply(
            function (BulkOperation $bulk) use ($what, $databox) {
                if ($what & self::THESAURUS) {
                    $this->termIndexer->populateIndex($bulk, $databox);

                    // Record indexing depends on indexed terms so we need to make
                    // everything ready to search
                    $bulk->flush();
                    $this->client->indices()->refresh();
                }

                if ($what & self::RECORDS) {
                    $databox->clearCandidates();
                    $this->recordIndexer->populateIndex($bulk, $databox);

                    // Final flush
                    $bulk->flush();
                }
            },
            $this->index
        );

        // Optimize index
        $this->client->indices()->optimize(
            [
                'index' => $this->index->getName()
            ]
        );

        $event = $stopwatch->stop('populate');

        return [
            'duration' => $event->getDuration(),
            'memory'   => $event->getMemory()
        ];
    }

    public function migrateMappingForDatabox($databox)
    {
        // TODO Migrate mapping
        // - Create a new index
        // - Dump records using scroll API
        // - Insert them in created index (except those in the changed databox)
        // - Reindex databox's records from DB
        // - Make alias point to new index
        // - Delete old index

        // $this->updateMapping();
        // RecordQueuer::queueRecordsFromDatabox($databox);
    }

    public function scheduleRecordsFromDataboxForIndexing(\databox $databox)
    {
        RecordQueuer::queueRecordsFromDatabox($databox);
    }

    public function scheduleRecordsFromCollectionForIndexing(\collection $collection)
    {
        RecordQueuer::queueRecordsFromCollection($collection);
    }

    public function indexRecord(RecordInterface $record)
    {
        $this->indexQueue->attach($record);
    }

    public function deleteRecord(RecordInterface $record)
    {
        $this->deleteQueue->attach($record);
    }

    /**
     * @param \databox $databox    databox to index
     * @throws \Exception
     */
    public function indexScheduledRecords(\databox $databox)
    {
        $this->apply(
            function (BulkOperation $bulk) use ($databox) {
                $this->recordIndexer->indexScheduled($bulk, $databox);
            },
            $this->index
        );
    }

    public function flushQueue()
    {
        // Do not reindex records modified then deleted in the request
        $this->indexQueue->removeAll($this->deleteQueue);

        // Skip if nothing to do
        if (count($this->indexQueue) === 0 && count($this->deleteQueue) === 0) {
            return;
        }

        $this->apply(
            function (BulkOperation $bulk) {
                $this->recordIndexer->index($bulk, $this->indexQueue);
                $this->recordIndexer->delete($bulk, $this->deleteQueue);
                $bulk->flush();
            },
            $this->index
        );

        $this->indexQueue = new SplObjectStorage();
        $this->deleteQueue = new SplObjectStorage();
    }

    private function apply(Closure $work, Index $index)
    {
        // Prepare the bulk operation
        $bulk = new BulkOperation($this->client, $this->logger);
        $bulk->setDefaultIndex($index->getName());
        $bulk->setAutoFlushLimit(1000);

        // Do the work
        $work($bulk, $index);

        // Flush just in case, it's a noop when already done
        $bulk->flush();
    }
}
