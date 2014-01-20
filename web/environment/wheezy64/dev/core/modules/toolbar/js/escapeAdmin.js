/**
 * @file
 *
 * Replaces the home link in toolbar with a back to site link.
 */
(function ($, Drupal, drupalSettings) {

"use strict";

var escapeAdminPath = sessionStorage.getItem('escapeAdminPath');

// Saves the last non-administrative page in the browser to be able to link back
// to it when browsing administrative pages. If there is a destination parameter
// there is not need to save the current path because the page is loaded within
// an existing "workflow".
if (!drupalSettings.currentPathIsAdmin && !/destination=/.test(window.location.search)) {
  sessionStorage.setItem('escapeAdminPath', drupalSettings.currentPath);
}

/**
 * Replaces the "Home" link with "Back to site" link.
 *
 * Back to site link points to the last non-administrative page the user visited
 * within the same browser tab.
 */
Drupal.behaviors.escapeAdmin = {
  attach: function () {
    var $toolbarEscape = $('[data-toolbar-escape-admin]').once('escapeAdmin');
    if ($toolbarEscape.length) {
      if (drupalSettings.currentPathIsAdmin && escapeAdminPath) {
        $toolbarEscape.attr('href', Drupal.url(escapeAdminPath));
        $toolbarEscape.closest('.toolbar-tab').removeClass('hidden');
      }
    }
  }
};

})(jQuery, Drupal, drupalSettings);
