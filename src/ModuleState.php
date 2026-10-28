<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots;

/** A Module's lifecycle state, as the content Store keeps it; Halted is worked out, never stored. */
enum ModuleState: string
{
    case Uploaded = 'uploaded';
    case Active = 'active';
    case Inactive = 'inactive';
}
