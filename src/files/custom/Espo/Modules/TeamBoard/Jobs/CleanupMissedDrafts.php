<?php

namespace Espo\Modules\TeamBoard\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\DateTime;
use Espo\Modules\TeamBoard\Tools\Timeline\AssignmentEditor;
use Espo\Modules\TeamBoard\Tools\Timeline\AssignmentQuery;
use Throwable;

/**
 * D15 — a draft whose start date has passed without being confirmed describes
 * a move that did not happen and now never will. Carrying it is the clutter
 * that made the previous whiteboard unreadable.
 *
 * The cutoff is `dateFrom < today`, never `<=`: a draft dated today survives
 * the whole of its own day, so a rotation still awaiting confirmation on the
 * morning it is due is on the board rather than deleted overnight.
 *
 * Deletion goes through `AssignmentEditor::delete()`, which restores the
 * period the draft closed. Without that, the closure would outlive the plan
 * and drop the person out of every team on the planned date with nothing on
 * the board explaining why.
 *
 * Nothing that describes reality is touched: applied periods, confirmed
 * periods, and every ended period stay forever.
 */
class CleanupMissedDrafts implements JobDataLess
{
    public function __construct(
        private AssignmentQuery $query,
        private AssignmentEditor $editor,
        private Log $log,
        private DateTime $dateTime,
    ) {}

    public function run(): void
    {
        $today = $this->dateTime->getToday()->toString();

        foreach ($this->query->findMissedDrafts($today) as $draft) {
            $id = $draft->getId();

            try {
                $this->editor->delete($draft);
            }
            catch (Throwable $e) {
                $this->log->error(
                    "TeamBoard: could not clean up missed draft $id. " . $e->getMessage()
                );
            }
        }
    }
}
