<?php

namespace Ahinkle\FileBoomerang\Listeners;

use Ahinkle\FileBoomerang\Editor;
use Ahinkle\FileBoomerang\Jobs\MailChanges;
use Override;
use Statamic\Events\Concerns\ListensForContentEvents;
use Statamic\Events\Event;
use Statamic\Events\Subscriber;

class RecordContentChanges extends Subscriber
{
    use ListensForContentEvents;

    public function record(Event $event): void
    {
        if (! config('file-boomerang.enabled')) {
            return;
        }

        $editor = Editor::from($event->authenticatedUser);

        if (app()->runningInConsole()) {
            rescue(fn () => MailChanges::dispatchSync($editor));

            return;
        }

        defer(fn () => MailChanges::dispatchSync($editor), 'file-boomerang')->always();
    }

    /**
     * @return array<class-string, array{class-string, string}>
     */
    #[Override]
    protected function getListeners(): array
    {
        return array_fill_keys($this->events, [static::class, 'record']);
    }
}
