<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features;

enum Status
{
    case Ok;
    case Missing;
    case Differs;
    case Written;
    case Overwritten;
    case Skipped;
    case Failed;
    case Ran;
    /** A process whose outcome cannot be inspected; omitted from the drift table. */
    case NotCheckable;

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'ok',
            self::Missing => 'missing',
            self::Differs => 'differs',
            self::Written => 'written',
            self::Overwritten => 'overwritten',
            self::Skipped => 'skipped',
            self::Failed => 'failed',
            self::Ran => 'ran',
            self::NotCheckable => 'not checkable',
        };
    }
}
