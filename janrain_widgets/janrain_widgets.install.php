<?php

/**
 * @file
 * Janrain Widgets submodule file.
 */

use Drupal\Core\Database\Database;

/**
 * Implements hook_uninstall().
 *
 * Cleans out all files and blocks and cache.
 */
function janrain_widgets_uninstall() {
  // List managed files.
  $managed_files = Database::getConnection('default')->select('file_managed', 'fm')
    ->fields('fm')
    ->condition('uri', 'public://janrain_widgets/%', 'LIKE')
    ->execute();

  // Let Drupal delete managed entries and files.
  foreach ($managed_files as $file) {
    // Force delete.
    file_delete($file, TRUE);
  }

  // Purge the installed files.
  file_unmanaged_delete_recursive('public://janrain_widgets');

  // Clear caches for good measure.
  drupal_flush_all_caches();
}
