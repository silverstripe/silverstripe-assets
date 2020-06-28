<?php

namespace App\FileMigration\SecureAssets;

use App\Queue;
use SilverStripe\Assets\Dev\Tasks\SecureAssetsMigrationHelper;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\Queries\SQLSelect;

class Job extends Queue\Job
{

    public function getTitle(): string
    {
        return 'Secure assets migration job';
    }

    public function setup(): void
    {
        $this->items = $this->getItemsToProcess();

        parent::setup();
    }

    /**
     * Code taken from @see SecureAssetsMigrationHelper::run()
     *
     * @param mixed $item
     */
    protected function processItem($item): void
    {
        $logger = new Queue\Logger();
        $logger->setJob($this);

        $helper = Helper::create()
            ->setLogger($logger);

        /** @var Folder $folder */
        $folder = Folder::get()->byID($item);

        if (!$folder) {
            $this->addMessage(sprintf('No Folder record found for ID %d. Skipping', $item));

            return;
        }

        $store = singleton(AssetStore::class);
        $filesystem = $store->getPublicFilesystem();

        $result = $helper->migrateFolder($filesystem, $folder->getFilename());

        if ($result) {
            return;
        }

        $this->addMessage(sprintf('No action needed for Folder ID %d. Skipping', $item));
    }

    /**
     * Code taken from @see SecureAssetsMigrationHelper::run()
     *
     * @return array
     */
    private function getItemsToProcess(): array
    {
        $fileTable = DataObject::getSchema()->baseDataTable(File::class);
        $securedFolders = SQLSelect::create()
            ->setFrom(sprintf('"%s"', $fileTable))
            ->setSelect([
                '"ID"',
                '"FileFilename"',
            ])
            ->addWhere([
                '"ClassName" = ?' => Folder::class,
                // We don't need to check 'Inherited' permissions,
                // since Apache applies parent .htaccess and the module doesn't create them in this case.
                // See SecureFileExtension->needsAccessFile()
                '"CanViewType" IN(?,?)' => ['LoggedInUsers', 'OnlyTheseUsers'],
            ]);

        $items = [];

        foreach ($securedFolders->execute()->map() as $id => $path) {
            if (!$id) {
                continue;
            }

            $items[] = (int) $id;
        }

        return $items;
    }
}
