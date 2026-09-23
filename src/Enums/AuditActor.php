<?php

namespace AiBlog\Enums;

/** Who made a change recorded in the audit trail: a person, a queued job, a connected credential (ChatGPT), or the Brain. */
enum AuditActor: string
{
    case User = 'user';

    case Job = 'job';

    case Credential = 'credential';

    case Brain = 'brain';
}
