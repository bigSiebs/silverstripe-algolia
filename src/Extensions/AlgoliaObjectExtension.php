<?php

namespace Wilr\SilverStripe\Algolia\Extensions;

use Exception;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Wilr\Silverstripe\Algolia\Jobs\AlgoliaDeleteItemJob;
use Wilr\Silverstripe\Algolia\Jobs\AlgoliaIndexItemJob;
use Wilr\SilverStripe\Algolia\Service\AlgoliaIndexer;
use Wilr\SilverStripe\Algolia\Service\AlgoliaService;

class AlgoliaObjectExtension extends DataExtension
{
    use Configurable;

    /**
     *
     */
    private static $enable_indexer = true;

    /**
     *
     */
    private static $use_queued_indexing = false;

    private static $db = [
        'AlgoliaIndexed' => 'Datetime'
    ];

    /**
     * @return bool
     */
    public function indexEnabled(): bool
    {
        return $this->config('enable_indexer') ? true : false;
    }

    /**
     * @param FieldList
     */
    public function updateCMSFields(FieldList $fields)
    {
        if ($this->owner->indexEnabled()) {
            $fields->addFieldsToTab('Root.Main', [
                ReadonlyField::create('AlgoliaIndexed', _t(__CLASS__.'.LastIndexed', 'Last indexed in Algolia'))
            ]);
        }
    }

    /**
     * On dev/build ensure that the indexer settings are up to date.
     */
    public function requireDefaultRecords()
    {
        $algolia = Injector::inst()->create(AlgoliaService::class);
        $algolia->syncSettings();
    }

    /**
     * Returns whether this object should be indexed into Algolia.
     */
    public function canIndexInAlgolia(): bool
    {
        if ($this->owner->hasField('ShowInSearch')) {
            return $this->owner->ShowInSearch;
        }

        return true;
    }

    /**
     * When publishing the page, push this data to Algolia Indexer. The data
     * which is sent to Algolia is the rendered template from the front end.
     */
    public function onAfterPublish()
    {
        if (min($this->owner->invokeWithExtensions('canIndexInAlgolia')) == false) {
            $this->owner->removeFromAlgolia();
        } else {
            $this->owner->indexInAlgolia();
        }
    }

    public function markAsRemovedFromAlgoliaIndex()
    {
        $this->touchAlgoliaIndexedDate(true);
    }

    /**
     * Update the AlgoliaIndexed date for this object.
     */
    public function touchAlgoliaIndexedDate($isDeleted = false)
    {
        $schema = DataObject::getSchema();
        $table = $schema->tableForField($this->owner->ClassName, 'AlgoliaIndexed');

        if ($table) {
            $newValue = $isDeleted ? 'null' : 'NOW()';
            DB::query(sprintf("UPDATE %s SET AlgoliaIndexed = $newValue WHERE ID = %s", $table, $this->owner->ID));

            if ($this->owner->hasExtension('SilverStripe\Versioned\Versioned')) {
                DB::query(
                    sprintf("UPDATE %s_Live SET AlgoliaIndexed = $newValue WHERE ID = %s", $table, $this->owner->ID)
                );
            }
        }
    }

    /**
     * Index this record into Algolia or queue if configured to do so
     *
     * @return bool
     */
    public function indexInAlgolia(): bool
    {
        if ($this->owner->indexEnabled() && min($this->owner->invokeWithExtensions('canIndexInAlgolia')) == false) {
            return false;
        }

        if ($this->config()->get('use_queued_indexing')) {
            $indexJob = new AlgoliaIndexItemJob(get_class($this->owner), $this->owner->ID);
            QueuedJobService::singleton()->queueJob($indexJob);

            return true;
        } else {
            return $this->doImmediateIndexInAlgolia();
        }
    }

    /**
     * Index this record into Algolia
     *
     * @return bool
     */
    public function doImmediateIndexInAlgolia()
    {
        $indexer = Injector::inst()->get(AlgoliaIndexer::class);

        try {
            $indexer->indexItem($this->owner);

            $this->touchAlgoliaIndexedDate();

            return true;
        } catch (Exception $e) {
            Injector::inst()->create(LoggerInterface::class)->error($e);

            return false;
        }
    }

    /**
     * When unpublishing this item, remove from Algolia
     */
    public function onAfterUnpublish()
    {
        if ($this->owner->indexEnabled()) {
            $this->removeFromAlgolia();
        }
    }

    /**
     * Remove this item from Algolia
     * @return boolean false if failed or not indexed
     */
    public function removeFromAlgolia()
    {
        if (!$this->owner->AlgoliaIndexed) {
            // Not in the index, so skipping
            return false;
        }
        $indexer = Injector::inst()->get(AlgoliaIndexer::class);

        if ($this->config()->get('use_queued_indexing')) {
            $indexDeleteJob = new AlgoliaDeleteItemJob(get_class($this->owner), $this->owner->ID);
            QueuedJobService::singleton()->queueJob($indexDeleteJob);
        } else {
            try {
                $indexer->deleteItem(get_class($this->owner), $this->owner->ID);

                $this->markAsRemovedFromAlgoliaIndex();
            } catch (Exception $e) {
                Injector::inst()->create(LoggerInterface::class)->error($e);
                return false;
            }
        }
        return true;
    }

    /**
     * Before deleting this record ensure that it is removed from Algolia.
     */
    public function onBeforeDelete()
    {
        if ($this->owner->indexEnabled()) {
            $this->removeFromAlgolia();
        }
    }

    /**
     * @return array
     */
    public function getAlgoliaIndexes()
    {
        $indexer = Injector::inst()->get(AlgoliaIndexer::class);

        return $indexer->getService()->initIndexes($this->owner);
    }
}
