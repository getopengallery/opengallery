<?php

namespace Tests\Support;

use Illuminate\Queue\Worker;

/**
 * The queue worker used in tests. Laravel's worker really sleeps (3 s by
 * default) when no job is ready, for example while a retried job waits for
 * its delay. In a test that only stretches the run, so this worker stops
 * instead: "nothing ready" means the pass is over.
 */
class TestQueueWorker extends Worker
{
    /** Takes over the queue manager, events, exception handler and callbacks of $worker. */
    public static function from(Worker $worker): self
    {
        return new self(
            $worker->manager,
            $worker->events,
            $worker->exceptions,
            $worker->isDownForMaintenance,
            $worker->resetScope,
        );
    }

    /**
     * @param  int|float  $seconds
     */
    public function sleep($seconds): void
    {
        $this->shouldQuit = true;
    }
}
