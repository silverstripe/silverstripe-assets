<?php

namespace App\FileMigration\FileBinary;

use App\Queue;
use RuntimeException;
use SilverStripe\ORM\Queries\SQLSelect;

class Job extends Queue\Job
{

    use Queue\ExecutionTime;

    private const TIME_LIMIT = 600;

    public function getTitle(): string
    {
        return 'File binary migration job';
    }

    public function hydrate(array $items): void
    {
        $this->items = $items;
    }

    /**
     * @param mixed $item
     */
    protected function processItem($item): void
    {
        $this->withExecutionTime(self::TIME_LIMIT, function () use ($item): void {
            $logger = new Queue\Logger();
            $logger->setJob($this);

            $result = Helper::create()
                ->setIds([$item])
                ->setLogger($logger)
                ->run();

            if ($result) {
                return;
            }

            $message = sprintf('File migration failed for file %d', $item);

            if (count($this->items) > 1 || !$this->checkFileBinary($item)) {
                // suppress exception in case we are migrating a batch or file binary is missing
                // missing file binaries are not a problem of this migration process
                // so we don't need to log them as errors
                $this->addMessage($message);

                return;
            }

            throw new RuntimeException($message);
        });
    }

    private function checkFileBinary(int $id): bool
    {
        $query = SQLSelect::create('"Filename"', '"File"', ['"ID"' => $id]);
        $results = $query->execute();
        $result = $results->first();

        if (!$result) {
            return false;
        }

        $filename = $result['Filename'];

        return file_exists($filename);
    }
}
