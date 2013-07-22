<?php
/**
 * @author  brooke.bryan
 */

namespace Qubes\Defero\Components\Campaign\Rules;

use Cubex\Foundation\Config\IConfigurable;
use Qubes\Defero\Transport\IProcessMessage;

interface IRule extends IConfigurable
{
  public function __construct(IProcessMessage $message);
}
