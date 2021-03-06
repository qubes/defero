<?php
/**
 * @author gareth.evans
 */

namespace Qubes\Defero\Applications\Defero\Views\Base;

use Cubex\View\HtmlElement;
use Cubex\View\TemplatedViewModel;

class DeferoView extends TemplatedViewModel
{
  private $_resultsPerPage = 10;

  /**
   * @param int $perPage
   *
   * @return $this
   */
  public function setResultsPerPage($perPage)
  {
    $this->_resultsPerPage = (int)$perPage;

    return $this;
  }

  /**
   * @return int
   */
  public function getResultsPerPage()
  {
    return $this->_resultsPerPage;
  }

  public function getDeletePopover($id)
  {
    return $this->getConfirmPopover("{$this->baseUri()}/{$id}/delete");
  }

  public function getConfirmPopover($url, $active = null)
  {
    $extra = "";
    if($active === '0')
    {
      $extra = "<span class='text-error'>"
        . "<strong>This is in TEST Mode</strong></span></br>";
    }
    $popover = (new HtmlElement(
      "div", ["class" => "text-center"], "Are you sure?<br />".$extra
    ))->nestElement("a", ["href" => $url], "Yes")
      ->nestElement("span", [], " | ")
      ->nestElement(
        "a",
        ["href" => "#", "class" => "js-popover-hide"],
        "<strong>No</strong>"
      );

    return htmlspecialchars($popover);
  }
}
