<?php

namespace ByteMinus\AiBlog\Enums;

/** Where a command is in its life. */
enum CommandStatus: string
{
    case Queued = 'queued';

    case Running = 'running';

    case Completed = 'completed';

    case Failed = 'failed';

    case Rejected = 'rejected';

    case Cancelled = 'cancelled';
}
