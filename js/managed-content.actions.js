/**
 * @file
 * Paragraphs actions JS code for managed content actions button.
 */

(function ($, Drupal) {

  'use strict';

  /**
   * Process managed_content_action elements.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches managedContentActions behaviors.
   */
  Drupal.behaviors.managedContentActions = {
    attach: function (context, settings) {
      var $actionsElement = $(context).find('.managed-content-dropdown').once('managed-content-dropdown');
      // Attach event handlers to toggle button.
      $actionsElement.each(function () {
        var $this = $(this);
        var $toggle = $this.find('.managed-content-dropdown-toggle');

        $toggle.on('click', function (e) {
          e.preventDefault();
          $this.toggleClass('open');
        });

        $toggle.on('focusout', function (e) {
          $this.removeClass('open');
        });
      });
    }
  };

})(jQuery, Drupal);
