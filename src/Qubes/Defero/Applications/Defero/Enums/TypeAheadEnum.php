<?php
/**
 * @author gareth.evans
 */

namespace Qubes\Defero\Applications\Defero\Enums;

use Cubex\Type\Enum;

/**
 * Class TypeAheadEnum
 * @package Qubes\Defero\Applications\Defero\Enums
 *
 * @method static ALL
 * @method static CONTACTS
 * @method static CAMPAIGNS
 * @method static PROCESSORS
 */
class TypeAheadEnum extends Enum
{
  const __default  = self::ALL;
  const ALL        = "all";
  const CONTACTS   = "contacts";
  const CAMPAIGNS  = "campaigns";
  const PROCESSORS = "processors";
}
