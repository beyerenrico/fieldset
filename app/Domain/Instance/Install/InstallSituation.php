<?php

declare(strict_types=1);

namespace App\Domain\Instance\Install;

/** Where `fsa install` starts from (R13, §7 "after exit 4, running install again resumes"). */
enum InstallSituation
{
    /** No finished install and no unfinished install journal. */
    case Fresh;

    /** An earlier install stopped half-way: completed phases are skipped. */
    case Resume;

    /** The instance is installed: same flags change nothing, different flags exit 2. */
    case Finished;
}
