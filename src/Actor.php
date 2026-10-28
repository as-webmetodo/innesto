<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots;

/** Who is acting on a Screen; a Visitor is none. */
enum Actor: string
{
    case Developer = 'developer';
    case Client = 'client';
}
