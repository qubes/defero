/**
 * Default class handles for bootstrap popver js;
 * http://getbootstrap.com/2.3.2/javascript.html#popovers
 *
 * @author  gareth.evans
 * @package bs
 */
jQuery(document).ready(function() {
  /**
   * Anonymous function gives us local scope and our own local copy of the
   * jQuery object via `$`. Only use `jQuery` in global scope.
   */
  (function($) {
    /**
     * @handle         .js-popover
     *
     * @data-animation       boolean         Apply a css fade transition to the
     *                                       tooltip.
     * @data-html            boolean         Insert html into the popover. If
     *                                       false, jquery's text method will be
     *                                       used to insert content into the
     *                                       dom. Use text if you're worried
     *                                       about XSS attacks.
     * @data-placement       string|function How to position the popover.
     * @data-selector        string          If a selector is provided, tooltip
     *                                       objects will be delegated to the
     *                                       specified targets.
     * @data-trigger         string          How popover is triggered.
     * @data-title           string|function Default title value if `title`
     *                                       attribute isn't present.
     * @data-content         string|function Default content value if
     *                                       `data-content` attribute isn't
     *                                       present.
     * @data-delay           number|object   Delay showing and hiding the
     *                                       popover (ms) - does not apply to
     *                                       manual trigger type.
     * @data-container       string|false    Appends the popover to a specific
     *                                       element.
     * @data-prevent-default bool
     */
    $(document).on('click', '.js-popover', function(e) {
      $(this).bsUtilPreventDefault(e).popover('show');
      e.stopPropagation();
    });

    /**
     * Closes all open popovers
     *
     * @data-prevent-default bool
     */
    $(document).on('click', '.js-popover-hide', function(e) {
      $('.js-popover').bsUtilPreventDefault(e).popover('hide');
      e.stopPropagation();
    });
  })(jQuery);
});
